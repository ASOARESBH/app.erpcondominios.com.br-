# Regras de Negócio Críticas

## 1. Moradores
- Um morador pode ter múltiplos dependentes e veículos.
- Um morador NÃO pode ser cadastrado como visitante simultaneamente.
- Desativação de morador (ativo=0) não exclui seus dados históricos.

## 2. Financeiro
- **Status de Contas a Pagar**: `PENDENTE` → `PARCIAL` → `PAGO`.
- Uma conta marcada como `PAGO` não pode ser reaberta sem permissão de `admin`.
- O saldo devedor é calculado automaticamente: `valor_total - valor_pago`.

## 3. Hidrômetros
- Cada unidade tem um hidrômetro associado.
- A leitura mensal gera um `lancamento_agua` com base na diferença entre a leitura atual e a anterior.
- O valor cobrado é calculado por faixas de consumo (tarifa progressiva).

## 4. Ordens de Serviço
- **Status**: `Aberto` → `Em Andamento` → `Finalizado` / `Cancelado`.
- Ao adicionar uma interação, o status muda automaticamente para `Em Andamento`.
- O solicitante (morador) recebe notificação push ao abrir e ao finalizar a O.S.
- O acesso às O.S. pode ser configurado por usuário e departamento, separando `Visualizar`,
  `Criar`, `Editar` e `Excluir`. A restritividade, quando marcada, permite ao usuário
  consultar as O.S. abertas por ele, atribuídas a ele como atendente ou assumidas por ele
  no Portal, quando esse vínculo existir no esquema. O vínculo pessoal permite leitura,
  mas não concede escrita: alterações continuam sujeitas ao RBAC e às permissões de ação
  por departamento. A validação vale também para dashboard, relatórios, interações,
  materiais, projetos, documentos, impressão e imagens.
- Na aba Chamados da listagem interna, `Unidade` é um filtro exato e cumulativo com os demais filtros e com
  todo o escopo de tenant/RBAC/departamento. O seletor opcional de Morador depende da Unidade
  e da permissão existente `moradores.visualizar`; selecionar unidade não concede leitura
  nem escrita adicionais e nunca permite editar O.S. fora das ações de departamento. O
  ranking usa os mesmos dados já visíveis ao usuário e funciona como atalho para esse filtro.
- A unidade persistida em uma O.S. é o snapshot associado ao morador quando o chamado foi
  aberto. O filtro preserva esse vínculo histórico; para registros legados sem unidade
  persistida, consulta a unidade atual do morador no mesmo tenant. A divergência entre
  snapshot e cadastro atual pode ser investigada por `sql/diagnostico_os_unidade_divergente.sql`,
  que é somente leitura e não altera registros.

## 5. Documentos (GED)
- Documentos podem ter visibilidade: `todos`, `moradores`, `usuarios` ou `unidades_especificas`.
- Arquivos são armazenados em `uploads/documentos/` com nome gerado por hash para evitar conflitos.


## 6. Inadimplência por snapshot BRCondos

A importação de inadimplência é exclusivamente analítica. Cada PDF gera um novo snapshot histórico e jamais altera títulos, baixas, negociações ou qualquer lançamento operacional. A comparação usa identificador BRCondos, tipo de cobrança e vencimento; quando não existe identificador, usa chave alternativa composta. Uma Gleba ausente do relatório mais recente é exibida como possível regularização para análise, mas não pode gerar baixa ou ação de cobrança automática.

O risco alto é uma heurística explicável e não uma previsão: a Gleba deve apresentar aumento em duas comparações consecutivas sem quitação intermediária. Importação, leitura, CSV e PDF devem sempre usar o `tenant_id` obtido da sessão autenticada.

## 7. Registro Manual de Acesso

