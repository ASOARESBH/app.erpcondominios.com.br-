# PROMPT DE IMPLEMENTAÇÃO — Banco de Horas: permitir abatimento maior que o saldo

> Gerado em 2026-11-04 com base na análise de `api/api_rh_banco_horas.php`, `api/rh_ponto_core.php` (`_bh_sincronizar`), `api/api_rh_ponto.php` (DSR), `api/api_rh_relatorios.php` (`acao=banco_horas`), `frontend/js/pages/recursos_humanos.js` (`_registrarBH`, render do ledger) e `frontend/pages/recursos_humanos.html` (painel `#bh-registrar-painel`).
> Tela: `frontend/layout-base.html?page=recursos_humanos` → Relatórios → Banco de Horas → "Registrar Abatimento / Pagamento".
> Sintoma: colaborador com saldo 06:01, abatimento de 480 min (folga compensatória de 08:00) → toast "Saldo insuficiente. Saldo atual: 06:01" e nada é gravado.

## INÍCIO DO PROMPT

Você é o tech lead, engenheiro de segurança e QA do **ERP CONDOMÍNIOS**. Corrija o módulo **Banco de Horas (RH)** para que um **abatimento (folga compensatória) maior que o saldo seja aceito**, deixando o saldo negativo de forma explícita, confirmada e auditada. Assim a regularização do saldo fica correta. Na mesma tarefa, corrija as falhas estruturais encontradas na análise abaixo. O código deve ficar pronto para produção, sem gambiarras.

### 0. Regras obrigatórias
1. Leia antes: `AI-CONTEXT/INDEX.md`, `SKILL.md`, `API.md`, `DATABASE.md`, `BUSINESS_RULES.md`, `SECURITY.md`, `MULTITENANT_ARCHITECTURE.md`, `FRONTEND.md` e este arquivo.
2. ANALISAR → ENTENDER → IDENTIFICAR CAUSA → PLANEJAR → IMPLEMENTAR → TESTAR → DOCUMENTAR → ATUALIZAR AI-CONTEXT. Apresente o plano antes de codar.
3. Multi-tenant: `tenant_id` sempre da sessão (`exigirTenantId()`), nunca do payload. Todo SELECT, INSERT, UPDATE e DELETE de `rh_banco_horas` usa `tenant_id`.
4. Apenas prepared statements. Remova a interpolação de `$tenant_id` e `$lancamento_id` nas queries. Resposta padrão `{sucesso, mensagem, dados}` com o código HTTP correto, sem expor `$conn->error` ou `$st->error` ao cliente (logue com `error_log`).
5. Vanilla JS (ES Module), `_esc()` em toda saída. Não edite arquivos `*_backup*`, `*.bak`, `teste_*`, `debug_*`.
6. Migrations idempotentes e compatíveis com MySQL 5.7, em `sql/migration_rh_banco_horas_saldo_negativo_mysql57.sql`.
7. Não quebre o cálculo automático de créditos/débitos (`_bh_sincronizar`), o DSR (`api_rh_ponto.php`), o relatório PDF (`api_rh_relatorio_pdf.php`) nem o abono.

---

### 1. Causa raiz e achados (confirmados no código)

