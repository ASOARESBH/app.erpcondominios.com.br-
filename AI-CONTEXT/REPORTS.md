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
- **Relatório de Ocupantes de Veículos**: ao marcar `Destino + condutor + ocupantes`, a tela consulta `api/api_registros.php?acao=relatorio_ocupantes` e agrupa pela chave `COALESCE(registro_titular_id, id)`. Cada grupo mostra o morador/unidade de destino, o condutor principal e todos os ocupantes secundários vinculados, com nome completo, CPF/documento, data/hora, Entrada/Saída, status e observação. Os filtros de nome, CPF, unidade e tipo preservam o grupo completo quando qualquer integrante coincide. A unidade prioriza o morador relacionado, depois o titular e só então a linha filha, evitando herdar uma Gleba incorreta de dados antigos. A tela abre somente com o dia corrente, aplica o intervalo de data no banco e não trunca grupos por limite de linhas; CSV e PDF seguem a mesma estrutura.
