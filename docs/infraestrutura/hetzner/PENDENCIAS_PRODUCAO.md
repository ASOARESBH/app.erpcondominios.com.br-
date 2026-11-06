# Pendências para produção e cutover — ambiente Hetzner

**Status do documento:** `DRAFT / BLOCKED / NOT_EXECUTED`
**Repositório:** `ASOARESBH/app.erpcondominios.com.br-`
**Branch/worktree:** `infra/hetzner-environment-preparation`
**Baseline documental consultado (não substitui HEAD atual verificado):** `f60739713e6de3f2473b79ecd14a70fd700e5462`
**Data de referência:** 2026-11-06

> Escopo autorizado, ações fora do escopo e estado operacional: [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md). Esta lista não autoriza execução.

## 1. Objetivo

Consolidar os bloqueadores que precisam ser resolvidos antes de qualquer alteração efetiva na VM, recebimento do código, deploy, migração de dados ou cutover do ERP Condomínios. A preparação de infraestrutura foi autorizada de forma **delimitada e condicional**, nos limites de [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md); essa autorização não substitui acesso administrativo seguro nem inventário da VM e não é autorização geral para executar etapas posteriores. Este documento não confirma que host, rede, serviços ou banco estejam acessíveis/configurados.

O objetivo de aceite é permitir uma decisão explícita de produção baseada em evidências reproduzíveis, autorização do responsável e plano de reversão. Até que os gates abaixo sejam atendidos, o cutover permanece bloqueado e o ambiente atual não deve ser desativado.

## 2. Estado conhecido e limites da evidência

### 2.1 VM declarada, ainda não auditada

Foi declarada a VM `<hostname privado omitido>`, IP `<IP privado omitido>`, região Helsinki, plano CPX32, 4 vCPU, 8 GB de RAM e 160 GB de armazenamento; Ubuntu 24.04 LTS foi escolhido como imagem. Esses dados são declaração de destino, não inventário observado. Permanecem pendentes a confirmação de existência, posse, imagem realmente instalada, hostname, estado de rede, discos, capacidade disponível, sistema operacional e ciclo de vida.

### 2.2 Acesso administrativo

A evidência histórica de 2026-11-05 registra que o SSH autenticado não foi confirmado, o fingerprint do host não foi validado, as chaves instaladas não foram confirmadas, o `sshd` foi visto inativo/desabilitado em inspeção anterior e o console serial expirou. Em 2026-11-06, a console abriu a tela de controle de acesso do provedor, mas a visualização expirou; nenhum acesso à VM foi realizado.

Portanto, não há evidência de acesso SSH funcional, identidade do host, conta administrativa recuperável, chave autorizada ou canal de recuperação disponível. A navegação desta sessão à console via My Browser chegou ao controle de acesso do provedor e expirou antes de autenticação; não se deve contornar essa etapa nem tratar qualquer acesso como concluído.

### 2.3 Aplicação e banco

O ERP está documentado como SPA em Vanilla JS com backend PHP 8.x e banco-fonte atual MySQL na HostGator. PostgreSQL foi definido pelo usuário como destino futuro; PHP 8.3 foi indicado como alvo preferido se compatível. Nenhum dos dois foi homologado no código/ambiente. PHP 8.3 já está em suporte de segurança apenas até 2027-12-31, então comparar 8.4/8.5 antes de fixar a versão. [1] O guia de deploy atual via cPanel/phpMyAdmin descreve a origem, não a Hetzner.

O guia de banco registra MySQL como banco relacional corrente, regras de isolamento por `tenant_id`, uso predominante de soft delete e migrations compatíveis com MySQL/MariaDB 5.7 em módulos específicos. Isso não constitui prova de que todo o sistema possa operar em PostgreSQL nem autoriza trocar o banco sem levantamento de compatibilidade, DDL, dados e comportamento.

### 2.4 Estado operacional não observado

Firewall, regras de rede, portas, sistema operacional, usuários, recursos, discos, serviços, logs, certificados, DNS, secrets, banco de dados e processos atuais da VM são desconhecidos. A navegação via My Browser chegou ao controle de acesso do provedor da console Hetzner e expirou antes da autenticação. Não foram executados SSH autenticado, API da Hetzner, shell remoto, `apt`, alterações de firewall, gerenciamento de services, PostgreSQL, Nginx, PHP, Composer, checkout na VM, deploy, backup ou migrations.

