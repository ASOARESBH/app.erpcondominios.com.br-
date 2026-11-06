# Fase 2.3 — Triggers e regras de negócio

**Escopo:** sete análises individuais derivadas exclusivamente dos JSONs privados autorizados e de suas evidências estáticas já revisadas. São narrativas de compatibilidade PostgreSQL/PLpgSQL, não DDL, não instalação e não confirmação live.

## Limites comuns

- Origem analisada: `ASOARESBH/app.erpcondominios.com.br-`; baseline de código: `f60739713e6de3f2473b79ecd14a70fd700e5462`; `source_summary.json` com SHA-256 `44505b1aab8c9a1085453ff3bf4d8a66519700810ac63fcd225693fdefec604a`.
- O destino PostgreSQL já está definido, mas não há PostgreSQL instalado nesta tarefa. Nenhum SQL foi executado ou trigger instalado; código aplicativo, banco, API, VM e `main` não foram alterados. Os documentos foram publicados em `pg/02-3-compatibilidade-riscos`, commit `4288185bb65456d682cbf2cce5b3ebab34d4b0e5`.
- Não são publicados corpos de definição nem informações sensíveis de segurança, mensagens, credenciais ou chaves. Cada origem é citada como **revisão privada `<objeto>.json` (não versionada)**.
- `not_replayed` significa somente que o parser não reproduziu um `ALTER` no estado inicial `CREATE`; não significa que a migração não esteja instalada nem revela o estado MySQL live atual.
- Todo `NEW`/`OLD` com tenant usado na proposta PostgreSQL é melhoria deliberada de segurança e isolamento; não é comportamento legado confirmado. A regra legada é descrita separadamente do escopo seguro proposto.

## Visão consolidada

| Trigger | origem/evento | efeito principal | status live |
|---|---|---|---|
| `trg_atualizar_km_acumulado` | `checklist_veicular`, AFTER UPDATE | acumula KM por categoria ativa | NAO CONFIRMADO |
| `tr_dependente_ativar_veiculos` | `dependentes`, BEFORE UPDATE | reativa veículos inativos na transição 0→1 | NAO CONFIRMADO |
| `tr_dependente_deletar_veiculos` | `dependentes`, BEFORE DELETE | remove veículos vinculados | NAO CONFIRMADO |
| `tr_dependente_inativar_veiculos` | `dependentes`, BEFORE UPDATE | inativa veículos na transição 1→0 | NAO CONFIRMADO |
| `after_qrcode_token_insert` | `qrcode_tokens`, AFTER INSERT | espelha token corrente no acesso | NAO CONFIRMADO |
| `after_validacao_dispositivo_insert` | `logs_validacoes_dispositivo`, AFTER INSERT | atualiza último acesso/contador do dispositivo | NAO CONFIRMADO |
| `trg_alerta_estoque_baixo` | `produtos_estoque`, AFTER UPDATE | insere alerta de estoque baixo | NAO CONFIRMADO |

---
## 1. `trg_atualizar_km_acumulado`

**Origem e evento:** `source_statement_id=S0042`; revisão privada `trg_atualizar_km_acumulado.json` (não versionada).
**Classificação/confiança:** Compatibilidade funcional parcialmente preservável; risco alto de isolamento multi-tenant e de dupla responsabilidade com a API. Confiança média; instalação live não confirmada.

