# Implantação — Portal do Morador e PWA Organizado

## Objetivo

Esta atualização corrige a abertura da aba **Controle de Acesso** no Portal do Morador, elimina o aviso de acessibilidade do formulário de alteração de senha e concentra os ativos de PWA em `PWA/`.

> O arquivo `firebase-messaging-sw.js` permanece na raiz apenas como um adaptador técnico. Navegadores só permitem que um Service Worker controle `/frontend/` quando seu arquivo de registro está na raiz. Toda a implementação do Service Worker foi movida para `PWA/firebase-messaging-sw.js`.

## Arquivos principais

| Caminho | Finalidade |
|---|---|
| `frontend/portal_morador.html` | Corrige a aba Controle de Acesso, o campo de usuário acessível e os links PWA. |
| `PWA/js/portal.js` | Lógica do portal PWA com URLs absolutas de API. |
| `PWA/firebase-messaging-sw.js` | Implementação do cache offline e Firebase Messaging. |
| `firebase-messaging-sw.js` | Adaptador mínimo de escopo para o navegador. |
| `PWA/portal-morador-manifest.json` | Manifesto do Portal do Morador. |
| `PWA/manifest.json` | Manifesto do Console de Acesso. |

## Implantação no HostGator

Faça backup dos arquivos atuais. Em seguida, envie o conteúdo do pacote ZIP para a raiz de `public_html`, preservando integralmente as pastas `PWA/`, `frontend/` e `api/`. A exclusão dos arquivos legados é coberta pelo pacote e deve ser aplicada conforme a estrutura enviada.

Após o envio, abra o Portal do Morador e use `Ctrl+F5`. Caso exista uma instalação PWA anterior, clique em **Atualizar** quando o banner de nova versão aparecer. O Service Worker da raiz carregará automaticamente a implementação em `PWA/` e manterá o escopo do portal em `/frontend/`.

## Validação

| Verificação | Resultado esperado |
|---|---|
| Aba Controle de Acesso | Carrega o histórico da unidade sem `ReferenceError`. |
| Formulário Alterar Senha | Não apresenta aviso de ausência de usuário no navegador. |
| Portal do Morador | Carrega `/PWA/portal-morador-manifest.json` e `/PWA/js/portal.js`. |
| Console de Acesso | Carrega `/PWA/manifest.json`. |
| Central PWA | Health check confirma a implementação em `PWA/` e o adaptador de escopo. |
| Push e cache | Service Worker é registrado em `/firebase-messaging-sw.js` com escopo `/`. |

## Observação de segurança

As credenciais Firebase continuam sendo carregadas pela API `api/api_pwa_sw_config.php`. Não inclua chaves, tokens ou configurações sensíveis manualmente em JavaScript estático.
