# Banco de Dados

Banco de dados relacional MySQL hospedado na HostGator.

## 1. Tabelas Principais (Core)
- `moradores`, `dependentes`, `unidades`, `veiculos`, `visitantes`
- `usuarios`, `sessoes_portal`

## 2. Tabelas Financeiras
- `contas_pagar`, `contas_receber`, `movimentacoes_bancarias`
- `conciliacoes`, `historico_importacoes_ofx`

## 3. Tabelas de Operação
- `hidrometros`, `leituras`, `lancamentos_agua`
- `ordens_servico`, `inventario`, `produtos_servicos`

## 4. Tabelas de Integração & Sistema
- `controlid_dispositivos`, `controlid_eventos_acesso`
- `configuracoes`, `logs_sistema`, `email_log`

## Regras Críticas de Banco
- **Soft Delete**: Registros raramente são deletados fisicamente (`DELETE`). Utiliza-se `ativo = 0` ou `status = 'INATIVO'`.
- **Chaves Estrangeiras**: Integridade mantida via código PHP na maioria dos módulos legacy, mas migrando para Constraints nativas do MySQL nas tabelas novas.
- **Timestamps**: Tabelas padronizadas com `criado_em` e `atualizado_em`.


## 5. Snapshots de Inadimplência

As tabelas `inadimplencia_importacoes` e `inadimplencia_lancamentos` preservam snapshots de PDFs BRCondos por `tenant_id`. Todas as leituras devem filtrar `tenant_id`, inclusive quando `importacao_id`, Gleba, CPF ou chave de comparação forem conhecidos. O PDF original é mantido no BLOB de `tenant_arquivos`, por meio de `tenant_arquivo_referencias`, e não deve ser duplicado em diretórios públicos.

A migration compatível com MySQL/MariaDB 5.7 é `sql/migration_inadimplencia_mysql57.sql`. As chaves `chave_comparacao` e `chave_alternativa` são evidências para comparar snapshots; não substituem títulos ou baixas do financeiro operacional.

## 6. Rondas de Vigilante

O módulo de rondas é instalado por `sql/migration_rondas_vigilante_mysql57.sql`, compatível com MySQL/MariaDB 5.7. Todas as tabelas abaixo possuem `tenant_id` e devem ser consultadas com o tenant resolvido no backend pela sessão.

| Tabela | Finalidade | Regras/índices relevantes |
|---|---|---|
| `ronda_rotas` | Agenda de uma rota de ronda | Define horas, intervalo, tolerância, repetições e dias da semana. |
| `ronda_pontos` | Pontos físicos da rota | Possui `token_qr` hexadecimal aleatório, ponto ativo e ordem. O token é segredo operacional e não é devolvido pela API móvel. |
| `ronda_vigilantes` | Vínculo N:N entre rota e `rh_colaboradores` | Só `ativo=1` autoriza a leitura de rota. |
| `ronda_registros` | Leituras efetivamente registradas | Armazena ponto, vigilante, SLA, atraso, GPS opcional e auditoria técnica. O índice `uk_ronda_registro_ponto_ciclo (tenant_id, ponto_id, colaborador_id, ciclo_chave)` impede duplicidade. |
| `ronda_auditoria` | Rastreabilidade administrativa | Registra ação, usuário interno, descrição, dados auxiliares e IP. |

A API móvel calcula `ciclo_chave` como `sha256(tenant_id|rota_id|colaborador_id|YYYYMMDD:indice_do_ciclo)`. O índice de `ronda_registros` inclui também o ponto, de modo que a mesma pessoa pode cumprir pontos distintos da rota no mesmo ciclo, mas não repetir o mesmo ponto. Leituras móveis gravadas nessa tabela já são consumidas pelos dashboards e relatórios administrativos existentes.
