# Manual do Sistema (Integração)

O ERP possui um módulo interno chamado "Manual do Sistema" (`manual.html`).

## 1. Como funciona
É um módulo com artigos, categorias e busca inteligente para ajudar os usuários a utilizarem o ERP.
Os artigos ficam salvos no banco de dados nas tabelas `manual_artigos`, `manual_categorias` e `manual_modulos`.

## 2. Relação com a IA
O AI Context Framework (`AI-CONTEXT/`) é para a **Inteligência Artificial** entender o código.
O Módulo "Manual do Sistema" (`manual.html`) é para os **Humanos** entenderem como usar o ERP.

A IA deve manter o Manual do Sistema atualizado sempre que criar uma nova funcionalidade que afete o usuário final, inserindo um novo artigo via banco de dados ou instruindo o administrador a fazê-lo.
