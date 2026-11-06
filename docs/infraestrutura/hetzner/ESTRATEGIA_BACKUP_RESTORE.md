# Estratégia de Backup e Restauração — ERP Condomínios

**Status:** `DRAFT / BLOCKED / NOT_EXECUTED`
**Branch/worktree:** `infra/hetzner-environment-preparation`
**Baseline consultado:** `f60739713e6de3f2473b79ecd14a70fd700e5462`
**Data do documento:** 2026-11-06

> Plano da preparação autorizada, ainda não executado. Backup do MySQL/HostGator não integra o escopo; ver [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md).
>
> **Gate 2:** `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`. O gate não pode ser contornado, presumido aberto ou dispensado.
>
> **Autorização vigente, condicionada aos gates técnicos (não executada):** somente após acesso administrativo autenticado, fingerprint confiável e inventário completo e confiável em modo somente leitura, estão autorizados a preparação delimitada — incluindo a validação obrigatória de uma segunda sessão administrativa independente e de recuperação comprovada antes de qualquer hardening de SSH/UFW/firewall —, atualizações e serviços locais. Depois desses gates, o PostgreSQL local vazio com a role mínima `erp_app`, o dump local obrigatório e o teste obrigatório de restauração local isolada do banco vazio, a cópia local protegida somente dos diretórios compartilhados acordados, a fixture/teste local, o clone privado completo em `source/` fora do webroot, os logs operacionais e, separadamente, o snapshot mínimo de configuração para rollback seguem o escopo desta preparação. O PostgreSQL só pode ser intencionalmente iniciado, habilitado e mantido ativo de forma permanente em modo local-only depois de comprovados bind em loopback, `pg_hba` seguro, firewall com negação de `5432` e ausência de conflito; Nginx/PHP-FPM não podem ter autostart/enable e só podem ser iniciados/recarregados temporariamente para fixture controlada em loopback/socket Unix.
>
> Essa autorização condicionada não inclui dados/linhas MySQL/HostGator, migrations/schema definitivo/importação, conexão funcional ou QA do ERP, serving externo, DNS, cutover, backup externo, root por senha público, SSH global ou bypass. O Gate 2 de migração/integração continua `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED` e não se confunde com esta preparação. Nada foi executado nesta sessão.

## 1. Objetivo

Definir uma estratégia proposta, auditável e reversível para uma preparação privada da VM, preservando origem, serviços e dados existentes e sem publicar ou servir a aplicação externamente. A preparação somente pode começar depois de acesso administrativo autenticado, fingerprint confiável e inventário completo e confiável em modo somente leitura.

A autorização vigente, sempre condicionada às pré-condições e gates técnicos, inclui atualizações, hardening restritivo somente depois da segunda sessão administrativa independente e da recuperação comprovada, PostgreSQL novo vazio com role mínima `erp_app`, dump local e teste obrigatório de restauração local isolada do PostgreSQL vazio, fixture/teste local, clone privado completo em `source/` fora do webroot, logs, snapshot mínimo de configuração separado como rollback e backup local somente dos diretórios compartilhados identificados e acordados. O backup autorizado limita-se ao banco vazio e aos diretórios compartilhados acordados, sem qualquer conteúdo extra; os diretórios copiados não podem ser restaurados nesta etapa. PDO `SELECT 1` com `erp_app` é teste local isolado e não demonstra conexão funcional, QA ou funcionamento do ERP.
Quando os gates forem satisfeitos, o PostgreSQL pode ser intencionalmente iniciado, habilitado e mantido ativo de forma permanente somente em modo local-only, após comprovação de bind em loopback, `pg_hba` seguro, firewall com negação de `5432` e ausência de conflito. Nginx/PHP-FPM permanecem sem autostart/enable e só podem ser iniciados/recarregados temporariamente, de modo controlado, para a fixture sintética local; devem ser encerrados ao fim desse uso.

Backup externo de produção, arquivos/uploads não acordados, linhas do MySQL/HostGator, schema/migrations/importação, PITR/WAL, cópia física do cluster, cutover, serving externo e restore drill de produção permanecem fora do escopo e exigem autorização específica.

Nada foi executado nesta sessão: não houve preparação, backup, teste técnico de backup e restauração local isolada do PostgreSQL vazio, teste PDO, acesso remoto, consulta, configuração de serviço, alteração de ambiente ou publicação.

