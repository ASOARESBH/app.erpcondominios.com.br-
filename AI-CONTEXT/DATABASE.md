# Banco de Dados

Banco de dados relacional MySQL hospedado na HostGator.

## 1. Tabelas Principais (Core)
- `moradores`, `dependentes`, `unidades`, `veiculos`, `visitantes`
- `usuarios`, `sessoes_portal`

## 2. Tabelas Financeiras
- `contas_pagar`, `contas_receber`, `movimentacoes_bancarias`
- `conciliacoes`, `historico_importacoes_ofx`

## 3. Tabelas de Operação
- `hidrometros`, `leituras`, `lancamentos_agua`
- `ordens_servico`, `inventario`, `produtos_servicos`

## 4. Tabelas de Integração & Sistema
- `controlid_dispositivos`, `controlid_eventos_acesso`
- `configuracoes`, `logs_sistema`, `email_log`

## Regras Críticas de Banco
- **Soft Delete**: Registros raramente são deletados fisicamente (`DELETE`). Utiliza-se `ativo = 0` ou `status = 'INATIVO'`.
- **Chaves Estrangeiras**: Integridade mantida via código PHP na maioria dos módulos legacy, mas migrando para Constraints nativas do MySQL nas tabelas novas.
- **Timestamps**: Tabelas padronizadas com `criado_em` e `atualizado_em`.


## 5. Snapshots de Inadimplência

As tabelas `inadimplencia_importacoes` e `inadimplencia_lancamentos` preservam snapshots de PDFs BRCondos por `tenant_id`. Todas as leituras devem filtrar `tenant_id`, inclusive quando `importacao_id`, Gleba, CPF ou chave de comparação forem conhecidos. O PDF original é mantido no BLOB de `tenant_arquivos`, por meio de `tenant_arquivo_referencias`, e não deve ser duplicado em diretórios públicos.

`inadimplencia_importacoes.fonte_sistema`, `layout_id` e `layout_versao` identificam o catálogo selecionado no momento do upload. Os defaults são o parser BRCondos homologado, garantindo compatibilidade com snapshots antigos. Layouts planejados não geram snapshot até que um adaptador seja homologado com amostra real.

A migration compatível com MySQL/MariaDB 5.7 é `sql/migration_inadimplencia_mysql57.sql`. As chaves `chave_comparacao` e `chave_alternativa` são evidências para comparar snapshots; não substituem títulos ou baixas do financeiro operacional.

## 6. Rondas de Vigilante

O módulo de rondas é instalado por `sql/migration_rondas_vigilante_mysql57.sql`, compatível com MySQL/MariaDB 5.7. Todas as tabelas abaixo possuem `tenant_id` e devem ser consultadas com o tenant resolvido no backend pela sessão.

| Tabela | Finalidade | Regras/índices relevantes |
|---|---|---|
| `ronda_rotas` | Agenda de uma rota de ronda | Define horas, intervalo, tolerância, repetições e dias da semana. |
| `ronda_pontos` | Pontos físicos da rota | Possui `token_qr` hexadecimal aleatório, ponto ativo e ordem. O token é segredo operacional e não é devolvido pela API móvel. |
| `ronda_vigilantes` | Vínculo N:N entre rota e `rh_colaboradores` | Só `ativo=1` autoriza a leitura de rota. |
| `ronda_registros` | Leituras efetivamente registradas | Armazena ponto, vigilante, SLA, atraso, GPS opcional e auditoria técnica. O índice `uk_ronda_registro_ponto_ciclo (tenant_id, ponto_id, colaborador_id, ciclo_chave)` impede duplicidade. |
| `ronda_auditoria` | Rastreabilidade administrativa | Registra ação, usuário interno, descrição, dados auxiliares e IP. |

A API móvel calcula `ciclo_chave` como `sha256(tenant_id|rota_id|colaborador_id|YYYYMMDD:indice_do_ciclo)`. O índice de `ronda_registros` inclui também o ponto, de modo que a mesma pessoa pode cumprir pontos distintos da rota no mesmo ciclo, mas não repetir o mesmo ponto. Leituras móveis gravadas nessa tabela já são consumidas pelos dashboards e relatórios administrativos existentes.

## 7. Registros de Acesso

