# Fase 2.4 — Export MySQL versus catálogo do snapshot

**Atualização:** 2026-11-08. **Schema:** `inlaud99_erpcondor`. **Decisão:** André aprovou o catálogo metadata-only da cópia restaurada do dump completo de 2026-11-07 para fins de conversão, substituindo a coleta via phpMyAdmin que não retornou resultados. **Limite:** este snapshot não demonstra alterações realizadas na produção depois da geração do dump.

## Comparação

| Estrutura | Export/schema-only histórico | Catálogo restaurado do dump | Conclusão para conversão |
|---|---:|---:|---|
| Objetos base table | 270 CREATE TABLE | 270 BASE TABLE | 270 tabelas no snapshot. |
| Alterações de schema | 489 ALTER statements; 746 suboperações | Estado pós-alter capturado diretamente pelo catálogo restaurado | Usar snapshot, não replay cego do export. |
| Colunas | 3.339 iniciais | 3.339 | Shape estrutural reconciliado para o snapshot. |
| Índices/constraints | 514 declarações catalogadas em ALTER | 770 índices: 400 BTREE não únicos e 370 únicos/PK | Gerar do catálogo restaurado e conferir no destino. |
| FKs | 11 declaradas | 11 | Assinaturas e ações entram no gate do gerador/reconciliador. |
| Candidatas a views | 22 nomes identificados por padrão | 0 views; 22 nomes presentes como tabelas | Resolvidas como tabelas para este snapshot. |
| Triggers | 7 definidos no export | 7 | Presentes e fingerprintados; equivalência requer testes. |
| Procedures / functions / events | Sem CREATE capturado no export validado | 2 / 0 / 0 | Duas procedures serão traduzidas e testadas; funções/events ausentes no snapshot. |
| AUTO_INCREMENT | 232 modificações catalogadas | 232 colunas auto_increment | Criar identities e alinhar max+1 no alvo. |
| Tenant | 42 estruturas inicialmente sem tenant | 228 com tenant_id e 42 sem | Contagem confirmada; classificação semântica segue necessária. |
| `ON UPDATE CURRENT_TIMESTAMP` | Contagem estática anterior de 45 | 111 colunas no catálogo | Usar lista exata do snapshot versionada, não a contagem antiga. |

**Interpretação:** diferenças entre export e catálogo indicam objetos/alterações efetivamente presentes no snapshot restaurado, mas não autorizam inferir alterações posteriores na produção. O export permanece útil como histórico de origem e não substitui o estado pós-ALTER encontrado no snapshot.

## O que ainda precisa de prova na conversão

- Correspondência de 270/270 tabelas e 3.339 colunas no PostgreSQL de ensaio.
- Igualdade de contagem e hashes por linha por tabela, somas numéricas, perfil binário, distribuição por tenant e máximo das PKs.
- Alinhamento exato das 232 sequences para `max+1`, `is_called=false`.
- Todas as 11 FKs presentes com colunas/ações corretas; constraints sem órfãos validadas, e órfãs mantidas `NOT VALID` com contagens.
- Equivalência temporal de `DATETIME`, `TIMESTAMP` em hora civil `America/Sao_Paulo`, zero-dates `NULL` e bloqueio de perda de precisão/valores TIME incompatíveis.
- Testes sintéticos das sete triggers e duas procedures, sem efeitos persistentes.
- Revisão semântica das 42 tabelas sem `tenant_id` e das tabelas movidas para `legado`.

## Histórico e segurança

A tentativa phpMyAdmin de 2026-11-06 não retornou resultados e nenhuma das dez consultas assistidas foi executada. A decisão de 2026-11-08 substituiu essa coleta para fins de conversão usando o catálogo de uma cópia privada restaurada do dump de 07/11. O dump e dados de negócio permanecem fora do Git e dos relatórios; este documento contém somente metadados e métricas. Não houve escrita no MySQL de produção, conexão da API ao destino, deploy, DNS ou cutover.
