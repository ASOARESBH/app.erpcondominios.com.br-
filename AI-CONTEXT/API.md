# Mapa de APIs

As APIs estão localizadas na pasta `/api/` e respondem primariamente em JSON.

## 1. Estrutura de Chamada
**Endpoint Padrão**: `GET/POST /api/api_nome_modulo.php?action=nome_da_acao`

## 2. Padrão de Resposta
Toda API deve retornar obrigatoriamente a estrutura:
```json
{
    "sucesso": true|false,
    "mensagem": "Texto descritivo",
    "dados": { ... } // Opcional
}
```

## 3. Autenticação nas APIs
O arquivo `auth_helper.php` deve ser incluído no topo.
```php
require_once 'config.php';
require_once 'auth_helper.php';
verificarAutenticacao(true, 'operador'); // true = exige login, 'operador' = nivel minimo
```

## 4. Prevenção de Erros de Output
Muitas APIs usam o padrão `ob_start()` no início e `ob_end_clean()` antes de enviar o JSON, para evitar que warnings do PHP quebrem o parse do JSON no frontend.

## 5. GED — Portal do Morador

O endpoint `api/api_portal_documentos.php` é o consumidor autenticado das tabelas GED. O token Bearer determina o morador; o cliente nunca informa `tenant_id`, `morador_id` ou `unidade_id`.

| Ação | Parâmetros | Uso correto |
|---|---|---|
| `pastas_listar` | Nenhum | Navegação por pastas com contagem de itens visíveis |
| `documentos_listar` | `pasta_id` obrigatório, `tipo` opcional | Lista de uma pasta específica; `pasta_id=0` representa itens sem pasta |
| `buscar` | `q`, `tipo`, `pagina` | Lista plana e paginada entre todas as pastas; é a ação usada pelo aplicativo móvel |
| `visualizar` / `download` | `id` | Revalida a visibilidade do documento antes de transmitir arquivo ou link externo |

> A resposta de erro de uma API (`sucesso: false`) deve ser apresentada explicitamente pelo cliente, inclusive se o cliente HTTP optar por não transformar HTTP 400 em exceção.


## 6. Controle de Acesso — Eventos automáticos ControlID

Os endpoints do diretório `api/controlid/` não recebem sessão PHP, pois são acionados diretamente pela catraca. Os modos `new_user_identified`, `new_card`, `new_qrcode`, `new_uhf_tag`, `notifications/dao` e `result` convergem para `push_registrar_acesso_erp()` em `api/controlid/_helper.php`.

Depois de inserir um acesso liberado em `registros_acesso`, o helper resolve `tenant_id` e unidade a partir do morador vinculado ao veículo e chama `controle_acesso_criar_notificacao_registro()`. A chamada é complementar, cercada por `try/catch` e não altera a resposta física da catraca. O evento persistido usa `tipo=acesso_entrada`, `registro_acesso_id` e rota `/home/notifications`.

A API nunca aceita `tenant_id` do payload ControlID. Se a inserção não gerar identificador positivo, o helper registra `registro_sem_id_valido` e não cria um evento idempotente com chave insegura.


## 7. Portal do Colaborador — Vigilante/Rondas Mobile

As ações abaixo pertencem a `api/api_colaborador_mobile.php` e exigem `Authorization: Bearer <colaborador_token>`. O token determina `tenant_id`, usuário e e-mail; as ações não aceitam `tenant_id` nem `colaborador_id` brutos enviados pelo cliente.

| Ação | Método | Entrada | Regras e retorno |
|---|---|---|---|
| `vigilante_qr_detalhe` | GET | `token` QR hexadecimal de 64 caracteres | Localiza ponto e rota ativos, bloqueia QR de outro tenant com HTTP 403 e retorna ponto, rota e instruções sem expor `token_qr`. Resolve o colaborador pelo e-mail; em divergência de cadastro entrega somente opções opacas e temporárias dos vigilantes vinculados à rota. |
| `vigilante_registrar_leitura` | POST | `token`, `opcao_vigilante` opaca quando solicitada; `latitude`, `longitude`, `precisao_metros` opcionais | Revalida tenant, vínculo à rota, dia ativo, janela/SLA e duplicidade. Grava em `ronda_registros`, audita em `ronda_auditoria` e retorna status do SLA, atraso, ponto e rota. |
| `vigilante_historico_hoje` | GET | Nenhuma | Resolve unicamente o colaborador pelo e-mail da sessão e retorna até 20 leituras dele no dia corrente. |

