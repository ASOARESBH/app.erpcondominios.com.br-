<?php
/**
 * Script para criar tabela configuracao_smtp
 * 
 * Execute este arquivo no navegador para criar a tabela
 */

require_once 'config.php';

// Conectar ao banco
$conexao = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conexao) {
    die("❌ Erro ao conectar: " . mysqli_connect_error());
}

mysqli_set_charset($conexao, 'utf8mb4');

echo "<!DOCTYPE html>";
echo "<html lang='pt-BR'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<title>Criar Tabela SMTP</title>";
echo "<style>body{font-family:Arial;padding:20px;} .success{color:green;} .error{color:red;} .info{color:blue;} code{background:#f0f0f0;padding:2px 5px;}</style>";
echo "</head>";
echo "<body>";
echo "<h1>🔧 Criação da Tabela configuracao_smtp</h1>";

// Verificar se a tabela já existe
$sql_check = "SHOW TABLES LIKE 'configuracao_smtp'";
$resultado = mysqli_query($conexao, $sql_check);

if (mysqli_num_rows($resultado) > 0) {
    echo "<p class='info'>ℹ️ A tabela <code>configuracao_smtp</code> já existe!</p>";
    
    // Mostrar estrutura
    $sql_desc = "DESCRIBE configuracao_smtp";
    $resultado_desc = mysqli_query($conexao, $sql_desc);
    
    echo "<h2>Estrutura atual:</h2>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
    echo "<tr style='background:#f0f0f0;'><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
    
    while ($row = mysqli_fetch_assoc($resultado_desc)) {
        echo "<tr>";
        echo "<td><code>{$row['Field']}</code></td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>" . ($row['Null'] == 'YES' ? 'Sim' : 'Não') . "</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Contar registros
    $sql_count = "SELECT COUNT(*) as total FROM configuracao_smtp";
    $resultado_count = mysqli_query($conexao, $sql_count);
    $row_count = mysqli_fetch_assoc($resultado_count);
    
    echo "<p>📊 Total de registros: <strong>{$row_count['total']}</strong></p>";
    
} else {
    echo "<p class='error'>❌ A tabela <code>configuracao_smtp</code> NÃO existe!</p>";
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
        echo "<p class='success'>✅ Tabela criada com sucesso!</p>";
        
        // Mostrar estrutura
        $sql_desc = "DESCRIBE configuracao_smtp";
        $resultado_desc = mysqli_query($conexao, $sql_desc);
        
        echo "<h2>Estrutura criada:</h2>";
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
        echo "<tr style='background:#f0f0f0;'><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
        
        while ($row = mysqli_fetch_assoc($resultado_desc)) {
            echo "<tr>";
            echo "<td><code>{$row['Field']}</code></td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>" . ($row['Null'] == 'YES' ? 'Sim' : 'Não') . "</td>";
            echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } else {
        echo "<p class='error'>❌ Erro ao criar tabela: " . mysqli_error($conexao) . "</p>";
    }
}

// Criar outras tabelas se não existirem
echo "<hr>";
echo "<h2>📋 Outras Tabelas Necessárias</h2>";

// Tabela email_templates
$sql_check = "SHOW TABLES LIKE 'email_templates'";
$resultado = mysqli_query($conexao, $sql_check);

if (mysqli_num_rows($resultado) == 0) {
    echo "<p class='info'>Criando tabela <code>email_templates</code>...</p>";
    
    $sql_create = "CREATE TABLE `email_templates` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `tipo` varchar(50) NOT NULL,
      `assunto` varchar(255) NOT NULL,
      `corpo` text NOT NULL,
      `variaveis_disponiveis` text,
      `ativo` tinyint(1) NOT NULL DEFAULT 1,
      `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `tipo` (`tipo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (mysqli_query($conexao, $sql_create)) {
        echo "<p class='success'>✅ Tabela <code>email_templates</code> criada!</p>";
    } else {
        echo "<p class='error'>❌ Erro: " . mysqli_error($conexao) . "</p>";
    }
} else {
    echo "<p class='success'>✅ Tabela <code>email_templates</code> já existe</p>";
}

// Tabela email_log
$sql_check = "SHOW TABLES LIKE 'email_log'";
$resultado = mysqli_query($conexao, $sql_check);

if (mysqli_num_rows($resultado) == 0) {
    echo "<p class='info'>Criando tabela <code>email_log</code>...</p>";
    
    $sql_create = "CREATE TABLE `email_log` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `morador_id` int(11) DEFAULT NULL,
      `destinatario` varchar(255) NOT NULL,
      `assunto` varchar(255) NOT NULL,
      `tipo` varchar(50) NOT NULL,
      `status` enum('enviado','erro','pendente') NOT NULL DEFAULT 'pendente',
      `erro_mensagem` text,
      `data_envio` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `morador_id` (`morador_id`),
      KEY `tipo` (`tipo`),
      KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (mysqli_query($conexao, $sql_create)) {
        echo "<p class='success'>✅ Tabela <code>email_log</code> criada!</p>";
    } else {
        echo "<p class='error'>❌ Erro: " . mysqli_error($conexao) . "</p>";
    }
} else {
    echo "<p class='success'>✅ Tabela <code>email_log</code> já existe</p>";
}

echo "<hr>";
echo "<h2>✅ Concluído!</h2>";
echo "<p><a href='config_smtp.html' style='display:inline-block;padding:10px 20px;background:#3b82f6;color:white;text-decoration:none;border-radius:5px;'>Ir para Configuração SMTP</a></p>";
echo "<p><a href='verificar_tabela_smtp.php'>Ver Detalhes das Tabelas</a></p>";

mysqli_close($conexao);

echo "</body>";
echo "</html>";
?>
