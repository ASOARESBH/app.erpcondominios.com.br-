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

Faça backup dos arquivos atuais. Em seguida, envie o conteúdo do pacote ZIP para a raiz de `public_html`, preservando integralmente as pastas `PWA/`, `frontend/` e `api/`. Depois confirme e remova manualmente os arquivos legados `manifest.json`, `portal-morador-manifest.json` e `frontend/js/pwa-portal.js`; eles foram movidos para `PWA/` e não devem permanecer como cópias soltas.

Mantenha `firebase-messaging-sw.js` na raiz: ele é um adaptador técnico obrigatório de escopo e não deve ser removido. Após o envio, abra o Portal do Morador e use `Ctrl+F5`. A barra “Nova versão disponível” não é mais exibida: quando uma nova versão for detectada, ela permanecerá em espera e assumirá no próximo ciclo seguro do navegador, sem recarregar uma tela com formulário em uso. O Service Worker da raiz carregará automaticamente a implementação em `PWA/` e manterá o escopo do portal em `/frontend/`.

## Validação

| Verificação | Resultado esperado |
|---|---|
| Aba Controle de Acesso | Carrega o histórico da unidade sem `ReferenceError`. |
| Formulário Alterar Senha | Não apresenta aviso de ausência de usuário no navegador. |
| Portal do Morador | Carrega `/PWA/portal-morador-manifest.json` e `/PWA/js/portal.js`. |
| Console de Acesso | Carrega `/PWA/manifest.json`. |
| Central PWA | Health check confirma a implementação em `PWA/` e o adaptador de escopo. |
| Push e cache | Service Worker é registrado em `/firebase-messaging-sw.js` com escopo `/`, sem barra visual de atualização. |

## Observação de segurança

As credenciais Firebase continuam sendo carregadas pela API `api/api_pwa_sw_config.php`. Não inclua chaves, tokens ou configurações sensíveis manualmente em JavaScript estático.
