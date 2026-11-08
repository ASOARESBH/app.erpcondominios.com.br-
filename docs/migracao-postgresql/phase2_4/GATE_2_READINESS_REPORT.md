# Fase 2.4 — Relatório de prontidão do Gate 2

**Decisão:** **APROVADO POR ANDRÉ EM 2026-11-08, exclusivamente para fins de conversão e ensaio**, usando o catálogo da cópia restaurada do dump completo de 2026-11-07. Esta decisão substitui, para a conversão, a exigência de nova coleta assistida pelo phpMyAdmin. Não afirma que o catálogo é uma leitura live feita no mesmo instante da produção nem aprova cutover, deploy, DNS, limpeza de ensaios ou uso do novo banco pelo ERP.

**Fonte oficial do código:** `ASOARESBH/app.erpcondominios.com.br-`, baseline `f60739713e6de3f2473b79ecd14a70fd700e5462`. **Branch deste documento:** `pg/02-4-live-catalog-reconciliation`. **Origem de dados:** cópia privada e isolada do dump MySQL/Percona do schema `inlaud99_erpcondor`.

## Decisão e proveniência

A tentativa via phpMyAdmin em 2026-11-06 não retornou resultados. O pacote SQL incremental e seu manual foram preparados, mas os dez blocos não foram executados. Em 2026-11-08, André autorizou que o catálogo obtido da cópia restaurada do dump completo de 07/11 substitua aquela coleta live para fins de conversão. A conversão lê somente a cópia isolada, não o banco de produção; o dump e seus dados permanecem fora do Git e de relatórios públicos.

A aprovação é limitada ao snapshot do dump: mudanças de produção posteriores a sua geração não foram observadas. A janela de virada deverá usar backup final atualizado, nova validação de identidade/checksum, reconciliação e plano de rollback; este Gate 2 não elimina esses passos.

## Catálogo aprovado para conversão

| Critério | Estado | Evidência do snapshot aprovado |
|---|---|---|
| Objetos de tabela | **ATENDIDO** | 270 `BASE TABLE`, InnoDB; 3.339 colunas. |
| Views e 22 candidatas anteriores | **ATENDIDO** | 0 views no catálogo restaurado; os 22 nomes candidatos do export antigo foram encontrados como tabelas, não views. |
| Chaves | **ATENDIDO PARA GERAR E RECONCILIAR** | 11 FKs; 232 colunas `AUTO_INCREMENT`; 30 tabelas sem PK, tratadas por hash multiset e contagem. |
| Triggers | **ATENDIDO PARA TRADUÇÃO/TESTE** | 7 triggers presentes no catálogo do dump; definições são fingerprintadas sem persistir corpos ou dados no repositório. |
| Routines | **ATENDIDO PARA TRADUÇÃO/TESTE** | 2 procedures; 0 functions; fingerprints e assinaturas são conferidos no pipeline. |
| Events | **ATENDIDO** | 0 events no catálogo restaurado. |
| Tenancy estrutural | **ATENDIDO PARA INVENTÁRIO; REVISÃO SEMÂNTICA CONTINUA** | 228 tabelas com `tenant_id`, 42 sem `tenant_id`. Não adicionar a coluna automaticamente. |
| `ON UPDATE CURRENT_TIMESTAMP` | **ATENDIDO PARA GERAÇÃO DETERMINÍSTICA** | 111 colunas verificadas por consulta metadata-only; lista exata versionada e validada pelo gerador. O número diverge da contagem estática anterior e deve permanecer rastreável como descoberta do snapshot. |
| Tipo e defaults finais | **ATENDIDO CONTRA O SNAPSHOT** | Metadados do schema restaurado, incluindo tipos, defaults, engines e índices, são a entrada do pipeline; conversão de dados e equivalência ainda dependem do aceite v9. |

O catálogo agregado adicional identificou 400 índices BTREE não únicos e 370 únicos/PK, além dos relacionamentos acima. O inventário da fonte e seus metadados não contêm nem publicam linhas de negócio.

## Ressalvas técnicas e gates seguintes

1. Gate 2 aprovado **não significa conversão concluída**. O pipeline v9 precisa gerar DDL revisável, executar em database PostgreSQL de ensaio recém-criado, validar triggers/routines e atingir reconciliação de 270/270 tabelas.
2. Valores `ENUM` observados fora da definição são preservados literalmente no destino e incluídos no `CHECK` gerado; os relatórios persistem apenas contagens por coluna, não os valores. `SET` é texto preservado. Zero/partial-zero dates são convertidas a `NULL`, com contagem por coluna; valores `TIME` fora da faixa aprovada bloqueiam a execução.
3. Foreign keys com órfãos não autorizam apagar linhas: permanecem `NOT VALID`, com contagem por FK, e precisam de decisão humana. As demais só são validadas depois dos gates de dados.
4. A regra das 42 tabelas sem `tenant_id` continua sujeita a revisão semântica e classificação; não criar tenant por inferência. Candidatas a backup só vão ao schema `legado` quando a busca pontual confirmar que o código executável do baseline não as usa; qualquer tabela ainda referenciada permanece em `erp`.
5. O pipeline, os scripts e os resultados agregados podem ser versionados; dumps, linhas de negócio, credenciais, corpos brutos de rotinas e logs com valores não podem ser publicados.
6. Fase D está autorizada em branch própria e somente para adaptação/testes privados; não autoriza conectar a API ao banco de ensaio como produção nem publicar serviços.

## Histórico da coleta anterior

A recomendação de “não aprovar” registrada na tentativa de 2026-11-06 era correta naquele momento: phpMyAdmin não devolveu resultados. Foi superada pela decisão expressa de André em 2026-11-08 e pela evidência metadata-only do catálogo da cópia restaurada do dump. Os dez blocos SQL e o manual seguem disponíveis como referência/recovery, mas não são evidência de execução.

## Estado de produção e resultado

Não houve escrita, migration ou alteração no MySQL de produção. O PostgreSQL principal, o ERP, a API, DNS, firewall público, serviços Nginx/PHP-FPM e deploy/cutover não foram alterados por esta aprovação. A única finalidade aprovada é conversão/teste isolado e desenvolvimento versionado na branch apropriada.
