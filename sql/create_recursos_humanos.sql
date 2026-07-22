-- ============================================================
-- Módulo Recursos Humanos
-- Execute via phpMyAdmin no banco do HostGator
-- ============================================================

-- Colaboradores
CREATE TABLE IF NOT EXISTS rh_colaboradores (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome                VARCHAR(150) NOT NULL,
    cpf                 VARCHAR(14)  NOT NULL UNIQUE,
    rg                  VARCHAR(20)  DEFAULT NULL,
    data_nascimento     DATE         DEFAULT NULL,
    sexo                ENUM('M','F','O') DEFAULT NULL,
    estado_civil        ENUM('solteiro','casado','divorciado','viuvo','uniao_estavel') DEFAULT NULL,
    cargo               VARCHAR(100) DEFAULT NULL,
    departamento        VARCHAR(100) DEFAULT NULL,
    tipo_contrato       ENUM('clt','pj','estagiario','temporario','autonomo') DEFAULT 'clt',
    data_admissao       DATE         DEFAULT NULL,
    data_demissao       DATE         DEFAULT NULL,
    salario             DECIMAL(10,2) DEFAULT NULL,
    -- Contato
    telefone            VARCHAR(20)  DEFAULT NULL,
    celular             VARCHAR(20)  DEFAULT NULL,
    email               VARCHAR(150) DEFAULT NULL,
    -- Endereço
    cep                 VARCHAR(10)  DEFAULT NULL,
    logradouro          VARCHAR(200) DEFAULT NULL,
    numero              VARCHAR(20)  DEFAULT NULL,
    complemento         VARCHAR(100) DEFAULT NULL,
    bairro              VARCHAR(100) DEFAULT NULL,
    cidade              VARCHAR(100) DEFAULT NULL,
    estado              CHAR(2)      DEFAULT NULL,
    -- Dados bancários
    banco               VARCHAR(100) DEFAULT NULL,
    agencia             VARCHAR(20)  DEFAULT NULL,
    conta               VARCHAR(30)  DEFAULT NULL,
    pix                 VARCHAR(150) DEFAULT NULL,
    -- Foto e observações
    foto_path           VARCHAR(300) DEFAULT NULL,
    observacoes         TEXT         DEFAULT NULL,
    ativo               TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ativo     (ativo),
    INDEX idx_nome      (nome),
    INDEX idx_departamento (departamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Escalas de trabalho por colaborador
CREATE TABLE IF NOT EXISTS rh_escala (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colaborador_id              INT UNSIGNED NOT NULL,
    nome_escala                 VARCHAR(100) NOT NULL DEFAULT 'Principal',
    tipo                        ENUM('livre','controle_jornada','alternada') NOT NULL DEFAULT 'livre',
    carga_horaria_diaria_min    INT UNSIGNED DEFAULT 480,   -- minutos (8h = 480)
    dias_trabalho               JSON         DEFAULT NULL,  -- ["seg","ter","qua","qui","sex"]
    hora_entrada                TIME         DEFAULT '08:00:00',
    hora_almoco_saida           TIME         DEFAULT '12:00:00',
    hora_almoco_retorno         TIME         DEFAULT '13:00:00',
    hora_saida                  TIME         DEFAULT '17:00:00',
    tolerancia_minutos          INT UNSIGNED DEFAULT 10,
    intervalo_almoco_min        INT UNSIGNED DEFAULT 60,
    -- Escala Alternada
    alternada_ativa             TINYINT(1)   DEFAULT 0,     -- 1 = escala alternada
    alternada_dia_inicio        DATE         DEFAULT NULL,  -- data de início da 1ª semana
    alternada_semana_a          JSON         DEFAULT NULL,  -- dias da semana A ["seg","qua","sex","dom"]
    alternada_semana_b          JSON         DEFAULT NULL,  -- dias da semana B ["ter","qui","sab"]
    alternada_tipo_folga        ENUM('folga','falta','feriado') DEFAULT 'folga', -- tipo lançado nos dias de folga alternada
    ativo                       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_escala_colab  FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id),
    INDEX idx_colaborador       (colaborador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Períodos de ponto (mês/ano por colaborador)
CREATE TABLE IF NOT EXISTS rh_ponto_periodo (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colaborador_id              INT UNSIGNED NOT NULL,
    mes                         TINYINT UNSIGNED NOT NULL,   -- 1-12
    ano                         SMALLINT UNSIGNED NOT NULL,
    status                      ENUM('aberto','fechado') NOT NULL DEFAULT 'aberto',
    total_horas_trabalhadas_min INT DEFAULT 0,
    total_horas_extras_min      INT DEFAULT 0,
    total_atraso_min            INT DEFAULT 0,
    total_faltas                INT DEFAULT 0,
    total_folgas                INT DEFAULT 0,
    observacoes                 TEXT DEFAULT NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_periodo       (colaborador_id, mes, ano),
    CONSTRAINT fk_periodo_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id),
    INDEX idx_periodo_mes_ano   (mes, ano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lançamentos diários de ponto
CREATE TABLE IF NOT EXISTS rh_ponto_lancamento (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    periodo_id              INT UNSIGNED NOT NULL,
    colaborador_id          INT UNSIGNED NOT NULL,
    data                    DATE         NOT NULL,
    hora_entrada            TIME         DEFAULT NULL,
    hora_almoco_saida       TIME         DEFAULT NULL,
    hora_almoco_retorno     TIME         DEFAULT NULL,
    hora_saida              TIME         DEFAULT NULL,
    tipo_dia                ENUM('normal','folga','falta','feriado','meio_periodo','afastamento','horas_extras') NOT NULL DEFAULT 'normal',
    horas_trabalhadas_min   INT          DEFAULT 0,
    horas_extras_min        INT          DEFAULT 0,
    atraso_min              INT          DEFAULT 0,
    observacoes             VARCHAR(300) DEFAULT NULL,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_lancamento (periodo_id, data),
    CONSTRAINT fk_lanc_periodo  FOREIGN KEY (periodo_id)     REFERENCES rh_ponto_periodo(id)     ON DELETE CASCADE,
    CONSTRAINT fk_lanc_colab    FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id),
    INDEX idx_lancamento_data   (data),
    INDEX idx_lancamento_colab  (colaborador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MIGRATIONS — execute apenas se o banco já existia
-- ============================================================

-- 1. Adicionar tipo horas_extras no lançamento de ponto
ALTER TABLE rh_ponto_lancamento
    MODIFY COLUMN tipo_dia ENUM('normal','folga','falta','feriado','meio_periodo','afastamento','horas_extras') NOT NULL DEFAULT 'normal';

-- 2. Adicionar suporte a escala alternada na tabela de escalas
ALTER TABLE rh_escala
    MODIFY COLUMN tipo ENUM('livre','controle_jornada','alternada') NOT NULL DEFAULT 'livre';

ALTER TABLE rh_escala
    ADD COLUMN IF NOT EXISTS alternada_ativa       TINYINT(1)   DEFAULT 0 AFTER intervalo_almoco_min,
    ADD COLUMN IF NOT EXISTS alternada_dia_inicio  DATE         DEFAULT NULL AFTER alternada_ativa,
    ADD COLUMN IF NOT EXISTS alternada_semana_a    JSON         DEFAULT NULL AFTER alternada_dia_inicio,
    ADD COLUMN IF NOT EXISTS alternada_semana_b    JSON         DEFAULT NULL AFTER alternada_semana_a,
    ADD COLUMN IF NOT EXISTS alternada_tipo_folga  ENUM('folga','falta','feriado') DEFAULT 'folga' AFTER alternada_semana_b;
