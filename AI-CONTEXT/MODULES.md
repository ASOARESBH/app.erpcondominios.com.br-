# Mapa de Módulos

O ERP é dividido em módulos de negócios independentes, acessíveis pelo `sidebar-controller.js` e `menu-controller.js`.

## 1. Administrativo & RH
- **Moradores/Dependentes**: Gestão de residentes, vinculação de veículos, controle de inadimplência.
- **Visitantes**: Controle de acesso temporário, integração com portaria.
- **RH**: Ponto, escala, cadastro de colaboradores.
- **Documentos (GED)**: Gestão Eletrônica de Documentos com versionamento e permissões por unidade.

## 2. Financeiro
- **Contas a Pagar/Receber**: Lançamentos, baixas parciais/totais.
- **Conciliação Bancária**: Importação de arquivos OFX.
- **Relatórios Financeiros**: DRE, fluxo de caixa.

## 3. Manutenção & Operação
- **Ordens de Serviço (O.S.)**: Abertura de chamados, mudança de status, interações.
- **Estoque/Inventário**: Controle de patrimônio e insumos.
- **Hidrômetros**: Leitura de consumo de água, cálculo de tarifas, geração de demonstrativos.

## 4. Segurança & Controle de Acesso
- **ControliD**: Integração direta com catracas e totens faciais.
- **Veículos**: Controle de frota interna.
- **Console de Acesso**: Tela otimizada para porteiros validarem entradas.

## 5. Portal PWA
- **App do Morador**: Interface PWA para celular (notificações push via Firebase, reserva de espaços, visualização de boletos).
