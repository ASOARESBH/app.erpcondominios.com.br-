# Fase 2.3 — Mapa de dependências das views

**Escopo:** exatamente 22 objetos candidatos recebidos como `view=true`. A classificação abaixo é proposta estática; o PostgreSQL é o destino já definido, mas não há PostgreSQL instalado nem catálogo live confirmado nesta tarefa.

## Limites, evidências e convenções

- Origem analisada: `ASOARESBH/app.erpcondominios.com.br-`; baseline de código: `f60739713e6de3f2473b79ecd14a70fd700e5462`; `source_summary.json` com SHA-256 `44505b1aab8c9a1085453ff3bf4d8a66519700810ac63fcd225693fdefec604a`.
- Foram usados somente `source_summary.json`, as revisões privadas individuais e os JSONs de entrada autorizados. A fonte de cada objeto é citada como **revisão privada `<objeto>.json` (não versionada)**; não são publicados caminhos absolutos privados.
- `TABLE_TYPE` live e `SHOW CREATE` individual permanecem desconhecidos porque consultas anteriores expiraram. Nenhum objeto é declarado instalado, ausente, VIEW, TABLE ou tenant-safe com base apenas no export.
- Para `v_emails_recentes` e `vw_movimentacoes_detalhadas`, há fontes SQL normais de definição: respectivamente `sql/database_recuperacao_senha.sql` e `sql/database_estoque.sql`. Essas referências são usadas somente como evidência estática já fornecida.
- `not_replayed`/`catalogued_not_replayed` significa apenas que o parser não reproduziu um `ALTER` no estado inicial derivado do `CREATE`; não significa que a migração não esteja instalada nem descreve o estado MySQL live atual.
- O grafo abaixo considera exclusivamente dependências diretas a tabelas ou views. Referências de manutenção, scripts, APIs, testes e consumidores não viram arestas. Nenhuma aresta candidato→candidato foi comprovada nas revisões privadas; isso não autoriza alegar ausência de ciclos no live.

## Resumo do grafo e da decisão

As 22 fichas abaixo não comprovam nenhuma dependência direta entre duas candidatas. Portanto, o grafo candidato→candidato publicado é vazio **por falta de evidência direta**, não por prova de que o catálogo live não tenha ciclos. As arestas estáticas publicáveis são somente candidato→tabela/base listadas individualmente. Objetos sem definição estática ficam explicitamente marcados e não recebem arestas inferidas.

**Decisão:** nenhuma candidata está aprovada para criação/recriação ou liberação tenant-safe. A ordem segura é: confirmar tipo/definição live; validar relações-base e tenant; reconciliar shape/tipos/nulabilidade; só então decidir portabilidade para PostgreSQL e validar consumidores.

---
## 1. `v_emails_recentes`

**Classificação proposta:** D View — proposta estática.
**Fonte da síntese:** revisão privada `v_emails_recentes.json` (não versionada).
A evidência estática trata o objeto como view de e-mails recentes, com associação opcional a moradores e ordenação/limite descritos na fonte. A natureza live continua desconhecida.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `destinatario` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `assunto` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `tipo` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `status` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `data_envio` — `timestamp`; nulabilidade `not_null`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `morador_nome` — `varchar(200)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `morador_unidade` — `varchar(50)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- email_log — origem de dados de e-mail e associação ao morador; `sql/database_recuperacao_senha.sql:198-212`.
- moradores — origem de nome e unidade; `sql/database_recuperacao_senha.sql:198-212`.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Referência nominal em rotina de integridade de IDs; não é consumidor nem dependência de view.

**Contrato estático e divergências:**
- Export estrutural registra CREATE TABLE e eventos não reaplicados pelo parser, enquanto fontes estáticas tratam como view; colunas convergem em oito nomes, mas tipo live desconhecido. A possibilidade de stand-in/placeholder é hipótese, não conclusão.

