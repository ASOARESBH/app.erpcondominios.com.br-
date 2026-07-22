<?php
/**
 * Script de Debug para capturar dados POST
 */

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>";
echo "<html><head><meta charset='UTF-8'><title>Debug POST</title>";
echo "<style>body{font-family:monospace;padding:20px;} pre{background:#f0f0f0;padding:15px;border-radius:5px;overflow:auto;}</style>";
echo "</head><body>";

echo "<h1>🔍 Debug - Dados Recebidos</h1>";

echo "<h2>$_POST:</h2>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

echo "<h2>$_GET:</h2>";
echo "<pre>";
print_r($_GET);
echo "</pre>";

echo "<h2>$_REQUEST:</h2>";
echo "<pre>";
print_r($_REQUEST);
echo "</pre>";

echo "<h2>$_SERVER (Headers):</h2>";
echo "<pre>";
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0 || in_array($key, ['REQUEST_METHOD', 'CONTENT_TYPE', 'CONTENT_LENGTH'])) {
        echo "$key => $value\n";
    }
}
echo "</pre>";

echo "<h2>Raw POST Data:</h2>";
echo "<pre>";
echo file_get_contents('php://input');
echo "</pre>";

// Testar conexão com banco
echo "<hr><h2>🗄️ Teste de Conexão com Banco</h2>";

require_once 'config.php';

$conexao = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conexao) {
    echo "<p style='color:green;'>✅ Conexão com banco OK</p>";
    
    // Verificar tabela
    $sql = "SHOW TABLES LIKE 'configuracao_smtp'";
    $resultado = mysqli_query($conexao, $sql);
    
    if (mysqli_num_rows($resultado) > 0) {
        echo "<p style='color:green;'>✅ Tabela configuracao_smtp existe</p>";
        
        // Contar registros
        $sql_count = "SELECT COUNT(*) as total FROM configuracao_smtp";
        $resultado_count = mysqli_query($conexao, $sql_count);
        $row = mysqli_fetch_assoc($resultado_count);
        
        echo "<p>Total de registros: <strong>{$row['total']}</strong></p>";
    } else {
        echo "<p style='color:red;'>❌ Tabela configuracao_smtp NÃO existe</p>";
    }
    
    mysqli_close($conexao);
} else {
    echo "<p style='color:red;'>❌ Erro ao conectar: " . mysqli_connect_error() . "</p>";
}

// Se recebeu dados POST, tentar inserir
if (!empty($_POST) && isset($_POST['acao']) && $_POST['acao'] == 'salvar') {
    echo "<hr><h2>🧪 Teste de Inserção</h2>";
    
    require_once 'config.php';
    $conexao = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    mysqli_set_charset($conexao, 'utf8mb4');
    
    $smtp_host = mysqli_real_escape_string($conexao, $_POST['smtp_host'] ?? '');
    $smtp_port = intval($_POST['smtp_port'] ?? 587);
    $smtp_usuario = mysqli_real_escape_string($conexao, $_POST['smtp_usuario'] ?? '');
    $smtp_senha = mysqli_real_escape_string($conexao, $_POST['smtp_senha'] ?? '');
    $smtp_de_email = mysqli_real_escape_string($conexao, $_POST['smtp_de_email'] ?? '');
    $smtp_de_nome = mysqli_real_escape_string($conexao, $_POST['smtp_de_nome'] ?? '');
    $smtp_seguranca = mysqli_real_escape_string($conexao, $_POST['smtp_seguranca'] ?? 'tls');
    $smtp_ativo = intval($_POST['smtp_ativo'] ?? 1);
    
    echo "<h3>Dados Sanitizados:</h3>";
    echo "<pre>";
    echo "smtp_host: '$smtp_host'\n";
    echo "smtp_port: $smtp_port\n";
    echo "smtp_usuario: '$smtp_usuario'\n";
    echo "smtp_senha: '$smtp_senha'\n";
    echo "smtp_de_email: '$smtp_de_email'\n";
    echo "smtp_de_nome: '$smtp_de_nome'\n";
    echo "smtp_seguranca: '$smtp_seguranca'\n";
    echo "smtp_ativo: $smtp_ativo\n";
    echo "</pre>";
    
    $sql = "INSERT INTO configuracao_smtp 
            (smtp_host, smtp_port, smtp_usuario, smtp_senha, smtp_de_email, smtp_de_nome, smtp_seguranca, smtp_ativo)
            VALUES 
            ('$smtp_host', $smtp_port, '$smtp_usuario', '$smtp_senha', '$smtp_de_email', '$smtp_de_nome', '$smtp_seguranca', $smtp_ativo)";
    
    echo "<h3>SQL Gerado:</h3>";
    echo "<pre>$sql</pre>";
    
    if (mysqli_query($conexao, $sql)) {
        $id = mysqli_insert_id($conexao);
        echo "<p style='color:green;'>✅ INSERT executado com sucesso! ID: $id</p>";
    } else {
        echo "<p style='color:red;'>❌ Erro no INSERT: " . mysqli_error($conexao) . "</p>";
    }
    
    mysqli_close($conexao);
}

echo "</body></html>";
?>