**Fonte:** Origem: `checklist_veicular`; `AFTER UPDATE FOR EACH ROW`. A condição é a transição de estado aberto para fechado.
**Condição:** Usa `OLD` para verificar o estado anterior e `NEW` para o estado final; calcula a distância `km_final - km_inicial`.
**Alvos/regra de escrita:** Lê configurações ativas em `checklist_alertas_config` e cria/incrementa `checklist_km_acumulado` por veículo e categoria; mantém último checklist e data de atualização. A unicidade legada por veículo/categoria precisa tornar-se tenant-aware na proposta.
**Regra de negócio:** Fechar um checklist válido incrementa o KM acumulado de manutenção preventiva para cada categoria ativa. A rotina posterior de alertas pode gerar alerta e zerar o acumulado atingido; o trigger não é de estoque.
**Efeitos:** Insere ou incrementa acumulado, atualiza `ultimo_checklist_id`/data e pode gerar uma linha por categoria ativa. No fluxo estático, a API chama verificação de alertas depois; se o trigger não existir, o ciclo pode ficar sem a distância recém-fechada.
**Tenant — legado versus proposta segura:** A regra exportada não escopa configurações/acumulados por tenant. Na equivalência segura, derivar tenant de `NEW.tenant_id`, validar compatibilidade com `OLD.tenant_id`, filtrar configuração e acumulado por tenant e nunca usar fallback 1. O uso de NEW/OLD e esse filtro são melhoria de segurança deliberada, não comportamento legado confirmado.
**Idempotência/concorrência:** Updates posteriores de checklist já fechado não devem acumular novamente. Concorrência exige chave tenant-aware e incremento atômico; a política final não foi executada/testada.
**Narrativa PL/pgSQL:** Narrativa: função row-level para UPDATE; valida transição, tenant, distância e categorias; faz upsert/incremento atômico por tenant/veículo/categoria na mesma transação e retorna `NEW`. Sem SQL executável.
**Retorno BEFORE/AFTER:** Como é AFTER, retornar `NEW` é convenção e o retorno é ignorado; não altera a linha. Em BEFORE, NEW seria necessário; DELETE usaria OLD, mas não pertence a este objeto.
**Status live:** NAO CONFIRMADO — nenhum SHOW CREATE individual; source-only.

---
## 2. `tr_dependente_ativar_veiculos`

**Origem e evento:** `source_statement_id=S0074`; revisão privada `tr_dependente_ativar_veiculos.json` (não versionada).
**Classificação/confiança:** Cascata de status dependente→veículo; confiança alta para a regra, baixa para instalação live.

**Fonte:** A tabela de origem é `dependentes`; proposta `BEFORE UPDATE FOR EACH ROW`. O export identifica transição inativo→ativo; a origem deve ser tratada como source-only, não como instalação.
**Condição:** Somente quando `OLD.ativo` estava inativo e `NEW.ativo` passa a ativo; alterações cadastrais sem transição não disparam.
**Alvos/regra de escrita:** Atualiza `veiculos`: somente linhas do mesmo dependente (`dependente_id=NEW.id`) que ainda estejam inativas passam a ativas; veículos já ativos não mudam.
**Regra de negócio:** Reativar veículos vinculados quando o dependente é reativado. Não cria/exclui veículos, não recalcula estoque e não emite log/notificação evidenciados.
**Efeitos:** DML em `veiculos` na mesma transação; falha deve abortar/rollbackar conforme política transacional definida. Pode interagir com outros triggers de veículos, não confirmados.
**Tenant — legado versus proposta segura:** O comportamento legado exportado não mostra tenant no alvo. A proposta segura deriva tenant de `NEW.tenant_id` e valida `OLD.tenant_id`, restringindo dependente e veículos ao mesmo tenant, sem fallback 1. Esse escopo é melhoria deliberada de segurança, não legado confirmado.
**Idempotência/concorrência:** Idempotente quanto ao estado: veículos já ativos não são alterados; repetição sem nova transição é no-op. Concorrência com atualização manual de veículo exige teste e proteção adicional.
**Narrativa PL/pgSQL:** Função narrativa: comparar OLD/NEW; sair sem efeito se não for 0→1; validar tenant; atualizar apenas veículos inativos do mesmo tenant e dependente; retornar NEW. Trigger proposto BEFORE UPDATE.
**Retorno BEFORE/AFTER:** BEFORE UPDATE exige retorno NEW para permitir a linha. AFTER ignoraria o retorno; DELETE usaria OLD e não é evento deste objeto.
**Status live:** NAO CONFIRMADO — instalação, ordem e definição live não obtidas.