## 3. Decisões e restrições atuais

1. **Cutover bloqueado:** não há autorização técnica baseada em evidência para produção, migração de dados ou troca de tráfego.
2. **Sem alteração no ambiente:** nenhuma configuração da VM, firewall, serviços, banco, DNS, certificado, aplicação ou backup foi aplicada por esta atividade; a autorização existente é delimitada, condicional e não autoriza essas alterações por si só.
3. **Sem suposição de compatibilidade:** PHP 8.3 é o alvo preferido solicitado, mas a prova de compatibilidade e a comparação de ciclo de suporte com 8.4/8.5 continuam pendentes; versão PostgreSQL, Nginx, extensões, Composer e demais componentes também dependem de auditoria e testes.
4. **Origem preservada até cutover autorizado:** o MySQL da HostGator permanece a fonte operacional atual; PostgreSQL é o destino escolhido. Preparar serviço vazio não autoriza trocar o banco sem plano de migração, validação, reconciliação e rollback.
5. **Gate 2 CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED:** dados, DDL, tenant, integridade, compatibilidade e reconciliação não estão aprovados para migração; este estado não pode ser contornado, flexibilizado ou considerado atendido nesta tarefa.
6. **Sem exposição de dados sensíveis:** este documento não contém credenciais, chaves, tokens ou dados de moradores.
7. **Critério de conclusão por evidência:** uma pendência somente poderá ser marcada como concluída após observação, registro verificável, autorização correspondente e teste compatível com o risco.

### 3.1 Limite da preparação privada e reversível

A autorização vigente é **delimitada e condicional**, não uma autorização geral. A preparação privada e reversível está autorizada pela Fase 2.5 e pode prosseguir, sem autorização adicional, somente após serem validados cumulativamente: acesso administrativo autorizado, fingerprint confiável do host, inventário read-only completo, segunda sessão administrativa independente e mecanismo de recuperação disponível e validado. Esse conjunto é pré-requisito do hardening de SSH/UFW/firewall; validar apenas parte dele não autoriza hardening. A preparação deve preservar serviços e dados existentes. Se houver conflito, sinal de comprometimento ou qualquer dúvida sobre a preservação, a atividade deve parar.

O ciclo de vida dos serviços também deve ser demonstrado antes de qualquer preparação: o PostgreSQL somente poderá ser iniciado e habilitado intencionalmente após comprovar bind em loopback, `pg_hba` seguro, firewall/UFW e firewall do provedor negando `5432` externo e ausência de conflito; poderá permanecer ativo somente local-only. Nginx/PHP-FPM não poderão ser habilitados para autostart e, se necessários à fixture sintética, somente poderão ser iniciados ou recarregados temporariamente e de forma controlada, sem listener público, sendo encerrados ao final. A atividade deve parar se não for possível demonstrar essas salvaguardas.

Esta atualização não executou preparação e não valida acesso, fingerprint, inventário, segunda sessão ou recuperação. A Fase 2.5 descreve preparação delimitada após esses gates; a execução segue bloqueada pelo canal técnico não validado.

## 4. Bloqueadores para produção e cutover

Todos os itens desta seção estão **PENDENTES**. Nenhum foi observado como concluído nesta sessão.

### 4.1 SSH, fingerprint e recuperação administrativa

- Confirmar canal de acesso autorizado à VM e responsável pelo acesso.
- Confirmar hostname, IP e identidade do host por fingerprint registrada em canal confiável antes da primeira sessão administrativa.
- Confirmar método de autenticação por chave, usuário administrativo nominal e política de `sudo`; não usar credenciais compartilhadas.
- Se o SSH permanecer indisponível, parar e escalar pelo procedimento autorizado; não contornar acesso, não usar SSH inseguro e não redefinir credenciais fora de autorização específica e auditável.
- Confirmar uma segunda via de recuperação, como console funcional ou procedimento do provedor, sem considerar a console expirada como disponível.
- Registrar data, operador, fingerprint, conta e evidência do acesso sem incluir chave privada, senha ou token no repositório.
- Mudanças restritivas de SSH/UFW/firewall somente poderão ser consideradas após validação cumulativa do acesso administrativo autorizado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e mecanismo de recuperação; nunca abrir acesso global.