## 2. Estado conhecido e evidências

### 2.1 O que está documentado

- A documentação declara uma origem de banco hospedada externamente; essa origem é apenas **documentada/declarada, não verificada** nesta tarefa. Não houve acesso, consulta, cópia ou backup da origem.
- O ERP é documentado como uma SPA Vanilla JS com backend PHP 8.x. PostgreSQL foi definido como destino futuro, mas a compatibilidade operacional da aplicação não está comprovada.
- Os dados são multi-tenant. Qualquer verificação pós-restauração local isolada, se futuramente autorizada, deve preservar o isolamento por `tenant_id`.
- PDFs e outros documentos de inadimplência podem estar preservados em BLOB na estrutura `tenant_arquivos`, por meio de referências, e não devem ser duplicados em diretórios públicos.
- Arquivos de upload devem permanecer fora da raiz pública ou ser entregues somente por mecanismo que valide a sessão, conforme o guia de segurança.
- O guia de testes registra ausência de suíte automatizada geral; a validação é predominantemente manual e deve incluir saída limpa, autenticação, segurança e isolamento de dados.

### 2.2 O que não foi comprovado

- Não houve acesso à VM declarada `<hostname privado omitido>` (`<IP privado omitido>`, Helsinki, CPX32, 4 vCPU, 8 GB RAM, 160 GB).
- Ubuntu, versões de PostgreSQL/banco declarado/PHP, layout de discos, mounts, usuários, serviços, firewall, diretórios de upload, agente de backup e armazenamento externo são desconhecidos.
- SSH autenticado, fingerprint do host e chaves instaladas não foram confirmados. A navegação desta sessão à console via My Browser parou no controle de acesso do provedor e expirou antes da autenticação; nenhum acesso operacional foi obtido.
- Não foi comprovada a existência de PostgreSQL local, cluster, replicação, arquivamento de WAL, armazenamento externo ou chave de criptografia. A existência de qualquer preparação, backup ou teste técnico de restauração também não foi comprovada.
- O Gate 2 da migração está `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`. Portanto, este documento não afirma que PostgreSQL exista na VM, seja usado pela aplicação ou seja o banco de produção.

As informações acima são limites de evidência, não falhas de backup observadas.

## 3. Decisões e princípios propostos

As decisões abaixo delimitam a preparação autorizada; a execução depende de acesso administrativo autenticado, fingerprint confiável, inventário completo e confiável somente leitura e change rastreável:

1. **Preservar serviços e dados existentes.** Parar imediatamente diante de conflito, indício de comprometimento ou impossibilidade de demonstrar isolamento, prevenção de autostart ou de listener público.
2. **Hardening restritivo de SSH/UFW/firewall somente após a salvaguarda obrigatória.** Antes de qualquer alteração, sempre validar uma segunda sessão administrativa independente e recuperação comprovada; sem ambas, não aplicar hardening. Não usar bypass/SSH inseguro, root por senha público ou SSH global. Não abrir listener público nem alterar DNS.
3. **Separar os ciclos de vida dos serviços.** O PostgreSQL pode ser intencionalmente iniciado, habilitado e mantido ativo de forma permanente somente em modo local-only, após comprovar bind em loopback, `pg_hba` seguro, firewall com negação de `5432` e ausência de conflito. Nginx/PHP-FPM não podem ser habilitados para autostart/enable; só podem ser iniciados/recarregados temporariamente, de modo controlado e local, para fixture sintética em loopback/socket Unix, e devem ser encerrados ao final; não servir o ERP externamente.
4. **Manter o PostgreSQL local vazio e isolado.** Não tocar em dados preexistentes, uploads não acordados, tenants, schema/migrations, aplicações, login/API ou banco de produção.
5. **Backup autorizado, com escopo fechado:** após os gates satisfeitos, é obrigatório gerar o `pg_dump` local do banco PostgreSQL vazio e executar o teste de restauração local isolada nesse destino vazio; a única cópia de arquivos permitida é local, protegida e limitada aos diretórios compartilhados expressamente acordados. Não incluir qualquer conteúdo extra e não restaurar os diretórios copiados. Logs operacionais são evidência protegida, não conteúdo de backup. Snapshot de configuração é rollback separado, não backup nem restauração. Não gerar artefato de roles/globals, cópia física, WAL/PITR, nem backup do MySQL/HostGator.
6. **Teste separado e mínimo:** usar a role mínima `erp_app` apenas em teste local PHP/PDO_PGSQL de `SELECT 1`; isso não é conexão funcional, QA ou validação do ERP.
7. **Clone completo somente em `source/` privado, fora do webroot.** Usar exclusivamente o remoto oficial `https://github.com/ASOARESBH/app.erpcondominios.com.br-`, preservar o histórico, verificar o HEAD efetivo de `origin/main`, registrar seu SHA, confirmar o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e descrever os commits/diferenças desde ele. Não alterar `release/`, `current/` ou qualquer diretório de publicação.
8. **Rollback de configuração separado:** snapshot mínimo somente local e root-only, e somente se um arquivo preexistente for alterado; esse snapshot não é backup nem restauração. Não criar snapshot de dados, uploads não acordados ou origem.
9. **Armazenamento externo e backup de produção** ficam fora do escopo; a cópia local protegida dos diretórios compartilhados acordados é autorizada, mas esses diretórios não serão restaurados nesta etapa. Arquivos/uploads não acordados continuam proibidos.

