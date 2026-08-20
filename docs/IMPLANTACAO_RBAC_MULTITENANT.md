# Implantação do RBAC Multi-Tenant

> **Objetivo.** Esta entrega substitui gradualmente o controle fixo de perfil por uma camada RBAC de grupos, permissões efetivas, exceções individuais, sessões administrativas e auditoria encadeada. A implantação deve ocorrer primeiro no banco e somente depois nos arquivos PHP e JavaScript.

## Escopo entregue

| Camada | Entrega | Resultado esperado |
|---|---|---|
| Banco | Catálogo de módulos, permissões, grupos, exceções, sessões, auditoria e políticas de API | Estrutura por `tenant_id`, sem alterar ou apagar permissões legadas |
| Backend | `rbac_helper.php`, controlador de permissões e API de grupos | Decisão de acesso no servidor, com negação por padrão após a migration |
| Usuários | Central com grupos, permissões efetivas, sessões e auditoria | Administração de identidade sem escalar privilégios indevidamente |
| Frontend | Menu por permissão e bloqueio de rota SPA | Menus ocultos e mensagem de acesso negado para rota sem autorização |
| Segurança | Sessões revogáveis e auditoria com hash encadeado | Logout/revogação rastreável e retenção de histórico |

## Pré-requisitos e backup

Antes de iniciar, exporte o banco `inlaud99_erpcondor` pelo phpMyAdmin e armazene uma cópia fora do servidor. Confirme que o banco está em MySQL/MariaDB 5.7, que a aplicação está com manutenção programada e que existe um administrador ativo para o tenant. Não altere manualmente as permissões de `admin@erpcondominios.com.br` durante a implantação.

A migration não remove usuários, grupos legados, vínculos `usuario_tenant`, dados de moradores ou dados operacionais. Ainda assim, o backup é obrigatório, pois a operação adiciona as tabelas de autorização que passarão a ser consultadas pelos novos arquivos.

## Ordem obrigatória de publicação

| Etapa | Ação | Critério de conclusão |
|---|---|---|
| 1 | Ativar manutenção ou realizar a publicação fora do horário de operação | Nenhuma alteração concorrente de usuários e permissões |
| 2 | Executar `sql/migration_rbac_multitenant_mysql57.sql` uma vez no banco de produção | phpMyAdmin conclui sem erro e cria tabelas `rbac_*` |
| 3 | Conferir as consultas de validação abaixo | Grupos `compat-*` e vínculos de usuários existem para cada tenant |
| 4 | Enviar os arquivos do ZIP à raiz da aplicação, preservando pastas | Nenhum arquivo é enviado para `uploads/` público |
| 5 | Limpar o cache do navegador com **Ctrl+F5** e sair/entrar novamente | Menu e Central de Usuários carregam normalmente |
| 6 | Executar o roteiro de aceite | Perfis inferiores recebem 403 no servidor e não apenas ocultação visual |

## Consultas de validação pós-migration

Execute as consultas abaixo no phpMyAdmin. Elas são somente leitura.

```sql
SELECT tenant_id, slug, nome, ativo
FROM rbac_grupos
ORDER BY tenant_id, protegido DESC, nome;
```

```sql
SELECT ug.tenant_id, u.id AS usuario_id, u.nome AS usuario,
       g.nome AS grupo, ug.ativo
FROM rbac_usuario_grupos ug
INNER JOIN usuarios u ON u.id = ug.usuario_id
INNER JOIN rbac_grupos g ON g.id = ug.grupo_id
ORDER BY ug.tenant_id, u.nome, g.nome;
```

```sql
SELECT modulo_chave, acao_permissao, endpoint, acao_requisicao, metodo_http
FROM rbac_politicas_api
WHERE ativo = 1
ORDER BY endpoint, acao_requisicao;
```

```sql
SELECT tenant_id, usuario_id, status, dispositivo, iniciada_em, ultima_atividade_em
FROM rbac_sessoes
ORDER BY id DESC
LIMIT 20;
```

## Comportamento após a implantação

