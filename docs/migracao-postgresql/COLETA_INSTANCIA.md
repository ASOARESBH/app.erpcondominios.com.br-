# Coleta da instância — Fases 2.1 e 2.2

> **Estado: parcial. Gate 2 permanece fechado.** Este documento registra somente evidências sanitizadas. O DDL bruto e os artefatos privados ficam fora do repositório.

## Evidência validada do export estrutural

O anexo mais recente, `inlaud99_erpcondor(7).sql`, foi pré-processado em memória por parser fail-closed. Seu checksum de origem é `25c60a44e06a41aea4202a983c3d539dde5a33f873da40cb70a0dfd008be594a`. Após mascarar apenas sete comentários condicionais standalone classificados como wrappers `SET` e retirar wrappers comuns de sessão/transação, o schema-only validado tem SHA-256 `44505b1aab8c9a1085453ff3bf4d8a66519700810ac63fcd225693fdefec604a`.

O catálogo do export aceitou 270 `CREATE TABLE`, 489 `ALTER TABLE`, 7 triggers e 11 FKs explícitas; não há DML top-level. Os 489 ALTERs foram inventariados, mas não reproduzidos, portanto as contagens e definições representam a projeção do export, não o estado final instalado. Os catálogos de tabelas e eventos ALTER coincidem semanticamente com o export schema-only validado anteriormente. Nenhuma linha de negócio foi lida, mostrada, importada ou gravada.

## Tentativa de consulta ao catálogo instalado

A sessão autenticada foi retomada no cPanel e o schema `inlaud99_erpcondor` aberto no phpMyAdmin 5.2.3. Foi preparado e submetido uma vez um SELECT agregado de `INFORMATION_SCHEMA.TABLES`, agrupando os tipos reais de objeto para o schema. A ação de execução expirou após 25 segundos; a consulta não foi reenviada. Uma verificação passiva da página também expirou.

**Nenhum resultado foi confirmado.** A quantidade real de `BASE TABLE`, `VIEW` e outros tipos permanece desconhecida. Não foi obtido `SHOW CREATE` individual nesta fase. Não se inferiu view pelo nome ou prefixo.

## Artefatos da Fase 2.2

Os arquivos de [phase2_2/](phase2_2/) são inventários estruturais derivados do export validado e estão marcados como parciais. Todos os campos que dependem do catálogo instalado permanecem `UNKNOWN`, `NOT_OBTAINED` ou `NOT_ASSESSED`. `MYSQL_EXPORT_RECONCILIATION.csv` não inventa divergências; `MYSQL_TENANT_CLASSIFICATION.csv` não adiciona tenant e deixa a semântica funcional pendente. Os CSVs de views, rotinas e eventos não tratam ausência no export como prova de ausência no servidor.

A projeção estrutural do export declara `tenant_id` em 228 tabelas e não declara essa coluna em 42. Isso não prova nulabilidade, índice ou isolamento instalado; classificação de domínio e relacionamentos tenant-aware ainda requerem consulta e análise de negócio. Valores default literais e comentários não foram publicados.

## Próximo passo e critério de conclusão

Retomar uma sessão My Browser estável e consultar o catálogo em blocos pequenos e read-only. Primeiro confirmar `TABLE_TYPE`, ENGINE e estimativas disponíveis em `INFORMATION_SCHEMA.TABLES`; em seguida, coletar `SHOW CREATE` por tabela-base, view e objeto programável identificado. Após cada consulta, verificar o resultado antes de prosseguir; se ocorrer timeout, não reenviar a mesma consulta sem avaliar uma alternativa mais estreita e segura.

O Gate 2 só poderá ser concluído após reconciliar o catálogo live, o DDL e os objetos programáveis, explicar divergências, classificar as 42 tabelas sem `tenant_id`, documentar permissões e calcular checksums verificáveis. A conversão PostgreSQL não foi iniciada.