**Bloqueio:** sem identidade validada do host e acesso administrativo recuperável, não é permitido inventariar, configurar ou publicar a VM.

### 4.2 Inventário da VM, firewall e serviços

- Confirmar o sistema operacional e sua versão, arquitetura, kernel, hostname, timezone e sincronização de relógio.
- Levantar CPU, memória, armazenamento, partições, volumes, inodes, swap, limites de processo e margem operacional.
- Identificar interfaces, rotas, DNS, portas expostas e dependências de saída necessárias à aplicação.
- Documentar firewall local e controles de rede do provedor, incluindo política default, portas administrativas e portas públicas autorizadas.
- Inventariar serviços, processos, usuários, grupos, jobs agendados, unidades de serviço e listeners existentes; o estado atual permanece desconhecido.
- Definir hardening mínimo, atualização de pacotes, acesso restrito, retenção de logs e procedimento de mudança antes da abertura pública.
- Confirmar que o inventário não expõe segredos nem dados pessoais.

**Bloqueio:** sem inventário observado e revisão de exposição de rede, não há base para avaliar disponibilidade, superfície de ataque ou capacidade de produção.

### 4.3 Versões, extensões e runtime com prova do código

- Levantar as versões realmente requeridas pelo código PHP, APIs, SPA Vanilla JS, bibliotecas, cron jobs e integrações existentes.
- Mapear extensões PHP usadas direta ou indiretamente, incluindo extensões de banco, sessão, arquivos, imagem, PDF, e-mail e demais integrações efetivamente encontradas no código.
- Confirmar versão mínima/alvo de PHP por testes; PHP 8.3 foi solicitado, porém compatibilidade e justificativa para seu horizonte de suporte mais curto precisam ser aprovadas.
- Verificar dependências Composer e sua origem, versões bloqueadas, scripts de instalação e necessidade de binários externos; não assumir Composer instalado.
- Confirmar servidor web, modo de execução PHP, limites de upload, tempo de execução, sessões, cabeçalhos CORS e regras equivalentes às documentadas.
- Avaliar incompatibilidades entre MySQL/MariaDB atual e qualquer PostgreSQL proposto, incluindo SQL, tipos, ENUMs, collation, transações, prepared statements, procedures, cron e migrations.
- Registrar a matriz `componente → versão requerida → evidência → risco → decisão`; sem essa prova, a escolha de runtime permanece pendente.

**Bloqueio:** não selecionar ou instalar runtime, banco ou serviços de produção com base apenas na descrição da VM ou em versões presumidas.

### 4.4 Gate 2 — dados, DDL, tenant e migração — CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED

O Gate 2 está **CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED**. Ele é requisito de uma mudança futura e separada, não é executável nesta tarefa e não pode ser aberto, flexibilizado, contornado ou considerado atendido por este documento.

A coleta metadata-only da Fase 2.4 tem autorização e trilha próprias e não abre o Gate 2. Nesta fase não consultar/exportar linhas MySQL/HostGator, migrar dados, criar schema/migrations, importar dados, conectar funcionalmente/testar o ERP ou fazer deploy. Backup local de diretórios compartilhados acordados está autorizado após inventário.

Requisitos para uma mudança futura, **não executáveis nesta tarefa**: aprovação do escopo e da autoridade sobre a base; inventário autorizado de schema, tabelas, índices, constraints, collation, triggers, rotinas, volumes e crescimento; revisão de DDL e migrations; validação de tenant e integridade; tratamento de uploads e referências privadas; fixture sanitizada; ensaio descartável; reconciliação; e aprovação formal do plano de migração e rollback. Esses requisitos não constituem autorização presente para consultar, copiar, testar ou migrar dados.

**Bloqueio:** Gate 2 CLOSED impede schema, migration, importação, conexão funcional do ERP, tráfego público e cutover; não impede preparação isolada, clone privado ou testes de infraestrutura autorizados.

