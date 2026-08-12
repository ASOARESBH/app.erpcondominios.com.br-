# Relatório de Duplicidades — Reconstrução do Tenant 1

## Causa raiz

A migration de consolidação anterior utilizou `INSERT ... ON DUPLICATE KEY UPDATE` esperando que o campo `id` de cada tabela disparasse conflitos. Entretanto, diversas tabelas operacionais do banco de destino possuem apenas índices de `tenant_id` e não possuem chave primária ou chave única sobre `id` no dump operacional.

Sem uma chave única, o MySQL/MariaDB não encontra duplicidade e insere uma nova linha. Como o ERP Condor já continha uma cópia histórica do mesmo condomínio, a importação do ERP Serra duplicou registros que existiam nas duas bases.

## Evidências nos dumps

| Tabela | Origem ERP Serra | Base ERP Condor antes | ERP Condor atual | Duplicatas exatas estimadas |
|---|---:|---:|---:|---:|
| `registros_acesso` | 25.159 | 24.419 | 48.887 | 23.909 |
| `leituras` | 1.101 | 985 | 2.086 | 985 |
| `protocolos` | 725 | 645 | 1.370 | 644 |
| `unidades` | 188 | 188 | 376 | 188 |
| `moradores` | 178 | 180 | 366 | 176 |
| `hidrometros` | 142 | 142 | 284 | 142 |
| `inventario` | 205 | 205 | 410 | 205 |

O padrão mostra que a base atual contém a soma da cópia anterior com os dados trazidos do legado.

## Estratégia aprovada para preservação da origem

O ERP Serra passa a ser a fonte definitiva dos dados de negócio do tenant 1. A correção não tentará escolher uma linha por vez dentro do banco duplicado. Em vez disso, ela fará uma reconstrução controlada:

1. Validar que só existe o tenant 1 no destino.
2. Desabilitar temporariamente chaves estrangeiras.
3. Limpar dados de negócio do tenant 1 no ERP Condor.
4. Reimportar uma única cópia de cada registro do ERP Serra para o tenant 1.
5. Preservar apenas o usuário de plataforma `admin@erpcondominios.com.br` como exceção necessária para administração global.
6. Reconstruir `usuario_tenant` e sincronizar `tenants` com `empresa`.
7. Executar a auditoria pós-reconstrução.

## Segurança e reversão

A limpeza só pode ser executada após exportar o banco atual `inlaud99_erpcondor` no phpMyAdmin. O dump atual `inlaud99_erpcondor(3).sql` já funciona como referência técnica, mas deve ser gerado um backup novo imediatamente antes da execução em produção.

A migration de reconstrução será bloqueada se houver mais de um tenant no destino, evitando remover registros de outros condomínios.
