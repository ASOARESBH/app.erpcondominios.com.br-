<?php
// =====================================================
// DIAGNÓSTICO DE CONEXÃO COM BANCO DE DADOS
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Diagnóstico de Conexão</h1>";
echo "<hr>";

// 1. Verificar se config.php existe
echo "<h2>1. Verificando config.php</h2>";
if (file_exists('config.php')) {
    echo "✅ config.php existe<br>";
    require_once 'config.php';
    echo "✅ config.php incluído com sucesso<br>";
} else {
    echo "❌ config.php NÃO ENCONTRADO<br>";
    die();
}

// 2. Verificar constantes
echo "<h2>2. Verificando Constantes</h2>";
echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : '❌ NÃO DEFINIDO') . "<br>";
echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : '❌ NÃO DEFINIDO') . "<br>";
echo "DB_USER: " . (defined('DB_USER') ? DB_USER : '❌ NÃO DEFINIDO') . "<br>";
echo "DB_PASS: " . (defined('DB_PASS') ? str_repeat('*', strlen(DB_PASS)) : '❌ NÃO DEFINIDO') . "<br>";
echo "DB_CHARSET: " . (defined('DB_CHARSET') ? DB_CHARSET : '❌ NÃO DEFINIDO') . "<br>";

// 3. Verificar extensão mysqli
echo "<h2>3. Verificando Extensão MySQLi</h2>";
if (extension_loaded('mysqli')) {
    echo "✅ Extensão MySQLi está carregada<br>";
} else {
    echo "❌ Extensão MySQLi NÃO está carregada<br>";
    die();
}

// 4. Testar conexão direta
echo "<h2>4. Testando Conexão Direta</h2>";
try {
    $conexao = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conexao->connect_error) {
        echo "❌ Erro de conexão: " . $conexao->connect_error . "<br>";
        echo "Erro número: " . $conexao->connect_errno . "<br>";
    } else {
        echo "✅ Conexão estabelecida com sucesso!<br>";
        echo "Host info: " . $conexao->host_info . "<br>";
        echo "Server info: " . $conexao->server_info . "<br>";
        echo "Protocol version: " . $conexao->protocol_version . "<br>";
        
        // Testar query
        echo "<h3>4.1. Testando Query</h3>";
        $result = $conexao->query("SELECT COUNT(*) as total FROM usuarios");
        if ($result) {
            $row = $result->fetch_assoc();
            echo "✅ Query executada com sucesso!<br>";
            echo "Total de usuários: " . $row['total'] . "<br>";
        } else {
            echo "❌ Erro na query: " . $conexao->error . "<br>";
        }
        
        $conexao->close();
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "<br>";
}

// 5. Testar função conectar_banco()
echo "<h2>5. Testando Função conectar_banco()</h2>";
try {
    $conexao2 = conectar_banco();
    if ($conexao2) {
        echo "✅ Função conectar_banco() funcionou!<br>";
        echo "Tipo: " . get_class($conexao2) . "<br>";
        fechar_conexao($conexao2);
    } else {
        echo "❌ Função conectar_banco() retornou null<br>";
    }
} catch (Exception $e) {
    echo "❌ Exception ao chamar conectar_banco(): " . $e->getMessage() . "<br>";
}

// 6. Verificar configurações PHP
echo "<h2>6. Configurações PHP</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "mysqli.default_host: " . ini_get('mysqli.default_host') . "<br>";
echo "mysqli.default_user: " . ini_get('mysqli.default_user') . "<br>";
echo "mysqli.default_pw: " . (ini_get('mysqli.default_pw') ? '(definido)' : '(vazio)') . "<br>";

// 7. Verificar variáveis de ambiente
echo "<h2>7. Variáveis de Ambiente MySQL</h2>";
$env_vars = ['MYSQL_HOST', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_DATABASE'];
foreach ($env_vars as $var) {
    $value = getenv($var);
    if ($value) {
        echo "$var: " . ($var == 'MYSQL_PASSWORD' ? str_repeat('*', strlen($value)) : $value) . "<br>";
    } else {
        echo "$var: (não definido)<br>";
    }
}

echo "<hr>";
echo "<p><strong>Diagnóstico concluído!</strong></p>";
?>
