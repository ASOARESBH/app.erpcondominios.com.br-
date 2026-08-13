# Changelog

Registro de alterações significativas no ERP.

## [2.1.1] - 2026-08-13
### Corrigido
- **Portal do Morador PWA**: Removida a barra visual fixa de “Nova versão disponível!”. A atualização passou a seguir o ciclo nativo conservador do Service Worker: a nova versão permanece em espera e assume em um ciclo seguro, sem recarregar formulários em uso.
- Mantidos o registro em `/firebase-messaging-sw.js`, o listener `controllerchange`, Firebase Cloud Messaging, cache offline e o banner de instalação do PWA.


## [2.1.0] - 2026-02-xx
### Adicionado
- **Manual do Sistema**: Novo módulo com artigos interativos, busca, favoritos e relatórios.
- **Módulo GED (Documentos)**: Gerenciamento Eletrônico de Documentos integrado à Manutenção, com versionamento e visibilidade por unidade.
- **AI Context Framework**: Criação da base de conhecimento permanente (`AI-CONTEXT/`) para inteligências artificiais.

## [2.0.0] - 2026-01-25
### Adicionado
- **Sistema de Dependentes**: Cadastro completo vinculado a moradores.
- **Integração ControliD**: Rota de webhook para catracas.
- **App PWA**: Portal do Morador com Push Notifications (Firebase).

### Corrigido
- Loop de redirecionamento no `session-manager-core.js`.
- Correção de layout no CSS do `input-wrapper` na tela de login.
