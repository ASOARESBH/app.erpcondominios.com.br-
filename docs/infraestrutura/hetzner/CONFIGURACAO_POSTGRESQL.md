# Proposta de configuração do PostgreSQL

**Status atual:** `BLOCKED / NOT_EXECUTED` — sem acesso administrativo autenticado, fingerprint confiável e inventário read-only completo e confiável.
**Escopo:** hardening restritivo, atualizações e preparação privada de serviços locais, incluindo PostgreSQL vazio, condicionados a fingerprint/acesso validados e inventário read-only completo, preservando serviços/dados; migração e integração do ERP não autorizadas.
**Gate 2:** `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`.

> A preparação autorizada inclui hardening restritivo, atualizações, serviços locais, PostgreSQL vazio, role mínima, teste PDO isolado, clone privado completo fora do webroot e cópia local protegida exclusivamente dos paths de diretórios explicitamente acordados e enumerados no inventário/janela autorizada; nenhum path pode ser inferido e, sem lista explícita, nenhuma cópia de diretório é autorizada. O snapshot mínimo/local/privado de configuração preexistente é uma exceção de rollback e permanece separado de backup/restore. Tudo isso depende de acesso/fingerprint e inventário confiáveis. Nada foi executado por falta de acesso autenticado. Ver [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md).

## 1. Objetivo

Definir a configuração restritiva e auditável para preparar PostgreSQL local na VM. A execução deve ser privada, reversível e condicionada à validação de acesso/fingerprint e ao inventário read-only completo e confiável, preservando serviços, arquivos e dados existentes. PostgreSQL é o destino definido; banco vazio e role da aplicação limitada a `LOGIN` e `CONNECT` só podem ser preparados depois desses gates e não foram executados porque não houve acesso administrativo autenticado. A preparação também pode incluir hardening restritivo, atualizações, serviços locais, clone completo e privado fora do webroot após os gates, já incluído nesta autorização e sem autorização adicional, waiver do Gate 2 ou publicação, e cópia protegida somente dos paths de diretórios explicitamente acordados, sem alterar as origens. O Gate 2 permanece `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED` para schema de aplicação, dados, migrations, compatibilidade e conexão do ERP. Um teste PHP/PDO_PGSQL isolado contra o banco vazio pode validar `SELECT 1`; não é teste nem conexão do ERP. A política de versão PHP está em [INSTALACAO_SERVICOS.md](INSTALACAO_SERVICOS.md).

A proposta cobre:

- escuta somente local (`listen_addresses = 'localhost'`);
- acesso de clientes restrito no `pg_hba.conf`;
- porta TCP `5432` sem exposição pública;
- banco vazio com codificação UTF-8, após inventário e escolha dos parâmetros locais;
- timezone ainda sujeito a aprovação;
- separação entre administração local e uma role de aplicação com login de teste e privilégio mínimo somente no banco vazio;
- preparação de Nginx/PHP-FPM sem habilitar autostart nem servir a aplicação externamente; se necessário, start/reload controlado somente para fixture sintética em `127.0.0.1`/socket Unix, removida ao final;
- clone completo e privado do remoto oficial `https://github.com/ASOARESBH/app.erpcondominios.com.br-`, após todos os gates cumulativos, dentro desta autorização e sem autorização adicional, somente em `source/` fora do webroot; sem waiver do Gate 2 e sem publicação. Registrar exatamente a URL remota, o SHA observado em `origin/main`, o SHA do `HEAD` efetivamente verificado, o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e os commits/diferenças desde o baseline, sem `release/` ou `current/`;
- logs, backup/restore isolado do banco vazio, cópia protegida somente dos paths de diretórios explicitamente acordados e verificações de integridade/saúde; snapshots mínimos de configuração preexistente pertencem exclusivamente ao rollback e não ao backup/restore;
- hardening restritivo, atualizações e instalação condicionados a acesso seguro, auditoria read-only da VM, demonstração de prevenção de autostart/listener público e mudança reversível; o PostgreSQL só pode iniciar/habilitar após bind, `pg_hba.conf` e firewall local-only validados; conexão da aplicação condicionada à compatibilidade e ao Gate 2.

