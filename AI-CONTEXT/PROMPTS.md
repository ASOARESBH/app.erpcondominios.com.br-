# Prompts Especializados

Utilize estes prompts para acelerar tarefas comuns no sistema.

## 1. Criar Novo Módulo (Frontend + Backend)
```text
Atuando como AI Context Engineer, crie um novo módulo chamado "[NOME_MODULO]".
Siga estritamente as regras do SKILL.md.
1. Crie a API em `api/api_[nome].php` estendendo `ApiBase`.
2. Crie a view em `frontend/pages/[nome].html`.
3. Crie o JS em `frontend/js/pages/[nome].js` exportando `init()`.
4. Crie o CSS em `assets/css/pages/[nome].css`.
5. Adicione a rota no `menu-controller.js`.
```

## 2. Refatorar Script Procedural para ApiBase
```text
Refatore o arquivo `api/api_[nome].php`.
Ele atualmente usa `switch($_GET['action'])` procedural.
Transforme-o em uma classe orientada a objetos estendendo `ApiBase`, mantendo exatamente as mesmas regras de negócio, queries SQL e nomes de endpoints.
Garanta que a autenticação via `auth_helper.php` seja mantida no construtor.
```

## 3. Criar Relatório PDF
```text
Crie uma nova API para geração de PDF chamada `api_relatorio_[nome]_pdf.php`.
Utilize a biblioteca TCPDF/FPDF já existente no projeto.
O relatório deve buscar dados da tabela `[tabela]`, filtrar por `data_inicio` e `data_fim`, e gerar um PDF com cabeçalho contendo a logo da associação.
```
