-- =====================================================
-- SISTEMA DE CONTROLE DE ACESSO - MÓDULO DE USUÁRIOS
-- Tabela: usuarios
-- =====================================================

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `funcao` varchar(100) NOT NULL,
  `departamento` varchar(100) DEFAULT NULL,
  `permissao` enum('admin','gerente','operador','visualizador') NOT NULL DEFAULT 'operador',
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INSERIR USUÁRIOS PADRÃO
-- Senha padrão: admin123
-- =====================================================

INSERT INTO `usuarios` (`id`, `nome`, `email`, `senha`, `funcao`, `departamento`, `permissao`, `ativo`) VALUES
(1, 'Administrador', 'admin@serraliberdade.com.br', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador do Sistema', 'TI', 'admin', 1),
(2, 'João Silva', 'joao@serraliberdade.com.br', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Porteiro', 'Portaria', 'operador', 1),
(3, 'Maria Santos', 'maria@serraliberdade.com.br', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Gerente', 'Administração', 'gerente', 1);

