# Backup, restore e rollback

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Parâmetros:** RPO, RTO, SLO, retenção, janela e critérios de abortar ainda não foram aprovados nem medidos.

## Princípio de recuperação coordenada

Backup de banco sozinho não preserva o ERP. O conjunto deve cobrir banco PostgreSQL, roles/globals, WAL/PITR, uploads/PDF, referências no banco, configuração não secreta necessária, filas/jobs e logs/auditoria. Snapshot é complemento, não substituto de backup lógico e WAL externo.

## Pacote futuro de backup

1. `pg_dump` em formato custom e export separado de globals/roles, com versão, checksum e manifesto.
2. Base backup e WAL contínuo para PITR, criptografados, externos ao host e com retenção/imutabilidade aprovadas.
3. Manifesto de storage: arquivos, tamanhos, hashes, referências, tenant e política de exclusão — sem copiar conteúdo pessoal para esta documentação.
4. Registro de versão do schema, migrations e migration ledger com checksum, ordem, ambiente, sucesso/erro e recovery.
5. Proteção de configurações e filas por secret manager/backup seguro; nunca publicar credenciais, tokens ou chaves.

## Restore drill

Em ambiente separado e descartável: restaurar roles, globals, banco, objetos, WAL/PITR, storage e referências; validar checksum/manifesto; executar smoke de autenticação, tenant, RBAC, API, uploads, relatórios, jobs e notificações; medir tempo e perda; registrar divergências. O drill ainda não foi realizado.

## Rollback antes da escrita

Parar tráfego de escrita, jobs, webhooks e filas; preservar logs, auditoria e checksums; voltar release compatível; restaurar banco/storage/configuração coordenadamente se necessário; validar schema, referências e smoke; medir RPO/RTO; reabrir gradualmente. Preferir rollback de release compatível quando dados ainda não foram alterados.

## Rollback depois da escrita

Congelar novas escritas e integrações; avaliar perda dentro do RPO; preferir correção compatível quando segura; se necessário, restaurar banco, storage, referências, configuração e filas no mesmo ponto temporal. Restauração cega ou somente de banco é proibida. Reconciliar contagens/agregados, testar tenant/RBAC e só reabrir com autorização.

## Critérios de abortar

Definir antes da janela: falha de backup/checksum, restore não reproduzível, RPO/RTO fora do aprovado, cross-tenant, perda de funcionalidade crítica, divergência de dados sem explicação, erro de migration/constraint, saturação/performance fora do SLO, jobs duplicados, storage sem referência, integração física indisponível ou impossibilidade de observabilidade. O dono e a autoridade de abortar ainda precisam ser nomeados.

## Evidências exigidas

Manifesto assinado/checksum; logs sanitizados; versões e ambiente; horários UTC e timezone; volume/contagem; teste de restauração; comparativo de agregados; resultados T21; decisão go/no-go; incidentes e recovery. Nenhuma evidência deve conter dados pessoais, credenciais, cookies, tokens ou SQL sensível.

## Referências

`AI-CONTEXT/TESTING.md`, `AI-CONTEXT/DEPLOY.md`; evidências externas `review_operations.md` e `plano_migracao_postgresql_erp.md`, identificadas no [baseline-manifest.json](baseline-manifest.json) e não incluídas nesta branch. O material atual confirma que backup, restore, PITR/WAL e rollback não foram validados.