## 4. Arquitetura de armazenamento proposta

### 4.1 Diretório privado local

O layout abaixo é uma proposta para os backups locais autorizados. Criar caminhos somente após acesso/fingerprint e inventário validados, sem sobrescrever existentes:

```text
<BACKUP_ROOT>/
├── postgresql-empty/
│   ├── logical/                 # único dump local permitido: banco PostgreSQL vazio
│   └── restauracao-local-isolada/ # banco local vazio e isolado para o teste técnico
├── shared/                       # cópia local dos diretórios compartilhados acordados
├── logs/                         # logs operacionais locais e protegidos
├── config-snapshots/            # somente snapshot root-only de config preexistente alterada
└── quarantine/                  # artefatos locais incompletos, se houver autorização
```

Requisitos propostos:

- filesystem privado, fora da raiz pública e sem exposição por Nginx, PHP, URL, listagem ou symlink;
- permissões mínimas e root-only para snapshots de configuração;
- separação demonstrável entre o banco local vazio e qualquer serviço ou dado existente;
- não modificar dados de origem; qualquer hardening de SSH/UFW/firewall exige sempre segunda sessão administrativa independente e recuperação comprovada antes da alteração; start/reload local controlado só ocorre após esses gates;
- não incluir linhas de origem, schema/migrations, aplicação executável ou credenciais; incluir somente os diretórios compartilhados expressamente identificados/acordados, nunca arquivos/uploads não acordados.

Se um arquivo de configuração preexistente for alterado em preparação autorizada, o único snapshot admitido é local, root-only e desse arquivo; se nada preexistente for alterado, não criar snapshot.

### 4.2 Armazenamento externo — requisito futuro, não executável

Backup externo de produção, recuperação ponto no tempo e cópia externa de arquivos/uploads ficam fora desta autorização. Arquivos/uploads locais que não estejam em diretórios compartilhados acordados também ficam fora do escopo. O desenho abaixo é apenas requisito futuro, sem acesso, configuração, upload, cópia ou comando nesta entrega, e exige autorização separada:

```text
<OBJECT_STORE>/<prefixo-privado>/
└── erp-condominios/<ambiente>/<escopo-nao-pessoal>/<tipo>/<AAAA>/<MM>/<DD>/
```

Requisitos futuros incluem segregação de contas, criptografia, transporte protegido, retenção, imutabilidade, auditoria, recuperação de chaves e teste de leitura. Nenhum destino externo foi acessado ou configurado, e nenhuma cópia externa existe como resultado desta tarefa.

## 5. Banco de dados: escopo e procedimento proposto

### 5.1 Gate de escolha do mecanismo

A origem consta apenas como **documentada/declarada, não verificada**. Não acessar, consultar, conectar, exportar, copiar ou fazer backup da origem, do provedor externo ou de qualquer banco de produção nesta autorização. Não há comando ou procedimento de origem neste documento.

O Gate 2 está `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`. Não pode ser aberto, contornado ou dispensado por este plano. PostgreSQL local não é produção, não é uma conversão e não substitui qualquer proteção futura da origem.

