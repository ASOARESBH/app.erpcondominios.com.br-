# Guia de Implantação — Módulo Vigilante/Rondas Mobile

## Finalidade

Este guia instala o fluxo de ronda do Portal do Colaborador: leitura de QR Code, validação de ponto, confirmação, registro de SLA e histórico do dia. O pacote contém somente arquivos de implantação e documentação; os repositórios GitHub permanecem a fonte completa do código.

> Faça backup dos arquivos PHP e do banco antes de qualquer atualização. A implantação deve ser feita em uma janela controlada, pois o aplicativo só poderá usar o módulo depois que a API atualizada estiver no HostGator.

## Pré-requisitos

| Item | Condição necessária |
|---|---|
| Banco | MySQL/MariaDB 5.7 ou compatível. |
| Estrutura | Tabelas `ronda_rotas`, `ronda_pontos`, `ronda_vigilantes`, `ronda_registros` e `ronda_auditoria` criadas. |
| Colaborador | Usuário ativo no Portal do Colaborador, com vínculo ativo ao tenant. |
| Ponte de identidade | O e-mail do usuário em `usuarios` deve ser igual ao e-mail ativo em `rh_colaboradores`, no mesmo tenant. |
| Rota | Rota ativa, programada para o dia, com ponto QR ativo e vigilante ativo vinculado. |
| App | Nova versão Flutter compilada após atualizar os arquivos do repositório do aplicativo. |

## 1. Atualizar o banco de dados

No phpMyAdmin do banco correto, verifique primeiro se já existem as tabelas de rondas. Se não existirem, execute **uma única vez** o arquivo `sql/migration_rondas_vigilante_mysql57.sql` que acompanha o pacote. O script é compatível com MariaDB 5.7 e não depende de `ADD COLUMN IF NOT EXISTS`.

Após a migração, entre no painel administrativo de rondas e cadastre uma rota. Defina horário inicial, horário final, intervalo, repetições e tolerância. Depois cadastre pelo menos um ponto e vincule o colaborador vigilante à rota.

## 2. Atualizar a API no HostGator

No Gerenciador de Arquivos ou FTP, abra a pasta pública onde está a instalação do ERP, normalmente a raiz do domínio que contém a pasta `api/`. Envie e substitua os arquivos abaixo, preservando os mesmos caminhos:

| Arquivo local do pacote | Caminho final no servidor |
|---|---|
| `erp/api/api_colaborador_mobile.php` | `api/api_colaborador_mobile.php` |
| `erp/api/api_rondas_vigilante.php` | `api/api_rondas_vigilante.php` |
| `erp/api/helpers/ronda_helper.php` | `api/helpers/ronda_helper.php` |

Não altere no servidor os endpoints administrativos `qr_detalhe` e `registrar_leitura` de `api_rondas_vigilante.php`. Eles foram preservados; a nova integração móvel acontece em `api_colaborador_mobile.php`.

## 3. Compilar e instalar o aplicativo

No computador de desenvolvimento, atualize o repositório do app e gere uma compilação nova. O SDK Flutter precisa estar instalado e configurado no PATH.

```bash
git clone https://github.com/ASOARESBH/aplicativoerpcondominios.git
cd aplicativoerpcondominios
flutter pub get
flutter analyze
flutter test
flutter build apk --release
```

O arquivo Android final fica em `build/app/outputs/flutter-apk/app-release.apk`. Para instalação de teste, copie esse APK para o celular, habilite a permissão do gerenciador de arquivos para instalar aplicativos desconhecidos e abra o APK. Para iOS, use um Mac com Xcode e execute `flutter build ipa` após configurar assinatura Apple.

## 4. Teste funcional obrigatório

1. Abra o app e, na tela de login do morador, toque cinco vezes na logo para abrir o Portal do Colaborador.
2. Entre com o e-mail e senha de um usuário operacional do mesmo tenant.
3. No painel, abra **Vigilante** e aceite a permissão de câmera quando solicitada.
4. Leia o QR Code de um ponto ativo. Confira ponto, rota, janela e instruções no diálogo.
5. Confirme a leitura. O resultado deverá ser **No prazo** ou **Atrasado**, com os minutos quando aplicável.
6. Atualize a tela e confirme que a leitura está em **Histórico de hoje**.
7. No painel web administrativo de rondas, confirme o mesmo registro no dashboard/relatório.
8. Leia o mesmo QR novamente no mesmo ciclo. O sistema deve recusar com a mensagem de que o ponto já foi registrado neste ciclo.

## Diagnóstico de problemas

| Sintoma | Verificação e correção |
|---|---|
| `Sessão do colaborador não informada.` | Faça login novamente no Portal do Colaborador; o token Bearer é obrigatório. |
| `QR Code não pertence ao condomínio da sua sessão.` | O QR foi gerado em outro tenant. Use ponto do condomínio selecionado no login. |
| `O vigilante da sessão não está vinculado a esta rota.` | Cadastre ou ative o vínculo em `ronda_vigilantes`. |
| Mensagem de associação de colaborador | Corrija o e-mail para que `usuarios.email` e `rh_colaboradores.email` coincidam no mesmo tenant. |
| `Esta rota não está programada para hoje` ou fora da janela | Revise dias da semana, horários, intervalo e repetições da rota. |
| `Já foi registrado neste ciclo` | É a proteção de deduplicação esperada; aguarde o próximo ciclo ou leia o próximo ponto. |
| Erro 503 sobre estrutura | Execute a migração de rondas no banco do ERP e confira se todas as tabelas foram criadas. |

## Segurança operacional

O tenant é derivado apenas do token Bearer. O aplicativo não informa `tenant_id` nem `colaborador_id` ao servidor. Em casos excepcionais de cadastro sem correspondência única de e-mail, o servidor emite uma opção temporária e assinada; ela não expõe o ID de colaborador ao aplicativo.

O GPS não é requisitado nesta primeira versão. Caso outra versão do aplicativo envie GPS, a API valida faixa numérica e não coloca coordenadas nos logs de operação.