A tabela `registros_acesso` registra os eventos manuais e automáticos de entrada e saída, sempre isolados por `tenant_id`. No lançamento manual, `modo_registro` é `VEICULO` por padrão ou `PEDESTRE` para acessos sem veículo; `vestimenta` (`VARCHAR(120)`, nula) guarda a descrição opcional da roupa da pessoa. A coluna `usuario_liberou` (`VARCHAR(150)`, nula) preserva o nome do operador autenticado que efetuou uma liberação manual, de forma independente do cadastro atual desse usuário. A coluna opcional `idempotency_key` (`VARCHAR(36)`, nula) recebe a chave do formulário, com índice único `uk_registros_acesso_tenant_idempotency (tenant_id, idempotency_key)`; como a coluna aceita `NULL`, registros antigos e origens que não enviam chave não colidem. Eventos automáticos de hardware não preenchem `usuario_liberou`, e registros históricos anteriores à adoção dos campos permanecem com valores nulos. Registros `PEDESTRE` não exigem placa, modelo ou cor e não geram registros de ocupantes.

## 8. Escopo de Ordens de Serviço

`os_usuarios_config` guarda uma configuração por `(tenant_id, usuario_id)`, incluindo
`restritivo_os`. `os_permissoes_departamento` guarda as ações `pode_visualizar`,
`pode_criar`, `pode_editar` e `pode_excluir` por `(tenant_id, usuario_id, departamento)`.
As duas tabelas são criadas/verificadas de forma idempotente pela API de O.S. e nunca
aceitam `tenant_id` vindo do navegador.

A tabela central `departamentos` deve possuir `tenant_id` e a chave única
`(tenant_id, nome)`. A API corrige a chave histórica global `uk_nome` antes de listar ou
salvar a matriz, permitindo que condomínios diferentes usem departamentos com o mesmo
nome. O escopo aplicado às consultas de `os_chamados` combina autoria própria, atribuição
como atendente e assunção no Portal com os departamentos autorizados; a restritividade
reduz o conjunto a esses vínculos pessoais. A unidade `morador_unidade` é o snapshot da O.S.
e o índice composto `idx_os_tenant_unidade (tenant_id, morador_unidade)` atende à listagem
por unidade. A migration idempotente MySQL/MariaDB 5.7 é
`sql/migration_os_indice_unidade_mysql57.sql`; a API também garante o índice no bootstrap.
O diagnóstico de divergências com a unidade atual do morador fica em
`sql/diagnostico_os_unidade_divergente.sql` e é somente leitura.

## 9. URL pública de Projetos

`tenants.projetos_slug` guarda o identificador opcional usado nas rotas públicas
`/projetos/{slug}` e `/projetos/{slug}/{id}`. A coluna é `VARCHAR(60)` com índice
único e aceita `NULL`, permitindo que tenants sem configuração mantenham a rota
legada `/projetos`. A migration canônica é
`sql/migration_projetos_slug_tenant_mysql57.sql`; o backend nunca aceita
`tenant_id` do navegador e resolve o tenant exclusivamente pelo slug cadastrado.


## 10. Configuração de Moradores e Dependentes

`config_moradores_campos` guarda a obrigatoriedade dos campos dos grupos `moradores` e `dependentes` por `(tenant_id, grupo, campo)`. `config_moradores_opcoes` guarda regras operacionais por `(tenant_id, opcao)`, incluindo `dependente_multiplos_veiculos`. Ambas as tabelas preservam o usuário que alterou a regra e são criadas pela migration `sql/migration_config_cadastro_moradores_mysql57.sql`. O backend resolve o tenant pela sessão; nunca usa `tenant_id` enviado pelo navegador.

## 11. Abastecimento

`abastecimento_lancamentos.observacao` é um campo `TEXT NULL` para informações adicionais do abastecimento. O conteúdo é opcional, preserva registros antigos com `NULL`, pertence ao mesmo `tenant_id` do lançamento e é exibido no histórico, relatório HTML/CSV e PDF. A coluna é criada pela migration idempotente `sql/migration_observacao_abastecimento_mysql57.sql`.

## 11. Integridade da tabela de Usuários

`usuarios.id` é a chave primária técnica e deve ser `INT NOT NULL AUTO_INCREMENT`. A migration `sql/migration_corrigir_ids_usuarios_mysql57.sql` repara registros com `id <= 0` e IDs positivos duplicados somente quando o e-mail é único, recria os vínculos tenant-aware dos usuários reparados e cria backups auxiliares antes da alteração. Para a base da Associação, onde os registros inválidos devem iniciar no ID 15, a migration específica é `sql/migration_corrigir_ids_usuarios_a_partir_15_mysql57.sql`; ela atribui IDs sequenciais por nome/e-mail, sem reutilizar IDs ocupados. Os scripts abortam diante de e-mails vazios/duplicados, destinos ocupados, vínculos positivos ambíguos ou backups já existentes; referências históricas com `usuario_id=0` não são atribuídas automaticamente a uma pessoa, para evitar autoria incorreta.


