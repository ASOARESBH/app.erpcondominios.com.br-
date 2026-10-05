# Relatórios Disponíveis

O sistema possui relatórios em PDF (gerados pelo PHP) e relatórios na tela (HTML/JS).

## 1. Relatórios em PDF
| Relatório | Arquivo API |
|---|---|
| Relatório de Moradores | `api_relatorio_moradores_pdf.php` |
| Relatório de Visitantes | `api_relatorio_visitantes_pdf.php` |
| Relatório de Veículos | `api_relatorio_veiculos_pdf.php` |
| Relatório de Acessos | `api_relatorio_acessos_pdf.php` |
| Relatório de Hidrômetro | `api_relatorio_hidrometro_pdf.php` |
| Relatório de Abastecimento | `api_relatorio_abastecimento_pdf.php` |
| Relatório de RH | `api_rh_relatorio_pdf.php` |

## 2. Relatórios na Tela
- **Dashboard Principal**: KPIs de moradores, consumo de água, saldo financeiro, chamados abertos.
- **Relatórios Bancários**: Extrato e conciliação.
- **Relatórios de Inventário**: Posição de estoque.
- **Relatório de Ocupantes de Veículos**: ao marcar `Ocupantes / ramificação`, a tela consulta `api/api_registros.php?acao=relatorio_ocupantes` e exibe cada veículo como titular, seguido dos ocupantes relacionados por `registro_titular_id`, com nome completo, CPF/documento, unidade, data/hora, Entrada/Saída, status e observação. A unidade prioriza o morador relacionado, depois o titular e só então a linha filha, evitando herdar uma Gleba incorreta de dados antigos. A tela abre somente com o dia corrente e envia filtros de período, placa, modelo, unidade, nome/CPF, tipo e liberação ao banco, com limite controlado; CSV e PDF também incluem os documentos.
