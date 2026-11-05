<?php
/**
 * ERP CONDOMÍNIOS — EVENTOS DO CONDOMÍNIO (Administrativo > Eventos)
 *
 * GET  ?acao=listar&periodo=proximos|passados|todos&status=
 * GET  ?acao=obter&id=
 * GET  ?acao=opcoes                         → unidades com moradores ativos
 * POST ?acao=salvar   {id?, titulo, descricao, local, data_inicio, data_fim, publico, unidades[], notificar_chatin}
 * POST ?acao=cancelar {id, motivo, notificar_chatin}
 * POST ?acao=notificar {id}                 → (re)envia o aviso pelo Chat-In ao público do evento
 * RBAC: módulo "eventos" (visualizar/criar/editar/excluir), fallback nível operador.
 */
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/chatin_helper.php';
ob_end_clean();
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function eventos_responder($sucesso, $mensagem, $dados = null, $status = 200) {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code($status);
    echo json_encode(['sucesso' => (bool)$sucesso, 'mensagem' => $mensagem, 'dados' => $dados === null ? [] : $dados], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
set_exception_handler(function (Throwable $e) {
    error_log('[Eventos] ' . get_class($e) . ' codigo=' . (int)$e->getCode());
    eventos_responder(false, 'Não foi possível concluir a operação de eventos.', null, 500);
});

$auth = verificarAutenticacao(true);
$tenant_id = (int)exigirTenantId();
$c = conectar_banco();
$c->set_charset('utf8mb4');
$metodo = $_SERVER['REQUEST_METHOD'];
$dados = [];
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    if (!is_array($dados)) eventos_responder(false, 'Envie um JSON válido.', null, 400);
}
$acao = strtolower(trim((string)($_GET['acao'] ?? $dados['acao'] ?? 'listar')));
if (!chatin_tabela_existe($c, 'eventos_condominio')) eventos_responder(false, 'Módulo de eventos não instalado. Execute sql/migration_chatin_mysql57.sql.', ['instalado' => false], 409);

$rbacAtivo = function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($c);
$acaoRbac = $metodo === 'GET' ? 'visualizar' : ($acao === 'cancelar' ? 'excluir' : ((int)($dados['id'] ?? 0) > 0 || $acao === 'notificar' ? 'editar' : 'criar'));
if ($rbacAtivo) rbacExigir($c, 'eventos', $acaoRbac);
elseif ($metodo !== 'GET') verificarPermissao('operador');

function eventos_dt($valor, $obrigatorio, $rotulo) {
    $valor = trim(str_replace('T', ' ', (string)$valor));
    if ($valor === '') { if ($obrigatorio) throw new ChatInErro("Informe $rotulo.", 422); return null; }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $valor)) $valor .= ':00';
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $valor);
    if (!$d || $d->format('Y-m-d H:i:s') !== $valor) throw new ChatInErro("A $rotulo é inválida.", 422);
    return $valor;
}

function eventos_formatar(array $e) {
    $e['id'] = (int)$e['id'];
    $e['unidades'] = json_decode((string)($e['unidades_json'] ?? ''), true) ?: [];
    unset($e['unidades_json'], $e['tenant_id']);
    $e['notificados_total'] = (int)$e['notificados_total'];
    return $e;
}

function eventos_obter(mysqli $c, $tenantId, $id) {
    $e = chatin_linha($c, 'SELECT * FROM eventos_condominio WHERE tenant_id = ? AND id = ? LIMIT 1', 'ii', [(int)$tenantId, (int)$id]);
    if (!$e) throw new ChatInErro('Evento não encontrado.', 404);
    return $e;
}