O único escopo de banco executável, após acesso e fingerprint validados e inventário completo e confiável somente leitura, é um PostgreSQL local recém-criado e vazio. Não importar roles/globals nem incluir dados, tenants, schema/migrations, aplicações, login/API ou arquivos; criar somente a role mínima `erp_app`, com o único atributo `LOGIN` e segredo provisionado fora de Git/chat/log. A role não pode ter memberships, atributos administrativos (`SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION` ou `BYPASSRLS`), ownership, `CREATE`, `USAGE`, `TEMPORARY`, grants de objetos ou privilégios DML. O banco deve revogar `CONNECT` e `TEMPORARY` de `PUBLIC` e conceder somente `CONNECT` à `erp_app`; não criar schema, tabelas ou objetos da aplicação. O uso autorizado limita-se ao teste local separado `PDO SELECT 1`.

### 5.2 Backup lógico PostgreSQL local vazio — único escopo permitido

Após todos os gates acima, é obrigatório gerar um `pg_dump` lógico do banco PostgreSQL local recém-criado e vazio e mantê-lo em filesystem privado local. O dump não é backup de produção, não contém dados da origem e não autoriza qualquer conexão funcional com o ERP.

Não incluir roles/globals no dump nem gerar artefato de roles/globals, cópia física, recuperação ponto no tempo, arquivos/uploads não acordados ou armazenamento externo. O único teste técnico permitido é o teste técnico de backup e restauração local isolada do PostgreSQL vazio, usando somente esse dump em banco PostgreSQL local, isolado e vazio.

Regras do único dump permitido:

- confirmar que o banco é local, recém-criado e vazio, sem tocar em bancos ou dados preexistentes;
- manter o artefato privado, com permissões mínimas e sem publicação;
- não enviar senha em linha de comando, log, manifesto ou nome de arquivo;
- registrar somente metadados não sensíveis, checksum local do dump e a evidência do teste de restauração no destino vazio;
- interromper imediatamente diante de conflito, comprometimento, dúvida sobre a identidade do banco ou impossibilidade de demonstrar isolamento;
- não declarar o artefato como backup de produção, cópia externa ou evidência de migração.

Nenhum dump foi gerado nesta sessão.

### 5.3 Recuperação ponto no tempo, WAL e cópia física — requisito futuro não executável

Cópia física de cluster, arquivamento contínuo, recuperação ponto no tempo, replicação e testes de continuidade não fazem parte da autorização atual. Permanecem apenas como requisitos de uma estratégia futura, sujeitos a avaliação, change e autorização separados. Não há comando, configuração ou teste dessas capacidades nesta entrega, e elas não podem ser declaradas disponíveis.

## 6. Arquivos compartilhados e documentos

Fazer backup local apenas dos diretórios compartilhados identificados no inventário e acordados para esta preparação, sem modificar a origem. Não acessar/exportar linhas MySQL, nem copiar arquivos/uploads fora desses caminhos.

A proteção de arquivos da origem que não estejam nos diretórios compartilhados acordados e o backup externo de produção são requisitos futuros, explicitamente não executáveis, que exigem autorização separada. Qualquer desenho futuro deverá manter arquivos privados fora da raiz pública e não poderá servir ou publicar conteúdo.

## 7. Criptografia, chaves e proteção de dados

### 7.1 Requisitos futuros

Criptografia de artefatos, gestão de chaves, armazenamento externo, rotação, auditoria, imutabilidade e recuperação fora da VM são requisitos futuros não executáveis nesta entrega. Não criar, acessar, copiar ou configurar chaves, cofres, buckets ou credenciais.

Os únicos conteúdos de backup local permitidos são o banco PostgreSQL vazio e a cópia local protegida dos diretórios compartilhados acordados, sempre sem conteúdo extra. Os diretórios copiados não serão restaurados nesta etapa. Logs protegidos são evidência operacional, não backup; snapshot mínimo root-only de configuração preexistente alterada é rollback separado, não backup nem restauração. Não gerar artefato de roles/globals e não colocar credenciais, tokens ou chaves em Git, logs, manifestos ou snapshots.

### 7.2 Privacidade e isolamento

