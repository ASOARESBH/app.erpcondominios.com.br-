<?php
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once 'rbac_helper.php';
require_once __DIR__ . '/helpers/alertas_acesso_helper.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
function alerta_responder($ok, $msg, $dados = null, $status = 200) { http_response_code($status); $out=['sucesso'=>(bool)$ok,'mensagem'=>$msg]; if ($dados!==null) $out['dados']=$dados; echo json_encode($out, JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
$auth = verificarAutenticacao(true);
$tenant_id = (int)exigirTenantId();
$conexao = conectar_banco();
if (!$conexao) alerta_responder(false, 'Não foi possível conectar ao banco.', null, 500);
$conexao->set_charset('utf8mb4');
$metodo = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true); if (!is_array($body)) $body=[];
$acao = $_GET['acao'] ?? $_POST['acao'] ?? $body['acao'] ?? '';
$rbac = function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($conexao);
if ($rbac) { rbacExigir($conexao, 'sistema', $metodo === 'GET' ? 'visualizar' : 'configurar'); }
else { verificarPermissao('admin'); }
function alerta_colunas($conexao, $tabela) { $ok=[]; $r=$conexao->query("SHOW COLUMNS FROM `$tabela`"); if($r) while($x=$r->fetch_assoc()) $ok[]=$x['Field']; return $ok; }
if ($acao === 'opcoes') {
    $veiculos=[]; $moradores=[]; $unidades=[];
    $r=$conexao->query("SELECT id,placa,modelo,cor,morador_id FROM veiculos WHERE tenant_id=$tenant_id AND ativo=1 ORDER BY placa"); if($r) while($x=$r->fetch_assoc()) $veiculos[]=$x;
    $r=$conexao->query("SELECT id,nome,cpf,unidade,telefone,celular FROM moradores WHERE tenant_id=$tenant_id AND ativo=1 ORDER BY nome"); if($r) while($x=$r->fetch_assoc()) $moradores[]=$x;
    $r=$conexao->query("SELECT DISTINCT unidade FROM moradores WHERE tenant_id=$tenant_id AND ativo=1 AND unidade IS NOT NULL AND unidade<>'' ORDER BY unidade"); if($r) while($x=$r->fetch_assoc()) $unidades[]=$x['unidade'];
    alerta_responder(true,'Opções carregadas',['veiculos'=>$veiculos,'moradores'=>$moradores,'unidades'=>$unidades]);
}
if ($acao === 'listar') {
    $lista=[]; $r=$conexao->query("SELECT a.*, (SELECT COUNT(*) FROM alertas_acesso_criterios c WHERE c.tenant_id=a.tenant_id AND c.alerta_id=a.id) criterios_count, (SELECT COUNT(*) FROM alertas_acesso_eventos e WHERE e.tenant_id=a.tenant_id AND e.alerta_id=a.id AND e.status IN ('pendente','notificado')) eventos_pendentes FROM alertas_acesso a WHERE a.tenant_id=$tenant_id ORDER BY a.ativo DESC, a.id DESC");
    if($r) while($a=$r->fetch_assoc()) { $a['canais']=json_decode($a['canais_json'],true) ?: ['sistema']; $a['criterios']=[]; $id=(int)$a['id']; $cr=$conexao->query("SELECT id,tipo,campo,operador,valor FROM alertas_acesso_criterios WHERE tenant_id=$tenant_id AND alerta_id=$id ORDER BY id"); if($cr) while($c=$cr->fetch_assoc()) $a['criterios'][]=$c; $lista[]=$a; }
    alerta_responder(true,'Alertas carregados',$lista);
}
if ($acao === 'salvar') {
    $id=(int)($body['id'] ?? 0); $nome=trim((string)($body['nome']??'')); $descricao=trim((string)($body['descricao']??'')); $sev=(string)($body['severidade']??'atencao'); $ativo=!empty($body['ativo'])?1:0; $canais=is_array($body['canais']??null)?$body['canais']:['sistema']; $criterios=is_array($body['criterios']??null)?$body['criterios']:[];
    if($nome==='') alerta_responder(false,'Informe o nome do alerta.',null,422);
    if(!in_array($sev,['informativo','atencao','critico'],true)) $sev='atencao';
    $canais=array_values(array_intersect(['sistema','email','whatsapp'],$canais)); if(!$canais) $canais=['sistema'];
    $permitidos=['placa','modelo','cor','pessoa_nome','pessoa_cpf','telefone','unidade','observacao']; $ops=['igual','contem','comeca_com']; $normal=[];
    foreach($criterios as $c){ $tipo=in_array(($c['tipo']??''),['veiculo','pessoa','contexto'],true)?$c['tipo']:'contexto'; $campo=(string)($c['campo']??''); $op=in_array(($c['operador']??''),$ops,true)?$c['operador']:'igual'; $valor=trim((string)($c['valor']??'')); if(in_array($campo,$permitidos,true)&&$valor!=='') $normal[]=['tipo'=>$tipo,'campo'=>$campo,'operador'=>$op,'valor'=>$valor]; }
    if(!$normal) alerta_responder(false,'Adicione pelo menos um critério de veículo, pessoa ou contexto.',null,422);
    $json=json_encode($canais,JSON_UNESCAPED_UNICODE); $usuario=(int)($auth['id']??$_SESSION['usuario_id']??0); $conexao->begin_transaction();
    try { if($id){$st=$conexao->prepare('UPDATE alertas_acesso SET nome=?,descricao=?,severidade=?,canais_json=?,ativo=? WHERE tenant_id=? AND id=?'); $st->bind_param('ssssiii',$nome,$descricao,$sev,$json,$ativo,$tenant_id,$id); } else {$st=$conexao->prepare('INSERT INTO alertas_acesso (tenant_id,nome,descricao,severidade,canais_json,ativo,criado_por_usuario_id) VALUES (?,?,?,?,?,?,?)'); $st->bind_param('issssii',$tenant_id,$nome,$descricao,$sev,$json,$ativo,$usuario);} if(!$st||!$st->execute()) throw new RuntimeException('Falha ao salvar alerta.'); if(!$id)$id=$conexao->insert_id; if($st)$st->close(); $del=$conexao->prepare('DELETE FROM alertas_acesso_criterios WHERE tenant_id=? AND alerta_id=?'); $del->bind_param('ii',$tenant_id,$id); $del->execute(); $del->close(); $ins=$conexao->prepare('INSERT INTO alertas_acesso_criterios (tenant_id,alerta_id,tipo,campo,operador,valor) VALUES (?,?,?,?,?,?)'); foreach($normal as $c){$ins->bind_param('iissss',$tenant_id,$id,$c['tipo'],$c['campo'],$c['operador'],$c['valor']); if(!$ins->execute()) throw new RuntimeException('Falha ao salvar critérios.');} $ins->close(); $conexao->commit(); alerta_responder(true,'Alerta salvo com sucesso.',['id'=>$id]); } catch(Throwable $e){$conexao->rollback(); error_log('[AlertasAcesso] '.$e->getMessage()); alerta_responder(false,'Não foi possível salvar o alerta.',null,500);}
}
if ($acao === 'excluir') { $id=(int)($body['id']??$_POST['id']??0); if(!$id) alerta_responder(false,'Alerta inválido.',null,422); $st=$conexao->prepare('UPDATE alertas_acesso SET ativo=0 WHERE tenant_id=? AND id=?'); $st->bind_param('ii',$tenant_id,$id); $st->execute(); alerta_responder(true,'Alerta desativado.'); }
if ($acao === 'eventos') { $lim=min(100,max(1,(int)($_GET['limite']??30))); $lista=[]; $r=$conexao->query("SELECT e.*,a.nome alerta_nome,a.severidade FROM alertas_acesso_eventos e INNER JOIN alertas_acesso a ON a.id=e.alerta_id AND a.tenant_id=e.tenant_id WHERE e.tenant_id=$tenant_id ORDER BY e.detectado_em DESC LIMIT $lim"); if($r) while($x=$r->fetch_assoc()){ $x['dados']=json_decode($x['dados_json'],true); $lista[]=$x; } alerta_responder(true,'Eventos carregados',$lista); }
if ($acao === 'reconhecer') { $id=(int)($body['evento_id']??0); $uid=(int)($auth['id']??$_SESSION['usuario_id']??0); if(!$id||!$uid) alerta_responder(false,'Evento inválido.',null,422); $st=$conexao->prepare("UPDATE alertas_acesso_eventos SET status='reconhecido',reconhecido_por_usuario_id=?,reconhecido_em=NOW() WHERE tenant_id=? AND id=?"); $st->bind_param('iii',$uid,$tenant_id,$id); $st->execute(); $st2=$conexao->prepare("UPDATE alertas_acesso_leituras SET lido=1,lido_em=NOW() WHERE tenant_id=? AND evento_id=? AND usuario_id=?"); if($st2){$st2->bind_param('iii',$tenant_id,$id,$uid);$st2->execute();} alerta_responder(true,'Alerta reconhecido.'); }
alerta_responder(false,'Ação não reconhecida.',null,400);
