-- Dashboard personalizável por empresa e usuário
-- Compatível com MySQL 5.7+

CREATE TABLE IF NOT EXISTS dashboard_widgets_catalogo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    modulo_key VARCHAR(80) NOT NULL,
    modulo_nome VARCHAR(120) NOT NULL,
    modulo_icone VARCHAR(80) NOT NULL DEFAULT 'fas fa-chart-line',
    widget_key VARCHAR(120) NOT NULL,
    widget_nome VARCHAR(160) NOT NULL,
    widget_tipo ENUM('kpi','chart','list','alert','shortcut') NOT NULL DEFAULT 'kpi',
    descricao VARCHAR(255) NOT NULL DEFAULT '',
    tamanho_padrao VARCHAR(20) NOT NULL DEFAULT '1x1',
    ordem INT NOT NULL DEFAULT 0,
    disponivel TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_dashboard_widget_key (widget_key),
    KEY idx_dashboard_modulo_ordem (modulo_key, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_empresa_widgets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    widget_key VARCHAR(120) NOT NULL,
    habilitado TINYINT(1) NOT NULL DEFAULT 1,
    atualizado_por INT NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_dashboard_empresa_widget (tenant_id, widget_key),
    KEY idx_dashboard_empresa_tenant (tenant_id),
    CONSTRAINT fk_dashboard_empresa_widget_catalogo FOREIGN KEY (widget_key) REFERENCES dashboard_widgets_catalogo(widget_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_usuario_widgets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    usuario_id INT NOT NULL,
    widget_key VARCHAR(120) NOT NULL,
    habilitado TINYINT(1) NOT NULL DEFAULT 1,
    posicao INT NOT NULL DEFAULT 0,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_dashboard_usuario_widget (tenant_id, usuario_id, widget_key),
    KEY idx_dashboard_usuario (tenant_id, usuario_id),
    CONSTRAINT fk_dashboard_usuario_widget_catalogo FOREIGN KEY (widget_key) REFERENCES dashboard_widgets_catalogo(widget_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dashboard_widgets_catalogo
(modulo_key, modulo_nome, modulo_icone, widget_key, widget_nome, widget_tipo, descricao, tamanho_padrao, ordem) VALUES
('financeiro','Financeiro','fas fa-wallet','financeiro_receita_mes','Receita do mês','kpi','Total de receitas no mês corrente.','1x1',10),
('financeiro','Financeiro','fas fa-wallet','financeiro_despesa_mes','Despesa do mês','kpi','Total de despesas no mês corrente.','1x1',20),
('financeiro','Financeiro','fas fa-wallet','financeiro_inadimplencia','Taxa de inadimplência','kpi','Percentual de unidades inadimplentes.','1x1',30),
('protocolos','Protocolos / OS','fas fa-screwdriver-wrench','os_abertas','OS abertas','kpi','Ordens de serviço abertas.','1x1',40),
('protocolos','Protocolos / OS','fas fa-screwdriver-wrench','os_recentes','Últimas OS abertas','list','Lista das ordens de serviço mais recentes.','2x1',50),
('contratos','Contratos','fas fa-file-signature','contratos_ativos','Contratos ativos','kpi','Contratos atualmente vigentes.','1x1',60),
('rh','Recursos Humanos','fas fa-users-cog','rh_colaboradores_ativos','Colaboradores ativos','kpi','Colaboradores ativos no tenant.','1x1',70),
('ged','GED','fas fa-folder-open','ged_documentos','Documentos cadastrados','kpi','Quantidade de documentos disponíveis.','1x1',80),
('acesso','Acesso e Segurança','fas fa-shield-halved','veiculos_total','Veículos cadastrados','kpi','Total de veículos cadastrados.','1x1',90),
('acesso','Acesso e Segurança','fas fa-shield-halved','acessos_hoje','Acessos hoje','kpi','Acessos registrados hoje.','1x1',100),
('acesso','Acesso e Segurança','fas fa-shield-halved','lpr_recentes','Últimos acessos LPR','list','Últimas leituras de placas.','2x1',110),
('visitantes','Visitantes','fas fa-user-clock','visitantes_pendentes','Visitantes aguardando liberação','kpi','Visitantes pendentes de liberação.','1x1',120),
('unidades','Unidades e Moradores','fas fa-building','moradores_total','Total de moradores','kpi','Moradores cadastrados.','1x1',130),
('unidades','Unidades e Moradores','fas fa-building','unidades_ocupacao','Ocupação das unidades','chart','Ocupação por unidade ou bloco.','2x1',140),
('manutencao','Manutenção','fas fa-tools','manutencoes_abertas','Manutenções em aberto','kpi','Manutenções e OS de manutenção em aberto.','1x1',150),
('relatorios','Atalhos / Relatórios','fas fa-chart-pie','atalhos_relatorios','Relatórios mais usados','shortcut','Atalhos configuráveis para relatórios.','2x1',160);