**Risco tenant:**
- Não há tenant_id no export nem predicado tenant demonstrado; risco alto/condicional de misturar `email_log`/`moradores` se forem tenantizados. Não classificar como global.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `v_emails_recentes.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 2. `view_dispositivos_ativos`

**Classificação proposta:** View — proposta estática; natureza instalada não confirmada.
**Fonte da síntese:** revisão privada `view_dispositivos_ativos.json` (não versionada).
A fonte estática trata o objeto como view de dispositivos ativos, calculando recência e agregando validações de logs. O export, porém, registra CREATE TABLE e onze colunas, enquanto a projeção estática tem dez.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `tenant_id` — `int(11)`; nulabilidade `not_null`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `nome` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `token` — `varchar(12)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `local` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `status` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `ultimo_acesso` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `total_validacoes` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `criado_em` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `minutos_desde_ultimo_uso` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
11. `validacoes_hoje` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- dispositivos_tablets — relação principal; `sql/create_dispositivos_tablets.sql:92`.
- logs_validacoes_dispositivo — agregação de validações; `sql/create_dispositivos_tablets.sql:93-94`.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Referências em migração multi-tenant e manutenção de IDs; não provam execução, tipo live ou filtro tenant.

**Contrato estático e divergências:**
- CREATE TABLE estrutural com 11 colunas/tenant_id versus view estática com 10 projeções; S0510 e S0742 constam como não reaplicados.

