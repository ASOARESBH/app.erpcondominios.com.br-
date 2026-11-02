# PROMPT DE IMPLEMENTAÇÃO — Aba "Lista de Convidados" no Registro de Acesso

> Gerado em 2026-10-31 após análise do código real (`frontend/pages/registro.html`, `frontend/js/pages/registro.js`, `api/api_registros.php`, `api/api_visitantes.php`, `api/api_acessos_visitantes.php`, `api/api_portal_morador.php`, `api/rbac_helper.php`, `sql/`, app Flutter `aplicativoerpcondominios`) e do AI-CONTEXT.
> Tela alvo: `https://app.erpcondominios.com.br/frontend/layout-base.html?page=registro`
> Copie tudo a partir da linha "INÍCIO DO PROMPT".

---

## INÍCIO DO PROMPT

Você é o tech lead + engenheiro de segurança + QA do **ERP CONDOMÍNIOS** (repositórios `app.erpcondominios.com.br-` e `aplicativoerpcondominios`). Implemente a funcionalidade **Lista de Convidados** como uma nova **aba** na tela de Registro de Acesso. O objetivo é um código pronto para produção, sem gambiarras.

### 0. Regras obrigatórias (não pule)

1. **Leia antes de codar**: `AI-CONTEXT/INDEX.md`, `SKILL.md`, `FRONTEND.md`, `API.md` (§8 Registro Manual, §13 Config. Visitantes), `DATABASE.md` (§7 Registros de Acesso), `BUSINESS_RULES.md` (§7), `SECURITY.md`, `MULTITENANT_ARCHITECTURE.md`, `APP_MOBILE_BUSINESS_RULES.md` e este arquivo.
2. Siga a sequência ANALISAR → ENTENDER → PLANEJAR → IMPLEMENTAR → TESTAR → DOCUMENTAR → ATUALIZAR AI-CONTEXT.
3. **Multi-tenant**: o `tenant_id` vem sempre da sessão ou do token, nunca do payload. Todo SELECT usa `WHERE tenant_id = ?`. Todo UPDATE e DELETE usa `WHERE id = ? AND tenant_id = ?`.
4. Use **apenas prepared statements**. Responda sempre `{sucesso, mensagem, dados}`, com `ob_start()`/`ob_end_clean()` e `set_exception_handler` no padrão de `api/api_visitantes.php`. Nunca vaze erro de banco para o cliente.
5. No frontend, use **apenas Vanilla JS** (ES Module com `init()`/`destroy()`). Não use frameworks.
6. Não edite arquivos `teste_*`, `debug_*`, `*_backup*`, `*_final*` ou `*_corrigid*`. Confirme o arquivo canônico pelo `fetch()` real do JS de página.
7. Faça migrations **idempotentes e compatíveis com MySQL 5.7**, em `sql/migration_lista_convidados_mysql57.sql`. Siga o padrão das migrations `*_mysql57.sql` existentes e use `INFORMATION_SCHEMA` para checar colunas e índices.
8. **Não quebre o Registro Manual atual.** Todo o formulário e a tabela "Registros Recentes" continuam funcionando exatamente como hoje, dentro da primeira aba.

---

### 1. Contexto encontrado no código (não reinvente)

