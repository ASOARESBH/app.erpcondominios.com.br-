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
