<?php
/**
 * ERP Condomínios — API Vigilante / Rondas
 *
 * Área administrativa: exclusiva do Super-Admin em contexto global.
 * Dados operacionais: sempre isolados pelo tenant selecionado e persistido
 * na sessão. A leitura QR resolve o tenant pelo token aleatório do ponto.
 */
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/tenant_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/i', $origin) || preg_match('/^https?:\/\/localhost(:\d+)?$/', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function rv_json(bool $ok, string $mensagem, $dados = null, int $codigo = 200): void {
    http_response_code($codigo);
    $retorno = ['sucesso' => $ok, 'mensagem' => $mensagem];
    if ($dados !== null) $retorno['dados'] = $dados;
    echo json_encode($retorno, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = $_POST;
if ($raw !== '') {
    $json = json_decode($raw, true);
    if (is_array($json)) $input = array_merge($input, $json);
}
$acao = $_GET['acao'] ?? $_GET['action'] ?? $input['acao'] ?? $input['action'] ?? '';
$conn = conectar_banco();
if (!$conn) rv_json(false, 'Não foi possível conectar ao banco de dados.', null, 500);
$conn->set_charset('utf8mb4');

function rv_auditar(mysqli $conn, int $tenantId, ?int $rotaId, string $acao, string $descricao, array $dados = []): void {
    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0) ?: null;
    $ip = substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $json = $dados ? json_encode($dados, JSON_UNESCAPED_UNICODE) : null;
    $stmt = $conn->prepare('INSERT INTO ronda_auditoria (tenant_id, rota_id, usuario_id, acao, descricao, dados_json, ip) VALUES (?,?,?,?,?,?,?)');
    if (!$stmt) return;
    $stmt->bind_param('iiissss', $tenantId, $rotaId, $usuarioId, $acao, $descricao, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

function rv_exigir_contexto_operacional(): int {
    // Vigilante é um módulo operacional de Manutenção. O tenant nunca vem de
    // GET/POST: é sempre resolvido pela sessão autenticada atual.
    verificarAutenticacao(true, 'operador');
    return (int)exigirTenantId();
}

function rv_tenant_contexto(mysqli $conn, int $tenantId): array {
    $stmt = $conn->prepare("SELECT id, nome_fantasia, razao_social FROM tenants WHERE id=? AND status='ativo' LIMIT 1");
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tenant) rv_json(false, 'O condomínio da sessão não está ativo.', null, 403);
    return $tenant;
}

function rv_dias_normalizar($dias): string {
    if (is_string($dias)) $dias = preg_split('/\s*,\s*/', trim($dias));
    if (!is_array($dias)) $dias = [];
    $saida = [];
    foreach ($dias as $dia) {
        $dia = (int)$dia;
        if ($dia >= 0 && $dia <= 6 && !in_array($dia, $saida, true)) $saida[] = $dia;
    }
    sort($saida);
    return $saida ? implode(',', $saida) : '0,1,2,3,4,5,6';
}

function rv_rota_ativa_hoje(array $rota, ?int $timestamp = null): bool {
    $timestamp = $timestamp ?: time();
    $dia = (int)date('w', $timestamp);
    $dias = array_filter(explode(',', (string)$rota['dias_semana']), 'strlen');
    return in_array((string)$dia, $dias, true);
}

function rv_ciclo(array $rota, ?int $timestamp = null): array {
    $timestamp = $timestamp ?: time();
    $data = date('Y-m-d', $timestamp);
    $inicio = strtotime($data . ' ' . ($rota['hora_inicio'] ?: '00:00:00'));
    $intervalo = max(5, (int)$rota['intervalo_minutos']) * 60;
    $indice = $timestamp < $inicio ? -1 : (int)floor(($timestamp - $inicio) / $intervalo);
    $previsto = $inicio + (max(0, $indice) * $intervalo);
    $limiteRepeticoes = max(1, (int)$rota['repeticoes_por_dia']);
    $fimConfigurado = !empty($rota['hora_fim']) ? strtotime($data . ' ' . $rota['hora_fim']) : null;
    $ativo = $indice >= 0 && $indice < $limiteRepeticoes && (!$fimConfigurado || $timestamp <= $fimConfigurado);
    return [
        'indice' => $indice,
        'ativo' => $ativo,
        'previsto_em' => date('Y-m-d H:i:s', $previsto),
        'chave_base' => date('Ymd', $timestamp) . ':' . max(0, $indice),
        'tolerancia_segundos' => max(0, (int)$rota['tolerancia_minutos']) * 60,
        'timestamp_previsto' => $previsto,
    ];
}

function rv_status_sla(array $rota, ?int $timestamp = null): array {
    $timestamp = $timestamp ?: time();
    $ciclo = rv_ciclo($rota, $timestamp);
    if (!$ciclo['ativo']) return ['fora_janela', 0, $ciclo];
    $atraso = max(0, (int)floor(($timestamp - ($ciclo['timestamp_previsto'] + $ciclo['tolerancia_segundos'])) / 60));
    return [$atraso > 0 ? 'atrasado' : 'no_prazo', $atraso, $ciclo];
}

function rv_rota_por_id(mysqli $conn, int $tenantId, int $rotaId): array {
    $stmt = $conn->prepare('SELECT * FROM ronda_rotas WHERE tenant_id=? AND id=? LIMIT 1');
    $stmt->bind_param('ii', $tenantId, $rotaId);
    $stmt->execute();
    $rota = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$rota) rv_json(false, 'Rota não encontrada.', null, 404);
    return $rota;
}

