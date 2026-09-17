# Relatório de ocupantes de veículos — 2026-09-17

## Estado encontrado

A funcionalidade havia existido em alterações locais anteriores, mas foi removida quando foram descartadas as mudanças não relacionadas à correção da placa. O código publicado não possuía a opção de ocupantes no filtro, embora o cadastro e a API de Registro Manual já persistissem os ocupantes.

## Implementação atual

A tela `layout-base.html?page=relatorios` agora possui duas formas de consulta:

- O checkbox **Ocupantes** inclui as linhas de ocupantes no relatório geral, junto com os demais registros.
- O tipo **Ocupantes de Veículos** consulta o endpoint específico e mostra uma linha por ocupante, com placa, modelo, unidade, titular, classificação do titular, ocupante, classificação do ocupante, entrada/saída, status e observação.

A classificação usa o campo `tipo` persistido no lançamento (`Morador`, `Visitante` ou `Prestador`). Cada ocupante é localizado pelo relacionamento `registro_titular_id`, sempre no mesmo `tenant_id`, e sua linha é identificada por `papel_veiculo = 'OCUPANTE'`.

Os filtros de período, horário, placa, modelo, unidade, nome, classificação e somente liberados são aplicados no servidor. A busca local também reconhece o nome e a classificação do titular e do ocupante. Os indicadores do modo detalhado apresentam total de ocupantes, titulares relacionados, ocupantes visitantes e ocupantes prestadores.

CSV e PDF foram adaptados para o modo detalhado. O PDF usa `api/api_relatorio_ocupantes_pdf.php`, com autenticação, tenant da sessão, prepared statements e escaping HTML.

## Segurança

O endpoint `api/api_registros.php?acao=relatorio_ocupantes` resolve o tenant pelo contexto autenticado e não aceita `tenant_id` do navegador. O titular e o morador relacionado também são unidos com filtro do mesmo tenant. Os parâmetros de busca são bindados em prepared statements.

## Validação

Foram executados `php -l api/api_registros.php`, `php -l api/api_relatorio_ocupantes_pdf.php`, `node --check frontend/js/pages/relatorios.js` e `git diff --check`, todos sem erros.
