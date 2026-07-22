-- =====================================================
-- TABELA: moradores_anexos
-- Armazena documentos vinculados a moradores
-- (PDF, JPG, PNG, GIF, WEBP — até 10MB)
-- =====================================================

CREATE TABLE IF NOT EXISTS `moradores_anexos` (
    `id`              INT(11)      NOT NULL AUTO_INCREMENT,
    `morador_id`      INT(11)      NOT NULL,
    `nome_documento`  VARCHAR(200) NOT NULL COMMENT 'Nome descritivo informado pelo usuário',
    `nome_arquivo`    VARCHAR(255) NOT NULL COMMENT 'Nome do arquivo no servidor (único)',
    `nome_original`   VARCHAR(255) NOT NULL COMMENT 'Nome original do arquivo enviado',
    `caminho`         VARCHAR(500) NOT NULL COMMENT 'Caminho relativo no servidor',
    `tipo_mime`       VARCHAR(100) NOT NULL COMMENT 'MIME type do arquivo',
    `tamanho_bytes`   INT(11)      NOT NULL DEFAULT 0,
    `ativo`           TINYINT(1)   NOT NULL DEFAULT 1,
    `criado_por`      VARCHAR(200) DEFAULT NULL,
    `data_cadastro`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `data_atualizacao` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_morador_id` (`morador_id`),
    KEY `idx_ativo`      (`ativo`),
    CONSTRAINT `fk_moradores_anexos_morador`
        FOREIGN KEY (`morador_id`) REFERENCES `moradores` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Documentos e anexos vinculados a moradores';

-- Verificar criação
SHOW TABLES LIKE 'moradores_anexos';
DESCRIBE moradores_anexos;
