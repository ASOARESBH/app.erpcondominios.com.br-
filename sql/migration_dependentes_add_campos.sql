-- =====================================================
-- MIGRAÇÃO: Adicionar campos email, celular, cpf na tabela dependentes
-- Execute este script no phpMyAdmin caso a tabela já exista sem esses campos
-- =====================================================

-- Adicionar coluna cpf (se não existir)
ALTER TABLE `dependentes`
  ADD COLUMN IF NOT EXISTS `cpf` VARCHAR(20) DEFAULT NULL AFTER `nome_completo`;

-- Adicionar coluna email (se não existir)
ALTER TABLE `dependentes`
  ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) DEFAULT NULL AFTER `data_nascimento`;

-- Adicionar coluna telefone (se não existir)
ALTER TABLE `dependentes`
  ADD COLUMN IF NOT EXISTS `telefone` VARCHAR(20) DEFAULT NULL AFTER `email`;

-- Adicionar coluna celular (se não existir)
ALTER TABLE `dependentes`
  ADD COLUMN IF NOT EXISTS `celular` VARCHAR(20) DEFAULT NULL AFTER `telefone`;

-- Verificar estrutura final
DESCRIBE `dependentes`;
