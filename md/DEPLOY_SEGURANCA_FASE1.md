# Deploy — Fase 1 de Segurança

**Escopo desta entrega:** bloqueio de Git e arquivos sensíveis, configuração externa e preparação de rotação de credenciais. Este pacote **não** altera schema, dados de negócio, APIs funcionais, autenticação, permissões ou regras multi-tenant.

> A aplicação só deve receber as novas configurações após a criação do arquivo privado fora do `public_html`. Não envie esse arquivo privado para o Git, para o ZIP ou para o chat.

## Pré-requisitos

| Item | Obrigatório | Critério |
|---|---:|---|
| Backup do `public_html` | Sim | Arquivo de backup criado pelo cPanel, mantido fora do webroot |
| Acesso ao cPanel | Sim | File Manager e MySQL Databases disponíveis |
| Janela de manutenção | Sim | Permite testar login, sessão e integrações sem impacto aos usuários |
| Nova senha do banco | Sim | Gerada pelo cPanel e nunca reutilizada |
| Nova chave de e-mail | Sim | Valor aleatório, exclusivo e mantido fora do webroot |

## Ordem obrigatória de implantação

1. No **File Manager**, registre o caminho real do document root do domínio `app.erpcondominios.com.br` e confirme se ele contém `.git`.
2. Crie, no diretório pai do document root, a pasta privada `erpcondominios-private` com permissão `0700`.
3. Crie dentro dela o arquivo `erp_config.php` com permissão `0600`, a partir de `config/erp_config.php.example`. Use inicialmente a credencial de banco ainda ativa para evitar indisponibilidade durante o primeiro deploy.
4. Preencha a chave `ERP_EMAIL_CRYPTO_KEY` com um segredo novo e aleatório. Esse valor não deve ser a senha do banco nem ser salvo no repositório.
5. Envie os arquivos deste pacote ao document root, preservando as pastas internas. Sobrescreva somente os arquivos previstos na seção **Arquivos do pacote**.
6. Confirme que o novo `.htaccess` está presente no document root.
7. Remova fisicamente o diretório `.git` do document root. Não remova o repositório de trabalho que esteja fora da pasta pública.
8. Antes de trocar a senha do banco, regrave as credenciais dos provedores de e-mail no painel administrativo, conforme o roteiro de rotação. Isso converte as chaves legadas para a chave externa nova.
9. Faça a rotação da senha do usuário MySQL no cPanel e atualize **somente** o arquivo externo `erp_config.php`.
10. Execute todos os testes pós-deploy antes de revogar ou limpar backups antigos.

## Arquivos do pacote

| Arquivo | Alteração | Finalidade |
|---|---|---|
| `.htaccess` | Atualizado | Bloqueia `.git`, config, dumps, pacotes, logs, testes e debug antes do roteamento |
| `api/config.php` | Atualizado | Carrega credenciais apenas de arquivo externo ou variável de ambiente |
| `config.php` | Atualizado | Aplica a mesma regra para scripts da raiz |
| `api/email/EmailCrypto.php` | Atualizado | Usa chave externa própria e mantém leitura temporária de valores legados |
| `config/erp_config.php.example` | Novo | Modelo sem segredos do arquivo privado |
| `api/config.example.php` | Atualizado | Exemplo sem credenciais reais |
| `.gitignore` | Atualizado | Impede novo versionamento de configurações privadas e chaves |

## Criação do arquivo privado

O arquivo externo deve ficar **fora** do document root. Exemplo de estrutura:

```text
/home/USUARIO_CPANEL/
├── erpcondominios-private/
│   └── erp_config.php        (0600)
└── public_html/              (document root do domínio)
    ├── .htaccess
    ├── api/
    └── frontend/
```

Use o modelo a seguir, preenchendo valores exclusivamente no cPanel:

```php
<?php
define('ERP_DB_HOST', 'localhost');
define('ERP_DB_NAME', 'NOME_ATUAL_DO_BANCO');
define('ERP_DB_USER', 'USUARIO_ATUAL_DO_BANCO');
define('ERP_DB_PASS', 'SENHA_ATUAL_OU_NOVA');
define('ERP_DB_CHARSET', 'utf8mb4');
define('ERP_EMAIL_CRYPTO_KEY', 'SEGREDO_NOVO_ALEATORIO');
define('ERP_TIMEZONE', 'America/Sao_Paulo');
```

> Nunca inclua o caminho ou o valor desse arquivo em um commit, anexo de suporte ou mensagem.

## Remoção do Git público

Depois de confirmar que o repositório de trabalho existe em local não público ou possui cópia segura, remova apenas a pasta `.git` que estiver dentro do document root. O `.htaccess` já fornece bloqueio imediato, mas a remoção física é obrigatória.

Não remova `.git` de uma cópia de trabalho usada pelo processo de manutenção sem primeiro confirmar que ela não é o mesmo diretório público.

## Testes pós-deploy

### Testes de segurança HTTP

Execute como leitura simples, sem autenticação:

| Caminho | Resultado esperado |
|---|---|
| `/.git/` | 403 ou 404 |
| `/.git/HEAD` | 403 ou 404 |
| `/.git/config` | 403 ou 404 |
| `/.git/index` | 403 ou 404 |
| `/.git/objects/` | 403 ou 404 |
| `/.env` | 403 ou 404 |
| `/api/testeAPI/teste_api.php` | 403 ou 404 |
| `/api/debug_system.php` | 403 ou 404 |
| `/api/config.php` | 403 ou 404 |
| `/config.php` | 403 ou 404 |

### Testes funcionais sem alterar dados

1. Abrir `https://app.erpcondominios.com.br` e confirmar carregamento da tela de login.
2. Efetuar login com uma conta de teste autorizada e abrir o dashboard.
3. Efetuar logout e confirmar encerramento da sessão.
4. Abrir uma tela de consulta do sistema e confirmar uma resposta normal da API autenticada.
5. Abrir a logo privada do tenant após login, se houver, para confirmar a API de BLOBs.
6. Verificar notificações sem acionar criação ou exclusão de registros.
7. Verificar o envio de e-mail apenas com uma credencial de teste ou durante a janela autorizada de integração.

## Reversão

Se a aplicação retornar a mensagem de configuração indisponível, **não** copie credenciais para `api/config.php` ou `config.php`. Corrija o arquivo externo e suas permissões. Como último recurso, restaure o backup do `public_html` criado antes do deploy e revise o caminho externo antes de tentar novamente.

## Itens intencionalmente não tratados nesta fase

Foram mantidos para fases próprias: isolamento BOLA/IDOR, CSRF, CORS, recuperação legada de senha, autenticação Push Control iD, SQL injection, XSS e hardening adicional de headers.
