<?php
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/rbac_api_controller.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

function rbac_api_responder($sucesso, $mensagem, $dados = null, $codigo = 200) {
    http_response_code($codigo);
    $saida = ['sucesso'=>(bool)$sucesso, 'mensagem'=>$mensagem];
    if ($dados !== null) $saida['dados']=$dados;
    echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='OPTIONS') { http_response_code(204); exit; }
$auth = verificarAutenticacao(true);
$tenant_id = exigirTenantId();
$conexao = conectar_banco();
if (!rbacTabelasDisponiveis($conexao)) rbac_api_responder(false,'RBAC ainda não foi instalado. Execute a migration antes de usar a Central de Identidade.',null,503);
$metodo = strtoupper($_SERVER['REQUEST_METHOD']);
$entrada = $metodo==='GET' ? $_GET : (json_decode(file_get_contents('php://input'),true) ?: $_POST);
$acao = trim((string)($entrada['acao'] ?? ''));
$usuario_atual = (int)$auth['id'];

if ($metodo==='GET' && $acao==='minhas_permissoes') {
    rbac_api_responder(true,'Permissões efetivas carregadas',rbacFormatoPermissoesApi($conexao,$usuario_atual,$tenant_id,false));
}

rbacExigir($conexao,'usuarios',$metodo==='GET' ? 'visualizar' : 'configurar');

if ($metodo==='GET' && $acao==='catalogo') {
    $catalogo=rbacCatalogoApi($conexao);
    foreach ($catalogo as $chave=>&$modulo) {
        $modulo['acoes_concediveis']=[];
        foreach ($modulo['acoes'] as $acaoCatalogo) $modulo['acoes_concediveis'][$acaoCatalogo]=rbacPodeConceder($conexao,$chave,$acaoCatalogo);
    }
    unset($modulo);
    rbac_api_responder(true,'Catálogo RBAC carregado',['modulos'=>array_values($catalogo),'pode_configurar'=>rbacPode($conexao,'usuarios','configurar')]);
}

if ($metodo==='GET' && $acao==='grupo') {
    $id=(int)($entrada['id'] ?? 0);
    $st=$conexao->prepare('SELECT id,slug,nome,descricao,ativo,protegido FROM rbac_grupos WHERE id=? AND tenant_id=? AND excluido_em IS NULL LIMIT 1');
    $st->bind_param('ii',$id,$tenant_id); $st->execute(); $grupo=$st->get_result()->fetch_assoc(); $st->close();
    if(!$grupo) rbac_api_responder(false,'Grupo não encontrado.',null,404);
    $st=$conexao->prepare('SELECT m.chave,p.acao,gp.efeito FROM rbac_grupo_permissoes gp INNER JOIN rbac_permissoes p ON p.id=gp.permissao_id INNER JOIN rbac_modulos m ON m.id=p.modulo_id WHERE gp.grupo_id=? AND p.ativo=1 AND m.ativo=1 ORDER BY m.chave,p.acao');
    $st->bind_param('i',$id); $st->execute(); $rs=$st->get_result(); $permissoes=[]; while($r=$rs->fetch_assoc()) $permissoes[]=['modulo'=>$r['chave'],'acao'=>$r['acao'],'estado'=>$r['efeito']]; $st->close();
    rbac_api_responder(true,'Grupo carregado',['grupo'=>$grupo,'permissoes'=>$permissoes]);
}

if ($metodo==='GET' && $acao==='grupos') {
    $sql = "SELECT g.id,g.slug,g.nome,g.descricao,g.ativo,g.protegido,g.criado_em,g.atualizado_em, COUNT(DISTINCT ug.usuario_id) AS total_usuarios, COUNT(DISTINCT gp.permissao_id) AS total_permissoes FROM rbac_grupos g LEFT JOIN rbac_usuario_grupos ug ON ug.grupo_id=g.id AND ug.ativo=1 AND ug.removido_em IS NULL LEFT JOIN rbac_grupo_permissoes gp ON gp.grupo_id=g.id WHERE g.tenant_id=? AND g.excluido_em IS NULL GROUP BY g.id ORDER BY g.protegido DESC,g.nome ASC";
    $st=$conexao->prepare($sql); $st->bind_param('i',$tenant_id); $st->execute(); $rs=$st->get_result(); $grupos=[];
    while($r=$rs->fetch_assoc()) $grupos[]=$r; $st->close();
    rbac_api_responder(true,'Grupos carregados',['grupos'=>$grupos]);
}

