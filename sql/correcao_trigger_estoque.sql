-- =====================================================
-- CORREÇÃO: REMOVER TRIGGER QUE CAUSA DUPLICAÇÃO
-- Sistema de Gestão de Estoque
-- Condomínio Serra da Liberdade
-- =====================================================

-- PROBLEMA IDENTIFICADO:
-- O trigger trg_entrada_estoque estava adicionando quantidade
-- automaticamente ao inserir movimentação, causando duplicação
-- quando o cadastro inicial já inseria a quantidade no produto.

-- SOLUÇÃO:
-- Remover o trigger e deixar a API controlar as quantidades
-- diretamente, garantindo controle total e evitando duplicações.

-- =====================================================
-- PASSO 1: REMOVER TRIGGER PROBLEMÁTICO
-- =====================================================

DROP TRIGGER IF EXISTS trg_entrada_estoque;

-- =====================================================
-- PASSO 2: REMOVER TRIGGER DE SAÍDA (SE EXISTIR)
-- =====================================================

DROP TRIGGER IF EXISTS trg_saida_estoque;

-- =====================================================
-- EXPLICAÇÃO
-- =====================================================

/*
ANTES (COM TRIGGER):
1. Cadastrar produto com 10 unidades → quantidade_estoque = 10
2. API registra movimentação de entrada de 10 unidades
3. TRIGGER detecta e adiciona mais 10 → quantidade_estoque = 20
4. RESULTADO: DUPLICAÇÃO!

DEPOIS (SEM TRIGGER):
1. Cadastrar produto com 10 unidades → quantidade_estoque = 10
2. API registra movimentação de entrada de 10 unidades (apenas histórico)
3. Quantidade permanece 10
4. RESULTADO: CORRETO!

Para entradas/saídas futuras:
- A API já controla as quantidades nas linhas 328-330 (entrada)
- A API já controla as quantidades nas linhas 376-378 (saída)
- Não é necessário trigger para isso
*/

-- =====================================================
-- OBSERVAÇÕES IMPORTANTES
-- =====================================================

/*
1. Execute este script no banco de dados para corrigir o problema
2. Produtos já cadastrados com duplicação precisam ser corrigidos manualmente
3. Para corrigir produtos duplicados, execute:
   
   UPDATE produtos_estoque 
   SET quantidade_estoque = quantidade_estoque / 2 
   WHERE quantidade_estoque > 0;
   
   ATENÇÃO: Verifique antes de executar! Pode haver produtos que não foram duplicados.

4. Após executar este script, novos cadastros funcionarão corretamente
5. A API já possui controle total das quantidades, não precisa de triggers
*/

-- =====================================================
-- FIM DA CORREÇÃO
-- =====================================================
