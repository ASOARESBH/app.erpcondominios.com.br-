# Fase 2.4 — Definições live das candidatas a views

**Data da tentativa:** 2026-11-06 (tentativa de reconciliação live; resultados não obtidos). **Schema alvo:** `inlaud99_erpcondor`. **Status:** definições live não obtidas.

## Limites da evidência

- A lista abaixo contém as 22 candidatas identificadas e analisadas estaticamente na Fase 2.3; nomes/prefixos não confirmam `TABLE_TYPE=VIEW`.
- O export estrutural validado declara `CREATE TABLE` para esses objetos; isso não comprova que estejam instalados como tabelas, views ou que ainda existam no servidor.
- Não houve resultado do catálogo live nem `SHOW CREATE VIEW`. Nenhum corpo de definição foi inventado, copiado de migrations ou inferido do PHP.
- Para o mapa de dependências estáticas e divergências entre projeções e export, consulte [Fase 2.3 — mapa de dependências](../phase2_3/VIEWS_DEPENDENCY_MAP.md). Aquele documento não substitui a definição live.

## Objetos

### 1. `v_emails_recentes`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 2. `view_dispositivos_ativos`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 3. `view_estatisticas_dispositivos`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 4. `view_estatisticas_tokens`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 5. `view_tokens_ativos`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 6. `vw_alertas_pendentes`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 7. `vw_checklist_completo`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 8. `vw_consumo_por_morador`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 9. `vw_consumo_veiculos`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 10. `vw_dashboard_financeiro`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 11. `vw_dependentes_ativos`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 12. `vw_dependentes_completo`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 13. `vw_estatisticas_veiculo`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 14. `vw_extrato_bancario`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 15. `vw_fluxo_caixa`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 16. `vw_movimentacoes_detalhadas`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 17. `vw_pendencias_conciliacao`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 18. `vw_produtos_estoque_baixo`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 19. `vw_saldo_contas`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 20. `vw_ultimos_abastecimentos`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 21. `vw_validacao_dependente_visitante`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.

### 22. `vw_valor_total_estoque`

- **TABLE_TYPE live:** NÃO CONFIRMADO.
- **Definição live (`SHOW CREATE`):** NÃO OBTIDO.
- **Dependências live:** não confirmadas.
- **Evidência disponível:** análise estática/export documentada na Fase 2.3; não é prova de instalação.
- **Decisão:** não criar, substituir, converter nem liberar tenant-safe com base nesta evidência.
