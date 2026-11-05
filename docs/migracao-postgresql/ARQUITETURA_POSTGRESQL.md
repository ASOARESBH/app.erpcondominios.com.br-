# Arquitetura PostgreSQL — desenho de planejamento

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Destino:** PostgreSQL já está definido pelo usuário. Versão, extensões, topologia, TLS, timezone, collation, scheduler e demais parâmetros permanecem a aprovar.

## Princípios

1. Preservar funcionalidades e contratos observáveis antes de otimizar ou modernizar.
2. Separar código Git, snapshot schema-only, runtime proposto e estado instalado; nenhum deles substitui os demais.
3. Derivar identidade e tenant no servidor; não confiar em IDs de tenant, morador, unidade ou colaborador enviados pelo cliente.
4. Converter semântica MySQL/MariaDB, não somente tokens SQL.
5. Fazer mudanças em etapas reversíveis, com fixtures anonimizadas e dois ou mais tenants em QA.
6. Manter o baseline sem alterações de aplicação, banco, VM ou produção até autorização específica.

## Arquitetura lógica proposta

```text
Clientes web / Portal / Mobile / PWA / ControlID
                    |
          HTTP + contratos por família
                    |
     PHP API existente + adaptador de dialeto
                    |
       camada de identidade / tenant / RBAC
                    |
      repositórios e transações PostgreSQL
                    |
  PostgreSQL + objetos convertidos + observabilidade
                    |
  storage de anexos + provedores externos + jobs
```

A figura é uma fronteira de desenho, não uma implementação existente. O backend atual é procedural/misto, com endpoints diretos por path e ações em query/body; por isso, o primeiro alvo é uma camada de compatibilidade testável, sem presumir refatoração integral.

## Camadas e responsabilidades

### API e contratos

Manter adaptadores para o envelope moderno `{sucesso,mensagem,dados}`, o legado `ApiBase` `{success,message,data,error}`, Assembleia `{ok,erro/dados}` e o payload próprio do ControlID. Status HTTP, `Content-Type`, erros sem HTML/warnings/SQL e idempotência devem ser uniformizados gradualmente, sem quebrar clientes legados sem plano de compatibilidade. Ver [CONTRATOS_API.md](CONTRATOS_API.md).

### Identidade, tenant e RBAC

Sessão administrativa, Bearer de Portal, sessão móvel, token de dispositivo e chamadas ControlID são superfícies distintas. A camada deve resolver a identidade, tenant, vínculo ativo e permissão antes de consultar ou modificar dados. Relações pai/filho exigem revalidação no mesmo tenant. Ver [AUTENTICACAO_TENANT_RBAC.md](AUTENTICACAO_TENANT_RBAC.md).

### Persistência PostgreSQL

O destino deve suportar transações explícitas, prepared statements, SQLSTATE, locks, savepoints, upsert com alvo de constraint, JSON/JSONB conforme decisão, identity/sequence e timezone aprovado. Não há adapter PostgreSQL comprovado no baseline; esta seção descreve o desenho futuro, não runtime instalado.

### Jobs e integrações

Jobs de alertas e demais cron devem ter lock, idempotência, timezone e observabilidade definidos. Notificações são efeitos secundários: persistência do evento primário deve ser tenant-scoped e idempotente; FCM, e-mail, Chat-In e provedores externos devem registrar sucesso/falha sem bloquear a decisão física do ControlID quando essa for a regra aprovada.

### Arquivos e documentos

Uploads/downloads devem permanecer autorizados no backend, com storage central tenant-scoped, MIME/tamanho/nome validados e nenhuma URL pública baseada diretamente no caminho recebido. O manifesto de anexos e a reconciliação entre banco, arquivos e referências ainda precisam ser obtidos.

## Compatibilidade e migração incremental

A ordem recomendada é: fechar evidência instalada; decidir parâmetros; converter schema em ambiente descartável; implementar/validar adaptador; migrar dados com reconciliação; executar QA de contratos e isolamento; ensaiar backup/restore/rollback; só então planejar cutover. Não há autorização para executar qualquer etapa operacional neste baseline.

## Decisões ainda abertas

- Versão PostgreSQL e extensões permitidas.
- `identity` versus sequences e mapeamento de `UNSIGNED`.
- `ENUM` como tipo nativo, `CHECK` ou domínio.
- `json` versus `jsonb` e política de validação.
- `timestamp` com ou sem timezone, precisão e timezone operacional.
- Política para `0000-00-00 00:00:00`, collations e prefix indexes.
- FKs/checks tenant-aware e classificação das 42 tabelas sem `tenant_id`.
- Driver/adapter PHP, runtime, pool/conexões, TLS, jobs e observabilidade.

A matriz completa está em [DECISOES_TECNICAS.md](DECISOES_TECNICAS.md) e as lacunas em [PENDENCIAS.md](PENDENCIAS.md).

## Referências

`AI-CONTEXT/ARCHITECTURE.md`, `AI-CONTEXT/BACKEND.md`, `AI-CONTEXT/DATABASE.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/DEPLOY.md`; evidências externas `review_database.md`, `review_operations.md` e `plano_migracao_postgresql_erp.md`, identificadas por nome e checksum no [baseline-manifest.json](baseline-manifest.json) e não incluídas nesta branch.
