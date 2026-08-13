# Implantação — Central PWA e Módulo Vigilante

## Objetivo

Esta entrega transfere a administração da **Central PWA** para o contexto global do **Super-Admin**, dentro do submenu **Aplicativos**, e introduz o módulo operacional **Manutenção → Vigilante** para gestão de rondas por QR Code. A operação de rondas é isolada automaticamente pelo condomínio da sessão e usa os colaboradores ativos do módulo de Recursos Humanos como vigilantes vinculáveis.

> A Central PWA passa a ser uma configuração institucional. Ela não deve mais ser acessada pelos menus operacionais de um condomínio.

## Ordem obrigatória de implantação

| Etapa | Ação | Resultado esperado |
|---|---|---|
| 1 | Fazer backup do banco `inlaud99_erpcondor`. | Possibilidade de restauração antes da criação do módulo. |
| 2 | Importar `sql/migration_rondas_vigilante_mysql57.sql` no phpMyAdmin. | Criação das tabelas `ronda_rotas`, `ronda_pontos`, `ronda_vigilantes`, `ronda_registros` e `ronda_auditoria`. |
| 3 | Enviar o conteúdo do pacote ZIP ao `public_html`, preservando os diretórios. | APIs, páginas, estilos, scripts e menu ficam disponíveis. |
| 4 | Sair e entrar novamente com a conta Super-Admin. | A sessão é recarregada no contexto global. |
| 5 | Executar `Ctrl+F5`. | O navegador descarrega scripts e estilos antigos. |

## Acesso administrativo

O Super-Admin deve acessar **Aplicativos → Central PWA** para configurações institucionais do Portal do Morador, Firebase, dispositivos, logs e versões PWA. O menu operacional e a tela de Manutenção não exibem atalhos para essa central; no lugar dela, a área de Manutenção apresenta o cartão e a aba **Vigilante**.

O módulo **Manutenção → Vigilante** deve ser usado dentro da sessão operacional do condomínio. O tenant é resolvido exclusivamente pela sessão autenticada; não existe seletor de condomínio e nenhum `tenant_id` é aceito pela URL ou pelo formulário.

## Configuração de uma rota

Crie uma rota e informe nome, horário inicial, horário final opcional, duração do ciclo, quantidade de repetições, tolerância de SLA e dias da semana. Em seguida, adicione os pontos físicos do percurso. Cada ponto recebe um token aleatório e um QR Code próprio, que pode ser baixado ou impresso com nome da rota, local e instruções.

Depois, vincule um ou mais colaboradores ativos do RH à rota. Apenas colaboradores vinculados conseguem confirmar leituras para aquela rota. O QR Code abre a página móvel `ronda_checkin.html`, na qual o vigilante seleciona seu nome e confirma a passagem. O registro guarda data, horário, situação do SLA, IP, navegador e, quando o celular autorizar, localização aproximada.

## SLA e alertas

O dashboard calcula o ciclo atual a partir do horário inicial, intervalo, número de repetições e tolerância de cada rota. Se os pontos de um ciclo não forem registrados após a tolerância, a rota aparece nos **Alertas de SLA**. O cálculo é realizado no carregamento e a cada atualização do dashboard; portanto, não depende de tarefa agendada no HostGator nesta primeira versão.

A página inclui relatório por período, rota e vigilante, além de exportação CSV. Leituras atrasadas e no prazo são destacadas separadamente. Rotas e pontos removidos são arquivados, e não apagados, para preservar a rastreabilidade histórica.

## Validação pós-implantação

| Verificação | Resultado esperado |
|---|---|
| Super-Admin global | Exibe `Painel Super-Admin` e `Aplicativos`, com `Central PWA` como submenu institucional. |
| Usuário de condomínio | Acessa `Manutenção → Vigilante`, mas não acessa a API administrativa da Central PWA. |
| Nova rota | É criada somente para o condomínio da sessão ativa. |
| Ponto QR | Gera etiqueta distinta, com URL de leitura própria. |
| Vigilante não vinculado | Recebe bloqueio ao tentar registrar leitura. |
| Leitura repetida no mesmo ciclo | Recebe bloqueio para evitar duplicidade. |
| Atraso | Surge no dashboard após extrapolar a tolerância de SLA. |

## Segurança e evolução

O QR Code usa token aleatório de 64 caracteres e é invalidado quando regenerado. A página móvel valida o token, o ponto, a rota, o condomínio ativo e o vínculo do colaborador antes da gravação. Para uma próxima etapa, podem ser adicionados login individual de vigilante, PIN pessoal, geofence obrigatório, fotos de evidência, notificações push de SLA e processamento automatizado por cron.

## Arquivos principais

| Arquivo | Finalidade |
|---|---|
| `api/api_pwa_central.php` | Central PWA restrita ao Super-Admin global. |
| `api/api_rondas_vigilante.php` | API Multi-Tenant de rotas, QR, SLA, registros e relatórios. |
| `frontend/pages/rondas_vigilante.html` | Painel administrativo de rondas. |
| `frontend/js/pages/rondas_vigilante.js` | Interface de cadastro, dashboard, QR e relatórios. |
| `frontend/ronda_checkin.html` | Tela móvel aberta pelo QR Code. |
| `frontend/js/ronda_checkin.js` | Validação e registro da passagem. |
| `sql/migration_rondas_vigilante_mysql57.sql` | Criação das tabelas compatíveis com MySQL/MariaDB 5.7. |
