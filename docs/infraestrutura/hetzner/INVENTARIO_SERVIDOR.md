# Inventário do Servidor Hetzner

**Status do documento:** `DRAFT / BLOCKED / NOT_EXECUTED`
**Classificação:** **INFRAESTRUTURA NÃO PREPARADA**
**Gate 2:** `CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE` — não pode ser `WAIVED`.
**Causalidade do bloqueio:** a preparação exige cumulativamente acesso/fingerprint seguros e inventário read-only completo e revisado; qualquer instalação só poderá ser avaliada se nada existente estiver comprometido ou em conflito e se não houver risco de sobrescrita.
**Repositório:** `app.erpcondominios.com.br-`
**Branch/worktree:** `infra/hetzner-environment-preparation`
**Data de referência:** 06/11/2026

> Limites normativos, distinção entre autorização e execução, e fontes verificáveis: [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md).

## 1. Objetivo e escopo

Este documento registra a identificação declarada da VM destinada ao ERP e separa, de forma explícita, os fatos fornecidos nesta sessão, a evidência histórica disponível e o estado atual ainda não verificado.

O escopo é exclusivamente o inventário inicial da infraestrutura. Nenhum acesso à VM, coleta remota, alteração de configuração, instalação, teste de serviço, deploy, backup, migração de banco de dados ou preparação operacional foi executado nesta sessão.

A Fase 2.5 autoriza preparação privada e reversível somente após os gates cumulativos de acesso administrativo autenticado, fingerprint confiável, inventário read-only completo e revisado, segunda sessão administrativa independente e recuperação validada. Deve preservar serviços/dados e parar diante de conflito ou comprometimento; a autorização existe, mas a execução depende de acesso técnico validado. Nenhum desses gates foi executado ou validado nesta sessão.

## 2. Identificação declarada da VM

As informações abaixo foram fornecidas pelo solicitante e **não constituem evidência de ambiente auditado**:

| Campo | Valor declarado | Situação da verificação |
|---|---|---|
| Nome da VM | `<hostname privado omitido>` | Não verificado na VM ou no provedor |
| Endereço IPv4 | `<IP privado omitido>` | Não verificado por acesso ao ambiente |
| Localização | Helsinki | Declarada; não verificada |
| Plano | `CPX32` | Declarado; não verificado |
| CPU | 4 vCPU | Declarada; não verificada |
| Memória | 8 GB RAM | Declarada; não verificada |
| Armazenamento | 160 GB | Declarado; não verificado |
| Sistema operacional escolhido | Ubuntu 24.04 LTS | Declarado/escolhido; a imagem Ubuntu só será confirmada por evidência da console/provedor e permanece **NÃO VERIFICADA** |
| Provedor/contexto | Hetzner | Declarado; a tela no controle de acesso do provedor expirou antes da autenticação; nenhum estado da VM foi obtido |

A identificação acima deve ser confirmada por evidência independente antes de qualquer decisão de preparação, instalação ou migração. Em particular, a imagem Ubuntu deve ser confirmada por evidência da console/provedor; se essa evidência permanecer inacessível, a imagem deve continuar marcada como **NÃO VERIFICADA**.

## 3. Evidências e limites conhecidos

### 3.1 Fatos fornecidos nesta sessão

- A VM declarada para o ambiente é `<hostname privado omitido>`, com IP `<IP privado omitido>`, localizada em Helsinki e com recursos declarados de plano `CPX32`, 4 vCPU, 8 GB de RAM e 160 GB de armazenamento.
- Ubuntu 24.04 LTS foi escolhido para a VM; a imagem e o sistema operacional efetivamente instalados ainda não foram confirmados.
- O ERP é documentado como uma SPA em Vanilla JavaScript, com backend em PHP 8.x.
- A documentação declara MySQL no HostGator como origem; esta menção é somente inventário local e não autoriza conexão, consulta, exportação, cópia ou backup no HostGator.
- O destino pretendido é PostgreSQL local vazio e o usuário indicou PHP 8.3 como alvo preferido se compatível; compatibilidade, versão instalada e serviço real permanecem não verificados. PHP 8.3 está em suporte de segurança apenas até 2027-12-31; comparar 8.4/8.5. [1]
- O Gate 2 da migração é `CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE` e não pode ser `WAIVED`.

### 3.2 Evidência histórica de 05/11/2026

O handoff histórico registra as seguintes condições, sem permitir inferir o estado atual:

