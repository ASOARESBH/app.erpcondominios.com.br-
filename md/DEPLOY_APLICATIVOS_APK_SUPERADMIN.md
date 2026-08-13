# Aplicativos — Controle de Versões APK e preparação para Google Play

O portal exclusivo do Super-Admin agora possui o menu lateral **Aplicativos**, imediatamente abaixo de **Painel Super-Admin**. O módulo controla o catálogo institucional de aplicativos e as releases Android sem se misturar a qualquer tenant.

## O que foi implantado

| Recurso | Descrição |
|---|---|
| Catálogo institucional | Cadastro de aplicativo, chave interna, plataforma, package name, status, descrição e informações Google Play. |
| Releases APK | Controle de versão semântica, `version_code`, canal, status, atualização obrigatória, URL HTTPS, tamanho, SHA-256, SDK mínimo/target e notas de release. |
| Publicação controlada | Uma publicação de produção arquiva automaticamente a release de produção anterior do mesmo aplicativo. |
| Auditoria | Inclusões, alterações, publicações e arquivamentos ficam registrados na tabela `aplicativos_versionamento_log`. |
| Google Play preparada | Campos para URL da loja, package, track e release ID foram incluídos. A integração automatizada pela Google Play Developer API poderá ser adicionada sem alterar o histórico de versões. |

> O módulo registra e governa versões; neste primeiro momento ele recebe uma URL HTTPS do APK. O envio binário e a publicação automática na Google Play são etapas futuras, pois exigem credenciais de serviço e configuração da conta Google Play Console.

## Implantação no HostGator

1. Faça backup do banco `inlaud99_erpcondor` pelo phpMyAdmin.
2. Importe o arquivo `sql/migration_aplicativos_versionamento_mysql57.sql` no phpMyAdmin. Ele cria três tabelas globais e registra o aplicativo inicial **ERP Condomínios Android**.
3. Envie o conteúdo do ZIP preservando diretórios e nomes de arquivos.
4. Acesse com uma conta `super_admin`, use `Ctrl+F5` e abra **Aplicativos** no menu lateral.
5. Em **Editar aplicativo**, informe o package Android e o link da Google Play quando disponíveis. Em **Nova versão**, registre o APK, o `version_code`, SHA-256 e as notas antes de publicar.

Não há nova configuração de conexão de banco: o endpoint usa o mesmo `api/config.php` existente. O módulo só aceita sessões `super_admin` no contexto global; para administrá-lo após entrar em uma unidade, use **Voltar ao Painel**.