Os usuários já existentes continuam associados a grupos de compatibilidade por tenant: **Visualizador**, **Operador**, **Gerente** e **Administrador**. Os grupos padrão são protegidos para não serem apagados pela Central de Usuários; grupos personalizados podem ser criados e receber permissões pontuais.

A decisão efetiva segue a ordem abaixo.

| Prioridade | Regra |
|---|---|
| 1 | O tenant vem exclusivamente da sessão autenticada |
| 2 | Usuário inativo ou sessão revogada não acessa a aplicação |
| 3 | Uma exceção individual de **negar** prevalece sobre qualquer permissão de grupo |
| 4 | Uma permissão individual de permitir complementa os grupos do usuário |
| 5 | Sem permissão efetiva, a operação retorna **HTTP 403** e é auditada |
| 6 | O menu e a rota SPA refletem a decisão, mas o backend permanece a fonte de verdade |

## Roteiro de aceite

| Cenário | Ação | Resultado esperado |
|---|---|---|
| Administrador | Abrir **Usuários > Grupos & Perfis** | Cria e edita grupo sem alterar grupo de compatibilidade |
| Operador | Tentar chamar ação administrativa de usuário pela interface ou URL | Resposta HTTP 403 quando não houver permissão efetiva |
| Visualizador | Abrir rota de módulo não permitido | Tela de acesso negado e menu sem o item bloqueado |
| Sessão | Revogar sessão na aba **Sessões & Auditoria** | Usuário desconectado na próxima verificação automática |
| Auditoria | Criar grupo, alterar vínculo ou revogar sessão | Evento aparece no tenant correto com hash de integridade |
| Isolamento | Repetir com dois tenants distintos | Grupos, sessões, usuários e auditorias não se misturam |

## Reversão

A reversão deve ser feita somente se o teste de aceite falhar e não puder ser corrigido sem interromper a operação. Primeiro, retorne os arquivos PHP/JS/HTML ao commit anterior. Em seguida, restaure o backup do banco **apenas se necessário**. As tabelas `rbac_*` são aditivas e não removem o esquema legado; mantê-las não afeta o login antigo após o retorno dos arquivos anteriores.

> Não execute `DROP TABLE` em produção como estratégia de reversão. Preserve auditorias e sessões para análise, e prefira a restauração controlada do backup quando houver necessidade comprovada.

## Segurança operacional

O ERP não grava senhas, tokens, segredos de agente ou cabeçalhos de autorização na auditoria RBAC. A auditoria sanitiza campos sensíveis, armazena IP e user-agent para rastreabilidade e encadeia os eventos por hash dentro de cada tenant.

A Central deve ser administrada somente por usuários que já possuam as permissões efetivas `usuarios.configurar`, `admin_sessoes.executar` e `auditoria.visualizar`. Alterações de grupos e exceções invalidam a revisão de permissões do tenant; a decisão nova passa a valer na próxima requisição protegida.

## Arquivos principais do pacote

| Arquivo | Finalidade |
|---|---|
| `sql/migration_rbac_multitenant_mysql57.sql` | Estrutura e compatibilidade de dados do RBAC |
| `api/rbac_helper.php` | Autorização, cache, sessão e auditoria encadeada |
| `api/rbac_api_controller.php` e `api/api_rbac.php` | Operações de grupos, permissões efetivas, sessões e auditoria |
| `api/api_usuarios.php` | Criação, edição, inativação e exclusão com RBAC |
| `frontend/pages/usuarios.html` e `frontend/js/pages/usuarios.js` | Central de Usuários, Grupos, Sessões e Auditoria |
| `frontend/js/menu-controller.js` e `frontend/js/app-router.js` | Menu e bloqueio de rota por permissão |

## Validação técnica executada

A entrega foi validada com análise de compatibilidade MariaDB 5.7, verificação de sintaxe PHP dos arquivos alterados, verificação de sintaxe JavaScript, validação estática do contrato RBAC e checagem da ausência de `ADD COLUMN IF NOT EXISTS` na migration. A execução funcional contra o banco de produção deve seguir o roteiro acima após o backup.
