# Prompt para o Manus — Concorrência e duplicidade no Registro Manual

Copie e cole o bloco abaixo inteiro para o Manus.

---

Você vai reforçar `api/api_registros.php` (Registro Manual, repositório `app.erpcondominios.com.br-`) contra dois problemas reais de concorrência que existem hoje no código, quando mais de um usuário do painel está logado registrando acessos ao mesmo tempo. Os dois problemas já foram diagnosticados abaixo — implemente a correção, teste sob concorrência real e commite/dê push ao final.

## Problema 1 (real, no código hoje): a auto-migração de colunas quebra sob concorrência

`api_registros.php` cria colunas novas em `registros_acesso` sob demanda, com este padrão (linhas ~47-57):

```php
function _tem_coluna($conexao, $tabela, $coluna) {
    $r = $conexao->query("SHOW COLUMNS FROM `$tabela` LIKE '$coluna'");
    return $r && $r->num_rows > 0;
}
function _garantir_coluna($conexao, $tabela, $coluna, $definicao) {
    if (!_tem_coluna($conexao, $tabela, $coluna)) {
        $conexao->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao");
    }
}
```

Isso é um clássico **check-then-act sem lock**: se duas requisições chegarem "ao mesmo tempo" logo após um deploy (coluna ainda não existe), as duas podem ver `_tem_coluna() === false` e as duas tentarem `ALTER TABLE ADD COLUMN` — a segunda falha com erro do MySQL (`Duplicate column name`, errno 1060), e como o código não trata esse erro, a requisição do segundo usuário quebra sem necessidade, mesmo a coluna sendo perfeitamente válida no final. Isso é o "banco falhar na gravação" descrito na tarefa: não é falta de espaço nem lentidão, é uma corrida de schema.

**Correção**: torne `_garantir_coluna()` idempotente sob concorrência, tratando o erro de coluna duplicada como sucesso (a coluna já existe, foi outra requisição que criou primeiro):

```php
function _garantir_coluna($conexao, $tabela, $coluna, $definicao) {
    if (_tem_coluna($conexao, $tabela, $coluna)) return;
    $ok = @$conexao->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao");
    if (!$ok && $conexao->errno !== 1060) { // 1060 = Duplicate column name (corrida concorrente, não é erro real)
        log_registro("ERRO ao garantir coluna $coluna em $tabela", ['erro' => $conexao->error, 'errno' => $conexao->errno]);
    }
}
```

Crie também `_garantir_indice_unico($conexao, $tabela, $nomeIndice, $definicaoColunas)` seguindo a mesma ideia (checar via `INFORMATION_SCHEMA.STATISTICS`, tentar criar, e tratar `errno 1061` "Duplicate key name" como sucesso) — vai ser usada no Problema 2.

## Problema 2 (real, ausente no código hoje): nada impede duplicar o mesmo lançamento

Hoje, se dois operadores registrarem o mesmo evento por engano (ex.: os dois viram o mesmo visitante chegar e cada um lançou manualmente), ou se o mesmo operador clicar duas vezes rápido em uma rede lenta, **duas linhas idênticas são gravadas em `registros_acesso` sem nenhum aviso** — não existe hoje nenhuma restrição de unicidade ou deduplicação neste endpoint (a única deduplicação que existe é entre ocupantes DENTRO da mesma requisição, não entre requisições diferentes).

Este projeto já resolve exatamente esse tipo de problema em outro módulo — reaproveite o mesmo padrão, não invente um novo: `ronda_registros` usa uma chave calculada no cliente/servidor (`ciclo_chave`) com índice único `uk_ronda_registro_ponto_ciclo (tenant_id, ponto_id, colaborador_id, ciclo_chave)`, e a segunda tentativa do mesmo lançamento retorna HTTP 409 em vez de duplicar (ver `AI-CONTEXT/DATABASE.md`, seção "Rondas de Vigilante"). Para o Registro Manual, use uma **chave de idempotência gerada no cliente**, técnica equivalente e mais adequada aqui porque o lançamento não tem um "ciclo" natural como a ronda:

