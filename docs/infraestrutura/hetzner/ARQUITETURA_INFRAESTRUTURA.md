# Arquitetura de infraestrutura — ERP Condomínios em Hetzner

> **Status:** `DRAFT / BLOCKED / NOT_EXECUTED`
> **Execução desta sessão:** nenhum acesso operacional ou alteração de host foi realizado.
> **Escopo:** preparação limitada da infraestrutura local e recebimento do clone completo, condicionados a acesso/fingerprint e inventário; sem serving público, deploy, acesso às linhas da origem MySQL ou operação do Gate 2.
> **Não é:** evidência de instalação, acesso ou estado atual da VM.

> Escopo autorizado, limites e evidências vigentes: [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md). A infraestrutura ainda não foi alterada porque falta acesso/fingerprint validados.

## 1. Objetivo

Definir uma arquitetura-alvo, condicionada a acesso e inventário, para hospedar o ERP Condomínios na VM declarada `<hostname privado omitido>` (`<IP privado omitido>`, Helsinki, CPX32, 4 vCPU, 8 GB RAM, 160 GB), com Ubuntu 24.04 LTS escolhido como imagem esperada, ainda não verificada no host.

A proposta cobre:

- terminação HTTP/HTTPS no Nginx;
- execução do backend PHP 8.x por PHP-FPM;
- entrega da SPA em Vanilla JS e seus recursos estáticos;
- PostgreSQL acessível somente localmente na VM, sem exposição da porta `5432` à Internet;
- fluxos entre navegador, Nginx, PHP-FPM, arquivos compartilhados, banco e operação;
- separação futura de código, dados compartilhados, backups, logs e materiais de deploy;
- releases versionadas, ponteiro `current` e deploy são arquitetura futura, não permitida nesta preparação; aqui só é admissível o clone privado completo em `source/`.

A proposta não autoriza, por si só, a migração do MySQL atual do HostGator, a criação de recursos, a alteração de DNS ou qualquer cutover. A preparação de código fica restrita ao clone privado completo em `source/`, após acesso/fingerprint validados e inventário read-only completo.

## 2. Estado conhecido, evidências e limites

### 2.1 Evidências documentais do sistema

Os documentos canônicos do repositório registram o ERP como:

- frontend em HTML, CSS e Vanilla JS, com SPA gerenciada por `app-router.js` e páginas parciais em `frontend/pages/`;
- backend monolítico modular composto por APIs `api_*.php`, em PHP 8.x, com partes procedurais e partes em transição para `ApiBase`;
- sessões PHP por `PHPSESSID`, autenticação centralizada em `auth_helper.php` e autorização server-side;
- banco MySQL no HostGator como origem declarada/documentada; não houve consulta, acesso, exportação ou cópia da origem nesta atividade;
- infraestrutura histórica em Apache/LiteSpeed com `.htaccess` e deploy manual via cPanel;
- uploads que devem permanecer fora da raiz pública ou ser entregues por endpoint PHP autenticado.

Esses registros orientam a análise estática da proposta, mas não comprovam compatibilidade com PostgreSQL, Nginx ou qualquer versão de PHP. PHP 8.3 é preferência condicional, a comparar com 8.4/8.5; PostgreSQL 18.6 é somente candidata técnica, dependente de OS, pacote assinado e versão efetivamente disponível.

### 2.2 Contexto desta sessão

- Repositório de referência: `https://github.com/ASOARESBH/app.erpcondominios.com.br-`.
- Baseline consultado: `f60739713e6de3f2473b79ecd14a70fd700e5462`.
- Branch de documentação: `infra/hetzner-environment-preparation`.
- A VM, o IP, a localização, o plano e a escolha de Ubuntu 24.04 LTS foram declarados pelo solicitante; não são fatos de ambiente auditado nesta sessão.
- O Gate 2 da migração está **CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED**.
- Não há acesso à VM, SSH autenticado ou fingerprint validado nesta entrega; nenhum bloqueio de acesso deve ser contornado.
- Não foram verificados chaves instaladas, firewall, sistema operacional, usuários, discos, recursos ou serviços.
- Não há evidência de DNS apontando para a VM nem de cutover realizado.

### 2.3 O que não foi executado

Não houve autenticação na console, SSH, chamada à API da Hetzner ou shell remoto. Também não foram executados `apt`, configuração de firewall, alteração de serviços, instalação de PostgreSQL/Nginx/PHP/Composer, recebimento do checkout na VM, deploy, backup ou migrations. A origem MySQL/HostGator não foi consultada, exportada, copiada nem usada como fonte de teste/importação. Nenhuma versão instalada, credencial, teste, estado de serviço ou resultado de conectividade deve ser inferido deste documento.

