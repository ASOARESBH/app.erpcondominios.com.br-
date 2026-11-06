# Procedimento de preparação Hetzner — sem deploy/cutover

**Status atual:** `BLOCKED / NOT_EXECUTED` — preparação autorizada, não executada; acesso autenticado, fingerprint e inventário ainda bloqueados.
**Escopo:** preparação de infraestrutura e recebimento privado do código, após validação de acesso/fingerprint/inventário. Não autoriza deploy, publicação ou serving externo do ERP.
**Fonte de código autorizada:** `https://github.com/ASOARESBH/app.erpcondominios.com.br-`
**Baseline de referência desta sessão:** `f60739713e6de3f2473b79ecd14a70fd700e5462`
**Branch de documentação:** `infra/hetzner-environment-preparation`

> A preparação da Fase 2.5 está autorizada, porém ainda não executada: nenhum clone foi recebido e nenhum acesso autenticado à VM foi obtido nesta sessão. As operações devem respeitar a matriz de escopo; não há autorização para deploy/cutover.

> Começar somente após validação de acesso/fingerprint e inventário read-only completo, preservando serviços/dados e sem exposição pública. Se houver conflito, comprometimento ou risco de perda de acesso, parar sem contornar controles.

## 1. Objetivo e limites

O objetivo é executar preparação determinística e privada da VM e receber o projeto completo, sem publicar nem servir externamente o ERP:

1. validar identidade do host e acesso autorizado sem bypass ou SSH inseguro;
2. levantar o inventário completo da VM em modo somente leitura, preservando serviços e dados existentes;
3. receber, somente se os gates forem satisfeitos, um clone completo e privado em `source/`, dentro de um `BASE_DIR` confirmado e fora do webroot;
4. verificar o remoto autorizado, o `HEAD` corrente de `origin/main`, seu SHA completo, o baseline e o diff de commits desde o baseline;
5. preparar candidatos privados de Nginx e PHP-FPM, validar sintaxe e, somente se indispensável, usar uma fixture sintética temporária local;
6. preparar PostgreSQL local vazio, role mínima e teste PDO/PDO_PGSQL isolado da aplicação;
7. guardar somente evidências não sensíveis e manter todas as mudanças privadas e reversíveis.

Esta preparação **não** inclui release, `current`, webroot público, deploy/serving externo, DNS, cutover, QA funcional do ERP, alteração de schema/migration/importação, consulta/exportação de linhas MySQL/HostGator ou backup externo. Serviços locais, hardening restritivo, clone privado e testes sintéticos de infraestrutura estão autorizados após os gates.

Qualquer necessidade posterior desses itens exige autorização e procedimento independente. Este documento não descreve passos para executá-los.

## 2. Estado conhecido, evidências e limites

| Item | Estado/evidência nesta sessão |
|---|---|
| Repositório fonte | Única fonte autorizada: `https://github.com/ASOARESBH/app.erpcondominios.com.br-`. |
| Baseline | Commit `f60739713e6de3f2473b79ecd14a70fd700e5462`, informado como referência desta sessão. |
| Branch de preparação | `infra/hetzner-environment-preparation`, criada a partir do baseline informado para a documentação. |
| VM declarada | `<hostname privado omitido>`, `<IP privado omitido>`, Helsinki, CPX32, 4 vCPU, 8 GB RAM, 160 GB; Ubuntu 24.04 LTS foi escolhido como imagem, sem verificação do sistema instalado. São dados declarados, não auditoria do ambiente. |
| Acesso | SSH autenticado não confirmado; fingerprint do host não validada; chaves instaladas não confirmadas. Nenhum acesso à VM ocorreu. |
| Console | A navegação via My Browser chegou ao controle de acesso do provedor; a verificação expirou antes da autenticação. Nenhum acesso operacional foi obtido. |
| Estado da VM | Firewall, sistema operacional efetivo, usuários, discos, recursos, permissões, serviços, listeners, autostart, Nginx, PHP-FPM e PostgreSQL são desconhecidos. |
| Aplicação | SPA Vanilla JS + PHP 8.x. O MySQL HostGator é a origem documentada; PostgreSQL local vazio é, no máximo, uma preparação condicionada. Nenhum runtime ou banco destino foi homologado. |
| Arquivos e dados | Não foram acessados, copiados ou inventariados `api/config.php`, `uploads/`, dados de moradores, MySQL/HostGator ou qualquer persistência preexistente. |
| Gate 2 | **`CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`**. Migrations, schema, importação, conexão funcional do ERP e mudança de banco permanecem bloqueados. |
| Preparação | Nenhum clone foi recebido na VM; nenhuma configuração foi aplicada; nenhum serviço, listener ou autostart foi ativado. |

