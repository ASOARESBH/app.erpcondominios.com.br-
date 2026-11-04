# Guia de Testes e QA

O sistema não possui testes automatizados (Jest/PHPUnit). Os testes são manuais e focados em comportamento.

## 1. Testes de Frontend
- **Navegação**: Verificar se o AppRouter carrega a página, CSS e JS sem recarregar a janela.
- **Console**: Garantir que não há erros vermelhos no Console do DevTools.
- **Responsividade**: Testar a sidebar em modo mobile (hambúrguer) e desktop (expandida/recolhida).

## 2. Testes de Backend
- **Output**: A API NUNCA deve retornar HTML ou Warnings PHP. O response deve ser estritamente JSON.
- **Segurança**: Tentar acessar uma API protegida sem o cookie `PHPSESSID`. Deve retornar 401 ou 403.
- **SQL Injection**: Tentar inserir aspas simples `'` em campos de busca para garantir que o Prepared Statement está funcionando.

## 3. Validação de Entrega
Antes de gerar o `.zip` final, certifique-se de que nenhum caminho absoluto (ex: `C:/Users/...` ou `/home/ubuntu/...`) foi hardcoded no código.


## 4. Alertas da Unidade — QA desta implementação web/backend
- Executado `php -l` nos endpoints/helper PHP alterados, `node --check` nos módulos JavaScript alterados e `git diff --check` após correção de whitespace.
- Em MariaDB descartável local, `migration_alertas_unidade_mysql57.sql` foi aplicada duas vezes; foram confirmadas as tabelas tenant-aware, o ENUM com decisões automáticas e a atualização de `sessoes_portal.tenant_id`.
- Teste sintético do helper confirmou migration gate, isolamento tenant A/B, match da regra, inserção `BLOQUEADO_AUTOMATICO`, máscara do documento e incremento do contador.
- Teste MariaDB descartável adicional confirmou 30 regras vigentes contadas somente no tenant/unidade correspondente e matching de `FAMILIAR` no contexto `CONVIDADOS`; nenhum alerta do tenant A foi retornado para o tenant B.
- Migration executada novamente duas vezes em schema temporário com `alertas_unidade_preferencias`; preferência desligada no tenant A suprimiu push apenas para A, enquanto tenant B sem linha manteve o padrão habilitado. O teste confirmou também o gate completo.
- Teste do cache descartável confirmou resposta inicial, hit dentro de 30 segundos, visibilidade de novo alerta após invalidação e nenhum resultado cruzado para tenant B.
- Teste MariaDB descartável da nova rotina: tenant A expirou um alerta vencido e recebeu um lembrete único; alerta futuro do tenant B permaneceu ativo; segunda execução não duplicou evento, inbox, histórico nem lembrete. O teste também cobriu `notificacoes_morador` sem a coluna opcional `alerta_unidade_id`.
- Após o erro MySQL #1267 observado na importação, a migration e o gate de schema foram ajustados para comparar metadata sem depender da collation implícita. Em schema descartável com collations `utf8_general_ci` e `utf8_unicode_ci` misturadas, a migration foi reaplicada duas vezes; as 11 tabelas, backfill de `sessoes_portal.tenant_id`, seed RBAC e configuração PWA foram confirmados. Não houve execução em produção.
- Verificados localmente `php -l` nos PHP alterados e cron, `node --check` nos módulos e script inline do Portal e `git diff --check` após a implementação de KPIs, abas de detalhe, filtros por usuário, exportação por período, modelos, prévia, duplicação e preflight de convidados.
- Não executados: end-to-end em browser autenticado, hardware/Console físico, Flutter, FCM real ou migration/deploy/agendamento no banco/servidor de produção. O cron e a migration precisam ser implantados e ativados pela operação após backup autorizado.

## 5. Moradores — grupo somente visualização
- Histórico de comportamento anterior à correção 2.1.17: grupo personalizado/`compat-visualizador` somente leitura fabricava `NEGAR` para outras ações; essa expectativa foi removida. A regra vigente não sintetiza negações: sem `PERMITIR` a ação não é concedida, e só um `NEGAR` explícito prevalece entre grupos.
- A suíte vigente de precedência está em §10 e confirma especificamente que U4, nos grupos visualização + edição, pode editar; U5, com `NEGAR` individual, não pode.
- Para validação manual após deploy: manter listagem/consulta disponíveis, confirmar ausência/bloqueio de formulários de escrita e respostas 403 em POST/PUT/DELETE. Os Alertas operacionais devem continuar sendo avaliados no lançamento manual de entrada, não na simples consulta de Moradores.

