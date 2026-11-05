# Relatórios agregados de qualidade — contrato read-only

## Estado e limites

Este componente **não acessa a origem, não conecta a banco, não gera SQL e não cria SQL**. O script `tools/generate_read_only_quality_reports.py` só aceita um snapshot JSON `metadata_only_aggregates` previamente produzido por um processo operacional autorizado. A entrada deve conter metadados e agregados já calculados; não deve conter linhas pessoais, células, valores, uploads, tokens, logs, SQL ou credenciais.

A recontagem independente das definições `CREATE TABLE` do schema-only encontrou 270 tabelas: 228 declaram `tenant_id`, 42 não declaram; há 22 nomes view-like no total, dos quais 18 estão nas 42 sem tenant e quatro têm `tenant_id`; as outras 24 sem tenant não têm nome view-like. Essas contagens são cobertura estrutural e **não são valores de dados**. O componente não transforma a ausência de `tenant_id` em defeito: as 42 linhas de `tenant_profile.csv` são classificadas explicitamente como pendentes por contrato.

Sem um snapshot metadata-only autorizado, os CSVs reais não devem ser fabricados. Os testes usam somente fixture sintética e não são uma exportação do sistema.

## Artefatos

A execução bem-sucedida grava, atomicamente, quatro arquivos no diretório indicado:

- `data_quality_aggregates.csv`: uma linha agregada por tabela e linhas agregadas por coluna, PK/unique, candidato de órfão e referência de anexo. Não exporta identificadores de linha, nomes de pessoas, valores, amostras ou conteúdo.
- `tenant_profile.csv`: exatamente uma linha por tabela, com presença de `tenant_id`, contagens agregadas quando autorizadas, estado do default de schema `1` e classificação.
- `quality_report_manifest.json`: política, cobertura, SHA-256 do input, SHA-256 do próprio gerador, checksums/tamanhos/quantidade de linhas dos dois CSVs e requisitos de permissão.
- `quality_report_manifest.json.sha256`: checksum do manifesto para verificação externa.

Os quatro arquivos são escritos em modo `0600` dentro de um diretório novo modo `0700`, criado sob um diretório pai privado do próprio usuário; o bundle é publicado por rename somente depois de todos os arquivos estarem prontos. O script recusa destino já existente, diretório dentro do checkout Git, symlink como input, input acima de 64 MiB e hash não coincidente. Falha de leitura, contrato, permissão ou escrita aborta sem sobrescrever relatórios existentes.

## Contrato de entrada

Estrutura mínima:

```json
{
  "schema_version": 1,
  "snapshot": {
    "kind": "metadata_only_aggregates",
    "captured_at": "2026-11-05T18:00:00Z",
    "source_sha256": "<sha256-do-export-autorizado>",
    "contains_row_data": false,
    "contains_cell_values": false,
    "contains_sql": false,
    "contains_personal_data": false
  },
  "inventory": {
    "tables": [
      {
        "table_name": "identificador_validado",
        "view_like": false,
        "row_count": null,
        "tenant": {
          "has_tenant_id": true,
          "tenant_id_column": "tenant_id",
          "schema_default_1_status": "unknown",
          "null_count": null,
          "default_1_row_count": null
        },
        "columns": [],
        "constraints": [],
        "orphan_candidates": [],
        "attachment_references": []
      }
    ]
  }
}
```

O exemplo é apenas contrato; não é dado real. O produtor autorizado deve preencher exatamente 270 tabelas. O script rejeita qualquer chaves desconhecida, listas de linhas, campos de valores/células, SQL, conteúdo pessoal ou nomes que não sejam identificadores simples (`[A-Za-z_][A-Za-z0-9_$]*`).

Os agregados aceitos cobrem:

- `row_count` somente como contagem;
- `null_count` e `duplicate_count` para colunas e constraints;
- PK e `unique` separadamente, com totais agregados;
- candidatos de órfãos por relação, com contagem e estado;
- `min_status`/`max_status`, **sem min/max value**;
- `tenant_id` nulo, quantidade com default de schema `1` e estado do default, sempre como contagens/estado;
- estado e contagem de inválidos de enum;
- estado e contagem de datas zero;
- estado e contagem de overflow;
- estado e contagem de JSON inválido;
- estado e contagem de referências a anexos, sem arquivo, caminho, hash de conteúdo ou conteúdo.

`0` é um agregado observável e permanece `0` no CSV. Campo vazio significa `null`/não observado, não zero.

## Classificação de tenant

A classificação é deliberadamente não conclusiva:

- `tenant_column_present_unverified`: a coluna foi observada, mas o isolamento efetivo não foi provado;
- `no_tenant_column_view_like_pending`: sem `tenant_id`, nome view-like, classificação por contrato pendente;
- `no_tenant_column_pending`: sem `tenant_id`, nome não view-like, classificação por contrato pendente.