| Item | Situação atual | Implicação |
|---|---|---|
| `frontend/pages/registro.html` | Página única, sem abas: card "Registrar Acesso" + card "Registros Recentes". `div.page-wrapper.page-registro`. CSS inline no fim do arquivo. | Envolver o conteúdo atual na aba 1 sem alterar IDs. |
| Padrão de abas | Já existe em `frontend/pages/abastecimento.html` (`.tabs` > `.tab-button[data-tab]` + `.tab-content#tab-*`) com CSS em `assets/css/pages/abastecimento.css` escopado em `.page-abastecimento`. | Reutilizar o mesmo markup e as mesmas classes, escopados em `.page-registro`. Não crie um componente novo. |
| `frontend/js/pages/registro.js` | ES Module de 1215 linhas. Usa `API_REGISTROS`, `API_VISITANTES` (`?documento=`), `API_MORADORES` (`?unidade=&ativo=1&por_pagina=0`), `API_DEPENDENTES` (`?acao=listar&morador_id=`), `API_UNIDADES` (`?acao=select`). Já tem máscara CPF/RG (`_aplicarMascaraDocumentoValor`), busca de visitante por documento, cascata Unidade → Morador → Dependente e `_esc()`. | Reaproveite essas funções. Isole a lógica nova em `frontend/js/pages/registro-convidados.js` (módulo importado por `registro.js`, com `init`/`destroy` próprios) para não inflar o arquivo. |
| `registros_acesso.tipo` | `ENUM('Morador','Visitante','Prestador')`. Whitelist repetida em `api/api_registros.php` (≈ linhas 212, 428 e 524) e em `api/api_portal_morador.php` (≈ linha 239). | Ver §4: novos tipos exigem migration e atualização de **todas** as whitelists. |
| Visitantes | `api/api_visitantes.php` (sessão, `operador`). Busca por `?documento=`. O cadastro exige anexo e grava autoria em `cadastrado_por_tipo/usuario_id/morador_id/nome`. A configuração do tenant pode exigir documento digitalizado (`visitantes_config_helper.php`). | O convidado **herda** o visitante pelo documento. A validação de anexo continua sendo aplicada só no momento da entrada, via Registro Manual. |
| `acessos_visitantes` + `api_acessos_visitantes.php` | Autorização com QR usada pelo app (portaria/externo/lagoa), com a regra "1 visitante = 1 acesso ativo no sistema todo". | **Não reutilize esta tabela** para a lista de convidados: a regra de acesso único e o fluxo de QR são outro conceito. Documente essa decisão no AI-CONTEXT. Integração futura opcional. |
| Dependentes | Tabela `dependentes` vinculada a `morador_id`. `sessoes_portal` só tem `morador_id`: **dependente não tem login hoje**. | O autor "DEPENDENTE" só pode ser gravado se existir autenticação de dependente. Ver §2.4. |
| RBAC | `rbac_helper.php` (`rbacTabelasDisponiveis`, `rbacExigir`, `rbacPode`, `rbacAuditar`). O módulo do menu é `registro` (`frontend/js/permissoes-modulos.js`). | Use o módulo `registro` (ações `visualizar`/`criar`/`editar`/`excluir`), com fallback para `verificarAutenticacao(true,'operador')` quando o RBAC estiver inativo. Audite criação, edição e cancelamento. |
| Notificações | `api/helpers/access_control_notification_helper.php` (`controle_acesso_criar_notificacao_registro`). | Quando o convidado der entrada pelo Registro Manual, a notificação atual já acontece. Não duplique. |

---

### 2. Regras de negócio

#### 2.1 Campos do cadastro de convidado (ordem na tela)

1. **Unidade** \* — select de `api_unidades.php?acao=select`.
2. **Convidado de** \* — radio `Morador` | `Dependente`.
   - **Morador** \*: select carregado pela unidade (mesma cascata do Registro Manual).
   - **Dependente** \*: aparece quando "Dependente" está marcado. Carrega os dependentes do morador selecionado (`api_dependentes.php?acao=listar&morador_id=`). Também exige o morador titular, porque o `morador_id` é sempre gravado.
3. **Tipo de Documento** \* — `CPF` | `RG`.
4. **Número do Documento** \* + botão buscar (lupa) — reutilize a máscara existente e valide o CPF (dígitos verificadores) no front e no back (`api/helpers/cpf_helper.php`).
   - Ao buscar (`api_visitantes.php?documento=`): se o visitante **existir**, preencha Nome (somente leitura), grave `visitante_id` e mostre o box verde "Cadastro de visitante encontrado" (mesmo estilo `.visitante-encontrado`).
   - Se **não existir**: libere o Nome para digitação, mostre o aviso "Visitante ainda não cadastrado — o cadastro completo (com anexo, se exigido) será feito na chegada" e deixe `visitante_id = NULL`.
5. **Nome completo** \*.
6. **Tipo** \* — `Visitante`, `Prestador`, `Entregador Autorizado`, `Familiar`. Morador não entra aqui, porque convidado não é morador.
7. **Data prevista de chegada** \* — `date`, padrão = data selecionada no navegador de datas. Não aceite data passada na criação.
8. **Dias de permanência** \* — inteiro de 1 a 365, padrão 1.
9. **Controle de entradas** \* — select:
   - `Acesso único` → `modalidade_entradas='UNICO'`, `limite_entradas=1`.
   - `2 entradas`, `4 entradas`, `6 entradas`, `8 entradas`, `10 entradas` → `MULTIPLO`, `limite_entradas` ∈ {2,4,6,8,10}. Gere a lista de múltiplos de 2 a partir de uma constante, para poder ampliar depois.
   - `Indeterminado` → `INDETERMINADO`, `limite_entradas=NULL` (vale qualquer número de entradas dentro do período de validade).
   - Regra de consumo: **cada registro de Entrada** vinculado ao convidado consome 1. Saídas não consomem. Quando `entradas_utilizadas >= limite_entradas`, o convidado não aceita nova entrada (a saída continua permitida).
