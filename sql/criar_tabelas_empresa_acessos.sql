-- =====================================================
-- TABELAS PARA MÓDULO DE EMPRESA E LOCAL DE ACESSOS
-- =====================================================

-- Tabela de Dados da Empresa
CREATE TABLE IF NOT EXISTS `empresa` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cnpj` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE COMMENT 'CNPJ da empresa',
  `razao_social` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Razão social da empresa',
  `nome_fantasia` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nome fantasia',
  `endereco_rua` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Rua/Avenida',
  `endereco_numero` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Número',
  `endereco_complemento` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Complemento',
  `endereco_bairro` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Bairro',
  `endereco_cidade` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Cidade',
  `endereco_estado` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Estado (UF)',
  `endereco_cep` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'CEP',
  `email_principal` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'E-mail principal',
  `email_cobranca` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'E-mail para cobrança',
  `telefone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Telefone',
  `logo_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL da logo (caminho relativo)',
  `logo_nome_arquivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nome do arquivo da logo',
  `situacao` enum('ativo','inativo') COLLATE utf8mb4_unicode_ci DEFAULT 'ativo' COMMENT 'Situação da empresa',
  `data_criacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação',
  `data_atualizacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Data de última atualização',
  `usuario_criacao_id` int(11) DEFAULT NULL COMMENT 'ID do usuário que criou',
  `usuario_atualizacao_id` int(11) DEFAULT NULL COMMENT 'ID do usuário que atualizou',
  PRIMARY KEY (`id`),
  UNIQUE KEY `cnpj_unique` (`cnpj`),
  KEY `situacao_idx` (`situacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dados da empresa/condomínio';

-- Tabela de Local de Acessos
CREATE TABLE IF NOT EXISTS `local_acessos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nome do local de acesso',
  `descricao` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Descrição do local',
  `observacao` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Observações',
  `situacao` enum('ativo','inativo') COLLATE utf8mb4_unicode_ci DEFAULT 'ativo' COMMENT 'Situação do local',
  `data_criacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação',
  `data_atualizacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Data de última atualização',
  `usuario_criacao_id` int(11) DEFAULT NULL COMMENT 'ID do usuário que criou',
  `usuario_atualizacao_id` int(11) DEFAULT NULL COMMENT 'ID do usuário que atualizou',
  PRIMARY KEY (`id`),
  KEY `situacao_idx` (`situacao`),
  KEY `nome_idx` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Local de acessos para moradores, visitantes e dependentes';

-- Tabela de Associação entre Local de Acesso e Tipos de Usuário
CREATE TABLE IF NOT EXISTS `local_acessos_tipos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `local_acesso_id` int(11) NOT NULL COMMENT 'ID do local de acesso',
  `tipo_usuario` enum('morador','visitante','dependente') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tipo de usuário',
  `permissao_acesso` tinyint(1) DEFAULT 1 COMMENT 'Permissão de acesso',
  `data_criacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `local_tipo_unique` (`local_acesso_id`, `tipo_usuario`),
  FOREIGN KEY (`local_acesso_id`) REFERENCES `local_acessos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tipos de usuário permitidos em cada local de acesso';

-- Tabela de Log de Alterações de Empresa
CREATE TABLE IF NOT EXISTS `empresa_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL COMMENT 'ID da empresa',
  `acao` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Ação realizada (criar, atualizar, deletar)',
  `dados_anteriores` json DEFAULT NULL COMMENT 'Dados antes da alteração',
  `dados_novos` json DEFAULT NULL COMMENT 'Dados após a alteração',
  `usuario_id` int(11) DEFAULT NULL COMMENT 'ID do usuário que realizou a ação',
  `data_acao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data da ação',
  `ip_usuario` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP do usuário',
  PRIMARY KEY (`id`),
  KEY `empresa_id_idx` (`empresa_id`),
  KEY `data_acao_idx` (`data_acao`),
  FOREIGN KEY (`empresa_id`) REFERENCES `empresa`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de alterações na tabela de empresa';

-- Tabela de Log de Alterações de Local de Acessos
CREATE TABLE IF NOT EXISTS `local_acessos_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `local_acesso_id` int(11) NOT NULL COMMENT 'ID do local de acesso',
  `acao` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Ação realizada (criar, atualizar, deletar)',
  `dados_anteriores` json DEFAULT NULL COMMENT 'Dados antes da alteração',
  `dados_novos` json DEFAULT NULL COMMENT 'Dados após a alteração',
  `usuario_id` int(11) DEFAULT NULL COMMENT 'ID do usuário que realizou a ação',
  `data_acao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data da ação',
  `ip_usuario` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP do usuário',
  PRIMARY KEY (`id`),
  KEY `local_acesso_id_idx` (`local_acesso_id`),
  KEY `data_acao_idx` (`data_acao`),
  FOREIGN KEY (`local_acesso_id`) REFERENCES `local_acessos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de alterações na tabela de local de acessos';

-- =====================================================
-- INSERÇÃO DE DADOS INICIAIS
-- =====================================================

-- Inserir dados iniciais da empresa (Serra da Liberdade)
INSERT INTO `empresa` (
  `cnpj`, `razao_social`, `nome_fantasia`, 
  `endereco_rua`, `endereco_numero`, `endereco_bairro`, 
  `endereco_cidade`, `endereco_estado`, `endereco_cep`,
  `email_principal`, `email_cobranca`, `situacao`
) VALUES (
  '00.000.000/0000-00', 'ASSOCIAÇÃO SERRA DA LIBERDADE', 'Serra da Liberdade',
  'Rua Serra da Liberdade', '1', 'Centro',
  'Serra da Liberdade', 'MG', '00000-000',
  'contato@serradalberdade.com.br', 'cobranca@serradalberdade.com.br', 'ativo'
) ON DUPLICATE KEY UPDATE `razao_social` = VALUES(`razao_social`);

-- Inserir locais de acesso iniciais
INSERT INTO `local_acessos` (`nome`, `descricao`, `observacao`, `situacao`) VALUES
('Portaria Principal', 'Acesso pela portaria principal do condomínio', 'Entrada/Saída principal', 'ativo'),
('Garagem', 'Acesso à garagem do condomínio', 'Acesso de veículos', 'ativo'),
('Piscina', 'Acesso à área de piscina', 'Área de lazer', 'ativo'),
('Salão de Festas', 'Acesso ao salão de festas', 'Área de eventos', 'ativo'),
('Academia', 'Acesso à academia', 'Área de lazer', 'ativo'),
('Playground', 'Acesso ao playground', 'Área infantil', 'ativo'),
('Churrasqueira', 'Acesso à churrasqueira', 'Área de lazer', 'ativo'),
('Sauna', 'Acesso à sauna', 'Área de lazer', 'ativo')
ON DUPLICATE KEY UPDATE `nome` = VALUES(`nome`);
