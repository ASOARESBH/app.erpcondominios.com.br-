<?php
/**
 * Script para verificar e criar tabela configuracao_smtp
 * 
 * Execute este script para garantir que a tabela existe
 */

require_once 'config.php';

// Conectar ao banco
$conexao = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conexao) {
    die("Erro ao conectar: " . mysqli_connect_error());
}

mysqli_set_charset($conexao, 'utf8mb4');

echo "<h2>Verificação da Tabela configuracao_smtp</h2>";

// Verificar se a tabela existe
$sql_check = "SHOW TABLES LIKE 'configuracao_smtp'";
$resultado = mysqli_query($conexao, $sql_check);

if (mysqli_num_rows($resultado) > 0) {
    echo "<p style='color: green;'>✅ Tabela 'configuracao_smtp' existe!</p>";
    
    // Verificar estrutura
    $sql_desc = "DESCRIBE configuracao_smtp";
    $resultado_desc = mysqli_query($conexao, $sql_desc);
    
    echo "<h3>Estrutura da tabela:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th><th>Padrão</th></tr>";
    
    while ($row = mysqli_fetch_assoc($resultado_desc)) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Verificar registros
    $sql_count = "SELECT COUNT(*) as total FROM configuracao_smtp";
    $resultado_count = mysqli_query($conexao, $sql_count);
    $row_count = mysqli_fetch_assoc($resultado_count);
    
    echo "<p>Total de registros: <strong>{$row_count['total']}</strong></p>";
    
    if ($row_count['total'] > 0) {
        $sql_data = "SELECT * FROM configuracao_smtp";
        $resultado_data = mysqli_query($conexao, $sql_data);
        
        echo "<h3>Dados existentes:</h3>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Host</th><th>Porta</th><th>Usuário</th><th>De Email</th><th>De Nome</th><th>Segurança</th><th>Ativo</th></tr>";
        
        while ($row = mysqli_fetch_assoc($resultado_data)) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['smtp_host']}</td>";
            echo "<td>{$row['smtp_port']}</td>";
            echo "<td>{$row['smtp_usuario']}</td>";
            echo "<td>{$row['smtp_de_email']}</td>";
            echo "<td>{$row['smtp_de_nome']}</td>";
            echo "<td>{$row['smtp_seguranca']}</td>";
            echo "<td>" . ($row['smtp_ativo'] ? 'Sim' : 'Não') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} else {
    echo "<p style='color: red;'>❌ Tabela 'configuracao_smtp' NÃO existe!</p>";
    echo "<p>Criando tabela...</p>";
    
    $sql_create = "CREATE TABLE `configuracao_smtp` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `smtp_host` varchar(255) NOT NULL,
      `smtp_port` int(11) NOT NULL DEFAULT 587,
      `smtp_usuario` varchar(255) NOT NULL,
      `smtp_senha` varchar(255) NOT NULL,
      `smtp_de_email` varchar(255) NOT NULL,
      `smtp_de_nome` varchar(255) NOT NULL,
      `smtp_seguranca` enum('tls','ssl','none') NOT NULL DEFAULT 'tls',
      `smtp_ativo` tinyint(1) NOT NULL DEFAULT 1,
      `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (mysqli_query($conexao, $sql_create)) {
        echo "<p style='color: green;'>✅ Tabela criada com sucesso!</p>";
    } else {
        echo "<p style='color: red;'>❌ Erro ao criar tabela: " . mysqli_error($conexao) . "</p>";
    }
}

// Verificar outras tabelas necessárias
echo "<hr><h2>Verificação de Outras Tabelas</h2>";

$tabelas = ['email_templates', 'email_log'];

foreach ($tabelas as $tabela) {
    $sql_check = "SHOW TABLES LIKE '$tabela'";
    $resultado = mysqli_query($conexao, $sql_check);
    
    if (mysqli_num_rows($resultado) > 0) {
        echo "<p style='color: green;'>✅ Tabela '$tabela' existe</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Tabela '$tabela' NÃO existe</p>";
    }
}

echo "<hr>";
echo "<p><strong>Próximo passo:</strong> Se alguma tabela não existe, execute o script SQL completo: <code>create_email_tables.sql</code></p>";
echo "<p><a href='config_smtp.html'>Ir para Configuração SMTP</a></p>";

mysqli_close($conexao);
?>
