# PROMPT DE IMPLEMENTAÇÃO — Banco de Horas: botão "Gerar / Reprocessar" e mapeamento do cálculo

> Gerado em 2026-11-04 com base na análise de `api/rh_ponto_core.php` (`_calcular_minutos`, `_calcular_minutos_par`, `_recalcular_totais`, `_bh_sincronizar`), `api/api_rh_ponto.php` (`salvar_lancamento`, `recalcular_lancamentos`, DSR), `api/api_rh_relatorios.php` (`banco_horas`), `api/api_rh_banco_horas.php`, `frontend/pages/recursos_humanos.html` (filtro `#rel-colaborador-id`, L756) e `frontend/js/pages/recursos_humanos.js`.
> Complementa `PROMPT_BANCO_HORAS_ABATIMENTO_20261104.md` (as correções multi-tenant de lá valem aqui).

## INÍCIO DO PROMPT

Você é o tech lead, engenheiro de segurança e QA do **ERP CONDOMÍNIOS**. O banco de horas do RH está com créditos menores que as horas extras apuradas no espelho de ponto. Implemente:
1. a **correção da regra de cálculo** do crédito;
2. um **mapeamento dia a dia** (espelho × banco) que deixe a origem de cada minuto auditável;
3. um botão **"Gerar"** ao lado do filtro **Colaborador (espelho / banco)** em Relatórios do RH. O botão reprocessa o banco de horas do período com a regra correta, com prévia antes de aplicar.

O código deve ficar pronto para produção, sem gambiarras.

### 0. Regras obrigatórias
1. Leia antes: `AI-CONTEXT/INDEX.md`, `SKILL.md`, `API.md`, `DATABASE.md`, `BUSINESS_RULES.md`, `SECURITY.md`, `MULTITENANT_ARCHITECTURE.md`, `FRONTEND.md`, `PROMPT_BANCO_HORAS_ABATIMENTO_20261104.md` e este arquivo.
2. ANALISAR → ENTENDER → IDENTIFICAR CAUSA → PLANEJAR → IMPLEMENTAR → TESTAR → DOCUMENTAR → ATUALIZAR AI-CONTEXT. Apresente o plano antes de codar.
3. `tenant_id` sempre da sessão, em todo SELECT, INSERT, UPDATE e DELETE de `rh_banco_horas`, `rh_ponto_lancamento` e `rh_ponto_periodo`. Use prepared statements e resposta `{sucesso, mensagem, dados}` com o código HTTP correto.
4. Vanilla JS (ES Module), `_esc()` em toda saída. Não edite `*_backup*`, `*.bak`, `teste_*`, `debug_*`.
5. Migrations idempotentes e compatíveis com MySQL 5.7, em `sql/migration_rh_banco_horas_mapeamento_mysql57.sql`.
6. **Nunca** apague nem altere lançamentos manuais (`lancamento_id IS NULL`: abatimento/pagamento). O reprocessamento só mexe nos automáticos (`credito`/`debito` com `lancamento_id`).

---

### 1. Causa raiz (confirmada no código e nos dados do usuário)

**Caso real — WANDERSON NUNES MOREIRA, outubro/2026:**
- Espelho de 17/10/2026 (sábado): 07:00–09:00 / 09:01–14:18 → **trabalhado 07:17, extra 03:17**.
- Banco de horas de 17/10/2026: **Crédito Extra +02:00**.
- Espelho do mês: **Horas Extras 07:09**. Créditos no banco em outubro: 05/10 +02:00, 09/10 +00:47, 17/10 +02:00 = **04:47** → **02:22 não foram creditadas**. Isso indica que 05/10 também foi cortado em 2 h; confirme pelo mapeamento.

**Causa:** `api/rh_ponto_core.php`, função `_bh_sincronizar()`, linhas 401–407:
```php
// Crédito: horas extras líquidas (após abono) — CLT Art. 59 §2°: máximo 2h/dia (120 min)
$linhas[] = ['credito', min($extraLiquido, 120), 'Horas extras automáticas'];
```
O limite legal de 2 h/dia de prorrogação foi implementado como **descarte silencioso** do excedente. A hora trabalhada a mais existe e precisa ser registrada (creditada ou paga). O espelho (`_calcular_minutos` / `_recalcular_totais`) apura corretamente (03:17); só o banco corta.

**Fator agravante ("travado no cálculo antigo"):** o banco só é ressincronizado quando o lançamento é salvo (`salvar_lancamento`) ou quando o período é recalculado no espelho (`recalcular_lancamentos`). Créditos gerados por versões anteriores da regra ficam congelados. Não existe hoje um reprocessamento por colaborador/departamento e período a partir da tela de relatórios.

