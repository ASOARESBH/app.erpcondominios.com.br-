<?php
// Ativar exibição de erros
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

header('Content-Type: application/json; charset=utf-8');

$resultado = array(
    'timestamp' => date('Y-m-d H:i:s'),
    'testes' => array()
);

// Teste 1: Verificar config.php
try {
    if (file_exists('config.php')) {
        $resultado['testes']['config_existe'] = true;
        require_once 'config.php';
        $resultado['testes']['config_incluido'] = true;
        
        // Verificar constantes
        $resultado['testes']['constantes'] = array(
            'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'NÃO DEFINIDO',
            'DB_NAME' => defined('DB_NAME') ? DB_NAME : 'NÃO DEFINIDO',
            'DB_USER' => defined('DB_USER') ? DB_USER : 'NÃO DEFINIDO',
            'DB_PASS' => defined('DB_PASS') ? '***' : 'NÃO DEFINIDO'
        );
    } else {
        $resultado['testes']['config_existe'] = false;
        $resultado['erro'] = 'config.php não encontrado';
    }
} catch (Exception $e) {
    $resultado['testes']['config_erro'] = $e->getMessage();
}

// Teste 2: Testar função conectar_banco()
try {
    if (function_exists('conectar_banco')) {
        $resultado['testes']['funcao_conectar_banco'] = 'existe';
        $conexao = conectar_banco();
        
        if ($conexao && !$conexao->connect_error) {
            $resultado['testes']['conexao'] = 'sucesso';
            $resultado['testes']['conexao_info'] = array(
                'host' => $conexao->host_info,
                'server' => $conexao->server_info,
                'protocol' => $conexao->protocol_version
            );
            
            // Teste 3: Query simples
            $sql = "SELECT COUNT(*) as total FROM moradores WHERE status = 'ativo'";
            $result = $conexao->query($sql);
            
            if ($result) {
                $row = $result->fetch_assoc();
                $resultado['testes']['query_moradores'] = array(
                    'sucesso' => true,
                    'total' => $row['total']
                );
            } else {
                $resultado['testes']['query_moradores'] = array(
                    'sucesso' => false,
                    'erro' => $conexao->error,
                    'errno' => $conexao->errno
                );
            }
            
            $conexao->close();
        } else {
            $resultado['testes']['conexao'] = 'falhou';
            $resultado['testes']['conexao_erro'] = $conexao ? $conexao->connect_error : 'Conexão nula';
        }
    } else {
        $resultado['testes']['funcao_conectar_banco'] = 'não existe';
    }
} catch (Exception $e) {
    $resultado['testes']['conexao_erro'] = $e->getMessage();
}

// Teste 4: Verificar api_dashboard_agua.php
try {
    if (file_exists('api_dashboard_agua.php')) {
        $resultado['testes']['api_dashboard_existe'] = true;
        $conteudo = file_get_contents('api_dashboard_agua.php');
        $resultado['testes']['api_dashboard_tamanho'] = strlen($conteudo) . ' bytes';
        
        // Verificar sintaxe básica
        if (strpos($conteudo, '<?php') !== false) {
            $resultado['testes']['api_dashboard_sintaxe'] = 'tem abertura PHP';
        } else {
            $resultado['testes']['api_dashboard_sintaxe'] = 'SEM abertura PHP';
        }
        
        if (strpos($conteudo, 'conectar_banco()') !== false) {
            $resultado['testes']['api_dashboard_usa_conectar'] = true;
        } else {
            $resultado['testes']['api_dashboard_usa_conectar'] = false;
        }
    } else {
        $resultado['testes']['api_dashboard_existe'] = false;
    }
} catch (Exception $e) {
    $resultado['testes']['api_dashboard_erro'] = $e->getMessage();
}

// Teste 5: Verificar api_moradores.php
try {
    if (file_exists('api_moradores.php')) {
        $resultado['testes']['api_moradores_existe'] = true;
        $conteudo = file_get_contents('api_moradores.php');
        $resultado['testes']['api_moradores_tamanho'] = strlen($conteudo) . ' bytes';
        
        // Verificar sintaxe básica
        if (strpos($conteudo, '<?php') !== false) {
            $resultado['testes']['api_moradores_sintaxe'] = 'tem abertura PHP';
        } else {
            $resultado['testes']['api_moradores_sintaxe'] = 'SEM abertura PHP';
        }
    } else {
        $resultado['testes']['api_moradores_existe'] = false;
    }
} catch (Exception $e) {
    $resultado['testes']['api_moradores_erro'] = $e->getMessage();
}

// Teste 6: Verificar permissões
$resultado['testes']['permissoes'] = array(
    'config_legivel' => is_readable('config.php'),
    'api_dashboard_legivel' => is_readable('api_dashboard_agua.php'),
    'api_moradores_legivel' => is_readable('api_moradores.php')
);

// Teste 7: Verificar PHP
$resultado['testes']['php'] = array(
    'versao' => phpversion(),
    'mysqli' => extension_loaded('mysqli') ? 'carregado' : 'NÃO carregado',
    'json' => extension_loaded('json') ? 'carregado' : 'NÃO carregado',
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'display_errors' => ini_get('display_errors'),
    'error_reporting' => error_reporting()
);

$resultado['sucesso_geral'] = true;

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
