-- ============================================================
-- ATUALIZAÇÃO DA TABELA DISPOSITIVOS_CONSOLE
-- Atualizar tipo_dispositivo para incluir Câmera e Totem
-- ============================================================

-- Atualizar ENUM do tipo_dispositivo
ALTER TABLE dispositivos_console
MODIFY COLUMN tipo_dispositivo ENUM('Tablet', 'Câmera', 'Totem', 'Outro') DEFAULT 'Tablet';

-- Atualizar dispositivos existentes com valores antigos
UPDATE dispositivos_console SET tipo_dispositivo = 'Tablet' WHERE tipo_dispositivo = 'tablet';
UPDATE dispositivos_console SET tipo_dispositivo = 'Outro' WHERE tipo_dispositivo = 'smartphone';
UPDATE dispositivos_console SET tipo_dispositivo = 'Outro' WHERE tipo_dispositivo = 'outro';

-- ============================================================
-- VERIFICAÇÃO
-- ============================================================

-- Verificar estrutura atualizada
DESCRIBE dispositivos_console;

-- Verificar dispositivos
SELECT id, nome_dispositivo, tipo_dispositivo, ativo FROM dispositivos_console;