## 12. Lista de Convidados

A tabela `convidados` armazena convites do painel, tenant-aware, com anfitrião `MORADOR`/`DEPENDENTE`, visitante vinculado opcional, documento normalizado, validade, limite e contador de entradas, status, autoria, cancelamento e chave de idempotência. Os índices começam por `tenant_id`; a unicidade da chave é `(tenant_id, idempotency_key)`. `registros_acesso.convidado_id` vincula cada movimento Entrada/Saída ao convite no mesmo tenant e é indexado junto com `tenant_id`. A migration MySQL/MariaDB 5.7 é `sql/migration_lista_convidados_mysql57.sql`.

O schema base `sql/database.sql` e a migration ampliam `registros_acesso.tipo` para manter `Entregador Autorizado` e `Familiar` como categorias próprias, sem convertê-las em Visitante/Prestador. A migration preserva valores existentes em bases legadas. Relatórios e Portal do Morador devem filtrar esses valores sob o tenant da sessão.

`convidados.origem_cadastro` inclui `APP_MORADOR` (pré-cadastro pelo aplicativo) além de `PORTAL_MORADOR`, via `sql/migration_convidados_pre_cadastro_morador_mysql57.sql` (idempotente, preserva os valores do ENUM). Pré-cadastros do morador gravam `cadastrado_por_tipo='MORADOR'`, `cadastrado_por_morador_id` e `cadastrado_por_usuario_id=NULL`; atualizações e cancelamentos feitos pelo morador gravam `atualizado_por_tipo='MORADOR'`. Índice `idx_conv_tenant_autor_morador (tenant_id, cadastrado_por_morador_id, status)` atende o limite de ativos e a listagem do portal.

## 13. Alertas da Unidade

A migration `sql/migration_alertas_unidade_mysql57.sql` cria as tabelas `alertas_unidade`, `alertas_unidade_alvos`, `alertas_unidade_acionamentos`, `alertas_unidade_ciencia_tokens`, `alertas_unidade_ciencias`, `alertas_unidade_historico`, `alertas_unidade_rate_limits`, `alertas_unidade_notificacoes`, `alertas_unidade_lembretes`, `alertas_unidade_preferencias` e `alertas_unidade_cache_ids`. Todas são tenant-aware e têm índices iniciados por `tenant_id`. `alertas_unidade_ciencias` e `alertas_unidade_historico` são append-only pela API; `alertas_unidade_acionamentos` também registra decisões automáticas (`APENAS_AVISAR`, `CIENCIA_PENDENTE`, `BLOQUEADO_AUTOMATICO`) sem atribuí-las a uma ciência humana.

A migration garante `sessoes_portal.tenant_id` quando ausente e, se `notificacoes_morador` existir, acrescenta referências opcionais a `alerta_unidade_id` e `registro_acesso_id`. `alertas_unidade_notificacoes` aplica deduplicação por tenant, alerta, morador e janela de 10 minutos; `alertas_unidade_lembretes` deduplica por tenant, alerta e versão de vigência final. `alertas_unidade_preferencias` guarda `push_ativo` por `(tenant_id,morador_id)`; sem registro, a preferência padrão é habilitada. `alertas_unidade_cache_ids` guarda somente IDs ativos e timestamp por `(tenant_id,unidade)` para cache efêmero de preflight. A rotina cron atualiza `ATIVO → EXPIRADO` e cria inbox/push cerca de 24 horas antes do vencimento, respeitando o controle individual para push sem suprimir o inbox. Não reutilizar tabelas `alertas_acesso_*`, que pertencem ao domínio distinto de alertas administrativos pós-evento.

## 14. RH — Banco de Horas

`rh_banco_horas` é o ledger de créditos/débitos manuais e automáticos. Leituras e mutações sempre incluem `tenant_id`; o colaborador precisa pertencer ao mesmo tenant. O índice composto `idx_rh_bh_tenant_colab_data (tenant_id, colaborador_id, data)` atende extratos/saldos e `idx_rh_bh_lancamento` localiza sincronizações automáticas. `uq_rh_bh_tenant_idempotency (tenant_id, idempotency_key)` impede retries duplicados sem cruzar tenants.

O ledger mantém `tipo` na whitelist `credito`, `debito`, `abatimento`, `pagamento`; minutos são positivos e o sinal é definido pelo helper `api/helpers/rh_banco_horas_helper.php`. Auditoria manual usa `saldo_anterior_min`, `saldo_posterior_min`, `saldo_negativo_confirmado`, `justificativa_saldo_negativo`, `usuario_id` e `ip`; `lancamento_id IS NULL` identifica operação manual e valor não nulo identifica vínculo automático ao ponto. `tipo=''` legado é apenas diagnosticado pela migration, não corrigido automaticamente.