**Risco tenant:**
- O export traz `tenant_id` NOT NULL com default estrutural 1, mas a definição estática não o projeta nem filtra; há divergência de 11 colunas exportadas contra 10 projetadas. Presença no export não prova isolamento.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `não obtido`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `view_dispositivos_ativos.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 3. `view_estatisticas_dispositivos`

**Classificação proposta:** D View.
**Fonte da síntese:** revisão privada `view_estatisticas_dispositivos.json` (não versionada).
Há duas projeções estáticas divergentes para estatísticas de dispositivos; ambas agregam dispositivos e logs de validação. O export traz oito colunas e tenant_id, sem reconciliação live.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `tenant_id` — `int(11)`; nulabilidade `not_null`; default presente=true; AUTO_INCREMENT no CREATE=false
2. `total_dispositivos` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `dispositivos_ativos` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `dispositivos_inativos` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `total_validacoes_geral` — `decimal(32,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `validacoes_hoje` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `validacoes_sucesso_hoje` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `validacoes_falha_hoje` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- dispositivos_tablets — relação base agregada.
- logs_validacoes_dispositivo — métricas de validação.

**Consumidores/refs de manutenção:**
- `DispositivoTokenManager::obterEstatisticas` — leitura direta da relação; `api/dispositivo_token_manager.php:315-324`.
- `api_validar_token.php` — repasse da ação de estatísticas; `api/api_validar_token.php:230-239`.
- `frontend/dispositivos.html` — painel que consome totais/ativos/validações; `frontend/dispositivos.html:673-685`.
- Referências em scripts de migração; não são consumidores.

**Contrato estático e divergências:**
- Export de oito colunas versus duas projeções estáticas divergentes; export CREATE TABLE e fonte view.

**Risco tenant:**
- Export tem `tenant_id` NOT NULL, mas as projeções estáticas e o consumidor não demonstram filtro/agrupamento tenant; risco alto e contrato divergente.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `view_estatisticas_dispositivos.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 4. `view_estatisticas_tokens`

**Classificação proposta:** F Indeterminado — proposta estatica.
**Fonte da síntese:** revisão privada `view_estatisticas_tokens.json` (não versionada).
A definição estática é uma agregação de métricas de qrcode_tokens por estado e janelas temporais; o export apresenta oito colunas com tenant_id, enquanto a projeção estática apresenta sete.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `tenant_id` — `int(11)`; nulabilidade `not_null`; default presente=true; AUTO_INCREMENT no CREATE=false
2. `total_tokens` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `tokens_ativos` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `tokens_usados` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `tokens_expirados` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `tokens_usados_hoje` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `tokens_usados_semana` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `tokens_usados_mes` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- qrcode_tokens — fonte estática das métricas (`usado`, `expira_em`, `usado_em`).

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Referências de migração para tratar o nome; execução não confirmada.

**Contrato estático e divergências:**
- Export de oito colunas/tenant_id versus projeção estática de sete; migrações não provam execução.

**Risco tenant:**
- O export tem `tenant_id` com default estrutural 1, mas a agregação estática não filtra/agrupa tenant; hipótese de globalidade não é conclusão.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `view_estatisticas_tokens.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 5. `view_tokens_ativos`

**Classificação proposta:** F Indeterminado; natureza estática proposta=D View — proposta estatica; natureza e tipo live nao comprovados.
**Fonte da síntese:** revisão privada `view_tokens_ativos.json` (não versionada).
A fonte estática relaciona tokens, acessos de visitantes e visitantes, restringindo validade/uso e calculando horas restantes. O export inclui tenant_id e uma coluna a mais que a projeção estática.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `tenant_id` — `int(11)`; nulabilidade `not_null`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `token` — `varchar(64)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `acesso_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `expira_em` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `criado_em` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `qr_code` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `tipo_acesso` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `data_inicial` — `date`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `data_final` — `date`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
11. `visitante_nome` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
12. `visitante_documento` — `varchar(20)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
13. `horas_restantes` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- qrcode_tokens — token, acesso, expiração, criação e uso.
- acessos_visitantes — QR, tipo e janela de validade.
- visitantes — nome e documento.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Referências de migração/manutenção; não provam VIEW/TABLE live.

**Contrato estático e divergências:**
- Export de 13 colunas com tenant_id versus projeção estática de 12; não replayar alterações como prova live.

**Risco tenant:**
- A projeção estática não usa tenant; `tenant_id` aparece só no stand-in/export. Token, QR e documento elevam o risco de exposição cruzada.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `view_tokens_ativos.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 6. `vw_alertas_pendentes`

**Classificação proposta:** D View — proposta estatica.
**Fonte da síntese:** revisão privada `vw_alertas_pendentes.json` (não versionada).
A fonte estática associa alertas gerados, veículos, checklists e operadores, selecionando pendências e ordenando por geração. Não há escopo tenant demonstrado.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `veiculo_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `placa` — `varchar(8)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `veiculo_modelo` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `categoria` — `varchar(50)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `descricao` — `text`; nulabilidade `implicit_nullable`; default presente=false; AUTO_INCREMENT no CREATE=false
7. `km_atual` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `km_limite` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `km_excedido` — `bigint(12)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `data_geracao` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
11. `operador_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
12. `operador_nome` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- checklist_alertas_gerados — alertas-fonte.
- abastecimento_veiculos — dados do veículo.
- checklist_veicular — checklist/quilometragem.
- usuarios — operador.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Referência nominal de manutenção de IDs; não é consumidor.

**Contrato estático e divergências:**
- Fonte declara view; export é CREATE TABLE/stand-in sem tenant_id; eventos estruturais não reaplicados não são estado live.

**Risco tenant:**
- Sem `tenant_id` e sem predicado tenant na definição estática; se fontes forem compartilhadas, pode haver mistura. Não afirmar globalidade.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_alertas_pendentes.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 7. `vw_checklist_completo`

**Classificação proposta:** View — proposta_estatica.
**Fonte da síntese:** revisão privada `vw_checklist_completo.json` (não versionada).
A fonte estática projeta checklist, veículo e operador, com coluna derivada de quilometragem e ordenação decrescente pela abertura. O comportamento live não foi testado.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `veiculo_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `placa` — `varchar(8)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `veiculo_modelo` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `operador_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `operador_nome` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `km_inicial` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `km_final` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `km_percorrido` — `bigint(12)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `data_hora_abertura` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
11. `data_hora_fechamento` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
12. `status` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
13. `observacao_abertura` — `text`; nulabilidade `implicit_nullable`; default presente=false; AUTO_INCREMENT no CREATE=false
14. `observacao_fechamento` — `text`; nulabilidade `implicit_nullable`; default presente=false; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- checklist_veicular — checklist.
- abastecimento_veiculos — veículo.
- usuarios — operador.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Nenhum consumidor exato confirmado.

**Contrato estático e divergências:**
- Fonte declara view; export estrutural é CREATE TABLE. Ordenação decrescente por data de abertura é somente contrato estático.

**Risco tenant:**
- O contrato exportado não demonstra tenant; a ordenação é estática e o escopo depende das fontes/consumidor, não confirmado.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_checklist_completo.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 8. `vw_consumo_por_morador`

**Classificação proposta:** View — proposta estatica; natureza corroborada apenas por fonte SQL rastreada, nao por catalogo live.
**Fonte da síntese:** revisão privada `vw_consumo_por_morador.json` (não versionada).
A fonte estática agrupa retiradas de estoque por morador, associando moradores e movimentações e calculando totais/última retirada.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `morador_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
2. `morador_nome` — `varchar(200)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `morador_unidade` — `varchar(50)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `total_retiradas` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `quantidade_total` — `decimal(32,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `valor_total_consumido` — `decimal(32,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `ultima_retirada` — `timestamp`; nulabilidade `not_null`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- moradores — identificador, nome e unidade.
- movimentacoes_estoque — retiradas, quantidades, valores e data.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.

**Contrato estático e divergências:**
- Há contrato estático/exportado, mas o live não foi reconciliado; tipos e nulabilidade são candidatos, não garantias.
- Shape semântico estático: uma linha por morador; `total_retiradas`, `quantidade_total`, `valor_total_consumido` e `ultima_retirada` são agregados candidatos.

**Risco tenant:**
- Não há tenant_id no contrato exportado nem predicado demonstrado; agrupamento por morador pode misturar condomínios se fontes forem compartilhadas.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_consumo_por_morador.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 9. `vw_consumo_veiculos`

**Classificação proposta:** D View.
**Fonte da síntese:** revisão privada `vw_consumo_veiculos.json` (não versionada).
A fonte estática agrega lançamentos de abastecimento por veículo, com LEFT JOIN e métricas de abastecimentos, litros, gasto, preço médio e quilometragem atual; não demonstra tenant.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `placa` — `varchar(8)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `modelo` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `ano` — `int(4)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `total_abastecimentos` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `total_litros` — `decimal(32,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `total_gasto` — `decimal(32,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `preco_medio_litro` — `decimal(20,10)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `km_atual` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- abastecimento_veiculos — veículo.
- abastecimento_lancamentos — lançamentos agregados.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Referências de auditoria/manutenção/comparação; não são dependências diretas nem consumidores.

**Contrato estático e divergências:**
- Fonte view agregada, export CREATE TABLE sem tenant_id; agregados de LEFT JOIN podem ser nulos.
- Shape semântico: aparentemente uma linha por veículo; medidas `total_abastecimentos`, `total_litros`, `total_gasto`, `preco_medio_litro`, `km_atual`; LEFT JOIN pode produzir nulos.

**Risco tenant:**
- Export e definição não têm tenant_id/predicado; risco alto para dados de abastecimento compartilhados.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_consumo_veiculos.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 10. `vw_dashboard_financeiro`

**Classificação proposta:** D View.
**Fonte da síntese:** revisão privada `vw_dashboard_financeiro.json` (não versionada).
A fonte estática compõe indicadores mensais de contas, movimentações, recebíveis e pagáveis, dependentes do mês corrente. Não há tenant_id exportado nem filtro demonstrado.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `mes_referencia` — `varchar(7)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
2. `saldo_bancario_total` — `decimal(37,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `total_contas_ativas` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `entradas_mes` — `decimal(37,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `saidas_mes` — `decimal(37,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `pendentes_conciliacao` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `conciliados_total` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `a_receber_mes` — `decimal(34,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `a_pagar_mes` — `decimal(34,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `inadimplencia_total` — `decimal(34,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
11. `vencidas_a_pagar` — `decimal(34,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- contas_bancarias — saldo e contas ativas.
- movimentacoes_bancarias — entradas, saídas e conciliação.
- contas_receber — recebíveis/inadimplência.
- contas_pagar — pagáveis/vencidos.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Anotação/inventário da migration; nenhum consumidor runtime exato confirmado.

**Contrato estático e divergências:**
- Fonte view mensal, export CREATE TABLE sem tenant; funções temporais e janela de 12 meses exigem compatibilidade deliberada.
- Shape semântico: onze indicadores mensais; a janela temporal deriva da data corrente e não foi testada no destino.

**Risco tenant:**
- Sem tenant_id no export e sem filtro demonstrado; agregados financeiros são sensíveis e não devem ser tenant-safe por inferência.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_dashboard_financeiro.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 11. `vw_dependentes_ativos`

**Classificação proposta:** F Indeterminado — proposta estatica.
**Fonte da síntese:** revisão privada `vw_dependentes_ativos.json` (não versionada).
Não foi encontrada definição estática de view nem dependência de origem; há somente contrato estrutural exportado e referência nominal de manutenção. A natureza é indeterminada.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `morador_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `nome_completo` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `cpf` — `varchar(14)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `parentesco` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `data_nascimento` — `date`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `total_veiculos` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- Nenhuma tabela ou view de origem confirmada; não inferir pelo nome.

**Consumidores/refs de manutenção:**
- Nenhum consumidor funcional exato confirmado; referência nominal apenas em manutenção de integridade.
- Referência nominal em manutenção de IDs; não é uso funcional.

**Contrato estático e divergências:**
- Não há definição estática; export CREATE TABLE e alterações S0516/S0748 não replayadas.

**Risco tenant:**
- Sem tenant_id e sem definição; escopo totalmente desconhecido, não classificar como global.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_dependentes_ativos.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 12. `vw_dependentes_completo`

**Classificação proposta:** F Indeterminado — proposta_estatica.
**Fonte da síntese:** revisão privada `vw_dependentes_completo.json` (não versionada).
Não foi encontrada definição estática de view nem tabela-base confirmável. O export estrutural é o único contrato de colunas; referências de auditoria/manutenção não provam dependências runtime.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `morador_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `morador_nome` — `varchar(200)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `unidade` — `varchar(50)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `nome_completo` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `cpf` — `varchar(14)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `email` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `celular` — `varchar(20)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `data_nascimento` — `date`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `parentesco` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
11. `ativo` — `tinyint(1)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
12. `data_cadastro` — `timestamp`; nulabilidade `not_null`; default presente=true; AUTO_INCREMENT no CREATE=false
13. `data_atualizacao` — `timestamp`; nulabilidade `not_null`; default presente=true; AUTO_INCREMENT no CREATE=false
14. `total_veiculos` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
15. `observacao` — `text`; nulabilidade `implicit_nullable`; default presente=false; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- Nenhuma tabela ou view de origem confirmada; referências de auditoria/manutenção não são dependências diretas.

**Consumidores/refs de manutenção:**
- Nenhum consumidor funcional exato confirmado; referências de auditoria, comparação e manutenção não são consumidores.
- Auditoria de IDs, comparação e manutenção; não são dependências runtime.

**Contrato estático e divergências:**
- Não há definição de view; export CREATE TABLE com 15 colunas e S0517/S0749 não replayados.

**Risco tenant:**
- Sem tenant_id no export e sem corpo/definição; risco alto indeterminado, agravado por dados cadastrais pessoais.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_dependentes_completo.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 13. `vw_estatisticas_veiculo`

**Classificação proposta:** Indeterminado — proposta estatica.
**Fonte da síntese:** revisão privada `vw_estatisticas_veiculo.json` (não versionada).
A fonte estática propõe estatísticas por veículo, agregando checklists e alertas; a combinação de agregações pode multiplicar linhas e inflar quantitativos. O export registra CREATE TABLE.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `veiculo_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
2. `placa` — `varchar(8)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `modelo` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `total_checklists` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `checklists_abertos` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `checklists_fechados` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `ultimo_km_registrado` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `total_alertas` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `alertas_pendentes` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- abastecimento_veiculos — identificação do veículo.
- checklist_veicular — checklists e quilometragem.
- checklist_alertas_gerados — alertas por veículo.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Nenhuma manutenção/consumidor funcional exato confirmado no escopo.

**Contrato estático e divergências:**
- Fonte view, export CREATE TABLE sem tenant; duas agregações independentes podem inflar contagens.

**Risco tenant:**
- Sem tenant_id/filtro na proposta estática; agregações de veículo/checklist/alerta podem misturar tenants.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_estatisticas_veiculo.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 14. `vw_extrato_bancario`

**Classificação proposta:** F Indeterminado.
**Fonte da síntese:** revisão privada `vw_extrato_bancario.json` (não versionada).
Não há definição CREATE VIEW autorizada para este nome; não se pode inferir tabelas-base a partir de nomes de colunas. O export fornece apenas shape estrutural.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `bigint(20) unsigned`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `conta_id` — `int(10) unsigned`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `conta_nome` — `varchar(120)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `banco_nome` — `varchar(80)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `banco_codigo` — `varchar(10)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `data_lancamento` — `date`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `tipo` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `valor` — `decimal(15,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `valor_sinal` — `decimal(15,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `descricao` — `varchar(500)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
11. `favorecido` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
12. `checknum` — `varchar(30)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
13. `numero_documento` — `varchar(60)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
14. `categoria` — `varchar(80)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
15. `centro_custo` — `varchar(80)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
16. `status_conciliacao` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
17. `origem` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
18. `importacao_id` — `int(10) unsigned`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
19. `conta_receber_id` — `int(10) unsigned`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
20. `doc_receber` — `varchar(50)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
21. `nome_receber` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
22. `venc_receber` — `date`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
23. `status_receber` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
24. `conta_pagar_id` — `int(10) unsigned`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
25. `doc_pagar` — `varchar(50)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
26. `nome_pagar` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
27. `venc_pagar` — `date`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
28. `status_pagar` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
29. `data_importacao` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
30. `usuario_importacao` — `varchar(80)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
31. `criado_em` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- Nenhuma tabela ou view de origem confirmada; campos semânticos não provam dependência física.

**Consumidores/refs de manutenção:**
- Nenhum consumidor funcional exato confirmado; a única ocorrência autorizada é referência nominal de manutenção.

**Contrato estático e divergências:**
- Não há definição estática autorizada; nenhum vínculo de origem/contrato live pode ser inferido.
- Não existe definição estática autorizada que permita classificar campos semânticos como dependências físicas; não usar `conta_id`, `importacao_id`, `conta_receber_id` ou `conta_pagar_id` como arestas.

**Risco tenant:**
- Sem tenant_id e sem definição; campos bancários não permitem concluir escopo.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_extrato_bancario.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 15. `vw_fluxo_caixa`

**Classificação proposta:** D View — proposta estatica.
**Fonte da síntese:** revisão privada `vw_fluxo_caixa.json` (não versionada).
A fonte estática propõe fluxo mensal de doze meses, com realizados e previstos. O export registra CREATE TABLE e não há tenant_id.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `mes` — `varchar(7)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
2. `mes_label` — `varchar(37)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `entradas_realizadas` — `decimal(37,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `saidas_realizadas` — `decimal(37,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `a_receber_previsto` — `decimal(35,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `a_pagar_previsto` — `decimal(35,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- movimentacoes_bancarias — realizados.
- contas_receber — previstos a receber.
- contas_pagar — previstos a pagar.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Inventário/teste estático do nome; não confirma execução ou consumo live.

**Contrato estático e divergências:**
- Fonte view, export CREATE TABLE sem tenant; período e rótulo têm tipos exportados, não live.
- Shape semântico: seis colunas mensais (`mes`, `mes_label`, realizados e previstos), doze meses derivados da data corrente.

**Risco tenant:**
- Sem tenant_id/filtro demonstrado; risco alto se schema compartilhado.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_fluxo_caixa.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 16. `vw_movimentacoes_detalhadas`

**Classificação proposta:** View — proposta_estatica.
**Fonte da síntese:** revisão privada `vw_movimentacoes_detalhadas.json` (não versionada).
A fonte estática define uma view de movimentações de estoque com vinte colunas e junções opcionais a produto, categoria e morador; a ordenação é contrato estático.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `tipo_movimentacao` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `produto_codigo` — `varchar(50)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `produto_nome` — `varchar(200)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `categoria` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `quantidade` — `decimal(10,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `unidade_medida` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `quantidade_anterior` — `decimal(10,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `quantidade_posterior` — `decimal(10,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `tipo_destino` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
11. `morador_nome` — `varchar(200)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
12. `morador_unidade` — `varchar(50)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
13. `usuario_responsavel` — `varchar(200)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
14. `motivo` — `varchar(500)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
15. `nota_fiscal` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
16. `valor_unitario` — `decimal(10,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
17. `valor_total` — `decimal(10,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
18. `fornecedor` — `varchar(200)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
19. `data_movimentacao` — `timestamp`; nulabilidade `not_null`; default presente=true; AUTO_INCREMENT no CREATE=false
20. `observacoes` — `text`; nulabilidade `implicit_nullable`; default presente=false; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- movimentacoes_estoque — movimento principal.
- produtos_estoque — produto/código/unidade.
- categorias_estoque — categoria opcional.
- moradores — morador/unidade opcionais.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Referência nominal em manutenção de IDs; não é consumidor funcional.

**Contrato estático e divergências:**
- Fonte view de 20 colunas, export CREATE TABLE sem tenant; S0519/S0751 não replayados.

**Risco tenant:**
- Sem tenant_id no export e sem filtro demonstrado; expõe dados operacionais e pessoais.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_movimentacoes_detalhadas.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 17. `vw_pendencias_conciliacao`

**Classificação proposta:** View — proposta_estatica.
**Fonte da síntese:** revisão privada `vw_pendencias_conciliacao.json` (não versionada).
A fonte histórica propõe movimentações bancárias pendentes, associação de conta e contagem de candidatos a receber/pagar. O export e a fonte divergem quanto à natureza instalada.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `bigint(20) unsigned`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `conta_id` — `int(10) unsigned`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `conta_nome` — `varchar(120)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `banco_nome` — `varchar(80)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `data_lancamento` — `date`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `tipo` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `valor` — `decimal(15,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `descricao` — `varchar(500)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `favorecido` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `checknum` — `varchar(30)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
11. `numero_documento` — `varchar(60)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
12. `memo` — `text`; nulabilidade `implicit_nullable`; default presente=false; AUTO_INCREMENT no CREATE=false
13. `origem` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
14. `data_importacao` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
15. `criado_em` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
16. `candidatos_receber` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
17. `candidatos_pagar` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- movimentacoes_bancarias — pendências e campos principais.
- contas_bancarias — conta e banco.
- contas_receber — candidatos a receber.
- contas_pagar — candidatos a pagar.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Inventários de auditoria/comparação e manutenção; não são consumidores.

**Contrato estático e divergências:**
- Fonte histórica view de 17 colunas, export CREATE TABLE sem tenant; eventos de alteração catalogados como não reaplicados; não tratá-los como estado live.

**Risco tenant:**
- Sem tenant_id/filtro tenant histórico; pode expor movimentações e contas entre tenants.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_pendencias_conciliacao.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 18. `vw_produtos_estoque_baixo`

**Classificação proposta:** D View.
**Fonte da síntese:** revisão privada `vw_produtos_estoque_baixo.json` (não versionada).
A fonte estática propõe produtos ativos abaixo ou no mínimo de estoque, com déficit e valor de reposição derivados; não demonstra filtro tenant.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `codigo` — `varchar(50)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `nome` — `varchar(200)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `categoria` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `quantidade_estoque` — `decimal(10,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `estoque_minimo` — `decimal(10,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `unidade_medida` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `deficit` — `decimal(11,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `preco_unitario` — `decimal(10,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `valor_reposicao` — `decimal(21,4)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- produtos_estoque — produto, quantidades, mínimo, ativo e preço.
- categorias_estoque — categoria via junção opcional.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Referência nominal em manutenção de IDs; não é consumidor funcional.

**Contrato estático e divergências:**
- Fonte view, export CREATE TABLE sem tenant; derivação de déficit/reposição depende de tipos/nulos live.

**Risco tenant:**
- Sem tenant_id/filtro demonstrado; risco de mistura de estoque e categorias.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_produtos_estoque_baixo.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 19. `vw_saldo_contas`

**Classificação proposta:** F Indeterminado.
**Fonte da síntese:** revisão privada `vw_saldo_contas.json` (não versionada).
Há definições estáticas versionadas de saldo agregado por conta e um consumidor PHP com SELECT *; o export tem quatorze colunas, divergentes do shape observado nas definições.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(10) unsigned`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `nome` — `varchar(120)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `banco_nome` — `varchar(80)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `banco_codigo` — `varchar(10)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `agencia` — `varchar(20)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `conta_numero` — `varchar(30)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `conta_tipo` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `moeda` — `char(3)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `saldo_inicial` — `decimal(15,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `total_creditos` — `decimal(37,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
11. `total_debitos` — `decimal(37,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
12. `saldo_calculado` — `decimal(39,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
13. `saldo_atual` — `decimal(15,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
14. `ativo` — `tinyint(1)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- contas_bancarias — identidade, dados bancários, saldo inicial e atividade.
- movimentacoes_bancarias — conta, tipo e valor.

**Consumidores/refs de manutenção:**
- `api/api_contas_bancarias.php::_saldo_contas` — SELECT * por nome, ordena por nome e converte `saldo_calculado`, `total_creditos`, `total_debitos`; `api/api_contas_bancarias.php:683-692`.
- A própria definição versionada e migration são evidências estáticas, não confirmação de instalação.

**Contrato estático e divergências:**
- Definições estáticas view versus export CREATE TABLE de 14 colunas; API SELECT * depende do shape instalado.

**Risco tenant:**
- Sem tenant_id nas definições/export; consumidor SELECT * pode receber saldo de outro tenant.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_saldo_contas.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 20. `vw_ultimos_abastecimentos`

**Classificação proposta:** D View — proposta_estatica.
**Fonte da síntese:** revisão privada `vw_ultimos_abastecimentos.json` (não versionada).
A fonte estática propõe os últimos abastecimentos, relacionando lançamentos, veículos e usuários, com ordenação/limite. Nenhum consumidor exato foi confirmado.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `id` — `int(11)`; nulabilidade `not_null`; default presente=false; AUTO_INCREMENT no CREATE=false
2. `data_abastecimento` — `datetime`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `placa` — `varchar(8)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `modelo` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `km_abastecimento` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `litros` — `decimal(10,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `valor` — `decimal(10,2)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
8. `tipo_combustivel` — `enum (labels não capturados neste catálogo parcial)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
9. `operador` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
10. `usuario_logado` — `varchar(100)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- abastecimento_lancamentos — dados do abastecimento.
- abastecimento_veiculos — placa/modelo.
- usuarios — operador.

**Consumidores/refs de manutenção:**
- Nenhum consumidor PHP/JS/HTML exato confirmado.

**Contrato estático e divergências:**
- Fonte view de 10 colunas, export CREATE TABLE sem tenant; ordenação/limite são estáticos.

**Risco tenant:**
- Sem tenant_id/predicado; risco de mistura entre tenants se bases compartilhadas.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_ultimos_abastecimentos.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 21. `vw_validacao_dependente_visitante`

**Classificação proposta:** Indeterminado — proposta estatica; live nao confirmado.
**Fonte da síntese:** revisão privada `vw_validacao_dependente_visitante.json` (não versionada).
Não foi encontrada definição estática nem dependência direta confirmada. As colunas sugerem uma relação dependente/visitante, mas isso é hipótese e não base de grafo.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `dependente_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
2. `morador_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `nome_completo` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `cpf` — `varchar(14)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
5. `visitante_id` — `int(11)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
6. `visitante_nome` — `varchar(255)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
7. `status` — `varchar(8)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- Nenhuma tabela ou view de origem confirmada; as relações sugeridas pelas colunas são apenas hipóteses.

**Consumidores/refs de manutenção:**
- Nenhum consumidor exato confirmado.

**Contrato estático e divergências:**
- Nome view-like versus export CREATE TABLE; sem definição, dependências e contrato live desconhecidos.
- As colunas sugerem dependente/morador/visitante, mas a revisão marca todas as relações como inferidas ou hipotéticas; não são dependências comprovadas.

**Risco tenant:**
- Sem tenant_id e sem definição; escopo e risco de exposição de CPF/visitante indeterminados.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_validacao_dependente_visitante.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
## 22. `vw_valor_total_estoque`

**Classificação proposta:** D View — proposta estática; escopo tenant não comprovado.
**Fonte da síntese:** revisão privada `vw_valor_total_estoque.json` (não versionada).
A fonte estática agrega produtos_estoque em quatro métricas de valor/quantidade e estoque baixo/zerado; não demonstra tenant.

**Colunas exportadas (contrato estrutural candidato; não é contrato live):**
1. `valor_total` — `decimal(42,4)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
2. `total_produtos` — `bigint(21)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
3. `produtos_baixo_estoque` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false
4. `produtos_zerados` — `decimal(23,0)`; nulabilidade `implicit_nullable`; default presente=true; AUTO_INCREMENT no CREATE=false

**Dependências diretas comprovadas estaticamente (somente tabelas/views):**
- produtos_estoque — fonte única das quatro métricas; `sql/database_estoque.sql:172-177`.

**Consumidores/refs de manutenção:**
- Nenhum consumidor runtime exato confirmado na revisão autorizada.
- Nenhum consumidor PHP/SQL adicional exato confirmado.

**Contrato estático e divergências:**
- Fonte view de quatro métricas versus export CREATE TABLE sem tenant; agregações/nulabilidade não validadas.

**Risco tenant:**
- Sem tenant_id/predicado; agregados podem misturar estoque de tenants.

**Status live:** `NAO CONFIRMADO` — TABLE_TYPE live `NAO CONFIRMADO`; SHOW CREATE individual `NAO OBTIDO`. Consultas anteriores expiraram; não houve confirmação de instalação, corpo, permissões ou estado PostgreSQL.

**Evidência operacional e próximo gate:**
- revisão privada `vw_valor_total_estoque.json` (não versionada) e `source_summary.json` sustentam somente análise estática/export; confirmar individualmente o catálogo antes de qualquer DDL ou consumo PostgreSQL.

---
