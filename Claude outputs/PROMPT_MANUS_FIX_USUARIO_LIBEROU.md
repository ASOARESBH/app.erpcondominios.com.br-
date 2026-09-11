# Prompt para o Manus — Preencher "Usuário que liberou" no modal de Acesso

Copie e cole o bloco abaixo inteiro para o Manus.

---

Você vai corrigir um bug real já diagnosticado no repositório `app.erpcondominios.com.br-`. A causa raiz já foi encontrada nos arquivos abaixo — não precisa reinvestigar do zero, sua tarefa é aplicar a correção, testar e commitar.

## Bug observado

Na tela **Acesso** (`layout-base.html?page=acesso`), ao clicar em "Visualizar" em qualquer linha de "Últimos Acessos Liberados", o modal "Visualizar Acesso" sempre mostra o campo **"USUARIO QUE LIBEROU" vazio ("—")**, mesmo quando o acesso foi liberado manualmente por um operador logado no sistema (ex.: origem "Registro Manual"). O esperado é que esse campo mostre o usuário do painel que efetuou a liberação, quando ela foi feita por um humano logado (não se aplica a eventos 100% automáticos de hardware, como o webhook da catraca ControlID/RFID, que continuam sem usuário — isso é correto).

## Causa raiz (já confirmada nos arquivos)

1. `api/api_rfid.php`, ação `ultimos_acessos` (por volta da linha 299), tem literalmente:
   ```php
   NULL as usuario_liberou,
   ```
   Esse campo nunca foi implementado — é um placeholder esquecido dentro do `SELECT` que alimenta a tabela "Últimos Acessos Liberados" e, por consequência, o modal de detalhe.
2. `frontend/js/pages/acesso.js`, função `abrirModalDetalhe` (linha ~501), já está correta e só exibe o que a API mandar:
   ```js
   ['Usuario que liberou', acesso.usuario_liberou || '—'],
   ```
   Não precisa mexer neste arquivo — o problema é 100% de onde o dado vem no backend.
3. A tabela `registros_acesso` **não possui nenhuma coluna** para registrar qual usuário do painel liberou o acesso. Confirmado em `api/api_registros.php` (POST do Registro Manual, por volta da linha 352): a lista de colunas do `INSERT` não inclui usuário algum, mesmo o arquivo já tendo `$_SESSION['usuario_nome']` disponível (é usado ali mesmo, só que apenas para notificação, nunca gravado na tabela).
4. Existem **duas origens de liberação feitas por operador logado** que gravam em `registros_acesso` e que precisam desse dado:
   - `api/api_registros.php`, POST (Registro Manual) — registro titular (linha ~369) e registros de ocupante (linha ~404).
   - `api/api_rfid.php`, ação `verificar_tag` (linha ~81, bloco `INSERT INTO registros_acesso` do "Verificação de Acesso" da própria tela Acesso).
5. Existe uma **terceira origem, automática, que não deve ganhar usuário**: `api/api_rfid.php`, ação `webhook` (linha ~173) — é a catraca/leitor falando direto com a API, sem sessão de operador. Deixe esse INSERT como está.

## Correção a aplicar

**1. Nova coluna em `registros_acesso`** — use o mesmo padrão de auto-migração já usado em `api/api_registros.php` (`_garantir_coluna()`, por volta da linha 60-68), adicionando ao lado das chamadas existentes:

```php
_garantir_coluna($conexao, 'registros_acesso', 'usuario_liberou', "VARCHAR(150) NULL DEFAULT NULL");
```

Guarde o **nome** do usuário (denormalizado, mesmo padrão já usado para `nome_visitante` nesta tabela — não depende de manter o cadastro do usuário vivo para o histórico continuar legível). Não é necessário criar migration `.sql` separada.

**2. Popular a coluna nos dois pontos de gravação feitos por operador logado:**

- Em `api/api_registros.php`, capture o nome do usuário da sessão do mesmo jeito que já é feito mais abaixo no arquivo (`$_SESSION['usuario_nome'] ?? ''`), inclua `usuario_liberou` em `$cols`/`$marks`/`$types`/`$params` do INSERT do registro titular (linha ~352-375) e também do INSERT de cada ocupante (linha ~404-410) — é a mesma pessoa que liberou o titular e os ocupantes no mesmo lançamento.
- Em `api/api_rfid.php`, ação `verificar_tag` (linha ~81), adicione a mesma coluna ao INSERT, preenchida com o usuário da sessão atual.
- **Não** altere o INSERT da ação `webhook` (linha ~173) — esse continua sem usuário, por ser evento automático de hardware.

**3. `api/api_rfid.php`, ação `ultimos_acessos`** (linha ~299): troque

```php
NULL as usuario_liberou,
```

por

```php
r.usuario_liberou,
```

Não precisa de `JOIN` novo — é leitura direta da coluna que acabou de ser adicionada em `registros_acesso`.

## Como testar antes de commitar

1. Fazer um novo lançamento pelo Registro Manual (qualquer tipo) logado com seu usuário e confirmar, no modal "Visualizar Acesso" da tela Acesso, que "Usuário que liberou" mostra seu nome.
2. Repetir usando "Verificação de Acesso" (digitar TAG/placa e clicar "Verificar Acesso") na própria tela Acesso, e confirmar o mesmo.
3. Confirmar que um registro antigo (antes da correção) continua abrindo o modal normalmente, só que com "—" no campo (não deve quebrar registros sem o dado histórico).
4. Confirmar que um evento de webhook/catraca automática continua aparecendo sem usuário (comportamento esperado, não é bug).

## Ao terminar

1. `php -l` nos três arquivos alterados (`api_registros.php`, `api_rfid.php`) sem erros.
2. Atualizar `AI-CONTEXT/DATABASE.md` (tabela `registros_acesso`) e `AI-CONTEXT/API.md` descrevendo a nova coluna/campo — sem apagar conteúdo existente, só complementar.
3. Commit apenas dos arquivos tocados (não usar `git add -A`), mensagem objetiva em português, ex.:
   `fix: registra usuario que liberou o acesso em registros manuais e verificacao por tag`
4. Push para o repositório remoto, na branch atual (confira com `git status`/`git branch` antes; não force push).