Nenhuma operação foi executada nesta sessão. Após acesso/fingerprint validados e inventário completo e confiável, está autorizada, de forma condicionada, a aplicação de hardening restritivo e atualizações, o PostgreSQL local e vazio com início/habilitação somente após bind, `pg_hba.conf` e firewall local-only validados, serviços locais com fixture controlada, o clone completo e privado em `source/` fora do webroot e a cópia protegida somente dos paths de diretórios explicitamente acordados. O clone está dentro desta autorização após os gates, sem autorização adicional, waiver do Gate 2 ou publicação. Se houver conflito ou risco de exposição, abortar. Schema, migration, dados e conexão do ERP permanecem bloqueados pelo Gate 2.

### 1.1 Limites de autorização

Os limites abaixo são requisitos de segurança e não comandos de execução atual:

- não contornar acesso nem usar SSH inseguro; alterações restritivas de SSH/firewall estão autorizadas após validar origem administrativa, segunda sessão e recuperação;
- não criar listener público do ERP, alterar DNS, usar `release/` ou `current/`, fazer deploy/publicação ou servir a aplicação externamente; PostgreSQL pode ser iniciado/habilitado somente após validação local-only de bind, `pg_hba.conf` e firewall; Nginx/PHP-FPM só podem ter start/reload controlado para fixture sintética em `127.0.0.1`/socket Unix, sem serving externo e sem habilitar autostart da aplicação;
- não consultar/exportar/copiar/backup de linhas do MySQL/HostGator, criar schema, aplicar migrations, importar dados, fazer backup externo ou cutover;
- não executar testes remotos do ERP. Testes locais de infraestrutura e `PDO SELECT 1` em banco vazio são permitidos; verificar `listen_addresses`, sockets, `pg_hba.conf`, `ss` e firewall local;
- o clone completo e privado faz parte desta autorização após os gates cumulativos, exclusivamente em `source/` fora do webroot, a partir do remoto oficial `https://github.com/ASOARESBH/app.erpcondominios.com.br-`, sem autorização adicional, waiver do Gate 2 ou publicação. Registrar a URL exata, o SHA de `origin/main`, o SHA do `HEAD` efetivamente verificado, o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e os commits/diferenças desde o baseline.

## 2. Estado e evidências disponíveis

### 2.1 Evidências documentais

- O `AI-CONTEXT/DATABASE.md` documenta o banco atual como **MySQL hospedado na HostGator**.
- A documentação do ERP descreve backend em PHP 8.x; a compatibilidade efetiva da aplicação com PostgreSQL não foi comprovada.
- O usuário definiu PostgreSQL como destino futuro; a major version, extensões, collation/locale e timezone ainda requerem seleção técnica após confirmar o Ubuntu efetivo e testar compatibilidade.
- Candidato documental em 2026-11-06: PostgreSQL 18.6, a minor corrente publicada para a major 18 suportada; se a VM for confirmada como Ubuntu 24.04/Noble, o repositório oficial PGDG a lista entre as distribuições atendidas. Confirmar OS, arquitetura, pacote assinado e política de atualização antes de instalar; isso não é compatibilidade aprovada do ERP. [1] [2]
- O banco e o schema PostgreSQL propostos ainda não existem como configuração validada nesta entrega.

### 2.2 Estado de ambiente

Não houve acesso à VM nesta sessão. Portanto, permanecem desconhecidos e não devem ser tratados como fatos:

- sistema operacional e versão efetivamente instalados;
- versão do PostgreSQL, se houver, e seus pacotes;
- estado de serviço, socket, porta e arquivos de configuração;
- regras de firewall;
- usuários, roles, métodos de autenticação e permissões existentes;
- recursos, discos, consumo, logs e rotinas de backup;
- disponibilidade de SSH, console ou API de infraestrutura.

A VM declarada é `<hostname privado omitido>` (`<IP privado omitido>`, Helsinki, CPX32, 4 vCPU, 8 GB RAM, 160 GB); Ubuntu 24.04 LTS foi escolhido como imagem, mas o sistema efetivamente instalado ainda não foi confirmado. Esses dados não são evidência de ambiente auditado.

Houve uma tentativa de navegação à console Hetzner, bloqueada em controle de acesso do provedor antes da autenticação. Nenhuma instalação, alteração de configuração, conexão, teste, backup, migration ou deploy foi executado.

