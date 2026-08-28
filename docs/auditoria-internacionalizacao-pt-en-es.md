# Auditoria de internacionalização do ERP Condomínios

**Data da auditoria:** 27 de agosto de 2026  
**Escopo:** repositório `ASOARESBH/app.erpcondominios.com.br-`, branch atual `feature/dashboard-personalizavel`  
**Idiomas avaliados:** Português do Brasil (`pt-BR`), Inglês (`en-US`) e Espanhol (`es-ES`)

## Conclusão executiva

O ERP está **operacionalmente preparado apenas para Português do Brasil**. Para Inglês e Espanhol, o nível atual é **não preparado**: não existe uma arquitetura de internacionalização aplicável ao sistema inteiro, não há catálogos de tradução, não há seletor ou preferência de idioma, e as mensagens, telas, relatórios, e-mails e notificações estão fortemente acoplados ao Português.

A conclusão não significa que o sistema não possa ser internacionalizado. A codificação `utf8mb4` e a separação entre frontend, APIs PHP e banco fornecem uma base técnica aproveitável. Entretanto, a migração exigirá uma refatoração transversal, e não apenas a tradução de alguns rótulos.

| Área | Português | Inglês | Espanhol | Classificação geral |
|---|---:|---:|---:|---|
| Interface frontend | Funciona | Não disponível | Não disponível | Ausente para multi-idioma |
| APIs e mensagens backend | Funciona | Não disponível | Não disponível | Ausente para multi-idioma |
| Relatórios e PDFs | Português fixo | Não disponível | Não disponível | Ausente para multi-idioma |
| E-mails e notificações | Português fixo | Não disponível | Não disponível | Ausente para multi-idioma |
| Datas, números e moedas | `pt-BR`/BRL fixos | Não suportado | Não suportado | Parcial apenas no idioma atual |
| Preferência por usuário/tenant | Não há seletor formal | Não há | Não há | Ausente |
| Banco e modelo de dados | Sem locale | Sem locale | Sem locale | Ausente |

## Evidências principais

A auditoria encontrou **340 ocorrências de `pt-BR`**, **233 ocorrências do marcador `R$`** e **zero ocorrências de `en-US` e `es-ES`** nas áreas de frontend, APIs e SQL examinadas. As poucas ocorrências dos termos `language` ou `locale` são dependências ou usos incidentais de ordenação, não um sistema de tradução.

No frontend, `frontend/layout-base.html` fixa o idioma do documento em `lang="pt-BR"`. Os textos de páginas, botões, mensagens e títulos são escritos diretamente em HTML ou JavaScript. Não foram encontrados arquivos de catálogo, atributos `data-i18n`, biblioteca de tradução, seletor de idioma ou persistência de preferência em sessão, banco ou `localStorage`.

A formatação também está presa ao Brasil. Há chamadas como `toLocaleString('pt-BR')` e `Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })` em módulos como `frontend/js/pages/dashboard.js`, `frontend/js/pages/contas_bancarias.js`, `frontend/js/pages/contas_pagar.js`, `frontend/js/pages/contas_receber.js`, `frontend/js/pages/contratos.js` e `frontend/js/pages/dispositivos.js`. Esses padrões precisam receber o locale ativo em vez de uma constante.

No backend, `api/config.php` fixa o fuso em `America/Sao_Paulo`. O fluxo de autenticação em `api/validar_login.php` devolve mensagens hardcoded em Português, como mensagens de campos obrigatórios, e-mail inválido e credenciais incorretas. Não há leitura de `Accept-Language`, nem parâmetro de idioma no perfil, na sessão ou nas respostas das APIs.

Os relatórios e exports continuam em Português. A API `api/api_demonstrativo_agua.php` gera HTML com `lang="pt-BR"`, e os geradores de PDF, incluindo `api/api_relatorio_moradores_pdf.php` e `api/api_relatorio_abastecimento_pdf.php`, possuem títulos e cabeçalhos fixos. Helpers financeiros usam `number_format` com separador decimal brasileiro e prefixo `R$`.

Os e-mails e notificações também não são internacionalizados. `api/EmailSender.php`, `api/email/SmtpProvider.php`, os templates de recuperação de senha, scripts de cron financeiro e APIs de notificações produzem assuntos, corpo, logs e mensagens somente em Português. Mensagens de upload, validação e falhas operacionais seguem o mesmo padrão.

O esquema do banco não possui uma preferência de idioma em `tenants`, usuários ou moradores. Não existe tabela de traduções nem migration para `locale`, `idioma` ou equivalente. A ausência é estrutural: mesmo que a interface recebesse um seletor, o sistema ainda não teria onde persistir a preferência do usuário e o padrão do condomínio.

## O que o sistema suporta hoje

O ERP suporta corretamente o conjunto **Português do Brasil + convenções brasileiras**, incluindo moeda BRL, separadores numéricos e fuso de São Paulo. A infraestrutura usa `utf8mb4`, o que é adequado para caracteres acentuados e para os alfabetos usados em Inglês e Espanhol. Além disso, o frontend e o backend estão suficientemente separados para receber uma camada de tradução sem reescrever a aplicação inteira.