if ($metodo==='GET' && $acao==='usuario_grupos') {
    $alvo=(int)($entrada['usuario_id'] ?? 0);
    if (!$alvo || !rbacUsuarioMesmoTenant($conexao,$alvo,$tenant_id)) rbac_api_responder(false,'Usuário não encontrado no condomínio atual.',null,404);
    $st=$conexao->prepare('SELECT g.id,g.slug,g.nome,g.descricao,g.protegido FROM rbac_usuario_grupos ug INNER JOIN rbac_grupos g ON g.id=ug.grupo_id WHERE ug.usuario_id=? AND ug.tenant_id=? AND ug.ativo=1 AND ug.removido_em IS NULL AND g.ativo=1 AND g.excluido_em IS NULL ORDER BY g.nome');
    $st->bind_param('ii',$alvo,$tenant_id); $st->execute(); $rs=$st->get_result(); $grupos=[]; while($r=$rs->fetch_assoc()) $grupos[]=$r; $st->close();
    rbac_api_responder(true,'Grupos do usuário carregados',['grupos'=>$grupos]);
}

if ($metodo==='GET' && $acao==='auditoria') {
    rbacExigir($conexao,'auditoria','visualizar');
    $pagina=max(1,(int)($entrada['pagina'] ?? 1)); $limite=min(100,max(10,(int)($entrada['limite'] ?? 30))); $offset=($pagina-1)*$limite;
    $acaoFiltro=trim(substr((string)($entrada['acao_filtro'] ?? ''),0,80)); $resultadoFiltro=trim(substr((string)($entrada['resultado'] ?? ''),0,12));
    $sql='SELECT id,ocorrido_em,usuario_nome,grupo_nome,modulo_chave,submodulo_chave,acao,resultado,status_http,ip,registro_tipo,registro_id,motivo,hash_evento FROM rbac_auditoria WHERE tenant_id=?';
    $tipos='i'; $params=[$tenant_id];
    if($acaoFiltro!==''){ $sql.=' AND acao=?'; $tipos.='s'; $params[]=$acaoFiltro; }
    if(in_array($resultadoFiltro,['SUCESSO','NEGADO','ERRO'],true)){ $sql.=' AND resultado=?'; $tipos.='s'; $params[]=$resultadoFiltro; }
    $sql.=' ORDER BY id DESC LIMIT ? OFFSET ?'; $tipos.='ii'; $params[]=$limite; $params[]=$offset;
    $st=$conexao->prepare($sql); $st->bind_param($tipos,...$params); $st->execute(); $rs=$st->get_result(); $itens=[]; while($r=$rs->fetch_assoc()) $itens[]=$r; $st->close();
    rbac_api_responder(true,'Auditoria carregada',['itens'=>$itens,'pagina'=>$pagina,'limite'=>$limite]);
}

if ($metodo==='GET' && $acao==='sessoes') {
    rbacExigir($conexao,'admin_sessoes','visualizar');
    $st=$conexao->prepare('SELECT s.id,s.usuario_id,u.nome AS usuario_nome,u.email AS usuario_email,s.status,s.sessao_inativa,s.ip,s.dispositivo,s.navegador,s.sistema_operacional,s.iniciada_em,s.ultima_atividade_em,s.encerrada_em,s.motivo_encerramento FROM rbac_sessoes s LEFT JOIN usuarios u ON u.id=s.usuario_id AND u.tenant_id=s.tenant_id WHERE s.tenant_id=? ORDER BY s.ultima_atividade_em DESC LIMIT 100');
    $st->bind_param('i',$tenant_id); $st->execute(); $rs=$st->get_result(); $itens=[]; while($r=$rs->fetch_assoc()) $itens[]=$r; $st->close();
    rbac_api_responder(true,'Sessões RBAC carregadas',['itens'=>$itens]);
}

