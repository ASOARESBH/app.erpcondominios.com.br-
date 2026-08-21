# Autoria de cadastro — Visitantes

## Resultado entregue

A listagem de visitantes passa a apresentar duas novas colunas ao lado de **Doc. Digitalizado**: **Cadastrado por** e **Tipo**. O tipo distingue **Funcionário**, **Morador** e **Legado**.

O cadastro administrativo obtém o autor exclusivamente da sessão autenticada e grava o identificador e o nome do usuário. O cadastro feito pelo Portal do Morador grava o morador autenticado como autor. Os dados não são aceitos do navegador, evitando que alguém forje a autoria.

A aba **Relatórios** recebeu filtro por tipo de cadastro, inclusão das duas informações na prévia e no CSV, além de passagem do filtro ao PDF. O gerador PDF também foi corrigido para filtrar obrigatoriamente pelo `tenant_id` da sessão.

## Arquivos e ordem de publicação

| Ordem | Arquivo | Destino |
|---:|---|---|
| 1 | `sql/migration_autoria_visitantes_mysql57.sql` | Executar no banco `inlaud99_erpcondor` pelo phpMyAdmin, após backup |
| 2 | `api/api_visitantes.php` | `public_html/api/api_visitantes.php` |
| 3 | `api/api_portal_morador.php` | `public_html/api/api_portal_morador.php` |
| 4 | `api/api_relatorio_visitantes_pdf.php` | `public_html/api/api_relatorio_visitantes_pdf.php` |
| 5 | `frontend/pages/visitantes.html` | `public_html/frontend/pages/visitantes.html` |
| 6 | `frontend/js/pages/visitantes.js` | `public_html/frontend/js/pages/visitantes.js` |

> **Importante:** execute a migration antes de enviar os arquivos PHP. Ela cria as colunas necessárias, preserva os moradores associados nos registros antigos e marca os demais como `LEGADO`.

## Validação pós-deploy

1. Entre no ERP como um usuário/funcionário e cadastre um visitante. A listagem deve mostrar o nome do usuário e o tipo **Funcionário**.
2. Cadastre um visitante pelo Portal do Morador. A listagem administrativa deve mostrar o nome do morador e o tipo **Morador**.
3. Abra **Visitantes > Relatórios**, filtre cada tipo e confirme que a prévia, o CSV e o PDF exibem **Cadastrado por** e **Tipo**.
4. Confirme que o relatório PDF não mostra visitantes de outro condomínio.
5. Faça **Ctrl+F5** após publicar os arquivos frontend.

## Consultas de auditoria

```sql
SELECT tenant_id, cadastrado_por_tipo, COUNT(*) AS total
FROM visitantes
GROUP BY tenant_id, cadastrado_por_tipo
ORDER BY tenant_id, cadastrado_por_tipo;

SELECT id, tenant_id, nome_completo, documento,
       cadastrado_por_nome, cadastrado_por_tipo,
       cadastrado_por_usuario_id, cadastrado_por_morador_id
FROM visitantes
ORDER BY tenant_id, id DESC
LIMIT 100;
```