## 3. Decisões de proposta

As decisões abaixo são **propostas condicionais**, não mudanças aplicadas:

| Tema | Proposta | Situação |
|---|---|---|
| Endereço de escuta | `listen_addresses = 'localhost'`. Não escutar em endereço público. | Proposto; depende de validação local e de IPv4/IPv6 do host. |
| Porta | Usar `5432` somente para acesso local ao serviço, sem regra de exposição pública. | Proposto; o estado atual da porta é desconhecido. |
| Banco | Criar `erp_condominios` vazio somente após o gate pré-instalação; sem schema/objetos de aplicação nem dados. | Preparação condicional; não executada por falta de acesso autenticado. |
| Codificação | Criar o banco vazio com `UTF8`; verificar `client_encoding` em conexão técnica isolada, sem conectar o ERP. | Proposto; depende de acesso e seleção do locale. |
| Collation/locale | Não escolher ainda. A decisão deve considerar a versão aprovada, o locale disponível no host e as regras de ordenação esperadas pelo ERP. | Não aprovado. |
| Timezone | Propor `UTC` como timezone padrão do cluster/serviço. O timezone de sessão e o tratamento funcional no ERP devem ser definidos após inspeção do código e dos contratos de data/hora. | UTC é proposta técnica para ambiente novo; semântica da aplicação permanece pendente. |
| Role administrativa | Manter uma role administrativa separada da aplicação, para manutenção autorizada e auditada. | Proposto; sem criação ou credencial. |
| Role da aplicação | Criar `erp_app` com `LOGIN` somente após provisionar segredo por canal seguro; conceder somente `CONNECT` em `erp_condominios`, revogar `CONNECT,TEMPORARY` de `PUBLIC` nesse banco e manter a role sem memberships, atributos privilegiados, ownership, `CREATE`, `USAGE` ou DML. Não configurar o ERP com essa role. | Preparação condicional; não executada. |
| Extensões | Não instalar nem habilitar extensões sem aprovação explícita de compatibilidade e segurança. | Não aprovado. |
| Migração | Não migrar dados MySQL nem aplicar migrations. | Gate 2 fechado. |

### 3.1 Endereço de escuta e exposição de rede

A configuração-alvo é `listen_addresses = 'localhost'`, com `port = 5432` se essa porta for mantida pela decisão de arquitetura. O serviço pode iniciar e ser habilitado somente depois de comprovados bind exclusivo, `pg_hba.conf` restrito e firewall local-only; deve permanecer acessível apenas pelo próprio host, via loopback e/ou socket Unix conforme o desenho validado.

A restrição deve ser em camadas:

1. o PostgreSQL deve escutar somente em `localhost`, nunca em `0.0.0.0` ou em endereço público;
2. o firewall deve manter `5432/tcp` fechado para a Internet e para redes não autorizadas;
3. o `pg_hba.conf` deve permitir somente o banco, as roles e as origens locais explicitamente aprovadas;
4. a evidência deve ser obtida somente por inspeção local de `listen_addresses`, sockets, `pg_hba.conf`, `ss`, firewall e estado de ativação do serviço; não executar testes ou probes remotos.

Nenhuma dessas configurações foi aplicada ou verificada.

### 3.2 `pg_hba.conf` restrito

Se o arquivo precisar ser alterado, deve haver somente um snapshot mínimo, local e privado do arquivo de configuração estritamente antes dessa alteração, seguido de revisão e rollback reversível. Esse snapshot é uma exceção de rollback, não compõe backup/restore e não autoriza cópia de outros arquivos. A cópia de diretórios, se houver, limita-se aos paths explicitamente acordados e enumerados no inventário/janela autorizada; sem essa enumeração, não copiar diretórios. Não copiar cluster, dados, roles globais ou arquivos fora dos paths acordados. O princípio é negar o que não for necessário e permitir somente combinações explícitas de:

- banco `erp_condominios`;
- role administrativa ou `erp_app` somente para health check local autorizado;
- origem local aprovada (`127.0.0.1/32`, `::1/128` e/ou socket local, conforme o modo escolhido);
- método de autenticação aprovado pela política de segurança e compatível com a versão homologada. A role `erp_app` não deve receber grants de schema/objeto.

