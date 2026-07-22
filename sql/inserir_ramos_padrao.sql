-- =====================================================
-- Script para Inserir Ramos de Atividade Padrão
-- Sistema ERP Serra da Liberdade - Marketplace
-- =====================================================

-- Limpar ramos existentes (opcional - comentar se não quiser limpar)
-- TRUNCATE TABLE ramos_atividade;

-- Inserir ramos de atividade mais comuns
INSERT INTO ramos_atividade (nome, descricao, icone, ativo) VALUES
-- Construção e Reformas
('Pedreiro', 'Serviços de alvenaria, construção e reformas', 'fa-hard-hat', 1),
('Pintor', 'Pintura residencial e comercial', 'fa-paint-roller', 1),
('Eletricista', 'Instalações e manutenção elétrica', 'fa-bolt', 1),
('Encanador', 'Instalações hidráulicas e reparos', 'fa-wrench', 1),
('Marceneiro', 'Móveis planejados e marcenaria em geral', 'fa-hammer', 1),
('Serralheiro', 'Portões, grades e estruturas metálicas', 'fa-tools', 1),
('Vidraceiro', 'Instalação de vidros e espelhos', 'fa-window-maximize', 1),
('Gesseiro', 'Forros e divisórias em gesso', 'fa-layer-group', 1),

-- Limpeza e Conservação
('Limpeza Residencial', 'Limpeza de casas e apartamentos', 'fa-broom', 1),
('Limpeza Pós-Obra', 'Limpeza após reformas e construções', 'fa-hard-hat', 1),
('Jardinagem', 'Manutenção de jardins e áreas verdes', 'fa-leaf', 1),
('Dedetização', 'Controle de pragas e dedetização', 'fa-bug', 1),
('Lavagem de Estofados', 'Limpeza de sofás, colchões e tapetes', 'fa-couch', 1),

-- Manutenção e Reparos
('Técnico em Refrigeração', 'Manutenção de ar-condicionado e geladeiras', 'fa-snowflake', 1),
('Técnico em Informática', 'Manutenção de computadores e redes', 'fa-laptop', 1),
('Chaveiro', 'Cópias de chaves e abertura de portas', 'fa-key', 1),
('Desentupidor', 'Desentupimento de ralos e esgotos', 'fa-toilet', 1),

-- Beleza e Estética
('Cabeleireiro', 'Corte e tratamento capilar', 'fa-cut', 1),
('Manicure/Pedicure', 'Cuidados com unhas', 'fa-hand-sparkles', 1),
('Esteticista', 'Tratamentos estéticos e faciais', 'fa-spa', 1),
('Barbeiro', 'Corte de cabelo e barba masculina', 'fa-user-tie', 1),

-- Alimentação
('Buffet', 'Serviços de buffet para eventos', 'fa-utensils', 1),
('Confeitaria', 'Bolos, doces e salgados', 'fa-birthday-cake', 1),
('Marmitex', 'Entrega de marmitas', 'fa-box', 1),
('Chef Particular', 'Serviços de chef em domicílio', 'fa-hat-chef', 1),

-- Serviços Domésticos
('Diarista', 'Serviços de limpeza por dia', 'fa-home', 1),
('Passadeira', 'Serviços de passar roupas', 'fa-tshirt', 1),
('Cozinheira', 'Preparo de refeições', 'fa-utensils', 1),
('Babá', 'Cuidados com crianças', 'fa-baby', 1),
('Cuidador de Idosos', 'Acompanhamento e cuidados com idosos', 'fa-user-nurse', 1),

-- Transporte e Mudanças
('Frete e Mudanças', 'Transporte de móveis e mudanças', 'fa-truck', 1),
('Motorista Particular', 'Serviços de motorista', 'fa-car', 1),

-- Educação e Aulas
('Professor Particular', 'Aulas particulares diversas', 'fa-chalkboard-teacher', 1),
('Personal Trainer', 'Treinamento físico personalizado', 'fa-dumbbell', 1),
('Instrutor de Música', 'Aulas de instrumentos musicais', 'fa-music', 1),

-- Tecnologia
('Instalador de Antenas', 'Instalação de antenas e parabólicas', 'fa-satellite-dish', 1),
('Instalador de Som', 'Instalação de sistemas de som', 'fa-volume-up', 1),
('Técnico de Celular', 'Manutenção de smartphones', 'fa-mobile-alt', 1),

-- Pets
('Pet Shop', 'Produtos e serviços para animais', 'fa-paw', 1),
('Veterinário', 'Atendimento veterinário', 'fa-stethoscope', 1),
('Adestrador', 'Adestramento de cães', 'fa-dog', 1),
('Pet Sitter', 'Cuidados com animais', 'fa-cat', 1),

-- Automotivo
('Mecânico', 'Manutenção automotiva', 'fa-car-mechanic', 1),
('Lavagem de Carros', 'Lavagem e polimento', 'fa-car-wash', 1),
('Funilaria e Pintura', 'Reparos de lataria e pintura', 'fa-spray-can', 1),

-- Outros Serviços
('Costureira', 'Costura e ajustes de roupas', 'fa-tshirt', 1),
('Fotógrafo', 'Serviços fotográficos', 'fa-camera', 1),
('Decorador', 'Decoração de ambientes e eventos', 'fa-palette', 1),
('Organizador de Eventos', 'Planejamento e organização de eventos', 'fa-calendar-check', 1)

ON DUPLICATE KEY UPDATE 
    descricao = VALUES(descricao),
    icone = VALUES(icone),
    ativo = VALUES(ativo);

-- Verificar quantos ramos foram inseridos
SELECT COUNT(*) as total_ramos FROM ramos_atividade WHERE ativo = 1;

-- Listar todos os ramos ativos
SELECT id, nome, icone, ativo FROM ramos_atividade WHERE ativo = 1 ORDER BY nome;