A regra compartilhada de dias, ciclo e SLA está em `api/helpers/ronda_helper.php` e é usada também por `api/api_rondas_vigilante.php`. A deduplicação usa o ciclo calculado e o índice único de `ronda_registros`; a segunda leitura do mesmo ponto no ciclo retorna HTTP 409.
> O cliente móvel deve apresentar `mensagem` do backend quando `sucesso` for `false`, inclusive em HTTP 4xx, pois `EmployeeApiClient` não transforma status abaixo de 500 em exceção.

## 8. Registro Manual de Acesso

O endpoint `api/api_registros.php` mantém o lançamento manual de acessos de moradores, visitantes e prestadores. O campo `modo_registro` aceita `VEICULO` (padrão, com placa e dados do veículo) ou `PEDESTRE` (sem veículo); neste último modo, `vestimenta` é opcional e a API ignora qualquer lista de `ocupantes` enviada pelo cliente. Os lançamentos manuais também gravam `usuario_liberou` com o nome do operador da sessão; eventos automáticos de hardware permanecem sem esse campo. O retorno permanece no formato `{sucesso, mensagem, dados}` e o `tenant_id` é sempre obtido da sessão autenticada.

O POST aceita opcionalmente `idempotency_key` (até 36 caracteres), gerada pelo formulário para que retries de rede do mesmo lançamento não criem duplicidade. A chave é única por `tenant_id` e deve ser renovada quando qualquer dado do lançamento mudar, inclusive `tipo_acesso` (`Entrada`/`Saída`); uma segunda tentativa com a mesma chave retorna `sucesso: true`, os dados do registro já existente e `idempotente: true`. Se a chave colidir com payload diferente, a API retorna `codigo: IDEMPOTENCY_CONFLICT` e o frontend gera outra chave e repete uma única vez. Após sucesso, o formulário preserva o aviso em `sessionStorage`, atualiza a página e inicia o próximo lançamento com chave nova. Clientes antigos que não enviam a chave continuam funcionando sem deduplicação nova.

A ação `verificar_tag` de `api/api_rfid.php`, usada pela verificação manual de acesso no painel, também grava `usuario_liberou` e a ação `ultimos_acessos` devolve esse valor para a tela de detalhes. A listagem GET de `api/api_registros.php` também retorna `usuario_liberou` para a tabela de registros recentes. A ação `webhook` continua sem usuário, pois representa uma liberação automática recebida diretamente do equipamento RFID.

A ação `GET api/api_registros.php?acao=relatorio_ocupantes` retorna o grupo completo de acesso, usando `COALESCE(NULLIF(registro_titular_id, 0), titular_legado, id)` como chave: a linha do titular/condutor principal e todas as linhas de ocupantes secundários vinculadas. Instalações antigas que gravaram `registro_titular_id = 0` são recuperadas pelo contexto do mesmo evento (tenant, data/hora, veículo, tipo, Entrada/Saída, morador e destino); quando a linha filha perdeu placa/modelo/cor, esses campos ficam como curingas e o vínculo só é aceito quando há um único titular compatível, sem misturar acessos diferentes. O endpoint também normaliza o campo para aceitar `NULL`, evitando que novos titulares voltem a ser gravados com zero. O retorno separa `destino_morador_nome`/`destino_unidade` (morador e unidade que receberão o acesso), `condutor_nome`/`condutor_cpf`/`condutor_tipo` e `ocupante_nome`/`ocupante_cpf`/`ocupante_tipo`. A unidade exibida e filtrada prioriza o morador relacionado ao acesso, depois a unidade do registro titular e só então o valor da linha do ocupante, evitando que uma unidade copiada no filho ou um texto antigo do titular sobrescreva a Gleba correta. Os filtros de placa e modelo também consultam o veículo do titular recuperado; assim, buscar `RTF9G52` retorna o condutor e todos os ocupantes do mesmo grupo mesmo quando uma linha filha legada não possui placa/modelo. Os filtros de nome, CPF, unidade, tipo e liberação são aplicados no grupo e devolvem todos os integrantes do grupo, não apenas a linha que coincidiu. A tela `frontend/pages/relatorios.html` apresenta destino → condutor principal → ocupantes secundários e o PDF `api/api_relatorio_ocupantes_pdf.php` mantém a mesma estrutura nas exportações.