`rh_escala.limite_saldo_negativo_min` é inteiro opcional em minutos; `NULL` significa sem limite. A migration idempotente MySQL/MariaDB 5.7 `sql/migration_rh_banco_horas_saldo_negativo_mysql57.sql` backfill o tenant do ledger pela relação com `rh_colaboradores`, aborta se houver linha órfã e acrescenta campos/índices de auditoria/idempotência. A migration não consulta `INFORMATION_SCHEMA`, para funcionar com usuários de hospedagem sem privilégio de leitura global; idempotência de DDL é controlada por handlers para códigos específicos de coluna/índice duplicados. Fazer backup e validar o relatório de diagnóstico antes de qualquer aplicação em produção.

`rh_escala.politica_extra_excedente` aceita `CREDITAR_TUDO` (padrão) ou `LIMITAR_E_SEPARAR`; `limite_extra_diario_min` tem padrão 120 e serve de alerta diário/teto de crédito apenas na segunda política. `rh_banco_horas` recebe `minutos_apurados`, `limite_aplicado_min`, `regra_aplicada`, `versao_calculo` e `reprocessamento_id`; `tipo='excedente'` não compõe saldo. `rh_banco_horas_reprocessamentos` guarda tenant/usuário/nome, escopo e filtros, hashes, regra/versão, motivo, totais antes/depois, dias divergentes, ignorados, status, IP, horário e se períodos fechados foram explicitamente incluídos.

A migration `sql/migration_rh_banco_horas_mapeamento_mysql57.sql` pressupõe a migration tenant do ledger já aplicada, usa DDL idempotente sem `INFORMATION_SCHEMA` e não reprocessa nem altera automaticamente lançamentos. O ENUM base também preserva `excedente` ao ser reaplicado depois dela. Excedentes são linhas automáticas vinculadas ao lançamento do ponto e não entram no saldo; abatimentos/pagamentos manuais permanecem fora do escopo do reprocessamento.

## 15. Chat-In, Canais e Eventos (2026-11-05)
`sql/migration_chatin_mysql57.sql` cria: `chatin_conversas`, `chatin_participantes`, `chatin_mensagens`, `chatin_presenca`, `chatin_preferencias`, `chatin_bloqueios`, `notificacao_canais_config`, `eventos_condominio`. Todas com `tenant_id` e índices iniciando por ele; só `CREATE TABLE IF NOT EXISTS` (compatível com phpMyAdmin sem acesso a `INFORMATION_SCHEMA`). Detalhes em `CHATIN.md` §3.
`sql/migration_assembleia_tenant_mysql57.sql` adiciona `assembleias.tenant_id` + índice `idx_assembleias_tenant_data` com backfill (criador → `usuarios.tenant_id`; vínculo único em `usuario_tenant`; instalação com um único `tenants`). Idempotente por handlers 1060/1061, sem `INFORMATION_SCHEMA`. Linhas sem tenant ficam invisíveis na API.
Chave opcional `pwa_configuracoes.push_chatin_ativo` = '0' desliga o push do Chat-In no tenant.

## Migração PostgreSQL (ensaio privado, 2026-11-08)

O banco de produção continua MySQL/Percona no HostGator; PostgreSQL 18 na Hetzner é somente ambiente de ensaio isolado, sem conexão do ERP. Gate 2 foi aprovado por André para conversão usando o catálogo metadata-only da cópia restaurada do dump completo de 2026-11-07; não representa mudanças posteriores da produção. O snapshot contém 270 tabelas, 3.339 colunas, 11 FKs, 7 triggers, 2 procedures, 232 `AUTO_INCREMENT`, 228 tabelas com `tenant_id` e 42 sem.

O pipeline versionado em `tools/migracao_pg/` é fail-closed: `DATETIME` → `timestamp(0) without time zone`; `TIMESTAMP` é lido em UTC e convertido para hora civil de `America/Sao_Paulo`; datas zero/parcial-zero vão a `NULL` com contagem; `TIME` não representável bloqueia; valores textuais são preservados e NUL textual real bloqueia. O detector de NUL usa comparação binária. Destinos usam schemas `erp` e `legado`, roles técnicas `erp_owner`/`erp_app`, privilégios mínimos e identities alinhadas. Nunca inferir tenant nem transformar automaticamente regras não mapeadas.

