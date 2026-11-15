# Segurança (Security Guide)

## 1. Autenticação e Sessão
- As sessões são gerenciadas pelo PHP (`PHPSESSID`).
- O frontend usa `credentials: 'include'` no `fetch()` para enviar o cookie.
- A API DEVE ter os headers CORS corretos:
  `Access-Control-Allow-Origin: https://app.erpcondominios.com.br`
  `Access-Control-Allow-Credentials: true`

### P3.2 — portabilidade em preparação
- O login administrativo PostgreSQL só aceita tenant obtido por vínculo ativo do próprio usuário em `usuario_tenant`, exceto `super_admin` global, que pode escolher um tenant ativo. A escolha envia slug não-ID, revalidado pelo servidor em cada chamada; nunca aceitar `tenant_id` do cliente como autoridade. Ausência/ambiguidade de vínculo falha fechada para usuários comuns; nunca usar tenant 1 como fallback PostgreSQL.
- RBAC PostgreSQL exige o conjunto de tabelas esperado e erro de metadata/validação de sessão não é convertido em permissão. A precedência registrada na seção RBAC abaixo não muda.
- Somente no opt-in PostgreSQL, a sessão ERP revalida em cada verificação/renovação que o usuário e tenant continuam ativos e que há vínculo ativo, salvo autoridade global `super_admin`; contexto ausente/falha de consulta não pode ser tratado como sessão válida. O comportamento histórico do MySQL permanece no restante inalterado. `verificar_sessao_completa.php` não deve devolver o PHP `session_id` ou token na resposta.
- Credenciais, hashes de senha, tokens e cookies não podem ser registrados/persistidos para debug. Exceção expressa: o SHA-256 do ID efêmero da sessão PHP pode ser persistido somente em `rbac_sessoes.sessao_hash`; o ID em claro e esse digest não devem aparecer em logs, auditoria, respostas nem em outro campo da sessão PHP.
- Auditoria do endpoint de login usa somente códigos de evento estáticos; não persistir e-mail, nome, user-agent ou IP do solicitante nesse fluxo. O helper mantém o comportamento padrão de IP para outros chamadores; login opta explicitamente por IP nulo.
- Após autenticação válida, regenere o ID PHP e limpe a sessão anterior antes de definir a nova identidade/tenant; nenhum `_rbac_sessao_id`, cache ou identidade de outro usuário/tenant pode sobreviver à troca de conta. Se falhar antes do commit, destrua a sessão PHP parcial e faça rollback transacional. No login Bearer do Portal MySQL, remova tokens anteriores/inserir o novo token dentro de transação InnoDB e só confirme depois de a resposta estar pronta; rollback precisa restaurar tokens anteriores. Se o commit foi confirmado, uma falha tardia deve devolver a resposta de sucesso já serializada, sem revogar o token durável. O token Bearer Portal PostgreSQL continua bloqueado até portabilidade/homologação próprias.
- O Portal do Morador no PostgreSQL permanece indisponível (503) até a sessão Bearer e o schema correspondente serem portados e homologados. Esta nota não autoriza seleção de driver em produção.

## 2. Hierarquia de Permissões
Definida no `auth_helper.php`:
1. `visualizador` (Apenas leitura)
2. `operador` (Leitura e criação básica)
3. `gerente` (Edição avançada, relatórios)
4. `admin` (Configurações, exclusão, integrações)

## 3. Proteção de Diretórios
Pastas sensíveis (como `/api/`) devem ter arquivo `.htaccess` bloqueando listagem de diretórios (`Options -Indexes`).
Arquivos de upload de moradores/documentos ficam fora da raiz pública ou protegidos por script PHP que valida a sessão antes de entregar o arquivo.

## 4. Submódulos de Manutenção
- O perfil legado `operador` não concede automaticamente acesso a Estoque, Abastecimento,
  Hidrômetros, Leituras, Inventário, Checklists ou Ordens de Serviço.
- As rotas SPA devem consultar `minhas_permissoes` para bloquear a abertura direta de um
  submódulo sem `visualizar`; a tela geral de Manutenção deve ocultar seus cards e abas negados.