- Autenticação SSH não confirmada.
- Fingerprint do host não validada.
- Instalação das chaves de acesso não confirmada.
- `sshd` observado como inativo/desabilitado em uma inspeção anterior.
- Tela no controle de acesso do provedor expirada antes da autenticação.

Esses registros são apenas evidência histórica. Não foram revalidados nesta sessão e não devem ser tratados como fotografia atual da VM.

### 3.3 Ocorrência desta sessão

- A tela no **controle de acesso do provedor** da console Hetzner expirou antes da autenticação.
- Nenhum acesso à VM foi realizado.
- Não houve autenticação SSH, validação de fingerprint, execução de shell remoto ou coleta de inventário.
- Não há evidência atual sobre firewall, sistema operacional efetivamente instalado, usuários, discos, recursos disponíveis, processos ou serviços.
- Não há serviço instalado confirmado por esta sessão.

### 3.4 Evidências e armazenamento

- Toda evidência de uma coleta futura deverá ser armazenada em local privado, com acesso restrito, fora do Git e nunca em local público.
- As evidências deverão ser sanitizadas e não poderão conter segredos, chaves privadas, tokens, dados de moradores, dados/uploads preexistentes ou conteúdo de produção.
- Nesta sessão não foram produzidas saídas de comandos, evidências de acesso ou artefatos de preparação.

## 4. Estado atual não verificado

O estado abaixo deve permanecer classificado como **desconhecido/não verificado** até que uma coleta autorizada, segura e reproduzível seja realizada:

- identidade efetiva do host, hostname e correspondência com o IP declarado;
- imagem efetiva do Ubuntu e sua versão, a confirmar somente por evidência da console/provedor; se a console/provedor permanecer inacessível, **NÃO VERIFICADA**; kernel e arquitetura;
- estado do acesso SSH, porta exposta, configuração efetiva e autenticação por chave;
- fingerprint das chaves do host e sua validação fora de banda;
- usuários, grupos, contas administrativas e chaves autorizadas;
- firewall e regras de filtragem de rede;
- CPU, memória, armazenamento disponível, partições, volumes e pontos de montagem;
- interfaces, rotas, DNS e portas em escuta;
- processos e serviços atualmente ativos, habilitados ou instalados;
- presença e versões de Apache, LiteSpeed, Nginx, PHP, Composer, PostgreSQL ou qualquer outro componente; qualquer menção a MySQL neste inventário é apenas inventário local e não autoriza conexão, consulta, exportação, cópia ou backup no HostGator;
- logs, backups, dados persistidos, configuração de produção e estado de implantação.

Nenhuma versão instalada, credencial, chave, saída de comando ou resultado de teste deve ser presumida a partir das informações declaradas.

## 5. Decisões e classificação

1. A classificação vigente é **INFRAESTRUTURA NÃO PREPARADA**; a preparação está autorizada, mas ainda não executada.
2. O acesso, a identidade, a proteção e a prontidão da VM não foram verificados; não tratá-la como pronta para receber o ERP.
3. Não será considerada válida qualquer tentativa de acesso sem validação prévia da fingerprint do host e autorização explícita para o método de acesso.
4. Este documento reporta somente o inventário; após seu aceite, hardening restritivo, instalação/configuração de serviços e clone privado estão autorizados pela Fase 2.5, dentro de seus limites.
5. A arquitetura documentada do ERP — Apache/LiteSpeed, PHP 8.x e MySQL no ambiente HostGator — é apenas referência de inventário local; não autoriza acesso ao HostGator nem comprova a adequação de uma nova VM ou compatibilidade com PHP 8.3 ou PostgreSQL.
6. O Gate 2 é `CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE`, não pode ser `WAIVED` e permanecerá assim até que a identidade, o acesso seguro, o inventário read-only completo e revisado e a compatibilidade sejam evidenciados e aprovados.
7. A preparação privada e reversível exige cumulativamente acesso administrativo autenticado, fingerprint confiável, inventário read-only completo e revisado, segunda sessão administrativa independente e recuperação validada.
8. Qualquer instalação só poderá ser avaliada se nada existente estiver comprometido ou em conflito e se não houver risco de sobrescrita; diante de qualquer dúvida, a preparação deve parar.
9. Toda evidência deve permanecer em local privado e restrito, nunca no Git ou em local público.
10. Este documento não contém nem deve receber segredos, chaves privadas, tokens, senhas ou dados de moradores.

## 6. Procedimento futuro condicionado a acesso seguro e autorizações

