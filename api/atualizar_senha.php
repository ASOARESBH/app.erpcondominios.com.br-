<?php
/**
 * Script para Atualizar Senha no Banco de Dados
 * Use este script para atualizar a senha de um morador para hash bcrypt
 */

require_once 'config.php';

// ⚠️ CONFIGURE AQUI
$cpf_morador = '08921646620';  // CPF sem pontuação
$nova_senha = '123456';  // Nova senha em texto plano

echo "<h2>Atualizar Senha - Portal do Morador</h2>";
echo "<hr>";

// Conectar ao banco
$conexao = conectar_banco();

// Buscar morador
$stmt = $conexao->prepare("SELECT id, nome, cpf FROM moradores WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), '/', '') = ?");
$stmt->bind_param("s", $cpf_morador);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    echo "<p style='color:red;'><strong>❌ CPF não encontrado</strong></p>";
    exit;
}

$morador = $resultado->fetch_assoc();

echo "<h3>Morador Encontrado</h3>";
echo "<p><strong>ID:</strong> {$morador['id']}</p>";
echo "<p><strong>Nome:</strong> {$morador['nome']}</p>";
echo "<p><strong>CPF:</strong> {$morador['cpf']}</p>";

echo "<hr>";

// Gerar hash bcrypt da nova senha
$senha_hash = password_hash($nova_senha, PASSWORD_BCRYPT);

echo "<h3>Nova Senha</h3>";
echo "<p><strong>Senha (texto plano):</strong> $nova_senha</p>";
echo "<p><strong>Hash Bcrypt:</strong> <code>$senha_hash</code></p>";

echo "<hr>";

// Atualizar no banco
$stmt = $conexao->prepare("UPDATE moradores SET senha = ? WHERE id = ?");
$stmt->bind_param("si", $senha_hash, $morador['id']);

if ($stmt->execute()) {
    echo "<p style='color:green; font-size:18px;'><strong>✅ SENHA ATUALIZADA COM SUCESSO!</strong></p>";
    echo "<p>O morador <strong>{$morador['nome']}</strong> agora pode fazer login com:</p>";
    echo "<ul>";
    echo "<li><strong>CPF:</strong> {$morador['cpf']}</li>";
    echo "<li><strong>Senha:</strong> $nova_senha</li>";
    echo "</ul>";
    
    // Testar a senha
    echo "<hr>";
    echo "<h3>Teste de Verificação</h3>";
    $teste = password_verify($nova_senha, $senha_hash);
    if ($teste) {
        echo "<p style='color:green;'><strong>✅ Verificação OK!</strong> password_verify() retornou TRUE</p>";
    } else {
        echo "<p style='color:red;'><strong>❌ Verificação FALHOU!</strong> password_verify() retornou FALSE</p>";
    }
    
} else {
    echo "<p style='color:red;'><strong>❌ ERRO ao atualizar senha</strong></p>";
    echo "<p>Erro: " . $stmt->error . "</p>";
}

echo "<hr>";
echo "<p><a href='teste_senha.php'>← Voltar para Teste de Senha</a></p>";