Um modelo ilustrativo, **não aplicável diretamente**, é:

```text
# Regras finais dependem da política de autenticação aprovada.
# Permitir somente erp_app/erp_admin no banco erp_condominios e origem local.
# Rejeitar demais bancos, roles e origens, inclusive redes públicas.
```

A escolha do método de autenticação, inclusive o uso de SCRAM, deve ser validada contra a versão aprovada do PostgreSQL, o cliente PHP e a política de segredos. Não inserir senhas, tokens ou credenciais neste documento, no repositório ou em comandos de procedimento.

A ordem das regras deve ser revisada, pois o PostgreSQL utiliza a primeira regra compatível. O acesso administrativo não deve depender da role da aplicação.

### 3.3 Banco, codificação e timezone

O banco vazio `erp_condominios` poderá ser preparado após os gates técnicos, que exigem:

- confirmação da identidade/fingerprint da VM, Ubuntu efetivo e inventário sem conflito com serviços ou dados existentes;
- preservação dos serviços e dados existentes e demonstração de prevenção de autostart e de listener público;
- interrupção imediata se houver conflito, comprometimento ou impossibilidade de demonstrar essas prevenções;
- confirmação de uma versão PostgreSQL ainda suportada na distribuição escolhida;
- aprovação de collation/locale;
- aprovação da política de timezone;
- validação do caminho de backup/restore do banco novo vazio, sem tratar isso como backup de origem ou produção;
- registro da mudança e da janela autorizada.

O destino PostgreSQL foi definido; a preparação isolada não abre nem waiva o Gate 2, que permanece `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`. Collation/locale e timezone são decisões locais ainda pendentes e não devem ser inferidos do host, do PHP ou do MySQL atual. A codificação pretendida é `UTF8`.

O banco deve permanecer vazio nesta fase preparatória. Não há schema definitivo, tabelas, extensões ou migrations aprovadas para PostgreSQL.

Na consulta oficial realizada em 06/11/2026, PostgreSQL 18.6 constava como minor corrente da major 18 e a documentação do PGDG listava Ubuntu 24.04/Noble. A versão 18.6 é apenas candidata: confirmar a versão efetivamente disponível, pacote assinado, repositório e suporte do OS real imediatamente antes da instalação. [1] [2] [3]

### 3.4 Roles e privilégio mínimo

Os nomes abaixo são nomes lógicos de proposta, sujeitos à aprovação; não representam usuários existentes:

- `erp_admin`: identidade administrativa local separada, usada somente por pessoal autorizado para manutenção e operações controladas. Não deve ser usada pela aplicação web nem ter credenciais documentadas aqui.
- `erp_app`: role de conexão para teste de conectividade com `LOGIN`, sem memberships, privilégios de superusuário, criação de roles/bancos, ownership, `BYPASSRLS` ou alteração de configuração. Em `erp_condominios`, recebe somente `CONNECT`; `CONNECT,TEMPORARY` devem ser revogados de `PUBLIC`, e não pode haver `USAGE`, `CREATE` ou DML em schema/objetos.

A role `erp_app` somente poderá receber grants adicionais após o Gate 2 ser formalmente reaberto e o schema ser aprovado: conexão ao banco, uso do schema aprovado e operações nos objetos efetivamente utilizados. Privilégios de DDL, criação de objetos, administração de roles e acesso a outros bancos não devem ser concedidos. Eventuais permissões em sequências, funções ou views deverão ser concedidas individualmente quando demonstradas como necessárias; nada disso é autorização atual.

A role administrativa não deve compartilhar credencial com `erp_app`. Nenhuma senha ou segredo deve ser enviado pelo chat, registrado no Git ou em logs. O `LOGIN` de `erp_app` só poderá ser criado após existir mecanismo seguro de segredo; durante Gate 2 fechado, seu único uso possível é um teste local separado de transporte/autenticação com `SELECT 1`, sem conexão da aplicação e sem acesso a schema/objetos. A evidência deve verificar grants efetivos, memberships, atributos, ownership e privilégios herdados; remover/revogar a credencial de teste se a política exigir após verificação.

