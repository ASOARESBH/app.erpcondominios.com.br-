# PROMPT MANUS — Visitantes: consultar todos os visitantes cadastrados para a Gleba (App + Portal)

Você é o tech lead do ERP CONDOMÍNIOS. A tarefa envolve **dois repositórios**:
- Backend/painel/portal web: `ASOARESBH/app.erpcondominios.com.br-`
- App Flutter do morador: `ASOARESBH/aplicativoerpcondominios`

Antes de alterar qualquer coisa, leia `AI-CONTEXT/INDEX.md`, `SKILL.md`, `PROTOCOLO_MANUS.md`, `APP_MOBILE_BUSINESS_RULES.md`, `MULTITENANT_ARCHITECTURE.md` e `BUSINESS_RULES.md` (§ Alertas da Unidade, item sobre alvos de visitante no Portal). Siga a sequência ANALISAR → CAUSA RAIZ → CORRIGIR → TESTAR → DOCUMENTAR.

## Tela
- App Flutter › **Visitantes** (`lib/presentation/screens/visitors/visitors_screen.dart`), lista "Meus Visitantes".
- App Flutter › **Acessos/QR** (`lib/presentation/screens/access/access_screen.dart`), seletor "Visitante *". Usa a mesma lista.
- Portal web do morador › aba **Visitantes** (`frontend/portal_morador.html`, função `carregarVisitantes()`). Usa a mesma API.

## Problema
O morador abre Visitantes e **não vê todos os visitantes cadastrados para a sua Gleba**. Ele só vê os que ele próprio cadastrou. Não aparecem:
1. visitantes cadastrados por **outro morador ativo da mesma Gleba** (ex.: cônjuge com login próprio);
2. visitantes da base geral cadastrados pela **portaria/painel** (`cadastrado_por_tipo = 'FUNCIONARIO'`, `morador_id` NULL) que já têm autorização em `acessos_visitantes` para um morador da Gleba.

Pelo mesmo motivo, o morador também não consegue gerar QR de acesso para esses visitantes.

## Causa raiz (confirmada no código)
- O app não pagina nem filtra: `GET /api/api_portal_morador.php?action=visitantes` e exibe todo o `dados`.
- `api/api_portal_morador.php`, bloco `action === 'visitantes' && GET`: `WHERE tenant_id = ? AND morador_id = ?`. Ou seja, o escopo é o **morador**, não a **unidade**.
- `api/api_acessos_visitantes.php`, POST via Bearer do portal: valida o visitante com `morador_id = sessão OR cadastrado_por_morador_id = sessão`. Tem a mesma restrição.
- A tabela `visitantes` **não tem coluna de unidade**. O vínculo com a Gleba precisa ser derivado dos moradores da unidade.

## Regra de negócio a implementar
- Escopo = **unidade (Gleba) do morador autenticado**, sempre resolvida no servidor a partir de `moradores.unidade` da sessão. O app nunca informa a unidade.
- Normalização da unidade igual à já usada na ação `acessos` do mesmo endpoint: `REPLACE(UPPER(TRIM(unidade)), 'GLEBA ', '')`. Assim, `Gleba 133`, `gleba 133 ` e `133` são a mesma unidade, mas `Gleba 13` e `Gleba 1330` **não** são.
- Um visitante pertence à Gleba se pelo menos uma destas condições valer, sempre no **mesmo tenant**:
  1. `visitantes.morador_id` é de um morador **ativo** da Gleba;
  2. `visitantes.cadastrado_por_morador_id` é de um morador **ativo** da Gleba;
  3. existe linha em `acessos_visitantes` com `visitante_id = v.id`, mesmo `tenant_id` e `morador_id` de um morador **ativo** da Gleba.
- O morador autenticado sempre vê os próprios cadastros (`morador_id`/`cadastrado_por_morador_id` = sessão). Se a unidade dele estiver vazia, o escopo cai para só os próprios.
- Moradores **inativos** não contam: o ex-proprietário não pode expor seus visitantes ao novo morador.
- A base geral de visitantes do tenant **continua fechada** ao portal. Visitante sem vínculo nenhum com a Gleba não aparece.
- **Exclusão continua restrita ao autor** (`morador_id = sessão`). Não amplie o DELETE.

