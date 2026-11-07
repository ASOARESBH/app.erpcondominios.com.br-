# Escopo, estado e evidências — preparação Hetzner

## Estado vigente — execução específica v2, 07/11/2026

**Status:** `BASE_FASE_2_5_PREPARADA / VERIFICACAO_OK / ERP_NAO_PUBLICADO / GATE_2_CLOSED`.

**Fonte exclusiva:** `ASOARESBH/app.erpcondominios.com.br-`. **Branch:** `infra/hetzner-environment-preparation`. O código privado recebido na VM tem HEAD/origin/main no baseline `f60739713e6de3f2473b79ecd14a70fd700e5462`; a documentação desta preparação não foi mergeada em main nem aplicada ao código do ERP.

### Autorização mais recente e limites

O usuário aprovou somente a execução integral da v2 fornecida, sem editar seu conteúdo, com verificação de tamanho/hash, uma execução e parada em falha. Confirmou segunda sessão administrativa/console de recuperação e permitiu expressamente o reinício previsto do fail2ban, sem editar sua configuração. Fingerprint SSH, autenticação por chave e inventário read-only foram validados antes da preparação.

A decisão específica passou a autorizar Nginx do Ubuntu, PHP 8.3 do Ubuntu, PostgreSQL 18/PGDG, fuso `America/Sao_Paulo`, swap e UFW permitindo 22/80/443 em IPv4/IPv6; Nginx/PHP-FPM ativos/habilitados e catch-all HTTP, sem vhost ERP. Esses limites de base substituem os trechos anteriores que proibiam qualquer listener/autostart. **Não se trata de waiver do Gate 2:** continuam proibidos deploy do ERP, habilitar vhost, criar `current` ou configuração operacional do ERP, emitir TLS, alterar DNS, instalar servidor MySQL, importar dados, criar schema/objetos da aplicação, remover NOPASSWD, mergear main ou iniciar a Fase 3.

Backup Hetzner desativado por decisão informada pelo usuário; nenhuma alteração/auditoria dessa opção foi feita pelo agente. Não houve execução de backup/restore nesta tarefa, nem consulta a linhas do HostGator.

### Executado e verificado

| Item | Evidência sanitizada |
|---|---|
| Script | v2 de 22.576 bytes, hash `5ddf609b108095dfd510a3ff1c0654fbab1549dca3d527e3a3274f01fc8c473a`; cópia local/remota verificada; execução única em 21:49:27–21:49:43 UTC, código 0 e `FASE_2_5_BASE_OK` |
| OS | Ubuntu 24.04.5 LTS, kernel `6.8.0-149-generic`, 4 vCPUs e RAM ~8 GB; swap 4 GiB |
| Nginx/PHP | Nginx 1.24.0, PHP 8.3.6/PHP-FPM; sintaxe válida; somente catch-all habilitado; 349/350 PHP rastreados aprovados em lint, sem QA funcional |
| Rede local | UFW ativo deny incoming/allow outgoing, 22/80/443 liberados IPv4/IPv6; listener HTTP público; nenhum listener HTTPS; PostgreSQL apenas loopback |
| Banco | PostgreSQL 18.6 PGDG, cluster online, `erpcondominios` com zero tabelas públicas; teste PDO técnico isolado passou; `America/Sao_Paulo` |
| Clone | Completo e privado em `/opt/erpcondominios/source`, origem oficial, baseline presente, árvore limpa; não precisou deploy key |
| Preservação SSH/fail2ban | Checksums de 167 configurações iguais ao preflight; restart fail2ban autorizado, sem editar hardening/configurações |
| Ausências verificadas | `current`, configuração operacional ERP e vhost ERP habilitado ausentes; zero unidades systemd em falha |

### Incidente v1 e correção v2

A v1 (`7dcabbda5cd07e342fdd555eb67d002aa0182f042329400a083f145507bcc90e`, 21.740 bytes) foi descartada para execução futura. O executor aplicou `umask 077` ao proteger logs e o script a herdou: `90-erp.conf` foi criado root/0600, ilegível por postgres; reinício falhou. O diagnóstico foi read-only e não houve correção sem autorização. A v2 fixa `umask 022`, aplica owner/modes explícitos e valida como postgres antes de reiniciar; a verificação confirmou conf postgres/0644 e hba postgres/0640, cluster online. Histórico e detalhe: [INSTALACAO_SERVICOS.md](INSTALACAO_SERVICOS.md).

