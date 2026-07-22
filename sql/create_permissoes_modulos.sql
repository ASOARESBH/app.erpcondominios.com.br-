-- =====================================================================
-- SISTEMA DE CONTROLE DE ACESSO POR MÓDULO
-- Versão 1.0 — Mapeamento completo de 35 módulos em 10 grupos
-- =====================================================================

-- Tabela de definição dos módulos do sistema
CREATE TABLE IF NOT EXISTS `modulos_sistema` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `chave`        VARCHAR(60)  NOT NULL COMMENT 'Identificador único (data-page)',
    `nome`         VARCHAR(100) NOT NULL COMMENT 'Nome exibido na interface',
    `grupo`        VARCHAR(60)  NOT NULL COMMENT 'Grupo/categoria do módulo',
    `icone`        VARCHAR(60)  NOT NULL DEFAULT 'fas fa-circle',
    `descricao`    VARCHAR(255) DEFAULT NULL,
    `permissao_minima` ENUM('visualizador','operador','gerente','admin') NOT NULL DEFAULT 'operador'
                       COMMENT 'Permissão mínima de perfil para acessar',
    `ativo`        TINYINT(1)   NOT NULL DEFAULT 1,
    `ordem`        SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_chave` (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de permissões individuais por usuário
CREATE TABLE IF NOT EXISTS `usuario_modulos` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id`   INT UNSIGNED NOT NULL,
    `modulo_chave` VARCHAR(60)  NOT NULL,
    `pode_acessar` TINYINT(1)   NOT NULL DEFAULT 1,
    `pode_criar`   TINYINT(1)   NOT NULL DEFAULT 0,
    `pode_editar`  TINYINT(1)   NOT NULL DEFAULT 0,
    `pode_excluir` TINYINT(1)   NOT NULL DEFAULT 0,
    `pode_exportar`TINYINT(1)   NOT NULL DEFAULT 0,
    `criado_em`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em`DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_usuario_modulo` (`usuario_id`, `modulo_chave`),
    KEY `idx_usuario_id` (`usuario_id`),
    KEY `idx_modulo_chave` (`modulo_chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- INSERÇÃO DOS MÓDULOS DO SISTEMA (35 módulos em 10 grupos)
-- =====================================================================
INSERT INTO `modulos_sistema` (`chave`, `nome`, `grupo`, `icone`, `descricao`, `permissao_minima`, `ordem`) VALUES

-- GRUPO 1: CORE (sempre visível para todos)
('dashboard',              'Dashboard',              'Core',          'fas fa-chart-line',      'Painel principal com KPIs e gráficos',                        'visualizador', 10),

-- GRUPO 2: CONDÔMINOS
('moradores',              'Moradores',              'Condomínios',   'fas fa-users',           'Cadastro e gestão de moradores e dependentes',                'operador',     20),
('veiculos',               'Veículos',               'Condomínios',   'fas fa-car',             'Cadastro e gestão de veículos e tags RFID',                   'operador',     21),
('visitantes',             'Visitantes',             'Condomínios',   'fas fa-user-friends',    'Cadastro e controle de visitantes',                           'operador',     22),

-- GRUPO 3: CONTROLE DE ACESSO
('registro',               'Registro Manual',        'Acesso',        'fas fa-clipboard-list',  'Registro manual de entrada e saída',                          'operador',     30),
('acesso',                 'Controle de Acesso',     'Acesso',        'fas fa-door-open',       'Histórico e monitoramento de acessos',                        'operador',     31),
('relatorios',             'Relatórios de Acesso',   'Acesso',        'fas fa-file-alt',        'Relatórios filtrados de acessos e veículos',                  'gerente',      32),

-- GRUPO 4: FINANCEIRO
('financeiro',             'Financeiro (Visão Geral)','Financeiro',   'fas fa-money-bill-wave', 'Painel financeiro com resumo geral',                          'gerente',      40),
('contas_pagar',           'Contas a Pagar',         'Financeiro',    'fas fa-arrow-up',        'Gestão de despesas e pagamentos',                             'gerente',      41),
('contas_receber',         'Contas a Receber',       'Financeiro',    'fas fa-arrow-down',      'Gestão de receitas e cobranças',                              'gerente',      42),
('planos_contas',          'Planos de Contas',       'Financeiro',    'fas fa-list-ol',         'Classificação contábil de receitas e despesas',               'gerente',      43),
('importacao_financeira',  'Importação Financeira',  'Financeiro',    'fas fa-file-import',     'Importação de extratos e contas de outros sistemas',          'gerente',      44),
('logs_financeiro',        'Logs Financeiros',       'Financeiro',    'fas fa-bug',             'Diagnóstico e logs de erros do módulo financeiro',            'admin',        45),

-- GRUPO 5: MANUTENÇÃO
('manutencao',             'Manutenção Geral',       'Manutenção',    'fas fa-tools',           'Ordens de serviço e chamados de manutenção',                  'operador',     50),
('hidrometro',             'Hidrômetros',            'Manutenção',    'fas fa-tint',            'Cadastro de hidrômetros por unidade',                         'operador',     51),
('leitura',                'Leituras de Hidrômetro', 'Manutenção',    'fas fa-tachometer-alt',  'Registro de leituras mensais de consumo',                     'operador',     52),
('relatorios_hidrometro',  'Relatórios Hidrômetro',  'Manutenção',    'fas fa-chart-bar',       'Relatórios de consumo de água por unidade',                   'gerente',      53),
('abastecimento',          'Abastecimento',          'Manutenção',    'fas fa-gas-pump',        'Controle de abastecimento de veículos e equipamentos',        'operador',     54),
('estoque',                'Estoque',                'Manutenção',    'fas fa-boxes',           'Gestão de estoque de materiais e insumos',                    'operador',     55),
('inventario',             'Inventário',             'Manutenção',    'fas fa-clipboard-check', 'Inventário de patrimônio e bens do condomínio',               'operador',     56),
('relatorios_inventario',  'Relatórios Inventário',  'Manutenção',    'fas fa-chart-pie',       'Relatórios de inventário e patrimônio',                       'gerente',      57),

-- GRUPO 6: ADMINISTRATIVO
('administrativa',         'Administrativo',         'Administrativo','fas fa-briefcase',       'Gestão administrativa geral',                                 'gerente',      60),
('contratos',              'Contratos',              'Administrativo','fas fa-file-contract',   'Gestão de contratos com fornecedores e prestadores',          'gerente',      61),
('protocolos',             'Protocolos',             'Administrativo','fas fa-stamp',           'Registro e acompanhamento de protocolos',                     'operador',     62),
('notificacoes',           'Notificações',           'Administrativo','fas fa-bell',            'Envio de notificações para moradores',                        'operador',     63),
('eventos',                'Eventos',                'Administrativo','fas fa-calendar-alt',    'Gestão de eventos e reservas de espaços',                     'operador',     64),

-- GRUPO 7: RECURSOS HUMANOS
('recursos_humanos',       'Recursos Humanos',       'RH',            'fas fa-id-card',         'Gestão de funcionários, escalas e ponto',                     'gerente',      70),

-- GRUPO 8: CRM
('crm',                    'CRM',                    'CRM',           'fas fa-handshake',       'Gestão de relacionamento com clientes e leads',               'gerente',      80),

-- GRUPO 9: MARKETPLACE
('marketplace',            'Marketplace',            'Marketplace',   'fas fa-store',           'Portal de produtos e serviços para moradores',                'operador',     90),
('marketplace_admin',      'Marketplace Admin',      'Marketplace',   'fas fa-store-alt',       'Administração do marketplace e fornecedores',                 'admin',        91),

-- GRUPO 10: CONFIGURAÇÕES E SISTEMA
('configuracao',           'Configurações',          'Sistema',       'fas fa-cog',             'Configurações gerais do sistema',                             'admin',        100),
('dispositivos',           'Dispositivos',           'Sistema',       'fas fa-microchip',       'Gestão de dispositivos de acesso (RFID, câmeras)',             'admin',        101),
('seguranca',              'Segurança',              'Sistema',       'fas fa-shield-alt',      'Configurações de segurança e auditoria',                      'admin',        102),
('sistema',                'Sistema',                'Sistema',       'fas fa-server',          'Informações e configurações do servidor',                     'admin',        103),
('usuarios',               'Usuários',               'Sistema',       'fas fa-user-cog',        'Gerenciamento de usuários e permissões',                      'admin',        104),
('empresa',                'Empresa',                'Sistema',       'fas fa-building',        'Dados cadastrais da empresa/associação',                      'admin',        105),
('meu_perfil',             'Meu Perfil',             'Sistema',       'fas fa-user-circle',     'Edição do perfil do usuário logado',                          'visualizador', 106)

ON DUPLICATE KEY UPDATE
    `nome`             = VALUES(`nome`),
    `grupo`            = VALUES(`grupo`),
    `icone`            = VALUES(`icone`),
    `descricao`        = VALUES(`descricao`),
    `permissao_minima` = VALUES(`permissao_minima`),
    `ordem`            = VALUES(`ordem`);
