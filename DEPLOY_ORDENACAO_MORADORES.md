# Deploy — Ordenação da Lista de Moradores

## Escopo

Esta entrega adiciona um seletor de ordenação à lista principal de **Configurações → Moradores → Moradores**. A ordenação ocorre no servidor e permanece correta ao navegar pelas páginas.

A aba **Dependentes** não foi alterada.

## Arquivos modificados

| Arquivo | Alteração |
|---|---|
| `frontend/pages/moradores.html` | Adiciona o seletor de seis opções na barra de busca da lista principal. |
| `frontend/js/pages/moradores.js` | Mantém estado `_ordenacaoAtual`, envia `ordenacao` à API e recarrega a página 1 ao trocar o critério. |
| `assets/css/pages/moradores.css` | Ajusta somente a barra principal e seu comportamento responsivo, reutilizando estilos existentes. |
| `api/api_moradores.php` | Converte `ordenacao` por whitelist fixa antes do `ORDER BY`. |
| `AI-CONTEXT/MODULES.md` e `AI-CONTEXT/CHANGELOG.md` | Registram comportamento, decisão técnica e validações. |

## Segurança e compatibilidade

A API aceita apenas os valores `nome_asc`, `nome_desc`, `unidade_asc`, `unidade_desc`, `id_asc` e `id_desc`. Valores ausentes ou inválidos usam `nome ASC`, exatamente como a listagem anterior. Não existe concatenação do parâmetro recebido no SQL.

Chamadas existentes, como `api_moradores.php?por_pagina=0` usadas pelos selects de Dependentes, continuam funcionando porque `ordenacao` é opcional e o fallback é `nome_asc`.

## Implantação

Envie o conteúdo do ZIP para `public_html`, preservando as pastas `api/`, `frontend/`, `assets/` e `AI-CONTEXT/`. Não existe migration SQL nesta entrega. Após o upload, execute `Ctrl+F5` em `layout-base.html?page=moradores`.

## Resultado das validações técnicas

| Item | Resultado antes do deploy | Como validar no ambiente |
|---|---|---|
| Sintaxe PHP e JavaScript | Aprovada por `php -l` e `node --check`. | Abrir a lista sem erros no console. |
| Seis opções de ordenação | Confirmadas no markup e no mapa do backend. | Alternar todas as opções. |
| Padrão Nome A→Z | Mantido por padrão no select e na API. | Abrir a página sem interagir. |
| Paginação no servidor | Preservada; `ordenacao` é enviada no mesmo `URLSearchParams` de `pagina` e `por_pagina`. | Navegar entre páginas 2 e 3 em cada ordem. |
| Busca combinada | Preservada; `busca` e `ordenacao` são enviados juntos. | Pesquisar nome, CPF ou unidade e trocar a ordem. |
| Proteção contra SQL injection | Aprovada por whitelist estática; valor inválido cai em `nome_asc` e gera `error_log`. | Testar `ordenacao=1; DROP TABLE moradores`. |
| Responsividade | Regras exclusivas da barra principal configuradas em `max-width: 640px`. | Reduzir a viewport e conferir quebra em linhas. |
| Aba Dependentes | Nenhuma alteração em HTML, JS ou CSS específico dessa aba. | Abrir Dependentes e realizar busca. |

## Teste funcional obrigatório após deploy

Faça login em um tenant com mais de uma página de moradores e execute os nove testes da tabela acima. Para o teste de parâmetro inválido, use o DevTools ou uma chamada autenticada; a resposta deve continuar JSON válida, com os registros em Nome A→Z.
