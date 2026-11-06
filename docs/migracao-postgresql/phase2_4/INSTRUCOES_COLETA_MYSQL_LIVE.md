# Coleta incremental do catálogo MySQL live

**Origem:** HostGator / MySQL-Percona, schema solicitado `inlaud99_erpcondor`.
**Data de preparação:** 2026-11-06.
**Arquivo SQL:** [MYSQL_LIVE_CATALOG_COLLECTION.sql](MYSQL_LIVE_CATALOG_COLLECTION.sql).
**Uso:** coleta assistida, exclusivamente read-only. Nenhuma consulta deste pacote foi executada por esta entrega.

## Segurança e preparação

1. Entre no phpMyAdmin pelo fluxo normal do administrador. Não envie senha, cookie, token ou URL de sessão para o chat.
2. Selecione o banco `inlaud99_erpcondor` e execute primeiro **somente o Bloco 01**. Confirme que `selected_schema` é exatamente `inlaud99_erpcondor`. Se não for, pare; o arquivo SQL fixa o schema solicitado e não deve ser reutilizado em outro banco sem autorização.
3. Abra o arquivo SQL localmente e copie **um bloco de cada vez** para o editor SQL. Não cole nem execute o arquivo inteiro como lote. Cada instrução SELECT tem schema explícito e não depende de uma consulta anterior. Os exemplos `SHOW CREATE` estão comentados e devem ser executados individualmente, somente depois da confirmação do tipo/nome do objeto.
4. A interface deve executar as consultas como SQL normal/read-only; não use Export Data nem opção que inclua registros das tabelas. Não use `SELECT *`, não abra a aba Browse/Visualizar de tabelas de negócio e não execute o texto retornado por `SHOW CREATE`.
5. As consultas de listas usam páginas ordenadas com `LIMIT`/`OFFSET`. Para obter o conjunto completo, repita a mesma consulta alterando apenas o `OFFSET`: por exemplo, 0, 50, 100 para páginas de 50; ou 0, 100, 200 para páginas de 100. Pare ao receber página vazia. Registre o intervalo de cada página para evitar lacunas ou duplicatas. Não aumente os limites sem necessidade.
6. Se aparecer timeout, falta de privilégio, campo inexistente, resultado truncado ou resultado vazio inesperado, registre o bloco, mensagem curta e timestamp como **NÃO CONFIRMADO**; não repita em sequência nem conclua que o objeto inexiste. Passe ao próximo bloco leve ou interrompa e solicite orientação.

## Blocos e arquivos de saída

Execute somente os blocos necessários, individualmente. Salve cada resultado em arquivo separado, com o número/nome do bloco no arquivo. No phpMyAdmin, prefira **Exportar resultado** em CSV para resultados tabulares; para definições de objetos, salve como TXT/SQL em pasta privada. Não grave arquivos em diretório público do site.

| Bloco | Conteúdo | Nome sugerido | Observações |
|---|---|---|---|
| 01 | Servidor e schema selecionado | `01_server_schema.txt` | Uma linha; confirme schema antes de prosseguir. |
| 02 | Nomes e tipo (`BASE TABLE`/`VIEW`) | `02_tables_views_p000.csv` | Páginas de 50; total live ainda desconhecido. |
| 03 | Engine e collation por objeto | `03_engines_collations_p000.csv` | Páginas de 50. |
| 04 | Colunas, tipos, dimensões, nullabilidade e geração | `04_columns_p000.csv` | Páginas de 100; não retorna literais de defaults, apenas informa se o campo é NULL/sem default ou possui default não nulo. Labels ENUM/SET exigem revisão/redação antes de compartilhar. |
| 05 | Primary keys e ordem das colunas | `05_primary_keys_p000.csv` | Páginas de 100. |
| 06 | Índices simples/compostos, unicidade, prefixo e ordem | `06_indexes_p000.csv` | Páginas de 100; não coleta cardinalidade. |
| 07 | Foreign keys e regras referenciais | `07_foreign_keys_p000.csv` | Páginas de 100. |
| 08 | Triggers, evento/timing/ordem e corpo | `08_triggers.csv` | Execute a consulta uma vez; revisar corpo antes de salvar/compartilhar. |
| 09 | Views e definição | `09_views_p000.txt` | Páginas de até 20; revisar definições antes de salvar/compartilhar. |
| 10A | Procedures/functions e definição | `10a_routines_p000.txt` | Páginas de até 50; `ROUTINE_DEFINITION` pode ser NULL por privilégio. |
| 10B | Events, agenda e definição | `10b_events_p000.txt` | Páginas de até 50; `EVENT_DEFINITION` pode ser NULL por privilégio. |