### 4.5 RPO, RTO e continuidade

- Definir e aprovar o **RPO** por domínio de dados e para a aplicação como um todo.
- Definir e aprovar o **RTO**, incluindo restauração do banco, arquivos, aplicação, DNS/certificado e validação pós-restauração.
- Identificar owner de continuidade, canal de escalonamento, horários de plantão e decisão de declarar incidente.
- Determinar o intervalo máximo de indisponibilidade aceitável e se haverá janela de congelamento de escrita durante o cutover.
- Documentar dependências que precisam ser restauradas em conjunto: banco, uploads, configurações, cron, e-mail, integrações e logs necessários à auditoria.

**Bloqueio:** sem RPO/RTO aprovados, não há critério para decidir se o cutover ou rollback é operacionalmente aceitável.

### 4.6 Backup do PostgreSQL vazio — obrigatório após os gates; backup externo e restore drill de produção fora do escopo

Backup externo de produção, MySQL/HostGator e restauração de dados reais permanecem fora do escopo. Após os gates da Fase 2.5, é obrigatório gerar um `pg_dump` local do PostgreSQL vazio e validar sua restauração técnica em ambiente isolado e descartável. Esse teste do banco vazio é distinto e não substitui o restore drill futuro de produção. Para cada diretório compartilhado cujos paths tenham sido explicitamente acordados, é obrigatória a cópia local protegida, com integridade verificada; ela não autoriza acessar ou copiar outros paths, não deve ser restaurada como parte dessa cópia e deve preservar a origem sem alteração. Sem paths acordados, nenhuma cópia deve ser feita. Snapshot mínimo de configuração preexistente eventualmente alterada deve ser privado/root-only e tratado separadamente como controle de rollback, nunca como backup/restore.

Requisitos futuros, **não executáveis nesta tarefa**: política autorizada de backup completo/incremental/lógico de produção, retenção, criptografia, segregação, cópia fora da VM e do domínio de falha, cofre de secrets, cobertura de todos os artefatos necessários ao cutover, restore drill de produção isolado, reconciliação, hash, evidências e periodicidade. O dump/restauração técnica do banco vazio deve ser evidenciado separadamente do restore drill de produção; a existência de qualquer arquivo não será tratada como backup executado ou restaurável.

**Bloqueio:** sem o dump e a restauração técnica isolada do banco vazio, quando os gates permitirem a preparação, e sem backup externo verificável e restore drill de produção aprovado em mudança futura autorizada, não realizar cutover nem considerar a operação recuperável.

### 4.7 Subdomínio, DNS e certificado

- Confirmar o subdomínio de produção autorizado, seu owner e a zona DNS sob controle do responsável.
- Definir TTL e plano de alteração/reversão de DNS, incluindo o destino atual e o novo destino.
- Emitir ou instalar certificado válido para o domínio autorizado, com cadeia, renovação automática ou procedimento de renovação documentado.
- Validar HTTPS, redirecionamento, cookies de sessão, `Secure`, `HttpOnly`, `SameSite`, CORS e origem permitida documentada para o ERP.
- Confirmar que nenhum hostname, certificado ou DNS será criado em nome de terceiro sem autorização registrada.
- Testar o domínio em ambiente controlado antes de apontar tráfego real.

**Bloqueio:** sem domínio e certificado autorizados, não publicar endpoints nem alterar o tráfego de usuários.

### 4.8 Operação, monitoramento e logging

- Definir owner de operação, canal de incidentes, severidade, SLA de resposta e procedimento de escalonamento.
- Implantar monitoramento de disponibilidade, latência, erros HTTP, uso de CPU/memória/disco, banco, filas/cron e validade do certificado, conforme escopo aprovado.
- Centralizar ou exportar logs com retenção, rotação, acesso mínimo e proteção contra exposição de CPF, e-mail, telefone, tokens, documentos ou conteúdo de moradores.
- Definir correlação de incidentes e auditoria suficiente para autenticação, RBAC, migração, deploy, backup e rollback.
- Criar alertas acionáveis com limiares definidos e testar o recebimento por responsáveis autorizados.
- Documentar runbooks para indisponibilidade, degradação, expiração de certificado, falha de backup, erro de banco e reversão.