---
## 3. `tr_dependente_deletar_veiculos`

**Origem e evento:** `source_statement_id=S0075`; revisão privada `tr_dependente_deletar_veiculos.json` (não versionada).
**Classificação/confiança:** Cascata de integridade/limpeza; não é trigger de estoque.

**Fonte:** Origem `dependentes`; `BEFORE DELETE FOR EACH ROW`; usa `OLD`.
**Condição:** Cada linha de dependente excluída aciona a limpeza dos veículos cujo `dependente_id` corresponde a `OLD.id`.
**Alvos/regra de escrita:** Remove registros de `veiculos` vinculados ao dependente excluído. A proposta inclui o mesmo `OLD.tenant_id` no predicado quando ambas as tabelas tiverem essa coluna.
**Regra de negócio:** Não deixar veículos órfãos. É remoção física de dependências, não desativação lógica, atualização de status ou recálculo de estoque.
**Efeitos:** Remove todos os veículos relacionados na mesma transação; falha deve impedir exclusão parcial. Pode afetar listagens/acessos, sem logging/notificação/estoque próprios evidenciados.
**Tenant — legado versus proposta segura:** O legado relaciona por id; a proposta segura deriva exclusivamente de `OLD.tenant_id`, filtra alvo por tenant e rejeita schema sem tenant compatível, sem fallback 1. Melhoria deliberada para PG, não legado confirmado.
**Idempotência/concorrência:** Uma exclusão por linha; rollback preserva atomicidade. Riscos: duplicidade/contradição com FK/cascade existente, locks e remoção cross-tenant se filtrar apenas id.
**Narrativa PL/pgSQL:** Função narrativa recebe OLD, valida schema/tenant, remove veículos do mesmo tenant e retorna OLD. Trigger proposto BEFORE DELETE; não duplicar uma eventual FK equivalente.
**Retorno BEFORE/AFTER:** BEFORE DELETE deve retornar OLD. Em AFTER, retorno seria ignorado e não reproduziria a semântica proposta.
**Status live:** NAO CONFIRMADO — source-only; não afirmar ativo/instalado.

---
## 4. `tr_dependente_inativar_veiculos`

**Origem e evento:** `source_statement_id=S0076`; revisão privada `tr_dependente_inativar_veiculos.json` (não versionada).
**Classificação/confiança:** Cascata unilateral de status; confiança média para regra, baixa para live.

**Fonte:** Origem `dependentes`; `BEFORE UPDATE FOR EACH ROW`; transição `OLD.ativo=ativo` para `NEW.ativo=inativo`.
**Condição:** Somente ativo→inativo; não há evidência para criação, exclusão, mudança cadastral ou reativação.
**Alvos/regra de escrita:** Atualiza `veiculos` ligados por `dependente_id=NEW.id`, marcando-os inativos; não apaga nem desassocia. O export não mostra filtro tenant.
**Regra de negócio:** Inativar o dependente desativa seus veículos. **Não reativa sozinho:** a regra é unilateral. Entretanto, o conjunto com `tr_dependente_ativar_veiculos` tem reativação no caminho separado inativo→ativo; isso não significa que este trigger de inativação reverta o próprio efeito nem que todo veículo originalmente inativo deva ser distinguido.
**Efeitos:** DML em veículos na mesma transação; pode afetar consultas por `veiculos.ativo`; sem estoque, notificações ou auditoria próprios evidenciados. Escritas concorrentes podem reativar manualmente um veículo.
**Tenant — legado versus proposta segura:** O legado não demonstra tenant no alvo. A proposta deriva de `NEW.tenant_id` (validando OLD), filtra `veiculos` por tenant e dependente, rejeita ausência/inconsistência e nunca usa tenant 1. É melhoria deliberada de segurança para PG.
**Idempotência/concorrência:** Repetir estado inativo é no-op lógico, mas uma atualização concorrente de veículo pode produzir corrida. Testar transição, no-op, rollback, tenants coincidentes e alternância manual.
**Narrativa PL/pgSQL:** Função compara OLD/NEW; em 1→0 valida tenant e atualiza somente veículos do mesmo tenant ainda atingíveis; retorna NEW. Proposta BEFORE UPDATE, sem reativação dentro desta função.
**Retorno BEFORE/AFTER:** BEFORE UPDATE retorna NEW; em AFTER o retorno seria ignorado; DELETE/OLD só se uma função genérica futura fosse desenhada.
**Status live:** NAO CONFIRMADO — não há evidência live individual.

