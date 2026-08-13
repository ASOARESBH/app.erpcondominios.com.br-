# Incidente: tela "Documentos" do app do morador sempre vazia (0 documentos)

## Evidências fornecidas

Os registros da tela **Documentos** do aplicativo Flutter mostravam sempre **"Nenhum documento encontrado."**, mesmo com a unidade logada devendo enxergar documentos publicados no GED. Não havia erro visível ao usuário; a tela renderizava o estado vazio como se a consulta tivesse retornado uma lista legítima sem itens.

## Diagnóstico inicial — leitura de código

| Camada | Comportamento encontrado | Consequência |
|---|---|---|
| Frontend | `lib/presentation/screens/documents/documents_screen.dart`, método `_loadData()` | Chamava a ação incorreta para uma lista plana |
| Endpoint | `AppConstants.endpointDocumentos` = `/api/api_portal_documentos.php` | API GED usada pelo Portal do Morador |
| Backend | Ação `documentos_listar` | Exige `pasta_id` para listar uma única pasta |
| Cliente HTTP | `dio_client.dart` aceita status HTTP menor que 500 | O `400` não era lançado como exceção |

A tela chamava `acao=documentos_listar` sem enviar `pasta_id`. No backend, a ausência do parâmetro cai no valor `-1` e resulta em `{"sucesso": false, "mensagem": "pasta_id inválido."}` com HTTP 400. Como o Dio considera esse status uma resposta processável e a tela atualizava a lista somente para `sucesso == true`, a falha era ocultada e `_documents` permanecia vazio.

A busca textual também estava incompatível: a tela enviava `busca`, enquanto a ação plana `buscar` lê `q`. A ação `buscar` é a que corresponde à UX existente, porque consulta todas as pastas, aceita `q`, `tipo` e `pagina` e devolve `pasta_nome`, campo já usado no subtítulo dos cartões.

## Regra de produto confirmada

O aplicativo usa uma **lista plana com busca e filtros**, não uma navegação hierárquica por pastas. Portanto, o contrato correto para essa tela é:

```text
GET /api/api_portal_documentos.php?acao=buscar&q=&tipo=&pagina=
```

A rota `documentos_listar&pasta_id=` continua existente e deve ser usada somente por uma experiência futura de navegação por pastas.

## Validação de unidade e visibilidade — 2026-08-13

A auditoria somente leitura no banco confirmou que a causa não era tenant, token ou unidade. O morador **ANDRE SOARES E SILVA** possui `morador_id=185`, `tenant_id=1`, unidade textual `Gleba 133` e correspondência exata para `unidade_id=139` em `unidades`.

| Verificação | Resultado |
|---|---|
| Correspondência `moradores.unidade` → `unidades.nome` | Válida: `Gleba 133` → unidade 139 |
| Documentos GED ativos | 12 documentos encontrados |
| Visibilidade `todos` | Há documentos destinados ao morador |
| Visibilidade `moradores` | Há documentos destinados ao morador |
| Visibilidade `usuarios` | Permanece corretamente bloqueada ao Portal do Morador |

Não foi necessária alteração da resolução de unidade no backend. A validação de `unidades_especificas` deve continuar sendo executada quando houver documento de teste com `unidades_acesso` preenchido para a unidade 139.

## Correção aplicada — 2026-08-13

O arquivo `lib/presentation/screens/documents/documents_screen.dart` foi atualizado para consultar `acao=buscar`, enviar `q`, `tipo` e `pagina`, e carregar documentos em páginas de 30 itens com rolagem incremental.

A tela agora trata `sucesso: false`, resposta inválida e falhas de rede como estado de erro explícito e `SnackBar`. Assim, `Nenhum documento encontrado.` é exibido apenas quando a API retornar uma lista válida sem documentos acessíveis. A abertura e o download continuam usando o endpoint GED, que revalida a permissão do morador antes de fornecer arquivo ou link externo.

## Validações executadas

A análise estática de `documents_screen.dart` foi concluída sem erros e os testes automatizados do aplicativo foram aprovados. O fluxo operacional que ainda deve ser validado após a instalação da atualização é mostrado abaixo.

| Cenário | Resultado esperado |
|---|---|
| Documento `todos` | Aparece para o morador |
| Documento `moradores` | Aparece para o morador |
| Documento `usuarios` | Não aparece para o morador |
| Documento `unidades_especificas` para unidade 139 | Aparece para Gleba 133 |
| Documento de outra unidade | Não aparece |
| Busca por texto | Filtra nome, descrição ou tags |
| Filtro de tipo | Restringe PDF, Word, Excel ou imagem |
| Download | Revalida a visibilidade no backend |

## Fontes locais

| Fonte | Papel |
|---|---|
| `lib/presentation/screens/documents/documents_screen.dart` | Interface móvel corrigida |
| `lib/core/network/dio_client.dart` | Cliente que explica o erro silencioso em HTTP 400 |
| `api/api_portal_documentos.php` | Contrato de busca, visibilidade e download |
| `AI-CONTEXT/TENANT_FILE_STORAGE.md` | Regras de isolamento de arquivos por tenant |
