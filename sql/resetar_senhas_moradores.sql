-- =====================================================
-- SCRIPT PARA RESETAR SENHAS DE TODOS OS MORADORES
-- =====================================================
-- Data: 02/12/2025
-- Descrição: Reseta todas as senhas dos moradores para 123456
-- =====================================================

-- ⚠️⚠️⚠️ ATENÇÃO ⚠️⚠️⚠️
-- Este script irá alterar a senha de TODOS os moradores para 123456
-- Execute apenas se tiver certeza absoluta!
-- Faça backup do banco de dados antes de executar!
-- =====================================================

-- Verificar quantos moradores serão afetados
SELECT 
    COUNT(*) as total_moradores,
    SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) as moradores_ativos,
    SUM(CASE WHEN ativo = 0 THEN 1 ELSE 0 END) as moradores_inativos
FROM moradores;

-- =====================================================
-- OPÇÃO 1: Resetar usando MD5 (menos seguro, mas compatível)
-- =====================================================

-- Resetar TODOS os moradores (ativos e inativos)
UPDATE moradores 
SET senha = MD5('123456');

-- OU resetar apenas moradores ATIVOS
-- UPDATE moradores 
-- SET senha = MD5('123456')
-- WHERE ativo = 1;

-- =====================================================
-- OPÇÃO 2: Resetar usando password_hash do PHP (mais seguro)
-- =====================================================
-- Hash gerado com: password_hash('123456', PASSWORD_DEFAULT)
-- Este hash é compatível com password_verify() do PHP

-- UPDATE moradores 
-- SET senha = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

-- =====================================================
-- OPÇÃO 3: Resetar usando SHA256 (segurança média)
-- =====================================================

-- UPDATE moradores 
-- SET senha = SHA2('123456', 256);

-- =====================================================
-- VERIFICAÇÃO APÓS RESET
-- =====================================================

-- Verificar se todas as senhas foram alteradas para o mesmo hash
SELECT 
    senha,
    COUNT(*) as quantidade_moradores
FROM moradores
GROUP BY senha;

-- Listar todos os moradores com a nova senha
SELECT 
    id,
    nome,
    cpf,
    email,
    unidade,
    ativo,
    'Senha resetada para 123456' as status
FROM moradores
ORDER BY nome;

-- =====================================================
-- LOG DE ALTERAÇÃO (OPCIONAL)
-- =====================================================
-- Criar tabela de log se não existir

CREATE TABLE IF NOT EXISTS log_reset_senha (
    id INT AUTO_INCREMENT PRIMARY KEY,
    morador_id INT,
    morador_nome VARCHAR(200),
    morador_cpf VARCHAR(14),
    senha_anterior VARCHAR(255),
    senha_nova VARCHAR(255),
    data_reset TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    usuario_responsavel VARCHAR(100),
    observacao TEXT,
    INDEX idx_data (data_reset),
    INDEX idx_morador (morador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registrar o reset no log (executar ANTES do UPDATE)
INSERT INTO log_reset_senha (morador_id, morador_nome, morador_cpf, senha_anterior, senha_nova, usuario_responsavel, observacao)
SELECT 
    id,
    nome,
    cpf,
    senha,
    MD5('123456'),
    'ADMINISTRADOR',
    'Reset em massa - Todas as senhas alteradas para 123456'
FROM moradores;

-- =====================================================
-- SCRIPT PARA RESETAR SENHA DE UM MORADOR ESPECÍFICO
-- =====================================================

-- Por CPF
-- UPDATE moradores 
-- SET senha = MD5('123456')
-- WHERE cpf = '000.000.000-00';

-- Por ID
-- UPDATE moradores 
-- SET senha = MD5('123456')
-- WHERE id = 1;

-- Por Unidade
-- UPDATE moradores 
-- SET senha = MD5('123456')
-- WHERE unidade = 'A-101';

-- =====================================================
-- NOTIFICAR MORADORES POR E-MAIL (OPCIONAL)
-- =====================================================
-- Listar e-mails dos moradores para enviar notificação

SELECT 
    nome,
    email,
    cpf,
    unidade,
    CONCAT('Olá ', nome, ', sua senha foi resetada para: 123456') as mensagem
FROM moradores
WHERE ativo = 1
  AND email IS NOT NULL
  AND email != ''
ORDER BY nome;

-- =====================================================
-- ESTATÍSTICAS APÓS RESET
-- =====================================================

SELECT 
    'Total de moradores' as descricao,
    COUNT(*) as quantidade
FROM moradores

UNION ALL

SELECT 
    'Moradores ativos',
    COUNT(*)
FROM moradores
WHERE ativo = 1

UNION ALL

SELECT 
    'Moradores com e-mail cadastrado',
    COUNT(*)
FROM moradores
WHERE email IS NOT NULL AND email != ''

UNION ALL

SELECT 
    'Moradores sem e-mail',
    COUNT(*)
FROM moradores
WHERE email IS NULL OR email = '';

-- =====================================================
-- REVERTER RESET (RESTAURAR SENHAS ANTERIORES)
-- =====================================================
-- ATENÇÃO: Só funciona se você executou o log antes do reset!

-- Restaurar senhas anteriores usando o log
-- UPDATE moradores m
-- INNER JOIN log_reset_senha l ON m.id = l.morador_id
-- SET m.senha = l.senha_anterior
-- WHERE l.data_reset = (SELECT MAX(data_reset) FROM log_reset_senha);

-- =====================================================
-- LIMPEZA
-- =====================================================

-- Limpar tokens de recuperação de senha antigos após reset
-- DELETE FROM recuperacao_senha_tokens 
-- WHERE data_criacao < NOW();

-- =====================================================
-- INFORMAÇÕES IMPORTANTES
-- =====================================================

/*
SENHA PADRÃO: 123456

HASH MD5: e10adc3949ba59abbe56e057f20f883e

COMO VERIFICAR SE A SENHA ESTÁ CORRETA:
- No PHP: if (md5('123456') == $senha_banco) { ... }
- No MySQL: SELECT * FROM moradores WHERE senha = MD5('123456');

RECOMENDAÇÕES DE SEGURANÇA:
1. Sempre use password_hash() no PHP ao invés de MD5
2. Notifique os moradores sobre o reset de senha
3. Force a alteração de senha no primeiro login
4. Mantenha log de todas as alterações
5. Faça backup antes de executar scripts de alteração em massa

PRÓXIMOS PASSOS:
1. Executar este script no banco de dados
2. Notificar moradores por e-mail (se possível)
3. Atualizar documentação do sistema
4. Orientar moradores a alterarem a senha no primeiro acesso
*/

-- =====================================================
-- FIM DO SCRIPT
-- =====================================================
