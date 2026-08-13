# Incidente: veículo entra na unidade (Controle de Acesso) e o morador não recebe notificação

## Evidências fornecidas
- Painel web, tela **Controle de Acesso → Registro Manual**: mostra o registro da placa `PBB0172`, morador **ANDRE SOARES E SILVA**, unidade **Gleba 133**, tipo **Entrada**.
- App do morador (Flutter), tela **Controle de Acesso**: mostra o mesmo histórico, com filtro por veículos da unidade logada, listando **"100 acessos encontrados"**.
- Em nenhum dos dois casos o morador recebe uma notificação equivalente à que já existe para **Protocolos** ("encomenda chegou para sua unidade"). O histórico de acessos aparece corretamente, mas nenhum aviso é gerado.

## Diagnóstico (leitura de código, sem alteração)

### O backend de notificação de acesso já existe e está quase pronto
- `api/helpers/access_control_notification_helper.php` implementa duas funções, seguindo o mesmo padrão já usado para Protocolos (`api/helpers/protocol_notification_helper.php` → `protocolo_criar_notificacao_morador()` + `protocolo_notificacao_enviar_fcm()`):
  - `controle_acesso_criar_notificacao_registro()` — cria a notificação "veículo entrou/saiu" a partir de um registro de `registros_acesso`, resolvendo o(s) destinatário(s) por `morador_id` (quando informado) ou por `unidade_destino` (busca todos os moradores ativos daquela unidade).
  - `controle_acesso_criar_notificacao_veiculo()` — cria notificação de "veículo cadastrado para a unidade".
- Ambas gravam em `notificacoes_morador` (mesma tabela que já alimenta a tela de notificações do protocolo) e tentam enviar push via FCM, com fallback silencioso (não bloqueante) se o push falhar.
- A tabela `notificacoes_morador` tem duas migrações que adicionam as colunas `veiculo_id`/`registro_acesso_id` necessárias para esse recurso: `sql/migration_notificacoes_registros_acesso.sql` e `sql/migration_notificacoes_veiculos_controle_acesso.sql`. **Não é possível confirmar, só pelo código-fonte, se alguma delas já foi executada no banco de produção.** Se não tiver sido, `controle_acesso_criar_notificacao_registro()` retorna cedo com `motivo: 'migracao_pendente'` (guard em `access_control_notification_helper.php`, checando `controle_acesso_notificacao_coluna_existe()`), e nenhuma notificação é criada — silenciosamente.

### Causa raiz nº 1 (confirmada por grep no repositório inteiro): só o cadastro manual pelo painel web chama a notificação
```
grep -rn "controle_acesso_criar_notificacao" --include="*.php" .
→ api/helpers/access_control_notification_helper.php (definições das funções)
→ api/api_registros.php:258  (ÚNICA chamada de controle_acesso_criar_notificacao_registro em todo o projeto)
```
- `api/api_registros.php` é o backend da tela **Controle de Acesso → Registro Manual** do painel web (`frontend/js/pages/registro.js`, `POST ../api/api_registros.php`). Depois de inserir em `registros_acesso`, ele chama `controle_acesso_criar_notificacao_registro()` dentro de um `try/catch` não bloqueante — isso está correto e implementado.
- `controle_acesso_criar_notificacao_veiculo()` (notificação de "veículo cadastrado") **não é chamada em nenhum lugar do código** — é função morta, mesmo com a migração já habilitando `pwa_configuracoes.push_controle_acesso_ativo` para essa categoria.

### Causa raiz nº 2 (a mais provável para o caso relatado): a catraca/leitor automático nunca aciona a notificação
- Os acessos "de verdade" (o carro passando pela catraca/leitor ControlID) **não passam pelo painel web** — eles chegam por dois endpoints de hardware:
  - `api/controlid/notifications/dao.php` (Monitor Mode — POST do equipamento a cada INSERT em `access_logs`).
  - `api/controlid/new_user_identified.php` (Online Mode Pro — identificação em tempo real, autoriza/nega o acesso).
- Ambos, ao liberar o acesso, chamam a mesma função `push_registrar_acesso_erp()` (definida em `api/controlid/_helper.php`, linha 221):
  ```
  grep -rn "push_registrar_acesso_erp" --include="*.php" .
  → api/controlid/_helper.php:221  (definição)
  → api/controlid/notifications/dao.php:100        (chamada, modo Monitor)
  → api/controlid/new_user_identified.php:95        (chamada, modo Online Pro)
  ```