## 3. Arquitetura-alvo proposta

### 3.1 Visão lógica

```text
Navegador do usuário
        |
        | HTTPS 443 (origem pública do aplicativo)
        v
+-------------------------+
| Nginx                   |
| TLS, estáticos, limites |
| e encaminhamento PHP    |
+------------+------------+
             |
             | socket Unix privado
             v
+-------------------------+
| PHP-FPM                  |
| PHP 8.x                  |
| APIs, sessão, RBAC       |
+-------+-----------+------+
        |           |
        |           +------------------------------+
        |                                          |
        | socket/127.0.0.1:5432                    | filesystem privado
        v                                          v
+-------------------------+              +------------------------------+
| PostgreSQL              |              | shared/uploads, sessions,   |
| somente local-bind     |              | tmp e demais dados runtime  |
+-------------------------+              +------------------------------+

Operação autorizada --> SSH restrito / canal administrativo (proposto)
Backups -------------> backup local do PostgreSQL novo vazio e dos diretórios compartilhados aprovados; sem consulta/exportação de linhas MySQL
```

A implantação deve preservar o modelo de monólito existente. Não se propõe introdução de React, Vue, Angular, Node.js ou outro backend; o frontend permanece Vanilla JS e o backend permanece PHP 8.x.

### 3.2 Componentes e responsabilidades

| Componente | Responsabilidade proposta | Limite e observação |
|---|---|---|
| Nginx | TLS, redirecionamento HTTP quando aplicável, servir arquivos estáticos, encaminhar scripts PHP ao PHP-FPM, negar listagem e caminhos sensíveis | Configuração final depende da validação do entrypoint e do document root reais. |
| PHP-FPM | Executar o monólito PHP e as APIs, validar sessão, RBAC, tenant e dados de entrada | O pool deve usar usuário/grupo próprios e socket Unix privado; não se presume que exista hoje. |
| SPA Vanilla JS | Entregar a interface e chamar as APIs com `credentials: 'include'` | O roteamento continua no `app-router.js`; não há mudança de frontend nesta proposta. |
| PostgreSQL | Destino futuro definido; preparação local e banco vazio estão autorizados após auditoria segura do host | Compatibilidade da aplicação não está comprovada; serviço vazio não autoriza schema, dados, conexão do ERP ou cutover. Gate 2 permanece fechado. |
| Shared filesystem | Manter uploads, sessões, temporários e configuração fora do ciclo de release | Uploads não devem ser diretamente navegáveis sem autorização server-side. |
| Backups | Rotina local para PostgreSQL e cópia protegida dos diretórios compartilhados acordados | Banco: somente vazio nesta fase; arquivos: após inventário dos caminhos, com acesso restrito. Sem consulta/exportação de linhas MySQL. |
| Releases/current/deploy | Arquitetura futura para referência | Não criar, ativar, alternar, publicar ou executar nesta preparação. |

## 4. Fluxos de rede e aplicação

### 4.1 Navegador para aplicação

1. O navegador acessa o hostname oficial por HTTPS.
2. O Nginx termina TLS e aplica as políticas de cabeçalho, tamanho, timeout e acesso a caminhos.
3. O Nginx serve somente os recursos estáticos que pertençam ao document root validado.
4. Requisições para APIs PHP são encaminhadas ao PHP-FPM por socket Unix privado.
5. O PHP valida a sessão `PHPSESSID`, a autenticação e as permissões no backend antes de ler ou gravar dados.
6. Respostas e erros técnicos não devem expor credenciais, SQL, caminhos privados ou dados de moradores.

A origem permitida para chamadas autenticadas deve ser um placeholder de origem a aprovar, por exemplo `<ORIGEM_CORS_A_APROVAR>`, e não um domínio operacional fixo. A configuração futura deverá usar `Access-Control-Allow-Credentials: true`, sem wildcard, somente depois de aprovar explicitamente a origem, confirmar domínio, TLS e fluxo de autenticação.

### 4.2 PHP-FPM para PostgreSQL

O PHP-FPM acessará PostgreSQL por socket Unix ou, se a aplicação exigir TCP, por `127.0.0.1:5432`. O PostgreSQL deve escutar apenas no loopback; a porta não deve ser publicada no firewall, no security group ou em qualquer proxy externo.

A conexão, o esquema, as extensões PHP, o driver, o tratamento de transações, os tipos, as consultas e as migrations precisam ser validados em ambiente controlado. O código atualmente documentado utiliza MySQL; portanto, a simples troca do DSN não é critério de compatibilidade.

