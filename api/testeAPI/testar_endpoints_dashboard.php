<?php
// =====================================================
// TESTE DE ENDPOINTS DO DASHBOARD
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Teste de Endpoints do Dashboard</h1>";
echo "<hr>";

$base_url = 'api_dashboard_agua.php';

$endpoints = [
    'total_moradores' => '?total_moradores=1',
    'top_consumo_agua' => '?top_consumo_agua=1',
    'saldo_abastecimento' => '?saldo_abastecimento=1',
    'ultimo_lancamento_abastecimento' => '?ultimo_lancamento_abastecimento=1',
    'historico_abastecimentos' => '?historico_abastecimentos=1',
    'estatisticas_gerais' => '?estatisticas_gerais=1'
];

foreach ($endpoints as $nome => $params) {
    echo "<h2>Testando: $nome</h2>";
    echo "<p><strong>URL:</strong> $base_url$params</p>";
    
    // Simular requisição
    $_GET = [];
    parse_str(ltrim($params, '?'), $_GET);
    
    ob_start();
    try {
        include 'api_dashboard_agua.php';
        $output = ob_get_clean();
        
        // Tentar decodificar JSON
        $json = json_decode($output, true);
        if ($json) {
            if ($json['sucesso']) {
                echo "<p style='color:green;'>✅ <strong>SUCESSO</strong></p>";
                echo "<pre>" . json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
            } else {
                echo "<p style='color:red;'>❌ <strong>ERRO</strong>: " . ($json['mensagem'] ?? 'Erro desconhecido') . "</p>";
                echo "<pre>" . json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
            }
        } else {
            echo "<p style='color:orange;'>⚠️  <strong>RESPOSTA NÃO É JSON</strong></p>";
            echo "<pre>" . htmlspecialchars($output) . "</pre>";
        }
    } catch (Exception $e) {
        ob_end_clean();
        echo "<p style='color:red;'>❌ <strong>EXCEÇÃO</strong>: " . $e->getMessage() . "</p>";
        echo "<p><strong>Arquivo:</strong> " . $e->getFile() . "</p>";
        echo "<p><strong>Linha:</strong> " . $e->getLine() . "</p>";
    }
    
    echo "<hr>";
}

echo "<h2>Teste Concluído!</h2>";
?>
