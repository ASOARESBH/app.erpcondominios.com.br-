# Estratégia de Consolidação — ERP Serra para ERP Condor

## Premissas confirmadas

O dump legado `inlaud99_erpserra` possui 152 tabelas e não possui colunas `tenant_id`. O dump `inlaud99_erpcondor` possui 177 tabelas, incluindo `tenants` e `usuario_tenant`; 145 das 151 tabelas físicas comuns possuem `tenant_id` no destino.

O cadastro de empresa do legado corresponde ao tenant `id = 1` do destino, com o mesmo CNPJ `28.231.106/0001-15`. Portanto, todos os registros do legado devem ser consolidados em `tenant_id = 1`.

## Método de migração

A migration será executada somente depois de os dois bancos coexistirem no mesmo servidor MySQL:

- origem: `inlaud99_erpserra`;
- destino: `inlaud99_erpcondor`.

Ela percorre exclusivamente tabelas físicas existentes nos dois bancos. Views não são copiadas porque o destino já possui as views atualizadas da arquitetura Multi-Tenant.

Para cada tabela elegível, o script usa as colunas que existem em ambos os lados. Quando a tabela de destino contém `tenant_id`, a migration insere o valor `1`; não usa nem copia valores de tenant da origem.

A operação é controlada pela tabela `mt_consolidacao_tabelas`, impedindo duplicação em reexecuções. Tabelas concluídas são registradas somente após a respectiva cópia. O script preserva IDs e, quando existe chave única/primária coincidente, atualiza o registro de destino com o dado do legado — a origem é o retrato mais recente do condomínio Serra da Liberdade.

## Tratamentos especiais

| Recurso | Tratamento |
|---|---|
| `tenants` | Não existe na origem. O cadastro existente no destino `id = 1` é preservado e sincronizado a partir de `empresa` do legado. |
| `empresa` | Copiada manualmente para o tenant `1`, incluindo endereço, contatos e logo. |
| `usuarios` | Copiados para o tenant `1`; a permissão `super_admin` já existente no destino nunca é reduzida. |
| `usuario_tenant` | Reconstruída a partir de `usuarios` após a importação. |
| `documentos_usuarios_acesso` | Não existe no destino. Seus registros são mapeados para `documentos_acessos` como visualizações internas, sem eliminar o histórico. |
| Sessões, tokens e caches | Não são migrados para tabelas operacionais por segurança; o dump de origem continua sendo o arquivo histórico e a migration registra as exclusões. |
| Arquivos físicos | Logos, anexos, fotos e documentos armazenados no sistema de arquivos não fazem parte do SQL. Devem ser copiados separadamente para o diretório de uploads antes da validação final. |

## Regras obrigatórias de execução

1. Gerar backup completo do banco `inlaud99_erpcondor` antes da consolidação.
2. Importar o dump legado como banco separado `inlaud99_erpserra`; não importar o dump dentro do banco de destino.
3. Executar primeiro o script de pré-validação.
4. Executar a migration de consolidação apenas com ambos os bancos presentes.
5. Executar a auditoria de contagens e de tenant após a migration.
6. Só depois validar a aplicação e, por último, remover o banco legado se houver backup arquivado e validação aprovada.
