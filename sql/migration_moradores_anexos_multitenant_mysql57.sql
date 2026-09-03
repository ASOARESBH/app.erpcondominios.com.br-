-- Migração de anexos de moradores para MySQL 5.7 / HostGator.
-- Não consulta information_schema.
-- Execute no banco correto pelo phpMyAdmin.

CREATE TABLE IF NOT EXISTS `moradores_anexos` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tenant_id` INT(11) NOT NULL DEFAULT 1,
    `morador_id` INT(11) NOT NULL,
    `nome_documento` VARCHAR(200) NOT NULL,
    `nome_arquivo` VARCHAR(255) NOT NULL,
    `nome_original` VARCHAR(255) NOT NULL,
    `caminho` VARCHAR(500) NOT NULL,
    `tipo_mime` VARCHAR(100) NOT NULL,
    `tamanho_bytes` INT(11) NOT NULL DEFAULT 0,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `criado_por` VARCHAR(200) DEFAULT NULL,
    `data_cadastro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tenant_morador` (`tenant_id`, `morador_id`),
    KEY `idx_morador_id` (`morador_id`),
    KEY `idx_ativo` (`ativo`),
    CONSTRAINT `fk_moradores_anexos_morador`
        FOREIGN KEY (`morador_id`) REFERENCES `moradores` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Se a tabela já existia sem tenant_id, execute esta linha uma única vez:
-- ALTER TABLE moradores_anexos ADD COLUMN tenant_id INT(11) NOT NULL DEFAULT 1 AFTER id;

-- Se a tabela já existia sem o índice multi-tenant, execute esta linha uma única vez:
-- ALTER TABLE moradores_anexos ADD INDEX idx_tenant_morador (tenant_id, morador_id);

SHOW TABLES LIKE 'moradores_anexos';
DESCRIBE moradores_anexos;

-- Registros antigos recebem tenant_id=1 pelo valor DEFAULT.
-- Revise essa atribuição se houver vários condomínios no mesmo banco.

SELECT 'Migração concluída: moradores_anexos disponível' AS status;

-- O upload também requer a tabela tenant_arquivos.
-- Se ela não existir, importe migration_arquivos_tenant_mysql57.sql.

-- Fim.

-- Compatibilidade: esta versão não acessa information_schema e não cria procedures.
