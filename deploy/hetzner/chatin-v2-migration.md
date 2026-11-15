# Chat-In v2 — procedimento de migration em produção (Hetzner)

## Estado e escopo

O código do Chat-In v2 foi mesclado na `main` pelo PR #13, commit `2ad4b9ec652435ccdc21fb754bbdbfeca54f0ed4`. A migration SQL recebeu uma correção fail-closed: agora exige `pwa_configuracoes` no preflight, antes de qualquer DDL do schema da aplicação. A procedure auxiliar temporária da própria migration ainda é criada antes desse preflight; o runner a remove em falha normal e bloqueia nova execução se restar. O SHA-256 revisado é `a5d402a17e3789223a9d5a297b0c12eb78fa26232ebd6eb8dd8c1f575f5aa375`. A aplicação em produção ocorreu em 2026-11-15 conforme a seção “Execução concluída” abaixo; não execute novamente. O runner não concede autorização para futuras alterações operacionais.

O único alvo codificado é o MySQL 8.4 local da Hetzner `2.29.161.106`, schema ativo `erpcondominios_stage_20261114`. Não alterar o schema antigo preservado, o HostGator, PostgreSQL, DNS ou outros ambientes. Não executar o SQL direto pelo phpMyAdmin nem editá-lo manualmente.

A migration cria/ajusta schema e seeds de configuração/RBAC. Embora aditiva e idempotente por projeto, DDL de MySQL pode confirmar instruções individualmente. Uma falha não deve ser tratada como transação revertida: preservar o estado e diagnosticar; não repetir automaticamente nem restaurar sobre o banco ativo.

## Preparação de release

1. Revisar e mesclar o PR do runner para `main` conforme o processo normal do repositório.
2. Criar uma release imutável a partir do commit mergeado, preservando o fluxo Hetzner atual. Não copiar a branch de trabalho nem executar o script a partir de um checkout editável.
3. Confirmar que a release contém `deploy/hetzner/migrate-chatin-v2-production.sh` e `sql/migration_chatin_v2_mysql.sql`; não incluir `.git`, credenciais, dumps, logs, uploads ou configurações privadas.
4. Confirmar que o arquivo SQL tem exatamente o checksum esperado pelo runner. Se mudar um byte, o runner deve parar; não atualize o checksum sem revisão da migration.

## Pré-checagem somente leitura

Execute pelo TTY SSH autorizado, como root através de `sudo`, usando a release imutável atualizada:

```bash
sudo /var/www/erpcondominios/current/deploy/hetzner/migrate-chatin-v2-production.sh --check
```

O modo `--check` valida somente metadados/operação: IPv4 local, configuração privada sem exibir seus valores, e compara a identidade do MySQL via conexão PHP do ERP e via CLI com `--no-defaults`; valida schema, versão/UUID/socket, serviços, checksum, objetos base (incluindo PWA), presença/contagem das colunas e constraints e configuração/grants se a migration estiver completa. Não inicia backup, não cria log/marcador, não executa DDL e não altera o banco. Se houver qualquer estado parcial ou tentativa anterior, para sem tentar corrigir.

Limite conhecido: no estado `COMPLETA`, o runner confia no marcador root-only e verifica presença/contagem/nome dos objetos; não revalida toda semântica de tipos, defaults e expressões de constraints em cada `--check`. Uma suspeita de alteração externa pós-migration exige comparação read-only detalhada com a definição versionada antes de aceitar o estado.

## Aplicação — não executar sem janela e autorização operacional

O modo `--apply` exige TTY, root, confirmação literal do nome do schema e lock exclusivo. Antes de enviar o SQL, ele:

- valida que a migration ainda está completamente não aplicada e que nenhum marcador de tentativa/conclusão anterior existe;
- confirma os diretórios root-only e pelo menos 10 GiB livres;
- inicia o serviço existente de backup do schema configurado (com routines/triggers/events conforme o contrato do backup local);
- executa o serviço existente de integridade e exige backup novo, `gzip -t`, manifesto SHA-256, permissões `0600` e marcador atualizado após o início da execução;
- revalida estado, identidade da instância e checksum para detectar mudança concorrente;
- grava marcador persistente root-only de tentativa imediatamente antes do SQL; qualquer falha ou interrupção depois disso bloqueia retentativa automática;
- grava log root-only sem conteúdo de linhas de negócio e executa o SQL fixado por checksum;
- verifica a estrutura final, uma configuração para cada tenant, decisões RBAC nos grupos e grava um marcador root-only de conclusão.

