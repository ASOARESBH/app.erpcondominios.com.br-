# Plano de implementação multilíngue do ERP Condomínios

## Objetivo

Disponibilizar o ERP em Português do Brasil (`pt-BR`), Inglês (`en-US`) e Espanhol (`es-ES`) com fallback seguro, preferência por usuário e condomínio, formatação regional, tradução do frontend, backend, relatórios, e-mails, notificações e rotinas automáticas.

## Princípios

O idioma não deve alterar silenciosamente moeda ou fuso horário. O locale, a moeda e o timezone serão configurações independentes. A preferência do usuário terá prioridade sobre o padrão do condomínio; quando não houver preferência, será utilizado o locale do condomínio; na ausência de configuração válida, o fallback será `pt-BR`. Chaves ausentes devem retornar o texto em Português e gerar alerta técnico, nunca quebrar a tela.

## Entregas já implementadas nesta branch

| Entrega | Situação |
|---|---|
| Catálogos JSON `pt-BR`, `en-US` e `es-ES` | Implementado |
| Núcleo frontend `ERP_I18N` | Implementado |
| Fallback e normalização de locale | Implementado |
| Formatadores `Intl.NumberFormat` e `Intl.DateTimeFormat` | Implementado no núcleo |
| Seletor global no layout autenticado | Implementado |
| Seletor na tela de login | Implementado |
| Tradução inicial do menu e login | Implementado |
| API de preferência de idioma | Implementado |
| Migration de locale em tenant e usuário | Implementado |
| Helper PHP para traduções e formatação | Implementado |
| Mensagens críticas de autenticação | Implementado |

## Etapas de cobertura total

### Fase 1 — Fundação

Executar a migration, validar os valores permitidos, definir o locale padrão de cada condomínio e confirmar o fallback em instalação nova. Criar testes de contrato para catálogos, parâmetros, chaves ausentes e persistência por tenant.

### Fase 2 — Shell, autenticação e componentes comuns

Traduzir menu lateral, cabeçalho, perfil, logout, notificações, modais, breadcrumbs, estados de carregamento, paginação e mensagens globais. Login, recuperação de senha e sessão devem retornar mensagens com chaves i18n.

### Fase 3 — Módulos de negócio

Refatorar cada página para trocar textos hardcoded por chaves. A ordem recomendada é: Dashboard, Empresa, Moradores, Dependentes, Veículos, Acesso/LPR, Visitantes, Dispositivos, Ordens de Serviço, Unidades, Financeiro, Contratos, RH, GED, Manutenção e Relatórios. Cada módulo deverá incluir labels, placeholders, validações, estados vazios, confirmações e status.

### Fase 4 — Backend e fontes externas

Aplicar `erp_t()` às respostas JSON, validações, erros de upload, RBAC, logs destinados ao usuário, notificações e rotinas automáticas. Logs puramente operacionais podem permanecer em idioma técnico definido pela operação, mas mensagens mostradas ao usuário devem seguir o locale da solicitação.

### Fase 5 — Relatórios, PDFs, e-mails e notificações

Receber o locale efetivo como parâmetro interno e traduzir títulos, cabeçalhos, rodapés, assuntos, corpos e status. PDFs devem usar fontes com suporte a caracteres acentuados. Datas, números e moedas devem ser formatados centralmente.

### Fase 6 — Qualidade e rollout

Criar testes automatizados de chaves, fallback, `Accept-Language`, isolamento por tenant, preferência pessoal, formatação e renderização. Fazer rollout com `pt-BR` como padrão, liberar Inglês e Espanhol por configuração do condomínio e monitorar chaves ausentes antes de remover textos antigos.

## Critério de aceite

A implementação será considerada completa quando não houver textos de interface críticos hardcoded fora dos catálogos, quando as APIs e relatórios aceitarem o locale efetivo, quando e-mails e notificações respeitarem o idioma, quando a preferência for persistida corretamente e quando os testes confirmarem que um usuário de um tenant não consegue ler ou alterar o locale de outro tenant.

## Operação de produção

Executar `sql/migration_i18n_pt_en_es_mysql57.sql` no banco de produção, publicar os arquivos da branch após revisão do Pull Request, limpar o cache do navegador, definir o idioma padrão na administração e testar uma conta em cada idioma. A ativação deve ser gradual, mantendo o fallback `pt-BR` enquanto a cobertura dos módulos restantes é concluída.