Não acessar dados pessoais, tenants ou conteúdo de produção. Uploads e arquivos somente podem ser incluídos quando estiverem em diretórios compartilhados previamente acordados; arquivos/uploads não acordados permanecem proibidos. O teste técnico de backup e restauração local isolada do PostgreSQL vazio deve permanecer em banco local vazio e isolado, sem aplicação, login, API, integração, notificação, job ou listener público.

## 8. Retenção proposta e ciclo de vida

Nenhuma retenção ou backup foi configurado/executado nesta sessão. A preparação deve definir a retenção local dos backups autorizados antes de habilitar qualquer rotina; backup externo e RPO/RTO produtivos seguem fora do escopo.

| Escopo futuro | Situação nesta entrega | Observação |
|---|---|---|
| Dump lógico PostgreSQL local vazio e teste técnico de backup e restauração local isolada | Não executado | Somente após os gates técnicos, confirmando banco local recém-criado e vazio. |
| Backup externo de produção | Fora do escopo | Requer autorização específica; não executar. |
| Recuperação ponto no tempo e cópia física | Fora do escopo | Requer autorização específica; não executar. |
| Diretórios compartilhados acordados | Não executado | Backup somente local, após inventário e acordo dos caminhos; não alterar a origem. |
| Arquivos/uploads não acordados | Fora do escopo | Não acessar ou copiar; exigiriam autorização específica. |
| Logs e rollback de configuração separado | Não executado | Logs protegidos; snapshot somente local/root-only se configuração preexistente for alterada, sem ser backup/restauração. |
| Restore drill de produção | Fora do escopo | Requer ambiente e autorização específicos; o teste técnico de backup e restauração local isolada do PostgreSQL vazio não é restore drill de produção. |

Definir retenção dos backups locais autorizados antes de habilitar a rotina; não remover dados, serviços ou cópias preexistentes.

## 9. RPO/RTO a aprovar

Nenhum RPO/RTO foi aprovado, medido ou atendido. Esta estratégia é proposta, não constitui SLA e não promete recuperação da origem, de produção, de arquivos ou em minutos.

Metas futuras para banco, arquivos e serviço completo dependem de autorização separada, inventário e teste formal. Não há evidência de backup externo, recuperação ponto no tempo ou restore drill validado.

## 10. Manifesto, checksum e cadeia de custódia

Se o único dump local permitido for autorizado no futuro, seus metadados locais poderão registrar apenas identificador não sensível, banco PostgreSQL vazio, timestamp, versão da ferramenta, tamanho, checksum e resultado do teste técnico de backup e restauração local isolada. Não registrar credenciais, dados de linha, nomes pessoais ou conteúdo de produção.

Não registrar upload, destino externo, cadeia de custódia de produção ou confirmação de restore drill, porque nenhum desses eventos integra esta autorização. Nenhum manifesto ou checksum de backup foi produzido nesta sessão.

## 11. Monitoramento e alertas

A rotina local de backup/logs dos escopos autorizados deve ser preparada somente após os gates, com frequência/retenção definidas antes de agendar. Backup externo/PITR/buckets/produção permanecem fora do escopo.

Para a preparação futura, o critério mínimo de segurança é parar diante de conflito, comprometimento, autostart não prevenível ou listener não prevenível. Não considerar a existência de arquivo, job ou script como evidência de backup válido.

## 12. Procedimento futuro condicionado a acesso e autorização

Este é um roteiro de planejamento, não uma lista de ações executadas. Nenhuma etapa abaixo foi executada nesta sessão.

### 12.1 Pré-condições e limites

