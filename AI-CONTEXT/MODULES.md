# Mapa de Módulos

O ERP é dividido em módulos de negócios independentes, acessíveis pelo `sidebar-controller.js` e `menu-controller.js`.

## 1. Administrativo & RH
- **Moradores/Dependentes**: Gestão de residentes, vinculação de veículos, controle de inadimplência. A lista de Moradores é paginada no servidor e aceita ordenação segura por nome, unidade ou ID; a aba Dependentes permanece independente.
- **Visitantes**: Controle de acesso temporário, integração com portaria.
- **RH**: Ponto, escala, cadastro de colaboradores.
- **Documentos (GED)**: Gestão Eletrônica de Documentos com versionamento e permissões por unidade.

## 2. Financeiro
- **Contas a Pagar/Receber**: Lançamentos, baixas parciais/totais.
- **Conciliação Bancária**: Importação de arquivos OFX.
- **Relatórios Financeiros**: DRE, fluxo de caixa.

## 3. Manutenção & Operação
- **Ordens de Serviço (O.S.)**: Abertura de chamados, mudança de status, interações e
  matriz tenant-aware de acesso por usuário/departamento com restritividade opcional.
- **Estoque/Inventário**: Controle de patrimônio e insumos.
- **Hidrômetros**: Leitura de consumo de água, cálculo de tarifas, geração de demonstrativos.

## 4. Segurança & Controle de Acesso
- **ControliD**: Integração direta com catracas e totens faciais.
- **Veículos**: Controle de frota interna.
- **Console de Acesso**: Tela otimizada para porteiros validarem entradas.

## 5. Portal PWA
- **App do Morador**: Interface PWA para celular (notificações push via Firebase, reserva de espaços, visualização de boletos).

- **Inadimplência**: Importação do Relatório de Inadimplência Detalhado BRCondos, snapshots históricos, comparação por Gleba, ranking, CSV e geração de PDF pelo fluxo de impressão. A tela mantém catálogo versionado para BRCondos, Superlógica, Ahreas, TownSq e PACTO; somente BRCondos está homologado atualmente. Exige permissão `inadimplencia` com nível mínimo `gerente`; consulte `AI-CONTEXT/INADIMPLENCIA.md`.


## 6. Projetos e Obras
- **Projetos**: classificação pública de uma O.S. existente (`os_chamados.projeto_publico=1`), com etapas, percentual, timeline, fotos e documentos; a aba Empresa configura o slug exclusivo do tenant para compartilhar `/projetos/{slug}` sem duplicar o cadastro operacional.


## 7. Parâmetros de Moradores

A rota SPA `config_moradores` pertence ao módulo protegido `sistema` e é acessada pelo cartão Moradores em Parâmetros do Sistema. A página possui as abas Moradores e Dependentes e configura campos obrigatórios e a regra de múltiplos veículos por dependente. O consumo administrativo usa `api/api_config_moradores.php`; o cadastro efetivo continua nos endpoints canônicos `api/api_moradores.php`, `api/api_dependentes.php` e `api/api_veiculos.php`.


## 8. Lista de Convidados

A aba Lista de Convidados é parte da rota SPA `registro` e usa `api/api_convidados.php` para previsão, busca por documento, histórico, cancelamento e autoria. O Registro Manual vincula o acesso ao convite e atualiza entradas/status na mesma transação. A migration é `sql/migration_lista_convidados_mysql57.sql`. A gestão de convites pelo Portal do Morador ("Meus Convidados"), o app Flutter e integrações de hardware permanecem fora desta entrega; o Portal existente apenas ganhou suporte aos filtros de histórico para Entregador Autorizado e Familiar.

Os tipos `Entregador Autorizado` e `Familiar` são categorias próprias nos registros e estão incluídos nos filtros/contadores de `relatorios`, CSV, PDFs gerais/ramificados e ranking, além do histórico do Portal do Morador.


## 9. Alertas da Unidade

A aba Alertas pertence à rota Moradores e ao RBAC `moradores`; suas APIs e dados vivem no domínio `alertas_unidade`. Regras são avaliadas em Registro Manual, Controle de Acesso e leitura de QR do Console, com ciência humana, bloqueio automático e histórico de acionamentos. O Portal web possui Meus Alertas e preflight de convites QR. Não confundir com `alertas_acesso`, que continua sendo o motor administrativo de detecção pós-evento. O app Flutter permanece fora do escopo autorizado nesta etapa.