- As APIs desses submódulos devem chamar `rbacExigir()` por ação (`visualizar`, `criar`,
  `editar` ou `excluir`) depois da autenticação. A proteção de frontend é somente visual e
  nunca substitui a autorização server-side.

## 5. Moradores, Dependentes e Anexos
- `visualizar` no módulo `moradores` permite listar/consultar moradores e dependentes,
  abrir anexos existentes e gerar os relatórios do módulo.
- `criar`, `editar` e `excluir` controlam, respectivamente, o cadastro, a alteração e a
  remoção de moradores, dependentes e anexos. Dependentes e anexos são subrecursos do
  módulo `moradores`, portanto não exigem uma chave RBAC paralela.
- `api_moradores.php`, `api_dependentes.php`, `api_moradores_anexos.php`, o PDF de
  moradores e o conteúdo privado de `api_arquivos_tenant.php` devem validar a ação no
  backend. Esconder botões no frontend não é suficiente.
- A ausência de `PERMITIR` significa sem acesso; herança entre grupos nunca cria `NEGAR`
  sintético. Só um `NEGAR` armazenado explicitamente pode negar uma ação. A precedência
  efetiva é: admin/super_admin do registro atual do usuário; depois `NEGAR` individual,
  `PERMITIR` individual, `NEGAR` de grupo e `PERMITIR` de grupo. Assim, `moradores.visualizar`
  em um grupo não apaga um `moradores.editar` concedido por outro grupo; para negar a edição,
  configure `NEGAR` explicitamente no grupo ou no usuário.
- Alterar perfil, exceções individuais, grupos ou vínculos incrementa/cria a revisão RBAC do
  tenant. Sessões ativas conferem a revisão e recarregam as permissões sem exigir novo login;
  falha de leitura bloqueia escritas no frontend e a autorização backend continua obrigatória.
- A Central de Usuários só pode conceder ações que o configurador possui, exceto admin real.
  O backend também compara efeitos antes/depois para alterações de perfil, grupo e vínculo,
  no tenant da sessão, impedindo autoelevação ou concessões indiretas a outros usuários.

## 6. Escopo de Ordens de Serviço
- A matriz `os_permissoes_departamento` é tenant-aware e controla visualização, criação,
  edição e exclusão por usuário e departamento. As próprias O.S. do usuário permanecem
  consultáveis; o checkbox `restritivo_os` pode limitar a consulta a elas.
- Dashboard, listagem, busca individual, relatórios, interações, finalização, materiais,
  projetos, documentos, impressão e imagens devem aplicar o mesmo escopo server-side.
- Uma URL direta, um botão oculto ou um departamento enviado no payload nunca substitui
  a resolução do departamento armazenado na O.S. e a validação da sessão autenticada.


## 7. Alertas da Unidade e QR
- APIs administrativas derivam `tenant_id` da sessão e aplicam RBAC (`moradores`, `registro`, `acesso`); APIs do Portal resolvem tenant/unidade/morador pelo Bearer validado contra `sessoes_portal` e morador ativo. O Console deriva tenant do dispositivo ativo autenticado no banco; tenant nunca vem do payload.
- Consultas e gravações de alertas, alvos, convites, registros e acionamentos devem permanecer no mesmo tenant. Documentos são normalizados para comparação e mascarados nos logs/listagens; a ciência guarda somente documento mascarado.
- Tokens de ciência são opacos, contextualizados, de uso único e revalidados sob transação. Não aceitar decisão, alerta ou supervisor escolhidos pelo cliente sem validação server-side/RBAC.
- Entradas falham fechadas se as tabelas requeridas da migration estiverem ausentes, retornando `MIGRATION_ALERTAS_PENDENTE`; não liberar acesso por fallback sem política carregada. QR do Portal deve enviar Bearer e só pode consultar/alterar recursos do próprio tenant/morador.

