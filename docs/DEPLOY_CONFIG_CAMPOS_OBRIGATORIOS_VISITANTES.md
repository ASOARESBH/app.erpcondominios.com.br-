# Deploy — Campos Obrigatórios Configuráveis de Visitantes

## Objetivo

Esta atualização adiciona **Sistema > Visitantes**, localizado abaixo de **Departamentos**, para que um usuário autorizado defina por condomínio quais campos do cadastro de visitantes são obrigatórios. A regra é aplicada na interface e confirmada pela API com base no `tenant_id` da sessão.

> A regra anterior exigia ao menos **uma evidência**: foto **ou** documento digitalizado. Ela é preservada como padrão inicial. Foto e documento digitalizado continuam opcionais individualmente até que um usuário autorizado altere a configuração.

## Ordem obrigatória de publicação

| Ordem | Ação | Arquivo ou destino |
|---:|---|---|
| 1 | Realizar backup do banco e dos arquivos que serão substituídos. | HostGator / phpMyAdmin |
| 2 | Executar a migration uma única vez. | `sql/migration_config_cadastro_visitantes_mysql57.sql` |
| 3 | Enviar os arquivos do ZIP preservando a estrutura de diretórios. | Document root da aplicação |
| 4 | Limpar o cache do navegador com `Ctrl+F5`. | Navegador |
| 5 | Acessar **Configurações > Sistema > Visitantes** e validar os campos. | ERP Condomínio |

## Arquivos incluídos

| Área | Arquivos |
|---|---|
| Banco | `sql/migration_config_cadastro_visitantes_mysql57.sql` |
| Backend | `api/api_config_visitantes.php`, `api/helpers/visitantes_config_helper.php`, `api/api_visitantes.php` |
| Interface | `frontend/pages/sistema.html`, `frontend/pages/config_visitantes.html`, `frontend/js/pages/config_visitantes.js`, `frontend/js/pages/visitantes.js`, `frontend/js/menu-controller.js`, `frontend/pages/visitantes.html`, `assets/css/pages/config_visitantes.css` |

## Controle de acesso

A leitura e alteração das regras usam o tenant da sessão e nunca aceitam `tenant_id` do navegador. A gravação exige a permissão RBAC **Sistema > Configurar**; sem a estrutura RBAC, o sistema preserva a autorização administrativa legada durante a transição.

## Roteiro de aceite

| Cenário | Resultado esperado |
|---|---|
| Padrão inicial | Nome, tipo de documento, documento e telefone obrigatórios; foto ou documento digitalizado como evidência obrigatória. |
| Desativar evidência | Um novo cadastro pode ser salvo sem foto e sem documento digitalizado. |
| Exigir foto | O cadastro é bloqueado sem foto, mesmo com documento digitalizado. |
| Exigir documento digitalizado | O cadastro é bloqueado sem arquivo de documento, mesmo com foto. |
| Alterar outro campo | A interface marca o campo com asterisco e a API bloqueia o `POST`/`PUT` se estiver vazio. |
| Outro tenant | Não visualiza nem altera a configuração do condomínio atual. |
| Usuário sem configurar | Não consegue gravar a configuração e recebe resposta de acesso negado. |

## Reversão

Se houver necessidade de reversão, restaure os arquivos substituídos a partir do backup. A tabela de configuração não remove dados de visitantes; sua remoção é opcional e somente deve ocorrer após a restauração dos arquivos anteriores:

```sql
DROP TABLE IF EXISTS config_visitantes_campos;
```

Não execute a reversão sem confirmar que o código anterior está novamente em produção.