---
## 5. `after_qrcode_token_insert`

**Origem e evento:** `source_statement_id=S0204`; revisão privada `after_qrcode_token_insert.json` (não versionada).
**Classificação/confiança:** Espelhamento de estado derivado; `AFTER INSERT` em `qrcode_tokens`; não é estoque.

**Fonte:** Origem `qrcode_tokens`; evento INSERT por linha; `NEW` contém token e acesso associados.
**Condição:** Cada inserção aciona a atualização do acesso correspondente; não procurar outro acesso por visitante, QR textual ou período.
**Alvos/regra de escrita:** Atualiza `acessos_visitantes` para que o token recém-inserido seja o token corrente do acesso. Não marca uso, invalida, recalcula validade nem toca estoque.
**Regra de negócio:** Manter no acesso um cache/estado corrente; o histórico continua nas linhas de tokens, não no campo derivado.
**Efeitos:** Uma atualização por token inserido na mesma transação. Se o acesso não existir ou a atualização atingir zero linhas, o export não define tratamento; falha/política precisa ser decidida no destino.
**Tenant — legado versus proposta segura:** O fluxo PHP procura o acesso com tenant, mas a inserção observada no gerenciador não carrega tenant e a regra por id não o demonstra. Para PG, derivar exclusivamente `NEW.tenant_id`, validar correspondência com o acesso e nunca usar fallback 1. É melhoria de segurança, não legado confirmado.
**Idempotência/concorrência:** Não idempotente em sentido de negócio: cada token novo substitui `token_atual`. Inserções concorrentes para o mesmo acesso podem deixar o último estado conforme ordenamento transacional, não necessariamente maior `criado_em`; não inventar ordenação.
**Narrativa PL/pgSQL:** Função narrativa AFTER INSERT: ler NEW, validar acesso/tenant e atualizar somente o acesso associado; manter atomicidade e não ampliar para UPDATE/DELETE. Sem SQL executável.
**Retorno BEFORE/AFTER:** AFTER ignora o retorno; retornar NEW é convenção e não substitui a linha inserida. OLD só existe em DELETE e não é usado aqui.
**Status live:** NAO CONFIRMADO — nenhum catálogo live individual.

---
## 6. `after_validacao_dispositivo_insert`

**Origem e evento:** `source_statement_id=S0131`; revisão privada `after_validacao_dispositivo_insert.json` (não versionada).
**Classificação/confiança:** Manutenção de projeção operacional/contador; `AFTER INSERT` em `logs_validacoes_dispositivo`; não é estoque.