O GET padrão de `api/api_registros.php` aceita filtros server-side de período, horário, placa, modelo, unidade, nome/documento, tipo e liberação, além de `ignorar_ocupantes=1`, e limita a página a no máximo 1000 registros. A tela de Relatórios identifica sua consulta simples com `acao=relatorio_acessos`; essa ação e `acao=relatorio_ocupantes` exigem `relatorios_acesso.visualizar`, enquanto a leitura operacional do histórico continua exigindo `registro.visualizar`. A tela de Relatórios carrega as unidades ativas de `api/api_unidades.php?acao=select`; sem seleção, o parâmetro de unidade é omitido e todas são consultadas. Ao selecionar uma unidade, envia `unidade_exata=1`, evitando que `Gleba 1` coincida com `Gleba 10`; consumidores antigos que enviam somente `unidade` continuam com busca parcial. A tela abre com data inicial e final do dia corrente, envia os filtros ao banco e usa limite de 500 registros por consulta no modo simples; não carrega mais a base inteira para filtrar somente no navegador. O modo ramificado sempre aplica um intervalo de data indexado — o dia corrente quando omitido — e usa limite parametrizado de no máximo 5.000 linhas para evitar sobrecarga, sem o corte histórico de 1.000 que podia interromper grupos. Falhas de preparação/execução são registradas no log do endpoint e devolvidas com mensagem JSON segura.

No POST manual, a unidade de Visitante/Prestador é resolvida novamente pelo `morador_id` do destino dentro do tenant, e a unidade do Morador é sempre obtida do cadastro oficial. O valor enviado pelo navegador não pode substituir a unidade persistida.

## 8.1 Inadimplência — catálogo de fontes e layouts

`GET api/api_inadimplencia.php?acao=catalogo_layouts` retorna cinco perfis versionados para importação futura: `BRCONDOS`, `SUPERLOGICA`, `AHREAS`, `TOWNSQ` e `PACTO`. O perfil `brcondos-inadimplencia-detalhado-v1` é o único com `habilitado: true` e mantém o parser PDF já homologado. Os demais são `PLANEJADO`: a tela exibe a fonte, formatos e campos candidatos, mas não permite importar nem faz fallback silencioso para o parser BRCondos.

`POST api/api_inadimplencia.php?acao=importar` recebe `layout_id` junto do arquivo. O backend valida o catálogo antes de armazenar o BLOB; layouts não homologados retornam `codigo: LAYOUT_NAO_HOMOLOGADO`. A tabela `inadimplencia_importacoes` preserva `fonte_sistema`, `layout_id` e `layout_versao` para que futuros adaptadores sejam aditivos, versionados e auditáveis por tenant.

## 9. Usuários — perfis e permissões por módulo

`PUT /api/api_usuarios.php` mantém `usuarios.permissao`, `usuario_tenant.permissao` e o grupo RBAC de compatibilidade (`compat-visualizador`, `compat-operador`, `compat-gerente` ou `compat-admin`) sincronizados no mesmo tenant. Ao trocar o perfil, somente o grupo compatível anterior é desativado; grupos personalizados e exceções individuais permanecem preservados.

`GET /api/api_permissoes_modulos.php?acao=permissoes_usuario&id=...` também repara, de forma idempotente, usuários legados que ficaram vinculados ao grupo compatível errado. A origem personalizada é retornada por ação em `permissoes[*].origens`; a tela deve considerar essa estrutura ao diferenciar `Padrão do perfil` de `Permissões personalizadas`. A decisão efetiva do usuário alvo não pode herdar o `admin` da sessão do operador que está configurando a tela: somente o próprio `usuarios.permissao` do alvo habilita acesso total. O salvamento via `salvar_permissoes` grava negações explícitas (`NEGAR`) para ações desmarcadas e recarrega a decisão efetiva após a confirmação.

