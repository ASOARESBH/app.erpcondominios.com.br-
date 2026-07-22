-- Migration: adicionar classificação da despesa e grupo em contas_pagar

ALTER TABLE `contas_pagar`
  ADD COLUMN `classificacao_despesa` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Classificação da despesa',
  ADD COLUMN `grupo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Grupo da despesa';

-- Opcional: atualizar registros existentes se quiser organizar dados históricos
-- UPDATE `contas_pagar` SET `classificacao_despesa` = 'Sem classificação', `grupo` = 'Sem grupo' WHERE `classificacao_despesa` IS NULL AND `grupo` IS NULL;
