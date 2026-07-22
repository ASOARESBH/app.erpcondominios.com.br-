<?php
// =====================================================
// TESTE DE TODAS AS APIs - Identificar Erros
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Teste de Todas as APIs</h1>";
echo "<hr>";

// Lista de APIs para testar
$apis = [
    'api_dashboard_agua.php',
    'api_dashboard_acessos.php',
    'api_moradores.php',
    'api_veiculos.php',
    'api_visitantes.php',
    'api_registros.php',
    'api_unidades.php',
    'api_hidrometros.php',
    'api_leituras.php',
    'api_estoque.php',
    'api_abastecimento.php',
    'api_protocolos.php',
    'api_notificacoes.php',
    'api_usuarios.php',
    'api_dispositivos.php'
];

$erros = [];
$sucessos = [];

foreach ($apis as $api) {
    echo "<h2>Testando: $api</h2>";
    
    $arquivo = __DIR__ . '/' . $api;
    
    if (!file_exists($arquivo)) {
        echo "<p style='color:orange;'>⚠️  Arquivo não encontrado</p>";
        $erros[] = "$api - Arquivo não encontrado";
        continue;
    }
    
    // Verificar sintaxe
    $output = [];
    $return_var = 0;
    exec("php -l " . escapeshellarg($arquivo) . " 2>&1", $output, $return_var);
    
    if ($return_var !== 0) {
        echo "<p style='color:red;'>❌ Erro de sintaxe:</p>";
        echo "<pre>" . implode("\n", $output) . "</pre>";
        $erros[] = "$api - Erro de sintaxe";
    } else {
        echo "<p style='color:green;'>✅ Sintaxe OK</p>";
        
        // Verificar se inclui config.php
        $conteudo = file_get_contents($arquivo);
        if (strpos($conteudo, 'config.php') !== false) {
            echo "<p style='color:green;'>✅ Inclui config.php</p>";
        } else {
            echo "<p style='color:orange;'>⚠️  NÃO inclui config.php</p>";
        }
        
        // Verificar se usa conectar_banco()
        if (strpos($conteudo, 'conectar_banco()') !== false) {
            echo "<p style='color:green;'>✅ Usa conectar_banco()</p>";
        } else {
            echo "<p style='color:blue;'>ℹ️  Não usa conectar_banco()</p>";
        }
        
        $sucessos[] = $api;
    }
    
    echo "<hr>";
}

// Resumo
echo "<h2>Resumo</h2>";
echo "<p><strong>Total de APIs testadas:</strong> " . count($apis) . "</p>";
echo "<p><strong>Sucessos:</strong> " . count($sucessos) . "</p>";
echo "<p><strong>Erros:</strong> " . count($erros) . "</p>";

if (!empty($erros)) {
    echo "<h3 style='color:red;'>APIs com Erro:</h3>";
    echo "<ul>";
    foreach ($erros as $erro) {
        echo "<li>$erro</li>";
    }
    echo "</ul>";
}

if (!empty($sucessos)) {
    echo "<h3 style='color:green;'>APIs OK:</h3>";
    echo "<ul>";
    foreach ($sucessos as $sucesso) {
        echo "<li>$sucesso</li>";
    }
    echo "</ul>";
}

echo "<hr>";
echo "<p><strong>Teste concluído!</strong></p>";
?>