### Ainda não aprovado/executado

Gate 2 permanece **CLOSED/NÃO APROVADO**; não se reconciliou catálogo MySQL live nem se migrou dado/schema. Não há adapter PostgreSQL do ERP ou homologação de autenticação, tenant/RBAC, módulos, relatórios ou uploads. Zero tabelas/PDO `SELECT 1` não comprovam aplicação funcional. Os grants são os do script (`CONNECT, TEMPORARY`), não a política mínima antiga; nenhuma correção avulsa foi realizada. As alegações de tradução fiel dos `.htaccess` não foram homologadas funcionalmente; CORS, paths persistentes e bugs legados estão nas pendências.

Artefatos públicos desta change limitam-se à v2 fornecida, seis arquivos Nginx autorizados e documentação sanitizada. Segredos, IPs pessoais de origem e logs brutos ficam fora do Git. Nginx e tarefas futuras: [CONFIGURACAO_NGINX.md](CONFIGURACAO_NGINX.md).

<details>
<summary>Histórico preservado: escopo/status de 06/11/2026, anterior à execução específica v2</summary>

**Data de referência:** 06/11/2026
**Status:** `DRAFT / BLOCKED / NOT_EXECUTED`
**Classificação:** documentação sanitizada destinada ao repositório oficial público. Não incluir IP, hostname, detalhes de acesso, credenciais, chaves ou evidências operacionais; essas informações permanecem em armazenamento privado.
**Fonte de código autorizada:** `https://github.com/ASOARESBH/app.erpcondominios.com.br-`
**Commit-base de referência:** `f60739713e6de3f2473b79ecd14a70fd700e5462`

## Estado registrado nesta atualização

O escopo autorizado é a preparação limitada da infraestrutura da Hetzner e o recebimento do código-fonte completo do repositório oficial, sempre condicionado a acesso seguro e inventário. PostgreSQL é o destino definido, e PHP 8.3 é uma preferência condicionada à compatibilidade. A VM declarada é CPX32 em Helsinki; Ubuntu 24.04 LTS é **esperado/declarado, não auditado**, e a versão efetiva do sistema operacional é desconhecida. Essas são declarações do solicitante, não resultados de auditoria do host. IP e hostname foram omitidos deste material.

Não há, neste registro, acesso operacional validado, fingerprint confiável ou inventário read-only completo da VM. Em 2026-11-06 foi aceita uma conexão TCP à porta 22 para verificar alcance; não foi iniciado handshake SSH pelo cliente, não se leu nem validou banner/host key, não houve autenticação e não foi aberta sessão na VM. A navegação passiva no My Browser para a console permaneceu no Security Check e não retornou estado do host. A chave cliente conhecida foi encontrada localmente com permissões restritas, mas não está carregada em agente SSH; sua autorização na VM e o usuário correspondente não foram confirmados. Nenhuma instalação, alteração de firewall/serviços, clone na VM, criação de banco/role, backup, teste de serviço/aplicação, migration ou deploy é alegada como executada por este documento. Esta fase de infraestrutura não executa a coleta metadata-only da Fase 2.4, que possui autorização e trilha próprias; nesta fase não se consultam nem alteram dados da origem MySQL/HostGator, nem se exportam linhas.

**Resultado operacional:** `INFRAESTRUTURA NÃO PREPARADA`. O usuário autorizou expressamente a preparação delimitada da Fase 2.5; isso não prova que o canal técnico esteja acessível. A execução remota segue bloqueada até serem validados acesso administrativo seguro, fingerprint, inventário read-only completo e salvaguardas contra conflito, sobrescrita e exposição. Não contornar o controle do provedor nem as verificações de identidade.

**Gate 2:** `CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE`. Permanece fechado para ERP, dados, migração, publicação, deploy e atividade operacional; nenhuma exceção ou waiver pode contornar este gate.

## Plano autorizado, condicionado aos gates técnicos