## 8. Convidados — escopo do morador (Portal/App)
- `api/api_portal_convidados.php` exige Bearer válido de `sessoes_portal` com morador ativo; tenant, morador e unidade vêm da sessão. `morador_id`, `unidade` e `origem_cadastro` do payload são ignorados.
- Toda consulta filtra `tenant_id` + `morador_id` da sessão (outro morador ou outro condomínio recebe 404). Visitante vinculado precisa pertencer à unidade (`pvu_condicao_visitante_unidade`); dependente precisa pertencer ao morador.
- Documento completo só é devolvido ao próprio autor do convite; listas usam documento mascarado. `verificar_alertas` não expõe alertas de outras unidades.
- Proteção contra abuso: máximo de 100 convites PREVISTO por morador (429) e chave de idempotência.
- Decisão (2026-11-04): para encontrar visitantes da portaria, `buscar_documento` revela o nome do visitante do condomínio quando o morador informa o **documento completo e válido**. Listagens continuam restritas à unidade; vínculo por ID fora da unidade exige documento idêntico.
- CPF de morador não vira visitante; no Portal/App a mensagem não identifica o morador.

## 9. RH — Banco de Horas
- A identidade e o `tenant_id` vêm da sessão autenticada. Toda leitura/gravação/exclusão do ledger usa o tenant preparado e valida o colaborador em `rh_colaboradores` no mesmo tenant; IDs de outro condomínio retornam 404.
- Lançamentos manuais são serializados por lock `(tenant, colaborador)` e transação; retries usam chave única por tenant. Confirmação de saldo negativo exige flag explícita correspondente ao checkbox da interface e justificativa, e registra saldo anterior/posterior, usuário, IP e justificativa.
- Exclusão exige motivo, `recursos_humanos.excluir` no RBAC (ou gerente no modo legado), filtro de tenant e auditoria na mesma transação. Registros com `lancamento_id` não podem ser excluídos manualmente. Não confiar em botão oculto no frontend.
- SQL não é interpolado para valores; erros técnicos ficam no `error_log`. `tipo=''` histórico é apenas reportado para investigação, sem correção automática nem atribuição presumida.
- Mapear, simular, histórico e aplicação filtram tenant da sessão; departamento/colaborador e offsets são validados e os valores seguem bind preparado. A API exige `recursos_humanos.editar` para simular/aplicar e `visualizar` para somente ler; esconder o botão no cliente nunca substitui autorização server-side.
- Aplicar bloqueia cada colaborador com o mesmo named lock compartilhado pelo ledger, usa uma transação para o lote e compara hash da prévia/entrada antes de qualquer gravação. Mudança concorrente em espelho, escala, abono ou ledger invalida a prévia (409). A chave/replay não autoriza trocar filtros, tenant ou hashes.
- A opção de incluir período fechado exige administrador validado e é persistida no histórico junto a autor, nome, motivo, período, totais, status, hashes e IP. A migration de reprocessamento é aditiva/idempotente; não altera dados de produção automaticamente.
- Gravação/exclusão de Ponto, alteração de Abono, DSR e sincronização do ledger usam o mesmo lock tenant/colaborador; período é relido sob `FOR UPDATE` antes da escrita, e uma falha de sincronização desfaz a transação em vez de retornar sucesso parcial. Abono não altera período fechado.
- Leituras de relatórios RH/PDF exigem `recursos_humanos.visualizar`; configuração de escala aplica RBAC por ação. CORS das APIs RH que foram ajustadas valida origem permitida com credenciais, sem wildcard de subdomínio.

## 10. Chat-In
- Identidade e tenant vêm do token/sessão; o cliente nunca informa quem é. Participação é conferida em toda leitura/escrita (404 fora da conversa).
- Respostas não trazem CPF, e-mail ou telefone — somente nome, unidade/função e status online (que o morador pode ocultar).
- `strip_tags` + limite de tamanho no corpo; o frontend escapa todo conteúdo (sem HTML). Rate limit 20/min, idempotência por chave, bloqueio bilateral.
- Push só com dados mínimos (`conversa_id`, `rota`); o app aceita apenas rotas internas `/home/...` vindas do push.
- Assembleia passou a isolar por tenant e exigir `permissao` admin/gerente para escrita (antes a checagem lia campo inexistente).

