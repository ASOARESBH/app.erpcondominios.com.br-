<?php
// =====================================================
// API DEBUG LOG — Leitura de logs de debug do sistema
// ATENÇÃO: Remover ou proteger em produção após uso
// =====================================================
require_once 'config.php';
require_once 'auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Requer autenticação admin
verificarAutenticacao(true, 'admin');

$acao = $_GET['acao'] ?? 'ler';
$modulo = $_GET['modulo'] ?? 'contas_bancarias';
$linhas = intval($_GET['linhas'] ?? 100);

$log_file = __DIR__ . '/../logs/debug_' . preg_replace('/[^a-z_]/', '', $modulo) . '.log';

switch ($acao) {
    case 'ler':
        if (!file_exists($log_file)) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Arquivo de log não encontrado: ' . basename($log_file), 'arquivo' => $log_file]);
            exit;
        }
        // Ler as últimas N linhas
        $conteudo = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $total = count($conteudo);
        $ultimas = array_slice($conteudo, -$linhas);
        echo json_encode([
            'sucesso'   => true,
            'mensagem'  => "Últimas $linhas linhas de $modulo",
            'total_linhas' => $total,
            'arquivo'   => basename($log_file),
            'dados'     => $ultimas,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'limpar':
        if (file_exists($log_file)) {
            file_put_contents($log_file, '');
            echo json_encode(['sucesso' => true, 'mensagem' => 'Log limpo com sucesso']);
        } else {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Arquivo não encontrado']);
        }
        break;

    case 'listar':
        $logs_dir = __DIR__ . '/../logs/';
        $arquivos = glob($logs_dir . 'debug_*.log');
        $lista = [];
        foreach ($arquivos as $arq) {
            $lista[] = [
                'nome'   => basename($arq),
                'tamanho' => filesize($arq),
                'modificado' => date('Y-m-d H:i:s', filemtime($arq)),
            ];
        }
        echo json_encode(['sucesso' => true, 'dados' => $lista], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Ação inválida. Use: ler, limpar, listar']);
}
