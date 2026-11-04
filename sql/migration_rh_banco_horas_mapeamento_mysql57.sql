-- ============================================================================
-- RH — Banco de Horas: política de extras, mapeamento e reprocessamento
-- MySQL 5.7 / MariaDB 10.2+. Idempotente sem SELECT em INFORMATION_SCHEMA:
-- usa handlers nativos para colunas/índices já existentes (privilégio HostGator).
-- Pré-requisito: migration_rh_banco_horas_saldo_negativo_mysql57.sql aplicada;
-- tabelas rh_escala, rh_colaboradores, rh_ponto_periodo e rh_ponto_lancamento
-- já tenant-aware. Faça backup e execute em janela de manutenção.
-- Esta migration NÃO altera/apaga registros manuais nem reprocessa dados.
-- ============================================================================

CREATE TABLE IF NOT EXISTS rh_banco_horas_reprocessamentos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    usuario_id INT NULL,
    usuario_nome VARCHAR(160) NULL,
    escopo ENUM('COLABORADOR','DEPARTAMENTO') NOT NULL,
    colaborador_id INT NULL,
    departamento_id VARCHAR(100) NULL,
    data_inicio DATE NOT NULL,
    data_fim DATE NOT NULL,
    regra VARCHAR(40) NOT NULL DEFAULT 'CREDITAR_TUDO',
    versao_calculo VARCHAR(20) NOT NULL DEFAULT '2026.11',
    hash_previa CHAR(64) NOT NULL,
    hash_entrada CHAR(64) NOT NULL,
    motivo VARCHAR(500) NOT NULL,
    totais_antes_json MEDIUMTEXT NULL,
    totais_depois_json MEDIUMTEXT NULL,
    dias_alterados INT NOT NULL DEFAULT 0,
    incluir_fechados TINYINT(1) NOT NULL DEFAULT 0,
    ignorados_json MEDIUMTEXT NULL,
    status ENUM('PREVIA','PROCESSANDO','CONCLUIDO','FALHA') NOT NULL DEFAULT 'PREVIA',
    ip VARCHAR(45) NULL,
    iniciado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_rh_bh_reproc_tenant_inicio (tenant_id, iniciado_em),
    KEY idx_rh_bh_reproc_tenant_colab (tenant_id, colaborador_id, data_inicio, data_fim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS migration_rh_banco_horas_mapeamento;
DELIMITER $$
CREATE PROCEDURE migration_rh_banco_horas_mapeamento()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END;

    -- Colunas consumidas pelo cálculo compartilhado de Ponto/Abono.
    ALTER TABLE rh_ponto_lancamento ADD COLUMN saida_antecipada_min INT NOT NULL DEFAULT 0;
    ALTER TABLE rh_ponto_lancamento ADD COLUMN abono_extras ENUM('nenhum','total','parcial') NOT NULL DEFAULT 'nenhum';
    ALTER TABLE rh_ponto_lancamento ADD COLUMN abono_extras_min INT NOT NULL DEFAULT 0;
    ALTER TABLE rh_ponto_lancamento ADD COLUMN abono_falta TINYINT(1) NOT NULL DEFAULT 0;
    ALTER TABLE rh_ponto_lancamento ADD COLUMN abono_atraso ENUM('nenhum','total','parcial') NOT NULL DEFAULT 'nenhum';
    ALTER TABLE rh_ponto_lancamento ADD COLUMN abono_atraso_min INT NOT NULL DEFAULT 0;
    ALTER TABLE rh_ponto_lancamento ADD COLUMN abono_justificativa TEXT NULL;
    ALTER TABLE rh_ponto_lancamento ADD COLUMN abono_usuario VARCHAR(100) NULL;
    ALTER TABLE rh_ponto_lancamento ADD COLUMN abono_data DATETIME NULL;
    ALTER TABLE rh_ponto_periodo ADD COLUMN total_saida_antecipada_min INT NOT NULL DEFAULT 0;
    ALTER TABLE rh_ponto_periodo ADD COLUMN total_carga_prevista_min INT NOT NULL DEFAULT 0;

    -- Colunas de escala que eram criadas em cada request pela API antiga.
    ALTER TABLE rh_escala ADD COLUMN carga_horaria_mensal_min INT UNSIGNED NOT NULL DEFAULT 0;
    ALTER TABLE rh_escala ADD COLUMN descanso_interjornada_min INT UNSIGNED NOT NULL DEFAULT 0;
    ALTER TABLE rh_escala ADD COLUMN regime_12x36 TINYINT(1) NOT NULL DEFAULT 0;
    ALTER TABLE rh_escala ADD COLUMN banco_horas_ativo TINYINT(1) NOT NULL DEFAULT 0;
    ALTER TABLE rh_escala ADD COLUMN escala_manual_semana TEXT NULL DEFAULT NULL;
    ALTER TABLE rh_escala MODIFY COLUMN tipo ENUM('livre','controle_jornada','alternada','jornada_44h','jornada_40h','jornada_36h','escala_manual') NOT NULL DEFAULT 'livre';

    -- O limite legal é alerta de jornada, não motivo para descartar horas.
    ALTER TABLE rh_escala
      ADD COLUMN politica_extra_excedente ENUM('CREDITAR_TUDO','LIMITAR_E_SEPARAR') NOT NULL DEFAULT 'CREDITAR_TUDO'
        COMMENT 'Política de crédito de extras líquidos; padrão CREDITAR_TUDO';
    ALTER TABLE rh_escala
      ADD COLUMN limite_extra_diario_min INT UNSIGNED NOT NULL DEFAULT 120
        COMMENT 'Limite diário informativo e teto da política LIMITAR_E_SEPARAR, em minutos';

    ALTER TABLE rh_banco_horas
      MODIFY COLUMN tipo ENUM('credito','debito','abatimento','pagamento','excedente') NOT NULL;
    ALTER TABLE rh_banco_horas ADD COLUMN minutos_apurados INT NULL;
    ALTER TABLE rh_banco_horas ADD COLUMN limite_aplicado_min INT NULL;
    ALTER TABLE rh_banco_horas ADD COLUMN regra_aplicada VARCHAR(40) NULL;
    ALTER TABLE rh_banco_horas ADD COLUMN versao_calculo VARCHAR(20) NULL;
    ALTER TABLE rh_banco_horas ADD COLUMN reprocessamento_id INT UNSIGNED NULL;

    ALTER TABLE rh_banco_horas_reprocessamentos ADD COLUMN hash_entrada CHAR(64) NOT NULL DEFAULT '' AFTER hash_previa;
    ALTER TABLE rh_banco_horas_reprocessamentos ADD COLUMN usuario_nome VARCHAR(160) NULL AFTER usuario_id;
    ALTER TABLE rh_banco_horas_reprocessamentos ADD COLUMN incluir_fechados TINYINT(1) NOT NULL DEFAULT 0 AFTER dias_alterados;
    ALTER TABLE rh_banco_horas_reprocessamentos
      MODIFY COLUMN status ENUM('PREVIA','PROCESSANDO','CONCLUIDO','FALHA') NOT NULL DEFAULT 'PREVIA';

    CREATE INDEX idx_rh_bh_reprocessamento ON rh_banco_horas (tenant_id, reprocessamento_id);
END$$
DELIMITER ;

CALL migration_rh_banco_horas_mapeamento();
DROP PROCEDURE IF EXISTS migration_rh_banco_horas_mapeamento;

-- Verificação manual compatível com contas sem acesso a INFORMATION_SCHEMA.
SHOW COLUMNS FROM rh_escala;
SHOW COLUMNS FROM rh_banco_horas;
SHOW INDEX FROM rh_banco_horas;
SHOW COLUMNS FROM rh_banco_horas_reprocessamentos;