Um grupo personalizado ou `compat-visualizador` que concede somente `moradores.visualizar` também nega as demais ações de Moradores concedidas por outros grupos (incluindo `compat-operador`); o cálculo efetivo mantém precedência de `NEGAR`. A revisão RBAC do tenant é criada/incrementada a cada mudança de política, inclusive em tenants sem linha prévia em `rbac_configuracoes`, para invalidar o cache das sessões ativas.

## 10. Manutenção — autorização por submódulo

As páginas de Manutenção (`hidrometro`, `leitura`, `relatorios_hidrometro`, `abastecimento`, `estoque`, `inventario`, `relatorios_inventario`, `ordens_servico`, `imprimir_os`, `checklists` e `documentos`) são rotas RBAC próprias. O `MenuController` e a visão geral ocultam cards/abas sem `visualizar`, e o `AppRouter` bloqueia a URL direta. As APIs de Estoque, Abastecimento, Checklists, Hidrômetros, Leituras, Inventário e Ordens de Serviço, incluindo relatórios PDF/gerenciais, validam novamente `rbacExigir()` no servidor; o perfil legado `operador` não libera esses recursos quando há uma negação individual.

### 10.0.1 Abastecimento — observação do lançamento

`POST /api/api_abastecimento.php` com `action=lancar_abastecimento` aceita o campo opcional `observacao`. O valor é gravado em `abastecimento_lancamentos.observacao` e retorna nas ações `listar_abastecimentos` e `relatorio`, sempre filtrado pelo `tenant_id` da sessão. A tela também o inclui no CSV; o PDF é gerado por `api/api_relatorio_abastecimento_pdf.php` com a mesma coluna. A migration compatível com MySQL/MariaDB 5.7 é `sql/migration_observacao_abastecimento_mysql57.sql`.

## 10.1 Ordens de Serviço — escopo por usuário e departamento

`GET api/api_ordens_servico.php?acao=listar_permissoes_os&usuario_id=...` e `POST ...` com `acao=salvar_permissoes_os` são exclusivos de administradores/gerentes e operam somente no `tenant_id` da sessão. `GET ...?acao=listar_atendentes` também é exclusivo de gestores e retorna somente `id` e `nome` de usuários ativos do tenant atual, sem expor a API global de usuários. A matriz grava `visualizar`, `criar`, `editar` e `excluir` por usuário e departamento em `os_permissoes_departamento`; o checkbox `restritivo_os` grava em `os_usuarios_config` e, quando ativo, limita dashboard, listagem, detalhe, relatórios, interações, materiais e imagens às O.S. abertas pelo usuário, atribuídas a ele como atendente ou assumidas por ele no Portal, quando a coluna opcional existir.

Sem restritividade, o usuário pode consultar as próprias O.S., as atribuídas/assumidas por ele e as dos departamentos marcados. O atendente comum é identificado pela sessão; ao criar uma O.S., o backend atribui o próprio usuário e, ao editar, preserva o responsável existente. A API também valida a ação do departamento na criação, edição, exclusão, interação, finalização, materiais, projetos, documentos e impressão; autoria, atribuição ou assunção não concedem escrita por si sós, e esconder botões no frontend não é considerado autorização. A ausência de configuração mantém a compatibilidade do legado, sem abrir dados entre tenants.

`GET api/api_ordens_servico.php?acao=listar` aceita `unidade` (igualdade exata normalizada com `UPPER(TRIM())`, nunca busca parcial) e `morador_id` opcional, além dos filtros existentes. A unidade é acrescentada ao tenant e ao escopo RBAC/por departamento já aplicado; quando também há morador, ele precisa pertencer ao mesmo tenant/unidade, e uma combinação inválida retorna lista vazia. O filtro considera a unidade copiada na O.S.; somente em registros legados sem unidade gravada usa `moradores.unidade` dentro do mesmo tenant. O parâmetro `morador_id` sem `unidade` é rejeitado e seu uso também exige `moradores.visualizar` no servidor. `GET ...?acao=unidades_com_os` retorna contagens agrupadas somente pelas O.S. visíveis ao usuário; o seletor de unidades usa `api/api_unidades.php?acao=select`, e o seletor dependente usa `GET api/api_moradores.php?acao=select_por_unidade&unidade=...`, que retorna somente `id` e `nome` de moradores ativos da unidade e do tenant da sessão, sob `moradores.visualizar`. Sem essa permissão, o seletor de morador fica indisponível; a URL não mantém `morador_id` e continua aplicando apenas os demais filtros, sem abrir acesso adicional a O.S.