A análise documental do baseline registrou que `composer.json`, `composer.lock`, `package.json` e `.env.example` não eram rastreados no commit de referência; `api/config.php` continha referência lexical a `mysqli` e `getenv`, sem referência lexical a PDO/PgSQL. Isso não autoriza instalar dependências, criar `.env`, alterar `api/config.php` ou concluir compatibilidade com PostgreSQL.

A documentação histórica do HostGator menciona ZIP/cPanel, `api/config.php`, `uploads/` e `.htaccess` como sensíveis. Isso não comprova a topologia da Hetzner nem autoriza copiar credenciais, uploads, dados, regras legadas ou arquivos de produção.

## 3. Gates, parada obrigatória e não-waiver

### 3.1 Gate de acesso e inventário

Antes de qualquer preparação futura, devem existir, registrados sem segredos:

1. autorização explícita para acessar a VM e identificação do operador com o menor privilégio suficiente;
2. fingerprint do host verificada por canal confiável e política de SSH segura, sem bypass;
3. acesso autorizado, sem reutilizar credenciais em documento, comando persistido, chat ou log;
4. inventário completo e read-only do sistema efetivo, discos, usuários, permissões, serviços, listeners, autostart, Nginx, PHP-FPM, PostgreSQL, logs, webroot, diretórios de dados e regras de firewall;
5. confirmação de preservação dos serviços existentes, mantendo somente listeners não-ERP preexistentes e inventariados, se houver, e de ausência de exposição pública do ERP; este gate não autoriza novo listener HTTP/HTTPS, enable, autostart ou start/reload genérico. Nginx/PHP-FPM somente poderão ter start/reload temporário e controlado para fixture local em loopback/socket Unix, sem enable, autostart, serving do ERP ou exposição externa; PostgreSQL somente poderá iniciar/habilitar após seu gate local específico e poderá permanecer ativo apenas local-only;
6. confirmação do `BASE_DIR` administrativo, privado e fora do webroot;
7. confirmação de que o remoto de código é o repositório autorizado.

Se a fingerprint, o acesso, o inventário, o `BASE_DIR` ou a preservação do ambiente não puderem ser demonstrados, o processo fica bloqueado e não há ação corretiva prevista neste documento.

### 3.2 Condições de parada por conflito ou comprometimento

A execução futura deve parar imediatamente, sem tentar corrigir ou contornar o achado, se houver:

- fingerprint inesperada, falha de autenticação, credencial suspeita ou necessidade de bypass;
- sinais de comprometimento, malware, alteração não explicada, serviço desconhecido, listener inesperado ou divergência entre o ambiente declarado e o inventário;
- conflito com serviços, dados, permissões, diretórios ou políticas existentes;
- impossibilidade de demonstrar que não haverá autostart ou listener público indevido, ou que o uso temporário de Nginx/PHP-FPM para fixture local e o início/habilitação do PostgreSQL ocorrerão somente após seus gates específicos, de forma controlada e sem exposição do ERP; publicação externa ou de produção permanece proibida;
- remoto, branch, `HEAD`, SHA ou baseline incompatíveis com a autorização;
- necessidade de acessar, copiar, testar ou preservar dados de produção, uploads, MySQL/HostGator ou credenciais;
- qualquer exigência de schema, migration, importação, alteração de banco ou teste funcional do ERP.

A parada deve ser registrada como `BLOCKED / PENDING`, sem alegar sucesso e sem executar remediação.

