# Deploy — Correção de Logo Multi-Tenant

## Objetivo

Esta entrega corrige o erro `404` de logo em **Configurações → Empresa** e adiciona a exibição segura da marca do condomínio no cabeçalho do **Portal do Morador**.

## Causa corrigida

A rota de compatibilidade `/uploads/...` era encaminhada à ação `legado` da API de arquivos antes da inicialização de sessão. Como o tenant não era resolvido, a logo privada do banco era tratada como arquivo público e retornava `404`.

A rota agora usa `obterTenantId()` para iniciar e ler a sessão antes da consulta. O upload e a página Empresa passam a priorizar URL autenticada do BLOB, sem abandonar `caminho_legado` armazenado somente para compatibilidade.

## Arquivos do pacote

| Caminho | Alteração |
|---|---|
| `api/api_arquivos_tenant.php` | Inicializa e resolve o tenant antes de servir URL legada privada. |
| `api/api_empresa.php` | Retorna `url_segura` no upload e `logo_url_segura` ao carregar dados. |
| `api/get_logo_empresa.php` | Reconhece sessão PHP de morador, além da sessão administrativa, sem receber tenant do cliente. |
| `frontend/js/pages/empresa.js` | Exibe BLOB autenticado e usa marca institucional no fallback. |
| `frontend/portal_morador.html` | Carrega logo do tenant no header com fallback seguro. |

## Implantação

Faça backup dos arquivos atuais e envie o conteúdo do ZIP para a raiz de `public_html`, preservando a hierarquia `api/`, `frontend/` e `AI-CONTEXT/`. Não há migration SQL nesta entrega e não deve ser criada qualquer pasta física de upload.

Após o envio, use `Ctrl+F5` em **Configurações → Empresa** e no **Portal do Morador** para atualizar os scripts em cache.

## Validação pós-deploy

| Cenário | Resultado esperado |
|---|---|
| Enviar logo no módulo Empresa | Preview aparece de imediato por `/api/api_arquivos_tenant.php?acao=conteudo&id=...`, sem 404. |
| Recarregar Empresa e entrar novamente | A mesma logo permanece visível. |
| Portal do Morador do mesmo tenant | A logo aparece no cabeçalho em 38×38 px. |
| Tenant sem logo | Empresa usa `logo_padrao.png`; Portal mantém ícone padrão, sem erro. |
| Sessões de tenants distintos | Cada resposta de logo é filtrada pelo `tenant_id` da sessão; uma sessão não recebe o BLOB de outra. |
| Login administrativo e de morador | Mantém exclusivamente a marca institucional da plataforma. |

## Segurança

A API não aceita `tenant_id` por query string, formulário ou header para resolver logos. Todas as consultas de BLOB usam o contexto de tenant autenticado na sessão PHP.
