# Serviços (Services)

O ERP possui serviços que rodam em background ou de forma independente:

## 1. ControliD (Catracas/Totens)
- Webhooks configurados nos equipamentos batem na rota `/api/controlid/push.php`.
- Registra acesso em tempo real, valida QR Code, cadastra nova face.

## 2. Serviço de Notificação (Firebase PWA)
- Push notifications enviadas pelo PHP via Firebase Cloud Messaging (Service Account).
- Service Worker (`sw.js`) no frontend intercepta e exibe a notificação no dispositivo do morador.

## 3. Envio de E-mail
- Implementado com Factory Pattern (`EmailProviderFactory`).
- Suporta múltiplos gateways: Brevo, Resend, SMTP genérico.
- Possui fallback automático (se Brevo falhar, tenta SMTP).
