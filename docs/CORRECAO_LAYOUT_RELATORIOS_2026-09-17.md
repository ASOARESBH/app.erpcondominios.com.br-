# Correção do layout principal e da tabela de Relatórios — 2026-09-17

## Causa

O `.main-content` tinha `width: 100%` e também `margin-left` reservado para a sidebar fixa. Como a margem não era descontada da largura, o conteúdo excedia a viewport. O `overflow-x: hidden` do `body` escondia a parte excedente, causando o corte lateral da tela e da tabela.

## Correções

O layout global agora usa `calc(100% - largura da sidebar)` no `.main-content`, com `min-width: 0`, e possui uma regra correspondente para a sidebar recolhida (`68px`). Em telas de até `768px`, a margem e a largura retornam a `0` e `100%`, respectivamente.

A página de Relatórios usa toda a largura útil disponível. A tabela mantém uma largura mínima de `1250px` para não comprimir as colunas do relatório de ocupantes, enquanto o `.table-wrapper` controla a rolagem horizontal e vertical. O contêiner interno não esconde mais o conteúdo da tabela. Nomes, classificações e observações possuem largura mínima e quebra controlada.

A versão do CSS dinâmico do roteador foi atualizada para `20260917-layout-1`, evitando que o navegador permaneça com uma folha de estilos específica antiga em cache.

## Impacto funcional

A alteração é visual e de dimensionamento. Não muda APIs, consultas, autenticação, filtros, permissões, registros, exportações ou regras de negócio. O comportamento esperado é que somente a tabela tenha rolagem horizontal quando a viewport não comportar todas as colunas.

## Validação

Foram conferidos o diff restrito aos arquivos de CSS e versionamento do CSS, `git diff --check` e capturas de teste em viewport desktop de `1920px`, notebook de `1482px` e mobile de `768px`. As capturas demonstraram que a área principal termina na borda direita da viewport, que a tabela apresenta barra de rolagem interna sem cortar o card e que a página mobile não cria overflow horizontal global.
