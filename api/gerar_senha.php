<?php
// =====================================================
// UTILITÁRIO PARA GERAR HASH DE SENHA
// =====================================================
// Este arquivo é apenas para auxiliar na criação de novos usuários
// Não deve ser usado em produção

// Senha padrão do sistema
$senha = 'admin123';

// Gerar hash bcrypt
$hash = password_hash($senha, PASSWORD_BCRYPT);

echo "Senha: {$senha}\n";
echo "Hash: {$hash}\n\n";

// Verificar se o hash está correto
if (password_verify($senha, $hash)) {
    echo "✅ Verificação bem-sucedida!\n";
} else {
    echo "❌ Erro na verificação!\n";
}

echo "\n";
echo "Use este hash no campo 'senha' da tabela 'usuarios'\n";
echo "Exemplo de INSERT:\n";
echo "INSERT INTO usuarios (nome, email, senha, funcao, departamento, permissao, ativo) VALUES\n";
echo "('Seu Nome', 'seu@email.com', '{$hash}', 'Sua Função', 'Seu Departamento', 'admin', 1);\n";