## 11. Portal do Morador — BLOB de logo e Marketplace
- `api/api_arquivos_tenant.php` aceita Bearer do Portal somente para `acao=conteudo` de imagem `logo_tenant` ativa do tenant derivado pela sessão `sessoes_portal` + morador ativo. O fluxo não autoriza documentos, anexos ou outros tipos; ids fora do escopo respondem 404 sem revelar existência.
- A página obtém o BLOB por `fetch` same-origin com header Bearer, usa URL de objeto temporária e a revoga ao substituir/restaurar o ícone. Nunca transportar token em query string nem registrá-lo em console/log.
- `api/api_portal_marketplace.php` deriva morador/tenant do Bearer validado; pedidos e avaliações são vinculados ao morador autenticado e ao tenant da sessão. Ignorar ids/tenant confiáveis fornecidos pelo cliente; erros SQL não são devolvidos na resposta.
- O escopo administrativo existente de `api/api_arquivos_tenant.php` permanece protegido por sessão ERP e RBAC; a exceção Portal não altera a autorização para arquivos de backoffice.
- `api/get_logo_empresa.php` também aceita Bearer do Portal somente via `Authorization`; o token é associado a sessão Portal ativa e morador ativo, e o tenant nunca vem do cliente. Se o header Bearer estiver presente mas inválido, falhar fechado com 401, sem fallback para sessão administrativa. Sem Bearer, só a sessão ERP validada permanece aceita; a sessão PHP do Portal não substitui a revalidação no banco.
- A configuração pública do Firebase contém apenas campos cliente (sem credencial privada); os validadores exigem `pwa_ativo=1`, formas válidas e campos sem placeholders antes de iniciar Messaging, além de conferir instância default. API key Firebase é identificador público, não credencial server-side, e suas restrições/habilitação de Firebase Installations/FCM devem ser corrigidas no Google Cloud; não se contorna 403 no backend do ERP.


## 7. Chat-In v2
- A rota `chatin` não é mais uma página `SEMPRE_VISIVEIS`: o widget, o menu e todas as ações para usuário do painel exigem `chatin.visualizar`; a migration concede essa ação aos grupos existentes de cada tenant sem conceder `configurar`.
- `config_chatin` admite leitura por `chatin.visualizar` ou `sistema.visualizar`, e escrita por `chatin.configurar` ou `sistema.configurar`. A API deriva tenant e ator da sessão, valida grupos no próprio tenant, usa prepared statements e audita alterações; recurso ausente ou sessão sem permissão é fail-closed.
- Busca de moradores permanece limitada a registros ativos; não inferir titularidade/proprietário/inquilino do parentesco de dependentes. Conversas desautorizadas por configuração posterior continuam legíveis, mas toda escrita é negada no servidor.
- Notificação de navegador exige opt-in e permissão concedida; seu corpo é somente “Nova mensagem”. O polling oculto só continua a cada 20 s quando esse opt-in está ativo.
- Em autenticação por cookie, gravações e `GET sync` (que atualiza presença) validam `Origin`/Fetch Metadata same-origin; GET não marca leitura. Bearer inválido nunca sofre downgrade para cookie de painel. `tenant_id` e ator permanecem derivados do token/sessão, não do body.
- Preferências e canais validam booleanos/whitelists; opções avançadas respeitam os gates de recurso tenant no servidor. Mensagens/limites são serializados com transação e locks; chave idempotente não é normalizada truncando conteúdo, e replay só é aceito para a mesma conversa e payload. Falhas de canais ou auditoria RBAC executam rollback.


## 9. Runtime PDO/mysqli isolado — P3.1b
- O shim só pode rodar em pool FPM isolado sem a extensão mysqli nativa, com `ERP_CONFIG_PATH` apontando para configuração fora do document root, sem permissão de grupo/outros (`0600` recomendado). Se o caminho estiver ausente/inválido ou o document root não puder ser validado em SAPI web, o bootstrap falha fechado. O fallback MySQL legado em `api/config.php` usa o mesmo resolvedor e não ignora as verificações de permissão/document root.
- A inicialização descarta qualquer saída parcial e retorna somente erro genérico 503; o bootstrap da configuração também suprime e rejeita output inesperado do arquivo privado. Configuração privada, mensagens brutas PDO e credenciais não devem ser incluídas em logs ou respostas. O driver MySQL permanece padrão e PostgreSQL exige seleção e schema explícitos.
- Multi-query é sequencial/bufferizada e não atômica; `CALL` e DDL de stored programs são recusados. `send_long_data` usa stream temporário e não significa streaming integral da aplicação.