1. Validar acesso administrativo autenticado, identidade do host e fingerprint confiável antes de qualquer mudança; não usar bypass ou SSH inseguro.
2. Concluir inventário completo e confiável somente leitura, incluindo serviços, processos, listeners, mounts, volumes, usuários e caminhos, sem acessar dados, uploads não acordados ou bancos preexistentes.
3. Confirmar que a preparação preservará serviços e dados existentes; parar diante de conflito, indício de comprometimento ou impossibilidade de demonstrar prevenção de autostart e listener.
4. Validar sempre uma segunda sessão administrativa independente e recuperação comprovada; somente depois aplicar qualquer hardening restritivo de SSH/UFW/firewall. Não criar listener público, alterar DNS ou tocar em `release/`/`current/`.
5. Aplicar atualizações e instalar/configurar somente os serviços necessários. O PostgreSQL pode ser intencionalmente iniciado, habilitado e mantido ativo de forma permanente somente depois de comprovados bind exclusivo em loopback, `pg_hba` seguro, firewall com negação de `5432` e ausência de conflito. Nginx/PHP-FPM devem permanecer sem autostart/enable e só podem ser iniciados/recarregados temporariamente, de forma controlada, para fixture sintética em loopback/socket Unix; encerrar esse uso ao final e não servir o ERP externamente.
6. Preparar PostgreSQL local recém-criado e vazio e a role mínima `erp_app`, sem dados, tenants, schema/migrations, aplicações, login/API ou conexão funcional do ERP.
7. Fazer clone completo do remoto oficial, preservando o histórico, somente em `source/` privado e fora do webroot; verificar o HEAD efetivo de `origin/main`, registrar o SHA, confirmar o baseline e descrever as diferenças desde ele. Não publicar nem fazer deploy.
8. Criar snapshot somente local e root-only como rollback separado, se um arquivo de configuração preexistente for alterado; não tratá-lo como backup/restauração nem criar snapshot de dados, uploads não acordados ou origem.
9. Manter o Gate 2 `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`; não executar migração, cutover ou proteção da origem.

### 12.2 Únicas operações técnicas permitidas após os gates

Depois de todas as pré-condições, a autorização vigente abrange as seguintes operações, sem ampliar o escopo:

1. Gerar o `pg_dump` lógico do PostgreSQL local recém-criado e vazio, em armazenamento privado, sem roles/globals;
2. executar o teste técnico de backup e restauração local isolada do PostgreSQL vazio, usando somente esse dump em banco PostgreSQL local, isolado e vazio, e registrar evidência do restore bem-sucedido e da integridade do dump no destino;
3. fazer a cópia local protegida somente dos diretórios compartilhados identificados e acordados, sem modificar a origem e sem restaurar esses diretórios nesta etapa;
4. proteger logs operacionais e criar snapshot mínimo local/root-only como rollback separado quando um arquivo de configuração preexistente for alterado;
5. executar o teste técnico separado PHP/PDO_PGSQL `SELECT 1` usando a role mínima `erp_app`, sem ERP, login, API, schema, migração ou dados.

Não há autorização para backup externo, recuperação ponto no tempo, cópia física, arquivos/uploads não acordados, origem, tenants, schema/migrations/importação, conexão funcional ou QA do ERP, publicação, deploy, DNS, cutover, root por senha público, SSH global, bypass ou listener público. O PostgreSQL só pode ter start/enable/permanência quando o modo local-only estiver comprovado por loopback, `pg_hba`, firewall com negação de `5432` e ausência de conflito; Nginx/PHP-FPM não podem ter autostart/enable e só podem ter start/reload temporário e controlado para fixture local, com encerramento ao final.

### 12.3 Critério de sucesso da estratégia

O resultado desta entrega é somente uma estratégia proposta. Não existe estado `SUCCESS`, `VALIDATED` ou equivalente: nada foi executado, não há backup externo, recuperação ponto no tempo ou restore drill validado, e o teste PDO não foi executado.

## 13. Teste técnico de backup e restauração local isolada do PostgreSQL vazio

### 13.1 Escopo permitido

Após acesso administrativo autenticado, fingerprint confiável e inventário completo e confiável somente leitura, o teste técnico de backup e restauração local isolada do PostgreSQL vazio é obrigatório. O destino deve ser banco local isolado e vazio, sem conexão funcional com aplicação, produção, origem, rede pública ou dados preexistentes.

### 13.2 Execução técnica do backup e da restauração local isolada

O teste técnico deve usar apenas o artefato lógico do banco vazio, sem roles/globals, dados de produção, tenants, arquivos, schema/migrations, aplicação, login ou API. A execução autorizada deve registrar, como evidência mínima: (a) identificador do dump e checksum calculado localmente; (b) confirmação de que o destino do restore foi criado vazio, isolado e separado de qualquer banco/dado preexistente; (c) resultado bem-sucedido do restore no destino vazio, sem erro; (d) verificação da integridade do dump no destino, com os metadados/objetos esperados do banco vazio e sem dados de produção; e (e) ausência de efeitos em serviços ou dados existentes. Nenhum teste técnico de backup e restauração local isolada foi executado ou validado.

