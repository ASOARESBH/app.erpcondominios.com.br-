# Linha de Base — Auditoria RBAC e Segurança

## Escopo avaliado

A auditoria estática foi executada sobre o repositório atual antes de qualquer alteração de RBAC. Foram identificadas **58 páginas SPA**, **57 módulos JavaScript de página** e **136 APIs PHP**. A contagem abaixo é indicativa, baseada em sinais estáticos no código, e serve para priorizar a migração incremental.

| Indicador estático | Quantidade | Observação |
|---|---:|---|
| APIs analisadas | 136 | Arquivos `api/api_*.php` e APIs autenticadas correlatas. |
| APIs com `verificarAutenticacao()` | 73 | A autenticação não está universalmente centralizada. |
| APIs com hierarquia global de papel | 30 | Usam `verificarPermissao()` com níveis fixos. |
| APIs com sinal de permissão granular | 2 | A cobertura RBAC por ação ainda é incipiente. |
| APIs com referência a tenant | 100 | A cobertura deve ser validada por consulta e ação. |
| APIs com sinal de auditoria | 53 | Os logs ainda não formam trilha imutável estruturada. |

## Estado atual confirmado

O sistema já possui contexto multi-tenant na sessão por `auth_helper.php`, com resolução via `usuario_tenant`. A autorização efetiva continua baseada principalmente na hierarquia fixa `visualizador`, `operador`, `gerente`, `admin` e `super_admin`.

Existe uma primeira camada de permissões por módulo formada por `modulos_sistema` e `usuario_modulos`, exposta por `api/api_permissoes_modulos.php`. Ela oferece `acessar`, `criar`, `editar`, `excluir` e `exportar`, mas não oferece grupos configuráveis, submódulos hierárquicos, permissões avançadas, negação explícita, cache ou autorização uniforme no backend.

O menu é construído a partir de um catálogo estático em `frontend/js/menu-controller.js`. O frontend já possui mecanismos de filtragem visual, mas eles não substituem autorização por endpoint. A nova camada deve manter esse catálogo apenas como fonte de apresentação, enquanto a decisão deve vir do backend.

A API atual de usuários possui CRUD, ativação/inativação e encerramento parcial de sessões. A auditoria revelou que a criação de usuário deve ser revisada na migração: o `INSERT` atual não inclui explicitamente `tenant_id`, embora as consultas posteriores o exijam. A nova implementação deve corrigir esse ponto sem remover os usuários existentes.

## Riscos prioritários

| Prioridade | Risco | Tratamento previsto |
|---|---|---|
| Crítica | Acesso por URL/API pode depender somente do papel global ou de validação client-side. | Middleware RBAC obrigatório por recurso e ação, com resposta 403 e evento auditado. |
| Crítica | Registros de permissão, logs e sessões não possuem modelo RBAC normalizado e auditável. | Migrations de grupos, permissões, vínculos, sessões e auditoria encadeada. |
| Alta | Tabelas de módulos e permissões ainda são criadas em runtime por API. | Migrations versionadas e catálogo inicial idempotente. |
| Alta | Usuários existentes precisam manter o acesso durante a transição. | Compatibilidade por perfil atual e migração explícita para grupo/papel RBAC. |
| Alta | Operações críticas não possuem prevenção uniforme de escalada de privilégio. | Verificação de delegação, negação explícita e confirmação auditada. |

## Diretriz de implantação

A implementação deverá ocorrer de forma incremental. Inicialmente, o RBAC será introduzido em modo de compatibilidade: Super Admin e administradores atuais recebem regras explícitas equivalentes ao acesso atual, enquanto a camada de autorização registra divergências. A negação por padrão será ativada somente após a catalogação e a atribuição de permissões dos módulos existentes.

Nenhuma migration utilizará `ADD COLUMN IF NOT EXISTS`, CTEs ou recursos posteriores ao MySQL/MariaDB 5.7.