A compatibilidade do código PHP, da camada de conexão e de todas as consultas com PostgreSQL continua pendente. Portanto, não é possível declarar os grants finais nem autorizar a conexão da aplicação.

## 4. Logs e observabilidade

A configuração futura deve registrar, no mínimo, eventos de inicialização e erro do PostgreSQL, falhas de autenticação e, conforme a política de privacidade e volume, conexões e desconexões. A retenção e rotação devem ser definidas junto aos logs do host, sem registrar senhas, tokens ou dados pessoais desnecessários.

Antes da ativação, devem ser aprovados:

- destino dos logs e permissão de leitura;
- retenção e rotação;
- alertas para falhas repetidas de autenticação, indisponibilidade e falta de espaço;
- limiar de duração de consultas, evitando exposição indevida de dados e volume excessivo;
- procedimento de revisão e resposta a eventos.

Não instalar `pgaudit` ou outra extensão de auditoria sem aprovação específica de versão, impacto e necessidade. Nenhum log foi habilitado ou inspecionado nesta sessão.

## 5. Backup e restauração

Não existe, nesta entrega, uma rotina de backup PostgreSQL ou de arquivos configurada ou validada. Nesta preparação, somente após os gates, podem ocorrer três categorias locais e privadas, independentes entre si: (a) dump/restore do banco PostgreSQL novo e vazio; (b) snapshot mínimo, local, privado e reversível dos arquivos de configuração estritamente alterados, exclusivamente como controle de rollback e não como backup/restore; e (c) cópia protegida exclusivamente dos paths de diretórios explicitamente acordados e enumerados no inventário e na janela autorizada. Sem paths explicitamente acordados, nenhuma cópia de diretório é autorizada. A cópia dos diretórios acordados não altera, remove ou limpa a origem e não autoriza acessar, copiar ou fazer backup de linhas do MySQL/HostGator. Não copiar cluster PostgreSQL, roles globais preexistentes, arquivos fora dos paths acordados ou qualquer conteúdo externo.

Para os diretórios compartilhados, registrar em local privado a lista exata dos paths e as origens explicitamente autorizadas, o destino protegido, permissões, data/hora, tamanho e manifesto de hashes; verificar a integridade da cópia contra a origem sem modificá-la, sem restaurar os diretórios nesta etapa. Não ampliar a lista por inferência e não copiar qualquer path não enumerado. Uma falha de integridade interrompe a preparação. O snapshot de configuração para rollback não integra essa cópia nem o backup/restore. Evidências, cópias e manifestos não devem entrar no Git, na documentação pública ou em diretório servido.

O procedimento futuro de dump/restore vazio deverá ocorrer apenas em ambiente isolado, sem dados de origem, sem conversão do MySQL/HostGator e sem pretender RPO/RTO produtivo. Frequência, retenção, criptografia, destino externo, credenciais, monitoramento e recuperação de produção são requisitos futuros separados, não autorização desta preparação.

## 6. Health checks propostos

Somente após os gates de acesso/fingerprint, inventário read-only, preservação e prevenção de autostart/listener público, e em janela controlada, os checks abaixo poderão avaliar a preparação sem alterar dados. Eles são evidências locais; não há teste ou probe remoto:

