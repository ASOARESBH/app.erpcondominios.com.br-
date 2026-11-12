-- H3 — delta estrutural comprovado para o HML Hetzner
-- Alvo validado: MySQL Community 8.4.13, schema erpcondominios.
-- Escopo: somente as lacunas estruturais aceitas após reconciliação H2.
-- Não contém DML, DROP, TRUNCATE, procedures ou segredos.
-- Oracle MySQL 8.4 não aceita ADD COLUMN/INDEX IF NOT EXISTS em ALTER TABLE;
-- as alterações abaixo usam guards INFORMATION_SCHEMA e SQL dinâmico.
-- Se um pre-requisito estrutural falhar, PREPARE recebe um comando inválido
-- intencionalmente e interrompe o cliente mysql (não executar com --force).

USE `erpcondominios`;

-- 1. Alertas de acesso: as cinco tabelas estavam ausentes no catálogo HML.
CREATE TABLE IF NOT EXISTS `alertas_acesso` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `nome` VARCHAR(150) NOT NULL,
    `descricao` TEXT NULL,
    `severidade` ENUM('informativo','atencao','critico') NOT NULL DEFAULT 'atencao',
    `canais_json` TEXT NOT NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `criado_por_usuario_id` INT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_alertas_tenant_ativo` (`tenant_id`, `ativo`),
    INDEX `idx_alertas_tenant_nome` (`tenant_id`, `nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_acesso_criterios` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `alerta_id` INT NOT NULL,
    `tipo` ENUM('veiculo','pessoa','contexto') NOT NULL,
    `campo` VARCHAR(50) NOT NULL,
    `operador` ENUM('igual','contem','comeca_com') NOT NULL DEFAULT 'igual',
    `valor` VARCHAR(255) NOT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_criterios_alerta` (`tenant_id`, `alerta_id`),
    INDEX `idx_criterios_busca` (`tenant_id`, `tipo`, `campo`, `valor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_acesso_eventos` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `alerta_id` INT NOT NULL,
    `evento_uuid` VARCHAR(80) NOT NULL,
    `origem` VARCHAR(40) NOT NULL,
    `dados_json` MEDIUMTEXT NULL,
    `detectado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('pendente','notificado','reconhecido','ignorado') NOT NULL DEFAULT 'pendente',
    `reconhecido_por_usuario_id` INT NULL,
    `reconhecido_em` DATETIME NULL,
    UNIQUE KEY `uk_alerta_evento` (`tenant_id`, `alerta_id`, `evento_uuid`),
    INDEX `idx_eventos_tenant_data` (`tenant_id`, `detectado_em`),
    INDEX `idx_eventos_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_acesso_entregas` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `evento_id` BIGINT NOT NULL,
    `canal` ENUM('sistema','email','whatsapp') NOT NULL,
    `destinatario` VARCHAR(255) NULL,
    `status` ENUM('pendente','enviado','falhou','nao_configurado') NOT NULL DEFAULT 'pendente',
    `detalhe` VARCHAR(500) NULL,
    `enviado_em` DATETIME NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_evento_canal_destinatario` (`evento_id`, `canal`, `destinatario`),
    INDEX `idx_entregas_tenant_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_acesso_leituras` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT NOT NULL,
    `evento_id` BIGINT NOT NULL,
    `usuario_id` INT NOT NULL,
    `lido` TINYINT(1) NOT NULL DEFAULT 0,
    `lido_em` DATETIME NULL,
    UNIQUE KEY `uk_evento_usuario` (`evento_id`, `usuario_id`),
    INDEX `idx_leituras_usuario` (`tenant_id`, `usuario_id`, `lido`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Campo opcional de observação. Tabela e âncora foram verificadas no HML.
SET @h3_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'abastecimento_lancamentos'
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'abastecimento_lancamentos'
          AND COLUMN_NAME = 'usuario_logado'
    ),
    '__H3_ABORT_MISSING_ABASTECIMENTO_TABLE_OR_ANCHOR__',
    IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'abastecimento_lancamentos'
              AND COLUMN_NAME = 'observacao'
        ),
        'SELECT 1',
        'ALTER TABLE `abastecimento_lancamentos` ADD COLUMN `observacao` TEXT NULL COMMENT ''Informações adicionais do abastecimento'' AFTER `usuario_logado`'
    )
);
PREPARE h3_stmt FROM @h3_sql;
EXECUTE h3_stmt;
DEALLOCATE PREPARE h3_stmt;

-- 3. Chaves de idempotência para sincronização offline de leituras e fotos.
SET @h3_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras'
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras'
          AND COLUMN_NAME = 'tenant_id'
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras'
          AND COLUMN_NAME = 'data_leitura'
    ),
    '__H3_ABORT_MISSING_LEITURAS_TABLE_OR_ANCHOR__',
    IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras'
              AND COLUMN_NAME = 'client_uuid'
        ),
        'SELECT 1',
        'ALTER TABLE `leituras` ADD COLUMN `client_uuid` VARCHAR(64) NULL AFTER `data_leitura`'
    )
);
PREPARE h3_stmt FROM @h3_sql;
EXECUTE h3_stmt;
DEALLOCATE PREPARE h3_stmt;