**Bloqueio:** sem observabilidade mínima e owner operacional, uma falha pós-cutover não terá detecção nem resposta comprovadas.

### 4.9 Secrets e configuração sensível

- Inventariar, sem registrar valores, secrets necessários para banco, sessão, e-mail, integrações, backup, certificados e automações.
- Definir cofre ou mecanismo autorizado de distribuição, rotação, revogação e auditoria.
- Confirmar que `api/config.php`, `.htaccess`, uploads e outras áreas sensíveis não serão sobrescritos ou expostos indevidamente; o guia atual de deploy trata esses itens como sensíveis.
- Separar configuração por ambiente e impedir secrets no Git, artefatos de build, logs, screenshots, fixtures e documentação.
- Planejar rotação de credenciais durante a transição e revogação do que não for mais necessário.
- Validar permissões de arquivos, usuários de serviço e leitura mínima dos processos.

**Bloqueio:** sem gestão de secrets aprovada, não configurar integrações nem armazenar credenciais no novo ambiente.

### 4.10 RBAC, tenant e isolamento de dados

- Confirmar o modelo de perfis e permissões documentado: `visualizador`, `operador`, `gerente` e `admin`, sem usar ocultação de frontend como autorização.
- Validar autenticação de sessão, `PHPSESSID`, CORS com credenciais e respostas 401/403 para acessos sem sessão ou sem permissão.
- Confirmar que APIs derivam tenant da sessão/contexto confiável e filtram leituras e escritas por tenant, sem aceitar `tenant_id` do cliente.
- Revisar ações críticas de RBAC server-side, inclusive módulos de moradores, anexos, O.S., registros, convidados e demais APIs presentes no escopo do deploy.
- Testar isolamento entre dois tenants sintéticos, inclusive IDs coincidentes, consultas, uploads, relatórios, notificações e erros 404/403 apropriados.
- Confirmar auditoria para alteração de perfis, grupos, vínculos, permissões, configurações e ações administrativas.
- Definir procedimento para usuário inicial, recuperação de conta e revogação de acessos sem registrar credenciais no repositório.

**Bloqueio:** sem validação de tenant e RBAC no backend, o ambiente não pode receber dados reais.

### 4.11 QA, homologação e regressão

QA funcional, conexão/configuração funcional do ERP e validação contra dados ou serviços da origem estão proibidos nesta tarefa. Os itens abaixo são requisitos futuros, não executáveis agora.

- Preparar ambiente de homologação/ensaio com dados sintéticos ou anonimizados, isolado de produção.
- Executar validações manuais compatíveis com o guia atual: navegação SPA sem recarregar, ausência de erros vermelhos no console, responsividade, JSON estrito nas APIs, acesso sem sessão e tentativa de entrada com aspas simples.
- Validar endpoints PHP sem HTML/warnings, sessões, CORS, prepared statements, uploads, relatórios, cron jobs e integrações autorizadas.
- Executar `php -l` nos PHP alterados, `node --check` nos módulos JavaScript alterados e `git diff --check` antes do pacote/revisão, quando aplicável ao conjunto efetivamente alterado.
- Testar fluxos críticos por tenant e por perfil, além de cenários de falha, timeout, retry, idempotência, indisponibilidade do banco e restauração.
- Registrar resultados, ambiente, versão, evidência e falhas abertas; nenhum teste deste documento deve ser descrito como executado antes de ocorrer.
- Obter aprovação de QA e do owner de negócio para o escopo de produção.

**Bloqueio:** sem homologação reproduzível e aceite explícito, não promover a versão para cutover.

### 4.12 Owner, janela, rollback e revisão PR