10. **Origem do cadastro** \* — select: `E-mail`, `WhatsApp`, `Presencial`, `Administrativo`. No painel (portaria/admin) o operador escolhe. Quando o cadastro vem do portal ou app do morador, o backend grava `PORTAL_MORADOR` automaticamente e o campo não aparece para o morador. ⚠️ **Confirmar com o André** antes de codar: esse quinto valor automático não foi pedido explicitamente, mas sem ele a origem de um cadastro feito pelo morador ficaria falsa.
11. **Observação** — opcional, até 500 caracteres.

#### 2.2 Período de validade (derivado, nunca digitado)

- `validade_inicio = data_prevista_chegada`
- `validade_fim = data_prevista_chegada + (dias_permanencia - 1)`
- Um convidado "aparece" numa data D se `validade_inicio <= D <= validade_fim`.

#### 2.3 Status

`PREVISTO` → (primeira entrada) `PRESENTE` → (saída com limite esgotado, ou fim da validade) `FINALIZADO`. Status adicionais: `CANCELADO` (ação manual, com motivo obrigatório) e `EXPIRADO`.

- `EXPIRADO` é **calculado na consulta** (`validade_fim < CURDATE()` e status ainda `PREVISTO`/`PRESENTE`) e devolvido como `status_exibicao`. **Não crie cron** só para isso. Persista apenas transições reais: entrada, saída, cancelamento.
- Não use exclusão física. Cancelar = `status='CANCELADO'` + `cancelado_por_*` + `cancelado_em` + `motivo_cancelamento`.

#### 2.4 Quem cadastrou (autoria — mostrar no painel)

- A autoria é **sempre derivada da sessão ou do token**, no mesmo padrão de `api_visitantes.php`, que confirma o autor no banco.
  - Painel web (sessão `usuarios`) → `cadastrado_por_tipo='USUARIO'`, `cadastrado_por_usuario_id`, `cadastrado_por_nome`.
  - Portal/app do morador (token em `sessoes_portal`) → `cadastrado_por_tipo='MORADOR'`, `cadastrado_por_morador_id`, `cadastrado_por_nome`.
  - `DEPENDENTE` → deixe o ENUM e a coluna `cadastrado_por_dependente_id` preparados, mas **não grave** esse valor enquanto não existir login de dependente (hoje não existe). **Nunca aceite o autor vindo do payload.** Se a sessão do portal passar a identificar um dependente no futuro, a API passa a gravar `DEPENDENTE`. Registre isso como pendência no AI-CONTEXT.
- Não confunda **autor** (quem cadastrou) com **anfitrião** (`anfitriao_tipo` MORADOR/DEPENDENTE, para quem é o convidado). Mostre os dois.
- Na listagem, mostre um badge: `Usuário` (azul) / `Morador` (verde) / `Dependente` (roxo) + nome + data/hora do cadastro. Também guarde `atualizado_por_*` e `atualizado_em`.

#### 2.5 Validações de integridade

- O morador (e o dependente, se houver) precisa pertencer à unidade e ao tenant. Revalide no backend: a unidade é sempre resolvida a partir do `morador_id` no tenant, como já faz o POST manual (`API.md` §8). Nunca confie na unidade enviada pelo navegador.
- Bloqueie duplicidade: mesmo `tenant_id` + documento normalizado + `morador_id` com períodos sobrepostos e status `PREVISTO`/`PRESENTE` → HTTP 409, `codigo: CONVIDADO_DUPLICADO`.
- Regra existente: um morador não pode ser cadastrado como visitante (`BUSINESS_RULES.md`). Se o documento pertencer a um morador ativo do tenant, recuse com mensagem clara.
- Use `idempotency_key` no POST (mesmo padrão de `registros_acesso`) para evitar duplo clique ou retry criando dois registros.
- Normalize o documento (só dígitos para CPF; maiúsculas, sem pontuação, para RG) numa coluna `documento_normalizado` indexada.

