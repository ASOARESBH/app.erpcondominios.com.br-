# Implantação Firebase Cloud Messaging — ERP Condomínios

## Objetivo

Este pacote prepara o ERP para enviar, pelo servidor PHP, notificações FCM de chegada e entrega de encomendas. O aplicativo Android já foi vinculado ao projeto Firebase `erp-condominios`; esta etapa autoriza exclusivamente o servidor a enviar mensagens para os tokens dos moradores.

> A chave privada da conta de serviço **não faz parte do repositório nem deste pacote ZIP**. Ela precisa ser enviada manualmente para o servidor, pois concede autorização de envio ao projeto Firebase.

## Arquivos do pacote

| Arquivo | Destino no ERP | Finalidade |
|---|---|---|
| `config/firebase/.htaccess` | `config/firebase/.htaccess` | Bloqueia acesso HTTP direto às credenciais |
| `sql/migration_notificacoes_encomendas_mobile.sql` | Executar no phpMyAdmin | Cria/ajusta o suporte multi-tenant de notificações |
| `sql/configurar_firebase_erp_condominios.sql` | Executar no phpMyAdmin após a migração | Registra `erp-condominios` em todos os tenants e habilita push de encomendas |
| `api/helpers/protocol_notification_helper.php` | `api/helpers/protocol_notification_helper.php` | Envia FCM HTTP v1 e registra o histórico de eventos |

## Ordem obrigatória de implantação

### 1. Backup

No cPanel, gere um backup dos arquivos do ERP e exporte o banco no phpMyAdmin antes de alterar qualquer dado.

### 2. Enviar os arquivos do pacote

Extraia `Firebase_FCM_ERP_Condominios_Deploy.zip` e envie seu conteúdo para a **raiz do ERP**, isto é, o mesmo diretório que contém as pastas `api/`, `config/` e `sql/`. Mantenha a estrutura de diretórios.

### 3. Enviar a chave privada manualmente

No **Gerenciador de Arquivos** do cPanel, acesse a raiz do ERP e crie a pasta `config/firebase/`, se ainda não existir. Envie o arquivo JSON baixado do Firebase e renomeie-o exatamente para:

```text
config/firebase/service-account.json
```

A pasta deverá conter:

```text
config/firebase/
├── .htaccess
└── service-account.json
```

Aplique, se o cPanel permitir, permissão `600` no arquivo `service-account.json` e `755` nas pastas. Nunca envie essa chave para GitHub, e-mail, WhatsApp ou diretórios públicos.

### 4. Executar as migrações no banco

No phpMyAdmin, selecione o banco do ERP. Primeiro execute integralmente:

```text
sql/migration_notificacoes_encomendas_mobile.sql
```

Em seguida execute:

```text
sql/configurar_firebase_erp_condominios.sql
```

O segundo script registra o projeto Firebase `erp-condominios` e habilita `push_encomenda_ativo = 1` para todos os tenants que possuem moradores. Os scripts podem ser rodados novamente sem gerar duplicidade.

### 5. Confirmar extensões PHP

A hospedagem precisa ter as extensões **OpenSSL** e **cURL** habilitadas para obter o token OAuth2 e chamar a API FCM HTTP v1. A validação local confirmou OpenSSL; o cURL deve ser confirmado no HostGator.

Crie temporariamente um arquivo `verificar_fcm.php` fora de qualquer diretório público, ou solicite ao suporte HostGator, para confirmar os módulos. Não exponha a saída de `phpinfo()` em URL pública.

### 6. Verificar registros no banco

Após o aplicativo Android ser recompilado com `google-services.json`, faça login e habilite **Alertas no dispositivo**. Confirme o registro com:

```sql
SELECT id, tenant_id, morador_id, plataforma, ativo, ultimo_uso,
       CHAR_LENGTH(fcm_token) AS tamanho_token
FROM pwa_fcm_tokens
ORDER BY ultimo_uso DESC;
```

O resultado deve apresentar um token Android ativo associado ao morador e ao tenant correto.

## Teste funcional

| Passo | Ação | Resultado esperado |
|---|---|---|
| 1 | Abrir o app, fazer login e ativar Alertas no dispositivo | Android pede a permissão e o token é salvo |
| 2 | Cadastrar um protocolo de encomenda para esse morador | A lista interna mostra **Sua encomenda chegou** e a descrição |
| 3 | Deixar o app em segundo plano | O Android exibe o alerta no display |
| 4 | Registrar a entrega com o nome de quem recebeu | A lista e o display mostram **Mercadoria recebida** |
| 5 | Conferir tabela `notificacoes_morador` | `push_status` deve ser `enviado` ou trazer diagnóstico de falha |

## Diagnóstico de falhas

| Situação | Verificação |
|---|---|
| Evento aparece somente na lista interna | Confirme `service-account.json`, `fcm_project_id`, extensão cURL e o token ativo |
| `push_status = nao_configurado` | Execute `configurar_firebase_erp_condominios.sql` |
| `push_status = sem_token` | Entre no app, habilite Alertas no dispositivo e permita notificações no Android |
| `push_status = falhou` | Veja o log PHP por entradas iniciadas em `[NotificacaoEncomenda]` |
| A notificação não abre na tela certa | Confirme que o app está na versão com o commit de notificações e a rota `/home/notifications` |

## Referências

[1] [Firebase — Envio por FCM HTTP v1](https://firebase.google.com/docs/cloud-messaging/send/v1-api)

[2] [Firebase — Cloud Messaging em Flutter](https://firebase.google.com/docs/cloud-messaging/flutter/get-started)
