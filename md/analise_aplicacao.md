# Análise da Aplicação ERP Serra da Liberdade

## 1. Visão Geral

**Nome do Sistema:** ERP Serra da Liberdade  
**Tipo:** Sistema de Gestão Condominial Completo  
**Banco de Dados:** MySQL (inlaud99_erpserra)  
**Tecnologias:** PHP, HTML5, CSS3, JavaScript, MySQL

## 2. Estrutura do Projeto

### 2.1 Arquivos por Tipo
- **HTML:** 50 arquivos (interfaces de usuário)
- **PHP:** 53 arquivos (APIs e lógica de negócio)
- **SQL:** 16 arquivos (schemas de banco de dados)
- **CSS:** 1 arquivo principal (style.css)
- **JavaScript:** 3 arquivos (auth-guard.js, user-display.js, cep.js)

### 2.2 Estrutura de Diretórios
```
/home/ubuntu/
├── assets/
│   ├── css/
│   ├── images/
│   └── js/
├── bck23.11.2025/ (backup)
├── fornecedor/
├── ico/
├── js/
├── md/
├── new/
├── sql/
└── uploads/
```

## 3. Módulos Principais

### 3.1 Controle de Acesso e Portaria
- **Arquivos:** acesso.html, registro.html, visitantes.html
- **APIs:** api_acesso.php, api_visitantes.php
- **Funcionalidades:**
  - Controle de entrada/saída de veículos
  - Registro de visitantes
  - Integração com leitor RFID Control iD iDUHF
  - Liberação automática de cancela

### 3.2 Gestão de Moradores
- **Arquivos:** moradores.html, login_morador.html, portal.html
- **APIs:** api_moradores.php, api_morador_dados.php, validar_login_morador.php
- **Funcionalidades:**
  - Cadastro de moradores e unidades
  - Portal do morador
  - Autenticação e recuperação de senha
  - Gestão de veículos vinculados

### 3.3 Gestão de Estoque
- **Arquivos:** estoque.html, entrada_estoque.html, saida_estoque.html, relatorio_estoque.html
- **APIs:** api_estoque.php
- **Banco de Dados:** database_estoque.sql
- **Funcionalidades:**
  - Cadastro de produtos e categorias
  - Controle de entrada e saída
  - Alertas de estoque mínimo
  - Relatórios de movimentação
  - Rastreamento por morador/destino

### 3.4 Gestão de Hidrometros
- **Arquivos:** hidrometro.html, leitura.html, relatorios_hidrometro.html
- **APIs:** api_hidrometros.php, api_leituras.php, api_morador_hidrometro.php
- **Banco de Dados:** database_hidrometros.sql
- **Funcionalidades:**
  - Cadastro de hidrômetros por unidade
  - Registro de leituras mensais
  - Cálculo de consumo
  - Relatórios de consumo
  - Portal do morador para consulta

### 3.5 Sistema de Protocolos
- **Arquivos:** protocolo.html, relatorios_protocolo.html
- **APIs:** api_protocolos.php, api_morador_protocolos.php
- **Banco de Dados:** database_protocolos.sql
- **Funcionalidades:**
  - Abertura de chamados/solicitações
  - Acompanhamento de status
  - Histórico de protocolos
  - Anexos de documentos

### 3.6 Checklist e Manutenção
- **Arquivos:** checklist.html, manutencao.html
- **APIs:** api_checklist.php, api_checklist_itens.php, api_checklist_alertas.php
- **Banco de Dados:** database_checklist.sql
- **Funcionalidades:**
  - Criação de checklists personalizados
  - Agendamento de verificações
  - Alertas automáticos
  - Histórico de manutenções

### 3.7 Inventário Patrimonial
- **Arquivos:** inventario.html, relatorios_inventario.html
- **APIs:** api_inventario.php
- **Banco de Dados:** database_inventario.sql
- **Funcionalidades:**
  - Cadastro de bens patrimoniais
  - Controle de localização
  - Depreciação
  - Relatórios de inventário

### 3.8 Marketplace de Fornecedores
- **Arquivos:** marketplace.html, login_fornecedor.html, painel_fornecedor.html
- **APIs:** api_marketplace.php, api_fornecedores.php, api_login_fornecedor.php
- **Banco de Dados:** database_marketplace.sql
- **Funcionalidades:**
  - Cadastro de fornecedores
  - Catálogo de produtos/serviços
  - Sistema de avaliações
  - Painel administrativo para fornecedores

### 3.9 Sistema de Notificações
- **Arquivos:** notificacoes.html
- **APIs:** api_notificacoes.php, api_morador_notificacoes.php
- **Funcionalidades:**
  - Envio de notificações para moradores
  - Anexos de arquivos
  - Histórico de notificações
  - Portal do morador

