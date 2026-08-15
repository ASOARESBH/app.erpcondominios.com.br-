# Deploy — Recuperação de Senha Segura

## Diagnóstico confirmado

A recuperação de senha chegava ao transporte de e-mail, mas o provedor **Brevo** retornava HTTP `401` por chave de API não reconhecida. Portanto, a entrega não será restabelecida apenas com a publicação do código: é obrigatório corrigir a configuração ativa de e-mail antes do teste final.

A implementação substitui a geração antecipada de senha temporária por um **link com token aleatório, de uso único e expiração de 60 minutos**. O token é guardado somente como hash SHA-256. O fluxo suporta contas internas do ERP e moradores, evita enumerar cadastros e aplica limite de três pedidos por hora por conta ou IP.

## Arquivos do pacote

| Destino | Arquivo |
|---|---|
| `api/` | `api_recuperar_senha.php` |
| `api/` | `api_recuperacao_senha.php` |
| `api/` | `EmailSender.php` |
| `api/email/` | `EmailProviderFactory.php` |
| `frontend/` | `login.html` |
| `frontend/` | `esqueci_senha.html` |
| `frontend/` | `redefinir_senha.html` |
| `sql/` | `migration_recuperacao_senha_segura_mysql57.sql` |

## Ordem obrigatória

1. Faça backup do banco e dos arquivos listados acima no cPanel.
2. No banco `inlaud99_erpcondor`, execute `sql/migration_recuperacao_senha_segura_mysql57.sql` uma única vez.
3. Envie o ZIP preservando a estrutura interna de diretórios e sobrescreva apenas os arquivos do pacote.
4. No painel administrativo de e-mail, abra a configuração ativa e corrija a chave da API Brevo. A chave atual foi rejeitada pelo serviço.
5. Se optar por SMTP, preencha e salve **host, porta, usuário, senha, e-mail remetente e segurança TLS/SSL**. Quando uma API Brevo/Resend e SMTP estiverem preenchidos na mesma configuração, o sistema tentará SMTP automaticamente se a API falhar.
6. Use o botão administrativo de teste SMTP para enviar uma mensagem a uma caixa sob seu controle. O teste precisa retornar sucesso antes de liberar a recuperação aos usuários.
7. Abra `https://app.erpcondominios.com.br/frontend/login.html`, informe um CPF ou e-mail cadastrado no painel de recuperação e confirme que chega um link de redefinição. Teste a criação de nova senha e confirme que o mesmo link não pode ser reutilizado.

## Critérios de aceite

| Verificação | Resultado esperado |
|---|---|
| Conta inexistente | Mensagem genérica, sem confirmar a existência do cadastro |
| Conta ERP ativa | Recebe link de redefinição por e-mail |
| Morador ativo | Recebe link de redefinição por e-mail |
| Link válido | Abre a página de nova senha por até 60 minutos |
| Link consumido | Não pode ser usado novamente |
| Senha nova | Exige pelo menos 10 caracteres, maiúsculas, minúsculas e números |
| Brevo inválido + SMTP válido | Entrega pelo SMTP como fallback |

> Não inclua chaves de API ou senhas SMTP em arquivos PHP, ZIPs, commits ou mensagens. Cadastre-as somente no painel administrativo de e-mail e mantenha os arquivos de configuração fora do document root.
