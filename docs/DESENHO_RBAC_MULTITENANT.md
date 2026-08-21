# Desenho Técnico — Central de Identidade, Acesso e Auditoria

## Princípios de segurança

A autorização será decidida no backend, a partir do usuário autenticado, do `tenant_id` guardado na sessão, do recurso solicitado e da ação solicitada. O frontend somente refletirá a decisão recebida; esconder menus ou botões jamais concederá ou revogará acesso por si só.

> **Regra operacional:** sem autorização efetiva, a operação não executa; toda negativa é retornada como HTTP 403 e registrada na auditoria. Toda operação autorizada que altera dados registra o ator e, quando aplicável, o estado anterior e posterior.

## Modelo de dados

| Entidade | Função | Isolamento |
|---|---|---|
| `rbac_modulos` | Catálogo real de módulos e submódulos existentes, com árvore por `modulo_pai_id`. | Global, pois define o produto. |
| `rbac_permissoes` | Ações suportadas por cada módulo, como visualizar, criar, editar, excluir, exportar, imprimir, importar, aprovar, executar e configurar. | Global. |
| `rbac_grupos` | Perfis configuráveis por condomínio, incluindo grupos iniciais migrados de perfis legados. | `tenant_id` obrigatório; grupos globais somente para Super Admin. |
| `rbac_grupo_permissoes` | Concessão ou negação de ações para cada grupo e escopo futuro de dados. | Herdado do grupo/tenant. |
| `rbac_usuario_grupos` | Associação de usuário a grupo por tenant. | `tenant_id` obrigatório. |
| `rbac_usuario_permissoes` | Exceções individuais de concessão ou negação. | `tenant_id` obrigatório. |
| `rbac_sessoes` | Sessões, IP, user-agent, dispositivo, início, última atividade, fim e motivo. | `tenant_id` obrigatório para sessões operacionais. |
| `rbac_auditoria` | Eventos imutáveis, resultado, requisição, antes/depois e cadeia de integridade. | `tenant_id` obrigatório quando houver contexto operacional. |
| `rbac_configuracoes` | Revisão de política por tenant para invalidação eficiente de cache. | `tenant_id` obrigatório. |

As tabelas existentes `modulos_sistema` e `usuario_modulos` serão preservadas em modo de compatibilidade durante a migração. Os dados serão copiados para o novo modelo, não apagados.

## Resolução de permissões

A decisão segue esta ordem:

1. **Super Admin global** possui acesso estrutural completo, sempre auditado.
2. Uma **negação explícita** do usuário ou grupo bloqueia a ação, exceto para o Super Admin global.
3. As permissões de todos os grupos ativos do usuário no tenant são combinadas.
4. Concessões individuais podem ampliar permissões recebidas de grupo, desde que o ator responsável pela concessão possa concedê-las.
5. O perfil atual (`admin`, `gerente`, `operador`, `visualizador`) é traduzido para grupos de compatibilidade durante a transição.
6. Na ausência de concessão explícita, aplica-se **negação por padrão**.

O escopo é armazenado desde o início com os valores `GLOBAL`, `DEPARTAMENTO`, `UNIDADE`, `PROPRIO` e `ATRIBUIDO`. A primeira entrega aplica `GLOBAL` e deixa os demais preparados para regras por registro quando cada módulo possuir colunas de referência compatíveis.

## Compatibilidade e implantação gradual

A migração será executada em etapas, sem substituir de uma vez a hierarquia existente. Primeiro serão criadas as tabelas RBAC, catálogo e grupos de compatibilidade. Em seguida, permissões de `usuario_modulos` serão convertidas em exceções individuais e os perfis atuais serão associados a grupos equivalentes no tenant.

O helper central calculará permissões efetivas e registrará decisões. Inicialmente, as APIs prioritárias e a Central de Identidade adotarão a exigência RBAC explícita. As APIs restantes serão integradas por catálogo de políticas, com bloqueio efetivo após a confirmação de que todos os usuários existentes receberam migração compatível. Não haverá fallback para outro tenant.

## Mitigações contra escalação de privilégio

Um ator só poderá administrar grupos, usuários ou permissões se possuir a permissão administrativa correspondente e não poderá conceder uma ação que não possua. Ele também não poderá alterar o próprio grupo, conceder privilégio superior a si próprio, desligar a própria auditoria ou eliminar registros de auditoria.

Ações críticas — bloquear usuário, encerrar sessão, excluir grupo, redefinir senha e alterar permissões — exigirão confirmação explícita na interface, autorização de backend e evento auditável.

## Catálogo atual inventariado

O catálogo inicial será alimentado pelos módulos e páginas já existentes no repositório. A auditoria identificou 58 páginas SPA; 45 já possuem mapeamento de permissões e 13 páginas operacionais serão incluídas como submódulos, como LPR, Unidades, Ordens de Serviço, Sessões, Protocolo, Rondas, Central PWA e configurações de alertas.

O catálogo será versionado em migration, e qualquer página sem módulo correspondente será negada pelo guard de rota até que seja cadastrada explicitamente. Essa abordagem elimina o comportamento atual de permitir páginas não mapeadas.