### 13.3 Recuperação ponto no tempo e arquivos — requisitos futuros não executáveis

Recuperação ponto no tempo e restauração de arquivos/uploads não fazem parte desta autorização. Qualquer procedimento futuro exige documento, change, autorização e ambiente separados; não há comandos, consultas ou instruções executáveis para essas atividades aqui.

### 13.4 Critério de saída

A preparação futura somente poderá ser considerada tecnicamente concluída se os gates técnicos, a segunda sessão administrativa independente e a recuperação comprovada, o dump e o teste técnico de backup e restauração local isolada do PostgreSQL vazio, incluindo evidência de restore bem-sucedido e integridade do dump no destino vazio, forem verificáveis; se o clone completo estiver em `source/` privado fora do webroot; se os diretórios compartilhados acordados tiverem cópia local protegida sem restauração; e se os testes locais estiverem registrados, sem listener público, publicação, alteração não restritiva de SSH/firewall, dano a serviços ou acesso a dados não autorizados. Isso não constitui backup de produção nem restore drill.

## 14. Restore drill

Restore drill de produção, arquivos, tenants, aplicação, login/API, recuperação ponto no tempo ou origem é requisito futuro não executável e exige autorização separada. O teste técnico local do dump vazio, se futuramente autorizado, não deve ser descrito como restore drill de produção.

Nenhum restore drill foi executado ou validado nesta sessão. Não há evidência de RPO/RTO, backup externo, recuperação ponto no tempo ou recuperação de dados reais.

## 15. Rollback e contenção

### 15.1 Durante eventual preparação privada

Se houver conflito, comprometimento, autostart não prevenível, listener não prevenível, dúvida sobre fingerprint ou risco aos serviços/dados existentes, parar sem prosseguir e preservar o estado encontrado. Não tentar contornar o gate nem corrigir por alteração de SSH/firewall.

### 15.2 Artefatos locais isolados

Em uma execução futura autorizada, qualquer artefato parcial do dump ou do teste técnico de backup e restauração local isolada deve ser isolado sem tocar em dados ou serviços existentes. Snapshot de configuração só existe como rollback separado se arquivo preexistente tiver sido alterado e deve permanecer local/root-only.

### 15.3 Produção

Restauração, rollback, cutover, deploy e publicação em produção não fazem parte desta entrega. Exigem procedimento, autorização e ponto de retorno separados; não executar rollback destrutivo por suposição.

## 16. Testes e critérios de aceite

Como nenhum comando foi executado, os itens abaixo são critérios futuros e não evidências atuais:

### 16.1 Pré-condições e segurança

- [ ] Acesso administrativo autenticado e fingerprint confiável do host foram validados.
- [ ] Inventário completo e confiável somente leitura foi concluído sem acessar dados, uploads não acordados ou bancos preexistentes.
- [ ] Foi demonstrada prevenção de autostart e listener; caso contrário, a preparação parou.
- [ ] Nginx/PHP-FPM permaneceram sem autostart/enable; quando iniciados/recarregados, foram usados somente de modo temporário, controlado e local para fixture sintética, sem publicação ou uso para servir o ERP.
- [ ] Qualquer hardening de SSH/UFW/firewall foi restritivo e precedido de validação de segunda sessão administrativa independente e recuperação comprovada; não houve DNS, listener público, `release/` ou `current/` alterados.
- [ ] O clone completo do remoto oficial ficou somente em `source/`, fora do webroot, com o HEAD efetivo de `origin/main`, seu SHA, o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e as diferenças desde o baseline registrados.
- [ ] Os diretórios compartilhados acordados, se existentes no inventário, foram copiados somente para backup local protegido, sem alterar a origem; logs operacionais foram protegidos.

### 16.2 Único teste de banco permitido

