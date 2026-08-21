# Relatório Analítico de Visitantes e Prestadores

## Objetivo

A aba **Relatórios** do módulo Visitantes passa a oferecer dois modos. O modo **Padrão — Cadastros** mantém exatamente os filtros, indicadores, CSV e PDF já utilizados para a base cadastral. O novo modo **Analítico — Acessos** consolida registros de entrada e saída de visitantes e prestadores para apoiar a decisão da administradora.

## Indicadores analíticos

| Indicador | Critério | Uso gerencial |
|---|---|---|
| Acessos no período | Registros de `registros_acesso` dos tipos Visitante e Prestador | Mede o fluxo externo do condomínio. |
| Visitantes e prestadores | Separação pelo tipo gravado no acesso | Distingue visitas ocasionais de atividade operacional. |
| Horário de pico em 24h | Hora com mais acessos nos últimos 24h | Apoia escala de portaria e vigilância. |
| Ranking de glebas/unidades | Dez destinos com maior volume de acessos | Destaca áreas que exigem atenção. |
| Taxa de liberação | Registros liberados sobre o total do período | Evidencia eficiência e pendências do controle de acesso. |
| Pessoas mais registradas | Ranking nominal de recorrência | Ajuda a identificar prestadores e visitas frequentes. |
| Tendência diária | Série dos últimos 31 dias do período | Mostra crescimento, redução ou concentração de acessos. |

## Segurança e fonte dos dados

As consultas usam apenas `registros_acesso` e aplicam obrigatoriamente o `tenant_id` obtido da sessão autenticada. Portanto, nenhum indicador, ranking, CSV ou PDF agrega dados de outro condomínio. Não há migration a executar.

## Publicação

Envie os arquivos do pacote preservando a estrutura abaixo.

| Arquivo | Destino no HostGator |
|---|---|
| `frontend/pages/visitantes.html` | `public_html/frontend/pages/visitantes.html` |
| `frontend/js/pages/visitantes.js` | `public_html/frontend/js/pages/visitantes.js` |
| `api/api_visitantes.php` | `public_html/api/api_visitantes.php` |
| `api/api_relatorio_visitantes_pdf.php` | `public_html/api/api_relatorio_visitantes_pdf.php` |

Após o upload, faça **Ctrl+F5**. Em **Visitantes > Relatórios**, mantenha o tipo **Padrão — Cadastros** para a visualização existente ou escolha **Analítico — Acessos**, informe o período e clique em **Atualizar**.

## Validação

1. O relatório padrão deve manter todos os filtros de cadastro e as exportações existentes.
2. Ao escolher o modo analítico, os filtros cadastrais devem ser ocultados e o seletor de tipo deve continuar visível.
3. O painel deve mostrar cartões de visitante, prestador, horário de pico e taxa de liberação.
4. O ranking de Glebas/Unidades deve listar somente acessos do condomínio autenticado.
5. Os botões CSV e PDF devem exportar a mesma análise do período informado.
