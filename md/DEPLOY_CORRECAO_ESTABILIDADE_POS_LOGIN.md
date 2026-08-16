# Correção Estabilizadora — Loop Pós-Login

## Diagnóstico confirmado

A autenticação e o cookie de sessão foram validados diretamente no ambiente de produção. Após o login, o endpoint central `api/verificar_sessao_completa.php` retornou sessão ERP ativa, usuário Super-Admin, tenant ativo e HTTP `200`. Chamadas simultâneas ao endpoint também responderam com HTTP `200`.

O problema persistente estava no carregamento do layout: havia múltiplas verificações de sessão concorrentes, uma delas por endpoint legado, operações de DDL no caminho crítico de permissões e um Service Worker com escopo global que podia servir versões antigas de `login.html`, `layout-base.html` e scripts de autenticação.

## Correções incluídas

| Componente | Correção |
|---|---|
| `PWA/firebase-messaging-sw.js` | O cache continua atendendo o Portal do Morador, mas deixa de interceptar Login, Layout e scripts do ERP. A revisão de cache remove versões antigas durante a ativação. |
| `frontend/js/sidebar-superadmin.js` | Passa a reutilizar o estado da única verificação executada pelo `SessionManager`, sem consultar endpoint legado nem criar chamadas simultâneas. |
| `api/verificar_sessao_completa.php` | Expõe o contexto de tenant no contrato central de sessão, eliminando a necessidade de consulta secundária para montar o menu. |
| `api/api_permissoes_modulos.php` | Não executa DDL em cada carga do layout. As tabelas só são criadas quando realmente inexistentes. |

## Implantação no HostGator

Envie o conteúdo do pacote ZIP para `public_html`, preservando a estrutura de pastas. Não há migration SQL para esta correção.

Após o upload, é indispensável atualizar o Service Worker anteriormente instalado. No navegador afetado, abra `https://app.erpcondominios.com.br/frontend/login.html`, pressione `Ctrl+F5` e, se o banner PWA for mostrado, clique em **Atualizar**. Se o comportamento persistir por causa de uma instalação antiga, abra as ferramentas do navegador, vá em **Application → Service Workers**, clique em **Unregister** para `firebase-messaging-sw.js`, recarregue a página e faça login novamente. O novo worker será instalado sem armazenar os arquivos do ERP.

## Validação esperada

O login deve redirecionar uma única vez para `layout-base.html?page=dashboard` ou `layout-base.html?page=superadmin`. A sessão não pode voltar ao login em caso de cache antigo, falha temporária de permissões, timeout de API ou resposta transitória.
