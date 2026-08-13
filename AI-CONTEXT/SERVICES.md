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