## Correção esperada

### 1. Backend — novo helper `api/helpers/portal_visitantes_unidade_helper.php`
Funções protegidas com `function_exists`, compatíveis com PHP 7.4 e MySQL/MariaDB 5.7:
- `pvu_unidade_morador(mysqli $c, int $tenant_id, int $morador_id): string`: devolve `trim(moradores.unidade)` do morador no tenant.
- `pvu_condicao_visitante_unidade(string $alias, int $tenant_id, int $morador_id, string $unidade): array`: devolve `[sql, tipos, params]` com a condição WHERE para o alias de `visitantes`.
  - Valide o alias com regex.
  - Subconsulta dos moradores da Gleba:
    `SELECT m.id FROM moradores m WHERE m.tenant_id = ? AND m.ativo = 1 AND REPLACE(UPPER(TRIM(COALESCE(m.unidade,''))),'GLEBA ','') = REPLACE(UPPER(TRIM(?)),'GLEBA ','')`
  - Com unidade vazia, a subconsulta vira `... WHERE m.tenant_id = ? AND m.id = ?`.
  - Condição final:
    `(v.morador_id = ? OR v.cadastrado_por_morador_id = ? OR v.morador_id IN (SUB) OR v.cadastrado_por_morador_id IN (SUB) OR EXISTS (SELECT 1 FROM acessos_visitantes a WHERE a.tenant_id = v.tenant_id AND a.visitante_id = v.id AND a.morador_id IN (SUB)))`
  - Use apenas prepared statements; nada de concatenar valores.
- `pvu_bind(mysqli_stmt $stmt, string $tipos, array $params)`: faz o `bind_param` dinâmico por referência.

### 2. Backend — `api/api_portal_morador.php`, GET `action=visitantes`
- Use o helper. Mantenha o early-return quando a coluna `morador_id` não existir.
- Faça `LEFT JOIN moradores` no autor (`cadastrado_por_morador_id`) e no legado (`morador_id`), ambos com `tenant_id`.
- Devolva, por item: `id` (int), `nome_completo`, `documento`, `tipo_documento`, `telefone`, `celular`, `email`, `observacao`, `ativo` (int), `data_cadastro`, e os campos novos:
  - `cadastrado_por_nome`: `COALESCE(NULLIF(NULLIF(cadastrado_por_nome,''),'Cadastro legado'), autor.nome, legado.nome, 'Portaria/Administração' quando FUNCIONARIO)`;
  - `proprio`: bool, true se `morador_id` ou `cadastrado_por_morador_id` for o da sessão;
  - `pode_excluir`: bool, true só se `morador_id` for o da sessão, em linha com o DELETE atual.
- Ordenação: ativos primeiro e depois nome (`ORDER BY v.ativo DESC, v.nome_completo ASC, v.id DESC`).
- Falha de prepare/execute: `error_log` + `retornar_json(false, 'Não foi possível carregar os visitantes da unidade.')`.
- **Não altere** POST, DELETE, contrato `{sucesso, mensagem, dados}` nem as outras ações.

### 3. Backend — `api/api_acessos_visitantes.php`, POST com Bearer do portal
- Troque a validação `morador_id/cadastrado_por_morador_id = sessão` pelo helper, usando `$portal_morador_unidade`, que já é carregado da sessão. Mantenha `v.ativo = 1` e `v.tenant_id = ?`.
- Se faltar a coluna `morador_id` ou `cadastrado_por_morador_id`, mantenha a mensagem atual "O Portal ainda não está configurado para restringir visitantes à unidade autenticada."
- O fluxo do painel (sem Bearer) não muda.
- O arquivo está em CRLF no checkout Windows. Não reformate o arquivo inteiro.

### 4. Portal web — `frontend/portal_morador.html`, `carregarVisitantes()`
- Mostre "Cadastrado por: você/nome" quando houver `cadastrado_por_nome`, com `escHtml`.
- Renderize o botão excluir **somente** se `v.pode_excluir`. Use `Number(v.id)` no `onclick`.