O teste técnico de PHP/PDO_PGSQL deve ser separado, local e isolado: usar `erp_app` somente contra o PostgreSQL vazio local e executar apenas `SELECT 1`. Não carregar o ERP, `api/config.php`, nenhuma API, configuração da aplicação ou dados/uploads existentes. Esse teste verifica apenas driver e conectividade do banco vazio; não é teste funcional do ERP e não autoriza conexão do ERP.

### 4.3 Arquivos de moradores e documentos

Uploads e arquivos privados devem residir em `shared/uploads/`, fora do document root público. A entrega deve ocorrer por endpoint PHP que valide sessão, tenant e autorização antes de transmitir o arquivo. O Nginx deve negar acesso direto aos diretórios `shared`, `backups`, `logs` e `deploy`, mesmo que uma configuração futura de root os torne alcançáveis por caminho.

### 4.4 Operação e manutenção

O acesso administrativo é um fluxo separado do tráfego da aplicação. A porta SSH `22/tcp` é apenas uma porta de gestão proposta e, se necessária, deverá ser restrita a IPs administrativos autorizados ou a uma rede privada/VPN. Não há evidência de que esteja acessível, configurada ou protegida na VM declarada.

## 5. Trust boundaries

| Boundary | Origem | Destino | Controle proposto | Situação |
|---|---|---|---|---|
| B1 — Internet/aplicação | Navegadores e clientes não confiáveis | Nginx | HTTPS, TLS válido, limites de requisição, cabeçalhos, sem exposição de diretórios | Proposta; não configurada/validada |
| B2 — Web/runtime | Nginx | PHP-FPM | Socket Unix, permissões de socket, pool dedicado, execução apenas de PHP autorizado | Proposta; socket e pool não auditados |
| B3 — Aplicação/banco | PHP-FPM | PostgreSQL | Usuário de banco com menor privilégio, autenticação local, bind em loopback/socket, sem `5432` público | Proposta; compatibilidade e instalação não comprovadas |
| B4 — Aplicação/arquivos | PHP-FPM | `shared/uploads`, sessões e temporários | Permissões de filesystem, fora do document root, entrega autenticada e filtragem de nomes | Proposta; layout atual não validado |
| B5 — Operação/servidor | Administradores autorizados | SSH e sistema operacional | Restrição de origem, chave ou mecanismo autorizado, MFA/VPN quando disponível, auditoria e menor privilégio | Proposta; acesso e fingerprint não confirmados |
| B6 — Runtime/backups futuros | Processo autorizado, após aprovação própria | Armazenamento de backup privado | Planejamento futuro limitado ao PostgreSQL novo/arquivos explicitamente incluídos; sem acesso/cópia da origem nesta fase | Não configurado; RPO/RTO e change pendentes |
| B7 — Transição de dados futura | MySQL HostGator | PostgreSQL | Qualquer consulta, backup, cópia, schema, carga, reconciliação ou cutover exige autorização independente; o Gate 2 não pode ser contornado ou waived | **CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED** |

## 6. Portas e superfícies de exposição

A tabela abaixo descreve a intenção da arquitetura, não o estado atual da VM.

| Porta/meio | Escopo proposto | Exposição | Uso |
|---|---|---|---|
| `443/tcp` | Internet → Nginx | **Não abrir nesta preparação**; listener público exige autorização/change separado | Aplicação HTTPS somente em etapa futura aprovada |
| `80/tcp` | Internet → Nginx | **Não abrir nesta preparação**; listener público exige autorização/change separado | ACME/redirect somente em etapa futura aprovada |
| `22/tcp` | Origem administrativa autorizada → host | Restrita; não é porta pública da aplicação | Administração, somente se autorizada e após validar acesso/fingerprint |
| `5432/tcp` | `127.0.0.1`/loopback → PostgreSQL | Privada local; **não publicar** | Acesso técnico local; conexão do ERP permanece proibida enquanto Gate 2 fechado |
| Socket Unix PHP-FPM | Nginx → PHP-FPM | Privado no host | Encaminhamento de scripts PHP, sem porta TCP pública |
| Socket Unix PostgreSQL, se adotado | PHP/runtime → PostgreSQL | Privado no host | Alternativa preferencial ao TCP local, se suportada pelo driver e configuração aprovados |
| MySQL HostGator | Nenhuma conexão nesta preparação | Não consultado/acessado/exportado/copiado | Origem declarada/documentada; qualquer interação futura requer autorização independente |

Não deve existir regra de entrada de Internet para `5432`. O fato de uma porta constar como necessária para comunicação local não autoriza sua publicação em IPv4 ou IPv6.

## 7. Organização de diretórios proposta

