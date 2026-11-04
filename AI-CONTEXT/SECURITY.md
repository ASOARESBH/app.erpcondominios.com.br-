# Segurança (Security Guide)

## 1. Autenticação e Sessão
- As sessões são gerenciadas pelo PHP (`PHPSESSID`).
- O frontend usa `credentials: 'include'` no `fetch()` para enviar o cookie.
- A API DEVE ter os headers CORS corretos:
  `Access-Control-Allow-Origin: https://app.erpcondominios.com.br`
  `Access-Control-Allow-Credentials: true`

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
