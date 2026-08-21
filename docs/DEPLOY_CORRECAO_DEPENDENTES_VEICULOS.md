# Correção de dependentes no cadastro de veículos

## Diagnóstico

O dependente estava corretamente cadastrado e vinculado ao morador, mas a tela de veículos interpretava o retorno da API de forma incorreta. A API retorna uma estrutura paginada em `dados.dados`, enquanto a interface procurava um array diretamente em `dados`. Por isso, mesmo com registros retornados, a tela apresentava a mensagem de que o morador não possuía dependentes.

A auditoria também identificou pontos sem filtro obrigatório de tenant na API de dependentes. Eles foram corrigidos para impedir leitura, criação ou busca de dependentes de outro condomínio.

## Alterações

| Arquivo | Correção |
|---|---|
| `frontend/js/pages/veiculos.js` | Lê o envelope paginado correto, preserva sessão, solicita até 100 dependentes e registra diagnóstico no console. |
| `api/api_dependentes.php` | Lista e obtém dependentes somente do tenant da sessão; valida morador no mesmo tenant; grava `tenant_id` na criação. |

## Publicação

Envie os arquivos do ZIP preservando as pastas abaixo:

| Arquivo | Destino |
|---|---|
| `frontend/js/pages/veiculos.js` | `public_html/frontend/js/pages/veiculos.js` |
| `api/api_dependentes.php` | `public_html/api/api_dependentes.php` |

Após o upload, faça **Ctrl+F5** no navegador.

## Validação

1. Abra **Veículos > Cadastro**.
2. Selecione o morador **SORAYA BATISTA**.
3. O texto deve apresentar a quantidade de dependentes encontrados e o botão **Dependentes** deve ser habilitado.
4. Selecione **Willian Valadão de Souza** e salve o veículo.
5. Confirme que o veículo aparece na listagem com o dependente associado.
6. Tente selecionar um dependente de outro morador: a API deve bloquear o vínculo.