O layout abaixo é uma convenção de destino e precisa ser confirmado contra o layout real do monólito antes da primeira implantação. Não pressupõe a existência de `public/`, nem autoriza mover ou renomear código sem análise.

Nesta preparação, após inventário e confirmação de que os caminhos não conflitam, pode-se criar a estrutura administrativa sugerida e receber o clone privado completo em `source/`. Não criar nem ativar `releases/`/`current` para deploy. Backups/shared files devem respeitar o escopo autorizado; jamais apagar ou sobrescrever diretórios preexistentes.

```text
/opt/erpcondominios/
├── source/                           # clone completo do repositório, fora do webroot
├── releases/                         # arquitetura futura; não criar nesta preparação
│   ├── <release-id-1>/             # código imutável da versão validada
│   └── <release-id-2>/             # versão candidata ou anterior
├── current -> releases/<release-id> # arquitetura futura; não criar/alterar nesta preparação
├── shared/
│   ├── config/                      # configuração fora do Git; sem segredos no repositório
│   ├── uploads/                     # documentos e arquivos de moradores, fora do web root
│   ├── sessions/                    # se sessões em arquivo forem mantidas
│   ├── tmp/                         # temporários com limpeza e permissões controladas
│   └── runtime/                     # artefatos necessários à execução, se aplicável
├── backups/
│   ├── database/                    # dumps criptografados e metadados de retenção
│   └── releases/                    # cópias de release quando aprovadas
├── logs/
│   ├── deploy/                      # auditoria de deploy sem segredos
│   ├── app/                         # logs de aplicação, com minimização de dados pessoais
│   └── nginx/                       # logs de acesso e erro, conforme retenção aprovada
└── deploy/                          # arquitetura futura; não criar nesta preparação
    ├── manifests/                   # metadados de release e checksums
    └── procedures/                  # procedimentos aprovados, sem credenciais
```

O recebimento local do clone privado completo do remoto oficial em `BASE_DIR/source/` é a única operação de código admissível, sempre condicionado a acesso/fingerprint validados e inventário read-only completo. Antes de recebê-lo, registrar e conferir o remoto exato autorizado (URL completa de `origin`), o HEAD efetivamente verificado de `origin/main`, seu SHA completo, a presença do baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e as diferenças desde esse baseline. Divergência, conflito ou indício de comprometimento exige parada imediata. `source/` não deve ser raiz pública. `releases/`, `current` e `deploy` são apenas arquitetura futura: não criar, ativar, alternar, publicar ou executar nesta preparação.

### 7.1 Regras de cada área

- **`releases/`**: arquitetura futura; não criar ou instalar código nesta preparação. Em etapa futura, não armazenar uploads, dumps, sessões, logs nem credenciais.
- **`current`**: ponteiro exclusivamente futuro; não criar, alterar ou usar nesta preparação. Qualquer troca futura exigirá autorização própria e rollback aprovado.
- **`shared/config/`**: arquivos de configuração com permissões mínimas; segredos devem vir de mecanismo autorizado de segredo ou arquivo protegido fora do repositório. Este documento não registra valores reais.
- **`shared/`**: identificar os diretórios compartilhados no inventário; a cópia de backup local dos arquivos acordados está autorizada, com permissões restritas e sem modificar a origem. Não servir uploads diretamente pelo Nginx.
- **`shared/sessions/`**: usar apenas se a política de sessão aprovar armazenamento local; definir proprietário, modo e limpeza. Não registrar conteúdo de sessão em logs.
- **`backups/`**: o teste técnico local de backup e restauração isolada do PostgreSQL novo vazio é obrigatório; validar a integridade do backup e da restauração isolada. Separadamente, a cópia local protegida fica limitada aos diretórios compartilhados explicitamente acordados e não se confunde com o backup/restore do banco. O snapshot local mínimo, root-only, da configuração preexistente alterada é um controle de rollback separado, não um backup do banco nem um teste de restauração. Não fazer backup/exportação do banco MySQL/HostGator nem copiar linhas.
- **`logs/`**: restringir leitura, definir rotação e remover/mascarar dados pessoais e tokens. Erros técnicos devem ser úteis para diagnóstico sem retornar detalhes ao cliente.
- **`deploy/`**: arquitetura futura; não criar, guardar manifestos, executar scripts ou publicar nesta preparação.

### 7.2 Document root

O document root real do monólito não foi validado no host e não deve ser presumido a partir da convenção do HostGator. Antes de configurar o Nginx, deve-se identificar o entrypoint real da SPA, a localização dos assets, o caminho das APIs e a relação entre a raiz do repositório e a raiz pública.