- Nomear owner técnico, owner de negócio, responsável por banco, responsável por DNS/certificado, responsável por segurança e aprovador final.
- Definir janela de mudança, comunicação, congelamento de alterações, suporte durante a janela e critério objetivo para abortar.
- Elaborar runbook ordenado do cutover, com pré-checagens, backup, migração, deploy, smoke tests, troca de DNS/tráfego e observação pós-mudança.
- Elaborar rollback testado, incluindo restauração de banco/arquivos, reversão de aplicação, DNS e certificados; não assumir que desfazer deploy desfaz migração de dados.
- Definir prazo e sinais de decisão para rollback, com autoridade clara para interromper a operação.
- Revisar a PR da branch `infra/hetzner-environment-preparation`, incluindo diff, documentação, segurança, scripts, migrations e impacto operacional.
- Obter aprovação de revisão antes de qualquer merge, deploy ou alteração de produção; esta atividade não faz commit nem push.

**Bloqueio:** sem owners, janela, rollback testado e PR aprovada, a mudança não está autorizada para execução.

## 5. Requisitos futuros condicionados a acesso e autorizações — não executáveis nesta tarefa

Os itens abaixo preservam o plano futuro, mas não são passos autorizados nem executáveis nesta tarefa. Exigem mudança separada, autorização expressa, acesso e fingerprint validados, inventário read-only completo, preservação de serviços/dados e abertura dos gates correspondentes. Não devem ser copiados como autorização atual.

1. **Governança:** autorização Fase 2.5 registrada; identificar owners, janela quando necessária, risco, comunicação e critérios de abortar.
2. **Acesso futuro:** validação do canal administrativo, fingerprint, usuário nominal, `sudo` e recuperação; sem bypass ou SSH inseguro.
3. **Inventário futuro:** inventário read-only de versões, recursos, rede, firewall, discos, serviços, logs e estado do sistema, sem aplicar mudanças.
4. **Arquitetura futura:** matriz de compatibilidade comprovada pelo código e dependências; não consultar origem, schema ou dados nesta tarefa.
5. **Clone privado futuro:** clone completo e privado somente em `source/`, exclusivamente do remoto exato `https://github.com/ASOARESBH/app.erpcondominios.com.br-.git`; verificar o HEAD atual de `origin/main`, registrar o SHA verificado, o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e as diferenças desde esse baseline. Não usar `release/` ou `current`, não fazer deploy, publicação ou servir a aplicação, e não alegar que o HEAD atual foi verificado nesta tarefa.
6. **Preparação autorizada após gates:** instalar/configurar Nginx/PHP-FPM, PostgreSQL vazio e componentes necessários; hardening restritivo, testes locais e serviços locais são permitidos se preservarem acesso/recuperação e não expuserem publicamente o ERP. O hardening exige, cumulativamente, acesso administrativo autorizado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e mecanismo de recuperação validado.
7. **Ciclo de vida dos serviços:** o PostgreSQL somente poderá ser iniciado e habilitado intencionalmente após todos os gates, com bind exclusivo em loopback, `pg_hba` seguro e ausência de conflito demonstrados; poderá permanecer ativo para uso local futuro, sem listener público. Nginx/PHP-FPM não poderão ser habilitados para autostart: se necessários, só poderão ser iniciados/recarregados temporariamente e de forma controlada para fixture sintética em loopback/socket Unix, sendo encerrados ao final.
8. **PostgreSQL local:** manter o banco vazio e acesso local-only; `erp_app` deverá ter `LOGIN` e somente o privilégio `CONNECT`, com `CONNECT` e `TEMPORARY` de `PUBLIC` revogados. A role não poderá ter memberships, `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION`, `BYPASSRLS` ou qualquer outro atributo elevado, nem ownership, `CREATE`/`USAGE`/`TEMPORARY`, grants de schema/objeto ou qualquer privilégio/DML sobre objetos. O dump local do banco vazio e sua restauração técnica isolada são obrigatórios após os gates e não substituem o restore drill futuro de produção. O único teste de aplicação permitido em mudança futura separada será PHP/PDO_PGSQL com `SELECT 1` local, isolado e sem conexão, configuração ou teste funcional do ERP.
9. **Cópias e rollback:** manter somente cópia local protegida dos diretórios explicitamente acordados, preservando a origem e sem restaurar esses diretórios como parte da cópia. Snapshot mínimo, privado/root-only, de configuração preexistente eventualmente alterada deve ser separado e tratado apenas como controle de rollback, não como backup/restore.
10. **Gate 2 futuro:** backup produtivo externo de banco/uploads/arquivos/certificados, consulta de origem, schema, migrations/importação, amostra, ensaio, reconciliação e QA funcional somente em mudança futura expressamente autorizada; todos permanecem proibidos e não executáveis nesta tarefa, com Gate 2 CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED.
11. **Homologação futura:** validações de segurança, tenant, RBAC, performance, logs, cron, uploads e integrações somente com escopo, dados sintéticos/anonimizados e autorização específica; nenhuma validação funcional do ERP nesta tarefa.
12. **Restore drill futuro:** eventual restauração de backup externo de produção em ambiente isolado somente em mudança futura autorizada, com RPO/RTO, reconciliação e evidências aprovados; não se confunde com o dump/restauração técnica obrigatórios do PostgreSQL vazio.
13. **Pré-cutover futuro:** smoke tests, DNS/certificado, monitoramento, suporte, rollback, comunicação e aprovação dos owners somente após todos os gates, sem executar agora.
14. **Cutover e pós-cutover futuros:** deploy, publicação, troca de DNS/tráfego, serving da aplicação, observação funcional e encerramento somente em mudança futura autorizada; estão proibidos nesta tarefa.

