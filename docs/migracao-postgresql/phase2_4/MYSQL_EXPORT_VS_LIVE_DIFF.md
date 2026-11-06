# Fase 2.4 — Export MySQL versus catálogo live

**Tentativa de coleta:** 2026-11-06 (tentativa de reconciliação live; resultados não obtidos). **Schema:** `inlaud99_erpcondor`. **Conclusão:** comparação live incompleta; diferenças não podem ser determinadas.

**Fontes:** export schema-only validado e análise estática publicados em `docs/migracao-postgresql/phase2_3/`; tentativa de catálogo live no phpMyAdmin 5.2.3, sem resultado; repositório oficial `ASOARESBH/app.erpcondominios.com.br-`, baseline `f60739713e6de3f2473b79ecd14a70fd700e5462`. **Limitação:** export, código e resultado informado pelo operador não substituem evidência `INFORMATION_SCHEMA`/`SHOW CREATE` da instância.

## Resumo

A reconciliação depende de um resultado real do catálogo instalado. Na tentativa de 2026-11-06, a página do phpMyAdmin 5.2.3 mostrou o schema selecionado e o SELECT agregado `INFORMATION_SCHEMA.TABLES` no editor, mas apareceu o alerta de formulário incompleto; uma única tentativa de fechar/submeter pelo navegador expirou. Não foi recebido conjunto de resultados. A consulta não foi repetida.

| Comparação | Export/estática disponível | Catálogo live | Resultado correto |
|---|---:|---|---|
| Objetos `CREATE TABLE` | 270 | NÃO CONFIRMADO | Não comparável; objetos live adicionais/ausentes desconhecidos |
| `ALTER TABLE` | 489 statements; 746 suboperações | NÃO CONFIRMADO | Eventos do export não foram replayados; estado final desconhecido |
| Colunas no `CREATE` | 3.339 | NÃO CONFIRMADO | Não comparar por tipo/nullability até obter catálogo live |
| PK / UNIQUE / INDEX | 240 / 70 / 193 declarações em ALTER | NÃO CONFIRMADO | Não inferir presença nem ausência |
| FKs | 11 declarações em ALTER | NÃO CONFIRMADO | Não inferir presença nem ausência |
| Candidatas a views | 22 nomes analisados estaticamente | `TABLE_TYPE` NÃO CONFIRMADO | Nenhuma classificada live |
| Triggers | 7 definições no export | NÃO CONFIRMADO | Instalação, ordem e definição divergente desconhecidas |
| Procedures / functions / events | Nenhum `CREATE` capturado no export validado | NÃO CONFIRMADO | Ausência live não confirmada |

**“Não comparável” não significa “sem divergência”.** Não há base para declarar zero objetos extras, zero diferenças ou equivalência entre export e produção.

## Metadado recebido separadamente

O operador informou resultado anterior de consulta somente leitura, datado de 2026-11-05: Percona Server 5.7.44-48; `utf8` / `utf8_unicode_ci`; timezone de sessão `SYSTEM`, timezone do servidor `-03`; SQL mode `NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`; engine padrão InnoDB; `lower_case_table_names=0`; event scheduler `OFF`. É evidência fornecida pelo operador e não confirma motores por tabela, permissões, objetos ou estado estrutural final. O scheduler segue reportado `OFF`; não foi alterado.

## Divergências possíveis ainda não verificáveis

1. Objetos live presentes e ausentes do export, inclusive relações, views, procedures, functions e events.
2. Objetos exportados que não existem mais no servidor, ou cujo tipo instalado difere de `CREATE TABLE`.
3. Estado final após 489 `ALTER TABLE`, incluindo `MODIFY`, `AUTO_INCREMENT`, unsigned, defaults e colunas.
4. Índices, chaves e regras referenciais instaladas, nome, ordem, prefixos e ações.
5. Definições, eventos, precedência/ordem e dependências live dos sete triggers.
6. Views e rotinas com dependências/definições não capturadas pelo export.
7. Engine e collation por objeto, privilégios de visibilidade e migrations/ledger efetivamente aplicados.

## Segurança e limites

Nenhuma linha de negócio foi consultada. Nenhuma migration, DDL, importação, alteração de banco, PostgreSQL, API, VM ou deploy foi executado. A lista estática de Fase 2.3 é referência de origem, não substituto do catálogo instalado.