- [ ] O PostgreSQL é local, recém-criado e vazio, com bind exclusivo em loopback, `pg_hba` seguro, firewall com negação de `5432` e ausência de conflito comprovados antes de start/enable/permanência.
- [ ] A role `erp_app` é mínima, sem memberships/atributos administrativos/ownership/grants de aplicação, e o teste local PHP/PDO_PGSQL de `SELECT 1` não foi tratado como conexão funcional do ERP.
- [ ] O `pg_dump` lógico obrigatório foi gerado em armazenamento privado e limitado ao banco vazio, sem roles/globals, dados, tenants, arquivos ou produção, com checksum registrado.
- [ ] O teste técnico obrigatório de backup e restauração local isolada foi feito apenas em banco local isolado e vazio, sem aplicação ou dados preexistentes, com evidência de restore bem-sucedido e integridade do dump no destino vazio.
- [ ] O teste separado `PDO SELECT 1`, se autorizado, não acessou ERP, login, API ou dados.
- [ ] Snapshot de configuração, se necessário, foi apenas local/root-only e somente de arquivo preexistente alterado.

### 16.3 Fora do aceite desta entrega

- [ ] Backup externo, arquivos/uploads não acordados, origem, recuperação ponto no tempo, cópia física, migração, cutover e produção permanecem não executados.
- [ ] Nenhum restore drill de produção foi executado ou validado.

O documento não pode evoluir de `DRAFT / BLOCKED / NOT_EXECUTED` com base em scripts, arquivos ou alegação de preparação; exige evidências autorizadas e revisão técnica.

## 17. Pendências e bloqueadores

1. Validar acesso administrativo autenticado e fingerprint confiável do host.
2. Concluir inventário completo e confiável somente leitura sem acessar dados, uploads não acordados ou bancos preexistentes.
3. Demonstrar prevenção de autostart e listener; se isso não for possível, parar.
4. Preservar serviços e dados existentes; qualquer hardening de SSH/UFW/firewall deve ser restritivo e precedido de segunda sessão administrativa independente e recuperação comprovada; não alterar DNS, publicação ou listeners públicos.
5. Manter o Gate 2 como `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`.
6. Tratar a origem somente como **documentada/declarada, não verificada**; não acessar, consultar, copiar ou fazer backup dela.
7. Definir, em autorização separada, qualquer proteção futura de produção, arquivos/uploads não acordados, storage externo, recuperação ponto no tempo, RPO/RTO ou restore drill.
8. Preparar somente os componentes autorizados — incluindo serviços locais, PostgreSQL vazio, role mínima, clone privado completo em `source/`, fixture/teste local, dump e teste técnico de backup e restauração local isolada, logs e backup local dos diretórios compartilhados acordados — se todos os gates forem satisfeitos.
9. Realizar o teste técnico local PDO `SELECT 1` com `erp_app`, sem ERP, e não tratá-lo como conexão funcional ou QA do ERP.
10. Registrar que nada foi executado; não afirmar que preparação, backup, teste técnico de backup e restauração local isolada ou teste já existe.

## 18. Conclusão

Esta entrega contém uma estratégia proposta e não uma execução. A origem está **documentada/declarada, não verificada**. O Gate 2 permanece `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED` e não pode ser dispensado.

A preparação futura, somente após acesso administrativo autenticado, fingerprint confiável e inventário completo e confiável somente leitura, deve preservar origem, serviços e dados existentes, parar diante de conflito/comprometimento ou falta de prevenção demonstrável de autostart/listener, e aplicar qualquer hardening de SSH/UFW/firewall somente após validação de segunda sessão administrativa independente e recuperação comprovada. O PostgreSQL só pode ter start/enable/permanência após bind exclusivo em loopback, `pg_hba` seguro, firewall com negação de `5432` e ausência de conflito comprovados; Nginx/PHP-FPM devem permanecer sem autostart/enable e só podem ter start/reload temporário e controlado para fixture local. O escopo permanece restrito aos componentes autorizados: hardening restritivo, atualizações, serviços locais, PostgreSQL vazio com role mínima `erp_app`, fixture/teste local, clone privado completo do remoto oficial em `source/` fora do webroot com `origin/main`/SHA/baseline/diferenças registrados, `pg_dump` vazio com teste técnico de backup e restauração local isolada, logs, rollback de configuração separado e backup local dos diretórios compartilhados acordados; isso não publica nem serve o ERP externamente.

Nada foi executado. Não há backup externo, recuperação ponto no tempo, cópia local dos diretórios compartilhados acordados, migração, deploy, publicação ou restore drill validado. Esta estratégia não afirma que preparação, backup, clone, teste técnico de backup e restauração local isolada ou rollback já exista.