SET @h3_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras'
          AND COLUMN_NAME IN ('tenant_id','client_uuid')
        GROUP BY TABLE_NAME HAVING COUNT(DISTINCT COLUMN_NAME) = 2
    ) OR EXISTS (
        SELECT 1 FROM (
            SELECT `tenant_id`, `client_uuid`
            FROM `leituras`
            WHERE `tenant_id` IS NOT NULL AND `client_uuid` IS NOT NULL
            GROUP BY `tenant_id`, `client_uuid`
            HAVING COUNT(*) > 1
        ) AS h3_duplicate_keys
    ),
    '__H3_ABORT_LEITURAS_UNIQUE_INDEX_PRECONDITION_OR_DUPLICATES__',
    IF(
        EXISTS (
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras'
              AND INDEX_NAME = 'uq_leituras_tenant_client_uuid'
        ),
        'SELECT 1',
        'ALTER TABLE `leituras` ADD UNIQUE KEY `uq_leituras_tenant_client_uuid` (`tenant_id`, `client_uuid`)'
    )
);
PREPARE h3_stmt FROM @h3_sql;
EXECUTE h3_stmt;
DEALLOCATE PREPARE h3_stmt;

SET @h3_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras_fotos'
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras_fotos'
          AND COLUMN_NAME = 'tenant_id'
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras_fotos'
          AND COLUMN_NAME = 'hidrometro_id'
    ),
    '__H3_ABORT_MISSING_LEITURAS_FOTOS_TABLE_OR_ANCHOR__',
    IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras_fotos'
              AND COLUMN_NAME = 'client_uuid'
        ),
        'SELECT 1',
        'ALTER TABLE `leituras_fotos` ADD COLUMN `client_uuid` VARCHAR(64) NULL AFTER `hidrometro_id`'
    )
);
PREPARE h3_stmt FROM @h3_sql;
EXECUTE h3_stmt;
DEALLOCATE PREPARE h3_stmt;

SET @h3_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras_fotos'
          AND COLUMN_NAME IN ('tenant_id','client_uuid')
        GROUP BY TABLE_NAME HAVING COUNT(DISTINCT COLUMN_NAME) = 2
    ) OR EXISTS (
        SELECT 1 FROM (
            SELECT `tenant_id`, `client_uuid`
            FROM `leituras_fotos`
            WHERE `tenant_id` IS NOT NULL AND `client_uuid` IS NOT NULL
            GROUP BY `tenant_id`, `client_uuid`
            HAVING COUNT(*) > 1
        ) AS h3_duplicate_keys
    ),
    '__H3_ABORT_LEITURAS_FOTOS_UNIQUE_INDEX_PRECONDITION_OR_DUPLICATES__',
    IF(
        EXISTS (
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras_fotos'
              AND INDEX_NAME = 'uq_leituras_fotos_tenant_client_uuid'
        ),
        'SELECT 1',
        'ALTER TABLE `leituras_fotos` ADD UNIQUE KEY `uq_leituras_fotos_tenant_client_uuid` (`tenant_id`, `client_uuid`)'
    )
);
PREPARE h3_stmt FROM @h3_sql;
EXECUTE h3_stmt;
DEALLOCATE PREPARE h3_stmt;

-- 4. Índices ausentes da tabela os_chamados; colunas-alvo já existentes.
SET @h3_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'os_chamados'
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'os_chamados'
          AND COLUMN_NAME = 'origem_portal'
    ),
    '__H3_ABORT_MISSING_OS_ORIGEM_PORTAL_PRECONDITION__',
    IF(
        EXISTS (
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'os_chamados'
              AND INDEX_NAME = 'idx_origem_portal'
        ),
        'SELECT 1',
        'ALTER TABLE `os_chamados` ADD INDEX `idx_origem_portal` (`origem_portal`)'
    )
);
PREPARE h3_stmt FROM @h3_sql;
EXECUTE h3_stmt;
DEALLOCATE PREPARE h3_stmt;

SET @h3_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'os_chamados'
    ) OR NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'os_chamados'
          AND COLUMN_NAME = 'morador_id'
    ),
    '__H3_ABORT_MISSING_OS_MORADOR_PRECONDITION__',
    IF(
        EXISTS (
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'os_chamados'
              AND INDEX_NAME = 'idx_morador_id'
        ),
        'SELECT 1',
        'ALTER TABLE `os_chamados` ADD INDEX `idx_morador_id` (`morador_id`)'
    )
);
PREPARE h3_stmt FROM @h3_sql;
EXECUTE h3_stmt;
DEALLOCATE PREPARE h3_stmt;
