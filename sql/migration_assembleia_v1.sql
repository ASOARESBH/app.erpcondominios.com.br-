-- ============================================================
-- MIGRATION: Módulo de Assembleia v1.0
-- Criado em: 2026-06-05
-- Descrição: Tabelas para assembleias, pautas, tópicos, votos e anexos
-- ============================================================

-- ── 1. ASSEMBLEIAS ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS assembleias (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(255)  NOT NULL,
    tipo            ENUM('ordinaria','extraordinaria','deliberacao') NOT NULL DEFAULT 'ordinaria',
    data_assembleia DATETIME      NOT NULL,
    local_realizacao VARCHAR(255) DEFAULT NULL,
    descricao       TEXT          DEFAULT NULL,
    status          ENUM('rascunho','convocada','em_andamento','encerrada','cancelada') NOT NULL DEFAULT 'rascunho',
    quorum_minimo   INT UNSIGNED  DEFAULT 0 COMMENT 'Número mínimo de moradores para quórum',
    criado_por      INT UNSIGNED  DEFAULT NULL,
    criado_em       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_data   (data_assembleia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. PAUTAS (itens de pauta de cada assembleia) ──────────
CREATE TABLE IF NOT EXISTS assembleia_pautas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assembleia_id   INT UNSIGNED NOT NULL,
    ordem           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    titulo          VARCHAR(255) NOT NULL,
    descricao       TEXT         DEFAULT NULL,
    tipo            ENUM('informativo','votacao','tema') NOT NULL DEFAULT 'informativo'
                    COMMENT 'informativo=somente texto; votacao=aprovo/nao_aprovo/anular; tema=discussao livre',
    status          ENUM('pendente','em_votacao','encerrado') NOT NULL DEFAULT 'pendente',
    resultado       ENUM('aprovado','reprovado','anulado','sem_quorum','pendente') DEFAULT 'pendente',
    votos_aprovado  INT UNSIGNED NOT NULL DEFAULT 0,
    votos_reprovado INT UNSIGNED NOT NULL DEFAULT 0,
    votos_anulado   INT UNSIGNED NOT NULL DEFAULT 0,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assembleia_id) REFERENCES assembleias(id) ON DELETE CASCADE,
    INDEX idx_assembleia (assembleia_id),
    INDEX idx_ordem      (assembleia_id, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. VOTOS ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS assembleia_votos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pauta_id        INT UNSIGNED NOT NULL,
    assembleia_id   INT UNSIGNED NOT NULL,
    morador_id      INT UNSIGNED DEFAULT NULL COMMENT 'NULL = voto presencial registrado pelo admin',
    unidade         VARCHAR(50)  DEFAULT NULL,
    nome_votante    VARCHAR(255) DEFAULT NULL,
    voto            ENUM('aprovado','reprovado','anulado') NOT NULL,
    tipo_participacao ENUM('online','presencial') NOT NULL DEFAULT 'presencial',
    ip_votante      VARCHAR(45)  DEFAULT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_voto_morador (pauta_id, morador_id),
    FOREIGN KEY (pauta_id)      REFERENCES assembleia_pautas(id) ON DELETE CASCADE,
    FOREIGN KEY (assembleia_id) REFERENCES assembleias(id)       ON DELETE CASCADE,
    INDEX idx_pauta      (pauta_id),
    INDEX idx_assembleia (assembleia_id),
    INDEX idx_morador    (morador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. ANEXOS (ata de convocação, ata de encerramento, etc.) ──
CREATE TABLE IF NOT EXISTS assembleia_anexos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assembleia_id   INT UNSIGNED NOT NULL,
    tipo_anexo      ENUM('convocacao','ata_encerramento','documento','outro') NOT NULL DEFAULT 'documento',
    nome_arquivo    VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(512) NOT NULL,
    tamanho_bytes   INT UNSIGNED DEFAULT 0,
    mime_type       VARCHAR(100) DEFAULT NULL,
    enviado_por     INT UNSIGNED DEFAULT NULL,
    enviado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assembleia_id) REFERENCES assembleias(id) ON DELETE CASCADE,
    INDEX idx_assembleia (assembleia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. PARTICIPANTES (controle de presença) ─────────────────
CREATE TABLE IF NOT EXISTS assembleia_participantes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assembleia_id   INT UNSIGNED NOT NULL,
    morador_id      INT UNSIGNED DEFAULT NULL,
    unidade         VARCHAR(50)  DEFAULT NULL,
    nome            VARCHAR(255) NOT NULL,
    tipo_participacao ENUM('presencial','online','procuracao') NOT NULL DEFAULT 'presencial',
    confirmado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assembleia_id) REFERENCES assembleias(id) ON DELETE CASCADE,
    INDEX idx_assembleia (assembleia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. SEED: Adicionar 'assembleia' ao catálogo de módulos ──
INSERT IGNORE INTO modulos_sistema (chave, nome, descricao, grupo, icone, ordem)
VALUES ('assembleia', 'Assembleia', 'Criação e gestão de assembleias, pautas e votações', 'Administrativo', 'fa-landmark', 90);

-- ── 7. Adicionar 'checklists' ao catálogo de módulos (se ausente) ──
INSERT IGNORE INTO modulos_sistema (chave, nome, descricao, grupo, icone, ordem)
VALUES ('checklists', 'Checklists', 'Listas de verificação para rondas e inspeções', 'Manutenção', 'fa-clipboard-list', 75);
