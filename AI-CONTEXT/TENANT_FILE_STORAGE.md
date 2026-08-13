# Armazenamento de Arquivos Multi-Tenant

## Objetivo

Os anexos de negócio deixam de depender de arquivos físicos expostos em `public_html/uploads/`. Cada arquivo passa a ser armazenado como conteúdo binário no banco e associado ao tenant correto.

## Separação de responsabilidades

| Contexto | Fonte do arquivo | Acesso |
|---|---|---|
| Marca institucional da plataforma | `assets/img/logos/logo_padrao.png` | Público, estático e fora do banco de tenants |
| Logo de tenant | `tenant_arquivos` + referência em `tenants.logo_arquivo_id` | Sessão autenticada do próprio tenant |
| Anexos de contrato, CRM, GED, OS, morador, leitura, visitante, RH e notificações | `tenant_arquivos` | Sessão autenticada e tenant da sessão |
| Imagens de projeto público | `tenant_arquivos` com `publico = 1` e token opaco | Token público não enumerável |

## Tabelas

`tenant_arquivos` contém o binário, metadados, hash SHA-256, dono tenant e token público. `tenant_arquivo_referencias` mantém a relação entre um arquivo e o registro do módulo que o utiliza. O caminho físico anterior é armazenado em `caminho_legado` para migração e compatibilidade temporária.

## Regras obrigatórias

1. Toda consulta autenticada filtra por `tenant_id` vindo da sessão.
2. Nenhuma API aceita `tenant_id` informado pelo navegador.
3. Arquivos legados são importados com `tenant_id = 1` somente durante a transição da base Serra.
4. Novos arquivos são gravados no banco e retornam URL da API `arquivo_tenant.php`.
5. URLs antigas `uploads/...` permanecem funcionalmente compatíveis durante a migração por rewrite controlado, mas nunca devem apontar para arquivo físico depois da conclusão.
6. Depois da auditoria, o diretório físico é preservado apenas como backup externo; não como fonte ativa.
7. Arquivos públicos só podem ser expostos com token aleatório, sem IDs sequenciais.

## Limites operacionais

O conteúdo binário usa `LONGBLOB`; o tamanho máximo efetivo é limitado por `upload_max_filesize`, `post_max_size` e `max_allowed_packet` do servidor. A API deve validar tamanho, MIME, extensão e SHA-256 antes de inserir o conteúdo.

## Correção de branding — 2026-08-13

A rota de compatibilidade `api_arquivos_tenant.php?acao=legado` deve sempre obter o tenant com `obterTenantId()` antes de ler arquivos privados. Como essa rota executa antes do bloco padrão de autenticação, acessar `$_SESSION` diretamente sem iniciar a sessão transforma logos privadas em consultas públicas e causa 404.

O banco continua armazenando `caminho_legado` em `empresa.logo_url` e `tenants.logo_url` somente para compatibilidade. As interfaces novas devem preferir a URL autenticada `/api/api_arquivos_tenant.php?acao=conteudo&id=...`, retornada como `url_segura` pelo upload e como `logo_url_segura` na API de Empresa. Isso mantém o BLOB como fonte ativa e preserva o isolamento pelo tenant da sessão.

O endpoint `get_logo_empresa.php` atende tanto a sessão administrativa quanto a sessão PHP do Portal do Morador, sempre resolvendo `tenant_id` apenas pela sessão. O Portal exibe a logo retornada com fallback à marca institucional; telas de login permanecem estáticas e institucionais.

## Compatibilidade

A migração não deve apagar a pasta `uploads` antes de o importador e a auditoria confirmarem que cada arquivo foi incorporado ao banco. A exclusão física é uma etapa manual posterior, com backup preservado.
