# Prompt para o Manus — Registro Manual: suporte a Pedestre

Copie e cole o bloco abaixo inteiro para o Manus.

---

Você vai trabalhar no repositório `app.erpcondominios.com.br-` (ERP Condomínios). Antes de escrever qualquer código, siga o protocolo de boot: leia `AI-CONTEXT/SKILL.md`, `AI-CONTEXT/PROTOCOLO_MANUS.md` e a skill `erp-condominios-api-dev`. Não varra o repositório nem as pastas `docs/`/`md/`. Os arquivos relevantes para esta tarefa já estão identificados abaixo — abra diretamente por nome, sem procurar.

## Objetivo

A tela **Registro Manual** (`frontend/pages/registro.html`, `frontend/js/pages/registro.js`, `api/api_registros.php`) hoje só contempla acesso **de veículo**: Placa é obrigatória, e há campos de Modelo/Cor do veículo. Preciso que a mesma tela passe a suportar também o lançamento de **pedestres** (pessoas a pé, sem veículo), sem perder a característica visual nem duplicar a tela. A regra de negócio de veículo que já existe (Morador/Visitante/Prestador, Entrada/Saída, detecção automática por placa, ocupantes do veículo, validação de documento digitalizado) deve continuar funcionando exatamente como está para o modo veículo.

## Arquivos envolvidos

- `frontend/pages/registro.html` — formulário e tabela.
- `frontend/js/pages/registro.js` — lógica de exibição condicional (`onTipoChange`, linha ~590), validação e payload (`salvarRegistro`, linha ~729), renderização da tabela (`renderRegistros`, linha ~679) e busca (`filtrarRegistros`, linha ~663).
- `api/api_registros.php` — contrato de API: GET lista (linha ~136), POST cria (linha ~172), PUT (linha ~488), DELETE (linha ~515). Já existe um padrão de auto-migração de colunas via `_garantir_coluna()` (linhas 60–68) — **use exatamente esse padrão**, não crie um arquivo `.sql` de migration separado para isto.
- Documentação a atualizar ao final: `AI-CONTEXT/API.md`, `AI-CONTEXT/BUSINESS_RULES.md`, `AI-CONTEXT/DATABASE.md` (tabela `registros_acesso`), e a skill `erp-condominios-api-dev` se o contrato de resposta mudar.

## Especificação funcional

### 1. Novo marcador "Lançar como Pedestre"

Adicione um checkbox no formulário, reaproveitando exatamente o padrão visual e de markup já usado pelos checkboxes existentes desta mesma página (`checkDependente` em `#camposMorador` e `checkOcupantes` em `#extraCampos` — classes `.checkbox-label` / `.checkbox-custom`, já definidas no `<style>` do próprio `registro.html`). Não crie um componente novo de toggle.

Posicione o marcador logo acima da LINHA 1 do formulário (Data/Hora, Placa, Tipo, Entrada/Saída), de forma que o operador decida o modo antes de preencher o resto. Texto sugerido: **"Lançar como Pedestre (acesso sem veículo)"**.

O `Tipo` (Morador/Visitante/Prestador) continua sendo selecionado normalmente nos dois modos — o marcador só muda quais campos aparecem e o que é obrigatório, não o fluxo de Tipo em si.

### 2. Comportamento ao marcar "Pedestre"

Quando o checkbox estiver marcado:

- O campo **Placa** (LINHA 1) fica oculto (ou desabilitado) e deixa de ser obrigatório.
- Os campos **Modelo do Veículo** e **Cor do Veículo** (LINHA 2) ficam ocultos.
- No lugar deles, na mesma linha/grid (`reg-grid-3`, para não alterar o layout), exiba um campo novo **"Vestimenta"** (opcional, texto livre, ex.: placeholder "Ex: camisa azul, boné preto..."). **Observação** continua no mesmo lugar, sem mudança.
- O listener de `blur` da placa (`detectarVeiculoPorPlaca`) não deve disparar em modo pedestre — não faz sentido procurar veículo por uma placa que não existe.
- O bloco **"Ocupantes do Veículo"** (`#ocupantesWrap`, dentro de `#extraCampos`, usado só quando Tipo = Visitante/Prestador) deve ficar oculto em modo pedestre — o conceito de "outros ocupantes do mesmo veículo" não existe sem veículo. Os demais campos de Visitante/Prestador (documento, nome, unidade de destino, morador de destino, dias de permanência) continuam exatamente iguais.
- O bloco de Morador (`#camposMorador`: unidade, morador, dependente) não muda em nada — a seleção já é manual por unidade/morador, não depende de placa.

Ao desmarcar o checkbox, o formulário deve voltar ao comportamento atual (Placa/Modelo/Cor visíveis e obrigatórios como hoje, Vestimenta oculta, Ocupantes disponível de novo). Reaproveite o padrão já usado em `onTipoChange()` (limpar campos ao trocar de contexto) para limpar Vestimenta ao voltar para modo veículo, e para limpar Placa/Modelo/Cor ao entrar em modo pedestre.

### 3. Payload enviado ao backend

Em `salvarRegistro()`, adicione ao payload:

```js
payload.modo_registro = pedestre ? 'PEDESTRE' : 'VEICULO';
if (pedestre) payload.vestimenta = vestimenta; // string, pode ser vazia
```

A validação de campos obrigatórios (linha ~744, hoje `if (!dataHoraInput || !placa || !tipo)`) deve exigir `placa` **somente quando `modo_registro === 'VEICULO'`**. Em modo pedestre, `dataHoraInput` e `tipo` continuam obrigatórios; `placa` não.

### 4. Backend (`api/api_registros.php`)

**Novas colunas em `registros_acesso`**, seguindo o mesmo padrão já usado nas linhas 60–68 (`_garantir_coluna`):

```php
_garantir_coluna($conexao, 'registros_acesso', 'modo_registro', "ENUM('VEICULO','PEDESTRE') NOT NULL DEFAULT 'VEICULO'");
_garantir_coluna($conexao, 'registros_acesso', 'vestimenta',    "VARCHAR(120) NULL DEFAULT NULL");
```

**Validação (linha ~178–201)**: hoje `if (empty($placa) || empty($tipo))` bloqueia tudo sem placa. Troque para: `tipo` sempre obrigatório; `placa` obrigatória apenas quando `modo_registro === 'VEICULO'`. Valide `modo_registro` contra `['VEICULO','PEDESTRE']` (default `VEICULO` se ausente/inválido, mesmo padrão já usado para `tipo_acesso` na linha 190).

**Lookup automático por placa (linhas ~211–226 e ~251–279)**: essa lógica (buscar veículo cadastrado pela placa, herdar modelo/cor/morador/unidade, e a busca específica para `Tipo=Morador`) só deve rodar quando `modo_registro === 'VEICULO'`. Em modo pedestre, pule inteiramente esses blocos — não tente casar placa vazia com a tabela `veiculos`.

**INSERT (linhas ~348–423)**: adicione `modo_registro` e `vestimenta` em `$cols`/`$marks`/`$types`/`$params`, tanto no registro titular quanto — se aplicável — nos registros de ocupante (embora ocupantes só existam hoje para Visitante/Prestador com veículo; se o modo for pedestre, o bloco de ocupantes nem deve ser processado, ver item 2 acima — reflita isso também no backend, ignorando `dados['ocupantes']` quando `modo_registro === 'PEDESTRE'`).

**SELECT do GET (linhas ~139–153)**: incluir `r.modo_registro, r.vestimenta` no `SELECT`.

**Multi-tenant**: nada muda aqui — `tenant_id` continua resolvido exclusivamente por `exigirTenantId()` a partir da sessão, nunca do payload. Não introduza nenhuma leitura de `tenant_id` vinda do cliente.

### 5. Tabela "Registros Recentes"

`placa`, `modelo` e `cor` já toleram valor vazio (`_esc(r.placa || '-')` etc.), então registros de pedestre não vão quebrar a tabela — vão aparecer com "-" nessas colunas, o que já é aceitável. Para não perder legibilidade, adicione um indicador visual leve reaproveitando o padrão de badges já existente no `<style>` da própria página (`.badge-entrada`, `.badge-saida`, `.badge-ocupante`): crie `.badge-pedestre` no mesmo estilo e exiba ao lado da coluna "Tipo" ou "Nome" quando `r.modo_registro === 'PEDESTRE'` (ex.: ícone `fa-person-walking` + texto "Pedestre"). Não adicione uma coluna nova na tabela nem uma biblioteca de ícones diferente da já usada (Font Awesome, já carregado no projeto).

Inclua `vestimenta` como termo pesquisável em `filtrarRegistros()` (mesmo padrão das outras `.includes(q)` já existentes).

## O que NÃO pode mudar

- O contrato de resposta `{"sucesso": bool, "mensagem": string, "dados": {...}}`.
- A validação de documento digitalizado obrigatório para Visitante/Prestador (`_validar_visitante_para_registro`) — continua idêntica, também em modo pedestre.
- O isolamento multi-tenant em todas as queries.
- A transação atômica do INSERT titular + ocupantes.
- O padrão arquitetural do arquivo (procedural, sem migrar para `ApiBase`/MVC nesta tarefa).

## Ao terminar

1. `php -l api/api_registros.php` sem erros.
2. Testar manualmente os dois modos (Veículo e Pedestre) para os três tipos (Morador, Visitante, Prestador) e ambos Entrada/Saída — inclusive o caso de reverter o checkbox de Pedestre para Veículo e vice-versa antes de salvar, para confirmar que os campos certos são limpos.
3. Confirmar que um registro de pedestre aparece corretamente na tabela e no filtro de busca.
4. Atualizar `AI-CONTEXT/API.md`, `AI-CONTEXT/BUSINESS_RULES.md` e `AI-CONTEXT/DATABASE.md` (seção de `registros_acesso`) descrevendo o novo `modo_registro` e `vestimenta` — sem apagar o conteúdo existente, apenas complementar.
5. Resumir a mudança de forma objetiva ao final (arquivos tocados, novas colunas, novo comportamento), sem colar de volta o código já entregue.
