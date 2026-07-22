<?php
// =====================================================
// API DE TESTE SIMPLIFICADA
// Para diagnosticar erro HTTP 500
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

try {
    // Teste 1: Incluir config.php
    require_once 'config.php';
    $teste1 = "✅ config.php incluído";
    
    // Teste 2: Conectar ao banco
    $conexao = conectar_banco();
    $teste2 = "✅ Conexão estabelecida";
    
    // Teste 3: Query simples
    $resultado = $conexao->query("SELECT COUNT(*) as total FROM moradores");
    if (!$resultado) {
        throw new Exception("Erro na query: " . $conexao->error);
    }
    $dados = $resultado->fetch_assoc();
    $teste3 = "✅ Query executada: " . $dados['total'] . " moradores";
    
    // Teste 4: Fechar conexão
    fechar_conexao($conexao);
    $teste4 = "✅ Conexão fechada";
    
    // Retornar sucesso
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Todos os testes passaram!',
        'testes' => [
            'config' => $teste1,
            'conexao' => $teste2,
            'query' => $teste3,
            'fechamento' => $teste4
        ],
        'dados' => [
            'total_moradores' => $dados['total'],
            'timestamp' => date('Y-m-d H:i:s'),
            'php_version' => phpversion(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no teste',
        'erro' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    error_log("ERRO NO TESTE SIMPLES: " . $e->getMessage());
}
?>
