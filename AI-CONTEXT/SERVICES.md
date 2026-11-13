# Serviços (Services)

O ERP possui serviços que rodam em background ou de forma independente:

## 1. ControliD (Catracas/Totens)
- Webhooks configurados nos equipamentos batem na rota `/api/controlid/push.php`.
- Registra acesso em tempo real, valida QR Code, cadastra nova face.

## 2. Serviço de Notificação (Firebase PWA)
- Push notifications enviadas pelo PHP via Firebase Cloud Messaging (Service Account).
- Service Worker (`sw.js`) no frontend intercepta e exibe a notificação no dispositivo do morador.

### Atualização de versão do Portal
- Quando uma nova versão do Service Worker é detectada, o Portal do Morador não exibe barra visual e não força recarregamento durante o uso.
- O novo worker permanece em espera e assume no próximo ciclo seguro do navegador; o listener `controllerchange` continua responsável pelo reload quando a troca efetivamente ocorrer.
- O banner de instalação do PWA é independente desse fluxo e permanece ativo.

## 3. Envio de E-mail
- Implementado com Factory Pattern (`EmailProviderFactory`).
- Suporta múltiplos gateways: Brevo, Resend, SMTP genérico.
- Possui fallback automático (se Brevo falhar, tenta SMTP).

### Alertas de infraestrutura Hetzner
- Transporte separado do e-mail do ERP: o dispatcher usa msmtp com TITAN primário e Brevo como fallback automático após falha conclusiva; timeout ambíguo não aciona fallback para evitar duplicidade.
- Usa o mesmo endereço `noreply@erpcondominios.com.br` como remetente e destinatário de resumo/alertas. A app password TITAN e a credencial SMTP Brevo são arquivos distintos, root-only; não são lidas da HML nem colocadas em Git/logs.
- Essa configuração pertence à VM Hetzner e não altera os gateways da aplicação.


### Eventos automáticos de Controle de Acesso

Todo acesso liberado pelos modos Online Pro, cartão, QR Code, UHF, Monitor e Push Mode converge em `api/controlid/_helper.php`. Após a gravação bem-sucedida em `registros_acesso`, o helper cria o evento persistente `acesso_entrada` para o morador vinculado ao veículo e tenta FCM pelo mesmo transporte de protocolos.

A notificação não bloqueia a catraca. Falhas de banco secundário, ausência de token ou falha FCM são registradas em `logs/access_notification.log` e `error_log`, preservando a liberação física. A trilha de validação pós-deploy está em `sql/validacao_controlid_notificacao_pos_deploy.sql`.
