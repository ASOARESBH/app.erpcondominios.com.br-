<?php
/**
 * Dashboard personalizável — catálogo, configuração empresarial e preferências pessoais.
 * Todas as regras de autorização e tenant são validadas no backend.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/tenant_helper.php';

$usuario = verificarAutenticacao();
$tenantId = exigirTenantId();
$usuarioId = (int)($usuario['id'] ?? $_SESSION['usuario_id'] ?? 0);
$conexao = conectar_banco();
$acao = $_GET['acao'] ?? $_GET['action'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function dashboard_json(bool $ok, string $mensagem, $dados = null, int $status = 200): void {
    http_response_code($status);
    echo json_encode(['sucesso'=>$ok, 'mensagem'=>$mensagem, 'dados'=>$dados], JSON_UNESCAPED_UNICODE);
    exit;
}
function dashboard_admin(array $usuario): void {
    $nivel = strtolower((string)($usuario['permissao'] ?? $usuario['nivel_permissao'] ?? $_SESSION['usuario_permissao'] ?? ''));
    if (!in_array($nivel, ['admin','super_admin'], true)) dashboard_json(false, 'Permissão administrativa necessária.', null, 403);
}
function dashboard_tabelas_ok(mysqli $db): bool {
    foreach (['dashboard_widgets_catalogo','dashboard_empresa_widgets','dashboard_usuario_widgets'] as $tabela) {
        $stmt = $db->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        if (!$stmt) return false;
        $stmt->bind_param('s', $tabela); $stmt->execute();
        $ok = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0; $stmt->close();
        if (!$ok) return false;
    }
    return true;
}
function dashboard_catalogo(mysqli $db): array {
    $items = [];
    $res = $db->query('SELECT id,modulo_key,modulo_nome,modulo_icone,widget_key,widget_nome,widget_tipo,descricao,tamanho_padrao,ordem FROM dashboard_widgets_catalogo WHERE disponivel=1 ORDER BY ordem, id');
    if (!$res) throw new RuntimeException($db->error);
    while ($row = $res->fetch_assoc()) $items[] = $row;
    return $items;
}
function dashboard_body(): array {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '{}', true);
    return is_array($body) ? $body : [];
}
if (!dashboard_tabelas_ok($conexao)) dashboard_json(false, 'Estrutura não instalada. Execute sql/migration_dashboard_personalizavel_mysql57.sql.', null, 503);

try {
    if ($acao === 'catalogo' && $metodo === 'GET') {
        dashboard_json(true, 'Catálogo carregado.', ['widgets'=>dashboard_catalogo($conexao)]);
    }
    if ($acao === 'empresa_config' && $metodo === 'GET') {
        dashboard_admin($usuario);
        $catalogo = dashboard_catalogo($conexao); $habilitados = [];
        $stmt = $conexao->prepare('SELECT widget_key, habilitado FROM dashboard_empresa_widgets WHERE tenant_id=?');
        $stmt->bind_param('i', $tenantId); $stmt->execute(); $res = $stmt->get_result();
        while ($row=$res->fetch_assoc()) $habilitados[$row['widget_key']] = (int)$row['habilitado']; $stmt->close();
        foreach ($catalogo as &$item) $item['habilitado'] = array_key_exists($item['widget_key'], $habilitados) ? $habilitados[$item['widget_key']] : 1;
        dashboard_json(true, 'Configuração empresarial carregada.', ['widgets'=>$catalogo]);
    }
    if ($acao === 'empresa_config' && $metodo === 'POST') {
        dashboard_admin($usuario); $body = dashboard_body(); $selecionados = $body['widgets'] ?? [];
        if (!is_array($selecionados)) dashboard_json(false, 'Formato de widgets inválido.', null, 422);
        $validos = []; foreach (dashboard_catalogo($conexao) as $item) $validos[$item['widget_key']] = true;
        $conexao->begin_transaction();
        $stmtDesativar = $conexao->prepare('UPDATE dashboard_empresa_widgets SET habilitado=0, atualizado_por=? WHERE tenant_id=?');
        $stmtDesativar->bind_param('ii', $usuarioId, $tenantId); if (!$stmtDesativar->execute()) throw new RuntimeException($stmtDesativar->error); $stmtDesativar->close();
        $stmt = $conexao->prepare('INSERT INTO dashboard_empresa_widgets (tenant_id,widget_key,habilitado,atualizado_por) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE habilitado=VALUES(habilitado),atualizado_por=VALUES(atualizado_por)');
        foreach ($selecionados as $item) {
            $key = trim((string)($item['widget_key'] ?? '')); if (!isset($validos[$key])) continue;
            $enabled = !empty($item['habilitado']) ? 1 : 0; $stmt->bind_param('isii', $tenantId, $key, $enabled, $usuarioId);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        }
        $stmt->close(); $conexao->commit();
        dashboard_json(true, 'Whitelist do Dashboard salva.', ['total'=>count($selecionados)]);
    }
    if ($acao === 'usuario_config' && $metodo === 'GET') {
        $empresa = []; $stmt = $conexao->prepare('SELECT widget_key FROM dashboard_empresa_widgets WHERE tenant_id=? AND habilitado=1'); $stmt->bind_param('i',$tenantId); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()) $empresa[$r['widget_key']]=true; $stmt->close();
        if (!$empresa) foreach (dashboard_catalogo($conexao) as $item) $empresa[$item['widget_key']] = true;
        $preferencias = []; $stmt=$conexao->prepare('SELECT widget_key,habilitado,posicao FROM dashboard_usuario_widgets WHERE tenant_id=? AND usuario_id=? ORDER BY posicao, id'); $stmt->bind_param('ii',$tenantId,$usuarioId); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()) $preferencias[$r['widget_key']]=$r; $stmt->close();
        $widgets=[]; foreach(dashboard_catalogo($conexao) as $item) if(isset($empresa[$item['widget_key']])) { $p=$preferencias[$item['widget_key']]??null; $item['habilitado']=$p?$p['habilitado']:1; $item['posicao']=$p?(int)$p['posicao']:count($widgets); $widgets[]=$item; }
        usort($widgets, static fn($a,$b)=>(int)$a['posicao'] <=>(int)$b['posicao']); dashboard_json(true,'Configuração efetiva carregada.',['widgets'=>$widgets]);
    }
    if ($acao === 'usuario_config' && $metodo === 'POST') {
        $body=dashboard_body(); $items=$body['widgets']??[]; if(!is_array($items)) dashboard_json(false,'Formato de widgets inválido.',null,422);
        $empresa=[]; $stmt=$conexao->prepare('SELECT widget_key FROM dashboard_empresa_widgets WHERE tenant_id=? AND habilitado=1'); $stmt->bind_param('i',$tenantId); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc())$empresa[$r['widget_key']]=true; $stmt->close();
        if (!$empresa) foreach (dashboard_catalogo($conexao) as $item) $empresa[$item['widget_key']] = true;
        $conexao->begin_transaction(); $stmt=$conexao->prepare('INSERT INTO dashboard_usuario_widgets (tenant_id,usuario_id,widget_key,habilitado,posicao) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE habilitado=VALUES(habilitado),posicao=VALUES(posicao)');
        foreach($items as $pos=>$item){$key=trim((string)($item['widget_key']??'')); if(!isset($empresa[$key])) continue; $enabled=!empty($item['habilitado'])?1:0; $position=(int)$pos; $stmt->bind_param('iisii',$tenantId,$usuarioId,$key,$enabled,$position); if(!$stmt->execute())throw new RuntimeException($stmt->error);} $stmt->close(); $conexao->commit(); dashboard_json(true,'Preferências pessoais salvas.');
    }
    if ($acao === 'widget_data' && $metodo === 'GET') {
        $key=trim((string)($_GET['widget_key']??'')); $stmt=$conexao->prepare('SELECT widget_key,widget_nome FROM dashboard_widgets_catalogo WHERE widget_key=? AND disponivel=1'); $stmt->bind_param('s',$key); $stmt->execute(); $widget=$stmt->get_result()->fetch_assoc(); $stmt->close(); if(!$widget)dashboard_json(false,'Widget não encontrado.',null,404);
        // Adapters somente usam tabelas com tenant_id; fontes legadas inseguras retornam estado vazio.
        $map=['moradores_total'=>['moradores','COUNT(*)'],'veiculos_total'=>['veiculos','COUNT(*)'],'acessos_hoje'=>['registros_acesso','COUNT(*)']];
        if(!isset($map[$key])) dashboard_json(true,'Fonte ainda não disponível.', ['disponivel'=>false,'mensagem'=>'Nenhum dado disponível ainda para este widget.']);
        [$tabela,$agregado]=$map[$key]; $stmt=$conexao->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME="tenant_id"'); $stmt->bind_param('s',$tabela); $stmt->execute(); $temTenant=(int)($stmt->get_result()->fetch_assoc()['total']??0)>0; $stmt->close(); if(!$temTenant)dashboard_json(true,'Fonte legada sem isolamento.', ['disponivel'=>false,'mensagem'=>'Nenhum dado disponível ainda para este widget.']);
        $sql="SELECT {$agregado} AS total FROM `{$tabela}` WHERE tenant_id=?"; if($key==='acessos_hoje')$sql.=' AND DATE(data_hora)=CURRENT_DATE()'; $stmt=$conexao->prepare($sql); $stmt->bind_param('i',$tenantId); $stmt->execute(); $total=(int)($stmt->get_result()->fetch_assoc()['total']??0); $stmt->close(); dashboard_json(true,'Dados do widget carregados.',['disponivel'=>true,'total'=>$total]);
    }
    dashboard_json(false,'Ação não reconhecida.',null,404);
} catch (Throwable $e) {
    @$conexao->rollback(); error_log('[DASHBOARD_PERSONALIZACAO] '.$e->getMessage()); dashboard_json(false,'Erro ao processar a configuração do Dashboard.',null,500);
}