---

### 3. Banco de dados — `sql/migration_lista_convidados_mysql57.sql`

Crie a tabela nova `convidados` (InnoDB, utf8mb4). Ajuste tipos e nomes às convenções reais do schema após conferir `DATABASE.md` e o dump:

```sql
CREATE TABLE IF NOT EXISTS convidados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  unidade VARCHAR(50) NOT NULL,                 -- mesmo formato de moradores.unidade
  morador_id INT NOT NULL,                      -- titular/anfitrião
  anfitriao_tipo ENUM('MORADOR','DEPENDENTE') NOT NULL DEFAULT 'MORADOR',
  dependente_id INT NULL,
  visitante_id INT NULL,                        -- herdado do cadastro de visitantes
  tipo_documento ENUM('CPF','RG') NOT NULL,
  documento VARCHAR(20) NOT NULL,
  documento_normalizado VARCHAR(20) NOT NULL,
  nome VARCHAR(200) NOT NULL,
  tipo_convidado ENUM('VISITANTE','PRESTADOR','ENTREGADOR_AUTORIZADO','FAMILIAR') NOT NULL,
  data_prevista_chegada DATE NOT NULL,
  dias_permanencia SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  validade_fim DATE NOT NULL,                   -- gravado pelo backend = chegada + dias - 1
  modalidade_entradas ENUM('UNICO','MULTIPLO','INDETERMINADO') NOT NULL DEFAULT 'UNICO',
  limite_entradas SMALLINT UNSIGNED NULL,       -- 1 | 2,4,6,8,10 | NULL
  entradas_utilizadas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  origem_cadastro ENUM('EMAIL','WHATSAPP','PRESENCIAL','ADMINISTRATIVO','PORTAL_MORADOR') NOT NULL,
  status ENUM('PREVISTO','PRESENTE','FINALIZADO','CANCELADO') NOT NULL DEFAULT 'PREVISTO',
  observacao VARCHAR(500) NULL,
  cadastrado_por_tipo ENUM('USUARIO','MORADOR','DEPENDENTE') NOT NULL,
  cadastrado_por_usuario_id INT NULL,
  cadastrado_por_morador_id INT NULL,
  cadastrado_por_dependente_id INT NULL,
  cadastrado_por_nome VARCHAR(150) NOT NULL,
  atualizado_por_tipo ENUM('USUARIO','MORADOR','DEPENDENTE') NULL,
  atualizado_por_id INT NULL,
  atualizado_por_nome VARCHAR(150) NULL,
  cancelado_por_nome VARCHAR(150) NULL,
  cancelado_em DATETIME NULL,
  motivo_cancelamento VARCHAR(255) NULL,
  primeira_entrada_em DATETIME NULL,
  ultima_movimentacao_em DATETIME NULL,
  idempotency_key VARCHAR(36) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_conv_tenant_periodo (tenant_id, data_prevista_chegada, validade_fim),
  KEY idx_conv_tenant_doc (tenant_id, documento_normalizado),
  KEY idx_conv_tenant_morador (tenant_id, morador_id),
  KEY idx_conv_tenant_status (tenant_id, status),
  UNIQUE KEY uk_conv_tenant_idempotency (tenant_id, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Em `registros_acesso`, adicione de forma idempotente `convidado_id INT NULL` + índice `(tenant_id, convidado_id)` para rastrear cada entrada e saída do convidado.

⚠️ Em MySQL 5.7, `CHECK` não é aplicado: as regras de `limite_entradas` × `modalidade_entradas` e de `validade_fim` são validadas **no PHP**.

---

### 4. Novos tipos "Entregador Autorizado" e "Familiar" também no Registro Manual

O select **Tipo** do Registro Manual passa a ter: Morador, Visitante, Prestador, **Entregador Autorizado**, **Familiar**.

- **Migration**: `ALTER TABLE registros_acesso MODIFY tipo ENUM('Morador','Visitante','Prestador','Entregador Autorizado','Familiar') NOT NULL`. Antes, verifique o tipo atual da coluna em produção (pode estar como VARCHAR) e preserve os dados. Teste o tempo do ALTER numa cópia: a tabela é grande.
- **Comportamento**: os dois tipos novos seguem **o mesmo fluxo de Visitante/Prestador**: identificação por documento, destino Unidade → Morador, ocupantes, validação de documento digitalizado. Em `registro.js`, troque as comparações `tipo === 'Visitante' || tipo === 'Prestador'` por um helper único `_tipoExigeDocumento(tipo)`.
- **"Familiar" ≠ Dependente**: familiar é um parente visitante, que não mora na unidade. Dependente continua sendo o fluxo "Morador + checkbox Dependente". Deixe isso explícito no tooltip.
- **Atualize TODAS as whitelists e filtros**. Busque por `'Morador', 'Visitante', 'Prestador'` e `Prestador` no repositório inteiro, ignorando os arquivos-armadilha. No mínimo:
  - `api/api_registros.php` (validação do POST, filtro `tipo` do GET e do `relatorio_ocupantes`);
  - `api/api_portal_morador.php` (filtro do histórico do morador);
  - `frontend/pages/relatorios.html` e o JS dela (filtro de tipo);
  - `api/api_relatorio_ocupantes_pdf.php`;
  - qualquer integração ControlID que escreva `tipo`;
  - app Flutter: filtro de tipo em `presentation/screens/access_control/access_control_screen.dart` (hoje Morador/Visitante/Prestador).

---

### 5. Backend — `api/api_convidados.php` (painel, sessão)

Arquivo novo no padrão de `api/api_visitantes.php`, procedural com `$_GET['acao']`, porque é o padrão do módulo. Não migre para ApiBase/MVC sem pedido. Exige `verificarAutenticacao(true, 'operador')` + RBAC `registro.*` quando ativo.

| Ação | Método | Parâmetros | Descrição |
|---|---|---|---|
| `listar` | GET | `data` (YYYY-MM-DD, padrão hoje no fuso `America/Sao_Paulo`), `unidade?`, `status?`, `tipo?`, `busca?` (nome/documento) | Convidados válidos em `data` (regra §2.2). Retorna `status_exibicao`, `entradas_restantes`, autoria, anfitrião (nome do morador/dependente), documento **mascarado** (`***.456.789-**`) e `resumo` com os contadores do dia: total, previstos, presentes, finalizados, cancelados e expirados. Ordena por status (presentes, previstos, demais) e nome. |
| `obter` | GET | `id` | Detalhe completo, incluindo as movimentações vinculadas (`registros_acesso.convidado_id`). |
| `criar` | POST | campos de §2.1 + `idempotency_key` | Valida tudo de §2.5, calcula `validade_fim` e grava a autoria. |
| `editar` | PUT | `id` + campos | Só se o status for `PREVISTO`. Recalcula a validade. Grava `atualizado_por_*`. |
| `cancelar` | POST | `id`, `motivo` | Só `PREVISTO`/`PRESENTE`. Grava `cancelado_*`. |
| `buscar_documento` | GET | `tipo_documento`, `documento`, `data?` | Usado pela portaria: devolve os convidados válidos hoje para aquele documento (para sugerir o vínculo no Registro Manual). |

**Vínculo com a entrada** (em `api/api_registros.php`, POST manual): aceite o campo opcional `convidado_id`. Se ele vier preenchido:

1. Revalide no mesmo tenant: status `PREVISTO`/`PRESENTE`, data dentro da validade, documento igual ao do registro.
2. Na **Entrada**, verifique o limite. Faça `UPDATE convidados SET entradas_utilizadas = entradas_utilizadas + 1 ... WHERE id=? AND tenant_id=? AND (limite_entradas IS NULL OR entradas_utilizadas < limite_entradas)` e confira `affected_rows` (evita condição de corrida). Na **Saída**, não consuma.
3. Atualize `status`, `primeira_entrada_em` e `ultima_movimentacao_em`. Faça a transição para `FINALIZADO` quando a saída acontecer com o limite esgotado.
4. Faça tudo **na mesma transação** do INSERT em `registros_acesso`.
5. Se o convidado ainda não tinha `visitante_id` e o visitante foi criado na chegada, atualize `convidados.visitante_id`.

A ausência de `convidado_id` mantém 100% o comportamento atual, preservando a compatibilidade.

---

### 6. Frontend — aba no painel

#### 6.1 Estrutura (`frontend/pages/registro.html`)

```
Registro de Acesso (título da página)
[ Registro Manual ] [ Lista de Convidados (badge: qtd prevista hoje) ]
├─ #tab-registro-manual   → TODO o conteúdo atual (form + Registros Recentes), sem alterar IDs
└─ #tab-lista-convidados  → conteúdo novo
```

- Copie as regras `.tabs` / `.tab-button` / `.tab-content` de `assets/css/pages/abastecimento.css`, escopadas em `.page-registro`, e mova o CSS inline atual de `registro.html` para `assets/css/pages/registro.css` se esse arquivo for carregado pelo AppRouter. Confirme antes.
- Aba ativa refletida na URL: `layout-base.html?page=registro&aba=convidados`. Abrir com esse parâmetro abre direto a aba. Guarde a última aba em `sessionStorage` (com try/catch).
- O título da página continua "Registro Manual" no metadata, a menos que o menu também seja ajustado. Avalie e documente.

#### 6.2 Navegação por data (topo da aba)

```
[◀]  [ 📅 31/10/2026 — Sábado · HOJE ]  [▶]   [Hoje]        [+ Novo Convidado]
```

- Abre **sempre na data de hoje** (fuso `America/Sao_Paulo`, calculado no cliente sem `toISOString()`, para não cair no dia anterior por UTC).
- ◀/▶ = −1/+1 dia. O campo de data abre o seletor nativo. O botão "Hoje" só fica habilitado fora de hoje. O selo "HOJE", "ONTEM" ou "AMANHÃ" aparece ao lado do dia da semana.
- Atalhos de teclado ← → quando o foco não estiver num input.
- Cada troca de data chama `listar` com debounce e cancela a requisição anterior (`AbortController`).

#### 6.3 Painel da lista

- **Cards-resumo** do dia: Previstos · Presentes · Finalizados · Expirados · Cancelados. Clicar num card filtra a lista.
- **Filtros**: unidade, tipo, status, busca por nome/documento.
- **Tabela** (`.table-responsive` / `.data-table`; em mobile vira cards, como `.visitante-card` do portal):
  `Nome` · `Documento (mascarado)` · `Tipo` · `Unidade` · `Convidado de (Morador/Dependente + nome)` · `Chegada prevista` · `Permanência (x dias, até dd/mm)` · `Entradas (usadas/limite ou "Indeterminado")` · `Origem` · `Cadastrado por (badge + nome + data/hora)` · `Status` · `Ações`.
- **Ações**:
  - **Dar entrada**: troca para a aba Registro Manual com Tipo, documento, nome, unidade e morador já preenchidos e o `convidado_id` oculto. O operador completa placa/pedestre e confirma. Todas as validações existentes continuam valendo, inclusive o documento digitalizado.
  - **Registrar saída**: mesmo fluxo, com Saída.
  - **Editar**: só `PREVISTO`.
  - **Cancelar**: modal com motivo obrigatório.
  - **Ver histórico**: modal com as movimentações.
  - Esconda as ações conforme `rbacPode` e o status.
- **Modal "Novo/Editar Convidado"** (`.modal-overlay` / `.modal-content`) com os campos de §2.1 na ordem pedida, botões desabilitados durante o envio e `idempotency_key` renovada a cada alteração do formulário.
- **Integração no Registro Manual**: ao buscar um documento no Registro Manual, chame também `api_convidados.php?acao=buscar_documento`. Se houver convidado válido hoje, mostre um box "Convidado previsto para hoje — Unidade X, convidado de Y — entradas 1/2" com o botão "Vincular", que preenche a unidade, o morador e o `convidado_id`.
- Escape todo texto com `_esc()`. Não use `innerHTML` com dados não escapados.
- Os estados vazio ("Nenhum convidado previsto para este dia"), carregando e erro devem estar tratados.

---

### 7. Lado do morador (portal web + app Flutter)

Implemente **depois** que o painel estiver validado, em fases separadas.

- **Endpoint**: `api/api_portal_convidados.php` (ou a ação `convidados` em `api/api_portal_morador.php`, se o padrão do portal preferir. Confira e justifique). Usa autenticação Bearer de `sessoes_portal` / sessão PHP do morador, como `api_portal_dependentes.php`. `tenant_id`, `morador_id` e unidade vêm **somente do token**. O morador só lista e cria convidados da própria unidade e só escolhe entre os **próprios** dependentes.
- **Ações**: `listar` (por data, com a mesma navegação), `criar`, `editar` (só `PREVISTO`), `cancelar`. Origem forçada `PORTAL_MORADOR`. Autor `MORADOR`.
- **Portal web** (`frontend/portal_morador.html`): nova seção "Meus Convidados" com o navegador de datas e um formulário simplificado (sem o campo Unidade e sem Origem).
- **App Flutter**: nova tela `presentation/screens/guests/` (ou uma aba dentro de `visitors/`). Use o mesmo `dioClient` e o padrão de `visitors_screen.dart`, com o endpoint em `core/constants/app_constants.dart`. Não use `initBaseUrl()`: esse método foi removido (ver `APP_MOBILE_ARCHITECTURE.md` §6).
- **Notificação opcional**: na primeira entrada do convidado, a notificação atual de registro já avisa o morador. Opcionalmente, enriqueça o texto com "Seu convidado {nome} chegou". Não crie um segundo evento duplicado.

---

### 8. Testes obrigatórios (registre os resultados no AI-CONTEXT)

1. `php -l` em todos os PHP alterados ou criados. Rode a migration duas vezes (tem de ser idempotente) numa cópia MySQL 5.7.
2. **Isolamento**: um usuário do tenant A não lista, edita nem cancela convidado do tenant B (teste trocando o `id`). Um morador não vê convidados de outra unidade nem usa dependente de outro morador.
3. **Autoria**: cadastro pelo painel mostra "Usuário + nome". Cadastro pelo portal mostra "Morador + nome". Enviar `cadastrado_por_tipo` no payload é ignorado.
4. **Herança**: CPF de visitante existente preenche o nome e o `visitante_id`. CPF inexistente permite nome manual. CPF inválido é recusado. CPF de morador é recusado.
5. **Datas**: a navegação ◀ ▶ e "Hoje" funciona. Às 23h30 no fuso SP, a data exibida continua sendo "hoje". Um convidado com 3 dias aparece em D, D+1 e D+2 e não aparece em D+3, onde vira expirado.
6. **Entradas**: Acesso único bloqueia a 2ª entrada. "4 entradas" permite exatamente 4. Indeterminado não bloqueia dentro da validade. Duas entradas simultâneas (duas abas) não ultrapassam o limite.
7. **Duplicidade e idempotência**: o mesmo convidado com período sobreposto dá 409. Duplo clique cria 1 registro.
8. **Regressão do Registro Manual**: Morador, Morador + Dependente, Visitante, Prestador, Pedestre, Ocupantes, documento digitalizado exigido, idempotência e Registros Recentes continuam iguais.
9. **Tipos novos**: Entregador Autorizado e Familiar são gravados, aparecem em Registros Recentes, filtram em Relatórios e no PDF, e aparecem no histórico do morador (portal e app).
10. **Layout**: desktop, tablet e mobile (≤ 768px) sem rolagem horizontal. A aba persiste via `?aba=convidados`. O console não mostra erros.

---

### 9. Documentação (mesma tarefa)

Atualize, sem apagar histórico:

- `DATABASE.md`: tabela `convidados` e a coluna `registros_acesso.convidado_id`; ENUM `tipo` ampliado.
- `API.md`: `api_convidados.php`, o endpoint do portal e o campo `convidado_id` no POST de `api_registros.php`.
- `BUSINESS_RULES.md`: §2 inteiro (validade, consumo de entradas, status, autoria × anfitrião, Familiar ≠ Dependente).
- `MODULES.md`, `FRONTEND.md` (padrão de abas reaproveitado), `APP_MOBILE_BUSINESS_RULES.md` (nova tela).
- `CHANGELOG.md`: nova versão com Adicionado / Alterado / Testes.
- Registre as decisões: não reutilizar `acessos_visitantes`; `EXPIRADO` calculado; origem `PORTAL_MORADOR`; autor `DEPENDENTE` pendente de login de dependente.

### 10. Entrega em fases (um commit por fase, cada uma testada)

1. **Fase 1**: migration + `api_convidados.php` + vínculo `convidado_id` em `api_registros.php`.
2. **Fase 2**: abas + Lista de Convidados no painel + integração com o Registro Manual.
3. **Fase 3**: tipos Entregador Autorizado/Familiar em todo o fluxo (whitelists, relatórios, PDF, app).
4. **Fase 4**: portal web do morador.
5. **Fase 5**: app Flutter.

Ao final de cada fase: resumo objetivo do que mudou, arquivos afetados, testes executados e resultados, e pendências.

## FIM DO PROMPT
