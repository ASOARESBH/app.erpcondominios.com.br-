# Gap: cadastro de documento (GED) não notifica moradores

## Pedido do usuário
Sempre que um documento for cadastrado no módulo GED (`Documentos`) e estiver liberado para moradores, notificar os moradores (in-app + push, igual já acontece hoje para Protocolos/encomendas), informando que um novo documento foi adicionado e o nome do documento.

## Diagnóstico (leitura de código, sem alteração)

### Já existe uma função de notificação de novo documento — mas ela não atende moradores
- `api/api_documentos.php`, função `_notificar_novo_documento()` (linha ~1140), é chamada dentro de `_documento_salvar()` (linha ~860) toda vez que um documento novo é criado.
- Porém essa função:
  1. **Só dispara e-mail** (via `EmailSender.php`), nunca grava nada em `notificacoes_morador`, nunca envia push, nunca aparece na tela de notificações do app.
  2. **Notifica apenas usuários internos do sistema** (`usuarios`, através de `documentos_grupos_usuarios` — grupo de acesso administrativo), nunca `moradores`.
  3. Tem um **early return que pula a notificação inteira quando `grupo_id` é vazio ou é o grupo "Todos" (id=1)**: `if (!$grupoId || $grupoId === 1) return;`. Ou seja, no caso mais comum — documento sem grupo específico, visível a todos — a função nem chega a rodar.
  4. **Não tem nenhuma relação com o campo que realmente controla se o morador pode ver o documento** (`visibilidade` = `todos`/`moradores`/`usuarios`/`unidades_especificas`, e `unidades_acesso` para o caso de unidades específicas). Ela usa `grupo_id` (grupo de acesso administrativo, tabela `documentos_grupos`), um conceito diferente.
- Confirmação por grep: `notificacoes_morador` nunca é referenciada em `api_documentos.php`, e não existe nenhum helper `document_notification_helper.php` (a pasta `api/helpers/` só tem `access_control_notification_helper.php`, `protocol_notification_helper.php` e `tenant_file_storage_helper.php`).

### A regra de visibilidade correta para moradores já existe — só não é usada para notificar
- `api/api_portal_documentos.php` tem a função `pd_where_visivel($moradorId, $unidadeId)` (linha ~96), reutilizada em toda a listagem/consulta de documentos do Portal do Morador. Ela é a fonte da verdade de "quem pode ver este documento":
  ```php
  function pd_where_visivel($moradorId, $unidadeId) {
      $unidadeSql = $unidadeId ? (int)$unidadeId : 0;
      return "d.status = 'ativo'
          AND (d.data_expiracao IS NULL OR d.data_expiracao >= CURDATE())
          AND (
                d.grupo_id IS NULL
             OR EXISTS (
                  SELECT 1 FROM documentos_grupos g
                  WHERE g.id = d.grupo_id AND g.ativo = 1
                    AND (
                          g.acesso_tipo IN ('todos','moradores')
                       OR (g.acesso_tipo = 'personalizado' AND EXISTS (
                              SELECT 1 FROM documentos_grupos_moradores gm
                              WHERE gm.grupo_id = g.id AND gm.morador_id = $moradorId
                          ))
                    )
                )
          )
          AND (
                d.visibilidade IN ('todos','moradores')
             OR (d.visibilidade = 'unidades_especificas' AND $unidadeSql > 0
                 AND JSON_CONTAINS(CAST(d.unidades_acesso AS JSON), CAST($unidadeSql AS JSON)))
          )";
  }
  ```
- Ou seja: um documento é visível a um morador quando (a) não pertence a nenhum grupo administrativo, ou pertence a um grupo com `acesso_tipo` `todos`/`moradores`, ou a um grupo `personalizado` do qual o morador é membro (`documentos_grupos_moradores`); **e** (b) `visibilidade` é `todos`/`moradores`, ou `unidades_especificas` com a unidade do morador incluída em `unidades_acesso` (JSON). `visibilidade = 'usuarios'` é documento só para a equipe — não deve gerar notificação a morador.

### Comparação com o padrão de referência (Protocolos), que já funciona
- `api/helpers/protocol_notification_helper.php` → `protocolo_criar_notificacao_morador()`: grava em `notificacoes_morador` (tabela única de eventos do Portal do Morador), depois tenta push via FCM HTTP v1 (`protocolo_notificacao_enviar_fcm()`), tudo dentro de `try/catch` não bloqueante — o cadastro do protocolo nunca falha por causa da notificação.
- Esse é exatamente o padrão a replicar para documentos: um novo helper (`document_notification_helper.php`) com uma função `documento_criar_notificacao_morador()`, chamada de dentro de `_documento_salvar()` logo após o `INSERT INTO documentos`, resolvendo os destinatários com a mesma regra de `pd_where_visivel()` (adaptada para buscar todos os moradores elegíveis, não um morador específico).

### Lacuna adicional no app (Flutter) — precisa de uma pequena mudança de UI
- `lib/presentation/screens/notifications/notifications_screen.dart` (`_buildNotificationCard`) já trata explicitamente os tipos `mercadoria_entregue`, `veiculo_cadastrado`, `acesso_entrada`, `acesso_saida`. **Não existe tratamento para um tipo de "novo documento"** — um tipo novo (ex.: `documento_novo`) cairia no `else` final, herdando por engano o ícone e a cor de "encomenda" (`Icons.inventory_2_outlined`, verde). O título e o corpo apareceriam certos (vêm de `titulo`/`mensagem`, que o backend controla), mas o ícone/cor ficariam errados. É um ajuste pequeno e não bloqueante: acrescentar um branch `isDocumento = type == 'documento_novo'` com ícone (ex.: `Icons.description_outlined`) e cor próprios.

### Estrutura de banco necessária
- `notificacoes_morador` hoje tem `protocolo_id`, `veiculo_id`, `registro_acesso_id` como colunas de vínculo, mas **não tem `documento_id`**. Será preciso uma nova migração idempotente (nos moldes de `sql/migration_notificacoes_registros_acesso.sql`) adicionando `documento_id INT NULL`, índice e uma chave única `(tenant_id, morador_id, documento_id, tipo)` para evitar notificação duplicada em reprocessamento.

## Conclusão
Não existe hoje nenhum caminho que notifique moradores sobre novos documentos. A função `_notificar_novo_documento()` existente é só e-mail para equipe interna, e ainda assim só roda quando o documento tem um grupo administrativo específico (não é o caso mais comum). É necessário construir o recurso do zero, seguindo o padrão já validado de Protocolos e reaproveitando a regra de visibilidade já existente em `pd_where_visivel()`.

## Fontes locais
- `api/api_documentos.php` (`_documento_salvar`, `_notificar_novo_documento`)
- `api/api_portal_documentos.php` (`pd_where_visivel`)
- `api/helpers/protocol_notification_helper.php` (padrão de referência)
- `api/helpers/access_control_notification_helper.php` (segundo exemplo do mesmo padrão)
- `lib/presentation/screens/notifications/notifications_screen.dart` (app Flutter)
- Investigação relacionada anterior: [DEBUG_NOTIFICACAO_CONTROLE_ACESSO_20260813.md](DEBUG_NOTIFICACAO_CONTROLE_ACESSO_20260813.md)