O Registro Manual pode lançar acessos em dois modos: `VEICULO`, quando a placa e os dados do veículo fazem parte do evento, e `PEDESTRE`, quando a pessoa acessa sem veículo. Os tipos de pessoa são Morador, Visitante, Prestador, Entregador Autorizado e Familiar nos dois modos. Pedestres podem informar opcionalmente a vestimenta para facilitar a identificação no histórico, mas não podem possuir ocupantes de veículo; a validação de documento digitalizado permanece aplicada aos tipos que exigem cadastro de visitante.

No painel web, o lançamento segue quatro etapas: Acesso (data/hora, entrada/saída e tipo), Destino (unidade, morador e, quando aplicável, dependente ou dias), Identificação (documento e visitante/convidado, exceto Morador, que a omite) e Veículo/Pedestre (placa, modelo, cor, ocupantes ou vestimenta). Etapas concluídas ficam recolhidas com resumo e podem ser reabertas; reabrir Destino preserva campos posteriores, invalida a ciência em memória e executa nova pré-validação. Convite já vinculado abre na etapa final preenchida. Limpar reinicia na primeira etapa e gera nova `idempotency_key`; retries do mesmo envio mantêm a mesma chave.

Em Entradas, a unidade/destino é pré-validada antes da identificação; visitante/documento e placa são reavaliados nas etapas seguintes. `BLOQUEAR`, `CONTATAR_MORADOR` e `EXIGIR_CIENTE`, bem como nível Atenção/Crítico, exigem popup operacional por etapa; avisos informativos permanecem acessíveis no resumo do stepper. Reconhecer um popup antecipado não equivale à ciência formal. Na gravação, o backend continua reavaliando o contexto e pode retornar `ALERTA_PENDENTE` (HTTP 409), que exige o modal formal, decisão autorizada e token contextual de uso único antes do retry. Saídas não são avaliadas por Alertas da Unidade.

Consulta de placa não muda automaticamente o morador nem a unidade de destino. Se o veículo estiver relacionado a outra pessoa/unidade, a portaria confirma o destino escolhido; o servidor mantém validação tenant-local e usa o cadastro do veículo para modelo/cor. O fluxo mantém documento digitalizado, ocupantes, convite, unidade oficial e demais validações server-side já existentes.

Em acesso de Dependente, a API confirma em qualquer movimento que o dependente pertence ao morador indicado e ao tenant da sessão; em Entrada, dependente e morador também precisam estar ativos. Para Visitante, Prestador, Entregador Autorizado e Familiar, o servidor compara tipo e documento informados com o cadastro correspondente ao `visitante_id`; a permanência aceita de 1 a 365 dias (omissão equivale a 1). A chave idempotente cobre também o conjunto de ocupantes; reutilizá-la com outro payload retorna conflito HTTP 409.

## 8. Projetos públicos por tenant

- Projeto não cria uma entidade paralela: é uma Ordem de Serviço marcada como pública, preservando título, departamento, responsável e datas na O.S. original.
- Cada tenant pode configurar um `projetos_slug` único na aba Empresa. A URL `/projetos/{slug}` lista somente projetos públicos daquele tenant; `/projetos/{slug}/{id}` detalha apenas uma O.S. pública do mesmo tenant.
- A página pública é somente leitura. O detalhe oferece compartilhamento nativo/WhatsApp e cópia da URL canônica; a remoção do slug não exclui projetos e mantém a rota legada `/projetos`.


## 9. Parâmetros de Moradores e Dependentes

- A tela Sistema > Moradores possui abas separadas para campos de Moradores e de Dependentes, com configuração por tenant e validação duplicada no frontend e no backend.
- Nome, CPF e unidade do Morador permanecem estruturais; no Dependente, o vínculo com o morador titular e o nome completo permanecem estruturais. Os outros campos podem ser definidos como obrigatórios ou opcionais pelo usuário com `Sistema > Configurar`.
- A opção `Permitir mais de um veículo por dependente` é desativada por padrão para preservar os dados e o comportamento existentes. Quando habilitada, o Dependente pode receber vários veículos, sem alterar a unicidade de placa e TAG do tenant.