| # | Onde | Problema | Correção esperada |
|---|---|---|---|
| 1 | `api_rh_banco_horas.php` L172–191 (`acao=registrar`) | `if ($minutos > $saldoAtual) retornar_json(false,'Saldo insuficiente...')` bloqueia **abatimento e pagamento** igualmente. É a causa do bloqueio. | Regra nova da §2: abatimento pode exceder o saldo com confirmação; pagamento continua limitado ao saldo positivo. |
| 2 | `api_rh_banco_horas.php` L193–197 | `INSERT` **não grava `tenant_id`**. A coluna foi criada com `DEFAULT 1` (`migration_multitenant_fase1.sql` L303), então **todo abatimento/pagamento manual de qualquer condomínio cai no tenant 1**. | Incluir `tenant_id` no INSERT. |
| 3 | `rh_ponto_core.php` `_bh_sincronizar` (L390 DELETE, L434 INSERT) e `api_rh_ponto.php` (DSR, L711 INSERT) | Créditos/débitos automáticos também são inseridos **sem `tenant_id`**, e o DELETE de `_bh_sincronizar` não filtra por tenant. | Gravar e filtrar por `tenant_id` (receber o tenant como parâmetro ou via `exigirTenantId()`). |
| 4 | `api_rh_banco_horas.php` `acao=extrato` (L74) e `api_rh_relatorios.php` `acao=banco_horas` (L295 e saldo global L329) | Consultas **sem filtro de `tenant_id`**: o extrato e o "Saldo atual" somam linhas de outros tenants que tenham o mesmo `colaborador_id`. É um vazamento de dados entre condomínios. | Filtrar por `tenant_id` e validar que o colaborador pertence ao tenant (`rh_colaboradores`, ou a tabela canônica do RH) antes de qualquer ação. Responder 404 se não pertencer. |
| 5 | Cálculo de saldo duplicado em 3 lugares (`extrato`, `saldo`, `registrar`, além do relatório) | Regra `tipo === 'credito' ? + : -` repetida. **Qualquer tipo inválido ou vazio vira débito.** | Centralizar em um helper (ex.: `api/helpers/rh_banco_horas_helper.php`: `bh_saldo()`, `bh_fmt()`, `bh_sinal()`) e usar em todos os endpoints e no PDF. |
| 6 | Extrato (print do usuário): linha de **15/09/2026, -08:00, "COMPENSAÇÃO-15/09/2026", usuário GABRIEL...** com a coluna **Tipo vazia** | O ENUM `tipo` só aceita `credito/debito/abatimento/pagamento`; um valor fora do ENUM gravado em modo não estrito vira `''`. O front mostra vazio porque `typeLabel['']` não existe. Ainda assim a linha conta como débito pelo achado 5. | Diagnosticar com `SELECT id, tenant_id, colaborador_id, data, tipo, minutos, descricao, usuario, criado_em FROM rh_banco_horas WHERE tipo = '' OR tipo IS NULL`. Descobrir a origem (lançamento direto no banco? versão antiga do código? outro endpoint?). Corrigir os dados para `abatimento` na migration **somente após confirmação**, registrando o antes/depois. Validar `tipo` por whitelist no back e rejeitar valores fora dela. |
| 7 | `acao=registrar` sem transação | Dois abatimentos simultâneos leem o mesmo saldo (condição de corrida). | Transação + `SELECT ... FOR UPDATE` na linha do colaborador (ou lock nomeado `GET_LOCK('bh_<tenant>_<colab>')`), recalculando o saldo dentro da transação. |
| 8 | `DELETE` (excluir manual) | Não há permissão específica nem auditoria. Qualquer operador remove um abatimento. | RBAC (módulo `recursos_humanos`, ação `excluir`, com fallback `verificarPermissao`), auditoria (`rbacAuditar` ou log próprio) e motivo obrigatório. |
| 9 | `_bh_garantir_tabela()` com `CREATE TABLE` sem `tenant_id`, duplicado em `api_rh_banco_horas.php` e `rh_ponto_core.php` | Em ambiente novo, a tabela nasce sem `tenant_id` e as queries que filtram por ele quebram. | Mover a estrutura para a migration (com `tenant_id` e índices `(tenant_id, colaborador_id, data)`) e deixar uma função única e idempotente. |

---

### 2. Regra de negócio nova

#### 2.1 Abatimento (folga compensatória) — **permitir exceder o saldo**
- Se `minutos <= saldo_atual`: grava normalmente (comportamento atual).
- Se `minutos > saldo_atual` (inclusive com saldo zero ou já negativo):
  1. **Primeira chamada** sem `confirmar_saldo_negativo`: a API **não grava** e responde **HTTP 409** com
     `{sucesso:false, codigo:'CONFIRMAR_SALDO_NEGATIVO', mensagem:'O abatimento é maior que o saldo. O colaborador ficará com saldo negativo.', dados:{saldo_atual, saldo_atual_fmt, minutos, minutos_fmt, saldo_projetado, saldo_projetado_fmt, excedente, excedente_fmt}}`.
  2. O front abre um **modal de confirmação** (não um `confirm()` nativo) com: saldo atual **06:01**, abatimento **08:00**, saldo após o lançamento **-01:59** em vermelho e o texto "O colaborador ficará devendo 01:59, que serão compensadas por futuros créditos". Há também o campo **Justificativa da compensação antecipada** (obrigatório, mínimo 10 caracteres) e o checkbox "Confirmo o lançamento com saldo negativo".
  3. **Segunda chamada** com `confirmar_saldo_negativo:true` + `justificativa_saldo_negativo`: grava o lançamento.
- Limite opcional configurável (não bloquear por padrão): `limite_saldo_negativo_min` na escala do colaborador (`rh_escalas`) ou na configuração do RH do tenant. `NULL` = sem limite. Se configurado e o `saldo_projetado` ultrapassar o limite → 422 com mensagem clara. Exponha o campo na tela da escala, ao lado de "Banco de horas ativo".
- Os créditos automáticos seguintes (`_bh_sincronizar`) abatem naturalmente o negativo, sem nenhuma mudança na lógica de crédito.

#### 2.2 Pagamento (monetário) — **continua limitado**
- Não se pagam horas que o colaborador não tem. Mantenha o bloqueio `minutos > saldo_atual` **apenas** para `pagamento`, com a mensagem "Pagamento maior que o saldo disponível (06:01). Para compensar com folga, use Abatimento."
- Documente essa decisão no `BUSINESS_RULES.md`. Se o negócio quiser outra regra, deve ser configurável, não hardcoded.