## 6. Testes e critérios de aceite

A produção só poderá ser aceita quando todos os critérios aplicáveis abaixo tiverem evidência anexada ao change e aprovação do owner correspondente. A ausência de observação mantém o critério pendente.

| Área | Critério de aceite | Evidência exigida | Situação atual |
|---|---|---|---|
| Acesso | Host identificado por fingerprint e acesso administrativo nominal validado | Registro de fingerprint, conta e teste autorizado | PENDENTE |
| Recuperação | Canal de recuperação testado ou comprovadamente disponível | Resultado de teste e procedimento | PENDENTE |
| Infraestrutura | OS, recursos, discos, rede, firewall e serviços inventariados | Inventário datado e revisão | PENDENTE |
| Runtime | PHP, extensões, servidor web, dependências e banco compatíveis com o código | Matriz de compatibilidade e logs de validação | PENDENTE |
| PostgreSQL vazio | Banco vazio, listener local-only e role `erp_app` com `LOGIN` e somente `CONNECT`; `PUBLIC` sem `CONNECT`/`TEMPORARY`, sem memberships, atributos elevados, ownership, privilégios de schema/objeto ou DML | Evidência autorizada de configuração e inventário de privilégios | PENDENTE |
| Dump local | `pg_dump` local obrigatório do PostgreSQL vazio, somente após os gates | Manifesto/hash e registro do dump fora do Git | PENDENTE |
| Restauração técnica local | Restauração isolada e descartável do dump do banco vazio, distinta do restore drill futuro de produção | Relatório técnico separado, com ambiente e resultado | PENDENTE |
| Cópias locais acordadas | Cópia local protegida somente dos diretórios explicitamente acordados, com a origem preservada e sem restaurar esses diretórios | Manifesto dos paths autorizados, proteção e integridade | PENDENTE |
| Snapshot de configuração | Snapshot mínimo, privado/root-only e separado, exclusivamente como controle de rollback; não é backup/restore | Registro do snapshot e vínculo com a mudança | PENDENTE |
| Banco | Gate 2 CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED; nenhum DDL, plano de dados ou migração executado nesta tarefa | Aprovação futura, scripts revisados e reconciliação em mudança separada | CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED |
| Tenant/RBAC | Isolamento e autorização server-side confirmados com tenants sintéticos | Casos de teste e respostas esperadas | PENDENTE |
| Backup | Cópia externa íntegra e protegida | Manifesto/hash, retenção e localização autorizada | PENDENTE |
| Restauração | Restore drill atende RPO/RTO | Relatório com tempos e reconciliação | PENDENTE |
| DNS/TLS | Subdomínio autorizado, HTTPS e renovação validados | Registro DNS, teste TLS e owner | PENDENTE |
| Observabilidade | Métricas, logs, alertas e runbooks acionáveis | Evidência de alertas e acesso controlado | PENDENTE |
| QA | Fluxos críticos, segurança, SPA e APIs aprovados | Relatório de testes sem falhas bloqueadoras | PENDENTE |
| Cutover | Janela, owner, comunicação e rollback aprovados | Change aprovado e runbook assinado | PENDENTE |
| Governança | PR revisada e aprovada; sem secrets ou dados pessoais | Revisão registrada e diff limpo | PENDENTE |