`p000` representa a página com `OFFSET 0`; use `p050`, `p100`, etc., ao salvar páginas subsequentes. Os blocos 08–10 retornam código-fonte de objetos, não executam o código.

## Como exportar pelo phpMyAdmin

- Depois de executar um bloco, use os controles de **Exportar** do próprio resultado, não o Exportar do banco inteiro.
- Para resultados tabulares, escolha CSV, UTF-8 e mantenha cabeçalhos. Não inclua tabelas/dados além do resultado corrente.
- Para os blocos 08–10, use TXT ou copie a grade/resultset para um arquivo `.txt` local. Preserve nomes, evento/timing, ordem e texto da definição para revisão técnica.
- Confirme que o arquivo salvo corresponde ao bloco e ao intervalo executado. Registre data/hora (fuso America/Sao_Paulo), schema, paginação e checksum SHA-256 em um `README.txt` local.
- Mantenha arquivos originais em pasta privada e com acesso restrito. Não os coloque em pasta pública do HostGator ou repositório aberto.

## Revisão de privacidade antes de compartilhar

As consultas não leem linhas de moradores, visitantes ou operações. Ainda assim, os nomes/labels de colunas e o código-fonte de views, triggers e rotinas podem conter literais ou referências sensíveis:

- O Bloco 04 não retorna literais de `COLUMN_DEFAULT`; mostra somente `NULL_OR_NO_DEFAULT` ou `NON_NULL_DEFAULT_PRESENT`. `COLUMN_TYPE` pode expor rótulos declarados de `ENUM`/`SET`.
- Antes de salvar ou compartilhar os blocos 08–10, revise localmente cada corpo. **Não encaminhe** definições que contenham senha, hash, token, chave, segredo, documento pessoal, e-mail/telefone pessoal ou valor confidencial embutido. Redija o literal e registre que houve redação; se não puder redigir com segurança, compartilhe apenas metadados do objeto e informe que a definição foi retida.
- Nunca compartilhe credenciais, sessão do phpMyAdmin, cookie, URL com token ou dump de dados.
- Preferencialmente disponibilize somente os arquivos desta coleta, após revisão. Não envie arquivos completos de backup/dump.

## Comandos `SHOW CREATE`

O Bloco 09 e os Blocos 10A/10B tentam obter definições via `INFORMATION_SCHEMA`. Quando a definição vier `NULL` ou for truncada, use o comando correspondente comentado no `.sql`, **um objeto por vez**, após confirmar o tipo. Em MySQL, `SHOW CREATE TABLE`, `SHOW CREATE VIEW`, `SHOW CREATE TRIGGER`, `SHOW CREATE PROCEDURE`, `SHOW CREATE FUNCTION` e `SHOW CREATE EVENT` apenas exibem a definição; não copie o resultado para executar. As definições podem conter literais sensíveis e exigem a revisão/redação acima.

Para tabelas com suspeita de alteração após o `CREATE` do export, `SHOW CREATE TABLE` pode ajudar a confirmar estrutura final, mas não precisa ser executado para todas as 270 de uma vez. Priorize as 42 estruturas sem `tenant_id`, as 22 candidatas a views que o Bloco 02 classificar como views, e tabelas cujos `ALTER` alteram tipos/colunas/índices. Use blocos independentes de nomes pequenos e salve cada saída separadamente.

## Reconciliação após disponibilização

A equipe só fará comparação após receber resultados revisados. Ela deve reconciliar objetos/tipos live versus os 270 `CREATE TABLE` do export; aplicar/reconstruir, em ferramenta offline revisada, os 489 `ALTER TABLE` na ordem original para derivar o estado final das colunas e constraints; comparar PKs, índices, FKs, triggers, views e rotinas; e fechar as classificações das 42 estruturas sem tenant. `not_replayed` no catálogo anterior significa que o parser não aplicou o `ALTER` — não demonstra se ele foi ou não executado no HostGator.

Até a chegada e validação dos resultados, **o Gate 2 permanece fechado**. Este pacote não cria PostgreSQL, não converte schema, não executa migrations, não modifica o MySQL e não inicia a Fase 3.
