# Deploy — Financeiro: Inadimplência

## Objetivo

Este pacote instala o módulo de **Inadimplência** do ERP Condomínios. O módulo importa PDFs do Relatório de Inadimplência Detalhado BRCondos, salva o original em BLOB por tenant, preserva snapshots históricos e oferece dashboard, comparação, ranking, CSV e geração de PDF pelo diálogo de impressão do navegador.

> A importação é analítica. Ela não cria, baixa, altera ou negocia títulos de contas a receber.

## Pré-requisitos

| Requisito | Validação esperada |
|---|---|
| Banco | `inlaud99_erpcondor` selecionado no phpMyAdmin |
| Banco de arquivos | tabelas `tenant_arquivos` e `tenant_arquivo_referencias` já criadas pela migration de arquivos tenant |
| PHP | extensão `fileinfo` habilitada |
| Utilitário | `pdftotext` disponível no ambiente PHP da hospedagem |
| Permissão | usuário com perfil `gerente` ou superior e módulo `inadimplencia` liberado ao tenant |

## Ordem de implantação

Primeiro faça backup completo do banco de produção e do diretório da aplicação. No phpMyAdmin, com o banco `inlaud99_erpcondor` selecionado, importe o arquivo:

```text
sql/migration_inadimplencia_mysql57.sql
```

Em seguida, envie o conteúdo do ZIP preservando exatamente as pastas `api/`, `frontend/`, `assets/`, `sql/` e `AI-CONTEXT/`. Não mova o PDF importado para `uploads/`: o módulo usa o armazenamento BLOB centralizado.

Após o upload, faça `Ctrl+F5` em uma sessão autenticada e acesse **Financeiro → Inadimplência**. Caso o card não esteja disponível, abra o gerenciamento de módulos do tenant e habilite `inadimplencia`; o Super-Admin mantém acesso conforme as regras de permissões globais.

## Teste de aceitação

| Passo | Resultado esperado |
|---|---|
| Abrir Financeiro | Card e submenu **Inadimplência** aparecem para perfil gerente |
| Primeiro acesso | Tela mostra estado vazio e formulário de PDF |
| Importar BRCondos | Novo snapshot `CONCLUIDO`, PDF BLOB e KPIs carregados |
| Validar totais | Comparar os valores da tela com a seção `Resumo` do PDF |
| Reimportar outro período | Histórico exibe os dois snapshots e seção “O que mudou” fica ativa |
| Buscar ranking | Busca, carteira, ordenação e paginação funcionam sem misturar tenants |
| Exportar CSV | Arquivo UTF-8 com BOM abre corretamente no Excel |
| Gerar PDF | Diálogo de impressão permite salvar a visão atual em PDF |
| Ver outra empresa | Não deve existir qualquer importação, Gleba, CPF ou PDF do tenant anterior |

## Diagnóstico de falhas

Se a API retornar que `pdftotext` não está disponível, solicite à HostGator a habilitação do pacote Poppler/`pdftotext` para a conta PHP. Não substitua a chamada por comando vindo do navegador e não aceite arquivo fora de PDF.

Se o parser concluir sem lançamentos, valide se o arquivo é o **Relatório de Inadimplência Detalhado** e não uma exportação resumida ou digitalização sem camada de texto. O log financeiro registra o evento em `logs_financeiro` com módulo `inadimplencia`.

Se houver alerta de conciliação, os dados continuam preservados no snapshot, mas o relatório deve ser conferido antes de decisões de cobrança. A divergência nunca é “corrigida” por ajuste automático no banco.

## Referência técnica

A arquitetura, tabelas, chaves de comparação, segurança e regra de tendência estão documentadas em [`AI-CONTEXT/INADIMPLENCIA.md`](AI-CONTEXT/INADIMPLENCIA.md).
