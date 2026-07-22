# Módulo de Gestão de Estoque

## Visão Geral

Este documento detalha a implementação e o uso do módulo de **Gestão de Estoque**, uma adição robusta ao sistema de controle de acesso do condomínio Serra da Liberdade. O módulo foi desenvolvido para oferecer um controle completo e intuitivo sobre os produtos e materiais do condomínio, desde o cadastro e categorização até o registro detalhado de entradas e saídas, incluindo a vinculação de retiradas a moradores.

O sistema é composto por uma API backend em PHP, um banco de dados MySQL e uma interface frontend em HTML, CSS e JavaScript, seguindo o padrão visual e funcional do restante da aplicação.

## Arquivos do Módulo

O módulo é composto pelos seguintes arquivos:

- **`database_estoque.sql`**: Script SQL para criação de todas as tabelas, views, triggers e procedures necessários para o funcionamento do módulo de estoque.
- **`api_estoque.php`**: A API RESTful que centraliza toda a lógica de negócio, incluindo CRUD de produtos, gestão de movimentações, geração de relatórios e alertas.
- **`estoque.html`**: A página principal do módulo, que funciona como um dashboard, exibindo estatísticas, listando todos os produtos com opções de busca, filtro, cadastro, edição e exclusão.
- **`entrada_estoque.html`**: Interface dedicada ao registro de entradas de produtos no estoque, permitindo associar a nota fiscal e o fornecedor.
- **`saida_estoque.html`**: Interface para registrar a saída de produtos, com a funcionalidade de associar a retirada a um **morador específico** ou à **administração**.
- **`relatorio_estoque.html`**: Página para geração de relatórios avançados com múltiplos filtros (período, tipo de movimentação, produto) e opção de exportação para **PDF** e **Excel**.
- **`administrativa.html`**: O menu principal do módulo administrativo, agora com um card de acesso direto para a Gestão de Estoque.

## Funcionalidades Implementadas

O módulo oferece um conjunto completo de funcionalidades para uma gestão de estoque eficiente:

| Funcionalidade | Descrição |
| :--- | :--- |
| **Dashboard e CRUD de Produtos** | A página `estoque.html` exibe cards com estatísticas (valor total em estoque, número de produtos, etc.) e uma tabela completa para gerenciar produtos. |
| **Categorias de Produtos** | Os produtos podem ser organizados em categorias, facilitando a gestão e a geração de relatórios. |
| **Entrada de Estoque** | A página `entrada_estoque.html` permite registrar a entrada de novos itens, com campos para fornecedor e nota fiscal. |
| **Saída de Estoque com Vínculo** | A página `saida_estoque.html` permite registrar saídas, com a opção crucial de vincular a retirada a um morador (buscando da base de dados existente) ou à administração do condomínio. |
| **Relatórios Avançados** | A página `relatorio_estoque.html` oferece relatórios dinâmicos, incluindo histórico de movimentações, saldo atual e saídas por morador, com filtros e exportação. |
| **Alertas de Estoque Baixo** | O sistema monitora automaticamente o estoque e pode gerar alertas quando a quantidade de um produto atinge um nível mínimo pré-definido. |
| **Histórico Completo** | Todas as entradas e saídas são registradas na tabela de movimentações, garantindo um histórico completo e auditável. |
| **Interface Responsiva** | Todas as páginas do módulo são totalmente responsivas e se adaptam a diferentes tamanhos de tela (desktop, tablet e mobile). |

## Como Instalar e Usar

1.  **Banco de Dados**: Execute o script `database_estoque.sql` no seu banco de dados MySQL. Ele criará as tabelas `estoque_categorias`, `estoque_produtos`, `estoque_movimentacoes` e `estoque_alertas`, além das views e procedures necessárias.

2.  **Arquivos**: Faça o upload de todos os arquivos (`.php` e `.html`) para o diretório raiz do seu sistema no servidor web.

3.  **Configuração da API**: Certifique-se de que o arquivo `api_estoque.php` tenha as permissões corretas de execução e que o caminho para a API de moradores em `saida_estoque.html` (`../sistema_acesso_portaria/api_moradores.php`) esteja correto, caso sua estrutura de diretórios seja diferente.

4.  **Acesso**: Acesse o módulo através do novo card "Gestão de Estoque" na página `administrativa.html`.

## Conclusão

O módulo de Gestão de Estoque é uma ferramenta poderosa e flexível, projetada para atender às necessidades específicas do condomínio. Com sua interface intuitiva, funcionalidades avançadas e integração com os dados dos moradores, o módulo simplifica o controle de insumos e materiais, aumenta a transparência e otimiza a administração dos recursos do condomínio.