**Backend (`api/api_registros.php`)**:
1. Nova coluna, via `_garantir_coluna()` já corrigido: `idempotency_key VARCHAR(36) NULL DEFAULT NULL`.
2. Novo índice único, via `_garantir_indice_unico()`: `(tenant_id, idempotency_key)` — em MySQL/InnoDB, múltiplas linhas com `idempotency_key = NULL` não colidem entre si num índice único, então isso não afeta linhas antigas nem outras origens (RFID, ControlID) que não mandam essa chave.
3. No POST, ler `$idempotency_key = trim($dados['idempotency_key'] ?? '')`; se vazio, seguir exatamente como hoje (sem dedup — mantém compatibilidade com qualquer outro cliente que não implemente a chave ainda).
4. Se vier preenchida, incluir no INSERT do titular (mesma transação que já existe, linhas ~362-430). Se o `execute()` falhar com `errno === 1062` (Duplicate entry, ou seja, essa chave já foi usada por essa `tenant_id`), **não é um erro para o operador**: busque o registro já existente com essa `idempotency_key` + `tenant_id` e devolva `sucesso:true` com os dados dele (resposta idempotente — a segunda tentativa da mesma ação não falha nem duplica, só devolve o que já foi salvo na primeira vez).

**Frontend (`frontend/js/pages/registro.js`)**:
1. Gerar uma chave nova (`crypto.randomUUID()`, com fallback simples se o navegador não suportar) sempre que o formulário for preparado para um lançamento novo — no carregamento inicial da página e dentro de `limparFormulario()` (por volta da linha ~865). Guardar num campo oculto novo, ex. `<input type="hidden" id="idempotencyKeyRegistro">` em `registro.html`, ao lado dos outros campos hidden já existentes (`moradorId`, `veiculoId`, `visitanteIdRegistro`).
2. Incluir essa chave no `payload` de `salvarRegistro()` (linha ~749) como `idempotency_key`.
3. **Só gerar uma chave nova após sucesso confirmado** (ou ao limpar manualmente) — se o `fetch` falhar por erro de rede e o operador clicar "Registrar" de novo sem ter limpado o formulário, deve reenviar a MESMA chave, para que um retry de rede não vire um lançamento duplicado.
4. O guard atual contra duplo-clique (`salvandoReg`, desabilitar o botão) já existe e continua válido — não precisa recriar, só confirmar que não conflita com o novo campo.

## Como testar sob concorrência real (não só manualmente um de cada vez)

1. **Teste da corrida de schema (Problema 1)**: num ambiente de teste com a coluna `idempotency_key` ainda não criada, disparar 2 requisições POST válidas e diferentes em paralelo (ex.: dois `curl` em background no mesmo segundo, ou um script simples com `Promise.all`/multiprocessing) e confirmar que **nenhuma das duas falha** por erro de `ALTER TABLE`.
2. **Teste de deduplicação (Problema 2)**: montar o mesmo payload duas vezes com a mesma `idempotency_key` e disparar as duas requisições em paralelo; confirmar que **só uma linha é gravada** em `registros_acesso` e que a segunda resposta ainda vem com `sucesso:true` (idempotente, não é erro).
3. **Teste de não regressão**: dois lançamentos diferentes, sem `idempotency_key` (simulando um cliente antigo/RFID/ControlID), continuam gravando normalmente, sem qualquer bloqueio novo.
4. `php -l api/api_registros.php` sem erros.

## Ao terminar

1. Atualizar `AI-CONTEXT/DATABASE.md` (tabela `registros_acesso`, seguindo o mesmo estilo já usado para `ronda_registros`) e `AI-CONTEXT/API.md` descrevendo `idempotency_key` e o comportamento idempotente do POST — sem apagar conteúdo existente, só complementar.
2. Commit apenas dos arquivos tocados (`api/api_registros.php`, `frontend/js/pages/registro.js`, `frontend/pages/registro.html`, docs do AI-CONTEXT) — não usar `git add -A`.
3. Mensagem de commit objetiva em português, por exemplo:
   `fix: adiciona idempotencia e corrige corrida de schema no Registro Manual para evitar duplicidade sob uso concorrente`
4. Push para o repositório remoto, na branch atual (confira com `git status`/`git branch` antes; não force push).