## 6. Ordens de Serviço — atendente e escopo pessoal
- `php -l api/api_ordens_servico.php`, `node --check frontend/js/pages/ordens_servico.js` e `git diff --check` passaram; a tela não mantém chamada para `api_usuarios.php`.
- Em MariaDB descartável, Restritividade O.S. retornou somente ordens criadas, atribuídas ou assumidas pelo usuário dentro do tenant; sem restritividade, somou departamentos explicitamente permitidos sem cruzar tenant. O schema legado sem `assumido_por_id` continuou consultável. A consulta de atendentes foi validada quanto a tenant, usuário ativo e exclusão de `super_admin`.
- Não executados: browser autenticado, deploy ou teste no banco de produção. Após publicação, validar com operador o próprio nome no atendente e as O.S. criadas/atribuídas/assumidas; confirmar com gestor a lista de atendentes ativos do tenant.

## 7. Ordens de Serviço — filtro exato por unidade e morador
- Em MariaDB descartável, consultas SQL equivalentes ao construtor preparado foram exercitadas com unidades `103`, `1030`, `Gleba 10` e `Gleba 103`: confirmaram correspondência exata, normalização de espaços/caixa, exclusão de prefixos semelhantes, fallback para O.S. antiga sem unidade snapshot, filtro de morador da mesma unidade, resultado vazio para incompatibilidade e manutenção do escopo de departamento, Restritividade O.S. e tenant.
- A consulta equivalente à ação de contagem por unidade foi testada sob o mesmo escopo visível, inclusive em modo restritivo e na query de Top 5. `migration_os_indice_unidade_mysql57.sql` foi executada duas vezes no banco descartável e confirmou `idx_os_tenant_unidade (tenant_id, morador_unidade)`; o SQL de diagnóstico somente leitura também foi executado no fixture.
- A consulta equivalente de moradores ativos por unidade, com `tenant_id` e unidade preparados, foi validada com fixtures de dois tenants e unidade com espaços; cada tenant retornou somente seus moradores. `php -l` nas duas APIs, `node --check` no módulo e `git diff --check` passaram.
- A resolução preparada do morador para criar/editar foi verificada contra fixtures: os valores retornados vieram do cadastro tenant-local, e um `morador_id` de outro tenant não foi resolvido. A regra de edição com o mesmo ID mantém o snapshot; troca explícita usa unidade tenant-local do novo morador.
- Não foram executados browser autenticado, deploy, nem migration/consulta na base de produção. Após publicação, testar os selects encadeados com usuário que tenha e outro que não tenha `moradores.visualizar`, URL/F5, remoção de chips, mobile/desktop, e confirmar que dashboard/lista/detalhe mantêm o escopo RBAC original.

## 8. Alertas da Unidade — erro 500 ao salvar
- A reprodução do `INSERT` de `alertas_unidade` usou o helper real `au_query` e uma tabela descartável com o contrato de 24 colunas. A assinatura antiga tinha 23 tipos e lançou `ArgumentCountError`; a corrigida passou a inserir e recuperar os valores, incluindo `NULL` para dependente e chave de idempotência. `php -l` e `git diff --check` passaram.
- A captura também mostra HTTP 422 em uma tentativa; sem o corpo da resposta da Network não é possível identificar qual validação específica a produziu. Não houve acesso aos logs nem à base de produção, nem teste autenticado, deploy ou migration.

## 9. Moradores — bloqueio e restauração de controles RBAC
- Harness local de DOM simulado confirmou que a resposta efetiva de administrador reabilita controles desabilitados pelo bloqueio provisório; controles já `disabled` por outro fluxo permanecem desabilitados; perfil somente leitura não recupera ações de edição; falha ao carregar permissões bloqueia escrita e apresenta aviso.
- `node --check frontend/js/pages/moradores.js`, `php -l api/api_moradores.php` e `git diff --check` passaram. Não houve sessão autenticada/browser, chamada direta com cookie real, deploy ou teste em produção.

