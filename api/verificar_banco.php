<?php
/**
 * Script para Verificar Estrutura do Banco de Dados
 * Verifica se todas as tabelas e campos necessários existem
 */

require_once 'config.php';

echo "<h2>Verificação do Banco de Dados - Portal do Morador</h2>";
echo "<hr>";

// Conectar ao banco
$conexao = conectar_banco();

// Verificar conexão
if (!$conexao) {
    echo "<p style='color:red;'><strong>❌ ERRO: Não foi possível conectar ao banco de dados</strong></p>";
    exit;
}

function verificar_banco_colunas($conexao, string $tabela): array {
    if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', $tabela) !== 1) return [];
    $tipoExpr = \ERP\Database\DatabaseDialect::isPostgres($conexao) ? 'data_type' : 'column_type';
    $schemaExpr = \ERP\Database\DatabaseDialect::isPostgres($conexao) ? 'current_schema()' : 'DATABASE()';
    $stmt = $conexao->prepare("SELECT column_name AS field_name, $tipoExpr AS column_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = $schemaExpr AND table_name = ? ORDER BY ordinal_position");
    if (!$stmt || !$stmt->bind_param('s', $tabela) || !$stmt->execute()) {
        if ($stmt) $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    if (!$result) { $stmt->close(); return []; }
    $columns = [];
    while ($row = $result->fetch_assoc()) $columns[] = $row;
    $result->free();
    $stmt->close();
    return $columns;
}

echo "<p style='color:green;'><strong>✅ Conexão com banco OK</strong></p>";
echo "<hr>";

// ========== VERIFICAR TABELA MORADORES ==========
echo "<h3>1. Tabela: moradores</h3>";

if (\ERP\Database\DatabaseDialect::tableExists($conexao, 'moradores')) {
    echo "<p style='color:green;'>✅ Tabela existe</p>";
    
    // Verificar campos
    $campos = verificar_banco_colunas($conexao, 'moradores');
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
    
    $campos_necessarios = ['id', 'nome', 'cpf', 'email', 'unidade', 'senha', 'ativo', 'ultimo_acesso'];
    $campos_encontrados = [];
    
    foreach ($campos as $campo) {
        echo "<tr>";
        echo "<td>{$campo['field_name']}</td>";
        echo "<td>{$campo['column_type']}</td>";
        echo "<td>{$campo['is_nullable']}</td>";
        echo "<td>{$campo['column_default']}</td>";
        echo "</tr>";
        $campos_encontrados[] = $campo['field_name'];
    }
    echo "</table>";
    
    // Verificar campos obrigatórios
    echo "<h4>Campos Obrigatórios:</h4>";
    $faltando = [];
    foreach ($campos_necessarios as $campo) {
        if (in_array($campo, $campos_encontrados)) {
            echo "<p style='color:green;'>✅ $campo</p>";
        } else {
            echo "<p style='color:red;'>❌ $campo (FALTANDO)</p>";
            $faltando[] = $campo;
        }
    }
    
    if (count($faltando) > 0) {
        echo "<hr>";
        echo "<h4>SQL para Adicionar Campos Faltando:</h4>";
        echo "<pre style='background:#f0f0f0; padding:10px;'>";
        if (in_array('senha', $faltando)) {
            echo "ALTER TABLE moradores ADD COLUMN senha VARCHAR(255) DEFAULT NULL;\n";
        }
        if (in_array('ultimo_acesso', $faltando)) {
            echo "ALTER TABLE moradores ADD COLUMN ultimo_acesso DATETIME DEFAULT NULL;\n";
        }
        echo "</pre>";
    }
    
} else {
    echo "<p style='color:red;'>❌ Tabela NÃO existe</p>";
    echo "<p>Execute o SQL: database.sql</p>";
}

echo "<hr>";

// ========== VERIFICAR TABELA SESSOES_PORTAL ==========
echo "<h3>2. Tabela: sessoes_portal</h3>";

$resultado = \ERP\Database\DatabaseDialect::tableExists($conexao, 'sessoes_portal');
if ($resultado) {
    echo "<p style='color:green;'>✅ Tabela existe</p>";
    
    // Verificar campos
    $campos = verificar_banco_colunas($conexao, 'sessoes_portal');
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
    foreach ($campos as $campo) {
        echo "<tr>";
        echo "<td>{$campo['field_name']}</td>";
        echo "<td>{$campo['column_type']}</td>";
        echo "<td>{$campo['is_nullable']}</td>";
        echo "<td>{$campo['column_default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} else {
    echo "<p style='color:red;'>❌ Tabela NÃO existe - ESTE É O PROBLEMA!</p>";
    echo "<p><strong>A API está falhando porque a tabela sessoes_portal não existe.</strong></p>";
    
    echo "<hr>";
    if (!\ERP\Database\DatabaseDialect::isPostgres($conexao)) {
    echo "<h4>Solução: Execute o SQL abaixo no phpMyAdmin</h4>";
    echo "<pre style='background:#f0f0f0; padding:10px; overflow-x:auto;'>";
    echo "CREATE TABLE IF NOT EXISTS `sessoes_portal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `morador_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `data_login` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_expiracao` datetime NOT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `morador_id` (`morador_id`),
  CONSTRAINT `sessoes_portal_ibfk_1` FOREIGN KEY (`morador_id`) REFERENCES `moradores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    echo "</pre>";
    
    echo "<hr>";
    echo "<h4>Ou clique no botão abaixo para criar automaticamente:</h4>";
    echo "<form method='post'>";
    echo "<button type='submit' name='criar_tabela' style='background:green; color:white; padding:10px 20px; font-size:16px; border:none; cursor:pointer;'>Criar Tabela sessoes_portal</button>";
    echo "</form>";
    } else {
        echo "<p>Criação de schema runtime desabilitada no PostgreSQL; use o bundle DDL versionado.</p>";
    }
    
    if (isset($_POST['criar_tabela']) && \ERP\Database\DatabaseDialect::isPostgres($conexao)) {
        echo "<p>Criação de schema runtime desabilitada no PostgreSQL; use o bundle DDL versionado.</p>";
    } elseif (isset($_POST['criar_tabela'])) {
        echo "<hr>";
        echo "<h4>Criando tabela...</h4>";
        
        $sql = "CREATE TABLE IF NOT EXISTS `sessoes_portal` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `morador_id` int(11) NOT NULL,
          `token` varchar(64) NOT NULL,
          `ip_address` varchar(45) DEFAULT NULL,
          `user_agent` text DEFAULT NULL,
          `data_login` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `data_expiracao` datetime NOT NULL,
          `ativo` tinyint(1) DEFAULT 1,
          PRIMARY KEY (`id`),
          UNIQUE KEY `token` (`token`),
          KEY `morador_id` (`morador_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if ($conexao->query($sql)) {
            echo "<p style='color:green; font-size:18px;'><strong>✅ Tabela criada com sucesso!</strong></p>";
            echo "<p><a href='verificar_banco.php'>Recarregar página para verificar</a></p>";
        } else {
            echo "<p style='color:red;'><strong>❌ Erro ao criar tabela:</strong></p>";
            echo "<p>" . $conexao->error . "</p>";
        }
    }
}

echo "<hr>";

// ========== VERIFICAR OUTRAS TABELAS DO PORTAL ==========
echo "<h3>3. Outras Tabelas do Portal</h3>";

$tabelas_portal = [
    'hidrometro' => 'Dados dos hidrômetros',
    'lancamentos_agua' => 'Lançamentos de consumo de água'
];

foreach ($tabelas_portal as $tabela => $descricao) {
    if (\ERP\Database\DatabaseDialect::tableExists($conexao, $tabela)) {
        echo "<p style='color:green;'>✅ $tabela ($descricao)</p>";
    } else {
        echo "<p style='color:orange;'>⚠️ $tabela ($descricao) - NÃO existe (opcional)</p>";
    }
}

echo "<hr>";

// ========== RESUMO ==========
echo "<h3>Resumo da Verificação</h3>";

$moradores_existe = \ERP\Database\DatabaseDialect::tableExists($conexao, 'moradores');
$sessoes_existe = \ERP\Database\DatabaseDialect::tableExists($conexao, 'sessoes_portal');

if ($moradores_existe && $sessoes_existe) {
    echo "<p style='color:green; font-size:20px; font-weight:bold;'>🎉 BANCO DE DADOS OK! LOGIN DEVE FUNCIONAR!</p>";
    echo "<p><a href='login_morador.html' style='background:blue; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Testar Login</a></p>";
} else {
    echo "<p style='color:red; font-size:20px; font-weight:bold;'>❌ BANCO DE DADOS INCOMPLETO</p>";
    echo "<p>Corrija os problemas acima e recarregue esta página.</p>";
}