Ao criar ou editar uma O.S. com `morador_id`, o backend resolve `nome` e `unidade` diretamente de `moradores` pelo ID e `tenant_id` da sessão; os valores correspondentes enviados pelo navegador são ignorados. Na edição, se o `morador_id` permanecer igual, `morador_unidade` mantém o snapshot original mesmo que o cadastro atual tenha mudado; uma troca explícita de morador grava o snapshot do novo vínculo. Não há reescrita automática em massa de O.S. antigas. A API do Portal do Morador e o lançamento de O.S. pelo Portal não são alterados por esse filtro.

## 10.2 Projetos — URL pública personalizada por tenant

Projetos continuam sendo O.S. marcadas com `os_chamados.projeto_publico=1`; não existe um cadastro paralelo. A aba Empresa usa `api/api_empresa.php` com `action=validar_projetos_slug` (GET) e `action=salvar_projetos_slug` (POST) para validar e gravar `tenants.projetos_slug` no tenant da sessão. O identificador é normalizado para letras minúsculas, números e hífens, precisa ser único entre tenants e pode ser removido para retornar à rota legada.

As rotas públicas `/projetos/{slug}` e `/projetos/{slug}/{id}` encaminham o slug para `api/api_publico_projetos.php` e aplicam `tenant_id` em dashboard, listagem, detalhe, timeline, documentos e cache. A API de imagens (`api/api_imagem_projeto.php`) também revalida o slug e o tenant antes de servir capa ou foto. `/projetos` e `/projetos/{id}` permanecem compatíveis com o comportamento público legado.

## 11. Moradores, Dependentes e Anexos — autorização granular

`api/api_moradores.php` e `api/api_dependentes.php` usam a chave RBAC `moradores`: `GET`
exige `visualizar`, `POST` exige `criar`, `PUT` exige `editar` e `DELETE` exige `excluir`.
Na listagem de `api/api_moradores.php`, tanto o `COUNT` quanto os itens são sempre
limitados a `tenant_id` obtido da sessão, mesmo quando a busca não informa filtros.
`GET api/api_moradores.php?acao=select_por_unidade&unidade=...` é uma consulta compacta
para selects: retorna somente `id` e `nome` de moradores ativos daquela unidade e tenant,
usando a mesma exigência `moradores.visualizar`.
Dependentes são um subrecurso do módulo Moradores e não possuem uma permissão paralela.

`api/api_moradores_anexos.php` aplica `visualizar` para listar/consultar/download,
`criar` para upload e `excluir` para remoção. O PDF de Moradores e o conteúdo privado de
`api/api_arquivos_tenant.php` também exigem `moradores.visualizar` e filtram os dados pelo
`tenant_id` da sessão. A tela `frontend/pages/moradores.html` e
`frontend/js/pages/moradores.js` refletem a mesma matriz: um usuário somente visualizador
consegue consultar moradores, dependentes, anexos e relatórios, mas não vê nem executa
criar, editar ou excluir.


## 12. Parâmetros de Moradores e Dependentes

- `GET /api/api_config_moradores.php`: retorna, para o tenant da sessão, os campos configuráveis de `moradores` e `dependentes`, além das regras operacionais, para que a tela aplique os campos obrigatórios. A leitura exige `moradores.visualizar`; usuários somente leitura não recebem autorização para alterar cadastros nem parâmetros.
- `PUT /api/api_config_moradores.php`: grava somente as chaves do catálogo oficial, sem aceitar `tenant_id` do cliente. A ação exige `sistema.configurar` e registra auditoria quando o RBAC está instalado.
- Campos estruturais (`moradores.nome`, `moradores.cpf`, `moradores.unidade`, `dependentes.morador_id` e `dependentes.nome_completo`) permanecem obrigatórios e não podem ser desativados. Os demais campos são aplicados pelo backend de Moradores e Dependentes.
- A opção `dependente_multiplos_veiculos` controla `POST /api/api_veiculos.php`: desativada, preserva o limite legado de um veículo por dependente; ativada, permite vários veículos do mesmo dependente. A unicidade de placa e TAG por tenant continua valendo.
- A estrutura é instalada por `sql/migration_config_cadastro_moradores_mysql57.sql`. Sem a migration, a API opera em modo de compatibilidade com os defaults atuais e rejeita gravações de configuração.