O procedimento abaixo inicia com coleta read-only e prossegue, após validação e revisão, para preparação autorizada. Não representa execução realizada; o acesso/fingerprint ainda não foi validado nesta sessão.

### 6.1 Pré-condições e gates técnicos cumulativos

- Confirmar o método de acesso autorizado e a pessoa responsável pela autorização.
- Obter a fingerprint esperada por canal independente e compará-la antes de aceitar a primeira conexão.
- Confirmar que a chave pública autorizada está instalada, sem expor chaves privadas ou segredos.
- Usar somente método de recuperação oficialmente suportado pelo provedor, sem contornar controle de acesso do provedor, SSH, fingerprint, MFA ou autenticação; se o controle bloquear o acesso, interromper a tentativa.
- Definir local privado e restrito para os resultados sanitizados, fora do Git e nunca público, sem copiar credenciais, dados pessoais, dados/uploads preexistentes ou conteúdo de produção.
- Manter o Gate 2 como `CLOSED / NÃO AUTORIZADO` durante a coleta; ele não pode ser `WAIVED`.
- Confirmar que a coleta read-only foi concluída e revisada antes de avaliar qualquer preparação privada e reversível.
- Parar se houver conflito, comprometimento, risco de sobrescrita, impossibilidade de manter Nginx/PHP-FPM sem autostart e sem listener público, ou impossibilidade de comprovar as barreiras local-only específicas antes de iniciar/habilitar PostgreSQL.

Os seguintes gates são cumulativos, explícitos e não constituem evidência de execução:

1. **Acesso:** sessão administrativa autenticada, pelo método autorizado e sem bypass de SSH, MFA, controle do provedor ou fingerprint.
2. **Identidade:** fingerprint do host confiável, obtida e comparada por fonte independente antes de aceitar a conexão.
3. **Inventário:** inventário read-only completo, sanitizado, armazenado em local privado e revisado antes de qualquer instalação ou alteração.
4. **Continuidade administrativa:** segunda sessão administrativa independente comprovadamente funcional, sem encerrar a sessão primária.
5. **Recuperação:** mecanismo de recuperação oficialmente suportado, acessível e validado antes de qualquer mudança de SSH, UFW ou firewall.

SSH/UFW/firewall hardening, incluindo regras restritivas, só pode ocorrer depois de os cinco gates acima terem evidência registrada. Se qualquer gate faltar, a alteração deve ser abortada; não há waiver para esses gates nem para o Gate 2. Após qualquer mudança autorizada, as duas sessões e o caminho de recuperação devem continuar preservados e verificáveis.

### 6.2 Coleta mínima de identificação e sistema

Após a validação do acesso, coletar somente informações de leitura sobre hostname, endereço, data/hora, kernel, arquitetura, versão do Ubuntu e imagem do sistema. A imagem Ubuntu só será considerada confirmada por evidência da console/provedor; se a console/provedor estiver inacessível, registrar **NÃO VERIFICADA**. As saídas devem ser preservadas com data, responsável e identificação da sessão, em local privado e restrito, sem alterações no host.

### 6.3 Coleta mínima de recursos e armazenamento

Registrar CPU disponível, memória total e disponível, discos, partições, volumes, sistemas de arquivos, pontos de montagem, espaço usado e espaço livre. Comparar os resultados observados com os recursos declarados nesta documentação, marcando divergências para investigação.

### 6.4 Coleta mínima de rede e segurança

Registrar interfaces e rotas, portas em escuta, configuração efetiva do SSH e regras de firewall por consulta read-only. Validar a fingerprint das chaves do host por procedimento aprovado. Não abrir portas, não alterar regras e não reiniciar serviços durante o inventário.

### 6.5 Coleta mínima de usuários, pacotes e serviços

Inventariar contas e grupos relevantes, privilégios administrativos, pacotes instalados e serviços configurados ou ativos, sem consultar ou exportar material secreto. Identificar localmente, quando existirem, os componentes de web server, PHP, Composer, PostgreSQL e outros bancos; qualquer menção a MySQL é somente inventário local e não autoriza conexão, consulta, exportação, cópia ou backup no HostGator. A presença de um binário ou pacote não será tratada como prova de configuração funcional ou compatibilidade.

### 6.6 Avaliação posterior

Após a coleta read-only completa e sua revisão, executar a preparação autorizada com plano de reversão e registro separado. Nada foi executado nesta sessão. As ações fora do escopo permanecem proibidas na seção 6.8.