## 10. Abastecimento

- O lançamento de abastecimento pode receber uma observação livre e opcional para registrar qualquer detalhe operacional relevante.
- A observação acompanha o lançamento e deve aparecer no histórico da aba Lançamento, no relatório HTML, no CSV exportado e no PDF, sem alterar cálculos de saldo, KM, litros ou consumo.
- Nenhum registro antigo é invalidado: quando a observação não existir, a interface exibe `—` e o relatório mantém os demais dados.


## 11. Lista de Convidados no Registro Manual

- Morador ou dependente anfitrião cadastra um convidado previsto para uma unidade, data/período e quantidade/modalidade de entradas; a validade final é calculada e persistida pelo backend.
- A entrada na portaria só ocorre dentro da validade, com documento e tipo correspondentes, anfitrião/unidade corretos e saldo de entradas. Entrada incrementa o contador; saída não consome saldo e é aceita para fechar uma presença já iniciada, inclusive após o fim da validade.
- Os movimentos Entrada/Saída do convite e a linha correspondente em `registros_acesso` são atômicos. Retentativas com a mesma chave não consomem a entrada duas vezes.
- A lista mascara CPF, mantém histórico e autoria e exibe EXPIRADO calculado pela data corrente de São Paulo sem alterar retroativamente o status salvo. Cancelamento requer motivo, só é permitido no status PREVISTO e fica auditável; um convidado PRESENTE precisa registrar saída antes de qualquer encerramento.
- Familiar significa parente visitante sem residência na unidade; não substitui o fluxo de Dependente. Entregador Autorizado e Familiar seguem o fluxo de Visitante/Prestador para cadastro, documento, destino e ocupantes.
- O cadastro completo de Visitante pode ser realizado na chegada e segue as mesmas regras de campos/anexos configuradas em Sistema > Visitantes; a política nunca é duplicada ou afrouxada pela Lista de Convidados.
- Entregador Autorizado e Familiar são tipos distintos em `registros_acesso`; precisam permanecer selecionáveis nos filtros simples/ramificados, contadores e exportações (CSV/PDF) e no histórico do Portal do Morador, sempre dentro do tenant autenticado.
- **Pré-cadastro pelo morador (2026-11-04)**: o morador cadastra convidados no Portal do Morador e no app (menu **Convidados**) com os mesmos campos e regras da portaria. A origem é preenchida automaticamente (`PORTAL_MORADOR`/`APP_MORADOR`) e a autoria é `MORADOR`; morador e unidade vêm da sessão. O convite aparece na aba Convidados da portaria, que segue o fluxo normal de entrada/saída.
- O morador vê todos os convites em que é anfitrião (inclusive os criados pela portaria), mas só edita os que ele próprio cadastrou; pode cancelar qualquer convite PREVISTO dele (motivo obrigatório). A portaria pode editar pré-cadastros, sem alterar a origem.
- Antes de cadastrar, o morador é avisado se houver alerta ativo da própria unidade que afete a entrada (ex.: "não receber visitas"); a portaria continua seguindo o alerta na chegada.
- Limite de 100 pré-cadastros PREVISTO por morador.
- No Portal/App, ao digitar o documento do convidado o sistema busca em Visitantes da unidade; sem cadastro, o convidado é cadastrado automaticamente em Visitantes da unidade ao salvar o convite. Anexos exigidos pela política de visitantes continuam sendo concluídos na chegada (Registro Manual).
- No painel, "visitante não encontrado" e demais avisos aparecem dentro do modal do convite; o convite é salvo com o nome digitado e o cadastro completo de visitante segue na chegada.
- A busca do convidado no Portal/App considera todos os visitantes do condomínio (inclusive os cadastrados pela portaria); o convite é vinculado ao cadastro existente e só cria visitante quando o documento não existe no condomínio.
- CPF de morador ativo nunca pode ser cadastrado como visitante (painel, Portal/App e pré-cadastro de convidado).

