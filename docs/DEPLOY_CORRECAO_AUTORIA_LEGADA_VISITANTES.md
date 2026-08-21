# Correção de autoria legada — Visitantes

## Diagnóstico confirmado

A tela não deixou de mostrar o usuário. Ela estava exibindo corretamente **Cadastro legado** para visitantes criados antes da implantação das colunas de autoria, pois esses registros não possuíam `cadastrado_por_usuario_id` nem `cadastrado_por_morador_id`.

O cadastro associado a `morador_id` já foi resolvido corretamente como **Morador**. Para os demais registros antigos, não é seguro atribuir o usuário que está logado hoje: isso falsificaria o histórico.

## Correção entregue

A API agora resolve a autoria em ordem segura: nome persistido no visitante, usuário vinculado pelo identificador no mesmo tenant, morador vinculado pelo identificador no mesmo tenant e, somente então, **Cadastro legado**.

A migration de correção também procura uma evidência histórica em `logs_sistema`: ela somente associa um usuário quando o log contém o ID exato do visitante, um nome de usuário e o vínculo desse usuário com o mesmo tenant. Os registros sem evidência continuam como legado.

Os novos visitantes passam a validar o usuário autenticado diretamente na tabela `usuarios` com vínculo em `usuario_tenant`, gravam o nome e o ID do autor e escrevem o mesmo nome no log de auditoria.

## Ordem de publicação

| Ordem | Arquivo | Ação |
|---:|---|---|
| 1 | `sql/correcao_autoria_visitantes_historico_mysql57.sql` | Executar no phpMyAdmin, após backup |
| 2 | `api/api_visitantes.php` | Enviar para `public_html/api/` |
| 3 | `api/api_relatorio_visitantes_pdf.php` | Enviar para `public_html/api/` |

> A migration anterior `migration_autoria_visitantes_mysql57.sql` é pré-requisito. Não execute esta correção antes de criar as colunas de autoria.

## Validação

1. Execute as consultas de auditoria que constam ao final da migration e confirme a redução dos registros `LEGADO` somente onde houver evidência.
2. Cadastre um novo visitante com um usuário administrativo. A listagem deve apresentar o nome desse usuário e o tipo **Funcionário**.
3. Gere o relatório PDF e confirme que ele apresenta o mesmo nome e tipo.
4. Atualize o navegador com **Ctrl+F5**.

## Tratamento dos legados sem evidência

Se um visitante antigo continuar como **Cadastro legado** após a migration, o banco não contém prova de quem o cadastrou. Mantenha-o assim para preservar a auditoria; uma eventual correção manual deve ser baseada em confirmação operacional, não no usuário atualmente conectado.
