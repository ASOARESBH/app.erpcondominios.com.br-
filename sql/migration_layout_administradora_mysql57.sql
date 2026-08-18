-- ============================================================================
-- ERP Condomínio — Layout Administradora Multi-Tenant
-- Compatível com MySQL/MariaDB 5.7
--
-- Cadastro mestre de administradoras e layouts analíticos por módulo.
-- A seleção do tenant é mantida em tabelas próprias; não altera empresa nem
-- dados financeiros operacionais.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `administradoras_importacao` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(50) NOT NULL,
    `nome` VARCHAR(100) NOT NULL,
    `descricao` VARCHAR(255) DEFAULT NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `ordem` INT(11) NOT NULL DEFAULT 0,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_administradora_importacao_slug` (`slug`),
    KEY `idx_administradora_importacao_ativo` (`ativo`,`ordem`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `administradoras_layouts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `administradora_id` INT(11) NOT NULL,
    `codigo` VARCHAR(80) NOT NULL,
    `modulo` ENUM('CONTAS_RECEBER','INADIMPLENCIA','CONTAS_PAGAR','EXTRATO_BANCARIO') NOT NULL,
    `nome` VARCHAR(150) NOT NULL,
    `descricao` VARCHAR(255) DEFAULT NULL,
    `formato_aceito` VARCHAR(50) NOT NULL DEFAULT 'PDF, CSV',
    `status_implantacao` ENUM('PRONTO','CONFIGURADO','PLANEJADO') NOT NULL DEFAULT 'CONFIGURADO',
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `ordem` INT(11) NOT NULL DEFAULT 0,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_administradora_layout_codigo` (`administradora_id`,`codigo`),
    KEY `idx_administradora_layout_modulo` (`administradora_id`,`modulo`,`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `empresa_administradora` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tenant_id` INT(11) NOT NULL,
    `administradora_id` INT(11) NOT NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `usuario_atualizacao_id` INT(11) DEFAULT NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_empresa_administradora_tenant` (`tenant_id`),
    KEY `idx_empresa_administradora_adm` (`administradora_id`,`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `empresa_administradora_layout` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tenant_id` INT(11) NOT NULL,
    `administradora_layout_id` INT(11) NOT NULL,
    `modulo` ENUM('CONTAS_RECEBER','INADIMPLENCIA','CONTAS_PAGAR','EXTRATO_BANCARIO') NOT NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `usuario_atualizacao_id` INT(11) DEFAULT NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_empresa_adm_layout_modulo` (`tenant_id`,`modulo`),
    KEY `idx_empresa_adm_layout_ativo` (`tenant_id`,`ativo`),
    KEY `idx_empresa_adm_layout_layout` (`administradora_layout_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `administradoras_importacao` (`slug`,`nome`,`descricao`,`ativo`,`ordem`) VALUES
('brcondos','BRCondos','Layouts analíticos para relatórios BRCondos.',1,10),
('pacto','PACTO','Layouts analíticos para relatórios da PACTO.',1,20),
('superlogica','Superlógica','Layouts analíticos para relatórios da Superlógica.',1,30),
('outra','Outra administradora','Configuração para layout a ser homologado.',1,99);

INSERT IGNORE INTO `administradoras_layouts` (`administradora_id`,`codigo`,`modulo`,`nome`,`descricao`,`formato_aceito`,`status_implantacao`,`ativo`,`ordem`)
SELECT a.id, l.codigo, l.modulo, l.nome, l.descricao, l.formato_aceito, l.status_implantacao, 1, l.ordem
FROM administradoras_importacao a
JOIN (
    SELECT 'brcondos' AS slug, 'BRCONDOS_RECEBER' AS codigo, 'CONTAS_RECEBER' AS modulo, 'Contas a Receber BRCondos' AS nome, 'Carteira de recebimentos e títulos em aberto.' AS descricao, 'PDF, CSV' AS formato_aceito, 'CONFIGURADO' AS status_implantacao, 10 AS ordem
    UNION ALL SELECT 'brcondos','BRCONDOS_INADIMPLENCIA','INADIMPLENCIA','Inadimplência Detalhada BRCondos','Relatório para snapshots e comparativo histórico.','PDF','PRONTO',20
    UNION ALL SELECT 'brcondos','BRCONDOS_PAGAR','CONTAS_PAGAR','Contas a Pagar BRCondos','Obrigações e vencimentos para análise.','PDF, CSV','CONFIGURADO',30
    UNION ALL SELECT 'pacto','PACTO_RECEBER','CONTAS_RECEBER','Contas a Receber PACTO','Carteira de recebimentos da administradora PACTO.','CSV, XLSX','CONFIGURADO',10
    UNION ALL SELECT 'pacto','PACTO_INADIMPLENCIA','INADIMPLENCIA','Inadimplência PACTO','Carteira de inadimplência para homologação de parser.','CSV, PDF','CONFIGURADO',20
    UNION ALL SELECT 'pacto','PACTO_PAGAR','CONTAS_PAGAR','Contas a Pagar PACTO','Obrigações da administradora PACTO.','CSV, XLSX','CONFIGURADO',30
    UNION ALL SELECT 'superlogica','SUPERLOGICA_RECEBER','CONTAS_RECEBER','Contas a Receber Superlógica','Recebimentos para análise financeira.','CSV, XLSX','CONFIGURADO',10
    UNION ALL SELECT 'superlogica','SUPERLOGICA_INADIMPLENCIA','INADIMPLENCIA','Inadimplência Superlógica','Carteira para homologação de parser.','CSV, PDF','CONFIGURADO',20
    UNION ALL SELECT 'superlogica','SUPERLOGICA_PAGAR','CONTAS_PAGAR','Contas a Pagar Superlógica','Obrigações para análise financeira.','CSV, XLSX','CONFIGURADO',30
    UNION ALL SELECT 'outra','OUTRA_HOMOLOGACAO','INADIMPLENCIA','Layout em homologação','Use para identificar a administradora e encaminhar um relatório modelo.','PDF, CSV, XLSX','PLANEJADO',10
) l ON l.slug = a.slug;

-- Validação somente leitura:
-- SELECT ea.tenant_id, a.nome AS administradora, al.modulo, al.nome AS layout, eal.ativo
-- FROM empresa_administradora ea
-- JOIN administradoras_importacao a ON a.id=ea.administradora_id
-- LEFT JOIN empresa_administradora_layout eal ON eal.tenant_id=ea.tenant_id
-- LEFT JOIN administradoras_layouts al ON al.id=eal.administradora_layout_id
-- ORDER BY ea.tenant_id, al.ordem;
