# Regras de Negócio Críticas

## 1. Moradores
- Um morador pode ter múltiplos dependentes e veículos.
- Um morador NÃO pode ser cadastrado como visitante simultaneamente.
- Desativação de morador (ativo=0) não exclui seus dados históricos.

## 2. Financeiro
- **Status de Contas a Pagar**: `PENDENTE` → `PARCIAL` → `PAGO`.
- Uma conta marcada como `PAGO` não pode ser reaberta sem permissão de `admin`.
- O saldo devedor é calculado automaticamente: `valor_total - valor_pago`.

## 3. Hidrômetros
- Cada unidade tem um hidrômetro associado.
- A leitura mensal gera um `lancamento_agua` com base na diferença entre a leitura atual e a anterior.
- O valor cobrado é calculado por faixas de consumo (tarifa progressiva).

## 4. Ordens de Serviço
- **Status**: `Aberto` → `Em Andamento` → `Finalizado` / `Cancelado`.
- Ao adicionar uma interação, o status muda automaticamente para `Em Andamento`.
- O solicitante (morador) recebe notificação push ao abrir e ao finalizar a O.S.

## 5. Documentos (GED)
- Documentos podem ter visibilidade: `todos`, `moradores`, `usuarios` ou `unidades_especificas`.
- Arquivos são armazenados em `uploads/documentos/` com nome gerado por hash para evitar conflitos.