Esses pontos são **fundação técnica**, não suporte multilíngue. Não há evidência de que um usuário consiga trocar o idioma e navegar de forma consistente pelas páginas, APIs, PDFs, e-mails e notificações.

## Riscos de uma tradução superficial

Traduzir somente os menus produziria uma experiência inconsistente. O usuário ainda receberia erros em Português, PDFs em Português, notificações em Português e valores formatados como reais. Também haveria risco de divergência entre o idioma escolhido no navegador, o idioma do usuário, o idioma do tenant e o idioma utilizado por tarefas automáticas.

O fuso horário não deve ser confundido com idioma. `America/Sao_Paulo` é uma configuração regional e pode continuar como padrão do condomínio, mas o sistema deverá separar **locale**, **moeda** e **timezone**. Um usuário em Inglês pode continuar usando horário de Brasília e moeda BRL, ou futuramente escolher outra configuração, sem que uma preferência altere silenciosamente as demais.

## Arquitetura recomendada

A solução deve adotar um serviço central de internacionalização com três camadas. No frontend, um catálogo versionado por idioma, por exemplo `pt-BR.json`, `en-US.json` e `es-ES.json`, deve fornecer chaves para rótulos, mensagens, status, validações, menus e estados vazios. No backend PHP, um `I18nService` deve carregar as mesmas chaves ou catálogos equivalentes e oferecer `t('chave', parâmetros)` para APIs, PDFs, e-mails e notificações. Nos dados, o idioma efetivo deve ser resolvido por prioridade: preferência explícita do usuário, padrão do tenant, idioma enviado pelo cliente autenticado e, por fim, `pt-BR` como fallback.

A preferência deve ser persistida separadamente para tenant e usuário. Recomenda-se adicionar `locale` ao tenant como padrão institucional e ao usuário como preferência pessoal, com valores controlados `pt-BR`, `en-US` e `es-ES`. A API deve validar a whitelist de idiomas e nunca aceitar texto arbitrário como locale.

Datas, horários, números e moedas devem ser formatados por funções centrais. APIs devem preferir datas ISO 8601 ou campos estruturados, deixando a apresentação para o cliente; PDFs e e-mails devem usar o locale resolvido no backend. Todas as mensagens devem ser identificadas por chaves, não por textos livres espalhados pelo código.

## Plano de adequação priorizado

| Prioridade | Entrega | Resultado esperado |
|---:|---|---|
| P0 | Definir contrato de locale, fallback e separação entre idioma, moeda e fuso | Regra única para todo o ERP |
| P0 | Criar catálogos `pt-BR`, `en-US` e `es-ES` e o serviço central de tradução | Base reutilizável no frontend e backend |
| P0 | Adicionar preferência de tenant e usuário com validação e sessão | Idioma persistente e seguro |
| P1 | Inserir seletor no layout-base e aplicar tradução aos menus, login, autenticação e componentes comuns | Primeira experiência multilíngue visível |
| P1 | Refatorar páginas frontend por módulos, substituindo textos hardcoded e `pt-BR` fixo | Cobertura progressiva das telas |
| P1 | Internacionalizar respostas de API, validações e mensagens de erro | Backend coerente com a interface |
| P2 | Refatorar PDFs, relatórios, exports, e-mails, notificações e cron | Comunicação externa no idioma correto |
| P2 | Criar testes automatizados de cobertura de chaves, fallback, formatação e isolamento por tenant | Prevenção de regressões |
| P3 | Revisar textos nativos com tradutor e testar visualmente expansão de textos | Qualidade linguística e de layout |

## Critérios para considerar o ERP pronto

O ERP somente deve ser considerado preparado quando o idioma puder ser escolhido e persistido, quando todas as telas principais tiverem cobertura de chaves, quando mensagens de API e autenticação respeitarem o idioma efetivo, quando PDFs/e-mails/notificações forem gerados no idioma correto e quando datas, números e moedas forem formatados por locale sem constantes `pt-BR` espalhadas.

Também é necessário validar que a configuração de um tenant não vaze para outro, que o usuário possa ter uma preferência diferente do padrão institucional e que a ausência de uma tradução não quebre a tela: toda chave ausente deve cair para Português do Brasil e registrar uma advertência técnica para correção posterior.

## Veredito final

**Hoje, o ERP Condomínios não está preparado para trabalhar de forma multilíngue em Português, Inglês e Espanhol.** Ele está preparado para operar em Português do Brasil e possui base técnica para receber internacionalização, mas o suporte a Inglês e Espanhol é atualmente **inexistente** nas camadas de interface, backend, relatórios, e-mails, notificações e persistência de preferências.

A estimativa qualitativa de prontidão atual é:

| Dimensão | Prontidão |
|---|---:|
| Português do Brasil | Alta |
| Inglês | 0–5% |
| Espanhol | 0–5% |
| Arquitetura i18n | 0% |
| Formatação regional abstrata | Baixa |
| Persistência de idioma | 0% |

Os percentuais são uma classificação técnica de cobertura arquitetural observada no código, não uma medição de tradução de conteúdo. A recomendação é iniciar pelo núcleo i18n e pelas preferências de tenant/usuário antes de traduzir as centenas de telas e mensagens individualmente.
