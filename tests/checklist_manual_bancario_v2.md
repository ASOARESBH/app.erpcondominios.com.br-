# Checklist de Testes Manuais — Banking Module v2

Execute os testes abaixo após rodar o diagnóstico automatizado (`/tests/diagnostico_bancario_v2.php`) com 0 falhas.

---

## Pré-condições

- [ ] `sql/migration_contas_bancarias_v2.sql` executado com sucesso
- [ ] Diagnóstico automatizado: **100% passou**
- [ ] Modificações ETAPA 3 aplicadas em todos os arquivos
- [ ] Upload dos 8 arquivos ETAPA 5 concluído
- [ ] Pelo menos 1 conta bancária cadastrada no sistema

---

## 1. Módulo Financeiro — Dashboard

| # | Ação | Esperado |
|---|------|----------|
| 1.1 | Acessar Financeiro no menu | Página carrega sem erros |
| 1.2 | Observar KPIs do dashboard | Saldo Total, Entradas/Saídas do mês exibem valores reais (não "R$ 0,00" fixo) |
| 1.3 | Inspecionar DevTools → Network | Requisição para `api_contas_bancarias.php?acao=dashboard_financeiro` com status 200 |

---

## 2. Contas Bancárias — Aba Conciliação

| # | Ação | Esperado |
|---|------|----------|
| 2.1 | Ir em Financeiro → Contas Bancárias | Página carrega; aparece aba "Conciliação" na barra de tabs |
| 2.2 | Clicar na aba Conciliação | Painel de pendentes carrega; spinner desaparece |
| 2.3 | Verificar filtros | Selects de tipo e datas presentes; campo de busca funciona |

---

## 3. Lançamento Manual — Campos Novos

| # | Ação | Esperado |
|---|------|----------|
| 3.1 | Clicar em "+ Nova Movimentação" | Modal abre com campos: Favorecido, Nº Documento, Centro de Custo, Status |
| 3.2 | Preencher todos os campos e salvar | Movimentação salva e aparece na listagem |
| 3.3 | Verificar no banco | `SELECT favorecido, numero_documento, centro_custo, status FROM movimentacoes_bancarias ORDER BY id DESC LIMIT 1` — valores corretos |

---

## 4. Importação OFX — Formato SGML (Bradesco, BB, Caixa, Santander)

| # | Ação | Esperado |
|---|------|----------|
| 4.1 | Ir em Contas Bancárias → Importar OFX | Modal de importação abre |
| 4.2 | Fazer upload de arquivo OFX do Bradesco/BB | Importação conclui sem erro |
| 4.3 | Verificar resultado | Contador "Importadas / Duplicadas / Pendentes conciliação" exibido; `formato_ofx = 'sgml'` na tabela `historico_importacoes_ofx` |
| 4.4 | Re-importar o mesmo arquivo | Nenhuma duplicata inserida (contador duplicadas > 0) |

---

## 5. Importação OFX — Formato XML (Itaú, Nubank)

| # | Ação | Esperado |
|---|------|----------|
| 5.1 | Upload de arquivo OFX do Itaú/Nubank | Importação conclui sem erro |
| 5.2 | Verificar resultado | `formato_ofx = 'xml'` em `historico_importacoes_ofx` |
| 5.3 | Verificar campos preenchidos | `SELECT favorecido, memo, fitid FROM movimentacoes_bancarias ORDER BY id DESC LIMIT 5` — campos populados |

---

## 6. Conciliação Automática (via Importação OFX)

| # | Ação | Esperado |
|---|------|----------|
| 6.1 | Criar um título em Contas a Receber com valor **exato** e data coincidente com uma transação OFX | — |
| 6.2 | Importar o OFX com a transação correspondente | Campo `conciliadas_auto` > 0 no resultado; título marcado como `RECEBIDO` |
| 6.3 | Verificar banco | `SELECT status, conciliacao_id FROM movimentacoes_bancarias WHERE fitid = '...'` → status='conciliado' |
| 6.4 | Verificar `conciliacoes` | 1 registro com `tipo_conciliacao='automatica'` e `ativa=1` |

---

## 7. Página Conciliação Bancária