A decisão proposta é apontar o Nginx para o menor diretório que contenha os entrypoints necessários, mantendo `shared/`, `backups/`, `logs/` e `deploy/` fora dele. Se a estrutura legada exigir que o root do release contenha `frontend/` e `api/`, a configuração deve negar arquivos de configuração, dumps, diretórios ocultos, uploads e qualquer recurso que não seja explicitamente público. Nenhuma reorganização é considerada feita por este documento.

## 8. Decisões de segurança propostas

1. **Somente HTTPS para a aplicação:** HTTP, se mantido, redireciona para HTTPS e não transporta sessão autenticada.
2. **PostgreSQL local-only:** bind em loopback ou socket Unix; `5432` não é publicada.
3. **PHP-FPM privado:** preferir socket Unix com permissões restritas; não expor PHP-FPM em endereço público.
4. **Autorização no backend:** autenticação, RBAC, tenant e validações continuam server-side; ocultar controles na SPA não substitui autorização.
5. **Sessão protegida:** cookies com `Secure`, `HttpOnly` e política `SameSite` compatível com o fluxo aprovado; CORS deve usar somente o placeholder de origem a aprovar, sem wildcard e sem domínio operacional fixo.
6. **Arquivos privados fora do web root:** uploads e documentos não são servidos como arquivos estáticos.
7. **Menor privilégio:** usuário do PHP-FPM não deve ter privilégios administrativos. Para o teste técnico local autorizado, `erp_app` pode ser uma role com `LOGIN` e somente `CONNECT` ao banco vazio; `PUBLIC` deve ter `CONNECT` e `TEMPORARY` revogados, e somente `CONNECT` deve ser concedido à role. `erp_app` não pode ter memberships nem os atributos `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION` ou `BYPASSRLS`, ownership, `CREATE`, `USAGE`, `TEMPORARY`, grants de objetos ou privilégios DML em schemas/objetos. A role não pode ser usada pela aplicação enquanto o Gate 2 estiver fechado.
8. **Separação de configuração:** credenciais, chaves e tokens não entram em Git, releases, logs, exemplos ou este documento.
9. **Observabilidade sem vazamento:** logs devem evitar CPF, tokens, cookies, senhas, conteúdo de documentos e SQL com dados pessoais.
10. **Backups limitados:** nesta preparação, somente o teste técnico de backup e restauração isolada do banco vazio PostgreSQL, o snapshot local mínimo, root-only, da configuração preexistente que será alterada e a cópia protegida dos diretórios compartilhados explicitamente acordados; o snapshot é apenas de configuração, não é backup do banco e não se confunde com teste ou ensaio de restauração de produção. Não incluir dados ou arquivos fora desse escopo, não acessar/copiar/exportar o MySQL/HostGator ou suas linhas e não fazer cópia externa.
11. **Bloqueio por padrão:** caminhos e métodos não necessários devem ser negados no Nginx e no backend, e listagem de diretórios deve estar desativada.
12. **Sem mudança silenciosa de banco:** PostgreSQL foi definido como destino, mas só se torna banco de aplicação após adapter compatível, validação funcional, reconciliação de dados, rollback aprovado e abertura dos gates correspondentes.

## 9. Procedimento futuro condicionado a acesso e autorizações

Os passos de preparação local descritos abaixo só podem ser considerados após acesso seguro com fingerprint validado, inventário read-only completo, segunda sessão administrativa validada, possibilidade de recuperação comprovada e change autorizado. As etapas de release/aplicação, migração, backup da origem, listener público, DNS e cutover são planejamento futuro, não autorização desta sessão. Nenhuma delas foi executada.

### Fase 0 — Autorizações e inventário

1. Registrar a autorização vigente de preparação e limitar os acessos ao mínimo necessário; ela não inclui DNS, dados de produção, cutover ou publicação pública.
2. Validar fingerprint do host por canal confiável antes de aceitar SSH.
3. Confirmar sistema operacional, usuários, chaves, disco, memória, rede, firewall e serviços atuais.
4. Confirmar o hostname, o IP efetivo, o domínio e o plano de reversão. O IP declarado não deve ser tratado como destino operacional até validação.
5. Registrar o estado existente antes de qualquer alteração. Permite-se somente snapshot local mínimo, root-only, da configuração preexistente que será alterada; não incluir dados/uploads, não copiar externamente, não consultar MySQL/HostGator e não apagar ou sobrescrever conteúdo.

**Saída exigida:** inventário auditado, acesso seguro e janela de mudança; manter separados os limites de DNS/dados/cutover.

