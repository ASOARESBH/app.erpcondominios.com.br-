-- =====================================================
-- SCRIPT DE MIGRAÇÃO DE SENHAS DE MORADORES
-- De SHA1 para BCRYPT
-- =====================================================

-- IMPORTANTE: Este script NÃO pode converter automaticamente as senhas antigas
-- porque SHA1 é uma função de hash unidirecional (não pode ser revertida).

-- OPÇÃO 1: Resetar todas as senhas para um padrão temporário
-- Os moradores deverão fazer login com a senha padrão e então alterá-la

-- Senha padrão temporária: Serra@2024
-- Hash BCRYPT da senha padrão: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi

-- ATENÇÃO: Descomente a linha abaixo APENAS se quiser resetar TODAS as senhas
-- UPDATE moradores SET senha = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE LENGTH(senha) = 40;

-- OPÇÃO 2: Migração Automática no Primeiro Login
-- O sistema já está configurado para fazer isso automaticamente!
-- Arquivo: validar_login_morador.php (linhas 80-105)
-- 
-- Quando um morador faz login:
-- 1. Sistema tenta verificar com password_verify() (BCRYPT)
-- 2. Se falhar, tenta com SHA1 (senhas antigas)
-- 3. Se login com SHA1 for bem-sucedido, atualiza automaticamente para BCRYPT
-- 4. Próximo login já usa BCRYPT

-- VERIFICAR SENHAS AINDA EM SHA1
SELECT 
    id,
    nome,
    email,
    unidade,
    CASE 
        WHEN LENGTH(senha) = 40 THEN 'SHA1 (ANTIGA)'
        WHEN senha LIKE '$2y$%' THEN 'BCRYPT (SEGURA)'
        ELSE 'DESCONHECIDO'
    END as tipo_senha,
    ultimo_acesso,
    CASE 
        WHEN ultimo_acesso IS NULL THEN 'Nunca acessou'
        WHEN ultimo_acesso < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'Inativo há mais de 30 dias'
        ELSE 'Ativo'
    END as status_acesso
FROM moradores
ORDER BY 
    CASE 
        WHEN LENGTH(senha) = 40 THEN 1
        ELSE 2
    END,
    ultimo_acesso DESC;

-- ESTATÍSTICAS DE MIGRAÇÃO
SELECT 
    CASE 
        WHEN LENGTH(senha) = 40 THEN 'SHA1 (Pendente migração)'
        WHEN senha LIKE '$2y$%' THEN 'BCRYPT (Migrado)'
        ELSE 'Outro'
    END as tipo_senha,
    COUNT(*) as total,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM moradores), 2) as percentual
FROM moradores
GROUP BY 
    CASE 
        WHEN LENGTH(senha) = 40 THEN 'SHA1 (Pendente migração)'
        WHEN senha LIKE '$2y$%' THEN 'BCRYPT (Migrado)'
        ELSE 'Outro'
    END;

-- RECOMENDAÇÃO:
-- 1. Deixar o sistema fazer a migração automática no primeiro login
-- 2. Após 30 dias, verificar moradores que ainda não migraram
-- 3. Para moradores inativos, considerar resetar senha ou entrar em contato

-- EXEMPLO: Resetar senha apenas para moradores inativos há mais de 90 dias
-- UPDATE moradores 
-- SET senha = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
-- WHERE LENGTH(senha) = 40 
--   AND (ultimo_acesso IS NULL OR ultimo_acesso < DATE_SUB(NOW(), INTERVAL 90 DAY));

-- CRIAR NOTIFICAÇÃO PARA MORADORES COM SENHAS ANTIGAS
-- (Opcional - requer tabela de notificações)
/*
INSERT INTO notificacoes (titulo, mensagem, data_envio, tipo)
SELECT 
    'Atualização de Segurança' as titulo,
    CONCAT('Olá ', nome, ', detectamos que sua senha precisa ser atualizada. Por favor, faça login no portal para atualizar automaticamente sua senha para um formato mais seguro.') as mensagem,
    NOW() as data_envio,
    'Sistema' as tipo
FROM moradores
WHERE LENGTH(senha) = 40
  AND ativo = 1;
*/

-- LOG DE AUDITORIA
-- Verificar logs de migração automática
SELECT 
    tipo,
    descricao,
    usuario,
    DATE_FORMAT(data_hora, '%d/%m/%Y %H:%i:%s') as data_hora
FROM logs_sistema
WHERE tipo = 'senha_atualizada'
ORDER BY data_hora DESC
LIMIT 50;