O script exige fechar `270 = 228 + 42`, `22 view-like total = 4 com tenant + 18 sem tenant` e `42 = 18 view-like sem tenant + 24 outros sem tenant`. Não preenche decisão funcional para as 42. `tenant_id=1` nunca é tratado como contexto operacional: somente o estado/contagem agregado do default de schema é reportado.

## Como evitar SQL unsafe e nomes dinâmicos

Este utilitário não recebe nomes para montar SQL e não executa SQL. A coleta futura, se aprovada separadamente, deve manter estas regras:

1. Obter nomes de tabelas/colunas apenas de catálogo/manifesto allowlisted, nunca de request, arquivo pessoal ou concatenação livre.
2. Validar cada identificador contra a gramática de identificador simples e comparar novamente com o catálogo autorizado. Não usar validação de string como substituto de autorização.
3. Usar o mecanismo de quote de identificadores do driver PostgreSQL (`quote_ident`/equivalente do driver) somente depois da allowlist; parâmetros (`$1`, `$2`, ...) são para valores, não para nomes.
4. Não aceitar `;`, comentários, aspas, ponto, operadores, expressões, `ORDER BY`/funções ou fragmentos SQL como “nome”. Não tentar escapar manualmente para tornar entrada arbitrária executável.
5. Para evitar consultas unsafe e resultados pessoais, preferir uma consulta fixa/revisada por família de métrica; executar em transação read-only, com `statement_timeout`, `lock_timeout`, limite de custo e `SET LOCAL` apropriado. O gerador entregue continua sem essa etapa.
6. Nunca consultar ou exportar linhas para calcular o relatório. A coleta aprovada deve retornar apenas `count`, `count(*) filter (...)`, estado, ou min/max apenas como estado/presença; min/max values devem ser descartados antes de qualquer persistência.
7. Não usar `tenant_id=1` como fallback. Contexto de tenant ausente/ambíguo deve resultar em `unknown`/pendente e bloqueio da coleta contextual.

## Permissões e retenção

O operador precisa apenas de leitura do arquivo de entrada e escrita no diretório de saída. Não são necessárias credenciais de banco, acesso de rede, SSH, sudo ou privilégios de schema. O processo deve rodar com usuário não privilegiado e um diretório de saída dedicado, fora da raiz pública. O input autorizado deve ser montado como somente leitura quando possível; não copiar `.env`, credenciais, cookies, tokens, uploads ou logs.

Aplicar ACL/mode que impeça outros usuários de ler CSVs e manifesto (`0600`), restringir retenção ao prazo aprovado, registrar owner/ambiente/data/checksum no migration ledger e transferir somente pelo canal autorizado. A publicação usa rename no-replace em diretório pai privado sem symlink. Se falha antes do rename, não existe bundle final; se `fsync` do pai falha após rename, o script retorna erro explícito e preserva o bundle publicado: verificar manifesto/checksum antes de nova tentativa. Não relaxar modo, não usar `/tmp` compartilhado como destino final e não imprimir conteúdo do input.

## Execução offline

```bash
python3 tools/generate_read_only_quality_reports.py \
  --input /caminho/autorizado/snapshot_metadata_only.json \
  --expected-input-sha256 "<sha256 aprovado por canal confiável>" \
  --output-dir /caminho/restrito/quality-report
```

A execução não consulta banco nem origem. Deve ser feita somente depois de receber o snapshot autorizado e seu checksum por canal confiável; o valor deve ser conferido independentemente e não é uma assinatura de origem. O diretório pai precisa ser privado (sem permissão de grupo/outros), o destino deve ser novo e fora do checkout. O branch atual não inclui um snapshot real e, portanto, não inclui CSVs reais.

## Validação mínima

A suíte offline é:

```bash
python3 -m unittest -v tools/test_generate_read_only_quality_reports.py
python3 -m py_compile tools/generate_read_only_quality_reports.py tools/test_generate_read_only_quality_reports.py
```

Os testes cobrem: rejeição de payload de linhas mesmo com flag falsa; rejeição de valores/células e SQL; rejeição de identificador unsafe; fixture sintética completa de 270 tabelas e partições 228/42, 22/4/18/24, além de decomposição inválida; preservação de zeros; `checked_absent` com contagem não-zero; quatro artefatos esperados; checksum do manifesto; política `rows_exported=false`; SHA input aprovado; data UTC válida; chaves JSON duplicadas; recusa de symlink e diretório pai permissivo; publicação privada; recusa de destino existente; falha de `fsync` pós-rename com preservação/verificação do bundle publicado.

## Estado atual

A documentação e o utilitário são read-only e offline. Nenhum banco, servidor, origem operacional ou dado de linha foi acessado durante esta alteração. A revisão estrutural existente continua sendo a fonte dos fatos de cobertura 270/228/42, 22 nomes view-like no total, 18 view-like sem tenant e 24 outros sem tenant; as métricas de qualidade permanecem `unknown`/`not_observed` até que um export metadata-only autorizado seja fornecido.