if ($metodo==='GET' && $acao==='permissoes_efetivas') {
    $alvo=(int)($entrada['usuario_id'] ?? 0);
    if (!$alvo || !rbacUsuarioMesmoTenant($conexao,$alvo,$tenant_id)) rbac_api_responder(false,'Usuário não encontrado no condomínio atual.',null,404);
    rbac_api_responder(true,'Permissões efetivas carregadas',rbacFormatoPermissoesApi($conexao,$alvo,$tenant_id,true));
}

if ($metodo==='POST' && $acao==='salvar_grupo') {
    $id=(int)($entrada['id'] ?? 0);
    $nome=trim(substr((string)($entrada['nome'] ?? ''),0,120));
    $slug=preg_replace('/[^a-z0-9_-]/','-',strtolower(trim((string)($entrada['slug'] ?? $nome))));
    $slug=trim($slug,'-');
    $descricao=trim(substr((string)($entrada['descricao'] ?? ''),0,255));
    $permissoes=is_array($entrada['permissoes'] ?? null) ? $entrada['permissoes'] : [];
    $estados=[];
    if ($nome==='' || $slug==='') rbac_api_responder(false,'Informe nome e identificador do grupo.',null,400);
    if ($id>0) {
        $st=$conexao->prepare('SELECT id,protegido FROM rbac_grupos WHERE id=? AND tenant_id=? AND excluido_em IS NULL'); $st->bind_param('ii',$id,$tenant_id); $st->execute(); $atual=$st->get_result()->fetch_assoc(); $st->close();
        if (!$atual) rbac_api_responder(false,'Grupo não encontrado neste condomínio.',null,404);
        if ((int)$atual['protegido']===1) rbac_api_responder(false,'Grupos de compatibilidade são protegidos e não podem ser alterados.',null,403);
    }
    $antes=[];
    if ($id>0) {
        $st=$conexao->prepare('SELECT m.chave,p.acao,gp.efeito FROM rbac_grupo_permissoes gp INNER JOIN rbac_permissoes p ON p.id=gp.permissao_id INNER JOIN rbac_modulos m ON m.id=p.modulo_id WHERE gp.grupo_id=? AND p.ativo=1 AND m.ativo=1');
        $st->bind_param('i',$id); $st->execute(); $rs=$st->get_result(); while($r=$rs->fetch_assoc()) $antes[$r['chave'].'.'.$r['acao']]=$r['efeito']; $st->close();
    }
    foreach ($permissoes as $item) {
        if (is_string($item)) {
            $partes=explode('.', $item, 2);
            $item=['modulo'=>$partes[0] ?? '', 'acao'=>$partes[1] ?? '', 'estado'=>'PERMITIR'];
        }
        if (!is_array($item)) rbac_api_responder(false,'Matriz de permissões do grupo inválida.',null,400);
        $modulo=trim((string)($item['modulo'] ?? ''));
        $acaoPermissao=trim((string)($item['acao'] ?? ''));
        $estado=strtoupper(trim((string)($item['estado'] ?? 'PERMITIR')));
        if ($modulo==='' || $acaoPermissao==='' || !in_array($estado,['HERDAR','PERMITIR','NEGAR'],true)) rbac_api_responder(false,'Estado de permissão do grupo inválido.',null,400);
        $chave=$modulo . '.' . $acaoPermissao;
        $st=$conexao->prepare('SELECT p.id FROM rbac_permissoes p INNER JOIN rbac_modulos m ON m.id=p.modulo_id WHERE m.chave=? AND m.ativo=1 AND p.acao=? AND p.ativo=1 LIMIT 1');
        if (!$st) rbac_api_responder(false,'Não foi possível validar o catálogo RBAC.',null,500);
        $st->bind_param('ss',$modulo,$acaoPermissao); $st->execute(); $permissaoCatalogo=$st->get_result()->fetch_assoc(); $st->close();
        if (!$permissaoCatalogo) rbac_api_responder(false,'Ação não encontrada no catálogo: ' . $chave,null,400);
        if ($estado==='PERMITIR' && ($antes[$chave] ?? 'HERDAR')!=='PERMITIR' && !rbacPodeConceder($conexao,$modulo,$acaoPermissao)) rbac_api_responder(false,'Você não pode conceder a permissão solicitada: ' . $chave,null,403);
        if (($antes[$chave] ?? null)==='NEGAR' && $estado!=='NEGAR' && !rbacPodeConceder($conexao,$modulo,$acaoPermissao)) rbac_api_responder(false,'Não é possível remover esta negação: você não possui a permissão que seria exposta (' . $chave . ').',null,403);
        $estados[$chave]=['modulo'=>$modulo,'acao'=>$acaoPermissao,'estado'=>$estado,'permissao_id'=>(int)$permissaoCatalogo['id']];
    }
    $depois=[];
    foreach ($estados as $chave=>$permissao) if ($permissao['estado']!=='HERDAR') $depois[$chave]=$permissao['estado'];
    $permissoesAtorAntes=rbacObterPermissoesEfetivas($conexao,$usuario_atual,$tenant_id,true);
    $permissoesUsuariosAntes=[];
    if ($id>0) {
        $st=$conexao->prepare('SELECT usuario_id FROM rbac_usuario_grupos WHERE grupo_id=? AND tenant_id=? AND ativo=1 AND removido_em IS NULL');
        if (!$st) rbac_api_responder(false,'Não foi possível validar os usuários vinculados ao grupo.',null,500);
        $st->bind_param('ii',$id,$tenant_id); $st->execute(); $rs=$st->get_result();
        while($membro=$rs->fetch_assoc()) { $membroId=(int)$membro['usuario_id']; $permissoesUsuariosAntes[$membroId]=rbacObterPermissoesEfetivas($conexao,$membroId,$tenant_id,true); }
        $st->close();
    }
    $conexao->begin_transaction();
    try {
        if ($id>0) {
            $st=$conexao->prepare('UPDATE rbac_grupos SET nome=?,slug=?,descricao=?,atualizado_em=NOW() WHERE id=? AND tenant_id=?'); $st->bind_param('sssii',$nome,$slug,$descricao,$id,$tenant_id); if(!$st->execute()) throw new RuntimeException($st->error); $st->close();
        } else {
            $st=$conexao->prepare('INSERT INTO rbac_grupos (tenant_id,slug,nome,descricao,ativo,protegido,criado_por_usuario_id) VALUES (?,?,?,?,1,0,?)'); $st->bind_param('isssi',$tenant_id,$slug,$nome,$descricao,$usuario_atual); if(!$st->execute()) throw new RuntimeException($st->error); $id=$conexao->insert_id; $st->close();
        }
        $st=$conexao->prepare('DELETE FROM rbac_grupo_permissoes WHERE grupo_id=?'); $st->bind_param('i',$id); $st->execute(); $st->close();
        foreach ($estados as $permissao) {
            if ($permissao['estado']==='HERDAR') continue;
            $st=$conexao->prepare("INSERT INTO rbac_grupo_permissoes (grupo_id,permissao_id,efeito,escopo_dados,criado_por_usuario_id) VALUES (?,?,?,'GLOBAL',?)");
            if (!$st) throw new RuntimeException('prepare matriz do grupo: ' . $conexao->error);
            $st->bind_param('iisi',$id,$permissao['permissao_id'],$permissao['estado'],$usuario_atual);
            if(!$st->execute()) { $erro=$st->error; $st->close(); throw new RuntimeException('gravar matriz do grupo: ' . $erro); }
            $st->close();
        }
        $permissoesAtorDepois=rbacObterPermissoesEfetivas($conexao,$usuario_atual,$tenant_id,true);
        $elevacao=rbacPrimeiraConcessaoNaoPossuida($permissoesAtorAntes,$permissoesAtorAntes,$permissoesAtorDepois);
        if ($elevacao!==null) throw new DomainException('A alteração do grupo elevaria suas próprias permissões (' . $elevacao . ').');
        foreach ($permissoesUsuariosAntes as $membroId=>$antesMembro) {
            $depoisMembro=rbacObterPermissoesEfetivas($conexao,$membroId,$tenant_id,true);
            $grantNaoAutorizado=rbacPrimeiraConcessaoNaoPossuida($permissoesAtorAntes,$antesMembro,$depoisMembro);
            if ($grantNaoAutorizado!==null) throw new DomainException('A alteração concederia a permissão ' . $grantNaoAutorizado . ' a um usuário do grupo, mas você não possui essa permissão.');
        }
        $conexao->commit(); rbacInvalidarCache($conexao,$tenant_id);
        unset($_SESSION['_rbac_cache']);
        rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'grupos','acao'=>!empty($entrada['id']) ? 'EDITAR_GRUPO':'CRIAR_GRUPO','registro_tipo'=>'rbac_grupos','registro_id'=>$id,'dados_antes'=>$antes,'dados_depois'=>['nome'=>$nome,'slug'=>$slug,'permissoes'=>$depois],'resultado'=>'SUCESSO','status_http'=>200]);
        rbac_api_responder(true,'Grupo salvo com sucesso.',['id'=>$id]);
    } catch(Throwable $e) { $conexao->rollback(); unset($_SESSION['_rbac_cache']); error_log('[RBAC] Falha ao salvar grupo tenant=' . $tenant_id . ' grupo=' . $id . ': ' . $e->getMessage()); rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'grupos','acao'=>'SALVAR_GRUPO','registro_tipo'=>'rbac_grupos','registro_id'=>$id,'dados_antes'=>$antes,'dados_depois'=>['nome'=>$nome,'slug'=>$slug,'permissoes'=>$depois],'resultado'=>'ERRO','status_http'=>400,'motivo'=>$e instanceof DomainException ? $e->getMessage() : 'Falha ao persistir a matriz do grupo.']); rbac_api_responder(false,$e instanceof DomainException ? $e->getMessage() : 'Não foi possível salvar o grupo.',null,400); }
}