function rv_listar_rotas(mysqli $conn, int $tenantId): array {
    $stmt = $conn->prepare("SELECT r.*, 
        (SELECT COUNT(*) FROM ronda_pontos p WHERE p.tenant_id=r.tenant_id AND p.rota_id=r.id AND p.ativo=1) AS total_pontos,
        (SELECT COUNT(*) FROM ronda_vigilantes v WHERE v.tenant_id=r.tenant_id AND v.rota_id=r.id AND v.ativo=1) AS total_vigilantes
        FROM ronda_rotas r WHERE r.tenant_id=? ORDER BY r.ativo DESC, r.nome ASC");
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $rotas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rotas as &$rota) {
        $rota['dias'] = array_map('intval', array_filter(explode(',', $rota['dias_semana']), 'strlen'));
        $p = $conn->prepare('SELECT id,nome,localizacao,instrucoes,ordem,token_qr,ativo FROM ronda_pontos WHERE tenant_id=? AND rota_id=? ORDER BY ordem,id');
        $p->bind_param('ii', $tenantId, $rota['id']); $p->execute();
        $rota['pontos'] = $p->get_result()->fetch_all(MYSQLI_ASSOC); $p->close();
        $v = $conn->prepare("SELECT rv.id AS vinculo_id, rv.colaborador_id, c.nome, c.cargo, c.departamento
                             FROM ronda_vigilantes rv INNER JOIN rh_colaboradores c ON c.id=rv.colaborador_id AND c.tenant_id=rv.tenant_id
                             WHERE rv.tenant_id=? AND rv.rota_id=? AND rv.ativo=1 ORDER BY c.nome");
        $v->bind_param('ii', $tenantId, $rota['id']); $v->execute();
        $rota['vigilantes'] = $v->get_result()->fetch_all(MYSQLI_ASSOC); $v->close();
    }
    unset($rota);
    return $rotas;
}

// QR lido em celular: somente consulta do ponto e registro. Demais ações são
// exclusivamente administrativas e exigem Super-Admin em contexto global.
if (in_array($acao, ['qr_detalhe', 'registrar_leitura'], true)) {
    if ($acao === 'qr_detalhe') {
        $token = strtolower(trim((string)($_GET['token'] ?? $input['token'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) rv_json(false, 'QR Code inválido.', null, 400);
        $stmt = $conn->prepare("SELECT p.id AS ponto_id,p.tenant_id,p.nome AS ponto_nome,p.localizacao,p.instrucoes,p.token_qr,
                                       r.id AS rota_id,r.nome AS rota_nome,r.intervalo_minutos,r.tolerancia_minutos,r.dias_semana,
                                       t.nome_fantasia AS condominio
                                FROM ronda_pontos p INNER JOIN ronda_rotas r ON r.id=p.rota_id AND r.tenant_id=p.tenant_id
                                INNER JOIN tenants t ON t.id=p.tenant_id
                                WHERE p.token_qr=? AND p.ativo=1 AND r.ativo=1 AND t.status='ativo' LIMIT 1");
        $stmt->bind_param('s', $token); $stmt->execute();
        $ponto = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$ponto) rv_json(false, 'Este ponto QR está inativo ou não foi encontrado.', null, 404);
        $tenantId = (int)$ponto['tenant_id'];
        $stmt = $conn->prepare("SELECT c.id,c.nome,c.cargo FROM ronda_vigilantes rv
                                INNER JOIN rh_colaboradores c ON c.id=rv.colaborador_id AND c.tenant_id=rv.tenant_id
                                WHERE rv.tenant_id=(SELECT tenant_id FROM ronda_pontos WHERE token_qr=? LIMIT 1)
                                  AND rv.rota_id=? AND rv.ativo=1 AND c.ativo=1 ORDER BY c.nome");
        $stmt->bind_param('si', $token, $ponto['rota_id']); $stmt->execute();
        $ponto['vigilantes'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        unset($ponto['token_qr']);
        rv_json(true, 'Ponto de ronda validado.', $ponto);
    }

    $token = strtolower(trim((string)($input['token'] ?? '')));
    $colaboradorId = (int)($input['colaborador_id'] ?? 0);
    if (!preg_match('/^[a-f0-9]{64}$/', $token) || $colaboradorId <= 0) rv_json(false, 'Informe um QR Code válido e o vigilante responsável.', null, 400);
    $stmt = $conn->prepare("SELECT p.id AS ponto_id,p.tenant_id,p.rota_id,p.nome AS ponto_nome,r.*
                            FROM ronda_pontos p INNER JOIN ronda_rotas r ON r.id=p.rota_id AND r.tenant_id=p.tenant_id
                            INNER JOIN tenants t ON t.id=p.tenant_id
                            WHERE p.token_qr=? AND p.ativo=1 AND r.ativo=1 AND t.status='ativo' LIMIT 1");
    $stmt->bind_param('s', $token); $stmt->execute();
    $ponto = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$ponto) rv_json(false, 'Ponto QR inválido ou inativo.', null, 404);
    $tenantId = (int)$ponto['tenant_id'];
    if (!rv_rota_ativa_hoje($ponto)) rv_json(false, 'Esta rota não está programada para hoje.', null, 409);
    $check = $conn->prepare('SELECT 1 FROM ronda_vigilantes WHERE tenant_id=? AND rota_id=? AND colaborador_id=? AND ativo=1 LIMIT 1');
    $check->bind_param('iii', $tenantId, $ponto['rota_id'], $colaboradorId); $check->execute();
    $autorizado = $check->get_result()->fetch_assoc(); $check->close();
    if (!$autorizado) rv_json(false, 'O vigilante selecionado não está vinculado a esta rota.', null, 403);
    [$sla, $atraso, $ciclo] = rv_status_sla($ponto);
    if ($sla === 'fora_janela') rv_json(false, 'Esta leitura está fora da janela programada da rota.', null, 409);
    $chave = hash('sha256', $tenantId . '|' . $ponto['rota_id'] . '|' . $colaboradorId . '|' . $ciclo['chave_base']);
    $lat = isset($input['latitude']) && is_numeric($input['latitude']) ? (float)$input['latitude'] : null;
    $lng = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : null;
    $prec = isset($input['precisao_metros']) && is_numeric($input['precisao_metros']) ? (float)$input['precisao_metros'] : null;
    $previsto = $ciclo['previsto_em'];
    $ip = substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $stmt = $conn->prepare('INSERT INTO ronda_registros (tenant_id,rota_id,ponto_id,colaborador_id,ciclo_chave,previsto_em,status_sla,atraso_minutos,latitude,longitude,precisao_metros,ip,user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('iiiisssidddss', $tenantId, $ponto['rota_id'], $ponto['ponto_id'], $colaboradorId, $chave, $previsto, $sla, $atraso, $lat, $lng, $prec, $ip, $ua);
    $ok = $stmt->execute();
    $errno = $stmt->errno; $stmt->close();
    if (!$ok && $errno === 1062) rv_json(false, 'Este ponto já foi registrado neste ciclo de ronda.', ['status_sla' => $sla], 409);
    if (!$ok) rv_json(false, 'Não foi possível registrar a leitura da ronda.', null, 500);
    rv_auditar($conn, $tenantId, (int)$ponto['rota_id'], 'LEITURA_QR', 'Leitura registrada no ponto ' . $ponto['ponto_nome'], ['colaborador_id'=>$colaboradorId,'status_sla'=>$sla]);
    rv_json(true, $sla === 'atrasado' ? 'Leitura registrada com atraso de SLA.' : 'Leitura de ronda registrada no prazo.', ['status_sla'=>$sla,'atraso_minutos'=>$atraso,'ponto'=>$ponto['ponto_nome'],'rota'=>$ponto['nome']]);
}

$tenantId = rv_exigir_contexto_operacional();
$tenant = rv_tenant_contexto($conn, $tenantId);

switch ($acao) {
    case 'contexto':
        rv_json(true, 'Condomínio selecionado.', $tenant);

    case 'listar_colaboradores':
        $busca = '%' . trim((string)($_GET['busca'] ?? '')) . '%';
        $stmt = $conn->prepare("SELECT id,nome,cargo,departamento,celular,email FROM rh_colaboradores
                                WHERE tenant_id=? AND ativo=1 AND (nome LIKE ? OR cargo LIKE ? OR departamento LIKE ?)
                                ORDER BY nome LIMIT 100");
        $stmt->bind_param('isss', $tenantId, $busca, $busca, $busca); $stmt->execute();
        $dados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        rv_json(true, 'Colaboradores carregados.', $dados);

    case 'listar_rotas':
        rv_json(true, 'Rotas carregadas.', rv_listar_rotas($conn, $tenantId));

    case 'salvar_rota':
        $id = (int)($input['id'] ?? 0);
        $nome = trim((string)($input['nome'] ?? ''));
        $descricao = trim((string)($input['descricao'] ?? ''));
        $horaInicio = trim((string)($input['hora_inicio'] ?? '00:00'));
        $horaFim = trim((string)($input['hora_fim'] ?? ''));
        $intervalo = max(5, min(1440, (int)($input['intervalo_minutos'] ?? 60)));
        $repeticoes = max(1, min(288, (int)($input['repeticoes_por_dia'] ?? 1)));
        $tolerancia = max(0, min(240, (int)($input['tolerancia_minutos'] ?? 10)));
        $dias = rv_dias_normalizar($input['dias_semana'] ?? []);
        $ativo = !empty($input['ativo']) ? 1 : 0;
        if ($nome === '') rv_json(false, 'Informe o nome da rota.', null, 400);
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $horaInicio)) rv_json(false, 'Hora inicial inválida.', null, 400);
        $horaInicio = substr($horaInicio, 0, 5) . ':00';
        $horaFim = $horaFim ? substr($horaFim, 0, 5) . ':00' : null;
        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE ronda_rotas SET nome=?,descricao=NULLIF(?,\'\'),hora_inicio=?,hora_fim=?,intervalo_minutos=?,repeticoes_por_dia=?,tolerancia_minutos=?,dias_semana=?,ativo=? WHERE tenant_id=? AND id=?');
            $stmt->bind_param('ssssiiisiii', $nome,$descricao,$horaInicio,$horaFim,$intervalo,$repeticoes,$tolerancia,$dias,$ativo,$tenantId,$id);
            $stmt->execute(); $alteradas=$stmt->affected_rows; $stmt->close();
            if ($alteradas < 0) rv_json(false, 'Não foi possível atualizar a rota.', null, 500);
            rv_auditar($conn,$tenantId,$id,'ROTA_ATUALIZADA','Rota atualizada: '.$nome);
            rv_json(true,'Rota atualizada com sucesso.',['id'=>$id]);
        }
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0) ?: null;
        $stmt = $conn->prepare('INSERT INTO ronda_rotas (tenant_id,nome,descricao,hora_inicio,hora_fim,intervalo_minutos,repeticoes_por_dia,tolerancia_minutos,dias_semana,ativo,criado_por_usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('issssiiisii',$tenantId,$nome,$descricao,$horaInicio,$horaFim,$intervalo,$repeticoes,$tolerancia,$dias,$ativo,$usuarioId);
        if (!$stmt->execute()) rv_json(false,'Não foi possível criar a rota.',null,500);
        $novoId=(int)$conn->insert_id; $stmt->close();
        rv_auditar($conn,$tenantId,$novoId,'ROTA_CRIADA','Rota criada: '.$nome);
        rv_json(true,'Rota criada com sucesso.',['id'=>$novoId]);

    case 'remover_rota':
        $rotaId=(int)($input['id'] ?? 0); $rota=rv_rota_por_id($conn,$tenantId,$rotaId);
        $conn->begin_transaction();
        try {
            $s=$conn->prepare('UPDATE ronda_vigilantes SET ativo=0 WHERE tenant_id=? AND rota_id=?'); $s->bind_param('ii',$tenantId,$rotaId); $s->execute(); $s->close();
            $s=$conn->prepare('UPDATE ronda_pontos SET ativo=0 WHERE tenant_id=? AND rota_id=?'); $s->bind_param('ii',$tenantId,$rotaId); $s->execute(); $s->close();
            $s=$conn->prepare('UPDATE ronda_rotas SET ativo=0 WHERE tenant_id=? AND id=?'); $s->bind_param('ii',$tenantId,$rotaId); $s->execute(); $s->close();
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); rv_json(false,'Não foi possível arquivar a rota.',null,500); }
        rv_auditar($conn,$tenantId,$rotaId,'ROTA_ARQUIVADA','Rota arquivada: '.$rota['nome']);
        rv_json(true,'Rota arquivada. Os registros históricos foram preservados.');

    case 'salvar_ponto':
        $rotaId=(int)($input['rota_id'] ?? 0); rv_rota_por_id($conn,$tenantId,$rotaId);
        $id=(int)($input['id'] ?? 0); $nome=trim((string)($input['nome'] ?? '')); $local=trim((string)($input['localizacao'] ?? '')); $instr=trim((string)($input['instrucoes'] ?? ''));
        $ordem=max(1,min(999,(int)($input['ordem'] ?? 1))); $ativo=!empty($input['ativo'])?1:0;
        if($nome==='') rv_json(false,'Informe o nome do ponto.',null,400);
        if($id>0){
            $stmt=$conn->prepare('UPDATE ronda_pontos SET nome=?,localizacao=NULLIF(?,\'\'),instrucoes=NULLIF(?,\'\'),ordem=?,ativo=? WHERE tenant_id=? AND rota_id=? AND id=?');
            $stmt->bind_param('sssiiiii',$nome,$local,$instr,$ordem,$ativo,$tenantId,$rotaId,$id); if(!$stmt->execute()) rv_json(false,'Não foi possível atualizar o ponto.',null,500); $stmt->close();
            rv_auditar($conn,$tenantId,$rotaId,'PONTO_ATUALIZADO','Ponto atualizado: '.$nome,['ponto_id'=>$id]); rv_json(true,'Ponto atualizado.',['id'=>$id]);
        }
        $token=bin2hex(random_bytes(32));
        $stmt=$conn->prepare('INSERT INTO ronda_pontos (tenant_id,rota_id,nome,localizacao,instrucoes,ordem,token_qr,ativo) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->bind_param('iisssisi',$tenantId,$rotaId,$nome,$local,$instr,$ordem,$token,$ativo);
        if(!$stmt->execute()) rv_json(false,'Não foi possível criar o ponto. Verifique se a ordem já está em uso.',null,409);
        $novoId=(int)$conn->insert_id; $stmt->close();
        rv_auditar($conn,$tenantId,$rotaId,'PONTO_CRIADO','Ponto QR criado: '.$nome,['ponto_id'=>$novoId]);
        rv_json(true,'Ponto QR criado com sucesso.',['id'=>$novoId,'token_qr'=>$token]);

    case 'regenerar_qr':
        $pontoId=(int)($input['id'] ?? 0); $token=bin2hex(random_bytes(32));
        $stmt=$conn->prepare('UPDATE ronda_pontos SET token_qr=? WHERE tenant_id=? AND id=?'); $stmt->bind_param('sii',$token,$tenantId,$pontoId); $stmt->execute();
        if($stmt->affected_rows!==1){$stmt->close();rv_json(false,'Ponto não encontrado.',null,404);} $stmt->close();
        rv_json(true,'QR Code regenerado. O adesivo anterior foi invalidado.',['token_qr'=>$token]);

    case 'remover_ponto':
        $pontoId=(int)($input['id'] ?? 0); $stmt=$conn->prepare('UPDATE ronda_pontos SET ativo=0 WHERE tenant_id=? AND id=?'); $stmt->bind_param('ii',$tenantId,$pontoId); $stmt->execute();
        if($stmt->affected_rows!==1){$stmt->close();rv_json(false,'Ponto não encontrado.',null,404);} $stmt->close(); rv_json(true,'Ponto arquivado.');

    case 'vincular_vigilante':
        $rotaId=(int)($input['rota_id'] ?? 0); $colaboradorId=(int)($input['colaborador_id'] ?? 0); rv_rota_por_id($conn,$tenantId,$rotaId);
        $s=$conn->prepare('SELECT id,nome FROM rh_colaboradores WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1'); $s->bind_param('ii',$tenantId,$colaboradorId);$s->execute();$c=$s->get_result()->fetch_assoc();$s->close(); if(!$c)rv_json(false,'Colaborador ativo não encontrado.',null,404);
        $usuarioId=(int)($_SESSION['usuario_id'] ?? 0) ?: null;
        $s=$conn->prepare('INSERT INTO ronda_vigilantes (tenant_id,rota_id,colaborador_id,ativo,vinculado_por_usuario_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE ativo=1,atualizado_em=NOW()');
        $ativo=1;$s->bind_param('iiiii',$tenantId,$rotaId,$colaboradorId,$ativo,$usuarioId);if(!$s->execute())rv_json(false,'Não foi possível vincular o vigilante.',null,500);$s->close();
        rv_auditar($conn,$tenantId,$rotaId,'VIGILANTE_VINCULADO','Vigilante vinculado: '.$c['nome'],['colaborador_id'=>$colaboradorId]); rv_json(true,'Vigilante vinculado à rota.');

    case 'remover_vigilante':
        $vinculoId=(int)($input['id'] ?? 0);$s=$conn->prepare('UPDATE ronda_vigilantes SET ativo=0 WHERE tenant_id=? AND id=?');$s->bind_param('ii',$tenantId,$vinculoId);$s->execute();if($s->affected_rows!==1){$s->close();rv_json(false,'Vínculo não encontrado.',null,404);}$s->close();rv_json(true,'Vigilante removido da rota.');

    case 'dashboard':
        $rotas=rv_listar_rotas($conn,$tenantId); $agora=time(); $alertas=[]; $emDia=0;
        foreach($rotas as $rota){
            if(!(int)$rota['ativo'] || !rv_rota_ativa_hoje($rota,$agora) || empty($rota['vigilantes']) || empty($rota['pontos'])) continue;
            [$sla,$atraso,$ciclo]=rv_status_sla($rota,$agora);
            if ($sla === 'fora_janela') continue;
            foreach($rota['vigilantes'] as $vig){
                $s=$conn->prepare('SELECT COUNT(*) c FROM ronda_registros WHERE tenant_id=? AND rota_id=? AND colaborador_id=? AND ciclo_chave LIKE ?');
                $prefix=hash('sha256',$tenantId.'|'.$rota['id'].'|'.$vig['colaborador_id'].'|'.$ciclo['chave_base']);
                $s->bind_param('iiis',$tenantId,$rota['id'],$vig['colaborador_id'],$prefix);$s->execute();$feito=(int)($s->get_result()->fetch_assoc()['c']??0);$s->close();
                if($feito < (int)$rota['total_pontos'] && $sla==='atrasado')$alertas[]=['rota_id'=>(int)$rota['id'],'rota'=>$rota['nome'],'vigilante'=>$vig['nome'],'atraso_minutos'=>$atraso,'pontos_feitos'=>$feito,'pontos_total'=>(int)$rota['total_pontos'],'previsto_em'=>$ciclo['previsto_em']]; else $emDia++;
            }
        }
        $hoje=$conn->prepare("SELECT COUNT(*) total, SUM(status_sla='no_prazo') no_prazo, SUM(status_sla='atrasado') atrasados FROM ronda_registros WHERE tenant_id=? AND DATE(registrado_em)=CURDATE()");$hoje->bind_param('i',$tenantId);$hoje->execute();$resHoje=$hoje->get_result()->fetch_assoc();$hoje->close();
        rv_json(true,'Dashboard de rondas carregado.',['rotas'=>$rotas,'alertas'=>$alertas,'kpis'=>['rotas_ativas'=>count(array_filter($rotas,fn($r)=>(int)$r['ativo']===1)),'vigilantes'=>array_sum(array_map(fn($r)=>count($r['vigilantes']),$rotas)),'leituras_hoje'=>(int)($resHoje['total']??0),'atrasos_hoje'=>(int)($resHoje['atrasados']??0),'em_dia'=>$emDia]]);

    case 'relatorio':
        $dataDe=trim((string)($_GET['data_de'] ?? date('Y-m-d',strtotime('-7 days'))));$dataAte=trim((string)($_GET['data_ate'] ?? date('Y-m-d')));$rotaId=(int)($_GET['rota_id']??0);$colaboradorId=(int)($_GET['colaborador_id']??0);
        $where=['rr.tenant_id=?','DATE(rr.registrado_em)>=?','DATE(rr.registrado_em)<=?'];$types='iss';$params=[$tenantId,$dataDe,$dataAte];
        if($rotaId>0){$where[]='rr.rota_id=?';$types.='i';$params[]=$rotaId;}if($colaboradorId>0){$where[]='rr.colaborador_id=?';$types.='i';$params[]=$colaboradorId;}
        $w=implode(' AND ',$where);$sql="SELECT rr.*,r.nome rota_nome,p.nome ponto_nome,c.nome vigilante_nome FROM ronda_registros rr INNER JOIN ronda_rotas r ON r.id=rr.rota_id AND r.tenant_id=rr.tenant_id INNER JOIN ronda_pontos p ON p.id=rr.ponto_id AND p.tenant_id=rr.tenant_id INNER JOIN rh_colaboradores c ON c.id=rr.colaborador_id AND c.tenant_id=rr.tenant_id WHERE $w ORDER BY rr.registrado_em DESC LIMIT 1000";
        $s=$conn->prepare($sql);$s->bind_param($types,...$params);$s->execute();$linhas=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $resumo=['total'=>count($linhas),'no_prazo'=>count(array_filter($linhas,fn($l)=>$l['status_sla']==='no_prazo')),'atrasado'=>count(array_filter($linhas,fn($l)=>$l['status_sla']==='atrasado'))];
        rv_json(true,'Relatório carregado.',['linhas'=>$linhas,'resumo'=>$resumo]);

    default: rv_json(false,'Ação inválida.',null,400);
}
