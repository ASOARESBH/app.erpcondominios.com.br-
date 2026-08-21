<?php
/** Controlador interno da Central RBAC. Deve ser usado após autenticação. */

function rbacMapaAcoesLegadas() {
    return [
        'pode_acessar' => 'visualizar',
        'pode_criar' => 'criar',
        'pode_editar' => 'editar',
        'pode_excluir' => 'excluir',
        'pode_exportar' => 'exportar'
    ];
}

function rbacCatalogoApi($conexao) {
    $res = $conexao->query("SELECT m.id,m.chave,m.nome,m.modulo_pai_id,m.pagina,m.grupo,m.tipo,m.icone,m.descricao,m.ordem,m.ativo,p.acao,p.nome AS permissao_nome FROM rbac_modulos m LEFT JOIN rbac_permissoes p ON p.modulo_id=m.id AND p.ativo=1 WHERE m.ativo=1 ORDER BY COALESCE(m.modulo_pai_id,0),m.ordem,m.nome,p.acao");
    $catalogo = [];
    while ($linha = $res->fetch_assoc()) {
        $chave = $linha['chave'];
        if (!isset($catalogo[$chave])) {
            $catalogo[$chave] = [
                'id'=>(int)$linha['id'], 'chave'=>$chave, 'nome'=>$linha['nome'],
                'modulo_pai_id'=>$linha['modulo_pai_id'] !== null ? (int)$linha['modulo_pai_id'] : null,
                'pagina'=>$linha['pagina'], 'grupo'=>$linha['grupo'], 'tipo'=>$linha['tipo'],
                'icone'=>$linha['icone'], 'descricao'=>$linha['descricao'], 'ordem'=>(int)$linha['ordem'],
                'acoes'=>[]
            ];
        }
        if ($linha['acao']) $catalogo[$chave]['acoes'][] = $linha['acao'];
    }
    return $catalogo;
}

function rbacFormatoPermissoesApi($conexao, $usuarioId, $tenantId, $forcar = false) {
    $efetivas = rbacObterPermissoesEfetivas($conexao, $usuarioId, $tenantId, $forcar);
    $catalogo = rbacCatalogoApi($conexao);
    $modulos = [];
    foreach ($catalogo as $chave => $modulo) {
        $saida = ['pode_acessar'=>false,'pode_criar'=>false,'pode_editar'=>false,'pode_excluir'=>false,'pode_exportar'=>false,'pode_imprimir'=>false,'pode_importar'=>false,'pode_aprovar'=>false,'pode_executar'=>false,'pode_configurar'=>false,'pode_bloquear'=>false,'pode_desbloquear'=>false,'escopos'=>[],'origens'=>[]];
        foreach ($modulo['acoes'] as $acao) {
            $registro = $efetivas['permitidas'][$chave . '.' . $acao] ?? null;
            $permitido = $efetivas['is_super_admin'] ? true : (!empty($registro['permitido']));
            $mapa = ['visualizar'=>'acessar','criar'=>'criar','editar'=>'editar','excluir'=>'excluir','exportar'=>'exportar','imprimir'=>'imprimir','importar'=>'importar','aprovar'=>'aprovar','executar'=>'executar','configurar'=>'configurar','bloquear'=>'bloquear','desbloquear'=>'desbloquear'];
            $campo = 'pode_' . ($mapa[$acao] ?? $acao);
            $saida[$campo] = $permitido;
            if ($registro) {
                $saida['escopos'][$acao] = $registro['escopo'];
                $saida['origens'][$acao] = $registro['origens'];
            }
        }
        $saida['modulo'] = $modulo;
        $modulos[$chave] = $saida;
    }
    return ['is_admin' => $efetivas['is_super_admin'], 'modo_compatibilidade'=>$efetivas['modo_compatibilidade'], 'revisao'=>$efetivas['revisao'] ?? 0, 'grupos'=>$efetivas['grupos'] ?? [], 'modulos'=>$modulos];
}

function rbacUsuarioMesmoTenant($conexao, $usuarioId, $tenantId) {
    $st = $conexao->prepare('SELECT id,nome,email,permissao,ativo FROM usuarios WHERE id=? AND tenant_id=? LIMIT 1');
    if (!$st) return null;
    $st->bind_param('ii',$usuarioId,$tenantId);
    $st->execute();
    $usuario = $st->get_result()->fetch_assoc();
    $st->close();
    return $usuario ?: null;
}

function rbacPodeConceder($conexao, $modulo, $acao) {
    return rbacUsuarioEhSuperAdmin() || rbacPode($conexao, $modulo, $acao);
}

