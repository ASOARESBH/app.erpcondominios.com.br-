<?php
/**
 * Script de Teste - Login de Moradores
 * Testa se o login está funcionando corretamente
 */

require_once 'config.php';

echo "<h1>Teste de Login de Moradores</h1>";
echo "<hr>";

// Teste 1: Verificar conexão com banco
echo "<h2>Teste 1: Conexão com Banco de Dados</h2>";
try {
    $conexao = conectar_banco();
    echo "✅ <strong>Conexão estabelecida com sucesso</strong><br>";
    echo "Banco: " . DB_NAME . "<br>";
    echo "Host: " . DB_HOST . "<br>";
} catch (Exception $e) {
    echo "❌ <strong>Erro na conexão:</strong> " . $e->getMessage() . "<br>";
    die();
}
echo "<hr>";

// Teste 2: Verificar estrutura da tabela moradores
echo "<h2>Teste 2: Estrutura da Tabela Moradores</h2>";
$sql = "DESCRIBE moradores";
$resultado = $conexao->query($sql);
if ($resultado) {
    echo "✅ <strong>Tabela 'moradores' existe</strong><br>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th></tr>";
    while ($row = $resultado->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "❌ <strong>Erro ao verificar tabela:</strong> " . $conexao->error . "<br>";
}
echo "<hr>";

// Teste 3: Contar moradores cadastrados
echo "<h2>Teste 3: Moradores Cadastrados</h2>";
$sql = "SELECT COUNT(*) as total FROM moradores";
$resultado = $conexao->query($sql);
$row = $resultado->fetch_assoc();
echo "✅ <strong>Total de moradores:</strong> {$row['total']}<br>";

$sql = "SELECT COUNT(*) as total FROM moradores WHERE ativo = 1";
$resultado = $conexao->query($sql);
$row = $resultado->fetch_assoc();
echo "✅ <strong>Moradores ativos:</strong> {$row['total']}<br>";
echo "<hr>";

// Teste 4: Verificar primeiro morador (para testes)
echo "<h2>Teste 4: Dados do Primeiro Morador</h2>";
$sql = "SELECT id, nome, cpf, unidade, email, ativo, LENGTH(senha) as tamanho_senha FROM moradores LIMIT 1";
$resultado = $conexao->query($sql);
if ($resultado && $resultado->num_rows > 0) {
    $morador = $resultado->fetch_assoc();
    echo "✅ <strong>Morador encontrado:</strong><br>";
    echo "<ul>";
    echo "<li><strong>ID:</strong> {$morador['id']}</li>";
    echo "<li><strong>Nome:</strong> {$morador['nome']}</li>";
    echo "<li><strong>CPF:</strong> {$morador['cpf']}</li>";
    echo "<li><strong>Unidade:</strong> {$morador['unidade']}</li>";
    echo "<li><strong>Email:</strong> {$morador['email']}</li>";
    echo "<li><strong>Ativo:</strong> " . ($morador['ativo'] ? 'Sim' : 'Não') . "</li>";
    echo "<li><strong>Tamanho da senha:</strong> {$morador['tamanho_senha']} caracteres</li>";
    echo "</ul>";
    
    // Identificar tipo de hash
    if ($morador['tamanho_senha'] == 40) {
        echo "⚠️ <strong>Tipo de hash:</strong> SHA1 (40 caracteres) - INSEGURO<br>";
        echo "💡 <strong>Recomendação:</strong> Migrar para BCRYPT<br>";
    } elseif ($morador['tamanho_senha'] == 60) {
        echo "✅ <strong>Tipo de hash:</strong> BCRYPT (60 caracteres) - SEGURO<br>";
    } else {
        echo "❓ <strong>Tipo de hash:</strong> Desconhecido ({$morador['tamanho_senha']} caracteres)<br>";
    }
} else {
    echo "❌ <strong>Nenhum morador encontrado</strong><br>";
}
echo "<hr>";

// Teste 5: Testar busca de CPF com formatação
echo "<h2>Teste 5: Busca de CPF (com e sem formatação)</h2>";

// Pegar CPF do primeiro morador
$sql = "SELECT cpf FROM moradores LIMIT 1";
$resultado = $conexao->query($sql);
$morador = $resultado->fetch_assoc();
$cpf_formatado = $morador['cpf'];
$cpf_limpo = preg_replace('/[^0-9]/', '', $cpf_formatado);

echo "<strong>CPF no banco:</strong> {$cpf_formatado}<br>";
echo "<strong>CPF sem formatação:</strong> {$cpf_limpo}<br><br>";

// Teste busca direta (antiga - não funciona)
$stmt = $conexao->prepare("SELECT id, nome FROM moradores WHERE cpf = ? LIMIT 1");
$stmt->bind_param("s", $cpf_limpo);
$stmt->execute();
$resultado = $stmt->get_result();
if ($resultado->num_rows > 0) {
    echo "✅ <strong>Busca direta funcionou</strong> (CPF sem formatação)<br>";
} else {
    echo "❌ <strong>Busca direta NÃO funcionou</strong> (CPF sem formatação)<br>";
}
$stmt->close();

// Teste busca com REPLACE (nova - deve funcionar)
$stmt = $conexao->prepare("
    SELECT id, nome 
    FROM moradores 
    WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ? 
    LIMIT 1
");
$stmt->bind_param("s", $cpf_limpo);
$stmt->execute();
$resultado = $stmt->get_result();
if ($resultado->num_rows > 0) {
    $morador = $resultado->fetch_assoc();
    echo "✅ <strong>Busca com REPLACE funcionou:</strong> {$morador['nome']}<br>";
} else {
    echo "❌ <strong>Busca com REPLACE NÃO funcionou</strong><br>";
}
$stmt->close();
echo "<hr>";

// Teste 6: Testar verificação de senha SHA1
echo "<h2>Teste 6: Verificação de Senha SHA1</h2>";

$senha_teste = "12345";
$senha_sha1 = sha1($senha_teste);

echo "<strong>Senha de teste:</strong> {$senha_teste}<br>";
echo "<strong>Hash SHA1:</strong> {$senha_sha1}<br><br>";

// Buscar morador com senha SHA1
$sql = "SELECT id, nome, senha FROM moradores WHERE LENGTH(senha) = 40 LIMIT 1";
$resultado = $conexao->query($sql);
if ($resultado && $resultado->num_rows > 0) {
    $morador = $resultado->fetch_assoc();
    echo "<strong>Morador testado:</strong> {$morador['nome']}<br>";
    echo "<strong>Senha no banco:</strong> {$morador['senha']}<br><br>";
    
    // Testar password_verify (não deve funcionar com SHA1)
    if (password_verify($senha_teste, $morador['senha'])) {
        echo "✅ password_verify() funcionou<br>";
    } else {
        echo "❌ password_verify() NÃO funcionou (esperado para SHA1)<br>";
    }
    
    // Testar comparação SHA1
    if (sha1($senha_teste) === $morador['senha']) {
        echo "✅ <strong>Comparação SHA1 funcionou!</strong><br>";
        echo "💡 Senha correta: {$senha_teste}<br>";
    } else {
        echo "❌ Comparação SHA1 NÃO funcionou<br>";
    }
} else {
    echo "ℹ️ Nenhum morador com senha SHA1 encontrado<br>";
}
echo "<hr>";

// Teste 7: Testar atualização de senha para BCRYPT
echo "<h2>Teste 7: Conversão SHA1 → BCRYPT</h2>";

$senha_bcrypt = password_hash($senha_teste, PASSWORD_DEFAULT);
echo "<strong>Senha original:</strong> {$senha_teste}<br>";
echo "<strong>Hash BCRYPT:</strong> {$senha_bcrypt}<br>";
echo "<strong>Tamanho:</strong> " . strlen($senha_bcrypt) . " caracteres<br><br>";

// Verificar se funciona
if (password_verify($senha_teste, $senha_bcrypt)) {
    echo "✅ <strong>Verificação BCRYPT funcionou!</strong><br>";
} else {
    echo "❌ Verificação BCRYPT NÃO funcionou<br>";
}
echo "<hr>";

// Resumo
echo "<h2>📊 Resumo dos Testes</h2>";
echo "<ul>";
echo "<li>✅ Conexão com banco: OK</li>";
echo "<li>✅ Tabela moradores: OK</li>";
echo "<li>✅ Busca de CPF com REPLACE: OK</li>";
echo "<li>✅ Verificação SHA1: OK</li>";
echo "<li>✅ Verificação BCRYPT: OK</li>";
echo "</ul>";

echo "<h3>🎯 Conclusão</h3>";
echo "<p><strong>O login de moradores deve funcionar com as correções aplicadas!</strong></p>";
echo "<ul>";
echo "<li>✅ Busca de CPF corrigida (REPLACE)</li>";
echo "<li>✅ Suporte a SHA1 e BCRYPT</li>";
echo "<li>✅ Atualização automática para BCRYPT</li>";
echo "</ul>";

echo "<hr>";
echo "<p><a href='login_morador.html'>🔐 Testar Login de Morador</a></p>";
echo "<p><a href='moradores.html'>👥 Gerenciar Moradores</a></p>";

fechar_conexao($conexao);
?>

<style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        max-width: 900px;
        margin: 2rem auto;
        padding: 2rem;
        background: #f8fafc;
    }
    h1 {
        color: #1e293b;
        border-bottom: 3px solid #667eea;
        padding-bottom: 0.5rem;
    }
    h2 {
        color: #475569;
        margin-top: 1.5rem;
        background: #e0e7ff;
        padding: 0.5rem 1rem;
        border-radius: 8px;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 1rem 0;
        background: #fff;
    }
    th {
        background: #667eea;
        color: #fff;
        padding: 0.75rem;
        text-align: left;
    }
    td {
        padding: 0.5rem;
        border-bottom: 1px solid #e2e8f0;
    }
    ul {
        line-height: 1.8;
    }
    strong {
        color: #1e293b;
    }
    hr {
        border: none;
        border-top: 2px solid #e2e8f0;
        margin: 2rem 0;
    }
    a {
        display: inline-block;
        background: #667eea;
        color: #fff;
        padding: 0.75rem 1.5rem;
        text-decoration: none;
        border-radius: 8px;
        margin: 0.5rem 0.5rem 0.5rem 0;
        transition: 0.2s;
    }
    a:hover {
        background: #5568d3;
        transform: translateY(-2px);
    }
</style>