## 12. Alertas da Unidade

- No painel, as unidades aparecem sem duplicatas por variação de caixa/espaços e em ordem natural (`Gleba 2` antes de `Gleba 10`). A seleção do formulário segue Unidade → Morador titular → Dependente: o morador só pode ser escolhido depois da unidade, e o dependente só é habilitado para titular do tipo Dependente, limitado à unidade e, quando selecionado, ao morador. Titular do tipo Morador não envia `dependente_id`.
- A unidade enviada pelo formulário é comparada após remover espaços externos e ignorar diferença de caixa, mas o backend continua persistindo o valor oficial obtido do cadastro tenant-local do morador.
- `alertas_unidade` registra avisos/restrições por tenant, unidade, titular morador/dependente, público, alvo, vigência e recorrência. Dependente é titular de regra, não autoria autenticada; no Portal a unidade/morador/dependente são derivados da sessão Bearer.
- Para controle de abuso, cada unidade pode manter no máximo 30 alertas simultaneamente vigentes. O limite é aplicado pelo servidor nas criações web do painel e Portal e também ao retomar um alerta pausado.
- `TODOS` abrange entradas de moradores, dependentes, visitantes, prestadores, entregadores autorizados e familiares da unidade. `APENAS_AVISAR` não bloqueia; `EXIGIR_CIENTE`, `CONTATAR_MORADOR` e `BLOQUEAR` exigem decisão operacional na entrada web. `BLOQUEAR` não pode ser liberado por operador comum; override requer permissão `moradores.aprovar`, reautenticação com a senha atual da conta ativa no tenant e justificativa registrada. Moradores podem criar bloqueios Críticos limitados à própria unidade, mas não aprovar override.
- O operador recebe token de ciência de uso único, curto e contextual. O servidor reavalia regras e contexto antes de inserir; ciência, acionamento e acesso são gravados atomicamente. O snapshot preserva o texto vigente à decisão.
- Preflight administrativo aplica rate limit e pode usar por até 30 segundos um cache de IDs de alertas ativos por tenant/unidade; salvar ou alterar status invalida esse cache. A gravação real da entrada, o Console e o RFID não confiam no cache e reavaliam as regras dentro da transação correspondente.
- No Portal, alvos de visitante cadastrados são pesquisáveis somente quando vinculados ao morador autenticado; o documento é mascarado na busca. Também é permitido informar nome e documento livremente, sem abrir a base geral de visitantes do tenant. Modelos rápidos preenchem o formulário e a prévia em linguagem natural é informativa; o servidor continua validando todos os campos.
- Leitura automática (Console QR/RFID) não inventa ciência humana: `BLOQUEAR` nega a abertura e registra `BLOQUEADO_AUTOMATICO`; as demais regras geram evento `CIENCIA_PENDENTE` (ou `APENAS_AVISAR`) para revisão posterior. O Console apresenta os alertas retornados. A notificação ao morador ocorre após a ciência humana, não por cada leitura automática.
- Antes de gerar QR no Portal, a tela faz preflight por Bearer e pede confirmação se houver alertas conflitantes; o leitor Console avalia novamente no momento da entrada. Uma resposta não substitui a outra.
- No cadastro pelo painel, a seleção é encadeada: Unidade → Morador titular (apenas moradores ativos da unidade) → Dependente (apenas quando o tipo do titular for Dependente, restrito à unidade/morador). Unidades são exibidas em ordem natural (Gleba 2 antes de Gleba 10).
- A avaliação ocorre somente para Entrada, nunca para Saída. `alertas_unidade` (regras do morador/unidade e ciência prévia) não é `alertas_acesso` (detecção administrativa pós-evento).
- A pré-validação parcial do Registro Manual aceita `DESTINO`, `IDENTIFICACAO` e `VEICULO`, sempre usando tenant e permissões da sessão. No Destino, regras gerais aplicáveis podem ser exibidas e alertas específicos ainda não identificáveis são somente contados, sem expor documento de terceiros. Nenhuma etapa parcial emite token ou substitui a reavaliação/ciência formal dentro da gravação; um mesmo alerta/conteúdo/contexto aceito não abre popup repetido sem mudança relevante.
- Ao resolver um alerta por `dependente_id` sem `morador_id`, o backend consulta o morador-pai ativo no tenant e deriva a unidade oficial; não confia na unidade enviada nesse caso.
- A rotina CLI `cron/alertas_unidade_a_cada_10_minutos.php` muda alertas vencidos de `ATIVO` para `EXPIRADO`, registrando a operação `EXPIRAR`; também enfileira uma única notificação aproximadamente 24 horas antes da vigência final. A preferência individual `push_ativo` é mantida por tenant/morador no Portal; padrão habilitado. Se o morador desativar o push, o FCM é suprimido para acionamentos e lembretes, mas o inbox do Portal continua persistido. O envio também respeita a configuração global do tenant `push_alertas_unidade_ativo`.