### 3.3 Gate 2 — fechado e não-waivable

O **Gate 2 permanece `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`**. Ele não pode ser flexibilizado, ignorado ou considerado implicitamente aprovado. Sob este documento não são permitidos schema, migration, importação, alteração de dados, conexão/configuração/teste funcional do ERP, troca de MySQL para PostgreSQL, acesso ao banco HostGator ou qualquer cutover.

O PostgreSQL local vazio descrito adiante é uma preparação isolada e condicionada; não abre, substitui ou contorna o Gate 2.

## 4. Decisões e invariantes da preparação

### 4.1 Código: clone completo, privado e somente em `source/`

- A única origem permitida é o remoto autorizado indicado no cabeçalho.
- O clone futuro deve ser completo, sem truncamento de histórico, privado e criado somente em `BASE_DIR/source/`, com `.git` e histórico preservados para auditoria.
- `BASE_DIR` deve ser confirmado no inventário, ficar fora do webroot e não conflitar com diretórios ou serviços existentes.
- Antes de considerar o clone íntegro, deve-se verificar a identidade do remoto autorizado, o `HEAD` corrente de `origin/main` e o SHA completo correspondente.
- Deve-se registrar o SHA corrente de `origin/main`, a existência do baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e o diff completo de commits desde esse baseline. O baseline não é promoção nem aprovação de produção.
- A revisão deve permanecer privada, sem webroot, release, `current`, link simbólico de publicação ou checkout de aplicação.
- Não são permitidos ZIP do HostGator, repositório alternativo, cópia de arquivos persistentes, segredo, `.env`, dump, chave ou token.

### 4.2 Preservação de serviços, dados e segredos

- O inventário read-only é pré-requisito para qualquer alteração de preparação.
- Serviços, configurações, permissões, discos, webroot, dados e diretórios existentes devem permanecer preservados.
- Não acessar, copiar, exportar, testar, fazer backup ou restaurar MySQL/HostGator, `uploads/`, dados de moradores, `api/config.php` ou outros dados preexistentes, exceto a cópia local expressamente autorizada dos diretórios compartilhados acordados.
- O único backup de arquivos preexistentes permitido é local, protegido e verificável, de diretórios compartilhados expressamente acordados e identificados no inventário, fora do conjunto proibido acima; a origem deve permanecer intacta e sua integridade deve ser conferida antes e depois da cópia. Esses diretórios não podem ser restaurados neste procedimento.
- Segredos não podem entrar no Git, no chat, em comandos persistidos, em logs ou neste documento.
- Não criar `.env`, não reformatar nem substituir `api/config.php` e não inferir seu contrato a partir da documentação histórica.
- Não preparar uploads, links de dados compartilhados, API, sessão, CORS, autenticação ou qualquer fluxo funcional da aplicação.

### 4.3 Nginx e PHP-FPM: somente candidatos privados e fixture local temporária

Após os gates, preparar somente candidatos privados de Nginx/PHP-FPM e validar a sintaxe. Se indispensável, usar uma fixture sintética temporária em loopback/socket Unix, com `start`/`reload` exclusivamente temporário e controlado de Nginx/PHP-FPM, sem `enable`, autostart, serving do ERP, exposição externa ou conflito, restaurando o estado anterior ao final. Não direcionar o webroot ao checkout privado. A autorização não inclui QA funcional ou tráfego público.

As configurações existentes devem permanecer intactas. Se não for possível demonstrar que o arquivo candidato não será carregado automaticamente, que não haverá autostart/listener e que não haverá conflito com a configuração existente, a preparação deve parar.

### 4.4 PostgreSQL local vazio, sem ERP

O PostgreSQL local vazio pertence ao escopo autorizado somente após o Gate de acesso/inventário e seu gate local específico. Instalar/configurar e, somente depois de comprovar bind exclusivo em loopback, `pg_hba` local-only, firewall sem exposição pública, ausência de conflito ou exposição indevida e acesso administrativo/recuperação ainda funcional, iniciar/habilitar o serviço local é permitido; ele poderá permanecer ativo exclusivamente local-only. Esta autorização não cria listener público nem autoriza iniciar ou habilitar Nginx/PHP-FPM.

