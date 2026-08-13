# Módulo Financeiro — Inadimplência

## Finalidade

O módulo `inadimplencia` importa o **Relatório de Inadimplência Detalhado** do BRCondos em PDF e transforma cada arquivo em um snapshot histórico isolado pelo `tenant_id` da sessão. Ele é destinado à análise gerencial; não cria, baixa, negocia nem altera títulos financeiros operacionais.

A tela é carregada pela rota `layout-base.html?page=inadimplencia`, acessível em **Financeiro → Inadimplência** para perfis a partir de `gerente` e Super-Admin conforme a política de módulos.

## Persistência

| Tabela | Finalidade | Isolamento obrigatório |
|---|---|---|
| `inadimplencia_importacoes` | Cabeçalho e totais de cada PDF importado | `tenant_id` em toda leitura e escrita |
| `inadimplencia_lancamentos` | Linhas normalizadas do relatório e chaves de comparação | `tenant_id` e `importacao_id` |
| `tenant_arquivos` | PDF original preservado como BLOB | referência vinculada ao tenant |
| `tenant_arquivo_referencias` | Evidência entre PDF e importação | `arquivo_id` do BLOB já isolado |
| `logs_financeiro` | Auditoria de importação, parser e reconciliação | contexto da requisição e usuário |

A migration explícita é `sql/migration_inadimplencia_mysql57.sql`. A API também verifica as duas tabelas de domínio antes de operar para evitar uma falha de primeira execução, mas a migration continua obrigatória no processo de deploy.

## Parser BRCondos

A API `api/api_inadimplencia.php` chama `pdftotext -layout`, processando blocos iniciados por `GLEBA, Nº <número>`. O parser preserva status de carteira, proprietário, CPF, identificador após `#`, tipo, mês de referência, vencimento e os cinco valores financeiros do relatório.

A chave principal de comparação é `BRCONDOS|identificador|tipo|vencimento`. Quando não existe identificador, a chave alternativa usa Gleba, descrição normalizada, competência, vencimento e valor. Isso impede que IDs de cobrança repetidos para tipos ou vencimentos diferentes sejam confundidos.

> Não se deve deduzir quitação apenas pela ausência de um lançamento em um novo PDF. A interface registra esse caso como `QUITADO` para fins comparativos e o usuário deve confirmar a regularização na fonte financeira antes de qualquer ação de cobrança.

## Regras de comparação e tendência

A comparação junta snapshots por `chave_comparacao` e agrega a visão exibida por Gleba. Os estados são `NOVO`, `EVOLUINDO`, `CORRIGIDO`, `QUITADO` e `ESTAVEL`.

A seção **Tendência baseada em histórico** não executa previsão estatística. O risco alto é calculado por regra transparente: a Gleba deve estar em evolução em duas comparações consecutivas e sem quitação intermediária. Se houver somente um snapshot, a tela informa que ainda não existe histórico suficiente.

## Segurança

O tenant é sempre obtido por `exigirTenantId()`. Nenhuma ação recebe `tenant_id` por query string, corpo HTTP ou formulário. A importação aceita exclusivamente PDF de até 20 MB, valida MIME, escapa o caminho em `pdftotext` com `escapeshellarg` e grava o original via `tenant_file_gravar_upload()`.

A ordenação do ranking é uma lista branca fixa no backend. A tela escapa conteúdos textuais antes de renderizar tabelas e não envia dados de credencial aos logs financeiros.

## APIs

| Ação | Método | Descrição |
|---|---|---|
| `importar` | POST | Recebe PDF, persiste BLOB, processa e cria snapshot |
| `dashboard` | GET | KPIs, carteiras, histórico, comparação e heurística |
| `listar_importacoes` | GET | Histórico paginado de snapshots |
| `ranking` | GET | Ranking paginado e filtrado por Gleba/morador/carteira |
| `comparar_importacoes` | GET | Comparação entre dois snapshots |
| `detalhe_importacao` | GET | Cabeçalho e comparação de um snapshot |
| `exportar_csv` | GET | Exportação UTF-8 BOM do ranking selecionado |

A saída PDF usa o fluxo padrão do ERP: o botão **Gerar PDF** abre a visualização de impressão do navegador, onde o usuário salva em PDF. O CSV contém o ranking visível sem alterar dados.

## Validação de referência

O PDF de referência fornecido em 13/08/2026 foi processado com sucesso pelo parser. A validação extraiu 41 unidades, 1.153 lançamentos e conciliou os totais calculados com o Resumo do relatório: R$ 405.265,52 lançado e R$ 574.363,84 projetado. Casos de múltiplos lançamentos em uma Gleba, proprietário `E OUTROS` e descrição extra longa foram preservados pelo parser.