## 13. Permissões por módulo (RBAC)

- Permissões são resolvidas por ação e tenant. A ausência de `PERMITIR` significa sem acesso; um grupo de somente leitura não cria `NEGAR` automático para criar/editar/excluir nem anula permissões de outros grupos.
- Precedência única: perfil `admin`/`super_admin` confirmado no banco concede acesso total; fora deles, `NEGAR` individual explícito > `PERMITIR` individual explícito > `NEGAR` explícito de grupo > `PERMITIR` de grupo > sem acesso. `HERDAR` remove a exceção individual ou deixa o grupo sem regra e retorna à decisão herdada.
- Configurar matriz/grupos exige `usuarios.configurar` no tenant da sessão. O operador não pode alterar a própria matriz nem conceder a outro usuário uma ação que ele não possui; a autorização e o tenant são validados no backend, independentemente dos controles visuais.

## 14. Banco de Horas — compensação e pagamento

- Crédito automático é positivo; débito automático, abatimento e pagamento consomem minutos. Tipos fora da whitelist, inclusive `tipo=''` legado, não podem ser tratados implicitamente como débito.
- Abatimento (folga compensatória) pode superar o saldo: a primeira tentativa responde HTTP 409 e não grava; a efetivação exige confirmação explícita e justificativa de 10 a 500 caracteres, deixando saldo negativo auditado. O limite devedor é opcional por escala; `NULL` significa sem limite e um limite excedido retorna HTTP 422.
- Crédito automático posterior é somado naturalmente ao saldo negativo. Pagamento monetário permanece limitado ao saldo positivo disponível; quando excede, deve ser recusado com orientação para usar Abatimento.
- Reprocessamento automático não pode apagar lançamentos manuais nem linhas de outro tenant; DSR mantém a regra de jornada vigente e registra suas linhas automáticas no tenant do colaborador.
- Falta abonada não caracteriza falta injustificada para perda do DSR. Alterar ou remover abono/falta exige recalcular a semana afetada; ponto, abono, DSR e ledger são confirmados ou revertidos juntos sob lock do colaborador.
- A função pura `bh_apurar_dia()` é a única fonte de crédito, débito e excedente para sincronização, mapeamento e reprocessamento. `CREDITAR_TUDO` credita extra líquida integral; `LIMITAR_E_SEPARAR` credita até o teto configurado e mantém a diferença como excedente fora do saldo para decisão do RH.
- **Decisão de regra:** o limite de 2 h/dia (CLT art. 59) é controle de jornada; não autoriza descartar horas efetivamente trabalhadas. O teto também é alerta visual, sem corte de horas no espelho. A política de crédito/excedente deve ser validada com RH/contabilidade do condomínio.
- Reprocessar exige prévia, revisão expressa e motivo auditável. Períodos fechados são ignorados por padrão; somente administrador pode incluí-los, com essa opção registrada no histórico. A transação é por tenant/colaborador e retries da mesma prévia são idempotentes.