A condição mínima do banco de preparação é:

- banco local vazio chamado `erp_condominios`, sem dados de aplicação;
- papel `erp_app` limitado a `LOGIN` e `CONNECT` somente, com `CONNECT` concedido apenas a essa role;
- `CONNECT` e `TEMPORARY` explicitamente revogados de `PUBLIC`, sem grants adicionais ao `PUBLIC` decorrentes da preparação;
- `erp_app` sem memberships, sem quaisquer atributos além de `LOGIN` (inclusive sem `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION` ou `BYPASSRLS`), sem ownership, `CREATE`, `USAGE`, `TEMPORARY`, grants em schema/objeto ou qualquer privilégio de DML;
- nenhuma tabela, view, função, sequência, extensão, migration, importação ou alteração de dados;
- nenhum acesso remoto ou listener público;
- segredo do papel fora do Git, chat e logs, com exposição somente ao operador autorizado.

O único teste permitido é um teste local, separado do ERP, em PHP com PDO/PDO_PGSQL, limitado a `SELECT 1`. O teste deve ser descartável, não pode usar `api/config.php`, configuração da aplicação, API, sessão, dados de moradores ou qualquer conexão funcional do ERP. Seu resultado deve registrar apenas sucesso técnico ou falha e metadados não sensíveis, nunca o segredo.

A existência desse banco vazio não representa migração, homologação do ERP, compatibilidade funcional ou autorização do Gate 2.

### 4.5 Backup, restauração e reversibilidade restrita

- Não fazer backup externo nem backup/restore de MySQL/HostGator, uploads, dados ou configurações preexistentes. A exceção única para arquivos preexistentes é o backup local, protegido e verificável, dos diretórios compartilhados expressamente acordados e inventariados, sem alterar a origem ou abranger todo o volume.
- O backup local, protegido e verificável do PostgreSQL vazio de preparação é obrigatório; qualquer backup ou teste técnico de restauração local isolada relacionado ao banco deve abranger exclusivamente esse banco, sem dados de aplicação.
- Quando houver diretórios compartilhados expressamente acordados e inventariados, sua cópia/backup local protegido e verificável é obrigatória, sem alterar a origem ou abranger todo o volume.
- Antes de qualquer alteração local estritamente autorizada em arquivo de configuração, o único snapshot permitido é local, root-only e limitado aos arquivos de configuração que efetivamente serão alterados. Não incluir webroot, dados, uploads, chaves, dumps ou todo o volume.
- Para os diretórios compartilhados acordados, registrar origem e destino privado, preservar permissões/metadados necessários, conferir a integridade da cópia e interromper se a origem mudar ou a verificação falhar; não restaurar esses diretórios em qualquer destino nem sobre a origem como parte desta preparação.
- A reversibilidade desta preparação consiste em manter candidatos privados, não ativar Nginx/PHP-FPM nem novos listeners, manter o PostgreSQL apenas local-only quando seu gate permitir e conseguir restaurar apenas os arquivos de configuração autorizadamente alterados. Não há rollback de aplicação, release, `current` ou banco de produção neste plano.

## 5. Convenção de diretório autorizada

Os caminhos abaixo são apenas uma convenção futura e precisam ser confirmados no inventário; não representam estado atual e não constituem autorização de criação:

```text
<BASE_DIR confirmado e privado, fora do webroot>/
└── source/       # único clone autorizado: completo, privado e com histórico Git
```

Não criar `releases/`, `current`, webroot de aplicação, área de uploads, cópia de `api/config.php` ou qualquer link de publicação como parte desta preparação. O Nginx não deve usar `source/` como raiz e nenhum arquivo deste plano deve ser servido.

## 6. Plano condicional após os gates — não executado

As subseções seguintes descrevem os passos autorizados da preparação, condicionados ao cumprimento dos gates aplicáveis, e seus resultados esperados. Elas não registram execução: cada passo somente pode começar após os gates indicados e dentro das salvaguardas abaixo. Esta seção não exige autorização nova nem altera as proibições de produção.

