# Roteiro de Rotação de Credenciais — Fase 1

**Objetivo:** substituir credenciais que podem ter sido expostas pela publicação do diretório Git, sem exibir valores e sem interromper a aplicação.

> Esta operação deve ser realizada no cPanel e nos painéis oficiais de cada integração. Não envie senhas, tokens ou chaves pelo chat. Nunca registre os valores em issues, commits, arquivos ZIP ou documentação.

## Classificação inicial

| ID | Tipo | Localização anterior | Estado | Ação nesta fase |
|---|---|---|---|---|
| SECRET-001 | Credencial do banco | Configuração PHP rastreada pelo Git | Potencialmente comprometida | Rotação obrigatória no cPanel |
| SECRET-002 | Chave de criptografia de e-mail | Antes derivada da senha do banco | Dependente da rotação do banco | Criar chave externa nova e regravar credenciais dos provedores |
| SECRET-003 | Credenciais de provedor de e-mail | Banco/configuração administrativa | Requer inventário no painel | Regravar/rotacionar antes de trocar a senha do banco |
| SECRET-004 | JWT secret | Variável de ambiente, se utilizado | Não confirmado nesta revisão | Inventariar antes de alterar; troca encerra tokens ativos |
| SECRET-005 | Token Control iD | Cadastro de dispositivos, se utilizado | Não confirmado nesta revisão | Inventariar e rotacionar em fase específica do módulo |
| SECRET-006 | Firebase service account | Apenas modelo detectado no repositório | Não confirmado como credencial real | Manter fora do webroot; revogar somente se um arquivo real for localizado |

## Ordem obrigatória

| Ordem | Ação | Condição para avançar |
|---:|---|---|
| 1 | Criar backup do document root e confirmar restauração disponível | Backup registrado no cPanel |
| 2 | Criar configuração externa com a credencial **atual** do banco | Aplicação continua conectando após o deploy da Fase 1 |
| 3 | Criar `ERP_EMAIL_CRYPTO_KEY` externa e aleatória | Arquivo privado com permissão 0600 |
| 4 | Regravar cada provedor de e-mail no painel administrativo | Teste controlado de envio concluído para cada provedor ativo |
| 5 | Criar nova senha forte para o usuário MySQL | Nova senha copiada apenas para o arquivo externo privado |
| 6 | Atualizar `ERP_DB_PASS` no arquivo externo | Login e consulta autenticada funcionam |
| 7 | Revogar/inutilizar a senha antiga do banco | Aplicação validada e demais serviços mapeados |
| 8 | Repetir varredura de exposição pública | `.git` e arquivos sensíveis retornam 403/404 |

## Rotação do banco de dados no cPanel

1. Abra **MySQL Databases** no cPanel.
2. Identifique o usuário MySQL efetivamente associado ao banco do ERP. Não infira o usuário a partir de documentação antiga.
3. Registre quais aplicações e tarefas dependem dele: aplicação web, scripts agendados, integrações e ferramentas administrativas.
4. Gere uma nova senha forte usando o gerador do cPanel.
5. Atualize imediatamente `ERP_DB_PASS` no arquivo privado `erp_config.php` fora de `public_html`.
6. Confirme a associação do usuário ao banco e os privilégios mínimos necessários.
7. Teste página de login, dashboard, uma consulta autenticada e um log de auditoria, sem alterar dados de moradores ou financeiro.
8. Se todos os testes forem positivos, invalide a senha anterior no cPanel.

**Critério de parada.** Se login, consulta ou registro de auditoria falhar, restaure apenas a senha anterior no arquivo privado, investigue a associação do usuário e não altere schema nem dados.

## Chave de criptografia dos provedores de e-mail

A partir desta entrega, os novos valores são criptografados com `ERP_EMAIL_CRYPTO_KEY`, uma chave independente da senha do banco. A aplicação mantém leitura compatível dos valores antigos apenas enquanto a senha do banco antiga ainda estiver disponível.

Antes da rotação do banco:

1. Adicione `ERP_EMAIL_CRYPTO_KEY` ao arquivo externo privado.
2. No painel administrativo, abra cada provedor de e-mail ativo e informe novamente sua credencial atual ou uma credencial já rotacionada no painel oficial.
3. Faça um único teste de envio autorizado para cada integração ativa.
4. Confirme que o provedor continua enviando após relogin.
5. Só depois prossiga com a rotação da senha MySQL.

Isso evita que valores antigos, cifrados com a derivação v1, se tornem indecifráveis após a troca do banco.

## JWT e sessões

Se a instalação definir uma variável de ambiente para JWT, identifique antes os módulos que a utilizam, a duração dos tokens e os usuários conectados. A rotação de JWT invalida sessões/token emitidos com o segredo anterior. Esta revisão não confirmou valor exposto, portanto **não altere JWT nesta fase sem inventário e janela de manutenção**.

## Integrações externas

| Integração | Ação | Observação |
|---|---|---|
| SMTP / Brevo / Resend | Rotacionar no painel oficial se a credencial estiver armazenada ou tiver sido incluída em backup exposto | Testar um envio controlado |
| Firebase | Revogar/criar conta de serviço somente se for localizado arquivo real, não o modelo | Nunca publicar JSON de service account |
| Control iD | Inventariar token por dispositivo e planejar rotação na fase do módulo | Não alterar agora a lógica Push |
| Pagamentos e bancos | Validar se há tokens reais em arquivos públicos ou histórico | Não executar transações durante o teste |

## Registro de execução (preencher no cPanel, sem valores)

| Credencial | Situação antes | Ação executada | Teste pós-rotação | Situação final |
|---|---|---|---|---|
| Banco de dados | Potencialmente comprometida | Pendente / concluída | Login + dashboard + consulta | Pendente / rotacionada |
| Chave de e-mail | Não existia externamente | Pendente / concluída | Envio controlado | Pendente / criada |
| Provedor de e-mail | A inventariar | Pendente / concluída | Envio controlado | Pendente / rotacionado |
| JWT | A inventariar | Não executar sem validação | Tokens/sessões | Pendente |
| Control iD | A inventariar | Não executar nesta fase | N/A | Pendente |

## Proibições

- Não copiar credenciais para `api/config.php`, `config.php`, JavaScript ou HTML.
- Não usar a credencial antiga para acessar serviços externos durante a auditoria.
- Não reescrever o histórico Git nesta fase.
- Não alterar schema, dados financeiros, moradores ou regras multi-tenant.
- Não confirmar a fase como concluída antes da validação HTTP e funcional pós-deploy.