Os itens abaixo estão dentro da autorização delimitada da Fase 2.5, mas não registram ações executadas. A sequência obrigatória e não circular é: (a) validar acesso administrativo autenticado e fingerprint confiável; (b) somente então iniciar o inventário inicial read-only; (c) concluir e registrar o inventário completo; e (d) somente depois, com os demais gates técnicos, janela, segredos seguros e rollback validados, realizar qualquer instalação, alteração ou hardening. Qualquer conflito, comprometimento ou salvaguarda não demonstrável exige abortar.

1. Após confirmar acesso administrativo autenticado e fingerprint confiável, fazer o inventário inicial read-only da VM; concluir e registrar o inventário completo antes de instalar ou alterar qualquer componente, preservando serviços, arquivos e dados já existentes.
2. Somente após o inventário completo e os demais gates técnicos, atualizar o Ubuntu e instalar/configurar somente componentes necessários do pedido: Nginx, PHP-FPM/extensões, PostgreSQL, Git e, quando comprovadamente necessário, Composer, Certbot e Fail2ban. Diferenciar autostart não controlado de uma ativação intencional: o PostgreSQL poderá ser iniciado e habilitado somente após os gates técnicos, e poderá permanecer ativo se o modo local-only for demonstrado; Nginx/PHP-FPM não devem ser habilitados para autostart nem publicar o ERP e só poderão ser iniciados/recarregados temporariamente, de forma controlada, para a fixture sintética em loopback/socket Unix. Hardening de SSH/UFW/firewall com regras restritivas somente depois de verificados fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e mecanismo de recuperação. Se os controles não puderem ser demonstrados, abortar. PHP 8.3 é preferência condicionada; comparar compatibilidade/suporte com 8.4 e 8.5. Redis/Node.js sem requisito não instalar.
3. Preparar PostgreSQL local, sem listener público, com UTF-8, logs e a rotina de backup/restauração autorizada apenas do novo banco vazio. Pode ser criado `erp_condominios` vazio e a role `erp_app` somente com `LOGIN`, se o segredo for provisionado fora de Git/chat/log. No banco vazio, a role deve ter somente `CONNECT`; revogar `CONNECT` e `TEMPORARY` de `PUBLIC`. `erp_app` não pode ter memberships nem `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION` ou `BYPASSRLS`, ownership, `CREATE`/`USAGE`/`TEMPORARY`, grants de schema/objeto ou qualquer DML. A role só pode ser usada no teste local PHP/PDO_PGSQL separado do ERP, de `SELECT 1`, nunca pela aplicação. O dump e a restauração do banco vazio constituem teste técnico isolado de restauração, não “restore drill de produção”. Snapshot mínimo de configuração, quando necessário, deve ser privado e tratado separadamente como controle de rollback, não como backup/restore. Não criar schema/tabelas/objetos da aplicação, inserir/importar dados nem executar migrations.
4. O ciclo de vida do PostgreSQL deve ser intencional: iniciar e habilitar somente depois de comprovar bind exclusivo em loopback, `pg_hba` seguro, firewall/UFW verificado sem 5432 externo e ausência de conflito; o serviço pode permanecer ativo para uso local futuro, sem listener público. Validar `SELECT 1` e executar `pg_dump`/restauração do banco vazio no teste técnico isolado. Se o modo local-only não puder ser demonstrado, abortar.
5. Receber o clone completo, com histórico, do único repositório autorizado em `source/` sob `BASE_DIR` administrativo confirmado no inventário, fora do webroot e sem servi-lo. `source/` é o clone e deve ser preservado, sem reset, edição ou substituição. Manter a cópia de `origin/main` no HEAD efetivamente verificado; registrar seu SHA atual, confirmar a presença do baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e descrever os commits/diferenças desde o baseline. Não criar release. Parar se a URL/remoto divergirem do repositório autorizado ou a integridade do clone falhar. Nenhum ZIP HostGator ou outro repositório é fonte de código.
6. Instalar e configurar Nginx/PHP-FPM com segurança, sem publicar ou servir o checkout/ERP externamente. Validar sintaxe e, se necessário, iniciar/recarregar os serviços somente de forma temporária e controlada para teste com fixture sintética em `127.0.0.1`/socket Unix; não habilitar autostart, remover a fixture e encerrar o uso temporário ao final. Não ativar domínio público, DNS ou certificado.
7. Preparar diretórios privados, rotina local de backup e logs somente para o PostgreSQL vazio e para os diretórios/arquivos compartilhados explicitamente acordados e previamente listados. É obrigatório gerar `pg_dump` do banco PostgreSQL vazio, validar sua restauração técnica isolada e manter cópia local protegida desses itens, sem alterar a origem. Não acessar, copiar ou fazer backup do MySQL/HostGator nem de qualquer dado, diretório, arquivo ou path que não esteja explicitamente acordado e previamente listado; não fazer backup externo. Snapshot de configuração preexistente alterada deve ser mínimo, privado e root-only, separado do backup/restore e tratado apenas como controle de rollback, não como backup dos itens compartilhados. Evidências operacionais ficam fora do Git.
8. Executar verificações locais de infraestrutura e um teste PHP/PDO_PGSQL separado do ERP usando `erp_app`/`SELECT 1` no banco vazio, somente após todos os gates cumulativos, janela, segredo seguro e rollback validados. Não iniciar a aplicação, usar `api/config.php`, testar APIs, abrir conexão do ERP ou realizar teste remoto/deploy/atividade do Gate 2. O Gate 2 permanece fechado para ERP, dados e migração; o status permanece `NOT_EXECUTED` e o acesso continua não validado.