### 6.1 Validar acesso e inventário

Registrar a autorização, o operador, a fingerprint validada e o inventário read-only completo. O inventário deve cobrir sistema, discos, usuários, permissões, serviços, listeners, autostart, Nginx, PHP-FPM, PostgreSQL, logs, webroot, diretórios persistentes e firewall. A coleta inicial é read-only. Depois dos gates, esta autorização cobre apenas o hardening restritivo de SSH/firewall da seção 6.2, a fixture temporária e local de Nginx/PHP-FPM da seção 6.4 e, somente após seu gate local, o PostgreSQL local-only da seção 6.5; não autoriza start/reload genérico, novo listener HTTP/HTTPS, enable ou autostart.

Comparar o inventário com o escopo declarado. Diante de conflito, comprometimento ou impossibilidade de comprovar que o hardening, a fixture temporária de Nginx/PHP-FPM ou o PostgreSQL local-only ocorrerão conforme seus gates, sem novo listener HTTP/HTTPS, enable, autostart ou exposição pública, marcar `BLOCKED` e encerrar a preparação.

### 6.2 Aplicar hardening restritivo de SSH e firewall

Somente após o acesso autenticado, a fingerprint verificada e o inventário read-only completo e confiável, registrar o estado efetivo de SSH, firewall, listeners, rotas de recuperação e regras existentes. Antes de alterar SSH ou firewall, validar uma segunda sessão administrativa independente, mantendo a primeira aberta, e confirmar uma via de recuperação autorizada (por exemplo, console do provedor ou caminho administrativo alternativo) que não dependa do acesso que será alterado.

Aplicar apenas regras restritivas e necessárias: SSH por chave e limitado às origens administrativas autorizadas, sem login público de root por senha, sem `0.0.0.0/0` ou `::/0` para TCP/22, sem desabilitar o firewall e sem abrir globalmente portas. Não criar, habilitar ou iniciar novos listeners HTTP/HTTPS, nem qualquer listener do ERP; manter somente listeners não-ERP preexistentes e inventariados, se houver, e preservar serviços, dados e regras não conflitantes existentes. O PostgreSQL deve permanecer somente local e só poderá ser iniciado/habilitado após o gate local da seção 6.5.

Validar depois, sem encerrar a sessão original, a sintaxe e a configuração efetiva do SSH, a conectividade da segunda sessão, a recuperação autorizada, as regras restritivas aplicadas, os listeners e a ausência de exposição pública. Se a segunda sessão, a recuperação, a fingerprint, a regra esperada ou a preservação do ambiente não puder ser confirmada, parar em `BLOCKED / PENDING`, sem bypass ou remediação improvisada.

### 6.3 Receber e verificar o código

Somente após o Gate de acesso/inventário, receber o clone completo do remoto autorizado em `BASE_DIR/source/`, de modo privado e fora do webroot. Preservar o `.git` dentro de `source/` para a verificação de identidade, sem copiar ZIP, credenciais, uploads, dumps, dados ou configuração de produção.

Verificar e registrar o remoto autorizado, o `HEAD` corrente de `origin/main`, o SHA completo, a presença do baseline informado e o diff de commits desde o baseline. Conferir o estado limpo da árvore e registrar somente metadados não sensíveis. Se a identidade ou o SHA não coincidirem com a autorização, parar.

A revisão do código pode registrar achados estáticos sobre SPA Vanilla JS, PHP 8.x e manifests, mas não pode executar a aplicação, consultar API, testar sessão, usar `api/config.php`, acessar dados, inferir compatibilidade com PostgreSQL ou transformar a revisão em deploy.

### 6.4 Preparar candidatos Nginx/PHP-FPM

Produzir, se necessário, cópias candidatas privadas e não carregadas, sem alterar a configuração ativa. A fixture Nginx/PHP-FPM é exclusivamente temporária: realizar somente validações sintáticas locais e, se indispensável, start/reload controlado em loopback/socket Unix, sem enable, autostart, ativação permanente, listener público, publicação ou tráfego externo, com restauração do estado anterior e remoção da fixture ao final.