### 3.10 Gestão de Veículos
- **Arquivos:** veiculos.html
- **APIs:** api_veiculos.php, api_morador_veiculos.php
- **Funcionalidades:**
  - Cadastro de veículos
  - Vinculação com moradores
  - Tags RFID
  - Controle de acesso

### 3.11 Abastecimento de Água
- **Arquivos:** abastecimento.html
- **APIs:** api_abastecimento.php
- **Banco de Dados:** sql_abastecimento.sql
- **Funcionalidades:**
  - Registro de abastecimentos
  - Controle de volume
  - Relatórios de consumo

## 4. Tecnologias e Integrações

### 4.1 Backend
- **Linguagem:** PHP 7.4+
- **Banco de Dados:** MySQL/MariaDB
- **Autenticação:** Sistema de sessões PHP
- **API:** RESTful com JSON

### 4.2 Frontend
- **HTML5:** Estrutura semântica
- **CSS3:** Design responsivo com gradientes
- **JavaScript:** Vanilla JS para interatividade
- **Font Awesome 6.4.0:** Ícones
- **Chart.js:** Gráficos (implícito nos relatórios)

### 4.3 Integrações Externas
- **RFID Control iD iDUHF:** Leitor de tags RFID para controle de acesso
- **ViaCEP:** API para busca de endereços (cep.js)
- **Sistema de E-mail:** Recuperação de senha e notificações

## 5. Segurança

### 5.1 Autenticação
- Sistema de login com senha criptografada
- Sessões PHP para controle de acesso
- Recuperação de senha via e-mail
- Verificação de sessão em todas as páginas protegidas

### 5.2 Proteção de Dados
- Sanitização de entradas (função sanitizar)
- Prepared Statements para prevenir SQL Injection
- Validação de CPF
- Logs de auditoria (logs_sistema)

### 5.3 Controle de Acesso
- Diferentes níveis de usuário (admin, morador, fornecedor)
- Verificação de sessão via API
- Logout seguro

## 6. Funcionalidades Especiais

### 6.1 Dashboard Administrativo
- **Arquivo:** dashboard.html, administrativa.html
- **APIs:** api_dashboard_acessos.php
- Estatísticas em tempo real
- Gráficos de acessos
- Cards de resumo

### 6.2 Portal do Morador
- **Arquivo:** portal.html
- Acesso a informações pessoais
- Consulta de hidrometro
- Visualização de notificações
- Gestão de veículos
- Abertura de protocolos

### 6.3 Sistema de Logs
- Registro de todas as ações
- Rastreamento de IP
- Auditoria completa

## 7. Banco de Dados

### 7.1 Tabelas Principais
- `moradores` - Cadastro de moradores
- `veiculos` - Veículos vinculados
- `registros_acesso` - Histórico de entradas/saídas
- `logs_sistema` - Auditoria
- `configuracoes` - Parâmetros do sistema
- `usuarios` - Usuários administrativos
- `unidades` - Unidades do condomínio
- `estoque_produtos` - Produtos em estoque
- `estoque_movimentacoes` - Entrada/saída de estoque
- `hidrometros` - Hidrômetros por unidade
- `leituras` - Leituras mensais
- `protocolos` - Chamados/solicitações
- `checklist` - Checklists de manutenção
- `inventario` - Bens patrimoniais
- `marketplace_produtos` - Catálogo de fornecedores
- `notificacoes` - Avisos para moradores

### 7.2 Recursos Avançados
- Views para relatórios
- Triggers para auditoria automática
- Índices otimizados
- Foreign Keys com CASCADE

## 8. Configurações

### 8.1 Banco de Dados (config.php)
```php
DB_HOST: localhost
DB_NAME: inlaud99_erpserra
DB_USER: inlaud99_admin
DB_PASS: Admin259087@
DB_CHARSET: utf8mb4
TIMEZONE: America/Sao_Paulo
```

### 8.2 Configurações do Sistema
- IP e porta do leitor RFID
- Liberação automática de cancela
- Tempo de abertura da cancela
- Nome do condomínio

## 9. Arquivos de Documentação

- `README_IMPLEMENTACAO.txt` - Guia de implementação do módulo de estoque
- `README_MORADOR.txt` - Documentação do portal do morador
- `README_ABASTECIMENTO.txt` - Sistema de abastecimento de água

## 10. Observações Técnicas

### 10.1 Pontos Fortes
- Sistema completo e integrado
- Arquitetura modular
- API RESTful bem estruturada
- Design responsivo
- Logs de auditoria
- Múltiplos módulos de gestão

### 10.2 Pontos de Atenção
- Credenciais de banco de dados expostas no código
- Necessidade de migração para variáveis de ambiente
- Alguns arquivos duplicados (dashboard .html vs dashboard.html)
- Backups misturados com código fonte

### 10.3 Recomendações
- Mover credenciais para arquivo .env
- Adicionar .gitignore para excluir backups e logs
- Implementar versionamento semântico
- Adicionar testes automatizados
- Documentar APIs com Swagger/OpenAPI