## 10. RBAC — matriz e precedência (Parte 2)
- A migration `sql/migration_rbac_multitenant_mysql57.sql` foi aplicada somente no schema descartável `qa_rbac_review_20261103`. A suíte usou usuários U1–U9 e conferiu: grupo só visualização não nega escrita implicitamente; grants de grupos são cumulativos; `NEGAR` individual prevalece sobre grant de grupo; grant individual prevalece sobre negação de grupo; entre grupos, `NEGAR` explícito prevalece; ações individuais explícitas são reconhecidas; e usuário/ação de outro tenant não atravessa o escopo.
- O helper/controlador PHP foi executado contra a fixture real: perfil admin obsoleto em sessão não dá acesso total a usuário comum; perfil atual do banco é devolvido junto de `is_admin`; configurador sem `moradores.editar` não pode conceder essa ação, nem remover um `NEGAR` individual via HERDAR; o grant é permitido ao admin real. A revisão do tenant é incrementada e criada quando ainda não existe. Todos os casos retornaram PASS.
- `php -l` passou para os cinco arquivos PHP RBAC tocados; `node --check` passou para `usuarios.js`, `permissoes-modulos.js` e `menu-controller.js`; os harnesses confirmaram falha fechada, aliases `PAGE_TO_MODULO` coerentes, evento em `document` e `MenuController` ignorando papel stale do navegador e aplicando mudanças sem recarregar; `git diff --check` passou.
- A migration da fixture descartável contém 59 módulos ativos e 199 ações. A auditoria REPO_ONLY paralela avaliou 357 operações/candidatas de código, com gaps ou implementação parcial em 331 linhas segundo os resultados por operação; isso não significa que 331 permissões de produção estejam desprotegidas. Não acessou schema de produção; as ausências na migration refletem somente o seed do repositório. Não foram executados browser autenticado, DevTools com cookie, endpoints de Moradores/Visitantes/Registro/O.S/Usuários com sessão real, nem deploy.

## 11. Registro Manual — stepper e pré-validação parcial
- `php -l` passou em `api/api_alertas_unidade.php`, `api/api_registros.php` e `api/helpers/alertas_unidade_helper.php`; `node --check frontend/js/pages/registro.js` e `git diff --check` também passaram.
- Em MariaDB descartável, a migration de Alertas foi aplicada somente ao schema QA. O helper real confirmou avaliação/contagem por etapa e isolamento de tenant; teste de token real confirmou `CIENCIA_TOKEN_DIVERGENTE` quando o destino muda e `CIENCIA_TOKEN_INVALIDO` em outro tenant. Nenhum endpoint foi executado com cookie/sessão real.
- Smoke test Playwright Chromium em 1366×768 e 375×812 carregou o HTML/CSS/JS ativos, com todas as APIs interceptadas. PASS: avanço por Enter e foco no primeiro controle visível; etapas concluídas recolhidas com resumo/Editar; dados posteriores preservados ao editar Destino; preflight reexecutado sem repetir popup do mesmo contexto; alertas de Entrada, informativo, saída sem consulta, bloqueio sem override e caminho de override; pendência de documento obrigatória; Morador/Dependente, Pedestre e ocupantes; convite vinculado; 409 → ciência formal → token mockado → retry com a mesma chave; atualização da lista recente; duplo clique gera um único POST; Limpar gera nova chave; Escape, temporizadores/preferência e zero rolagem horizontal. Sem erros de página.
- A fixture MariaDB `qa_registro_hardening_20261104` executou helpers reais extraídos dos endpoints: dependente de outro morador/tenant rejeitado em Entrada e Saída, estado ativo exigido para Entrada, documento/tipo conferidos, permanência 1–365, unidade oficial derivada do dependente e comparação idempotente do conjunto de ocupantes. Reordenação igual passou; adição, remoção ou documento divergente falhou; o conflito foi confirmado como HTTP 409 `IDEMPOTENCY_CONFLICT`. O smoke test também confirmou Voltar de Veículo para Destino quando Morador omite Identificação.
- A validação 409 real de browser foi simulada com API mockada; a suíte encontrou e motivou a correção de uma referência a função inexistente para abrir o modal formal. Não foram executados browser autenticado, API com sessão real, deploy, nem testes ou alterações no banco de produção. Após publicação, validar a tela com operador e confirmar políticas reais de tenant/RBAC, ciência e override.

## 5. Visitantes por Gleba no App/Portal (2026-11-04)
- `php -l` em `api_portal_morador.php`, `api_acessos_visitantes.php` e `helpers/portal_visitantes_unidade_helper.php`.
- MariaDB descartável executou o endpoint real (`action=visitantes`, GET e DELETE) com fixture: morador 185 e 186 na Gleba 133 (variação de caixa/espaço), ex-morador inativo da Gleba 133, Gleba 13, Gleba 1330, unidade `133` sem prefixo, visitante da portaria autorizado para a Gleba, visitante da portaria sem vínculo e tenant 2 com a mesma Gleba. Resultado: só visitantes da Gleba 133 do tenant 1; exclusão de visitante de outro morador recusada e do próprio aceita.
- Validação do QR (`pvu_condicao_visitante_unidade` com `ativo=1`) aceitou visitantes ativos da Gleba e recusou inativo, sem vínculo, ex-morador, Glebas parecidas e outro tenant.
- Não executados: `flutter analyze`/build do APK (SDK indisponível no ambiente da análise), teste autenticado em produção.
