-- ============================================================
-- SEED: Manual do Sistema ERP Condomínios ERP Condomínio
-- Estrutura correta: manual_modulos usa page_id (sem cor)
--                    manual_artigos usa criado_por (sem autor_id)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. MÓDULOS (INSERT IGNORE — não duplica se já existirem)
INSERT IGNORE INTO manual_modulos (id, nome, icone, page_id, ordem) VALUES
  (1, 'Dashboard', 'fas fa-chart-line', 'dashboard', 1),
  (2, 'Moradores', 'fas fa-users', 'moradores', 2),
  (3, 'Veículos', 'fas fa-car', 'veiculos', 3),
  (4, 'Visitantes', 'fas fa-user-friends', 'visitantes', 4),
  (5, 'Controle de Acesso', 'fas fa-door-open', 'acesso', 5),
  (6, 'Registro Manual', 'fas fa-clipboard-list', 'registro', 6),
  (7, 'Hidrômetros', 'fas fa-tint', 'hidrometro', 7),
  (8, 'Financeiro', 'fas fa-money-bill-wave', 'financeiro', 8),
  (9, 'RH', 'fas fa-id-badge', 'rh', 9),
  (10, 'Ordens de Serviço', 'fas fa-wrench', 'ordens_servico', 10),
  (11, 'Projetos', 'fas fa-project-diagram', 'projetos', 11),
  (12, 'Marketplace', 'fas fa-store', 'marketplace', 12),
  (13, 'GED', 'fas fa-folder-open', 'documentos', 13),
  (14, 'Relatórios', 'fas fa-chart-bar', 'relatorios', 14),
  (15, 'Configurações', 'fas fa-cog', 'configuracao', 15);

-- 2. CATEGORIAS
INSERT IGNORE INTO manual_categorias (id, modulo_id, nome, ordem) VALUES
  (1, 1, 'Visão Geral', 1),
  (2, 2, 'Gestão de Moradores', 1),
  (3, 2, 'Dependentes', 2),
  (4, 3, 'Controle de Veículos', 1),
  (5, 4, 'Registro de Visitantes', 1),
  (6, 5, 'Dispositivos e Integração', 1),
  (7, 5, 'Logs de Acesso', 2),
  (8, 6, 'Registro Manual', 1),
  (9, 7, 'Leituras e Consumo', 1),
  (10, 8, 'Contas a Pagar e Receber', 1),
  (11, 8, 'Conciliação Bancária', 2),
  (12, 8, 'Planos de Contas', 3),
  (13, 9, 'Colaboradores', 1),
  (14, 9, 'Ponto e Banco de Horas', 2),
  (15, 10, 'Ordens de Serviço', 1),
  (16, 11, 'Projetos Públicos', 1),
  (17, 13, 'Organização de Documentos', 1),
  (18, 13, 'Compartilhamento', 2),
  (19, 14, 'Relatórios Gerenciais', 1),
  (20, 15, 'Configurações Gerais', 1),
  (21, 15, 'Usuários e Permissões', 2),
  (22, 15, 'Notificações Push (FCM)', 3);

-- 3. ARTIGOS (remove seed anterior e reinserere)
DELETE FROM manual_artigos WHERE criado_por = 1;