#### 2.3 Auditoria do lançamento
Adicione colunas (migration idempotente via `INFORMATION_SCHEMA`) em `rh_banco_horas`:
`saldo_anterior_min INT NULL`, `saldo_posterior_min INT NULL`, `saldo_negativo_confirmado TINYINT(1) NOT NULL DEFAULT 0`, `justificativa_saldo_negativo VARCHAR(500) NULL`, `usuario_id INT NULL` (hoje só há `usuario` em texto), `ip VARCHAR(45) NULL`. Preencha-as no `registrar`. No extrato, mostre um ícone ⚠ com tooltip "Lançado com saldo negativo confirmado por <usuário> — <justificativa>".

---

### 3. Frontend (`recursos_humanos.js` / `recursos_humanos.html`)
1. **Prévia em tempo real** no painel "Registrar Abatimento / Pagamento": ao digitar minutos, mostre abaixo "Saldo atual 06:01 → após lançamento **-01:59**" (verde se ≥ 0, vermelho se < 0). Use o `saldo_global` já retornado pelo relatório. A confirmação definitiva vem do back (409).
2. Campo de duração aceitando **HH:MM ou minutos** (ex.: `8:00` → 480), com conversão e validação, mantendo `minutos` no payload.
3. Tratar o `409 CONFIRMAR_SALDO_NEGATIVO`: abrir o modal descrito em §2.1, reenviar com a confirmação e, no sucesso, recarregar `_gerarRelatorio('banco_horas')`.
4. Quando o tipo for `pagamento` e exceder o saldo, desabilitar o botão "Registrar" e mostrar a dica de usar Abatimento.
5. Card **SALDO ATUAL**: quando negativo, exibir "Saldo devedor -01:59" em vermelho, e não apenas o número.
6. `typeLabel`/`typeColor`: tratar tipo desconhecido como "Não classificado" com destaque cinza e tooltip "Lançamento com tipo inválido — verificar". Nunca exibir vazio.
7. Botão "Registrar" com estado de carregamento e bloqueio de duplo clique; enviar `idempotency_key` para evitar lançamento duplicado.

---

### 4. API final (`api_rh_banco_horas.php`)
- `GET extrato` / `GET saldo`: com tenant e validação do colaborador, usando o helper central.
- `POST registrar`: validações → lock/transação → saldo dentro da transação → regras da §2 → INSERT com `tenant_id` e campos de auditoria → commit → retorno `{id, saldo_anterior_fmt, saldo_posterior_fmt}`.
- `DELETE`: tenant + RBAC + motivo + auditoria. Proibido excluir lançamentos automáticos (`lancamento_id IS NOT NULL`), como hoje.
- Ajustar `api_rh_relatorios.php` (`banco_horas`) e `api_rh_relatorio_pdf.php` para usar o mesmo helper e o filtro de tenant. O PDF deve mostrar saldo negativo e a marcação de "saldo negativo confirmado".

---

### 5. Testes obrigatórios (registrar em `AI-CONTEXT/TESTING.md`)
1. Saldo 06:01 + abatimento 480 sem confirmação → 409 com `saldo_projetado = -119` (‑01:59) e nada gravado.
2. O mesmo lançamento com confirmação + justificativa → gravado, extrato mostra -01:59 em vermelho, auditoria preenchida.
3. Confirmação sem justificativa → 422.
4. Abatimento ≤ saldo → grava direto, sem modal (regressão).
5. Pagamento > saldo → bloqueado com a mensagem nova; pagamento ≤ saldo → grava.
6. Limite negativo configurado (ex.: 600 min) e saldo projetado de -700 → 422; sem limite → aceita.
7. Crédito automático posterior (recalcular um dia com hora extra) reduz o negativo corretamente.
8. Multi-tenant: lançamento do tenant 2 grava `tenant_id = 2`; extrato e relatório do tenant 1 não mostram linhas do tenant 2; colaborador de outro tenant → 404.
9. Concorrência: dois `registrar` simultâneos não usam o mesmo saldo (verificar o saldo final).
10. Linha com `tipo = ''` identificada, origem documentada, dados corrigidos com backup do antes.
11. Excluir lançamento manual exige permissão e motivo e gera log; lançamento automático não é excluível.
12. Migration roda duas vezes sem erro.

---

### 6. Documentação
Atualize `CHANGELOG.md`, `DATABASE.md` (novas colunas, `tenant_id` obrigatório), `API.md` (código 409 `CONFIRMAR_SALDO_NEGATIVO`, payload novo), `BUSINESS_RULES.md` (abatimento pode negativar com confirmação; pagamento não), `SECURITY.md` (correção de vazamento entre tenants no banco de horas) e `TESTING.md`.

### 7. Entrega
Liste os arquivos alterados, a migration, o resultado de cada teste, a origem da linha com tipo vazio e as pendências. Não faça commit sem autorização.

## FIM DO PROMPT