| # | Ação | Esperado |
|---|------|----------|
| 7.1 | Navegar via menu: Financeiro → Conciliação Bancária | Página carrega; AppRouter aplica CSS `.conc-*` |
| 7.2 | KPIs: Pendentes, Conciliados, Auto-conciliados, Taxa%, Ignorados | Todos exibem valores numéricos |
| 7.3 | Barra de progresso de taxa | Preenchida proporcionalmente à taxa de conciliação |
| 7.4 | Aba Pendentes | Lista movimentações com `status = 'pendente'`; botão "Vincular" visível |
| 7.5 | Clicar em "Vincular" em uma pendência | Modal "Candidatos à Conciliação" abre com seções A Receber e A Pagar |
| 7.6 | Clicar em "Vincular" em um candidato | Conciliação registrada; movimentação sai da aba Pendentes; KPIs atualizam |
| 7.7 | Aba Conciliadas | Mostra a movimentação recém-conciliada com badge `manual`, score e nome do título |
| 7.8 | Botão "Desfazer" | Conciliação desfeita; movimentação volta para Pendentes; título volta para PENDENTE |
| 7.9 | Aba Histórico | Exibe ambos os eventos: conciliação e desfazimento |
| 7.10 | Botão "Ignorar" em uma pendência | Movimentação recebe status `ignorado`; sai da lista de pendentes |

---

## 8. Página Relatórios Bancários

| # | Ação | Esperado |
|---|------|----------|
| 8.1 | Navegar: Financeiro → Relatórios Bancários | Página carrega; CSS `.rb-*` aplicado |
| 8.2 | Aba Extrato (padrão) | Tabela exibe movimentações do mês atual; KPIs de totais preenchidos |
| 8.3 | Filtrar por tipo Crédito | Apenas créditos exibidos |
| 8.4 | Busca por texto | Filtro debounce 400ms; resultados atualizados sem reload |
| 8.5 | Botão CSV | Download do arquivo CSV; abre corretamente no Excel com acentos (BOM UTF-8) |
| 8.6 | Aba Fluxo de Caixa | Gráfico de barras Chart.js renderizado; tabela mensal abaixo |
| 8.7 | Alterar seletor de Meses (6/12/18/24) | Gráfico e tabela atualizam |
| 8.8 | Aba DRE Simplificado | Resultado Líquido exibido (verde=positivo, vermelho=negativo) |
| 8.9 | Grid Receitas vs Despesas | Duas colunas lado a lado com totais por categoria |
| 8.10 | Trocar ano no select | DRE recarrega com dados do ano selecionado |
| 8.11 | Trocar conta no seletor de conta | Todas as abas atualizam para a conta selecionada |

---

## 9. RBAC — Controle de Acesso

| # | Ação | Esperado |
|---|------|----------|
| 9.1 | Remover permissão `conciliacao` de um perfil de teste | Usuário não vê item no menu; tentativa de acesso direto redireciona |
| 9.2 | Remover permissão `relatorios_bancarios` | Mesmo comportamento |
| 9.3 | Usuário sem permissão chama API conciliacao diretamente | Retorna `{"sucesso":false,"mensagem":"Acesso negado"}` com HTTP 403 |

---

## 10. Regressão — Funcionalidades Pré-existentes

| # | Ação | Esperado |
|---|------|----------|
| 10.1 | Listar contas bancárias | Continua funcionando |
| 10.2 | Criar/editar/excluir conta | OK |
| 10.3 | Listar movimentações (aba padrão) | OK; nenhuma coluna nova quebrando a query |
| 10.4 | Conciliação manual via aba de movimentações | OK |
| 10.5 | Contas a Receber — fluxo normal | Nenhuma regressão |
| 10.6 | Contas a Pagar — fluxo normal | Nenhuma regressão |
| 10.7 | Menu lateral — posição dos itens | Conciliação e Relatórios Bancários aparecem sob Financeiro |

---

## Critério de Aprovação

- **ETAPA 6 aprovada** quando:
  - Diagnóstico automatizado: 100% passou (ou 0 falhas de schema/lógica)
  - Itens 1–10 acima todos com ✓
  - Nenhum erro no console do browser durante os testes
  - Nenhum erro PHP nos logs (`logs/debug_contas_bancarias.log`)
