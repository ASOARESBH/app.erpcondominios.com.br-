-- ============================================================
-- SQL: TABELA DEPENDENTES DO PORTAL DO MORADOR
-- Sistema ERP Serra da Liberdade
-- Data: 2026-03-22
-- ============================================================
-- Cria a tabela de dependentes vinculados a moradores.
-- Executar após criar_tabelas_portal.sql.
-- ============================================================

CREATE TABLE IF NOT EXISTS `dependentes` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `morador_id`       INT(11)      NOT NULL COMMENT 'FK moradores.id',
  `nome_completo`    VARCHAR(150) NOT NULL,
  `cpf`              VARCHAR(20)  DEFAULT NULL,
  `parentesco`       VARCHAR(60)  DEFAULT NULL COMMENT 'Ex: Cônjuge, Filho(a), Pai, Mãe',
  `data_nascimento`  DATE         DEFAULT NULL,
  `email`            VARCHAR(150) DEFAULT NULL,
  `telefone`         VARCHAR(20)  DEFAULT NULL,
  `celular`          VARCHAR(20)  DEFAULT NULL,
  `observacao`       TEXT         DEFAULT NULL,
  `ativo`            TINYINT(1)   NOT NULL DEFAULT 1,
  `data_cadastro`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dep_morador` (`morador_id`),
  KEY `idx_dep_ativo`   (`ativo`),
  CONSTRAINT `fk_dep_morador`
    FOREIGN KEY (`morador_id`) REFERENCES `moradores` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Dependentes dos moradores — gerenciado pelo Portal do Morador';

-- ── Verificação ──────────────────────────────────────────────
SHOW TABLES LIKE 'dependentes';
DESCRIBE dependentes;