Achados correlatos para corrigir junto:
- `_bh_sincronizar()` faz `DELETE ... WHERE lancamento_id = $id` e `INSERT` **sem `tenant_id`** (cai no `DEFAULT 1`).
- `recalcular_lancamentos` lê `rh_ponto_periodo` sem filtro de tenant (`SELECT colaborador_id FROM rh_ponto_periodo WHERE id=$periodo_id`).
- O espelho tem um recálculo por período (botão "Recalcular"), mas o relatório de banco não indica se o banco está desatualizado em relação ao espelho.

---

### 2. Regra de cálculo nova (configurável, sem descarte silencioso)

Crie a política `politica_extra_excedente` na escala (`rh_escalas`) ou na configuração do RH do tenant, com padrão `CREDITAR_TUDO`:
- `CREDITAR_TUDO` (**padrão**): crédito = extra líquido integral (03:17 em 17/10).
- `LIMITAR_E_SEPARAR`: crédito no banco até `limite_extra_diario_min` (padrão 120). O excedente é gravado como `tipo='excedente'`, que **não entra no saldo** e aparece no relatório para o RH decidir pagamento. Nunca é descartado.
- `limite_extra_diario_min` configurável (padrão 120), usado também para **sinalizar** no mapeamento e no espelho os dias acima do limite legal (alerta visual, sem cortar).

Implemente a regra em uma única função pura, `bh_apurar_dia(array $calc, array $abono, array $escala, string $data): array`, que retorna:
`{extra_bruto, extra_abonado, extra_liquido, limite_dia, credito, excedente, atraso_liquido, saida_antecipada, falta_min, debito_total, regra_aplicada, alertas[]}`.
Use essa função em `_bh_sincronizar`, no reprocessamento e no mapeamento, para que os três nunca divirjam. O cálculo de débitos (atraso, saída antecipada, falta, DSR perdido) permanece igual; apenas passa a ser feito por essa função.

Novas colunas em `rh_banco_horas` (migration via `INFORMATION_SCHEMA`):
`minutos_apurados INT NULL`, `limite_aplicado_min INT NULL`, `regra_aplicada VARCHAR(40) NULL`, `versao_calculo VARCHAR(20) NULL` (ex.: `'2026.11'`), `reprocessamento_id INT NULL`.
Se usar `LIMITAR_E_SEPARAR`, adicione `'excedente'` ao ENUM `tipo` e garanta que **todos** os cálculos de saldo (helper central do prompt de abatimento) ignorem `excedente`.

---

### 3. Mapeamento espelho × banco (`acao=mapear`)

Endpoint `GET api/api_rh_banco_horas.php?acao=mapear&colaborador_id=&data_inicio=&data_fim=` (ou `mes`/`ano`, igual aos filtros do relatório). Retorna, por dia:

| Campo | Origem |
|---|---|
| data, dia_semana, tipo_dia, batidas | `rh_ponto_lancamento` |
| carga_prevista | `_obter_parametros_jornada_dia()` / escala |
| trabalhado, extra_espelho, atraso_espelho | valores gravados no lançamento |
| abonos | `abono_*` |
| credito_esperado, debito_esperado, excedente | `bh_apurar_dia()` com a regra atual |
| credito_atual, debito_atual | soma em `rh_banco_horas` (automáticos daquele `lancamento_id`) |
| diferenca | esperado − atual |
| status | `OK` / `DIVERGENTE` / `SEM_BANCO` (dia com extra/atraso e nenhum lançamento no banco) / `PERIODO_FECHADO` / `SEM_ESCALA_BH` |

Também retorna os totais do período (espelho × banco atual × banco esperado) e `saldo_atual` / `saldo_apos_reprocessar`.

---

### 4. Botão "Gerar" no filtro (Relatórios do RH)

**Local:** `frontend/pages/recursos_humanos.html`, ao lado do `<select id="rel-colaborador-id">` (L756–757), na mesma linha de Mês / Ano / Departamento / Colaborador.
- Botão `btn-primary-modern` **"⟳ Gerar"** (tooltip: "Reprocessar o banco de horas do período com a regra atual"). Fica habilitado quando há mês/ano ou período personalizado válido **e** um colaborador ou um departamento selecionado. Sem colaborador → reprocessa todos os colaboradores ativos do departamento (com banco de horas ativo na escala).
- Respeita o modo **Por Mês** / **Período Personalizado** já existente.
- Visível apenas para quem tem permissão (RBAC módulo `recursos_humanos`, ação `editar` ou nova `reprocessar_banco`; fallback `verificarPermissao('admin')`).

