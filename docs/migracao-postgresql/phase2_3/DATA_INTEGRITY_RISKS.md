# Fase 2.3 — Integridade dos dados e riscos de conversão

**Origem:** MySQL/Percona 5.7 do HostGator. **Destino definido:** PostgreSQL, ainda sem banco instalado nesta fase. **Gate 2:** fechado/não aprovado. Nenhuma consulta de qualidade foi executada; não há evidência de dano ou vazamento live.

## 1. Evidências e limites

A análise usa o export estrutural autorizado do anexo (7), o catálogo parcial e as revisões estáticas do código no baseline `f60739713e6de3f2473b79ecd14a70fd700e5462`, exclusivamente do repositório `ASOARESBH/app.erpcondominios.com.br-`. Os catálogos privados e revisões não são dados de registros e não foram incorporados ao Git como dump.

`not_replayed` significa que o catalogador **não reproduziu os ALTERs sobre o estado inicial das declarações CREATE**. Isso não significa que migrations não foram aplicadas no HostGator. O estado instalado MySQL permanece **não confirmado**; nenhum `SHOW CREATE` individual foi obtido. Não existe catálogo PostgreSQL live para validar nesta fase.

Não foram incluídos valores pessoais, amostras, senhas, tokens, `DEFINER`, corpos DDL ou defaults literais. Os nomes de tabelas e colunas são metadados.

## 2. Cobertura estrutural

| Métrica do export | Quantidade | Limite |
|---|---:|---|
| Declarações CREATE TABLE | 270 | Não prova tipo/estado instalado. |
| Estruturas com/sem tenant_id | 228 / 42 | Escopo não é inferido automaticamente. |
| Eventos ALTER TABLE | 489 | Não reproduzidos pelo catalogador. |
| MODIFY com AUTO_INCREMENT | 232 | Não é a contagem lexical de referências. |
| FKs explícitas | 11 | Instalação, enforcement e dados não confirmados. |
| Candidatos view-like | 22 | Todos aparecem como CREATE TABLE no export. |
| Triggers | 7 | Regras exportadas, instalação não confirmada. |
| Colunas CREATE mapeadas | 3.339 | Tipos finais podem diferir após MODIFY. |
| tenant_id nullable/default legado 1 | 7 / 161 | Não prova entidade global ou tenant correto. |
| JSON / ENUM | 12 / 228 | Metadados do CREATE inicial. |
| Colunas temporais / temporais nullable | 544 / 169 | Não há perfil agregado de valores. |

Tabelas de backup, histórico e apoio devem ser preservadas no inventário. Seu nome não autoriza exclusão ou prova obsolescência.

## 3. Relações explícitas a validar

| Filho.campo | Pai.campo | Tenant nas duas pontas? | Observação |
|---|---|---|---|
| aplicativos_versoes.aplicativo_id | aplicativos_catalogo.id | Não | Catálogo global proposto. |
| dashboard_empresa_widgets.widget_key | dashboard_widgets_catalogo.widget_key | Não | Validar escopo do pai. |
| dashboard_usuario_widgets.widget_key | dashboard_widgets_catalogo.widget_key | Não | Validar escopo do pai. |
| moradores_anexos.morador_id | moradores.id | Sim | Contar órfãos e mismatch separadamente. |
| rbac_auditoria.sessao_id | rbac_sessoes.id | Sim | FK nullable no CREATE inicial. |
| rbac_grupo_permissoes.grupo_id | rbac_grupos.id | Não | Tenant transitivo, sem preenchimento automático. |
| rbac_grupo_permissoes.permissao_id | rbac_permissoes.id | Não | Não presumir FK tenant composta. |
| rbac_modulos.modulo_pai_id | rbac_modulos.id | Não | Auto-relação nullable. |
| rbac_permissoes.modulo_id | rbac_modulos.id | Não | Validar chave pai. |
| rbac_usuario_grupos.grupo_id | rbac_grupos.id | Sim | Contar mismatch somente após validar escopo. |
| rbac_usuario_permissoes.permissao_id | rbac_permissoes.id | Não | Pai global proposto. |

As 25 relações lógicas adicionais constam em `FOREIGN_KEYS_AND_RELATIONSHIPS.csv` com evidência de JOIN estático. Não equivalem a FKs instaladas nem autorizam criação de constraint. O conjunto não representa prova de todas as relações da aplicação.

## 4. Riscos e testes agregados propostos