if ($metodo==='POST' && $acao==='atribuir_grupos') {
    $alvo=(int)($entrada['usuario_id'] ?? 0); $grupos=array_map('intval',(array)($entrada['grupos_ids'] ?? []));
    if (!$alvo || $alvo===$usuario_atual) rbac_api_responder(false,'Não é permitido alterar os próprios grupos.',null,400);
    if (!rbacUsuarioMesmoTenant($conexao,$alvo,$tenant_id)) rbac_api_responder(false,'Usuário não encontrado no condomínio atual.',null,404);
    $permissoesAtorAntes=rbacObterPermissoesEfetivas($conexao,$usuario_atual,$tenant_id,true);
    $permissoesAlvoAntes=rbacObterPermissoesEfetivas($conexao,$alvo,$tenant_id,true);
    $conexao->begin_transaction();
    try {
        $st=$conexao->prepare('UPDATE rbac_usuario_grupos SET ativo=0,removido_em=NOW() WHERE usuario_id=? AND tenant_id=?'); $st->bind_param('ii',$alvo,$tenant_id); $st->execute(); $st->close();
        foreach(array_unique($grupos) as $grupoId) {
            $st=$conexao->prepare('SELECT id,protegido FROM rbac_grupos WHERE id=? AND tenant_id=? AND ativo=1 AND excluido_em IS NULL'); $st->bind_param('ii',$grupoId,$tenant_id); $st->execute(); $grupo=$st->get_result()->fetch_assoc(); $st->close();
            if(!$grupo) throw new RuntimeException('Grupo inválido.');
            if ((int)$grupo['protegido']===1) throw new DomainException('Grupos de compatibilidade são gerenciados pelo perfil e não podem ser atribuídos manualmente.');
            $st=$conexao->prepare('INSERT INTO rbac_usuario_grupos (usuario_id,tenant_id,grupo_id,ativo,atribuido_por_usuario_id,atribuido_em,removido_em) VALUES (?,?,?,1,?,NOW(),NULL) ON DUPLICATE KEY UPDATE ativo=1,atribuido_por_usuario_id=VALUES(atribuido_por_usuario_id),atribuido_em=NOW(),removido_em=NULL'); $st->bind_param('iiii',$alvo,$tenant_id,$grupoId,$usuario_atual); if(!$st->execute()) throw new RuntimeException($st->error); $st->close();
        }
        $permissoesAlvoDepois=rbacObterPermissoesEfetivas($conexao,$alvo,$tenant_id,true);
        $concessaoNaoAutorizada=rbacPrimeiraConcessaoNaoPossuida($permissoesAtorAntes,$permissoesAlvoAntes,$permissoesAlvoDepois);
        if ($concessaoNaoAutorizada!==null) throw new DomainException('Você não pode conceder ao usuário a permissão ' . $concessaoNaoAutorizada . '.');
        $conexao->commit(); rbacInvalidarCache($conexao,$tenant_id); unset($_SESSION['_rbac_cache']); rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'grupos','acao'=>'ATRIBUIR_GRUPOS','registro_tipo'=>'usuarios','registro_id'=>$alvo,'dados_antes'=>$permissoesAlvoAntes['grupos'],'dados_depois'=>['grupos_ids'=>$grupos],'resultado'=>'SUCESSO','status_http'=>200]);
        rbac_api_responder(true,'Grupos atribuídos com sucesso.');
    } catch(Throwable $e) { $conexao->rollback(); unset($_SESSION['_rbac_cache']); error_log('[RBAC] Falha ao atribuir grupos tenant=' . $tenant_id . ' usuario=' . $alvo . ': ' . $e->getMessage()); rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'grupos','acao'=>'ATRIBUIR_GRUPOS','registro_tipo'=>'usuarios','registro_id'=>$alvo,'resultado'=>'ERRO','status_http'=>400,'motivo'=>$e instanceof DomainException ? $e->getMessage() : 'Falha ao alterar os grupos.']); rbac_api_responder(false,$e instanceof DomainException ? $e->getMessage() : 'Não foi possível atribuir os grupos.',null,400); }
}