**Stop obrigatório:** parar imediatamente diante de conflito, indício de comprometimento, impossibilidade de manter Nginx/PHP-FPM sem autostart e sem listener público, ou impossibilidade de comprovar as barreiras local-only específicas do PostgreSQL antes de iniciá-lo/habilitá-lo. Não prosseguir por suposição nem contornar bloqueios. A autorização condicional da Fase 2.5 cobre o hardening restritivo de SSH/firewall somente após os gates cumulativos de acesso autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e mecanismo de recuperação validado; não alterar antes de todos eles.

### Fase 1 — Validação do aplicativo e do document root

1. Confirmar no código ativo o entrypoint da SPA, o roteamento, as APIs PHP, os diretórios de upload e as dependências.
2. Identificar requisitos de PHP 8.x, extensões, Composer, permissões, sessão e tarefas agendadas. No baseline consultado não há manifests Composer; não instalar Composer nem criar `.env` sem necessidade comprovada.
3. Confirmar como a aplicação atual usa MySQL e catalogar incompatibilidades potenciais com PostgreSQL: driver, SQL, tipos, índices, funções, transações, charset, procedures, views e migrations.
4. Receber localmente o clone privado completo do remoto oficial sob `BASE_DIR/source/`, fora do webroot, somente após registrar e conferir o remoto exato autorizado, o HEAD efetivamente verificado de `origin/main`, seu SHA completo, o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e as diferenças desde esse baseline. Isso é staging de source, não release/deploy; divergência, conflito ou comprometimento exige parada.
5. Não montar, ativar ou testar aplicação nesta etapa. PostgreSQL pode ser preparado vazio antes do Gate 2, mas a aplicação não deve conectar-se a ele nem a MySQL/HostGator.

**Saída exigida:** matriz de compatibilidade e document root confirmado; identificar explicitamente o uso atual de `mysqli` e os adaptadores ainda ausentes.

### Fase 2 — Preparação controlada da base de infraestrutura

1. Auditar distribuição e versões existentes, preservando serviços e dados preexistentes. Preparar privadamente Nginx/PHP-FPM e PostgreSQL vazio em versões suportadas pelo OS efetivo. O `start`/`enable` do PostgreSQL só pode ocorrer após comprovar bind exclusivo em loopback, `pg_hba.conf` seguro, ausência de conflito ou exposição e verificar/documentar no firewall local/UFW e no firewall do provedor que a entrada externa para `5432` está negada em IPv4 e IPv6; antes dessas verificações, iniciar ou habilitar o PostgreSQL é proibido. Não publicar nem servir a aplicação externamente, nem declarar compatibilidade do ERP ou conectar o runtime ao PostgreSQL.
2. Criar usuários e grupos de serviço com menor privilégio.
3. Não criar `releases/`, `current`, `deploy` ou áreas de dados/uploads. O único diretório de código admissível é `source/`, para o clone privado completo, após as validações exigidas; preservar qualquer conteúdo preexistente.
4. Preparar configuração privada de Nginx/PHP-FPM sem listener público e sem ativar site padrão; é proibido habilitar ou manter `enable`/autostart persistente de Nginx/PHP-FPM, sem exceção. Durante a preparação, esses serviços só podem ser iniciados ou recarregados temporariamente, de forma controlada, para uma fixture sintética restrita a `127.0.0.1` ou socket Unix; remover a fixture e encerrar os serviços ao final. A fixture não pode servir o checkout, o ERP ou qualquer conteúdo real, nem habilitar publicação externa. Manter PostgreSQL local-only e demonstrar que nenhum listener público será criado.
5. Após acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa validada e possibilidade de recuperação comprovada, pode-se aplicar hardening restritivo e reversível de SSH e firewall/UFW, preservando a origem, os serviços e os dados não envolvidos. Restringir SSH às origens e mecanismos autorizados, sem abrir `22/tcp` globalmente, sem habilitar login SSH de root por senha e sem desabilitar o firewall; não alterar antes desses gates. Não abrir `5432` para a Internet.
6. Preparar obrigatoriamente o teste técnico local de backup e restauração isolada do PostgreSQL novo vazio, separadamente da cópia protegida somente dos diretórios compartilhados explicitamente acordados, e do snapshot local mínimo, root-only, da configuração preexistente que será alterada. Validar o backup e a restauração isolada do banco vazio e a integridade da cópia dos diretórios acordados, sem backup/cópia/consulta/exportação do MySQL/HostGator ou suas linhas, sem dados ou arquivos fora do acordo e sem cópia externa. O snapshot é apenas de configuração e controle de rollback separado; não é backup do banco e não representa teste ou ensaio de restauração de produção.