| Risco | Teste/revisão | Pré-condição |
|---|---|---|
| PK ausente | Contagem de constraints PRIMARY KEY no catálogo MySQL, uma tabela por vez. | Objeto BASE TABLE confirmado. Ausência no CREATE inicial não prova ausência instalada. |
| UNIQUE/duplicidade | Contar grupos e excesso de linhas em subquery. | Colunas/ordem/tenant/collation e prefixos aprovados. UNIQUE normal permite múltiplos NULLs; excluir chave com qualquer componente NULL. PK exige contagem própria de NULL. |
| Índices duplicados/redundantes | Comparação estática de ordem, prefixo, tipo, unicidade e direções. | Candidatos devem ser reconciliados com STATISTICS live. Mesmo prefixo não autoriza remoção. |
| Órfãos | COUNT com NOT EXISTS, sem projetar chave. | Relação comprovada, tipos e índice/plano confirmados. FK NULL não é órfã. |
| Tenant inconsistente | COUNT de filhos com EXISTS de pai de tenant diferente. | Nas três FKs com tenant nas duas pontas; confirmar unicidade pai e escopo. NULL tenant é teste separado, não global/fallback 1. |
| Tipos de identificadores diferentes | Comparação agregada de COLUMN_TYPE, DATA_TYPE, charset/collation dos dois campos. | Relacionamento comprovado, sem coerção implícita de dados. Diferença de largura de exibição não é necessariamente incompatibilidade. |
| NULL vs futura NOT NULL | COUNT total/não nulos/nulos. | Constraint futura aprovada; não corrigir dados automaticamente. |
| Zero-date/temporais | Contagem de datas completas/parciais zero usando representação textual MySQL. | Somente DATE/DATETIME/TIMESTAMP confirmados. TIME pode representar duração superior a 24h; decidir domínio. |
| JSON/TEXT | JSON_VALID com contagem de nulos/inválidos. | MySQL 5.7 confirmado; payload nunca retornado. JSON MySQL válido não garante representabilidade numérica/Unicode em jsonb. |
| UNSIGNED/faixas | Contagem numérica fora dos limites aprovados do tipo destino. | Coluna numérica confirmada; nunca converter texto implicitamente ou usar amostras. |
| ENUM/boolean/collation | Metadados permitidos e testes de contrato com dados sintéticos. | Labels completos, semântica da aplicação e regras de comparação aprovadas. |
| Views/triggers/históricos | Revisão individual de definição, dependências e regra. | Não assumir tipo por nome nem descartar objetos por sufixo. |

Não há FK composta explícita no export. O template composto só é aplicável se uma for comprovada posteriormente e a regra for `MATCH SIMPLE`: qualquer componente NULL dispensa o teste de existência. A regra MySQL e a futura PostgreSQL devem ser validadas, não transportadas por convenção.

## 5. Consultas de revisão

[`QUALITY_QUERIES_REVIEW_ONLY.sql`](QUALITY_QUERIES_REVIEW_ONLY.sql) contém **templates MySQL/Percona 5.7**, não migrations. Inclui catálogo, PK, tipos, índices, grants visíveis, as 11 FKs, três casos de mismatch, duplicidade, NULL, datas zero, JSON e faixa numérica. Todos os resultados externos são contagens agregadas. Identidades usadas internamente em GROUP BY/JOIN nunca são retornadas.

Os placeholders tornam o arquivo inadequado para execução direta. Identificadores não podem ser parâmetros preparados: devem ser resolvidos por allowlist/quoting em revisão controlada. Não enviar estes templates ao phpMyAdmin como lote.

A triagem de grants no catálogo visível **não prova** o privilégio efetivo. Ausência de linhas pode refletir visibilidade insuficiente. Validar a conta autenticada e seus grants por procedimento administrativo autorizado sem incorporar credenciais ou informação identificável ao relatório.

Uma bateria pós-carga PostgreSQL será planejada separadamente quando houver ambiente de QA aprovado. Não confundir checagem futura de `pg_catalog`/RLS/índices válidos com coleta atual da origem MySQL.

## 6. Custo, janela e abortar

Antes de consultar dados: confirmar schema, TABLE_TYPE, campos, tipos, nullabilidade, índices de junção e permissões; revisar cardinalidades/estatísticas já autorizadas; definir janela somente leitura, observabilidade e limite de tempo no cliente aprovado. Estatísticas estimadas não substituem contagens exatas. Uma consulta deve tratar apenas uma tabela ou relação por vez; `LIMIT` não protege um COUNT de scan completo.

Nenhum EXPLAIN foi executado. A revisão estática/offline pode usar um plano previamente obtido por procedimento autorizado. Não se inventa um plano real sem acesso; EXPLAIN simples na origem exigiria etapa autorizada separada, sem ANALYZE ou coleta de registros.

Abortar diante de tipo/objeto divergente, grants desconhecidos, consulta demasiado pesada, cast/coerção não aprovada, relação não comprovada, escopo tenant indefinido, erro de sessão ou necessidade de retornar dados individuais. Não repetir consulta após timeout sem verificar seu estado.

## 7. Critério de conclusão

Sem catálogo MySQL reconciliado, políticas de tipos/tenant e qualidade agregada aprovada, não impor FKs, UNIQUE, NOT NULL, casts ou preenchimentos. As consultas são apenas preparação. Esta entrega **não aprova o Gate 2**, não prepara importação definitiva e não confirma integridade dos registros de produção.