### 5. App Flutter — `aplicativoerpcondominios`
- `visitors_screen.dart`:
  - título "Visitantes da Unidade" com contador (`_visitors.length`) à direita;
  - o estado vazio passa a dizer "Nenhum visitante cadastrado para a unidade.";
  - no item, mostre o subtítulo com documento + "Cadastrado por: você/autor" (`isThreeLine` quando houver autor);
  - o botão excluir só aparece com `pode_excluir == true`;
  - `ativo` deve ser aceito como `1`, `true` ou `"1"`.
- `access_screen.dart` (seletor de visitante do QR): filtre só os ativos (o backend recusa inativos) e use `isExpanded: true` + `TextOverflow.ellipsis`, porque a lista fica maior.
- Não mexa no `dio_client`, no roteamento nem em outras telas.

## Testes obrigatórios
1. Rode `php -l` nos três PHP tocados.
2. Monte um MariaDB descartável e chame o **endpoint real** com esta fixture:
   - Moradores 185 e 186 na `Gleba 133`; o 186 com unidade `gleba 133 `.
   - Morador 187 **inativo** na `Gleba 133`.
   - Morador 190 na `Gleba 13` e morador 191 na `Gleba 1330`.
   - Morador 192 com unidade `133`.
   - Tenant 2 com morador 300 na `Gleba 133`.
   - Visitantes:
     - V1 do 185;
     - V2 do 186;
     - V3 legado do 186, inativo;
     - V4 da portaria com acesso para o 186;
     - V5 da portaria sem vínculo;
     - V6 do 187;
     - V7 do 190;
     - V8 do 191;
     - V9 do tenant 2, com acesso do 300;
     - V10 do 192.
   - **Esperado para o 185:** V1, V2, V3, V4 e V10. **Nunca:** V5, V6, V7, V8 e V9.
   - **Esperado para o 190:** só V7.
3. DELETE pelo 185 do V2 → "não pertence a você". DELETE do V1 → sucesso.
4. Validação do QR (POST em acessos pelo 185):
   - aceita V1, V2, V4 e V10;
   - recusa V3 (inativo), V5, V6, V7, V8 e V9;
   - com a unidade vazia, aceita só os próprios.
5. Rode `flutter analyze` sem novos erros/avisos nos arquivos tocados e `flutter run` em dispositivo Android. Toolchain atual: Flutter 3.47.1, Gradle 8.14, AGP 8.13.1, Kotlin 2.3.20, Java 17 (ver `APP_MOBILE_ARCHITECTURE.md`).
6. Teste autenticado em produção com o morador 185 (`Gleba 133`, tenant 1). A lista deve mostrar os visitantes dos demais moradores da Gleba, sem botão excluir neles. Gere um QR para um visitante cadastrado por outro morador da Gleba.
7. Regressão:
   - cadastro de visitante pelo app/portal continua gravando a autoria;
   - lista de acessos do morador e QR sem mudança;
   - painel `api_visitantes.php` intocado;
   - Alertas do Portal continuam pesquisando só os visitantes vinculados ao morador (não amplie isso nesta tarefa);
   - isolamento por tenant preservado;
   - console do navegador sem erros.

## Deploy
Publique os três PHP (incluindo o helper **novo**) **antes** de distribuir o APK. O APK antigo continua compatível: os campos são adicionais e o botão excluir antigo recebe "não pertence a você" para visitantes de terceiros.

## Documentação
Atualize, preservando o histórico:
- `AI-CONTEXT/CHANGELOG.md`: Corrigido + testes executados com resultado;
- `API.md`: seção Portal do Morador — Visitantes da unidade;
- `APP_MOBILE_BUSINESS_RULES.md`: linha da tabela de Visitantes + parágrafo da regra;
- `TESTING.md`;
- `INDEX.md`.

> Observação: esta correção **já foi implementada e testada localmente** no XAMPP do André em 04/11/2026 (CHANGELOG 2.1.20, `DEBUG_VISITANTES_APP_GLEBA_20261104.md`), mas **pode ainda não estar no GitHub**. Antes de codar, verifique se existe `api/helpers/portal_visitantes_unidade_helper.php` e se `api_portal_morador.php` já devolve `pode_excluir`. Se já existir, apenas revise contra este prompt, teste e complete o que faltar; não duplique o código.