Para o snapshot privado atual, três FKs tinham tipos PostgreSQL incompatíveis: duas colunas filhas `INT` signed seriam `integer` enquanto os pais identity são `bigint`; são promovidas para `bigint` sob `CHECK` que preserva o intervalo MySQL signed INT. A terceira FK, `rbac_auditoria.sessao_id`, é `BIGINT UNSIGNED` na origem, enquanto `rbac_sessoes.id` é identity `bigint` no destino. A exceção do ensaio promove a coluna filha a `bigint` apenas após confirmar por metadata o contrato exato e contar 791 linhas, zero nulos, zero frações, zero negativos e zero valores fora do `bigint` signed. As verificações no catálogo e no destino, as três alterações e as 11 FKs `NOT VALID` são transacionais; qualquer divergência aborta o bundle. Essa redução do domínio unsigned só é válida para o snapshot de ensaio e não define o mapeamento da migração final nem cobre dados futuros.

O alvo de ensaio v9 contém a carga original (270 tabelas/202.609 linhas), as três promoções FK revisadas, dois `CHECK` de faixa e as 11 FKs, todas validadas na reconciliação. O runner da retomada final terminou com `ACCEPTED`; o CSV possui 270 linhas e os critérios de contagem, hash, PK/identity/sequences, numeric, binário, tenancy e amostras temporais passaram em 270/270 tabelas. Houve zero órfãos e zero mismatch de tenant em FK. O cluster v9 foi parado pelo runner.

Foi criado um modo distinto, `--resume-post-fk-reconciliation`, restrito ao run de origem `20261108T203651Z`, ao commit aprovado e ao manifesto root-only de hashes. Ele revalida logs/summaries/snapshots/DDL, compara catálogo regenerado byte a byte, confere as 270 contagens e o estado exato das 11 FKs, e executa somente a reconciliação; não chama criação do database, loader, harness, DDL ou `ANALYZE`. A primeira retomada (`20261108T213233Z`) passou o preflight e bloqueou antes das comparações de linhas por diferença de alias de tipo retornado por `format_type`; a normalização estrita de `character varying(n)`/`character(n)` para `varchar(n)`/`char(n)`, preservando typmod, foi publicada em `9461f6584978767bf1da6a680331a1d67cda7912`. A segunda retomada (`20261108T214035Z`) completou a reconciliação e validou atomicamente as 11 FKs sem órfãos.

Dump, credenciais, linhas de negócio e logs brutos são privados e nunca versionados. A equivalência do alvo atual está aceita; o critério literal de executar todas as fases em um único ciclo contínuo do zero não foi cumprido, pois foram necessárias retomadas controladas após falhas fail-closed. Por isso, a Fase C não é declarada globalmente concluída e nenhum ensaio antigo foi removido. A autorização do Gate 2 não aprova deploy, DNS, cutover, conexão da aplicação ou limpeza do backup original.

Na branch dedicada `pg/03-1-ensaio-integral-v10`, a revisão pré-execução registra 434 datas zero convertidas para `NULL` em cinco colunas e 213 valores ENUM vazios preservados em seis colunas, além da análise das oito tabelas roteadas para `legado`; detalhes e referências de código estão em `tools/migracao_pg/V10_PRE_EXECUTION_REVIEW.md`. Essa normalização exige futura adaptação explícita do PDO para traduzir entradas temporais vazias para `NULL` e tolerar ENUM vazio sem inferência de domínio; a aplicação ainda não foi alterada nem conectada ao PostgreSQL. O runner v10 valida contagens do catálogo e nomes/schemas exatos no CSV de 270 tabelas. O ensaio integral do zero em `erp_ensaio_v10` ainda não foi executado; v9 e artefatos anteriores permanecem intocados. O hash do dump é validado somente contra manifesto root-only, não aparece em Git nem em stdout/logs e, por autorização expressa, será incluído apenas no relatório privado final.


**Retificação específica da Fase C.1:** por solicitação expressa, o SHA-256 do dump será incluído somente no relatório privado da execução v10. Ele não será incluído no código, no Git nem nos eventos/stdout/logs do runner.

O runner v10 mantém o database alvo exclusivo e recusa retomadas; as raízes de runs podem ser reutilizadas apenas com owner/mode corretos, sem sobrescrever logs anteriores. Antes do aceite, o próprio histórico privado deve comprovar, em ordem exata, todas as fases catalog→schema/índices→carga→harness→FKs→sequences→ANALYZE→reconciliação, os 10 testes funcionais e o marcador de privilégios mínimos `erp_app_minimum_privileges` (11 eventos distintos), os agregados 270/270 e o encerramento do cluster. No estado atual, a suíte sintética de 101 testes passou, mas a execução remota ainda não começou e as evidências root-only de proveniência precisam ser conferidas/preparadas.