/** Envia o aviso do evento pelo Chat-In ao público do evento. */
function eventos_notificar(mysqli $c, $tenantId, array $e, $cancelado = false, $motivo = '') {
    $unidades = $e['publico'] === 'UNIDADES' ? (json_decode((string)$e['unidades_json'], true) ?: []) : null;
    $dest = chatin_destinatarios_moradores($c, $tenantId, $unidades);
    $quando = date('d/m/Y', strtotime($e['data_inicio'])) . ' às ' . date('H:i', strtotime($e['data_inicio']));
    if ($e['data_fim']) $quando .= ' até ' . date('d/m/Y H:i', strtotime($e['data_fim']));
    if ($cancelado) {
        $titulo = 'Evento cancelado: ' . $e['titulo'];
        $corpo = 'O evento "' . $e['titulo'] . '" de ' . $quando . ' foi cancelado.' . ($motivo !== '' ? ' Motivo: ' . $motivo : '');
    } else {
        $titulo = 'Evento: ' . $e['titulo'];
        $corpo = $quando . (trim((string)$e['local']) !== '' ? ' — Local: ' . trim($e['local']) : '') . '.'
            . (trim((string)$e['descricao']) !== '' ? "\n\n" . trim($e['descricao']) : '');
    }
    $n = chatin_enviar_aviso($c, $tenantId, $dest, 'Administração', $titulo, $corpo, 'evento', (int)$e['id']);
    chatin_q($c, 'UPDATE eventos_condominio SET notificado_chatin_em = NOW(), notificados_total = ? WHERE tenant_id = ? AND id = ?', 'iii', [$n, (int)$tenantId, (int)$e['id']])->close();
    return $n;
}