- `push_registrar_acesso_erp()` **insere direto em `registros_acesso`** (por isso o histórico aparece certinho no painel e no app) **mas nunca inclui/chama `access_control_notification_helper.php`** — não há `require_once` do helper nem chamada a `controle_acesso_criar_notificacao_registro()` nesse arquivo.
- Resultado prático: praticamente todo acesso real de veículo (via catraca) é gravado normalmente em `registros_acesso`, porém **nenhuma notificação é criada** para ele. Só ganharia notificação um acesso lançado manualmente por um operador no painel web (`Registro Manual`) — e mesmo esse caminho depende da migração de banco já ter sido aplicada.

### Causa raiz nº 3 (a confirmar em produção, não verificável só pelo código)
- Mesmo quando `controle_acesso_criar_notificacao_registro()` é chamada (caminho manual), o sucesso depende de:
  1. As colunas `veiculo_id`/`registro_acesso_id` existirem em `notificacoes_morador` (migração aplicada).
  2. Existir morador ativo vinculado à unidade/placa (`moradores.unidade` batendo exatamente com o valor da unidade do registro).
  3. Push FCM não é obrigatório para o registro aparecer no app: a tela de notificações do Portal do Morador (`api_portal_morador.php`, `action=notificacoes`) lê da mesma tabela `notificacoes_morador`, então mesmo que o push falhe (token ausente/expirado), a notificação deveria aparecer na lista do app — o que não está acontecendo, reforçando que a causa nº 2 é a principal.

## Conclusão
O recurso de notificação "veículo entrou para a unidade" **já foi construído e ligado ao fluxo manual do painel web**, mas **nunca foi ligado ao fluxo automático da catraca (ControlID)**, que é a origem da grande maioria (senão totalidade) dos acessos reais mostrados nas telas do usuário. Além disso, não há confirmação de que a migração de banco (`veiculo_id`/`registro_acesso_id` em `notificacoes_morador`) já rodou em produção — sem ela, nem o caminho manual funciona.

## Correção recomendada (não aplicada nesta análise — ver prompt para Manus)
1. Confirmar em produção se `notificacoes_morador` já tem as colunas `veiculo_id` e `registro_acesso_id` (`SHOW COLUMNS FROM notificacoes_morador;`); se não, rodar as migrações pendentes.
2. Em `api/controlid/_helper.php`, dentro de `push_registrar_acesso_erp()`, após o `INSERT INTO registros_acesso`, incluir `access_control_notification_helper.php` e chamar `controle_acesso_criar_notificacao_registro()` com o `id` do registro recém-inserido, o `morador_id` do veículo e a unidade do morador — replicando exatamente o que `api_registros.php` já faz.
3. Validar se faz sentido também notificar em `api/controlid/notifications/door.php` (abertura de porta/relé) ou se esse evento não deve gerar notificação ao morador (provavelmente não, é evento de infraestrutura).
4. Testar de ponta a ponta: acesso real via catraca → linha em `registros_acesso` → linha em `notificacoes_morador` → aparece na tela de notificações do app → push chega no celular.
5. Avaliar (separadamente) se `controle_acesso_criar_notificacao_veiculo()` (veículo cadastrado) também deveria ser ligada em algum ponto do cadastro de veículos, já que está pronta e configurada mas nunca é chamada.

## Fontes locais
- Helper de notificação de acesso: `api/helpers/access_control_notification_helper.php`
- Helper de notificação de protocolo (padrão de referência): `api/helpers/protocol_notification_helper.php`
- Caminho manual (painel web): `api/api_registros.php`, `frontend/js/pages/registro.js`
- Caminho automático (catraca ControlID): `api/controlid/_helper.php` (`push_registrar_acesso_erp`), `api/controlid/notifications/dao.php`, `api/controlid/new_user_identified.php`
- Migrações: `sql/migration_notificacoes_registros_acesso.sql`, `sql/migration_notificacoes_veiculos_controle_acesso.sql`
- Leitura das notificações no app: `api/api_portal_morador.php` (`action=notificacoes`)
- Arquitetura do app mobile: [APP_MOBILE_ARCHITECTURE.md](APP_MOBILE_ARCHITECTURE.md)