## 13. Configuração de Visitantes

- `GET /api/api_config_visitantes.php` retorna a configuração de campos obrigatórios do tenant autenticado para que o cadastro de Visitantes aplique a regra correta a cada operador. Com RBAC ativo, exige `visitantes.visualizar`; não exige `sistema.visualizar`.
- `PUT /api/api_config_visitantes.php` grava somente as chaves do catálogo oficial e continua exigindo `sistema.configurar`. O `tenant_id` é sempre resolvido da sessão e a alteração é auditada.
- Essa separação é necessária porque operadores com acesso a Visitantes precisam ler a política do tenant, mesmo sem permissão para alterar Parâmetros do Sistema.


## 14. Lista de Convidados no Registro Manual

`api/api_convidados.php` fornece `GET listar`, `obter`, `buscar_documento` e `politica_visitantes`, `POST criar`/`cancelar` e `PUT editar`. Todas as ações usam a sessão, `tenant_id` resolvido no servidor e as permissões RBAC de `registro` com submódulo `convidados` quando o RBAC está ativo. A lista retorna CPF mascarado, autoria e anfitrião; o detalhe autorizado contém dados completos e movimentações daquele convite no mesmo tenant.

A data operacional é calculada no fuso `America/Sao_Paulo`. Convidados PREVISTO/PRESENTE cuja validade terminou são apresentados como EXPIRADO sem reescrever seu status persistido; as entradas novas são proibidas após a validade e a saída continua autorizada para encerrar uma presença aberta. Cancelamento requer motivo e somente altera convites PREVISTO, para não bloquear a saída de pessoa já presente. A criação valida validade, anfitrião e duplicidade sobreposta, aceita chave de idempotência e grava autoria. A migration MySQL/MariaDB 5.7 é `sql/migration_lista_convidados_mysql57.sql`; ela cria `convidados`, acrescenta `registros_acesso.convidado_id` e expande ENUMs preservando valores anteriores.

Antes de criar um convite, o frontend envia `POST acao=verificar_alertas` à mesma API com o formulário; o servidor resolve novamente anfitrião/unidade dentro do tenant e retorna somente resumos de alertas aplicáveis. Como o convite informa a data, não a hora, essa checagem usa 12h locais e avisa que a portaria revalidará no instante real. A confirmação não substitui nem libera a ciência exigida no Registro Manual.

O POST de `api/api_registros.php` aceita `convidado_id` opcional. Se informado, bloqueia o convite, revalida tenant, documento, tipo, anfitrião, status, validade e limite de entradas, alterna Entrada/Saída e grava a movimentação no mesmo transaction do acesso. Uma falha de negócio desfaz ambos os registros; uma falha técnica não expõe erro SQL ao cliente. O módulo SPA é composto por `frontend/pages/registro.html`, `frontend/js/pages/registro.js`, `frontend/js/pages/registro-convidados.js` e `assets/css/pages/registro.css`; o aplicativo Flutter permanece fora do escopo, enquanto o Portal do Morador aceita filtrar o histórico pelos novos tipos.

Os novos tipos `Entregador Autorizado` e `Familiar` são aceitos pela escrita e pelos filtros do GET principal; a tela de Relatórios inclui ambos nos filtros simples, ramificados, contadores, CSV, PDF geral, PDF de ocupantes e ranking. O Portal do Morador aceita esses valores no filtro do histórico. O PDF de ranking e as consultas de relatório limitam registros e joins ao tenant autenticado.


## 15. Alertas da Unidade