## 7. Pendências para decisão e responsáveis

A tabela abaixo deve ser preenchida durante o planejamento autorizado. Nenhum owner, prazo ou decisão deve ser inventado neste documento.

| Pendência | Owner a nomear | Evidência/entregável | Gate | Status |
|---|---|---|---|---|
| Acesso SSH, fingerprint e recuperação administrativa | A definir | Registro de acesso e recuperação | Acesso | PENDENTE |
| Inventário de VM, firewall e serviços | A definir | Inventário read-only revisado | Infraestrutura | PENDENTE |
| Matriz de runtime e extensões | A definir | Compatibilidade comprovada no código | Runtime | PENDENTE |
| Destino PostgreSQL | Definido pelo usuário; major, minor, locale e timezone ainda precisam de validação | Decisão do usuário registrada; parâmetros técnicos aprovados após auditoria e testes | Arquitetura | DESTINO DEFINIDO / PARÂMETROS PENDENTES |
| DDL live, dados, tenant e Gate 2 | A definir | Requisito futuro não executável: inventário e reconciliação somente em mudança separada autorizada; Fase 2.4, origem, schema, amostra e ensaio permanecem proibidos | Dados | CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED |
| Dump e restauração técnica do PostgreSQL vazio | A definir | Dump local obrigatório após gates e restauração isolada do banco vazio; distinto do restore drill futuro de produção | Continuidade | PENDENTE |
| Cópias locais dos diretórios acordados | A definir | Cópia local protegida somente dos paths explicitamente acordados, sem restauração e preservando a origem | Continuidade | PENDENTE |
| Snapshot de configuração para rollback | A definir | Snapshot mínimo, privado/root-only e separado do backup/restore | Rollback | PENDENTE |
| RPO/RTO e continuidade | A definir | Metas e runbook aprovados | Operação | PENDENTE |
| Backup externo e restore drill | A definir | Relatório de restauração | Continuidade | PENDENTE |
| Subdomínio, DNS e certificado | A definir | Autorização e validação TLS | Rede | PENDENTE |
| Monitoramento, logging e incidentes | A definir | Alertas e runbooks | Operação | PENDENTE |
| Secrets e rotação | A definir | Cofre/configuração auditável | Segurança | PENDENTE |
| RBAC e isolamento tenant | A definir | Testes server-side por tenant/perfil | Segurança | PENDENTE |
| QA e homologação | A definir | Relatório de aceite | Qualidade | PENDENTE |
| Owner, janela e rollback | A definir | Change/runbook aprovados | Governança | PENDENTE |
| Revisão da PR | A definir | Aprovações e diff revisado | Governança | PENDENTE |

## 8. Condição de liberação

A liberação para produção/cutover deve permanecer **NÃO AUTORIZADA** enquanto houver qualquer bloqueador sem evidência e aprovação. Em especial, o Gate 2 permanece **CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED**; a ausência de acesso administrativo validado, inventário da VM, compatibilidade comprovada, backup externo com restore drill, DNS/TLS autorizado, observabilidade, QA, rollback e owners impede a execução.

Nenhuma pendência deste documento foi marcada como concluída por falta de observação. O próximo avanço permitido é somente o que estiver coberto por autorização explícita, change rastreável, evidência adequada e revisão correspondente.

## 9. Referências canônicas consideradas

- `AI-CONTEXT/DEPLOY.md` — ambiente atual, deploy, arquivos sensíveis e migrations.
- `AI-CONTEXT/SECURITY.md` — sessão, CORS, RBAC, proteção de diretórios e isolamento tenant.
- `AI-CONTEXT/DATABASE.md` — banco corrente, regras de integridade, migrations e tenant-awareness.
- `AI-CONTEXT/TESTING.md` — validações manuais, segurança, QA e limites do que foi ou não executado.

## Referências externas

[1]: https://www.php.net/supported-versions.php "Versões PHP oficialmente suportadas"