function rbacSalvarExcecoesLegadas($conexao, $tenantId, $alvoId, array $permissoes) {
    $ator = (int)($_SESSION['usuario_id'] ?? 0);
    if ($alvoId === $ator) return ['ok'=>false,'mensagem'=>'Não é permitido alterar as próprias permissões.'];
    $alvo = rbacUsuarioMesmoTenant($conexao, $alvoId, $tenantId);
    if (!$alvo) return ['ok'=>false,'mensagem'=>'Usuário não encontrado no condomínio atual.'];
    if (strtolower((string)$alvo['permissao']) === 'super_admin') return ['ok'=>false,'mensagem'=>'Permissões de Super Admin não podem ser alteradas nesta tela.'];

    $mapaAcoes = rbacMapaAcoesLegadas();
    $salvos = 0;
    $conexao->begin_transaction();
    try {
        foreach ($permissoes as $chave => $linha) {
            $chave = substr((string)$chave,0,80);
            $stMod = $conexao->prepare('SELECT id FROM rbac_modulos WHERE chave=? AND ativo=1 LIMIT 1');
            $stMod->bind_param('s',$chave); $stMod->execute();
            $modulo = $stMod->get_result()->fetch_assoc(); $stMod->close();
            if (!$modulo) continue;
            foreach ($mapaAcoes as $campo => $acao) {
                $permitir = !empty($linha[$campo]);
                $stPerm = $conexao->prepare('SELECT id FROM rbac_permissoes WHERE modulo_id=? AND acao=? AND ativo=1 LIMIT 1');
                $stPerm->bind_param('is',$modulo['id'],$acao); $stPerm->execute();
                $permissao = $stPerm->get_result()->fetch_assoc(); $stPerm->close();
                if (!$permissao) continue;
                if ($permitir && !rbacPodeConceder($conexao, $chave, $acao)) {
                    throw new RuntimeException('Você não pode conceder a permissão ' . $chave . '.' . $acao . '.');
                }
                $efeito = $permitir ? 'PERMITIR' : 'NEGAR';
                $motivo = 'Central de permissões (compatibilidade)';
                $stSave = $conexao->prepare("INSERT INTO rbac_usuario_permissoes (usuario_id,tenant_id,permissao_id,efeito,escopo_dados,motivo,atribuido_por_usuario_id,revogado_em) VALUES (?,?,?,'$efeito','GLOBAL',?,?,NULL) ON DUPLICATE KEY UPDATE efeito=VALUES(efeito),escopo_dados=VALUES(escopo_dados),motivo=VALUES(motivo),atribuido_por_usuario_id=VALUES(atribuido_por_usuario_id),revogado_em=NULL,atualizado_em=NOW()");
                $stSave->bind_param('iiisi',$alvoId,$tenantId,$permissao['id'],$motivo,$ator);
                if (!$stSave->execute()) throw new RuntimeException($stSave->error);
                $stSave->close();
                $salvos++;
            }
        }
        $conexao->commit();
        rbacInvalidarCache($conexao,$tenantId);
        rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'permissoes','acao'=>'CONFIGURAR_PERMISSOES','registro_tipo'=>'usuarios','registro_id'=>$alvoId,'dados_depois'=>['quantidade'=>$salvos],'resultado'=>'SUCESSO','status_http'=>200]);
        return ['ok'=>true,'salvos'=>$salvos];
    } catch (Throwable $e) {
        $conexao->rollback();
        rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'permissoes','acao'=>'CONFIGURAR_PERMISSOES','registro_tipo'=>'usuarios','registro_id'=>$alvoId,'resultado'=>'ERRO','status_http'=>400,'motivo'=>$e->getMessage()]);
        return ['ok'=>false,'mensagem'=>$e->getMessage()];
    }
}

function rbacResetarExcecoesUsuario($conexao,$tenantId,$alvoId) {
    $ator=(int)($_SESSION['usuario_id'] ?? 0);
    if ($alvoId===$ator) return ['ok'=>false,'mensagem'=>'Não é permitido redefinir as próprias permissões.'];
    $alvo=rbacUsuarioMesmoTenant($conexao,$alvoId,$tenantId);
    if (!$alvo) return ['ok'=>false,'mensagem'=>'Usuário não encontrado no condomínio atual.'];
    $st=$conexao->prepare('DELETE FROM rbac_usuario_permissoes WHERE usuario_id=? AND tenant_id=?');
    $st->bind_param('ii',$alvoId,$tenantId);
    $ok=$st->execute(); $afetados=$st->affected_rows; $st->close();
    if ($ok) {
        rbacInvalidarCache($conexao,$tenantId);
        rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'permissoes','acao'=>'REDEFINIR_PERMISSOES','registro_tipo'=>'usuarios','registro_id'=>$alvoId,'dados_depois'=>['excecoes_removidas'=>$afetados],'resultado'=>'SUCESSO','status_http'=>200]);
        return ['ok'=>true,'afetados'=>$afetados];
    }
    return ['ok'=>false,'mensagem'=>'Não foi possível redefinir as permissões.'];
}
?>
