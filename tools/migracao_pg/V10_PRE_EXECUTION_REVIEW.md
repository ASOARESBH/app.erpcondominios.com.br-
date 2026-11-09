# Revisão pré-execução — ensaio integral PostgreSQL v10

**Escopo:** revisão do snapshot privado de ensaio restaurado do dump, do código oficial em `pg/03-1-ensaio-integral-v10` e dos metadados read-only da origem isolada. Este documento não autoriza produção, publicação, conexão da aplicação, deploy, DNS, cutover ou limpeza de artefatos antigos.

A execução v9 foi aceita para equivalência dos dados do alvo preservado (270 tabelas, 202.609 linhas, 11 FKs validadas e sem divergências), mas ocorreu em retomadas controladas. A finalidade do v10 é provar a execução integral desde alvo inexistente. O runner verifica o dump e a cópia antes de criar o novo banco; a verificação SHA-256 compara ambos com um manifesto root-only. Por solicitação expressa, o digest exato será incluído somente no relatório privado gerado após aceite; não será versionado nem emitido nos logs/stdout.

## A. Datas zero convertidas para NULL

A regra do pipeline é converter somente valores `0000-00-00`, `0000-00-00 00:00:00` e datas parciais-zero reconhecidas pelo catálogo em `NULL`. Nenhuma data é inventada nem substituída pela data atual. A contagem fica associada à coordenada tabela/coluna. Para permitir preservar as linhas, o DDL torna nullable uma coluna que contenha esses valores, mesmo quando era `NOT NULL` no MySQL. As cinco coordenadas revisadas totalizam 434 células:

| Tabela.coluna | Células | Regra e impacto potencial | Como a aplicação deve interpretar |
|---|---:|---|---|
| `contas_pagar.data_emissao` | 1 | Emissão ausente/desconhecida; o campo não é obrigatório no POST atual, embora a coluna MySQL seja `NOT NULL`. | Adaptador futuro deve converter entrada vazia em SQL `NULL`, não em string vazia nem data de hoje; telas/relatórios devem apresentar “sem data” e não usar esse campo como vencimento ou data de pagamento. |
| `contratos.data_vencimento` | 9 | Vencimento histórico inválido/ausente. O cadastro atual exige vencimento; a nulabilidade relaxada serve somente para preservar registros antigos do snapshot. | Leitura futura deve tratar `NULL` como dado pendente/ausente, sem fabricar vencimento ou considerar o contrato liquidado. A validação de novos contratos continua obrigatória. |
| `dependentes.data_nascimento` | 213 | Nascimento não informado; o campo é opcional no fluxo atual. | `NULL` significa desconhecido; manter campo vazio na apresentação e enviar `NULL` pelo adaptador ao receber entrada vazia. Não usar como critério de idade sem validação. |
| `inventario.data_baixa` | 208 | Data de baixa não informada/inválida. A data não é obrigatória; o estado do item é armazenado separadamente. | `NULL` não deve, isoladamente, alterar `status` ou `situacao`; não inferir baixa nem atribuir data atual. |
| `inventario.data_compra` | 3 | Data de compra desconhecida/ausente; não há exigência de data no POST atual. | Mostrar ausência de data, preservar os demais dados patrimoniais e não preencher automaticamente com a data de cadastro. |

A API atual de `contas_pagar` não exige `data_emissao`, a API de `contratos` exige `data_vencimento`, a API de dependentes aceita nascimento opcional e a API de inventário não exige datas de compra/baixa. Algumas rotas passam strings vazias diretamente ao banco MySQL. A Fase D, em branch e autorização próprias, deverá normalizar a fronteira do PDO (`""` → `NULL` somente para campos temporais opcionais/normalizados) e garantir que consultas, filtros e formatos exibam o valor ausente como ausente. Nenhuma alteração funcional foi feita nesta revisão.

## B. ENUMs vazios/fora do domínio

Foram revisadas 213 células em seis colunas. O valor observado vazio é a única anomalia: em todas as seis coordenadas, a contagem de vazio é igual à contagem fora do domínio. Não foi encontrada contagem de valor não vazio fora do domínio. O pipeline mapeia o ENUM para texto com `CHECK` contendo os valores declarados e o literal vazio observado; copia `''` literalmente. Não converte para `NULL`, default, “outros” ou qualquer categoria inferida.

| Tabela.coluna | Células vazias | Compatibilidade e uso observado |
|---|---:|---|
| `contas_pagar.forma_pagamento` | 1 | Campo de classificação do pagamento. O endpoint grava a seleção recebida. Vazio permanece vazio; nunca inferir modalidade de pagamento. No futuro, a UI deve expor ausência/“não informado” e escrita nova deve continuar validando o domínio aprovado. |
| `contrato_documentos.tipo_documento` | 25 | Categoria de documento enviada no upload e apresentada na lista. Vazio significa categoria não classificada; não renomear o documento nem presumir contrato/aditivo/ata. |
| `email_log.tipo` | 19 | Categoria de log de e-mail lida e gravada pelo `EmailSender` e consultável no módulo de log. Preservar como evento sem categoria; não atribuir tipo por assunto ou destinatário. |
| `produtos_estoque.unidade_medida` | 157 | Unidade lida em produtos/movimentações e escrita pelo endpoint de estoque (que usa “Unidade” como default para novo cadastro). Vazio histórico não deve ser convertido em `Unidade`; não realizar conversões de quantidade sem unidade definida. |
| `registros_acesso.tipo` | 10 | Classificação da pessoa/registro usada em filtro, vínculo e relatório. Não confundir com `tipo_acesso` (`Entrada`/`Saída`), nem inferir `Morador`/`Visitante`. |
| `rh_banco_horas.tipo` | 1 | Tipo de lançamento contábil do banco de horas. A regra atual retorna sinal nulo para tipo desconhecido/vazio e totais consideram somente tipos permitidos; preservar `''` mantém o comportamento de não contabilizar como crédito ou débito. |