1. **Readiness local:** verificar localmente disponibilidade na porta `5432` e/ou socket aprovado, `listen_addresses`, sockets, `pg_hba.conf`, `ss` e firewall local; confirmar ausência de listener público.
2. **Banco e grants efetivos:** confirmar que `erp_condominios` está vazio, que `PUBLIC` não possui `CONNECT,TEMPORARY` nesse banco e que `erp_app` tem somente `LOGIN` e `CONNECT`, sem memberships, atributos privilegiados, ownership, `CREATE`, `USAGE` ou DML, inclusive por privilégios herdados.
3. **Dump/restore, rollback e diretórios:** após os gates, é obrigatório realizar o exercício isolado de backup/restauração do banco novo vazio; separadamente, criar apenas snapshots mínimos, locais e privados de configuração estritamente alterada, exclusivamente para rollback; copiar protegidamente somente os paths de diretórios explicitamente acordados, gerar manifesto/hash e validar a integridade da cópia sem restaurar esses diretórios. Não incluir cluster, roles globais, dados do MySQL/HostGator, paths fora da lista explícita ou qualquer origem externa.
4. **Teste PHP/PDO_PGSQL separado:** executar localmente, sem carregar `api/config.php`, sem iniciar a aplicação e sem configurar o ERP, um teste técnico com `SELECT 1` contra o banco vazio; esse teste não valida compatibilidade do ERP.
5. **Serviços locais controlados:** verificar que o PostgreSQL só foi iniciado/habilitado depois da comprovação de bind, `pg_hba.conf` e firewall local-only; se Nginx/PHP-FPM forem iniciados/recarregados, confirmar que isso ocorreu somente para fixture sintética em `127.0.0.1`/socket Unix, sem serving externo, sem autostart da aplicação e com a fixture removida ao final.
6. **Clone privado:** após todos os gates cumulativos, o clone completo do remoto oficial `https://github.com/ASOARESBH/app.erpcondominios.com.br-` está dentro desta autorização, sem autorização adicional, sem waiver do Gate 2 e sem publicação. Confirmar o clone somente em `source/` fora do webroot, privado, com a URL exata, o SHA de `origin/main` e o SHA do `HEAD` efetivamente verificado; registrar o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e descrever os commits/diferenças desde ele; não executar deploy ou publicação.
7. **Evidências:** registrar somente evidências sem segredos, tokens, chaves, dados, uploads ou conteúdo de serviços existentes, incluindo apenas manifestos/hash necessários para provar integridade dos diretórios acordados.

Os procedimentos concretos não são comandos de autorização atual e devem ser definidos somente após os gates. Nenhum health check foi executado.

## 7. Procedimento futuro condicionado a acesso e autorizações

O procedimento abaixo descreve preparação autorizada ainda não executada. Não contornar bloqueios de autenticação/identidade; cada mudança requer acesso/fingerprint validados, auditoria read-only, preservação dos existentes e rollback. Sem esses gates, parar:

1. **Validar acesso e host:** confirmar usuário administrativo, fingerprint por canal independente, acesso de recuperação, janela e rollback. Não contornar controle de acesso do provedor ou controles de autenticação. O Gate 2 de migração continua fechado e não autoriza conexão do ERP.
2. **Auditar sem alterar:** confirmar sistema operacional, versão suportada, discos, serviço, processos, portas, firewall, usuários, configurações, logs e os diretórios compartilhados a preservar. Preservar inventário e, somente se um arquivo for alterado, um snapshot mínimo, local, privado e reversível desse arquivo, exclusivamente como rollback e separado de backup/restore; não copiar cluster, dados, roles globais, arquivos fora dos paths explicitamente acordados ou uploads não autorizados.
3. **Atualizar, aplicar hardening e selecionar versão:** somente após inventário e validação de recuperação, aplicar atualizações e hardening restritivo aprovados, sem perder acesso administrativo, e escolher major suportada pela distribuição efetiva e a minor atual dessa major; registrar origem do pacote e política de atualização. A versão do ERP alvo só será aprovada após testes posteriores.
4. **Preparar serviço isolado:** somente se todos os gates passarem, preparar PostgreSQL com escuta local/socket e `5432` não pública; antes de iniciar/habilitar o serviço, comprovar bind exclusivo em loopback, `pg_hba.conf` seguro, ausência de conflito e regras do firewall local/UFW e do provedor negando acesso externo à `5432` em IPv4 e IPv6. Se não for possível demonstrar essas prevenções, abortar.
5. **Criar banco vazio:** somente se aprovado, preparar `erp_condominios` com `UTF8` e parâmetros aprovados. Não criar schema de aplicação, tabelas, objetos ou migrations; revogar `CONNECT,TEMPORARY` de `PUBLIC` no banco e impedir `CREATE` no schema padrão.
6. **Criar role mínima:** separar admin da aplicação; se aprovado, criar `erp_app` com `LOGIN` e somente `CONNECT` em `erp_condominios`, sem memberships, atributos privilegiados, ownership, `CREATE`, `USAGE` ou DML. Verificar grants efetivos. Não colocar segredo em Git/chat/log.
7. **Restringir autenticação:** revisar localmente `pg_hba.conf`, permitindo somente administração e teste técnico local aprovados, sem teste remoto e sem conexão da aplicação.
8. **Preparar recuperação vazia e de arquivos acordados:** após os gates, fazer obrigatoriamente dump/restore isolado do banco novo vazio; separadamente, criar snapshots mínimos, locais e privados de configuração estritamente alterada, somente para rollback; copiar protegidamente apenas os paths de diretórios explicitamente acordados, quando a lista estiver definida. Gerar manifesto/hash para a cópia e verificar sua integridade sem alterar a origem; não restaurar os diretórios compartilhados. Não copiar cluster, roles globais, paths fora da lista explícita, dados/uploads não autorizados, MySQL/HostGator ou qualquer conteúdo externo.
9. **Preparar Nginx/PHP-FPM com fixture local:** deixar arquivos e dependências preparados, sem habilitar autostart da aplicação; se necessário, iniciar/recarregar de forma controlada somente para fixture sintética em `127.0.0.1`/socket Unix, sem publicar ou servir a aplicação externamente, e remover a fixture ao final.
10. **Clone privado sem publicação:** após os gates cumulativos, fazer o clone completo e privado do remoto oficial `https://github.com/ASOARESBH/app.erpcondominios.com.br-` apenas em `source/` fora do webroot; esta ação já está autorizada nesta fase, sem autorização adicional e sem waiver do Gate 2. Verificar e registrar a URL exata, o SHA de `origin/main`, o SHA do `HEAD` efetivamente verificado, o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e os commits/diferenças desde o baseline; não usar `release/` ou `current/`, nem fazer deploy/publicação.
11. **Executar checks locais separados:** validar readiness local, grants efetivos, dump/restore vazio, integridade da cópia dos diretórios compartilhados acordados e teste PHP/PDO_PGSQL local com `SELECT 1`; não fazer teste remoto, API, compatibilidade ou conexão do ERP.
12. **Manter integração bloqueada:** não apontar PHP/ERP para PostgreSQL, não criar schema, carregar dados ou aplicar migrations. Compatibilidade ERP e conversão/migração pertencem a gate futuro e não podem reabrir ou waivar o Gate 2 nesta etapa.

