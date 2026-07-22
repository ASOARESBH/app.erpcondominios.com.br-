<?php
/**
 * Script de Teste de Senha - Portal do Morador
 * Use este script para testar se a senha está correta
 */

require_once 'config.php';

// CPF para testar (sem pontuação)
$cpf_teste = '08921646620';  // 089.216.466-20

// Senha para testar
$senha_teste = '123456';

echo "<h2>Teste de Autenticação - Portal do Morador</h2>";
echo "<hr>";

// Conectar ao banco
$conexao = conectar_banco();

// Buscar morador
$stmt = $conexao->prepare("SELECT id, nome, cpf, email, unidade, senha, ativo FROM moradores WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), '/', '') = ?");
$stmt->bind_param("s", $cpf_teste);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    echo "<p style='color:red;'><strong>❌ CPF não encontrado no banco de dados</strong></p>";
    echo "<p>CPF buscado: $cpf_teste</p>";
    exit;
}

$morador = $resultado->fetch_assoc();

echo "<h3>✅ Morador Encontrado</h3>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><td><strong>ID</strong></td><td>{$morador['id']}</td></tr>";
echo "<tr><td><strong>Nome</strong></td><td>{$morador['nome']}</td></tr>";
echo "<tr><td><strong>CPF</strong></td><td>{$morador['cpf']}</td></tr>";
echo "<tr><td><strong>Email</strong></td><td>{$morador['email']}</td></tr>";
echo "<tr><td><strong>Unidade</strong></td><td>{$morador['unidade']}</td></tr>";
echo "<tr><td><strong>Ativo</strong></td><td>" . ($morador['ativo'] == 1 ? 'Sim' : 'Não') . "</td></tr>";
echo "</table>";

echo "<hr>";
echo "<h3>Teste de Senha</h3>";
echo "<p>Senha testada: <strong>$senha_teste</strong></p>";

// Mostrar informações da senha armazenada
$senha_banco = $morador['senha'];
$tamanho_senha = strlen($senha_banco);
$primeiros_chars = substr($senha_banco, 0, 10);

echo "<p>Senha no banco (primeiros 10 caracteres): <strong>$primeiros_chars...</strong></p>";
echo "<p>Tamanho da senha no banco: <strong>$tamanho_senha caracteres</strong></p>";

// Verificar tipo de senha
if (strpos($senha_banco, '$2y$') === 0) {
    echo "<p style='color:blue;'>Tipo de senha: <strong>Hash Bcrypt</strong></p>";
    
    // Testar password_verify
    $senha_valida = password_verify($senha_teste, $senha_banco);
    
    if ($senha_valida) {
        echo "<p style='color:green; font-size:18px;'><strong>✅ SENHA CORRETA!</strong></p>";
        echo "<p>password_verify() retornou TRUE</p>";
    } else {
        echo "<p style='color:red; font-size:18px;'><strong>❌ SENHA INCORRETA!</strong></p>";
        echo "<p>password_verify() retornou FALSE</p>";
        
        // Tentar gerar novo hash para comparação
        echo "<hr>";
        echo "<h4>Debug: Gerando novo hash da senha '$senha_teste'</h4>";
        $novo_hash = password_hash($senha_teste, PASSWORD_BCRYPT);
        echo "<p>Novo hash gerado: <code>$novo_hash</code></p>";
        
        // Testar com o novo hash
        $teste_novo = password_verify($senha_teste, $novo_hash);
        echo "<p>Teste com novo hash: " . ($teste_novo ? '✅ OK' : '❌ FALHOU') . "</p>";
        
        echo "<hr>";
        echo "<h4>Possíveis Causas:</h4>";
        echo "<ul>";
        echo "<li>O hash no banco foi gerado com uma senha diferente de '$senha_teste'</li>";
        echo "<li>O hash no banco está corrompido ou incompleto</li>";
        echo "<li>A senha correta não é '$senha_teste'</li>";
        echo "</ul>";
        
        echo "<h4>Solução:</h4>";
        echo "<p>Execute o seguinte SQL para atualizar a senha para '$senha_teste':</p>";
        echo "<pre style='background:#f0f0f0; padding:10px;'>";
        echo "UPDATE moradores SET senha = '$novo_hash' WHERE id = {$morador['id']};";
        echo "</pre>";
    }
    
} elseif (strpos($senha_banco, '$2a$') === 0 || strpos($senha_banco, '$2b$') === 0) {
    echo "<p style='color:blue;'>Tipo de senha: <strong>Hash Bcrypt (variante $2a$ ou $2b$)</strong></p>";
    
    // Testar password_verify
    $senha_valida = password_verify($senha_teste, $senha_banco);
    
    if ($senha_valida) {
        echo "<p style='color:green; font-size:18px;'><strong>✅ SENHA CORRETA!</strong></p>";
    } else {
        echo "<p style='color:red; font-size:18px;'><strong>❌ SENHA INCORRETA!</strong></p>";
    }
    
} else {
    echo "<p style='color:orange;'>Tipo de senha: <strong>Texto Plano</strong></p>";
    
    // Comparar diretamente
    $senha_valida = ($senha_teste === $senha_banco);
    
    if ($senha_valida) {
        echo "<p style='color:green; font-size:18px;'><strong>✅ SENHA CORRETA!</strong></p>";
        echo "<p>Comparação direta: '$senha_teste' === '$senha_banco'</p>";
    } else {
        echo "<p style='color:red; font-size:18px;'><strong>❌ SENHA INCORRETA!</strong></p>";
        echo "<p>Comparação direta: '$senha_teste' !== '$senha_banco'</p>";
        echo "<p>Senha no banco: <strong>$senha_banco</strong></p>";
    }
}

echo "<hr>";
echo "<h3>Teste Completo</h3>";

if ($morador['ativo'] != 1) {
    echo "<p style='color:red;'><strong>❌ Morador está INATIVO</strong></p>";
} else {
    echo "<p style='color:green;'><strong>✅ Morador está ATIVO</strong></p>";
}

if (isset($senha_valida) && $senha_valida && $morador['ativo'] == 1) {
    echo "<p style='color:green; font-size:20px; font-weight:bold;'>🎉 LOGIN DEVE FUNCIONAR!</p>";
} else {
    echo "<p style='color:red; font-size:20px; font-weight:bold;'>❌ LOGIN NÃO VAI FUNCIONAR</p>";
}
