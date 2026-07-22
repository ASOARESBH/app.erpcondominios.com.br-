-- =====================================================
-- TABELA: leituras_fotos
-- Evidência fotográfica das leituras de hidrômetros
-- (JPG, JPEG, PNG, WEBP — capturada via câmera ou anexada)
--
-- Não altera nenhuma tabela existente (leituras, hidrometros).
-- Uma leitura pode ter várias fotos (histórico completo, nunca
-- sobrescreve); um hidrômetro pode ter fotos de várias leituras
-- (usadas na galeria do Cadastro de Hidrômetros).
--
-- leitura_id fica NULL temporariamente entre o momento em que a
-- foto é enviada (câmera/anexo, antes de "Registrar Leitura") e o
-- momento em que a leitura é efetivamente salva — api_leituras.php
-- vincula leitura_id após o INSERT da leitura ter sucesso. Fotos
-- que nunca chegam a ser vinculadas (usuário cancelou/limpou antes
-- de salvar) podem ser removidas via DELETE em api_leituras_fotos.php
-- enquanto leitura_id ainda é NULL.
-- =====================================================

CREATE TABLE IF NOT EXISTS `leituras_fotos` (
    `id`              INT(11)      NOT NULL AUTO_INCREMENT,
    `leitura_id`      INT(11)      DEFAULT NULL COMMENT 'NULL até a leitura ser registrada; nunca reaproveitado para outra leitura',
    `hidrometro_id`   INT(11)      NOT NULL,
    `nome_arquivo`    VARCHAR(255) NOT NULL COMMENT 'Nome do arquivo no servidor (único)',
    `nome_original`   VARCHAR(255) NOT NULL COMMENT 'Nome original do arquivo enviado (quando anexado)',
    `caminho`         VARCHAR(500) NOT NULL COMMENT 'Caminho relativo no servidor',
    `tipo_mime`       VARCHAR(100) NOT NULL,
    `tamanho_bytes`   INT(11)      NOT NULL DEFAULT 0,
    `origem`          ENUM('camera','upload') NOT NULL DEFAULT 'upload',
    `lancado_por_tipo` VARCHAR(20)  NOT NULL DEFAULT 'usuario' COMMENT 'usuario | morador',
    `lancado_por_id`   INT(11)      DEFAULT NULL,
    `lancado_por_nome` VARCHAR(200) DEFAULT NULL COMMENT 'Usuário que realizou a leitura',
    `ip_origem`        VARCHAR(45)  DEFAULT NULL,
    `data_upload`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_leitura_id`    (`leitura_id`),
    KEY `idx_hidrometro_id` (`hidrometro_id`),
    CONSTRAINT `fk_leituras_fotos_leitura`
        FOREIGN KEY (`leitura_id`) REFERENCES `leituras` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_leituras_fotos_hidrometro`
        FOREIGN KEY (`hidrometro_id`) REFERENCES `hidrometros` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Evidência fotográfica vinculada a leituras de hidrômetros';

-- Verificar criação
SHOW TABLES LIKE 'leituras_fotos';
DESCRIBE leituras_fotos;