## Proibido nesta fase

- Contornar SSH, controle de acesso do provedor, MFA, validação de fingerprint, autenticação ou controles do provedor; não usar host key desconhecida, `StrictHostKeyChecking=no`, `known_hosts` descartável, `sshpass`, credenciais em comandos, login SSH de root por senha nem abertura global do TCP/22.
- Executar deploy, publicar ou servir a aplicação externamente; abrir portas públicas da aplicação (80/443/5432), configurar DNS ou cutover. Teste temporário de fixture é permitido apenas em loopback/socket Unix. Mudanças controladas de serviço durante a preparação não podem expor o ERP. Nenhuma exceção `WAIVED` pode contornar esses limites ou o Gate 2.
- Remover, sobrescrever ou limpar serviços, arquivos, configurações ou dados existentes. Qualquer mudança deve ser reversível e precedida de inventário.
- Acessar, consultar, exportar, copiar, fazer backup, alterar ou migrar o banco MySQL/HostGator ou suas linhas. O backup autorizado de arquivos compartilhados não autoriza acesso ao banco de origem.
- Criar schema/tabelas/objetos definitivos do ERP, executar migrations da aplicação, carregar dados reais, apontar a aplicação para PostgreSQL ou declarar o ERP funcional.
- Expor PostgreSQL/5432 publicamente, abrir portas sem necessidade comprovada, ativar domínio produtivo, alterar DNS, substituir HostGator, publicar endpoints para moradores ou fazer cutover.
- Incluir credenciais, chaves, tokens, dados de moradores, dumps, backups ou logs sensíveis no Git, na documentação ou em diretório público.

Esta autorização cobre somente as ações enumeradas na Fase 2.5, condicionadas aos gates técnicos; não autoriza etapas de produção, migração ou cutover.

## Critérios de versão

Em 06/11/2026, a página oficial do PHP indica que 8.3 está apenas em suporte de segurança até 31/12/2027; PHP 8.4 tem suporte de segurança até 31/12/2028, e PHP 8.5 até 31/12/2029. Portanto, 8.3 só deve ser fixado se os testes de compatibilidade do ERP justificarem essa escolha frente a 8.4/8.5. A versão instalada do PHP é desconhecida. [1]

`18.6` é candidata técnica não verificada, não versão instalada nem homologação do ERP. Antes de qualquer instalação futura, devem ser confirmados a fonte APT/PGDG autorizada, o repositório configurado, a assinatura, a disponibilidade dos pacotes e a versão efetiva no host; a compatibilidade da imagem Ubuntu 24.04/Noble também deve ser confirmada. [2] [3] [4]

## Referências

[1]: https://www.php.net/supported-versions.php "Versões PHP oficialmente suportadas"
[2]: https://www.postgresql.org/support/versioning/ "Política de versões PostgreSQL"
[3]: https://www.postgresql.org/about/news/postgresql-186-1711-1615-1519-1424-and-19-beta-3-released-3365/ "PostgreSQL 18.6 e atualizações de agosto de 2026"
[4]: https://www.postgresql.org/download/linux/ubuntu/ "Pacotes PostgreSQL para Ubuntu"

</details>