Essas verificações provam integridade do arquivo comprimido e correspondência ao manifesto local; não provam restauração bem-sucedida. O restore drill e a cópia off-host continuam pendentes no runbook operacional e não são simulados pelo runner. Antes de executar, a operação também deve verificar externamente que `current` aponta exatamente à release imutável aprovada; o runner rejeita checkout `.git` e exige diretório com nome SHA, mas não verifica ancestrais/assinatura do GitHub.

Com todos os gates atendidos e autorização para aplicação, o comando será:

```bash
sudo /var/www/erpcondominios/current/deploy/hetzner/migrate-chatin-v2-production.sh --apply
```

Digite somente o texto exato solicitado pelo runner no TTY. Não automatize a confirmação, não redirecione entrada e não execute por cron/CI.

## Parada, falha e pós-validação

Qualquer erro antes do SQL interrompe sem aplicar a migration. Se o cliente MySQL retornar erro, o runner tenta remover apenas a procedure temporária `sp_erp_chatin_v2_20261114`, não grava a saída bruta do cliente, registra código/categoria sanitizados em log `root:root 0600` e sai com código não zero. DDL anterior pode ter sido confirmado; o marcador de tentativa fica persistente e **não reaplique**. Preserve backup, log, marcador e schema e faça investigação read-only para decidir a próxima ação.

Após sucesso, o runner deve indicar `APPLIED` e confirmar a contagem estrutural/configuração/grants. Em seguida, a equipe deve executar smoke autenticado com usuários de teste do mesmo tenant e de um segundo tenant, verificar autorização/isolamento, validar o canal/widget e monitorar erros sanitizados. O teste FCM real exige consentimento/sessão apropriada e continua sendo gate separado. Não declarar entrega de push sem prova de entrega.

Rollback não é DROP/limpeza de objetos. Não restaurar o backup sobre a base ativa automaticamente: isso apagaria gravações ocorridas desde o backup. Se for necessária recuperação, pare o uso, preserve o estado e planeje restauração em schema novo e isolado, com aprovação explícita.

## Verificações realizadas ao preparar este procedimento

O runner foi validado por revisão estática, `bash -n`, testes de argumentos/ajuda e verificação do checksum do SQL no repositório. Fixtures locais MariaDB 10.11 validam a falha do novo preflight PWA antes de DDL; isso não substitui MySQL 8.4. Não houve conexão SSH, execução de `--check` na VM, backup, consulta ao banco ativo, aplicação de SQL, deploy de código ou alteração de produção nesta preparação.

## Execução concluída — 2026-11-15

PR #14 foi mesclado em `main` (`7c834ff90905857c58431855d02d432cef91963f`). A release imutável foi ativada em `current`; a release anterior `3f78734566c9d2ceac38e265e72f7cb79a2a4e90` permaneceu intacta. O runner passou `--check`, MySQL 8.4.13 e checksum do SQL foram confirmados, e a aplicação única foi autorizada no TTY com a frase literal exigida. O backup novo e o serviço de integridade concluíram com sucesso; `gzip -t`, manifesto SHA-256 e modo `0600` foram conferidos. A pós-checagem do runner e uma verificação independente indicaram `COMPLETA`; Nginx, PHP-FPM e MySQL permaneceram ativos.

Smoke não autenticado: `/`, Portal do Morador, logins de morador/fornecedor, assets do widget e Configuração Chat-In retornaram 200; a API Chat-In sem sessão retornou 401 esperado. Não foram feitos login autenticado, teste FCM, teste Flutter ou restore drill; cópia off-host também continua pendente.

Achado de roteamento: `/config_chatin` responde 301 para `/`, enquanto `/frontend/layout-base.html?page=config_chatin` e os arquivos estáticos da página retornam 200. O snippet Nginx instalado não continha a rota. A substituição pelo snippet gerado do SHA mergeado passou `nginx -t`, mas o smoke continuou 301; o script restaurou automaticamente o snippet anterior. Estado final: configuração Nginx anterior restaurada, `nginx -t` aprovado e nenhuma mudança Nginx persistente. Investigar a seleção/precedência efetiva de location antes de nova alteração.