Registrar o resultado técnico sem conteúdo sensível. A etapa fica `PENDING` se os binários, caminhos, permissões ou isolamento do candidato não puderem ser comprovados. Não realizar teste HTTP, browser, API, sessão ou servir a aplicação.

Se uma fixture sintética for necessária, o start/reload de Nginx/PHP-FPM poderá ser temporário, controlado e somente em loopback/socket Unix, sem autostart, exposição externa ou conflito; restaurar o estado anterior e remover a fixture ao final. Isso não autoriza servir o ERP nem testar sua funcionalidade.

### 6.5 Preparar o PostgreSQL vazio e o teste isolado

Somente se a instância local já estiver em condição segura e o inventário autorizar, preparar o banco vazio `erp_condominios` com a role mínima `erp_app`, limitada a `LOGIN` e `CONNECT`, observando todas as restrições da seção 4.4. Antes de iniciar/habilitar o PostgreSQL, comprovar bind exclusivo em loopback, `pg_hba` local-only, ausência de conflito e regras do firewall local/UFW e do provedor negando acesso externo à `5432` em IPv4 e IPv6; o serviço poderá permanecer ativo apenas para uso local. Não abrir listener, não criar schema/objeto, não executar DML e não conectar o ERP.

Após preparar o banco vazio, é obrigatório criar e validar seu backup local e realizar a restauração técnica isolada em destino local vazio, sem dados de aplicação. O teste técnico separado poderá usar PHP/PDO_PGSQL e somente `SELECT 1`; ele não pode carregar configuração ou código funcional do ERP e não é um teste de aplicação.

### 6.6 Preparar backup local e restauração verificável

Preparar, somente após o inventário e a confirmação dos caminhos, um destino local privado e protegido. É obrigatório produzir e validar backup local do PostgreSQL vazio. Para cada diretório compartilhado expressamente acordado e identificado no inventário, é obrigatória a cópia/backup local protegido e verificável, preservando a origem; não incluir MySQL/HostGator, `uploads/`, dados de moradores, `api/config.php` ou outros dados proibidos. Registrar origem, destino, permissões/metadados necessários e verificação de integridade antes/depois. Não fazer backup externo, snapshot amplo ou restauração sobre a origem.

Validar a integridade do backup do PostgreSQL vazio e executar somente o teste técnico de restauração desse banco em destino local isolado e vazio, sem dados de aplicação; não restaurar os diretórios compartilhados, apenas verificar a integridade de sua cópia local sem alterar a origem. Não chamar esse teste de restore drill de produção. Qualquer alteração na origem, conflito, perda de metadados ou falha de verificação exige parar e marcar `BLOCKED / PENDING`.

### 6.7 Registrar evidências

A evidência futura deve conter apenas:

- autorização, operador, fingerprint e data/hora;
- resultado do inventário read-only e decisão de parada ou continuidade;
- `BASE_DIR`, remoto autorizado, `HEAD` corrente de `origin/main`, SHA completo, baseline e diff de commits;
- confirmação de que o clone está completo, privado e somente em `source/`;
- resultado dos testes sintáticos locais de candidatos Nginx/PHP-FPM;
- estado vazio do PostgreSQL, limites de `erp_app` e resultado técnico do `SELECT 1` isolado, sem segredo;
- resultado do backup e do teste técnico de restauração local isolada do PostgreSQL vazio e, se autorizado e acordado, origem, destino e verificação de integridade da cópia local dos diretórios compartilhados, sem restaurar esses diretórios;
- resultado do hardening SSH/firewall, incluindo segunda sessão, recuperação, regras efetivas e validação posterior, sem registrar segredos;
- relação exata de configurações que eventualmente receberiam snapshot local root-only;
- bloqueios, pendências e divergências.

Nunca registrar senha, token, chave, conteúdo de `api/config.php`, dump, upload, dado de morador ou conteúdo de log sensível.

## 7. Requisitos futuros fora deste plano e não executáveis

Os itens abaixo permanecem proibidos neste escopo. São requisitos para uma eventual revisão independente, não instruções de execução:

- criar/ativar release, `current`, link de webroot ou qualquer publicação;
- fazer deploy, servir o app, alterar DNS, fazer cutover ou abrir disponibilidade pública;
- abrir listener público do ERP ou expor PostgreSQL; serviços locais podem operar em loopback/socket Unix;
- executar rollback de aplicação, release, `current` ou banco de produção;
- executar smoke tests, testes HTTP/browser, API, autenticação, sessão, CORS, consulta funcional ou qualquer teste do ERP;
- acessar, copiar, testar, importar ou fazer backup/restore de MySQL/HostGator, uploads, dados preexistentes ou `api/config.php`; a única exceção documental é a cópia local, protegida e verificável dos diretórios compartilhados expressamente acordados, sem alterar sua origem;
- criar schema, objetos, migrations, importar dados, alterar schema ou executar DML no contexto do ERP;
- usar bypass ou aceitar fingerprint não validada; hardening restritivo SSH/firewall está dentro do escopo após validação de recuperação;
- fazer backup externo ou snapshot amplo da VM.

Esses limites não podem ser waived nem inferidos do baseline. O checklist operacional de preparação não autoriza deploy/cutover.

## 8. Pendências e bloqueadores

- **Acesso à VM:** SSH, fingerprint, chaves e usuário operacional ainda não confirmados.
- **Auditoria do ambiente:** sistema efetivo, firewall, discos, permissões, Nginx, PHP-FPM, PostgreSQL, listeners, autostart, logs e serviços ainda desconhecidos.
- **Preservação:** não foi demonstrado que a preparação pode ocorrer sem conflito com serviços ou dados existentes.
- **BASE_DIR:** diretório administrativo privado, fora do webroot, ainda não confirmado.
- **Código:** nenhum clone foi recebido; `origin/main`, `HEAD` corrente, SHA e diff desde o baseline ainda não foram verificados na VM.
- **Configuração:** candidatos Nginx/PHP-FPM e seus testes sintáticos locais ainda não existem; nenhuma configuração foi carregada.
- **PostgreSQL:** banco local vazio, limites de `erp_app` e teste isolado PDO/PDO_PGSQL `SELECT 1` ainda não foram preparados ou executados.
- **Dados e segredos:** MySQL/HostGator, uploads, `api/config.php`, dados preexistentes e credenciais permanecem fora do escopo e não foram acessados.
- **Gate 2:** **`CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`**; migrations, schema, importação, DML, conexão funcional e mudança de banco continuam bloqueados.
- **Serviços:** listeners não-ERP preexistentes e inventariados, se houver, devem ser os únicos mantidos; novos listeners HTTP/HTTPS, enable e autostart não são autorizados. Nginx/PHP-FPM permanecem restritos a fixture local temporária e controlada em loopback/socket Unix, sem serving; PostgreSQL só pode iniciar/habilitar após seu gate local e pode permanecer ativo exclusivamente local-only.
- **Backup:** nenhum backup externo é permitido; o único teste de restauração autorizado é técnico, local, isolado e do PostgreSQL vazio, sem dados de aplicação. Os diretórios compartilhados expressamente acordados podem ter somente cópia local protegida e verificável, preservando a origem e sem restauração.

## 9. Estado de encerramento desta tarefa

Este documento permanece uma proposta futura (`PLANEJAMENTO FUTURO / BLOCKED / NOT_EXECUTED`) e não autorização de deploy. Nenhuma ação operacional foi executada: não houve SSH, acesso à API da Hetzner, shell remoto, instalação, alteração de SSH ou firewall, ativação de serviço/listener, clone na VM, preparação de banco, teste PDO, configuração Nginx/PHP-FPM, publicação, backup, restore, migration, conexão do ERP ou acesso a dados.

O resultado operacional permanece pendente: nenhum SSH autenticado foi obtido e nenhum comando remoto foi executado. A preparação autorizada aguarda validação de acesso/fingerprint/inventário. Gate 2 segue fechado; nenhum segredo ou dado pessoal foi incluído.
