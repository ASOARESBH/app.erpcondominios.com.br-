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
- Em MariaDB descartável, tanto grupo personalizado com somente `moradores.visualizar` quanto `compat-visualizador`, mesmo associados também a `compat-operador`, mantiveram a leitura e receberam `NEGAR` efetivo para criar, editar, excluir e exportar. O usuário apenas `compat-operador` manteve as permissões usuais.
- O mesmo teste alterou uma permissão após cache e confirmou invalidação por revisão; também confirmou a criação da linha de revisão quando ela ainda não existia.
- Para validação manual após deploy: manter listagem/consulta disponíveis, confirmar ausência/bloqueio de formulários de escrita e respostas 403 em POST/PUT/DELETE. Os Alertas operacionais devem continuar sendo avaliados no lançamento manual de entrada, não na simples consulta de Moradores.