Qualquer falha deve interromper a mudança, preservar os registros de diagnóstico permitidos e acionar o rollback aprovado. Não há rollback executável nesta sessão porque nenhuma alteração foi feita.

## 8. Testes e critérios de aceite

O aceite da preparação do serviço vazio e o aceite futuro da aplicação são gates diferentes. Os itens abaixo podem ser usados somente para avaliar localmente serviço/DB vazio após acesso seguro e mudança especificamente autorizada; compatibilidade do ERP, conexão da aplicação, schema, migrations e migração são requisitos futuros e permanecem fora do Gate 2 fechado:

- PostgreSQL escutando somente em `localhost`/loopback e/ou socket local aprovado;
- nenhum `5432/tcp` exposto publicamente no firewall ou no listener do host;
- `pg_hba.conf` restrito a administração e teste técnico local aprovado; nenhum acesso externo/remoto aberto;
- banco vazio, sem schema de aplicação, tabelas, funções, views, migrations ou dados; schema `public` padrão, se presente, sem `CREATE` por `PUBLIC`;
- `erp_app` sem `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION`, `BYPASSRLS`, ownership, `CREATE`, `USAGE` ou DML em schema/objetos; somente `CONNECT` ao banco vazio;
- `erp_app` com login de teste somente se segredo seguro foi provisionado, sem configurar essa role no ERP; `erp_admin` separado e nunca configurado no ERP;
- `UTF8` confirmado no banco criado, se a criação tiver sido aprovada;
- collation/locale e timezone registrados como decisões aprovadas, sem inferência implícita;
- logs de erro e autenticação disponíveis com retenção e rotação verificadas;
- exercício de backup/restore do banco novo vazio validado, sem dados da origem; snapshots mínimos, locais e privados de configuração preexistente, quando houver alteração, tratados separadamente apenas como rollback; isso não representa RPO/RTO produtivo;
- somente os paths de diretórios explicitamente acordados copiados localmente em destino protegido, com manifesto/hash conferido contra a origem e restauração em diretório isolado validada, sem alteração da origem;
- PostgreSQL somente iniciado/habilitado após bind exclusivo, `pg_hba.conf` restrito e firewall local-only comprovados;
- Nginx/PHP-FPM, se iniciados/recarregados, usados somente para fixture sintética em `127.0.0.1`/socket Unix, sem serving externo, sem autostart da aplicação e com a fixture removida ao final;
- clone completo e privado do remoto oficial, após os gates e dentro desta autorização sem autorização adicional, somente em `source/` fora do webroot e sem publicação, com a URL exata, SHA de `origin/main`, SHA do `HEAD` efetivamente verificado, baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e commits/diferenças desde o baseline registrados; sem waiver do Gate 2;
- health checks locais, inclusive conexão técnica PHP/PDO_PGSQL e `SELECT 1`, concluídos; teste remoto e teste da aplicação não integram o aceite do serviço vazio;
- compatibilidade do ERP com PostgreSQL validada em homologação, em gate futuro independente;
- evidências sem segredos, tokens, chaves ou dados de moradores;
- aceite formal da preparação não abre o Gate 2 nem autoriza integração/migração.