A estratégia é compatibilidade e preservação, não correção de dados. O estado final deve indicar `PRESERVED_EMPTY_ENUM_VALUE`; qualquer futura migração para uma categoria ou valor nulo requer decisão funcional específica e atualização coordenada do código.

## C. Oito tabelas classificadas como legado

As oito tabelas abaixo são preservadas no schema `legado`; nenhuma é descartada. A auditoria literal no código versionado não encontrou consultas ou escritas operacionais da aplicação. As ocorrências comprovadas são de catálogo/validação e testes, não de uso em telas/rotinas de negócio. Essa conclusão é delimitada ao checkout e não prova inexistência de SQL dinâmico, consumidores externos ou dependências não versionadas.

| Tabela | Resultado da busca no repositório oficial |
|---|---|
| `rbac_sessoes_bkp_ids_a_partir_15_20261027` | Referência estática no catálogo de candidatos de backup e no teste do snapshot. Nenhuma consulta/escrita operacional literal comprovada. |
| `rbac_usuario_grupos_bkp_ids_a_partir_15_20261027` | Referência estática no catálogo/teste; nenhum uso operacional literal comprovado. |
| `rbac_usuario_permissoes_bkp_ids_a_partir_15_20261027` | Referência em catálogo, manifesto de colunas ON UPDATE e teste; nenhum uso operacional literal comprovado. Dependência dinâmica não é excluída pela busca textual. |
| `sessoes_usuarios_bkp_ids_a_partir_15_20261027` | Referência em catálogo, manifesto de metadados e teste; nenhum uso operacional literal comprovado. |
| `usuario_modulos_bkp_ids_a_partir_15_20261027` | Referência em catálogo, manifesto e teste; nenhum uso operacional literal comprovado. |
| `usuario_tenant_bkp_ids_a_partir_15_20261027` | Referência estática no catálogo/teste; nenhum uso operacional literal comprovado. |
| `usuarios_bkp_duplicata_admin_20261027` | Referência em catálogo, manifesto e teste; nenhum uso operacional literal comprovado. |
| `usuarios_bkp_ids_a_partir_15_20261027` | Referência em catálogo, manifesto e teste; nenhum uso operacional literal comprovado. |

Consultas metadata-only e read-only independentes ao schema da cópia de ensaio retornaram zero referências para os oito nomes candidatos em cada categoria: FKs (tabela de origem e referenciada), triggers (tabela-alvo e corpo), definições de procedures/functions, views e events. Essa conferência foi repetida com padrões que tratam os underscores dos identificadores literalmente; consultas anteriores tinham contado o alvo de triggers, mas não os corpos, e foram substituídas por este resultado completo. A classificação não remove tabelas nem autoriza limpeza. A comparação de fonte/destino deve conferir os nomes exatos e direcionar apenas essas oito para `legado`; nenhuma das 42 tabelas sem `tenant_id` pode receber tenant inferido.

## Outras verificações estruturais para o v10

O snapshot revisado tem 270 tabelas, 3.339 colunas, 503 definições de índice, 240 tabelas com primary key, 11 FKs, 7 triggers, 2 procedures, zero functions/views/events, 232 colunas `AUTO_INCREMENT`, 228 tabelas com `tenant_id`, 42 sem, 111 colunas `ON UPDATE` e 83 colunas com collation de destino `C`. Os totais de PKs/índices foram confirmados por consulta somente de metadados. O runner v10 deve comparar as contagens do catálogo, as assinaturas já versionadas de objetos e os 270 nomes efetivos na reconciliação; qualquer drift bloqueia antes de declarar aceite.

## Decisão de execução e limites

As normalizações descritas preservam os dados sem inferência, mas criam requisitos explícitos para a futura adaptação PDO: entrada temporal vazia deve ser tratada com valor SQL `NULL`, valores ENUM vazios devem ser tolerados na leitura e exibidos como não classificados, e validações de novas gravações permanecem estritas. Esses riscos foram documentados, não corrigidos na aplicação nem usados para reclassificar dados.

Esta revisão atende o gate pré-execução para o ensaio isolado v10. A execução só pode ser aceita com exit 0, 270/270 nomes/schemas e métricas por tabela, 202.609 linhas em cada lado, zero divergências em contagem/hash/PK/identity/sequence/numeric/binário/tenant/timestamps, 11 FKs válidas sem órfãos, cluster v10 parado e credencial temporária removida. Se qualquer condição falhar, o status será `BLOCKED` ou `FAILED`; preservar o alvo e evidências. O resumo e os logs entregues não reproduzirão dados de negócio, segredos ou logs brutos. O digest exato solicitado ficará somente no artefato privado e não será versionado.
