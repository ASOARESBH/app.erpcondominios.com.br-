# Análise arquitetural — Dashboard personalizável

## Escopo

Este documento registra a descoberta obrigatória para a implementação de configuração de Dashboard por empresa e por usuário. A análise foi feita sobre o repositório atual do ERP Condomínios.

## Stack e execução

O sistema utiliza PHP 8.x procedural, MySQL e Apache/LiteSpeed. O frontend é HTML5, CSS3 e JavaScript vanilla, sem React ou Vue. `frontend/layout-base.html` funciona como shell da aplicação; `frontend/js/app-router.js` carrega páginas parciais de `frontend/pages/`, injeta o CSS específico e executa módulos ES correspondentes. Não há etapa de build frontend obrigatória para essas páginas.

## Autenticação e permissões

As APIs protegidas utilizam `api/auth_helper.php`, sessões PHP (`PHPSESSID`) e `verificarAutenticacao()`. A hierarquia legada inclui visualizador, operador, gerente, admin e super_admin. Também existe RBAC granular por módulos através de `api_rbac.php`. A API de Empresa exige autenticação administrativa e tenant ativo, por meio de `verificarAutenticacao(true, 'admin')` e `exigirTenantId()`. A nova configuração empresarial deverá reutilizar essa mesma guarda, sem criar autorização paralela.

## Multi-tenancy

O código atual possui evolução em andamento para isolamento lógico por `tenant_id`. `api_empresa.php` consolida dados de `tenants` e `empresa` e resolve o tenant da sessão. A documentação histórica alerta que várias tabelas e endpoints legados ainda possuem consultas globais, portanto widgets novos não devem copiar consultas sem filtro. Toda configuração nova deve ser filtrada pelo tenant da sessão, e as configurações pessoais devem ser vinculadas ao usuário autenticado e limitadas pela whitelist empresarial.

## Dashboard atual

A página `frontend/pages/dashboard.html` possui seções independentes: estatísticas gerais, chamados/ordens de serviço, top 10 de consumo de água, abastecimento de veículos e histórico de abastecimentos. `frontend/js/pages/dashboard.js` carrega dados de forma assíncrona, incluindo `api_dashboard_agua.php` e `api_ordens_servico.php?acao=dashboard_kpis`, além de carregar Chart.js sob demanda. Essa estrutura é adequada para uma evolução incremental por widgets, mas o endpoint legado de água contém consultas que não filtram consistentemente tenant. Esses dados não devem ser expostos a outros tenants; a primeira versão deve encapsular cada widget e tratar respostas legadas com cautela.

## Padrão da tela Empresa

`frontend/js/pages/empresa.js` registra abas por `[data-empresa-tab]`, alterna painéis com `hidden` e carrega/salva configurações por `api_empresa.php`. A aba Layout Administradora usa ações específicas de carregamento e salvamento, renderiza cartões e mostra estado de configuração. A nova aba Dashboard deverá reutilizar as classes e o comportamento desse componente, acrescentando ações próprias sem alterar Dados da Empresa ou Layout Administradora.

## Módulos e fontes identificadas

| Módulo | Evidência atual | Tratamento inicial |
|---|---|---|
| Moradores/Unidades | Telas e APIs existentes | Widget real com endpoint filtrado |
| Veículos/Acesso/LPR | Módulos de veículos, acesso e LPR existentes | Widget real de veículos/acessos |
| Visitantes | Tela e APIs existentes | Widget real de visitantes aguardando/liberados |
| Manutenção/OS | APIs e tela de ordens de serviço existentes | Widget real de OS, reaproveitando KPIs |
| Financeiro | APIs de contas a pagar/receber existentes | Widget somente após confirmar campos e filtros tenant |
| Recursos Humanos | Módulo existente | Widget real quando houver fonte segura identificada |
| GED | Anexos/documentos existentes, sem catálogo único confirmado | Estado vazio/desabilitado até fonte real ser confirmada |
| Contratos | API `api_contratos.php` existente | Widget real após mapear esquema e datas |
| Relatórios/Atalhos | Relatórios existentes | Atalhos configuráveis, sem dados fictícios |
| Água/Abastecimento | Dashboard atual e API legada | Reutilização gradual com correção de tenant ou estado claro |

## Decisões e riscos

A configuração empresarial será uma whitelist de módulos/widgets. O usuário só poderá escolher e ordenar widgets liberados pelo tenant. A interseção entre whitelist empresarial e preferência pessoal será calculada no backend. Widgets sem fonte confiável ou com endpoint legado não filtrado devem aparecer desabilitados por padrão ou com estado vazio; não serão criados números fictícios. A criação de tabelas e migrations deverá ser compatível com a instalação HostGator e não poderá alterar tabelas compartilhadas sem migration reversível.

O pedido recomenda branch separada e Pull Request. A implementação deve, portanto, ser desenvolvida em branch de feature e não diretamente em `main`. Antes da fase de código, será necessário confirmar o repositório remoto destinado à entrega, pois o checkout atual aponta para `ASOARESBH/app.erpcondominios.com.br-`, enquanto a documentação de contexto também menciona `ASOARESBH/erpserralib`.

## Plano técnico aprovado

1. Criar migration idempotente para catálogo global e configurações por tenant/usuário.
2. Adicionar ações protegidas em API seguindo o formato de `api_empresa.php`.
3. Acrescentar aba Dashboard na Empresa com catálogo, toggles, estado sujo, salvar e restaurar.
4. Adaptar Dashboard para carregar configuração efetiva e renderizar widgets independentes.
5. Implementar preferências pessoais e reordenação com HTML drag-and-drop nativo, evitando dependência nova.
6. Testar tenant, permissões, widget desabilitado, estado vazio, regressão das abas atuais e responsividade.