Nenhum critério acima foi testado ou atendido como fato nesta sessão.

## 9. Pendências e bloqueadores

1. Gate 2 permanece `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`.
2. A preparação está apenas condicionada; acesso administrativo à VM e método seguro ainda não foram confirmados.
3. Fingerprint do host, instalação de chaves e estado do SSH não foram validados nesta sessão.
4. Sistema operacional, versão do PostgreSQL e estado do serviço são desconhecidos.
5. Compatibilidade do ERP PHP 8.x, bibliotecas de conexão, consultas e transações com PostgreSQL não foi comprovada.
6. Versão de PostgreSQL, extensões e política de atualização ainda não foram aprovadas.
7. Collation/locale, timezone e convenção de datas/horários precisam de decisão técnica e funcional.
8. O schema PostgreSQL, grants por tabela/função/sequência e eventual estratégia de migração não foram definidos.
9. Regras atuais de firewall, rede, DNS e exposição da porta `5432` são desconhecidas.
10. Política de logs, retenção, alertas, backup, cópia protegida somente dos paths de diretórios explicitamente acordados, integridade/restauração e RPO/RTO ainda precisa ser aprovada; snapshots mínimos de configuração preexistente permanecem uma exceção de rollback separada de backup/restore.
11. Clone completo privado após os gates, remoto oficial, URL exata, SHA de `origin/main`, SHA do `HEAD` efetivamente verificado, baseline, commits/diferenças desde o baseline e localização em `source/` fora do webroot ainda não foram verificados; a ação está dentro desta autorização, sem autorização adicional, waiver do Gate 2 ou publicação.
12. Serviço PostgreSQL local, banco vazio e role mínima somente poderão ser preparados após todos os gates; schema, grants de aplicação, extensão e migration dependem de aprovações específicas futuras.
13. A navegação à console foi tentada, mas bloqueada em controle de acesso do provedor; não houve autenticação, SSH, API, shell remoto, `apt`, firewall, instalação, clone, cópia protegida ou backup nesta sessão.

## 10. Conclusão

Este arquivo registra o plano da preparação autorizada, mas não comprova o estado de PostgreSQL na VM. O status permanece `BLOCKED / NOT_EXECUTED`: nenhuma operação foi executada porque acesso autenticado, fingerprint confiável e inventário read-only completo e confiável ainda não foram validados. Após os gates, preparar o serviço vazio, os serviços locais controlados, o clone completo e privado já autorizado em `source/` sem autorização adicional, e as cópias protegidas somente dos paths explicitamente acordados, validando localmente integridade e restauração; snapshots mínimos, locais e privados de configuração preexistente permanecem separados como rollback. O clone não autoriza waiver do Gate 2 nem publicação. O Gate 2 permanece `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED` e impede schema/dados/conexão funcional do ERP.

## Referências

[1]: https://www.postgresql.org/support/versioning/ "Política oficial de versões PostgreSQL"
[2]: https://www.postgresql.org/download/linux/ubuntu/ "Pacotes PostgreSQL para Ubuntu"
[3]: https://www.postgresql.org/about/news/postgresql-186-1711-1615-1519-1424-and-19-beta-3-released-3365/ "Anúncio oficial do PostgreSQL 18.6, 13/08/2026"