**Saída exigida:** configuração privada reversível, sem schema/dados, sem conexão funcional do ERP, sem release/current/deploy, sem listener público e sem autostart persistente de Nginx/PHP-FPM, sem cutover ou declaração de produção funcional. Se o autostart persistente de Nginx/PHP-FPM ou qualquer listener não puder ser controlado e demonstrado, parar.

> **Fases 3–5 abaixo são apenas referência de planejamento futuro e estão fora da autorização operacional atual. Não executar migração, consulta/backup/cópia/export da origem, migrations, carga/reconciliação de dados, criação de schema/objetos do ERP, conexão do ERP, release/deploy/publicação (inclusive privada), listener público, troca de tráfego, DNS ou cutover com base neste documento. A preparação local condicional autorizada permanece limitada às atividades da Fase 2 após os gates técnicos; ativações ou reloads estritamente locais de serviços/fixtures e o teste técnico de backup e restauração isolada do banco vazio, quando previstos ali, não representam ativação do ERP, migração, produção ou abertura do Gate 2. Cada mudança futura exige autorização própria; dados/schema/ERP/migração exigem Gate 2 formal, que permanece fechado para migração e uso do ERP: CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED.**

### Fase 3 — Banco e dados, se a migração for aprovada

1. Nenhum backup/consulta/exportação/cópia do MySQL/HostGator integra esta fase; qualquer proteção da origem exige autorização separada antes de qualquer leitura.
2. Mapear schema MySQL → PostgreSQL e testar migrations/dados em ambiente isolado.
3. Executar carga piloto, validação de contagens, chaves, relacionamentos, colunas críticas e amostras funcionais.
4. Corrigir incompatibilidades no código somente mediante escopo aprovado; não presumir que uma conversão automática seja suficiente.
5. Ensaiar sincronização final, janela de indisponibilidade, reconciliação e rollback.
6. Qualquer alteração futura do Gate 2 exigiria autorização formal independente; este documento não o abre, e ele permanece CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED.

**Saída exigida:** relatório de reconciliação, testes aprovados e plano de rollback assinado. Sem isso, o MySQL continua sendo a referência documentada e PostgreSQL não deve assumir produção.

### Fase 4 — Release e validação sem DNS

1. Empacotar uma release identificável, sem segredos e sem dados de produção.
2. Instalar em `releases/<release-id>` e gerar manifesto/checksums.
3. Configurar `shared/config` e conexões somente por mecanismo autorizado.
4. Validar sintaxe, extensões, carregamento da SPA, rotas, sessão, APIs, uploads autenticados e conexão ao banco de teste.
5. Alternar `current` apenas após os testes, mantendo o release anterior disponível para rollback.
6. Confirmar que arquivos privados não são servidos pelo Nginx e que não há rota pública para `5432`.

**Saída exigida (futura):** release candidata aprovada em ambiente privado, sem hostname público, sem alteração de DNS produtivo e somente após autorização própria.

### Fase 5 — Cutover condicionado

1. Aprovar janela, responsáveis, comunicação, backup final e critério de abortamento.
2. Confirmar TLS, DNS de baixo risco, health checks e monitoramento.
3. Fazer a troca de tráfego somente após o Gate 2 e os critérios de aceite deste documento estarem satisfeitos.
4. Monitorar erros HTTP, PHP-FPM, banco, autenticação, latência, logs, espaço em disco e integridade das operações críticas.
5. Reverter para o release ou ambiente anterior se qualquer critério de segurança, dados ou disponibilidade falhar.

Nenhum cutover, alteração de DNS ou declaração de produção está implícito nesta arquitetura.

## 10. Testes e critérios de aceite

Os testes abaixo são critérios futuros e não resultados obtidos.

### 10.1 Rede e exposição

- [ ] HTTPS atende o hostname aprovado com certificado válido e redirecionamento coerente de HTTP, se `80/tcp` estiver habilitada.
- [ ] A aplicação responde somente pelos endpoints públicos aprovados.
- [ ] Não há exposição externa de `5432` em IPv4 ou IPv6.
- [ ] PHP-FPM não está exposto por TCP público.
- [ ] SSH, se habilitado, aceita somente origens e mecanismos administrativos autorizados.
- [ ] Nginx não lista diretórios nem entrega `shared`, `backups`, `logs`, `deploy`, arquivos ocultos, dumps ou configuração.

### 10.2 Aplicação e sessão