if ($metodo==='POST' && $acao==='revogar_sessao') {
    rbacExigir($conexao,'admin_sessoes','executar');
    $sessaoId=(int)($entrada['sessao_id'] ?? 0); if(!$sessaoId) rbac_api_responder(false,'Informe a sessão a revogar.',null,400);
    $motivo=trim(substr((string)($entrada['motivo'] ?? 'REVOGADA_POR_ADMIN'),0,120));
    $st=$conexao->prepare("UPDATE rbac_sessoes SET status='REVOGADA',encerrada_em=NOW(),motivo_encerramento=?,encerrado_por_usuario_id=? WHERE id=? AND tenant_id=? AND status='ATIVA'");
    $st->bind_param('siii',$motivo,$usuario_atual,$sessaoId,$tenant_id); $st->execute(); $afetados=$st->affected_rows; $st->close();
    if(!$afetados) rbac_api_responder(false,'Sessão não encontrada ou já encerrada.',null,404);
    rbacAuditar($conexao,['modulo_chave'=>'admin_sessoes','submodulo_chave'=>'sessoes','acao'=>'REVOGAR_SESSAO','registro_tipo'=>'rbac_sessoes','registro_id'=>$sessaoId,'dados_depois'=>['motivo'=>$motivo],'resultado'=>'SUCESSO','status_http'=>200]);
    rbac_api_responder(true,'Sessão revogada. O acesso será encerrado na próxima validação.');
}