### 6.7 Preparação futura permitida, sob todas as condições

Esta seção preserva o plano futuro sem alegar execução. Instalação, hardening e mudanças só poderão ser consideradas depois de acesso autenticado e fingerprint confiável, inventário read-only completo e revisado, ausência de conflito/comprometimento, segunda sessão administrativa independente e mecanismo de recuperação validado; sem essas pré-condições, parar:

- Instalar/configurar Nginx e PHP-FPM somente para uma fixture temporária, sintética e local em `127.0.0.1`/socket Unix; validar sintaxe e iniciar/recarregar os serviços apenas de forma controlada durante o teste. Não habilitar autostart persistente, não executar `enable` persistente, não servir o ERP externamente, remover a fixture ao final e encerrar o uso temporário.
- Prevenir autostart e listeners não intencionais do PostgreSQL durante a preparação. Só iniciar ou habilitar o PostgreSQL depois de comprovar bind exclusivo em loopback, `pg_hba` seguro, firewall com `5432` negada e ausência de conflito; ele poderá permanecer ativo somente em modo local-only demonstrado, sem listener público.
- Preparar PostgreSQL local vazio e role exata mínima: `erp_app` com apenas `LOGIN` e `CONNECT`; revogar `CONNECT` e `TEMPORARY` de `PUBLIC`, sem atributos adicionais, memberships, ownership, grants, `CREATE`, `USAGE`, `TEMPORARY` ou qualquer DML. Executar teste PDO `SELECT 1` isolado, sem conexão funcional do ERP.
- Fazer clone completo e privado somente em `source/`, preservando todo o histórico e a origem autorizada, sem usar `release/` ou `current/`. Registrar separadamente o remoto efetivo, o SHA de `origin/main`, o SHA de `HEAD`, a presença do baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e o diff/commits desde esse baseline.
- Gerar obrigatoriamente `pg_dump` do banco vazio e validar a restauração em banco local vazio e isolado; manter cópia local protegida somente dos diretórios acordados, preservando a origem e o `source/`. Qualquer snapshot mínimo de configuração deve ser privado, separado do dump/restore e tratado exclusivamente como rollback.
- Preservar serviços e dados existentes; não sobrescrever nada e interromper diante de conflito, comprometimento ou dúvida.

### 6.8 Proibições e requisitos futuros não executáveis

Permanecem fora do escopo: bypass de acesso, SSH inseguro, SSH global, hardening de SSH/UFW/firewall antes dos cinco gates, autostart persistente ou `enable` de Nginx/PHP-FPM, fixture não removida, listener público do ERP ou PostgreSQL, DNS, `release/`/`current/`, deploy, publicação, serving externo, conexão funcional do ERP, consulta/exportação/backup de linhas do MySQL/HostGator, migração, schema/migrations/importação, backup externo e cutover. Hardening restritivo, PostgreSQL local-only, fixture temporária, clone privado, dump/restauração do banco vazio e cópia protegida somente dos diretórios acordados são permitidos apenas após os gates e demais condições; o snapshot mínimo de configuração permanece rollback separado.

## 7. Testes e critério de aceite

### 7.1 Execução nesta sessão

- **Testes de acesso e gates de continuidade/recuperação:** não executados.
- **Validação de fingerprint:** não executada.
- **Evidência da imagem Ubuntu pela console/provedor:** indisponível; **NÃO VERIFICADA**.
- **Coleta de sistema, recursos, discos, rede, firewall, usuários ou serviços:** não executada.
- **Testes de PHP, Composer, web server, MySQL ou PostgreSQL:** não executados; nenhuma conexão, consulta, exportação, cópia ou backup no HostGator foi autorizada.
- **Fixture Nginx/PHP-FPM, dump/restauração local, clone completo, cópia protegida de diretórios ou snapshot de rollback:** não executados; não há autostart, `enable`, listener, clone, dump, restore, cópia ou snapshot produzido por esta sessão.
- **Deploy, backup, migrations e alterações na VM:** não executados.

Não há outputs de comandos a reportar.

### 7.2 Critério de aceite do inventário futuro

O inventário só poderá ser aceito como concluído quando:

1. a identidade do host e o IP observado forem reconciliados com a identificação declarada;
2. a fingerprint do host for validada por fonte independente;
3. o método de acesso autorizado estiver comprovado e documentado;
4. a versão efetiva do sistema operacional e o kernel forem registrados;
5. CPU, memória, armazenamento, rede, firewall, usuários, pacotes e serviços forem coletados em modo read-only;
6. divergências entre recursos declarados e observados forem registradas, sem suposições;
7. as evidências forem sanitizadas e não contiverem segredos, chaves privadas, tokens ou dados de moradores;
8. as evidências forem armazenadas em local privado e restrito, fora do Git e nunca público;
9. a evidência da imagem Ubuntu vier da console/provedor; se inacessível, a imagem permanecerá **NÃO VERIFICADA**;
10. a segunda sessão administrativa independente funcionar sem substituir a sessão primária e o mecanismo de recuperação oficialmente suportado for validado;
11. hardening de SSH/UFW/firewall só for aplicado depois dos gates anteriores, com as duas sessões e a recuperação preservadas;
12. a fixture temporária de Nginx/PHP-FPM for limitada a loopback/socket Unix, sem autostart/`enable` persistente, e for removida ao final;
13. o PostgreSQL só for iniciado/habilitado após bind loopback, `pg_hba` seguro, firewall `5432` negada e ausência de conflito, permanecendo apenas local-only;
14. a role tiver exatamente `LOGIN`+`CONNECT`, com `PUBLIC CONNECT`/`TEMPORARY` revogados e sem attrs, memberships, ownership, grants, `CREATE`, `USAGE`, `TEMPORARY` ou DML;
15. o clone completo preservar a origem e registrar remoto, `origin/main` SHA, `HEAD` SHA, baseline e diff/commits desde o baseline;
16. o dump do banco vazio for restaurado em banco local vazio e isolado, e a cópia protegida ficar limitada aos diretórios acordados, com snapshot mínimo de configuração separado como rollback;
17. o responsável técnico revisar e aprovar o inventário.

Uma preparação privada e reversível permanece apenas como plano condicional; ela não foi executada porque não houve acesso administrativo autenticado e seguro, fingerprint confiável, inventário read-only completo e revisado, segunda sessão administrativa independente nem recuperação validada. O inventário não abre o Gate 2, que permanece `CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE` e não pode ser `WAIVED`. Ver [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md) para o limite exato.

Uma janela operacional só é requisito do teste ou da atividade futura que explicitamente a exigir; não é condição geral inventada nem substitui acesso/fingerprint seguros, inventário read-only completo e revisão.

## 8. Pendências e bloqueadores

- Acesso SSH autenticado ainda não confirmado.
- Fingerprint do host ainda não validada.
- Instalação das chaves autorizadas ainda não confirmada.
- Tela no controle de acesso do provedor expirada antes da autenticação nesta sessão.
- Método de recuperação e acesso administrativo ainda não comprovado.
- Sistema operacional efetivo e sua versão desconhecidos; a imagem Ubuntu permanece **NÃO VERIFICADA** por falta de evidência acessível da console/provedor.
- Firewall, rede, usuários, discos, recursos e serviços atuais desconhecidos.
- Nenhum serviço instalado confirmado por evidência desta sessão.
- PHP 8.3 é o alvo preferido solicitado, mas compatibilidade não comprovada; PostgreSQL local vazio é o destino pretendido, com compatibilidade do ERP pendente. A menção à origem MySQL no HostGator é apenas inventário local e não autoriza conexão, consulta, exportação, cópia ou backup.
- A preparação privada e reversível é apenas condicional e não foi executada; faltam autenticação, fingerprint, inventário read-only completo e revisão. Uma janela só será exigida por teste ou atividade que explicitamente a requeira.
- Segunda sessão administrativa independente e mecanismo de recuperação ainda não foram validados; portanto, SSH/UFW/firewall hardening continua bloqueado.
- Não há evidência de fixture temporária Nginx/PHP-FPM, sem autostart/`enable` persistente e removida, nem de PostgreSQL local-only com bind loopback, `pg_hba`, firewall `5432` negada e ausência de conflito.
- Não há evidência de role com exatamente `LOGIN`+`CONNECT`, das revogações de `PUBLIC`, do clone completo com remoto/SHAs/baseline/diff, do dump/restauração do banco vazio, da cópia protegida somente dos diretórios acordados ou do snapshot mínimo de configuração separado como rollback.
- Gate 2 da migração: `CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE`; não pode ser `WAIVED`.

Até a resolução dessas pendências, a infraestrutura deve continuar classificada como **INFRAESTRUTURA NÃO PREPARADA**.

## Referências

[1]: https://www.php.net/supported-versions.php "Versões PHP oficialmente suportadas"
