# Correção do heartbeat do Hub Multimodal

## Sintoma

O agente Windows permanecia em execução e alcançava o Control iD, mas o log registrava:

```text
API HTTP 500 em /api_monitoramento.php?action=heartbeat
codigo: MONITORING_INTERNAL_ERROR
```

O Control iD registrava acessos normalmente; portanto, a conectividade local não era a causa.

## Causa raiz

O heartbeat envia a lista `devices` do Hub Local. Ao persistir um dispositivo em `monitoramento_dispositivos_local`, a API usava uma assinatura `bind_param` com quantidade de tipos incompatível com a quantidade de valores:

- atualização: 12 valores, mas 13 tipos;
- inserção: 13 valores, mas 12 tipos.

O `mysqli` lançava uma exceção, que era convertida pelo dispatcher em `MONITORING_INTERNAL_ERROR`.

## Correção

As assinaturas foram alinhadas ao número real de parâmetros. O dispatcher também passou a gerar um `debug_id` e registrar ação, arquivo e linha no `error_log` sanitizado, sem devolver segredos ao agente.

A correção é compatível com MySQL/MariaDB 5.7 e não exige nova tabela. A migration multimodal continua necessária para a primeira instalação:

```text
sql/migration_monitoramento_hub_multimodal_mysql57.sql
```

## Homologação

Após publicar a API corrigida, reinicie o serviço Windows e confira se o log deixa de registrar `MONITORING_INTERNAL_ERROR`. O resultado esperado é heartbeat aceito, o dispositivo Control iD como `online` e, após uma nova TAG, um evento `source=uhf` na outbox local.