try {
    if ($metodo === 'GET' && $acao === 'opcoes') {
        $u = chatin_linhas($c, "SELECT DISTINCT unidade FROM moradores WHERE tenant_id = ? AND ativo = 1 AND unidade IS NOT NULL AND unidade <> '' ORDER BY unidade", 'i', [$tenant_id]);
        eventos_responder(true, 'OK', ['unidades' => array_map(function ($l) { return $l['unidade']; }, $u), 'chatin_instalado' => chatin_instalado($c)]);
    }
    if ($metodo === 'GET' && $acao === 'listar') {
        $periodo = (string)($_GET['periodo'] ?? 'proximos');
        $where = 'tenant_id = ?'; $tipos = 'i'; $params = [$tenant_id];
        if ($periodo === 'proximos') $where .= " AND COALESCE(data_fim, data_inicio) >= NOW() - INTERVAL 1 DAY";
        elseif ($periodo === 'passados') $where .= " AND COALESCE(data_fim, data_inicio) < NOW() - INTERVAL 1 DAY";
        $status = strtoupper((string)($_GET['status'] ?? ''));
        if (in_array($status, ['AGENDADO', 'CANCELADO', 'REALIZADO'], true)) { $where .= ' AND status = ?'; $tipos .= 's'; $params[] = $status; }
        $ordem = $periodo === 'passados' ? 'data_inicio DESC' : 'data_inicio ASC';
        $lista = array_map('eventos_formatar', chatin_linhas($c, "SELECT * FROM eventos_condominio WHERE $where ORDER BY $ordem LIMIT 200", $tipos, $params));
        eventos_responder(true, 'OK', ['eventos' => $lista]);
    }
    if ($metodo === 'GET' && $acao === 'obter') {
        eventos_responder(true, 'OK', eventos_formatar(eventos_obter($c, $tenant_id, (int)($_GET['id'] ?? 0))));
    }
    if ($metodo === 'POST' && $acao === 'salvar') {
        $id = (int)($dados['id'] ?? 0);
        $titulo = chatin_texto($dados['titulo'] ?? '', 150);
        if ($titulo === '') throw new ChatInErro('Informe o título do evento.', 422);
        $descricao = chatin_texto($dados['descricao'] ?? '', 2000);
        $local = chatin_texto($dados['local'] ?? '', 150);
        $inicio = eventos_dt($dados['data_inicio'] ?? '', true, 'data de início');
        $fim = eventos_dt($dados['data_fim'] ?? '', false, 'data de término');
        if ($fim !== null && $fim < $inicio) throw new ChatInErro('O término deve ser depois do início.', 422);
        $publico = strtoupper((string)($dados['publico'] ?? 'TODOS')) === 'UNIDADES' ? 'UNIDADES' : 'TODOS';
        $unidades = null;
        if ($publico === 'UNIDADES') {
            $lista = is_array($dados['unidades'] ?? null) ? $dados['unidades'] : [];
            $lista = array_values(array_unique(array_filter(array_map(function ($u) { return chatin_texto($u, 50); }, $lista), 'strlen')));
            if (!$lista) throw new ChatInErro('Selecione ao menos uma unidade.', 422);
            $unidades = json_encode($lista, JSON_UNESCAPED_UNICODE);
        }
        if ($id > 0) {
            $atual = eventos_obter($c, $tenant_id, $id);
            if ($atual['status'] === 'CANCELADO') throw new ChatInErro('Evento cancelado não pode ser editado.', 409);
            chatin_q($c, 'UPDATE eventos_condominio SET titulo = ?, descricao = ?, local = ?, data_inicio = ?, data_fim = ?, publico = ?, unidades_json = ? WHERE tenant_id = ? AND id = ?',
                'sssssssii', [$titulo, $descricao, $local, $inicio, $fim, $publico, $unidades, $tenant_id, $id])->close();
        } else {
            if ($inicio < date('Y-m-d H:i:s', strtotime('-1 day'))) throw new ChatInErro('A data de início não pode estar no passado.', 422);
            chatin_q($c, 'INSERT INTO eventos_condominio (tenant_id, titulo, descricao, local, data_inicio, data_fim, publico, unidades_json, criado_por, criado_por_nome) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'isssssssis', [$tenant_id, $titulo, $descricao, $local, $inicio, $fim, $publico, $unidades, (int)$auth['id'], (string)($auth['nome'] ?? '')])->close();
            $id = (int)$c->insert_id;
        }
        $notificados = null;
        if (!empty($dados['notificar_chatin'])) $notificados = eventos_notificar($c, $tenant_id, eventos_obter($c, $tenant_id, $id));
        eventos_responder(true, 'Evento salvo.' . ($notificados !== null ? " Aviso enviado pelo Chat-In a $notificados morador(es)." : ''),
            ['id' => $id, 'notificados' => $notificados, 'evento' => eventos_formatar(eventos_obter($c, $tenant_id, $id))]);
    }
    if ($metodo === 'POST' && $acao === 'cancelar') {
        $e = eventos_obter($c, $tenant_id, (int)($dados['id'] ?? 0));
        if ($e['status'] === 'CANCELADO') throw new ChatInErro('O evento já está cancelado.', 409);
        $motivo = chatin_texto($dados['motivo'] ?? '', 255);
        chatin_q($c, "UPDATE eventos_condominio SET status = 'CANCELADO' WHERE tenant_id = ? AND id = ?", 'ii', [$tenant_id, (int)$e['id']])->close();
        $n = !empty($dados['notificar_chatin']) ? eventos_notificar($c, $tenant_id, $e, true, $motivo) : null;
        eventos_responder(true, 'Evento cancelado.' . ($n !== null ? " Aviso enviado a $n morador(es)." : ''), ['notificados' => $n]);
    }
    if ($metodo === 'POST' && $acao === 'notificar') {
        $e = eventos_obter($c, $tenant_id, (int)($dados['id'] ?? 0));
        if ($e['status'] === 'CANCELADO') throw new ChatInErro('Evento cancelado.', 409);
        $n = eventos_notificar($c, $tenant_id, $e);
        eventos_responder(true, "Aviso enviado pelo Chat-In a $n morador(es).", ['notificados' => $n]);
    }
    eventos_responder(false, 'Ação não permitida.', null, 405);
} catch (ChatInErro $e) {
    eventos_responder(false, $e->getMessage(), null, $e->status);
}
