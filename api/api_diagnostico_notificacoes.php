<?php
/**
 * Auditoria de notificações do Portal do Morador.
 *
 * Uso restrito a administrador autenticado. Nunca retorna token FCM completo
 * nem credenciais Firebase. Serve para localizar o ponto de falha entre
 * morador, token, evento persistente e FCM.
 */
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/protocol_notification_helper.php';
require_once __DIR__ . '/helpers/access_control_notification_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

verificarAutenticacao(true, 'admin');
$tenant_id = exigirTenantId();
$metodo = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$dados = $metodo === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

function diagnostico_json(bool $sucesso, string $mensagem, array $dados = []): void {
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function diagnostico_morador(mysqli $conexao, int $tenant_id, int $morador_id): ?array {
    $stmt = $conexao->prepare('SELECT id, tenant_id, nome, unidade, ativo FROM moradores WHERE id = ? AND tenant_id = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('ii', $morador_id, $tenant_id);
    $stmt->execute();
    $morador = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $morador;
}

function diagnostico_config(mysqli $conexao, int $tenant_id): array {
    $resultado = [];
    if (!protocolo_notificacao_tabela_existe($conexao, 'pwa_configuracoes')) return $resultado;
    $stmt = $conexao->prepare("SELECT chave, valor FROM pwa_configuracoes WHERE tenant_id = ? AND chave IN ('fcm_project_id', 'push_encomenda_ativo', 'push_controle_acesso_ativo')");
    if (!$stmt) return $resultado;
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    while ($linha = $stmt->get_result()->fetch_assoc()) {
        $resultado[$linha['chave']] = $linha['valor'];
    }
    $stmt->close();
    return $resultado;
}

if ($action === 'resumo' && $metodo === 'GET') {
    $morador_id = (int)($_GET['morador_id'] ?? 0);
    if ($morador_id <= 0) diagnostico_json(false, 'Morador obrigatório.');
    $morador = diagnostico_morador($conexao, $tenant_id, $morador_id);
    if (!$morador) diagnostico_json(false, 'Morador não encontrado neste tenant.');

    $tokens = [];
    if (protocolo_notificacao_tabela_existe($conexao, 'pwa_fcm_tokens')) {
        $stmt = $conexao->prepare('SELECT id, plataforma, device_info, ativo, ultimo_uso, fcm_token FROM pwa_fcm_tokens WHERE tenant_id = ? AND morador_id = ? ORDER BY ativo DESC, ultimo_uso DESC');
        if ($stmt) {
            $stmt->bind_param('ii', $tenant_id, $morador_id);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $token) {
                $tokens[] = [
                    'token_id' => (int)$token['id'],
                    'plataforma' => $token['plataforma'],
                    'device_info' => $token['device_info'],
                    'ativo' => (bool)$token['ativo'],
                    'ultimo_uso' => $token['ultimo_uso'],
                    'token_final' => substr((string)$token['fcm_token'], -12),
                ];
            }
            $stmt->close();
        }
    }

    $eventos = [];
    if (protocolo_notificacao_tabela_existe($conexao, 'notificacoes_morador')) {
        $stmt = $conexao->prepare('SELECT id, registro_acesso_id, tipo, titulo, push_status, push_detalhe, criado_em, atualizado_em FROM notificacoes_morador WHERE tenant_id = ? AND morador_id = ? ORDER BY criado_em DESC LIMIT 20');
        if ($stmt) {
            $stmt->bind_param('ii', $tenant_id, $morador_id);
            $stmt->execute();
            $eventos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    diagnostico_json(true, 'Resumo de notificações obtido.', [
        'morador' => $morador,
        'configuracoes' => diagnostico_config($conexao, $tenant_id),
        'tokens' => $tokens,
        'eventos' => $eventos,
    ]);
}

if ($action === 'testar_push' && $metodo === 'POST') {
    $morador_id = (int)($dados['morador_id'] ?? 0);
    if ($morador_id <= 0) diagnostico_json(false, 'Morador obrigatório.');
    $morador = diagnostico_morador($conexao, $tenant_id, $morador_id);
    if (!$morador) diagnostico_json(false, 'Morador não encontrado neste tenant.');

    $configuracoes = diagnostico_config($conexao, $tenant_id);
    $project_id = trim((string)($configuracoes['fcm_project_id'] ?? ''));
    if ($project_id === '') diagnostico_json(false, 'FCM não configurado para este tenant.');

    $stmt = $conexao->prepare('SELECT id, fcm_token, plataforma, device_info FROM pwa_fcm_tokens WHERE tenant_id = ? AND morador_id = ? AND ativo = 1');
    if (!$stmt) diagnostico_json(false, 'Não foi possível consultar os dispositivos.');
    $stmt->bind_param('ii', $tenant_id, $morador_id);
    $stmt->execute();
    $tokens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$tokens) diagnostico_json(false, 'Nenhum token ativo para este morador.');

    $resultados = [];
    foreach ($tokens as $token) {
        $resultado = protocolo_notificacao_enviar_fcm(
            $token['fcm_token'],
            'TESTE ERP Condomínios',
            'Teste de notificação push do dispositivo para ' . $morador['unidade'] . '.',
            [
                'tipo' => 'teste_controle_acesso',
                'origem' => 'auditoria_notificacao',
                'rota' => '/home/notifications',
            ],
            $project_id,
            'controle_acesso'
        );
        $linha = [
            'token_id' => (int)$token['id'],
            'token_final' => substr((string)$token['fcm_token'], -12),
            'plataforma' => $token['plataforma'],
            'aceito_fcm' => (bool)$resultado['sucesso'],
            'message_id' => $resultado['message_id'] ?? null,
            'erro' => $resultado['erro'] ?? null,
            'status' => $resultado['status'] ?? null,
        ];
        $resultados[] = $linha;
        controle_acesso_notificacao_auditoria('teste_fcm', [
            'tenant_id' => $tenant_id,
            'morador_id' => $morador_id,
            'unidade' => $morador['unidade'],
            'token_id' => $linha['token_id'],
            'token_final' => $linha['token_final'],
            'aceito_fcm' => $linha['aceito_fcm'],
            'fcm_message_id' => $linha['message_id'],
            'erro' => $linha['erro'],
            'status' => $linha['status'],
        ]);
    }

    diagnostico_json(true, 'Teste de push executado.', [
        'morador_id' => $morador_id,
        'unidade' => $morador['unidade'],
        'resultados' => $resultados,
    ]);
}

diagnostico_json(false, 'Ação de diagnóstico inválida.');