if ($metodo==='DELETE' && $acao==='excluir_grupo') {
    $id=(int)($entrada['id'] ?? 0);
    $st=$conexao->prepare('SELECT nome,protegido FROM rbac_grupos WHERE id=? AND tenant_id=? AND excluido_em IS NULL'); $st->bind_param('ii',$id,$tenant_id); $st->execute(); $grupo=$st->get_result()->fetch_assoc(); $st->close();
    if(!$grupo) rbac_api_responder(false,'Grupo não encontrado.',null,404);
    if((int)$grupo['protegido']===1) rbac_api_responder(false,'Grupos de compatibilidade são protegidos.',null,403);
    $permissoesAtorAntes=rbacObterPermissoesEfetivas($conexao,$usuario_atual,$tenant_id,true);
    $permissoesUsuariosAntes=[];
    $st=$conexao->prepare('SELECT usuario_id FROM rbac_usuario_grupos WHERE grupo_id=? AND tenant_id=? AND ativo=1 AND removido_em IS NULL');
    if (!$st) rbac_api_responder(false,'Não foi possível validar os usuários vinculados ao grupo.',null,500);
    $st->bind_param('ii',$id,$tenant_id); $st->execute(); $rs=$st->get_result();
    while($membro=$rs->fetch_assoc()) { $membroId=(int)$membro['usuario_id']; $permissoesUsuariosAntes[$membroId]=rbacObterPermissoesEfetivas($conexao,$membroId,$tenant_id,true); }
    $st->close();
    $conexao->begin_transaction();
    try {
        $st=$conexao->prepare('UPDATE rbac_grupos SET ativo=0,excluido_em=NOW() WHERE id=? AND tenant_id=?');
        if (!$st) throw new RuntimeException('prepare excluir grupo: ' . $conexao->error);
        $st->bind_param('ii',$id,$tenant_id); if(!$st->execute()) { $erro=$st->error; $st->close(); throw new RuntimeException($erro); }
        $afetados=$st->affected_rows; $st->close();
        if (!$afetados) throw new DomainException('Grupo já foi removido ou não está ativo.');
        $permissoesAtorDepois=rbacObterPermissoesEfetivas($conexao,$usuario_atual,$tenant_id,true);
        $elevacao=rbacPrimeiraConcessaoNaoPossuida($permissoesAtorAntes,$permissoesAtorAntes,$permissoesAtorDepois);
        if ($elevacao!==null) throw new DomainException('A remoção do grupo elevaria suas próprias permissões (' . $elevacao . ').');
        foreach ($permissoesUsuariosAntes as $membroId=>$antesMembro) {
            $depoisMembro=rbacObterPermissoesEfetivas($conexao,$membroId,$tenant_id,true);
            $grantNaoAutorizado=rbacPrimeiraConcessaoNaoPossuida($permissoesAtorAntes,$antesMembro,$depoisMembro);
            if ($grantNaoAutorizado!==null) throw new DomainException('A remoção concederia a permissão ' . $grantNaoAutorizado . ' a um usuário vinculado, mas você não possui essa permissão.');
        }
        $conexao->commit(); rbacInvalidarCache($conexao,$tenant_id); unset($_SESSION['_rbac_cache']);
        rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'grupos','acao'=>'EXCLUIR_GRUPO','registro_tipo'=>'rbac_grupos','registro_id'=>$id,'dados_antes'=>$grupo,'resultado'=>'SUCESSO','status_http'=>200]);
        rbac_api_responder(true,'Grupo excluído com segurança.');
    } catch(Throwable $e) {
        $conexao->rollback(); unset($_SESSION['_rbac_cache']);
        error_log('[RBAC] Falha ao excluir grupo tenant=' . $tenant_id . ' grupo=' . $id . ': ' . $e->getMessage());
        rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'grupos','acao'=>'EXCLUIR_GRUPO','registro_tipo'=>'rbac_grupos','registro_id'=>$id,'dados_antes'=>$grupo,'resultado'=>'ERRO','status_http'=>400,'motivo'=>$e instanceof DomainException ? $e->getMessage() : 'Falha ao remover grupo.']);
        rbac_api_responder(false,$e instanceof DomainException ? $e->getMessage() : 'Não foi possível excluir o grupo.',null,400);
    }
}

rbac_api_responder(false,'Ação não reconhecida.',null,404);