- `api/api_alertas_unidade.php` é a API administrativa da aba Moradores → Alertas. Usa sessão, tenant da sessão e RBAC de `moradores` para listar, consultar opções/KPIs/detalhes, salvar alertas, alterar status, avaliar contexto (`verificar`), registrar ciência e exportar o log de ciências. A ação `exportar` entrega CSV por padrão; `formato=json` é reservada ao fluxo de impressão/PDF, ambos exigem `moradores.exportar`. Quando informado, o período `data_inicio`/`data_fim` deve incluir as duas datas e não exceder 90 dias. A ciência devolve token de uso único vinculado a tenant, operador, contexto, horário e conjunto de alertas; nunca substitui a revalidação na gravação do acesso. `OVERRIDE_SUPERVISOR` exige permissão `moradores.aprovar`, justificativa e reautenticação com a senha atual da conta ativa no tenant.
- A ação `opcoes` devolve unidades ativas deduplicadas por caixa, com espaços externos removidos e em ordem natural. `salvar` compara a unidade informada ignorando caixa/espaços externos, mas persiste a unidade oficial do cadastro de morador no tenant da sessão.
- `verificar` aplica rate limit e usa cache efêmero de IDs ativos por tenant/unidade (30 segundos), sem PII; criação, edição, pausa, retomada e cancelamento invalidam o cache. A decisão final do registro transacional e dos leitores automáticos sempre ignora o cache e reconsulta/locka as regras atuais.
- `api/api_portal_alertas.php` autentica por Bearer em `sessoes_portal`, confirma tenant, morador ativo e unidade no banco. Permite listar, consultar detalhe, buscar dependentes e visitantes vinculados ao próprio morador (com documento mascarado), criar/editar/pausar/cancelar alertas próprios, verificar conflitos (`verificar_convite`) antes de gerar QR e ler/salvar `preferencia_push` da própria sessão. Morador pode definir nível Crítico/Bloquear somente para sua unidade; não pode aprovar override. O morador não envia tenant, morador anfitrião nem unidade confiáveis no payload.
- Painel e Portal aplicam limite de 30 alertas simultaneamente vigentes por unidade na criação e na retomada de pausa; a checagem de contagem sempre filtra por tenant/unidade no servidor.
- `api/api_registros.php` reavalia as regras no mesmo tenant antes de uma Entrada, consome ciência na mesma transação do registro e impede Entrada sem token válido quando houver ação que exige decisão. Saída não é avaliada. GET aceita `registro_id` como filtro preparado (junto ao RBAC `registro.visualizar`) para abrir o registro vinculado na timeline. `api/api_rfid.php?acao=verificar_tag` usa o mesmo contrato para operação com atendente; o webhook automático nega `BLOQUEAR` e grava acionamentos automáticos para as demais regras.
- `api/api_console_acesso.php?action=validar_qrcode` deriva tenant do dispositivo ativo (`dispositivos_console`), filtra QR e vínculos pelo mesmo tenant, nega `BLOQUEAR` e retorna alertas/eventos ao console. O detalhe administrativo expõe a timeline de acionamentos, distinta do log imutável de ciências humanas.
- A criação de QR existente em `api/api_acessos_visitantes.php` mantém escopo tenant-aware. O Portal consulta alertas antes da criação e o leitor Console reavalia no momento real da entrada. Sem a migration completa, as entradas que exigem enforcement retornam HTTP 503 com `MIGRATION_ALERTAS_PENDENTE`, em vez de falhar abertas.
- Se a importação da migration for interrompida, ela pode ser reaplicada integralmente: os checks de tabela/coluna/índice são idempotentes e comparam metadados sem depender da collation padrão do banco. O gate administrativo continua retornando 503 até encontrar todas as tabelas obrigatórias e registra no `error_log` qual tabela está ausente; o nome não é exposto na resposta HTTP.
- O domínio `alertas_unidade` é diferente de `alertas_acesso`: o primeiro é prevenção/ciente e regras da unidade; o segundo permanece no fluxo administrativo de detecção pós-evento.

## 16. Protocolos de Mercadorias

- `api/api_protocolos.php?acao=operador_atual` exige sessão e `protocolos.visualizar`, localiza o usuário ativo pelo ID e tenant da sessão e retorna somente seu nome para preencher Recebedor (Portaria). Não consulta a lista administrativa de usuários nem exige `usuarios.visualizar`.
- A criação exige `protocolos.criar` quando RBAC está ativo e grava `recebedor_portaria` a partir do usuário autenticado no servidor; o valor enviado pelo navegador não define autoria. Edição exige `protocolos.editar` e preserva o recebedor original. Registrar retirada usa `protocolos.aprovar`; exclusão usa `protocolos.excluir`. Antes da migration RBAC, mantém-se a hierarquia legada.
- Todos os protocolos, moradores e usuários resolvidos nessa API ficam limitados ao `tenant_id` da sessão.
