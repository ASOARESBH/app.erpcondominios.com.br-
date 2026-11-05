# Fase 2.2 — reconciliação do catálogo MySQL instalado

**Classificação: PARCIAL. Gate 2 não concluído.** Este relatório distingue a estrutura do export validado da estrutura atualmente instalada no HostGator. Não houve execução de migrations, escrita no MySQL, leitura de linhas de negócio, importação, alteração da VM ou deploy.

## Evidência disponível

O anexo de origem `inlaud99_erpcondor(7).sql` tem SHA-256 `25c60a44e06a41aea4202a983c3d539dde5a33f873da40cb70a0dfd008be594a`. O schema-only normalizado tem SHA-256 `44505b1aab8c9a1085453ff3bf4d8a66519700810ac63fcd225693fdefec604a` e passou pelo parser fail-closed já aprovado. O export declara 270 `CREATE TABLE`, 3.339 colunas, 489 `ALTER TABLE`, 7 triggers e zero DML top-level. Nos eventos ALTER foram catalogadas 514 declarações de constraint/relação — 240 PK, 70 UNIQUE, 193 índices e 11 FKs; não foram reproduzidas. O anexo foi comparado semanticamente com o export schema-only anterior; catálogos de tabela e de eventos ALTER coincidem. Estes são números do export, não do catálogo instalado.

## Tentativa de consulta ao catálogo instalado

Foi retomada a sessão autenticada do cPanel/My Browser e aberto o schema `inlaud99_erpcondor` no phpMyAdmin 5.2.3. O editor recebeu um único SELECT agregado sobre `INFORMATION_SCHEMA.TABLES`, agrupando `TABLE_TYPE` para esse schema. A ação de executar expirou no cliente do navegador após 25 segundos. Uma consulta passiva da página também expirou. **Nenhum resultado foi recebido nem confirmado como concluído, e a consulta não foi reenviada.** Assim, a distribuição real entre `BASE TABLE`, `VIEW` e outros tipos continua desconhecida. Nenhum `SHOW CREATE` individual foi obtido nesta fase.

## Estado da reconciliação

| Tipo | No export validado | No catálogo instalado | Situação |
|---|---:|---|---|
| Definições `CREATE TABLE` | 270 | Desconhecido | Correspondência não verificada; `SHOW CREATE TABLE` pendente. |
| `ALTER TABLE` catalogados | 489 | Desconhecido | Inventariados, não reproduzidos; não representam necessariamente o estado final. |
| PK / UNIQUE / índices declarados em ALTER | 503 | Desconhecido | 240 PK, 70 UNIQUE e 193 índices; presença instalada não confirmada. |
| Triggers | 7 | Desconhecido | DDL no export; `SHOW CREATE TRIGGER`/reconciliação live pendente. Os corpos não foram copiados para esta branch. |
| FKs explícitas | 11 | Desconhecido | Declaradas no material exportado; presença instalada não confirmada. |
| Views | 0 definições no export | Desconhecido | Ausência no arquivo não prova ausência no servidor. |
| Procedures / functions / events | 0 definições no export | Desconhecido | Inventário live e SHOW CREATE pendentes; Event Scheduler foi reportado `OFF` em metadado anterior, não foi alterado. |

As 270 entradas estruturais indicam 228 declarações com `tenant_id` e 42 sem essa coluna no `CREATE TABLE` do export. Isso não confirma nulabilidade/índices/relacionamentos instalados, nem substitui classificação de domínio. Os relatórios refletem apenas atributos estruturais que o catalogador já expõe; valores literais de defaults e comentários não foram copiados.

## Artefatos gerados

Os CSVs desta pasta são projeções parciais do export e rotulam todos os campos live como `UNKNOWN`, `NOT_COLLECTED` ou `NOT_OBTAINED`. `MYSQL_EXPORT_RECONCILIATION.csv` não contém divergências presumidas: cada comparação não verificável é marcada `UNDETERMINED`. `MYSQL_TENANT_CLASSIFICATION.csv` não adiciona tenant automaticamente e deixa classificação funcional pendente. Os CSVs de views, rotinas e eventos registram que o export não os trouxe sem inferir inexistência instalada.

O checksum `PHASE2_2_CHECKSUMS.sha256` cobre os onze artefatos solicitados, exceto ele próprio.

## O que falta para fechar o Gate 2

Retomar uma sessão My Browser estável e obter, em consultas pequenas read-only, a listagem real `INFORMATION_SCHEMA.TABLES` com tipo/engine/row estimates/timestamps e inventários de colunas/constraints/índices. Em seguida obter `SHOW CREATE` para tabelas-base, views e objetos programáveis identificados, confirmar privilégios/visibilidade, comparar os resultados com o export e classificar as 42 tabelas sem `tenant_id` com evidência de domínio. Não repetir a consulta após timeout sem alternativa mais estreita; não executar views/rotinas para obter linhas.

Até essa reconciliação, o Gate 2 permanece **PARCIAL**. A conversão para PostgreSQL não foi iniciada.
