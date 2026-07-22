# Guia de Configuração PWA e Firebase Cloud Messaging (FCM)
**Portal do Morador — Serra da Liberdade**

Este documento detalha o passo a passo para configurar o envio de notificações Push no Portal do Morador, utilizando o Firebase Cloud Messaging (FCM).

---

## 1. Criar o Projeto no Firebase

1. Acesse o [Console do Firebase](https://console.firebase.google.com/) com uma conta Google do condomínio.
2. Clique em **Adicionar projeto**.
3. Dê um nome ao projeto (ex: `serra-liberdade-pwa`) e clique em **Continuar**.
4. Desative o Google Analytics (não é necessário para push básico) e clique em **Criar projeto**.
5. Após a criação, clique em **Continuar** para ir ao painel do projeto.

---

## 2. Adicionar o App Web ao Firebase

1. No painel do projeto, clique no ícone **Web** (`</>`) para adicionar um app web.
2. Dê um apelido ao app (ex: `Portal Morador Web`).
3. Não marque a opção de Firebase Hosting. Clique em **Registrar app**.
4. O Firebase exibirá um bloco de código `firebaseConfig`. Copie esse bloco, pois você precisará dele no Passo 4.
5. Clique em **Continuar no console**.

---

## 3. Gerar a Chave VAPID (Web Push Certificate)

1. No menu lateral esquerdo, clique no ícone de engrenagem ⚙️ ao lado de "Visão geral do projeto" e selecione **Configurações do projeto**.
2. Vá para a aba **Cloud Messaging**.
3. Role a página até a seção **Configuração da Web**.
4. Na subseção **Certificados push da Web**, clique em **Generate key pair** (Gerar par de chaves).
5. Copie a chave pública gerada. Esta é a sua **VAPID Key**.

---

## 4. Configurar as Credenciais no Sistema (Painel Interno)

Agora você precisa inserir as credenciais obtidas nos passos anteriores dentro do painel do ERP:

1. Acesse o ERP com um usuário administrador.
2. No menu lateral, vá em **Configurações** -> **Notif. Push**.
3. Clique no botão **Configurar FCM**.
4. Preencha os campos com os dados obtidos:
   * **Project ID:** (do `firebaseConfig`)
   * **API Key:** (do `firebaseConfig`)
   * **Auth Domain:** (do `firebaseConfig`)
   * **Messaging Sender ID:** (do `firebaseConfig`)
   * **App ID:** (do `firebaseConfig`)
   * **VAPID Key:** (A chave pública gerada no Passo 3)
5. **Atenção ao FCM Server Key:** O Google descontinuou a API Legacy. Para o envio via PHP, a API usa o *Service Account*. Para simplificar, o sistema está preparado para usar a chave Legacy se ela ainda estiver ativa no seu projeto, ou você precisará habilitar a Cloud Messaging API (Legacy) no Google Cloud Console.
6. Clique em **Salvar Configurações**.

---

## 5. Configurar o Frontend (Código Fonte)

Você precisa atualizar o arquivo JavaScript do portal com as mesmas credenciais do Firebase.

1. Abra o arquivo `frontend/js/pwa-portal.js`.
2. Localize o bloco `FIREBASE_CONFIG` nas primeiras linhas do arquivo:

```javascript
const FIREBASE_CONFIG = {
    apiKey: "SUA_API_KEY_AQUI",
    authDomain: "SEU_AUTH_DOMAIN_AQUI",
    projectId: "SEU_PROJECT_ID_AQUI",
    storageBucket: "SEU_STORAGE_BUCKET_AQUI",
    messagingSenderId: "SEU_MESSAGING_SENDER_ID_AQUI",
    appId: "SEU_APP_ID_AQUI"
};
const VAPID_KEY = "SUA_VAPID_KEY_AQUI";
```

3. Substitua os valores pelos dados reais do seu `firebaseConfig` e a `VAPID_KEY`.
4. Salve o arquivo.

---

## 6. Configurar o Service Worker

1. Abra o arquivo `firebase-messaging-sw.js` (localizado na raiz do projeto).
2. Localize o bloco `firebase.initializeApp` no final do arquivo:

```javascript
firebase.initializeApp({
    apiKey: "SUA_API_KEY_AQUI",
    authDomain: "SEU_AUTH_DOMAIN_AQUI",
    projectId: "SEU_PROJECT_ID_AQUI",
    storageBucket: "SEU_STORAGE_BUCKET_AQUI",
    messagingSenderId: "SEU_MESSAGING_SENDER_ID_AQUI",
    appId: "SEU_APP_ID_AQUI"
});
```

3. Substitua os valores pelas mesmas credenciais do passo anterior.
4. Salve o arquivo.

---

## 7. Banco de Dados (Migração SQL)

Antes de fazer o upload dos arquivos, você deve executar o script SQL para criar as tabelas necessárias para armazenar os tokens e o histórico de notificações.

1. Acesse o seu banco de dados (ex: phpMyAdmin no cPanel).
2. Selecione o banco de dados do sistema.
3. Importe ou execute o conteúdo do arquivo `sql/migration_pwa_fcm.sql`.

---

## 8. Upload dos Arquivos (Deploy)

Faça o upload dos seguintes arquivos e diretórios para a raiz do seu servidor (`public_html`):

1. `portal-morador-manifest.json` (Raiz)
2. `firebase-messaging-sw.js` (Raiz)
3. Pasta `ico/` completa (Raiz)
4. `frontend/portal_morador.html` (Substituir existente)
5. `frontend/js/pwa-portal.js` (Novo)
6. `frontend/components/sidebar.html` (Substituir existente)
7. `frontend/pages/notificacoes_push.html` (Novo)
8. `frontend/js/pages/notificacoes_push.js` (Novo)
9. `api/api_pwa_push.php` (Novo)

---

## 9. Testando o PWA e Notificações

1. Acesse o **Portal do Morador** pelo celular ou desktop (usando Chrome, Edge ou Safari).
2. Você verá um banner no topo solicitando permissão: **"Ative as notificações para receber alertas..."**.
3. Clique em **Ativar Notificações** e permita no prompt do navegador.
4. (Opcional) No navegador mobile ou desktop (Chrome/Edge), procure a opção **"Instalar aplicativo"** ou **"Adicionar à tela inicial"** para instalar o PWA.
5. Acesse o sistema interno do ERP com um usuário administrador.
6. Vá em **Notif. Push** no menu lateral.
7. Verifique se o contador "Dispositivos Ativos" aumentou.
8. Clique em **Enviar Notificação**, preencha os dados e envie para teste.
9. A notificação deve aparecer no seu dispositivo!

---
*Documentação gerada para ASL ERP Condomínios.*
