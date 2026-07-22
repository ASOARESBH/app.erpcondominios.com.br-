-- =====================================================
-- SISTEMA DE CONTROLE DE ACESSO - SERRA DA LIBERDADE
-- Módulo: Protocolo de Mercadorias
-- Alteração: Adicionar campo OBSERVAÇÃO
-- =====================================================

-- Adicionar coluna observacao na tabela protocolos
ALTER TABLE protocolos 
ADD COLUMN observacao TEXT NULL 
COMMENT 'Observações adicionais sobre a mercadoria' 
AFTER recebedor_portaria;

-- Verificar se a coluna foi adicionada
DESCRIBE protocolos;

-- =====================================================
-- INSTRUÇÕES DE USO
-- =====================================================
-- 
-- 1. Acesse o phpMyAdmin ou MySQL via terminal
-- 2. Selecione o banco de dados: inlaud99_erpserra
-- 3. Execute este script SQL
-- 4. Verifique se a coluna foi criada com sucesso
--
-- OU via terminal:
-- mysql -u inlaud99_admin -p inlaud99_erpserra < adicionar_observacao_protocolos.sql
--
-- =====================================================
-- FIM DO SCRIPT
-- =====================================================