**Fluxo em 2 etapas (nunca aplicar direto):**
1. **Prévia** (`POST ?acao=reprocessar&modo=simular`): abre um modal grande com o mapeamento da §3. Mostra os dias divergentes em destaque (ex.: `17/10 — Crédito atual +02:00 → +03:17 (diferença +01:17)`), os totais "Banco atual 06:01 → Banco após reprocessar 08:23", alertas (dias acima do limite legal, períodos fechados, dias sem escala) e o filtro "mostrar só divergências".
2. **Aplicar** (`POST ?acao=reprocessar&modo=aplicar`, com o `hash_previa` devolvido pela simulação): checkbox "Revisei as diferenças" + motivo obrigatório. O back recalcula e confere se o hash ainda bate (o espelho pode ter mudado entre a prévia e a aplicação → 409, refazer a prévia).

**Execução no back (`modo=aplicar`):**
- Para cada colaborador e para cada dia do período: recalcula `_calcular_minutos` / `_calcular_minutos_par` (mesma lógica de `recalcular_lancamentos`, **extraída para uma função reutilizável**, sem copiar código), atualiza `rh_ponto_lancamento`, chama `_recalcular_totais` e regera os automáticos via `bh_apurar_dia()`.
- Reaplica o DSR da semana (função existente em `api_rh_ponto.php`, extraída para o core).
- **Períodos fechados:** por padrão **não** altera e lista como "ignorado — período fechado". Opção explícita "incluir períodos fechados" só para administrador, gerando log de reabertura implícita.
- Transação por colaborador; lock por colaborador (`GET_LOCK('bh_reproc_<tenant>_<colab>')`); idempotente (rodar 2× não duplica).
- Gravar em uma nova tabela `rh_banco_horas_reprocessamentos` (`id, tenant_id, usuario_id, escopo (colaborador/departamento), colaborador_id, departamento_id, data_inicio, data_fim, regra, versao_calculo, motivo, totais_antes_json, totais_depois_json, dias_alterados, ignorados_json, status, iniciado_em, concluido_em`), e o `reprocessamento_id` nas linhas regeradas.
- Departamento grande: processar em lotes (ex.: 20 colaboradores por requisição, com progresso no modal) para não estourar o `max_execution_time`.

Depois de aplicar: recarregar automaticamente o relatório "Banco de Horas" e mostrar o toast "Banco reprocessado: 3 dias ajustados, +02:22".

---

### 5. Melhorias de UX no relatório de Banco de Horas
- Selo **"Banco desatualizado"** (amarelo) no topo do relatório quando o mapeamento detectar divergência, com atalho para o botão Gerar.
- Na tabela do extrato, coluna/tooltip "Apurado 03:17 · Regra CREDITAR_TUDO · v2026.11" para créditos automáticos.
- No espelho, ícone ⚠ nos dias com extra acima de `limite_extra_diario_min` (informativo, sem cortar).
- Histórico de reprocessamentos (quem, quando, período, totais antes/depois) acessível pelo relatório.

---

### 6. Testes obrigatórios (registrar em `AI-CONTEXT/TESTING.md`)
1. **Caso Wanderson, 17/10/2026:** prévia mostra crédito atual 02:00 → esperado 03:17; após aplicar, o extrato mostra +03:17.
2. Outubro/2026 do Wanderson: total de créditos do banco = horas extras do espelho (07:09), descontados abonos; saldo final recalculado corretamente.
3. `LIMITAR_E_SEPARAR`: 17/10 gera crédito 02:00 + excedente 01:17 (fora do saldo, visível no relatório).
4. Abatimentos/pagamentos manuais permanecem intactos após o reprocessamento.
5. Rodar "Aplicar" 2× → resultado idêntico, sem duplicação.
6. Período fechado é ignorado por padrão e listado na prévia.
7. Espelho alterado entre a prévia e a aplicação → 409 (hash divergente).
8. Departamento com N colaboradores processado em lotes, com progresso e totais corretos.
9. Multi-tenant: reprocessar no tenant 2 não toca linhas do tenant 1; linhas regeradas gravam o `tenant_id` correto.
10. Débitos (atraso, saída antecipada, falta, DSR perdido) iguais antes/depois para dias sem mudança de regra (regressão).
11. Escala 12x36 (`_calcular_minutos_par`) e virada de meia-noite continuam corretas.
12. Usuário sem permissão não vê o botão e recebe 403 na API.

---

### 7. Documentação
Atualize `CHANGELOG.md`, `BUSINESS_RULES.md` (regra de crédito, política do excedente, limite legal como alerta e não como descarte), `DATABASE.md` (colunas novas, tabela de reprocessamentos, ENUM), `API.md` (`mapear`, `reprocessar` simular/aplicar), `FRONTEND.md` (botão Gerar, modal de prévia) e `TESTING.md`. Registre a decisão **"o limite de 2 h/dia (CLT art. 59) é controle de jornada; não autoriza descartar horas efetivamente trabalhadas"** e que a política deve ser validada com o RH/contabilidade do condomínio.

### 8. Entrega
Liste os arquivos alterados, a migration, o resultado de cada teste (com os números do caso Wanderson antes/depois) e as pendências. Não faça commit sem autorização.

## FIM DO PROMPT