**Fonte:** Cada log inserido fornece `NEW.dispositivo_id` e `NEW.validado_em` (este último depende do valor/default efetivo da coluna).
**Condição:** Não há WHEN/filtro por resultado: sucesso e falha incrementam igualmente, uma vez por linha inserida.
**Alvos/regra de escrita:** Atualiza em `dispositivos_tablets` a linha cujo id corresponde a `NEW.dispositivo_id`: copia `ultimo_acesso=NEW.validado_em` e incrementa `total_validacoes` em 1.
**Regra de negócio:** O log é evento; a tabela de dispositivos mantém projeção rápida do último horário e contador de logs processados, não contagem deduplicada de acessos autorizados.
**Efeitos:** Cada INSERT faz UPDATE; ausência de dispositivo e falha não têm política explícita no export. A falha pode abortar o INSERT conforme semântica transacional. Sem moradores, acessos, logs_sistema ou estoque.
**Tenant — legado versus proposta segura:** O trigger exportado atualiza por id sem tenant; o produtor também não inclui tenant. Na proposta PG, derivar e validar `NEW.tenant_id`, usar tenant+id quando o modelo efetivo o exigir e falhar fechado sem fallback 1. É melhoria deliberada de segurança, não legado confirmado.
**Idempotência/concorrência:** Não idempotente: duplicar/reprocessar log incrementa novamente. Em concorrência, incremento tende a serializar na linha, mas `ultimo_acesso` pode refletir a ordem do UPDATE, não o maior timestamp; preservar literalidade até decisão explícita.
**Narrativa PL/pgSQL:** Função AFTER INSERT lê NEW, valida dispositivo/tenant, atualiza projeção e incremento atômico; definir previamente erro para dispositivo ausente. Retorna NEW por convenção, ignorado em AFTER.
**Retorno BEFORE/AFTER:** AFTER ignora retorno; INSERT/UPDATE em BEFORE usariam NEW e DELETE usaria OLD. Não há OLD neste evento.
**Status live:** NAO CONFIRMADO — source-only.

---
## 7. `trg_alerta_estoque_baixo`

**Origem e evento:** `source_statement_id=S0191`; revisão privada `trg_alerta_estoque_baixo.json` (não versionada).
**Classificação/confiança:** Alto risco de compatibilidade/isolamento; `AFTER UPDATE` por linha em `produtos_estoque`; regra simples, mas com duplicidade potencial.

**Fonte:** Origem `produtos_estoque`; `AFTER UPDATE FOR EACH ROW`; usa valores finais de NEW.
**Condição:** Produto ativo com quantidade menor ou igual ao estoque mínimo. Deve distinguir semanticamente zero de mínimo atingido/ultrapassado. INSERT inicial e DELETE não são eventos cobertos.
**Alvos/regra de escrita:** Insere um alerta em `alertas_estoque` associado ao produto, carregando estado atual, unidade, quantidade e limite conforme contrato a validar. Não modifica quantidade nem cria movimentação.
**Regra de negócio:** Gerar alerta quando produto ativo estiver baixo; preservar possibilidade de alertas repetidos em updates qualificados, salvo decisão explícita de mudança.
**Efeitos:** Um INSERT de alerta por UPDATE qualificado; alterações não quantitativas podem repetir alerta. Múltiplos caminhos de API/O.S. atualizam estoque. Não recalcula estoque.
**Tenant — legado versus proposta segura:** A regra exportada não carrega tenant. A proposta segura deriva de `NEW.tenant_id` em UPDATE, valida destino `alertas_estoque` e falha fechado se tenant faltar; nunca usa fallback 1. O filtro é melhoria de segurança PG, não comportamento legado confirmado. Consultas adjacentes sem filtro tenant são risco separado e não foram alteradas.
**Idempotência/concorrência:** Não idempotente: sem chave/guard de deduplicação confirmado, cada UPDATE qualificado pode inserir novo alerta; não deduplicar nem mudar para transição sem decisão de compatibilidade.
**Narrativa PL/pgSQL:** Função narrativa AFTER UPDATE: verificar ativo/quantidade/mínimo, escolher categoria zero/mínimo, derivar tenant, inserir exatamente um alerta e retornar NEW por convenção. Confirmar tipos, obrigatoriedade, índices e constraints antes; sem SQL executável.
**Retorno BEFORE/AFTER:** AFTER ignora o retorno; NEW é convenção e não altera a linha. OLD só seria usado em DELETE de função genérica, não neste evento.
**Status live:** NAO CONFIRMADO — catálogo, tipos, constraints e instalação não obtidos.

---
