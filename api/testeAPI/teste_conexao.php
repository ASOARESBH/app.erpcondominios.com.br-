<?php
// =====================================================
// TESTE DE CONEXÃO COM BANCO DE DADOS
// =====================================================

header('Content-Type: application/json; charset=utf-8');

// Incluir configurações
require_once 'config.php';

$resultado = array(
    'timestamp' => date('Y-m-d H:i:s'),
    'testes' => array()
);

// Teste 1: Verificar constantes
$resultado['testes']['constantes'] = array(
    'DB_HOST' => DB_HOST,
    'DB_NAME' => DB_NAME,
    'DB_USER' => DB_USER,
    'DB_PASS' => substr(DB_PASS, 0, 3) . '***', // Mostrar apenas primeiros 3 caracteres
    'DB_CHARSET' => DB_CHARSET
);

// Teste 2: Tentar conexão
try {
    $conexao = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conexao->connect_error) {
        $resultado['testes']['conexao'] = array(
            'sucesso' => false,
            'erro' => $conexao->connect_error,
            'errno' => $conexao->connect_errno
        );
    } else {
        $resultado['testes']['conexao'] = array(
            'sucesso' => true,
            'mensagem' => 'Conexão estabelecida com sucesso!'
        );
        
        // Teste 3: Verificar charset
        $charset_atual = $conexao->character_set_name();
        $resultado['testes']['charset'] = array(
            'esperado' => DB_CHARSET,
            'atual' => $charset_atual,
            'correto' => ($charset_atual === DB_CHARSET)
        );
        
        // Teste 4: Verificar tabela usuarios
        $query_usuarios = "SHOW TABLES LIKE 'usuarios'";
        $result_usuarios = $conexao->query($query_usuarios);
        
        $resultado['testes']['tabela_usuarios'] = array(
            'existe' => ($result_usuarios && $result_usuarios->num_rows > 0)
        );
        
        // Teste 5: Contar usuários
        if ($result_usuarios && $result_usuarios->num_rows > 0) {
            $query_count = "SELECT COUNT(*) as total FROM usuarios";
            $result_count = $conexao->query($query_count);
            
            if ($result_count) {
                $row = $result_count->fetch_assoc();
                $resultado['testes']['usuarios'] = array(
                    'total' => $row['total']
                );
            }
        }
        
        // Teste 6: Verificar tabela logs_sistema
        $query_logs = "SHOW TABLES LIKE 'logs_sistema'";
        $result_logs = $conexao->query($query_logs);
        
        $resultado['testes']['tabela_logs'] = array(
            'existe' => ($result_logs && $result_logs->num_rows > 0)
        );
        
        // Teste 7: Verificar timezone
        $query_tz = "SELECT @@session.time_zone as tz";
        $result_tz = $conexao->query($query_tz);
        
        if ($result_tz) {
            $row_tz = $result_tz->fetch_assoc();
            $resultado['testes']['timezone'] = array(
                'mysql' => $row_tz['tz'],
                'php' => date_default_timezone_get()
            );
        }
        
        $conexao->close();
    }
    
    $resultado['sucesso_geral'] = true;
    
} catch (Exception $e) {
    $resultado['testes']['conexao'] = array(
        'sucesso' => false,
        'erro' => $e->getMessage()
    );
    $resultado['sucesso_geral'] = false;
}

// Retornar resultado
echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
