# Relatório de Impacto — Consolidação ERP Serra para ERP Condor

## Escopo analisado

| Indicador | ERP Serra (origem) | ERP Condor (destino) |
|---|---:|---:|
| Tamanho do dump | 34.847.556 bytes | 18.946.379 bytes |
| Tabelas identificadas pelo DDL | 152 | 177 |
| Tabelas comuns | 151 | 151 |
| Tabelas Multi-Tenant no destino | — | 145 |
| Registros aproximados nas tabelas comuns | 59.415 | 67.989 |
| Tabelas com registros aproximados | 102 | 103 |

O ERP Serra é o banco legado sem `tenant_id`. O ERP Condor possui a estrutura Multi-Tenant e o tenant `id = 1` corresponde à mesma empresa pelo CNPJ `28.231.106/0001-15`.

## Cobertura da migration

A migration processa 137 tabelas de negócio comuns e injeta `tenant_id = 1` sempre que a tabela de destino possui essa coluna. As views do legado não são copiadas, pois são estruturas derivadas. O destino contém views e recursos novos próprios da plataforma Multi-Tenant.

As tabelas `usuarios`, `empresa`, `tenants`, `usuario_tenant` e `documentos_usuarios_acesso` recebem tratamento específico. A tabela exclusiva `documentos_usuarios_acesso` é preservada por mapeamento para `documentos_acessos` como evento de visualização interna.

## Exclusões deliberadas

Sessões, tokens, filas de comandos, cache público e tabelas de referência global não são transferidos. Esses dados não representam histórico de negócio consolidável e poderiam invalidar sessões ativas ou duplicar processamento. Todas as exclusões são registradas na tabela `mt_consolidacao_exclusoes` durante a importação.

## Controles de segurança

1. A migration valida que os dois bancos existem no mesmo servidor.
2. A migration exige o tenant `id = 1` no destino.
3. A migration interrompe se houver outros tenants no destino, impedindo associação incorreta de dados.
4. A cópia ocorre em transação e registra uma linha de auditoria para cada tabela concluída.
5. A reexecução não repete tabelas já concluídas, protegendo tabelas sem chave única.
6. O privilégio `super_admin` que já existir no destino não é reduzido durante a consolidação de usuários.

## Limitação importante: arquivos físicos

O SQL não carrega arquivos que estejam fora do banco, como fotos, anexos, documentos, logos e arquivos de upload. Antes de validar a aplicação, é necessário copiar os diretórios equivalentes do legado para o destino, preservando os caminhos usados no banco.

## Ordem obrigatória de execução

1. Fazer backup do `inlaud99_erpcondor`.
2. Importar o dump do legado como banco separado `inlaud99_erpserra`.
3. Executar `pre_validacao_consolidacao_erpserra_erpcondor_mysql57.sql`.
4. Executar `migration_consolidar_erpserra_para_erpcondor_mysql57.sql`.
5. Executar `auditoria_pos_consolidacao_erpserra_erpcondor_mysql57.sql`.
6. Copiar arquivos físicos e validar a aplicação com o tenant 1.