- [ ] A SPA carrega pelo entrypoint validado e o `app-router.js` resolve as páginas esperadas.
- [ ] As APIs PHP são encaminhadas ao PHP-FPM e retornam o contrato esperado sem warnings ou detalhes internos.
- [ ] Login, expiração de sessão, cookie `PHPSESSID`, logout e acesso não autenticado funcionam conforme a política aprovada.
- [ ] CORS, somente em etapa futura autorizada, usa a origem aprovada no placeholder `<ORIGEM_CORS_A_APROVAR>` e preserva credenciais sem wildcard.
- [ ] RBAC, tenant e validações server-side impedem acesso direto indevido, inclusive por URL ou payload manipulado.
- [ ] Upload, download autenticado e isolamento de arquivos funcionam sem tornar documentos públicos.

### 10.3 Banco e dados

- [ ] O único teste técnico permitido, separado e local, usa PHP/PDO_PGSQL com `erp_app` contra o PostgreSQL vazio e executa apenas `SELECT 1`; não usa ERP, `api/config.php`, API, configuração da aplicação, dados ou uploads.
- [ ] Consultas e transações críticas passam pela matriz de compatibilidade do banco.
- [ ] Contagens, chaves, relacionamentos e amostras críticas são reconciliados entre origem e destino quando houver migração.
- [ ] O teste técnico de backup e restauração isolada do PostgreSQL vazio e a cópia protegida somente dos diretórios compartilhados acordados têm integridade verificável; o snapshot da configuração alterada é mínimo e root-only, não é backup do banco nem teste ou ensaio de restauração de produção; não há backup/cópia de MySQL/HostGator ou suas linhas, de dados ou arquivos fora do acordo, nem cópia externa nesta preparação.
- [ ] Existe rollback documentado para falha de release, falha de dados ou falha de tráfego.

### 10.4 Operação

- [ ] `current` aponta para release aprovada e o release anterior permanece disponível até o período de estabilização.
- [ ] Logs têm rotação, retenção e acesso restrito, sem segredos ou dados pessoais desnecessários.
- [ ] Espaço em disco, memória, PHP-FPM, Nginx e banco têm monitoramento ou procedimento de verificação aprovado.
- [ ] O procedimento de deploy identifica release, operador, horário, checksum e resultado.

## 11. Pendências e bloqueadores

1. Acesso administrativo à VM não confirmado; fingerprint, chaves e estado do SSH ainda não validados.
2. Sistema operacional, versões instaladas, firewall, usuários, discos, serviços e capacidade real desconhecidos.
3. Document root e entrypoint efetivos do monólito não validados no host.
4. Compatibilidade do ERP com Nginx, PHP-FPM, PHP 8.3 e PostgreSQL não comprovada.
5. Schema, queries, extensões, migrations e estratégia de sessão para PostgreSQL ainda não avaliados.
6. Gate 2 da migração está **CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED**.
7. PostgreSQL está definido como destino; ainda falta aprovar se o cutover ocorrerá com congelamento/escrita somente leitura, janela de manutenção ou outro método, além dos critérios de retorno ao MySQL.
8. DNS, TTL, certificado, hostname de teste, janela de cutover e plano de comunicação não definidos.
9. Política de firewall, origem administrativa do SSH, VPN, monitoramento, rotação de logs e retenção de backups pendentes.
10. Cópia externa, backup/cópia do MySQL/HostGator ou de suas linhas e backup/cópia de dados ou arquivos não acordados não são permitidos nesta preparação; permanecem autorizados somente o teste técnico de backup e restauração isolada do PostgreSQL vazio e a cópia protegida dos diretórios compartilhados acordados, sem confundir o snapshot mínimo de configuração com restauração de produção.
11. Permissões finais de filesystem e usuário/grupo do Nginx/PHP-FPM pendentes de inventário e aprovação.
12. Nenhuma instalação, configuração, migration, backup, deploy ou cutover foi executada por esta entrega.

## 12. Decisão de encerramento desta proposta

Esta arquitetura é uma proposta, não evidência de ambiente preparado. O escopo autorizado inclui preparação local condicional após acesso/fingerprint e inventário read-only validados; hardening restritivo de SSH/firewall somente após segunda sessão administrativa validada e possibilidade de recuperação comprovada; atualização de segurança, instalação/configuração de serviços necessários, PostgreSQL vazio, clone local privado completo em `source/`, Nginx/PHP-FPM, testes locais e o teste técnico de backup e restauração isolada do banco vazio e dos diretórios compartilhados acordados. Não inclui migração, linhas do MySQL/HostGator, deploy, serving público, DNS, cutover ou conexão funcional do ERP. SSH/firewall devem permanecer restritos e reversíveis. O Gate 2 está **CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED** para migração e uso do ERP. Preservar serviços e dados existentes; parar diante de conflito/comprometimento ou se não for possível demonstrar prevenção de autostart e listener. Nenhuma ação operacional é evidência desta proposta; PostgreSQL nunca deve ser publicado na Internet.