INSERT INTO manual_artigos (modulo_id, categoria_id, titulo, resumo, conteudo, tags, status, versao, criado_por) VALUES
  (1, 1, 'Visão Geral do Dashboard Principal', 'Entenda os indicadores de desempenho, consumo de água, saldo de abastecimento e status dos chamados exibidos na tela inicial.', '<h2>Dashboard Principal</h2>
<p>O Dashboard é a primeira tela exibida após o login e oferece uma visão consolidada e em tempo real da situação do condomínio. Todos os dados são atualizados automaticamente a cada carregamento da página.</p>
<h3>Indicadores Principais (KPIs)</h3>
<p>A faixa superior exibe quatro indicadores de destaque:</p>
<ul>
  <li><strong>Total de Moradores:</strong> Contagem de moradores com status ativo no banco de dados.</li>
  <li><strong>Consumo Total de Água:</strong> Soma de todas as leituras de hidrômetro do mês vigente, com a média de consumo por morador calculada automaticamente.</li>
  <li><strong>Valor Total de Água:</strong> Custo financeiro total do consumo de água no mês, baseado na tarifa configurada.</li>
  <li><strong>Saldo de Abastecimento:</strong> Valor monetário disponível no fundo de combustível do condomínio. Exibe alerta visual quando o saldo está baixo.</li>
</ul>
<h3>Painel de Ordens de Serviço</h3>
<p>O painel de chamados exibe os contadores por status, atualizados em tempo real:</p>
<ul>
  <li><strong>Abertos:</strong> Chamados novos que ainda não foram atribuídos a nenhum responsável.</li>
  <li><strong>Em Andamento:</strong> Chamados que já possuem um responsável e estão sendo executados.</li>
  <li><strong>Urgentes:</strong> Chamados marcados com prioridade alta ou urgente.</li>
  <li><strong>Prazo Vencido:</strong> Chamados cuja data limite de resolução já passou sem ser finalizado.</li>
</ul>
<div class="callout callout-info"><i class="fas fa-info-circle"></i> <strong>Dica:</strong> Clique em qualquer card de KPI para ser redirecionado para o módulo correspondente com os dados completos.</div>', 'dashboard, kpi, indicadores, inicio, resumo', 'publicado', '1.0', 1),
  (2, 2, 'Cadastro e Gestão de Moradores', 'Como adicionar, editar, inativar e filtrar moradores. Regras de CPF único e vínculo com unidades.', '<h2>Gestão de Moradores</h2>
<p>O módulo de Moradores é o cadastro central de todas as pessoas que residem no condomínio. Ele serve como base para o controle de acesso, financeiro, notificações e o portal do morador.</p>
<h3>Como Cadastrar um Novo Morador</h3>
<ol>
  <li>Acesse o menu <strong>Moradores</strong> na barra lateral.</li>
  <li>Clique no botão <strong>+ Novo Morador</strong>.</li>
  <li>Preencha os campos obrigatórios: <strong>Nome Completo</strong>, <strong>CPF</strong> e <strong>Unidade</strong>.</li>
  <li>Opcionalmente, preencha: Email, Senha (para acesso ao app), Telefone e Celular.</li>
  <li>Clique em <strong>Salvar</strong>.</li>
</ol>
<h3>Regras de Negócio</h3>
<ul>
  <li><strong>CPF Único:</strong> O sistema valida se o CPF já está cadastrado. Se existir, o cadastro é bloqueado e um erro é exibido.</li>
  <li><strong>Vínculo Obrigatório com Unidade:</strong> Todo morador deve estar associado a uma unidade existente no sistema.</li>
  <li><strong>Inativação (Soft Delete):</strong> Ao excluir um morador, ele não é removido do banco de dados. O campo <code>ativo</code> é alterado para <code>0</code>. Isso preserva o histórico de acessos, financeiro e logs vinculados a ele.</li>
  <li><strong>Senha do App:</strong> A senha cadastrada aqui é usada pelo morador para acessar o Portal PWA. Ela é armazenada com hash seguro.</li>
</ul>
<div class="callout callout-warning"><i class="fas fa-exclamation-triangle"></i> <strong>Atenção:</strong> Ao inativar um morador, ele perderá acesso ao aplicativo imediatamente.</div>', 'morador, cadastro, cpf, inativar, unidade, editar', 'publicado', '1.0', 1),
  (2, 3, 'Cadastro de Dependentes', 'Como adicionar filhos, cônjuges e outros dependentes vinculados a um morador.', '<h2>Dependentes de Moradores</h2>
<p>O sistema permite cadastrar dependentes vinculados a um morador principal. Isso é útil para controle de acesso de crianças, cônjuges e outros familiares que residem na mesma unidade.</p>
<h3>Como Adicionar um Dependente</h3>
<ol>
  <li>Acesse o cadastro do morador principal.</li>
  <li>Clique na aba ou botão <strong>Dependentes</strong>.</li>
  <li>Preencha o nome, parentesco, data de nascimento e documento do dependente.</li>
  <li>Salve o registro.</li>
</ol>
<h3>Regras</h3>
<ul>
  <li>Dependentes são vinculados ao morador principal e herdam a unidade dele.</li>
  <li>Dependentes podem ter acesso ao condomínio registrado separadamente no controle de acesso.</li>
  <li>Menores de idade não possuem acesso ao portal do morador.</li>
</ul>', 'dependente, filho, conjuge, morador, cadastro', 'publicado', '1.0', 1),
  (3, 4, 'Cadastro e Controle de Veículos', 'Como registrar veículos dos moradores, vincular placas ao controle de acesso e gerenciar autorizações.', '<h2>Módulo de Veículos</h2>
<p>O módulo de Veículos permite o cadastro de todos os automóveis dos moradores e a integração com o sistema de controle de acesso da portaria.</p>
<h3>Cadastro de Veículo</h3>
<ol>
  <li>Acesse o menu <strong>Veículos</strong>.</li>
  <li>Clique em <strong>+ Novo Veículo</strong>.</li>
  <li>Preencha: Placa, Modelo, Marca, Cor, Tipo (Carro, Moto, Caminhão, Van).</li>
  <li>Vincule o veículo a um morador existente.</li>
  <li>Salve.</li>
</ol>
<h3>Integração com Controle de Acesso</h3>
<p>Após o cadastro, o veículo pode ser autorizado para entrada automática de duas formas:</p>
<ul>
  <li><strong>RFID/Tag:</strong> Um tag RFID é associado ao veículo. Quando o veículo passa pela cancela, o leitor identifica a tag e libera automaticamente.</li>
  <li><strong>QR Code:</strong> Um QR Code único é gerado para o veículo e pode ser impresso ou salvo no celular do morador.</li>
</ul>
<div class="callout callout-info"><i class="fas fa-info-circle"></i> A placa é validada para evitar duplicatas no sistema.</div>', 'veiculo, placa, carro, moto, acesso, rfid, qrcode', 'publicado', '1.0', 1),
  (4, 5, 'Registro e Controle de Visitantes', 'Procedimento completo para registrar visitantes na portaria, capturar foto e documento, e registrar entrada e saída.', '<h2>Controle de Visitantes</h2>
<p>O módulo de Visitantes é utilizado pela portaria para registrar e controlar a entrada de pessoas externas ao condomínio.</p>
<h3>Registrando um Novo Visitante</h3>
<ol>
  <li>Acesse o menu <strong>Visitantes</strong>.</li>
  <li>Clique em <strong>+ Novo Visitante</strong>.</li>
  <li>Selecione o <strong>Tipo de Documento</strong>: CPF ou RG.</li>
  <li>Digite o número do documento. O sistema busca automaticamente se o visitante já tem cadastro anterior.</li>
  <li>Se for um visitante novo, preencha o <strong>Nome Completo</strong>.</li>
  <li>Capture a <strong>foto do visitante</strong> usando a webcam da portaria.</li>
  <li>Opcionalmente, capture a foto do documento de identificação.</li>
  <li>Informe a <strong>Unidade/Morador</strong> que está sendo visitado.</li>
  <li>Clique em <strong>Registrar Entrada</strong>.</li>
</ol>
<h3>Registrando a Saída</h3>
<p>Ao final da visita, localize o visitante na lista de "Em Visita" e clique em <strong>Registrar Saída</strong>. O sistema registra automaticamente a data e hora da saída.</p>
<div class="callout callout-warning"><i class="fas fa-exclamation-triangle"></i> <strong>Atenção:</strong> Nunca libere a entrada de um visitante sem registrar no sistema. O histórico de visitas é importante para a segurança do condomínio.</div>', 'visitante, portaria, acesso, foto, documento, rg, cpf, entrada, saida', 'publicado', '1.0', 1),
  (5, 6, 'Integração com Dispositivos de Acesso (ControlID)', 'Como configurar catracas, leitores RFID e câmeras de reconhecimento facial integradas ao sistema.', '<h2>Controle de Acesso Automatizado</h2>
<p>O ERP integra-se com dispositivos físicos de controle de acesso da marca ControlID, permitindo a liberação automática de moradores e veículos cadastrados.</p>
<h3>Tipos de Dispositivos Suportados</h3>
<ul>
  <li><strong>Leitores RFID:</strong> Leem tags/cartões de proximidade. O morador ou veículo apresenta o cartão/tag e a cancela é liberada automaticamente.</li>
  <li><strong>Reconhecimento Facial (Face ID):</strong> A câmera identifica o rosto do morador cadastrado e libera o acesso sem necessidade de cartão.</li>
  <li><strong>QR Code:</strong> O morador apresenta o QR Code gerado pelo app para liberar a catraca ou cancela.</li>
</ul>
<h3>Configuração de Dispositivos</h3>
<ol>
  <li>Acesse <strong>Controle de Acesso > Dispositivos</strong>.</li>
  <li>Clique em <strong>+ Novo Dispositivo</strong>.</li>
  <li>Informe o IP do dispositivo na rede local do condomínio.</li>
  <li>Defina o tipo (entrada/saída) e o local (Portaria Principal, Garagem, etc.).</li>
  <li>Teste a conexão clicando em <strong>Verificar Conectividade</strong>.</li>
</ol>
<div class="callout callout-info"><i class="fas fa-info-circle"></i> O sistema mantém um log completo de todos os acessos, incluindo data, hora, dispositivo utilizado e identificação do morador ou visitante.</div>', 'controle de acesso, controlid, rfid, face id, catraca, dispositivo', 'publicado', '1.0', 1),
  (5, 7, 'Logs e Histórico de Acessos', 'Como consultar o histórico de entradas e saídas, filtrar por data, morador ou dispositivo.', '<h2>Logs de Acesso</h2>
<p>O sistema registra automaticamente todos os eventos de acesso, criando um histórico auditável e completo.</p>
<h3>Consultando o Histórico</h3>
<ol>
  <li>Acesse <strong>Controle de Acesso > Logs</strong>.</li>
  <li>Use os filtros disponíveis: Período (data início/fim), Morador, Dispositivo, Tipo (entrada/saída).</li>
  <li>Clique em <strong>Buscar</strong>.</li>
</ol>
<h3>Informações Registradas em Cada Log</h3>
<ul>
  <li>Data e hora exata do evento.</li>
  <li>Identificação do morador, visitante ou veículo.</li>
  <li>Dispositivo utilizado (nome e localização).</li>
  <li>Método de acesso (RFID, Face ID, QR Code, Manual).</li>
  <li>Tipo do evento (Entrada ou Saída).</li>
</ul>
<h3>Exportação</h3>
<p>Os logs podem ser exportados em PDF para relatórios de auditoria. Acesse <strong>Relatórios > Acessos</strong> para gerar o relatório com filtros personalizados.</p>', 'log, historico, acesso, entrada, saida, auditoria', 'publicado', '1.0', 1),
  (6, 8, 'Registro Manual de Acesso', 'Como registrar manualmente entradas e saídas quando os dispositivos automáticos não estão disponíveis.', '<h2>Registro Manual de Acesso</h2>
<p>O módulo de Registro Manual permite que o porteiro registre entradas e saídas de forma manual, sem depender de dispositivos automáticos. É útil em situações de falha de energia, manutenção dos equipamentos ou para visitantes sem cadastro prévio.</p>
<h3>Realizando um Registro Manual</h3>
<ol>
  <li>Acesse o menu <strong>Registro Manual</strong>.</li>
  <li>Selecione o tipo: Morador, Visitante ou Veículo.</li>
  <li>Busque o cadastro pelo nome ou CPF.</li>
  <li>Selecione o tipo de evento: Entrada ou Saída.</li>
  <li>Confirme o registro.</li>
</ol>
<p>O registro manual fica marcado no histórico com a indicação de que foi feito manualmente, diferenciando-o dos registros automáticos.</p>
<div class="callout callout-warning"><i class="fas fa-exclamation-triangle"></i> Use o registro manual apenas quando necessário. Prefira sempre os dispositivos automáticos para maior confiabilidade e rastreabilidade.</div>', 'registro manual, entrada, saida, portaria, manual', 'publicado', '1.0', 1),
  (7, 9, 'Hidrômetros: Leituras e Consumo de Água', 'Como registrar leituras mensais, calcular consumo por unidade e gerar relatórios de água.', '<h2>Gestão de Hidrômetros</h2>
<p>O módulo de Hidrômetros controla o consumo de água de cada unidade do condomínio através de leituras mensais.</p>
<h3>Cadastro de Hidrômetros</h3>
<ol>
  <li>Acesse <strong>Hidrômetros</strong> no menu lateral.</li>
  <li>Cada unidade deve ter um hidrômetro cadastrado com número de série e localização.</li>
  <li>Defina a leitura inicial (leitura de instalação do medidor).</li>
</ol>
<h3>Registro de Leitura Mensal</h3>
<ol>
  <li>Acesse <strong>Hidrômetros > Nova Leitura</strong>.</li>
  <li>Selecione o hidrômetro e informe a leitura atual do medidor (em m³).</li>
  <li>O sistema calcula automaticamente o consumo do período (leitura atual - leitura anterior).</li>
  <li>Opcionalmente, anexe uma foto do hidrômetro como comprovante.</li>
</ol>
<h3>Cálculo do Valor</h3>
<p>O valor a cobrar é calculado com base na tarifa configurada em <strong>Configurações > Período de Leitura</strong>. A tarifa pode ser fixa por m³ ou escalonada (quanto mais consome, maior o preço por m³).</p>
<h3>Relatórios</h3>
<p>O sistema gera relatórios comparativos de consumo por unidade, por período e o demonstrativo geral do condomínio para prestação de contas em assembleia.</p>', 'hidrometro, agua, leitura, consumo, unidade, relatorio, tarifa', 'publicado', '1.0', 1),
  (8, 10, 'Contas a Pagar: Cadastro e Baixa', 'Como registrar despesas, realizar pagamentos parciais ou totais e acompanhar o fluxo de caixa.', '<h2>Contas a Pagar</h2>
<p>O módulo de Contas a Pagar controla todas as despesas do condomínio, desde fornecedores até folha de pagamento.</p>
<h3>Cadastrando uma Nova Conta</h3>
<ol>
  <li>Acesse <strong>Financeiro > Contas a Pagar</strong>.</li>
  <li>Clique em <strong>+ Nova Conta</strong>.</li>
  <li>Preencha: Descrição, Fornecedor/Beneficiário, Valor Total, Data de Emissão, Data de Vencimento.</li>
  <li>Selecione o Plano de Contas (categoria) para classificação contábil.</li>
  <li>Salve. A conta será criada com status <strong>PENDENTE</strong>.</li>
</ol>
<h3>Realizando a Baixa (Pagamento)</h3>
<ol>
  <li>Localize a conta na listagem e clique em <strong>Pagar</strong>.</li>
  <li>Informe o valor pago, a data do pagamento e a forma de pagamento (Dinheiro, Boleto, PIX, Transferência, etc.).</li>
  <li>Clique em <strong>Confirmar Pagamento</strong>.</li>
</ol>
<h3>Lógica de Status Automático</h3>
<ul>
  <li>Valor pago = Valor total → Status muda para <strong>PAGO</strong>.</li>
  <li>Valor pago &lt; Valor total → Status muda para <strong>PARCIAL</strong>. O sistema calcula e exibe o saldo devedor restante.</li>
  <li>Conta cancelada manualmente → Status muda para <strong>CANCELADO</strong> e não entra nos cálculos de fluxo de caixa.</li>
</ul>
<div class="callout callout-warning"><i class="fas fa-exclamation-triangle"></i> Contas vencidas são destacadas em vermelho na listagem.</div>', 'contas a pagar, despesa, pagamento, parcial, pendente, pago, fluxo de caixa', 'publicado', '1.0', 1),
  (8, 10, 'Contas a Receber e Controle de Inadimplência', 'Como registrar receitas, taxas condominiais e cobranças, e acompanhar inadimplência.', '<h2>Contas a Receber</h2>
<p>O módulo de Contas a Receber controla todas as receitas do condomínio, incluindo taxas condominiais, multas e outras cobranças.</p>
<h3>Lançando uma Nova Receita</h3>
<ol>
  <li>Acesse <strong>Financeiro > Contas a Receber</strong>.</li>
  <li>Clique em <strong>+ Nova Conta</strong>.</li>
  <li>Preencha: Descrição, Devedor (morador ou externo), Valor, Vencimento.</li>
  <li>Selecione o Plano de Contas correspondente.</li>
  <li>Salve. O status inicial é <strong>PENDENTE</strong>.</li>
</ol>
<h3>Controle de Inadimplência</h3>
<p>O sistema identifica automaticamente contas vencidas e não pagas. O Dashboard exibe o indicador de inadimplência. Além disso:</p>
<ul>
  <li>O sistema pode enviar notificações push automáticas para moradores inadimplentes (configure em <strong>Notificações > Regras</strong>).</li>
  <li>O relatório de inadimplência lista todos os moradores com contas em atraso, com valor e dias de atraso.</li>
</ul>', 'contas a receber, receita, taxa, boleto, inadimplencia, cobranca', 'publicado', '1.0', 1),
  (8, 11, 'Conciliação Bancária', 'Como reconciliar os lançamentos do sistema com o extrato bancário real.', '<h2>Conciliação Bancária</h2>
<p>A conciliação bancária é o processo de verificar se os lançamentos registrados no sistema correspondem exatamente ao extrato da conta bancária do condomínio.</p>
<h3>Processo de Conciliação</h3>
<ol>
  <li>Acesse <strong>Financeiro > Conciliação Bancária</strong>.</li>
  <li>Selecione a conta bancária e o período.</li>
  <li>O sistema exibe os lançamentos do ERP lado a lado com as movimentações bancárias importadas.</li>
  <li>Para cada lançamento, clique em <strong>Conciliar</strong> para marcar como conferido.</li>
  <li>Lançamentos sem correspondência são destacados para análise.</li>
</ol>
<h3>Importação de Extrato</h3>
<p>O extrato bancário pode ser importado no formato OFX (padrão da maioria dos bancos brasileiros). Após a importação, o sistema tenta fazer a correspondência automática entre os lançamentos.</p>
<div class="callout callout-info"><i class="fas fa-info-circle"></i> Realize a conciliação mensalmente para garantir a integridade dos dados financeiros do condomínio.</div>', 'conciliacao, bancario, extrato, banco, movimentacao, ofx', 'publicado', '1.0', 1),
  (8, 12, 'Planos de Contas', 'Como criar e organizar categorias financeiras para classificação de receitas e despesas.', '<h2>Planos de Contas</h2>
<p>O Plano de Contas é a estrutura de categorias usada para classificar todas as receitas e despesas do condomínio, permitindo a geração de relatórios gerenciais precisos.</p>
<h3>Estrutura Hierárquica</h3>
<p>O plano de contas é organizado em grupos e subgrupos. Exemplo:</p>
<ul>
  <li><strong>Receitas:</strong> Taxa Condominial, Multas e Juros, Aluguel de Espaços.</li>
  <li><strong>Despesas:</strong> Manutenção, Salários e Encargos, Água e Energia, Serviços Terceirizados.</li>
</ul>
<h3>Criando uma Nova Categoria</h3>
<ol>
  <li>Acesse <strong>Financeiro > Planos de Contas</strong>.</li>
  <li>Clique em <strong>+ Nova Categoria</strong>.</li>
  <li>Defina o nome, tipo (receita/despesa) e, se necessário, a categoria pai.</li>
  <li>Salve.</li>
</ol>', 'plano de contas, categoria, contabilidade, classificacao, financeiro', 'publicado', '1.0', 1),
  (9, 13, 'Cadastro de Colaboradores', 'Como registrar funcionários, tipos de contrato, cargos e departamentos.', '<h2>Cadastro de Colaboradores</h2>
<p>O módulo de Recursos Humanos gerencia os dados de todos os funcionários e colaboradores do condomínio.</p>
<h3>Cadastrando um Colaborador</h3>
<ol>
  <li>Acesse <strong>RH > Colaboradores</strong>.</li>
  <li>Clique em <strong>+ Novo Colaborador</strong>.</li>
  <li>Preencha os dados pessoais: Nome, CPF, RG, Data de Nascimento, Sexo, Estado Civil.</li>
  <li>Preencha os dados profissionais: Cargo, Departamento, Tipo de Contrato, Data de Admissão, Salário.</li>
  <li>Adicione informações de contato: Telefone, Celular, Email.</li>
  <li>Opcionalmente, faça o upload da foto do colaborador.</li>
  <li>Salve.</li>
</ol>
<h3>Tipos de Contrato</h3>
<ul>
  <li><strong>CLT:</strong> Funcionário com carteira assinada.</li>
  <li><strong>PJ:</strong> Prestador de serviço como pessoa jurídica.</li>
  <li><strong>Temporário:</strong> Contrato por prazo determinado.</li>
  <li><strong>Estágio:</strong> Contrato de estágio.</li>
  <li><strong>Terceirizado:</strong> Funcionário de empresa terceirizada.</li>
</ul>
<h3>Desligamento</h3>
<p>Para registrar o desligamento de um colaborador, acesse o cadastro dele e preencha a <strong>Data de Demissão</strong> e o motivo. O colaborador será marcado como inativo, mas seu histórico é preservado.</p>', 'rh, colaborador, funcionario, clt, pj, cargo, departamento, admissao', 'publicado', '1.0', 1),
  (9, 14, 'Ponto Eletrônico e Banco de Horas', 'Como registrar a jornada de trabalho, calcular horas extras e gerenciar abonos.', '<h2>Ponto Eletrônico</h2>
<p>O módulo de Ponto controla a jornada de trabalho dos colaboradores, calculando automaticamente horas trabalhadas, extras e atrasos.</p>
<h3>Registrando o Ponto</h3>
<p>O ponto pode ser registrado de três formas:</p>
<ul>
  <li><strong>Pelo Sistema (Administrador):</strong> O RH registra manualmente a entrada e saída do colaborador.</li>
  <li><strong>Pelo Terminal:</strong> Se houver um terminal de ponto integrado.</li>
  <li><strong>Importação:</strong> Importação de arquivo de ponto em formato padrão.</li>
</ul>
<h3>Cálculo Automático</h3>
<p>O sistema calcula automaticamente:</p>
<ul>
  <li><strong>Horas Trabalhadas:</strong> Diferença entre entrada e saída, descontando o intervalo.</li>
  <li><strong>Horas Extras:</strong> Horas trabalhadas além da jornada diária configurada.</li>
  <li><strong>Atrasos:</strong> Diferença entre a hora de entrada registrada e o horário previsto.</li>
</ul>
<h3>Banco de Horas</h3>
<p>O saldo de horas extras é acumulado no Banco de Horas de cada colaborador. O RH pode registrar a compensação quando o colaborador folga no lugar de receber o pagamento das horas extras.</p>
<h3>Abonos</h3>
<p>Para justificar faltas ou atrasos (ex: atestado médico), acesse <strong>RH > Abonos</strong> e registre o abono com o motivo e o documento comprobatório.</p>', 'ponto, jornada, hora extra, banco de horas, abono, atestado, escala', 'publicado', '1.0', 1),
  (10, 15, 'Ordens de Serviço: Criação e Acompanhamento', 'Ciclo de vida completo de uma O.S., tipos de interação, prioridades e projetos públicos.', '<h2>Ordens de Serviço (O.S.)</h2>
<p>As Ordens de Serviço são o coração do módulo de Manutenção. Elas centralizam todos os chamados, reparos e projetos do condomínio.</p>
<h3>Criando uma Nova O.S.</h3>
<ol>
  <li>Acesse <strong>Manutenção > Ordens de Serviço</strong>.</li>
  <li>Clique em <strong>+ Nova O.S.</strong></li>
  <li>Preencha: Título, Descrição detalhada, Setor/Local, Prioridade.</li>
  <li>Opcionalmente, vincule a um morador solicitante e adicione fotos do problema.</li>
  <li>Salve. A O.S. recebe um número sequencial automático (ex: #O.S-2026-0001).</li>
</ol>
<h3>Ciclo de Vida (Status)</h3>
<ol>
  <li><strong>Aberto:</strong> O.S. criada, aguardando triagem e atribuição.</li>
  <li><strong>Em Andamento:</strong> Responsável atribuído, serviço em execução.</li>
  <li><strong>Finalizado:</strong> Serviço concluído e solução registrada.</li>
  <li><strong>Cancelado:</strong> O.S. encerrada sem execução (duplicada, improcedente, etc.).</li>
</ol>
<h3>Tipos de Interação</h3>
<ul>
  <li><strong>Comentário:</strong> Mensagem geral, visível para o morador se ele abriu o chamado.</li>
  <li><strong>Andamento:</strong> Atualização de progresso com percentual de conclusão (0-100%).</li>
  <li><strong>Nota Interna:</strong> Visível apenas para a administração, oculta do morador.</li>
  <li><strong>Solução:</strong> Descrição final de como o problema foi resolvido.</li>
</ul>
<div class="callout callout-info"><i class="fas fa-info-circle"></i> <strong>Projetos Públicos:</strong> Uma O.S. pode ser marcada como Projeto Público para aparecer no mural do App do Morador como uma melhoria em andamento (ex: Reforma da Piscina).</div>', 'os, ordem de servico, manutencao, chamado, status, prioridade, interacao', 'publicado', '1.0', 1),
  (11, 16, 'Projetos Públicos no App do Morador', 'Como marcar uma O.S. como projeto público e como ela aparece no aplicativo dos moradores.', '<h2>Projetos Públicos</h2>
<p>O módulo de Projetos exibe no App do Morador as obras e melhorias em andamento no condomínio, promovendo transparência na gestão.</p>
<h3>Como Tornar uma O.S. Pública</h3>
<ol>
  <li>Abra a Ordem de Serviço desejada.</li>
  <li>Ative a opção <strong>Projeto Público</strong>.</li>
  <li>Adicione uma descrição resumida para os moradores.</li>
  <li>Opcionalmente, adicione fotos do andamento.</li>
  <li>Salve.</li>
</ol>
<h3>Visualização no App</h3>
<p>Após ser marcada como pública, a O.S. aparece no mural do App do Morador com:</p>
<ul>
  <li>Título e descrição do projeto.</li>
  <li>Status atual (Em Andamento, Finalizado).</li>
  <li>Percentual de conclusão (se informado).</li>
  <li>Fotos do andamento.</li>
</ul>
<div class="callout callout-info"><i class="fas fa-info-circle"></i> Use projetos públicos para comunicar reformas, manutenções programadas e melhorias ao condomínio, aumentando a confiança dos moradores na gestão.</div>', 'projeto, publico, app, morador, transparencia, mural', 'publicado', '1.0', 1),
  (13, 17, 'GED: Organização e Envio de Documentos', 'Como organizar, classificar e enviar arquivos no sistema de Gestão Eletrônica de Documentos.', '<h2>Gestão Eletrônica de Documentos (GED)</h2>
<p>O módulo GED é o repositório digital central do condomínio, funcionando como um Google Drive/OneDrive com características específicas para gestão documental.</p>
<h3>Estrutura de Organização</h3>
<ul>
  <li><strong>Departamentos:</strong> Divisão principal por área (ex: Financeiro, Jurídico, RH, Comunicados).</li>
  <li><strong>Pastas:</strong> Estrutura hierárquica dentro de cada departamento (pastas e subpastas).</li>
  <li><strong>Tipos de Documento:</strong> Classificação padronizada configurável (ex: ATA, Estatuto, Contrato, NF, Regulamento, Comunicado).</li>
</ul>
<h3>Enviando um Documento</h3>
<ol>
  <li>Acesse <strong>Manutenção > Documentos</strong>.</li>
  <li>Clique em <strong>+ Novo Documento</strong>.</li>
  <li>Preencha: Nome, Departamento, Tipo, Pasta de destino.</li>
  <li>Defina a <strong>Visibilidade</strong>: Somente Usuários, Todos os Moradores ou Unidades Específicas.</li>
  <li>Faça o upload do arquivo.</li>
  <li>Salve.</li>
</ol>
<h3>Controle de Visibilidade por Unidade</h3>
<p>Ao selecionar "Unidades Específicas", aparece um seletor com todas as unidades ativas do sistema. Isso é ideal para documentos individuais como notificações de infração, boletos específicos ou comunicados direcionados.</p>', 'ged, documentos, arquivos, pastas, tipo, departamento, visibilidade', 'publicado', '1.0', 1),
  (13, 18, 'Compartilhamento e Rastreabilidade de Documentos', 'Como gerar links públicos com expiração e consultar o histórico de acessos a documentos.', '<h2>Compartilhamento e Rastreabilidade</h2>
<p>O GED oferece recursos avançados de compartilhamento externo e rastreabilidade de acessos.</p>
<h3>Gerando um Link Público</h3>
<ol>
  <li>Localize o documento na listagem.</li>
  <li>Clique em <strong>Compartilhar</strong>.</li>
  <li>Configure as opções do link:
    <ul>
      <li><strong>Data de Expiração:</strong> O link deixa de funcionar após essa data.</li>
      <li><strong>Limite de Acessos:</strong> O link é desativado após um número máximo de visualizações.</li>
    </ul>
  </li>
  <li>Copie o link gerado e compartilhe com o destinatário.</li>
</ol>
<h3>Rastreabilidade</h3>
<p>A aba <strong>Rastreabilidade</strong> exibe o log completo de quem visualizou ou baixou cada documento:</p>
<ul>
  <li>Data e hora do acesso.</li>
  <li>Usuário ou IP que acessou.</li>
  <li>Tipo de ação (Visualização ou Download).</li>
</ul>
<div class="callout callout-info"><i class="fas fa-info-circle"></i> A rastreabilidade é especialmente importante para documentos jurídicos e financeiros, garantindo a comprovação de entrega e acesso.</div>', 'compartilhamento, link publico, rastreabilidade, auditoria, acesso, download', 'publicado', '1.0', 1),
  (14, 19, 'Geração de Relatórios em PDF', 'Como gerar e exportar relatórios de moradores, veículos, visitantes, acessos, hidrômetros e abastecimento.', '<h2>Módulo de Relatórios</h2>
<p>O ERP oferece relatórios gerenciais em formato PDF para os principais módulos do sistema.</p>
<h3>Relatórios Disponíveis</h3>
<ul>
  <li><strong>Relatório de Moradores:</strong> Lista completa com nome, CPF, unidade, contato e status.</li>
  <li><strong>Relatório de Veículos:</strong> Todos os veículos cadastrados com placa, modelo, cor e morador vinculado.</li>
  <li><strong>Relatório de Visitantes:</strong> Histórico de visitas com data, hora, visitante e unidade visitada.</li>
  <li><strong>Relatório de Acessos:</strong> Log de entradas e saídas filtrado por período.</li>
  <li><strong>Relatório de Hidrômetros:</strong> Leituras mensais, consumo por unidade e comparativo entre meses.</li>
  <li><strong>Relatório de Abastecimento:</strong> Histórico de abastecimentos, veículos abastecidos e consumo de combustível.</li>
  <li><strong>Relatório Financeiro:</strong> DRE simplificado com receitas, despesas e saldo do período.</li>
</ul>
<h3>Como Gerar um Relatório</h3>
<ol>
  <li>Acesse o menu <strong>Relatórios</strong>.</li>
  <li>Selecione o tipo de relatório desejado.</li>
  <li>Configure os filtros (período, unidade, morador, etc.).</li>
  <li>Clique em <strong>Gerar PDF</strong>.</li>
  <li>O arquivo será aberto em uma nova aba para visualização e download.</li>
</ol>
<div class="callout callout-info"><i class="fas fa-info-circle"></i> Os relatórios incluem automaticamente o logotipo e os dados da associação configurados em <strong>Configurações > Empresa</strong>.</div>', 'relatorio, pdf, exportar, moradores, veiculos, visitantes, hidrometro, financeiro', 'publicado', '1.0', 1),
  (15, 20, 'Configurações da Empresa e SMTP', 'Como configurar os dados da associação, logotipo e o servidor de e-mail para envio de notificações.', '<h2>Configurações Gerais</h2>
<p>As configurações gerais definem as informações da associação e os parâmetros de funcionamento do sistema.</p>
<h3>Dados da Empresa/Associação</h3>
<ol>
  <li>Acesse <strong>Configurações > Empresa</strong>.</li>
  <li>Preencha: Nome da Associação, CNPJ, Endereço, Telefone, Email institucional.</li>
  <li>Faça o upload do logotipo (recomendado: PNG com fundo transparente, 200x200px).</li>
  <li>Salve. O logotipo aparecerá no cabeçalho do sistema e nos relatórios PDF.</li>
</ol>
<h3>Configuração do SMTP (E-mail)</h3>
<p>O SMTP é necessário para o envio de emails automáticos (recuperação de senha, notificações, relatórios).</p>
<ol>
  <li>Acesse <strong>Configurações > SMTP</strong>.</li>
  <li>Preencha: Servidor SMTP (ex: smtp.gmail.com), Porta (587 para TLS, 465 para SSL), Usuário, Senha.</li>
  <li>Clique em <strong>Testar Conexão</strong> para verificar se as configurações estão corretas.</li>
  <li>Salve.</li>
</ol>
<div class="callout callout-warning"><i class="fas fa-exclamation-triangle"></i> <strong>Gmail:</strong> Se usar o Gmail, é necessário criar uma <em>Senha de App</em> nas configurações de segurança da conta Google, pois o Gmail não aceita a senha normal para SMTP.</div>', 'configuracao, empresa, smtp, email, logotipo, dados, associacao', 'publicado', '1.0', 1),
  (15, 21, 'Usuários, Perfis e Permissões', 'Como criar usuários administrativos, definir perfis de acesso e controlar permissões por módulo.', '<h2>Gestão de Usuários e Permissões</h2>
<p>O sistema possui controle granular de acesso, permitindo definir exatamente quais módulos cada usuário pode acessar.</p>
<h3>Criando um Novo Usuário</h3>
<ol>
  <li>Acesse <strong>Configurações > Usuários</strong>.</li>
  <li>Clique em <strong>+ Novo Usuário</strong>.</li>
  <li>Preencha: Nome, Email (usado como login), Senha inicial, Perfil.</li>
  <li>Salve.</li>
</ol>
<h3>Perfis de Acesso</h3>
<ul>
  <li><strong>Administrador:</strong> Acesso total a todos os módulos e configurações.</li>
  <li><strong>Gerente:</strong> Acesso à maioria dos módulos, exceto configurações críticas.</li>
  <li><strong>Porteiro:</strong> Acesso restrito a Visitantes, Controle de Acesso e Moradores (somente leitura).</li>
  <li><strong>Financeiro:</strong> Acesso apenas ao módulo Financeiro e Relatórios.</li>
</ul>
<h3>Departamentos</h3>
<p>Os departamentos são usados para organizar usuários e documentos. Cadastre os departamentos da associação em <strong>Configurações > Departamentos</strong>. Os departamentos criados aqui ficam disponíveis para seleção no cadastro de documentos (GED) e no cadastro de colaboradores (RH).</p>', 'usuario, permissao, perfil, acesso, admin, gerente, modulo, departamento', 'publicado', '1.0', 1),
  (15, 22, 'Configuração do Firebase e Notificações Push (FCM)', 'Como configurar o Firebase Cloud Messaging para enviar notificações push para os moradores.', '<h2>Notificações Push com Firebase (FCM)</h2>
<p>O sistema utiliza o Firebase Cloud Messaging (FCM) para enviar notificações push diretamente para o celular dos moradores.</p>
<h3>Pré-requisitos</h3>
<p>Para configurar as notificações, você precisa ter concluído os Passos 1 a 3 do Guia de Configuração do PWA (criação do projeto no Firebase, registro do app e geração da VAPID Key).</p>
<h3>Configurando as Credenciais no Sistema</h3>
<ol>
  <li>Acesse <strong>Notificações > Configurar FCM</strong>.</li>
  <li>Preencha os campos com os dados obtidos no Firebase Console:
    <ul>
      <li><strong>Project ID:</strong> Identificador único do projeto Firebase.</li>
      <li><strong>API Key:</strong> Chave de API do firebaseConfig.</li>
      <li><strong>Auth Domain:</strong> Domínio de autenticação (ex: seu-projeto.firebaseapp.com).</li>
      <li><strong>Messaging Sender ID:</strong> ID do remetente de mensagens.</li>
      <li><strong>App ID:</strong> ID do aplicativo Firebase.</li>
      <li><strong>VAPID Key:</strong> Chave pública gerada em Cloud Messaging > Certificados push da Web.</li>
    </ul>
  </li>
  <li>Clique em <strong>Salvar Configurações</strong>.</li>
</ol>
<h3>Regras de Notificação Automática</h3>
<p>Após configurar o FCM, configure as regras de envio automático em <strong>Notificações > Regras de Notificação</strong>. Exemplos de eventos configuráveis:</p>
<ul>
  <li>Visitante chegou na portaria.</li>
  <li>Status de O.S. alterado.</li>
  <li>Novo comunicado publicado.</li>
  <li>Conta em atraso (inadimplência).</li>
</ul>
<div class="callout callout-warning"><i class="fas fa-exclamation-triangle"></i> <strong>FCM Server Key Legacy:</strong> O Google descontinuou a API Legacy. O sistema está preparado para usar a API moderna via Service Account. Se necessário, habilite a Cloud Messaging API (Legacy) no Google Cloud Console.</div>', 'push, notificacao, firebase, fcm, vapid, configurar, celular, app, pwa', 'publicado', '1.0', 1);

SET FOREIGN_KEY_CHECKS = 1;

-- Total: 15 módulos, 22 categorias, 23 artigos