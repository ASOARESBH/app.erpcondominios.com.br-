-- =====================================================
-- SCRIPT DE TESTE: VALIDAR CORREÇÃO DE DUPLICAÇÃO
-- Sistema de Gestão de Estoque
-- =====================================================

-- PASSO 1: Verificar se os triggers foram removidos
-- =====================================================
SHOW TRIGGERS LIKE 'trg_%estoque';

-- Resultado esperado: Nenhum trigger encontrado
-- Se ainda aparecer, execute novamente:
-- DROP TRIGGER IF EXISTS trg_entrada_estoque;
-- DROP TRIGGER IF EXISTS trg_saida_estoque;


-- PASSO 2: Verificar produtos cadastrados recentemente
-- =====================================================
SELECT 
    id,
    codigo,
    nome,
    quantidade_estoque,
    preco_unitario,
    (quantidade_estoque * preco_unitario) AS valor_total_calculado,
    data_cadastro,
    data_atualizacao
FROM produtos_estoque
WHERE ativo = 1
ORDER BY data_cadastro DESC
LIMIT 10;

-- Verifique se as quantidades estão corretas
-- Se estiverem duplicadas, veja PASSO 4


-- PASSO 3: Verificar movimentações recentes
-- =====================================================
SELECT 
    m.id,
    m.tipo_movimentacao,
    p.codigo,
    p.nome AS produto_nome,
    m.quantidade,
    m.quantidade_anterior,
    m.quantidade_posterior,
    m.motivo,
    m.data_movimentacao
FROM movimentacoes_estoque m
INNER JOIN produtos_estoque p ON m.produto_id = p.id
ORDER BY m.data_movimentacao DESC
LIMIT 20;

-- Verifique se as movimentações estão coerentes com as quantidades


-- PASSO 4: CORRIGIR produtos duplicados (SE NECESSÁRIO)
-- =====================================================
-- ⚠️ ATENÇÃO: Execute apenas se confirmar que há duplicação!
-- ⚠️ Faça backup antes de executar!

-- 4.1. Identificar produtos possivelmente duplicados
SELECT 
    id,
    codigo,
    nome,
    quantidade_estoque,
    (quantidade_estoque / 2) AS quantidade_corrigida,
    preco_unitario,
    (quantidade_estoque * preco_unitario) AS valor_total_atual,
    ((quantidade_estoque / 2) * preco_unitario) AS valor_total_corrigido,
    data_cadastro
FROM produtos_estoque
WHERE quantidade_estoque > 0
AND ativo = 1
AND data_cadastro >= '2025-11-01'  -- Ajuste a data conforme necessário
ORDER BY data_cadastro DESC;

-- 4.2. Se confirmar duplicação, execute a correção:
/*
UPDATE produtos_estoque 
SET quantidade_estoque = quantidade_estoque / 2 
WHERE quantidade_estoque > 0 
AND ativo = 1
AND data_cadastro >= '2025-11-01';  -- Ajuste a data conforme necessário
*/


-- PASSO 5: Teste prático - Inserir produto de teste
-- =====================================================
-- Execute este INSERT para testar se a correção funcionou

-- 5.1. Inserir produto de teste
INSERT INTO produtos_estoque 
(codigo, nome, categoria_id, unidade_medida, descricao, preco_unitario, quantidade_estoque, estoque_minimo, estoque_maximo, localizacao, fornecedor) 
VALUES 
('TESTE-001', 'Produto Teste Correção', 1, 'Unidade', 'Produto para testar correção de duplicação', 10.00, 5.00, 1.00, 10.00, 'Teste', 'Fornecedor Teste');

-- 5.2. Obter ID do produto inserido
SET @produto_teste_id = LAST_INSERT_ID();

-- 5.3. Registrar movimentação de entrada (como faz a API)
INSERT INTO movimentacoes_estoque 
(produto_id, tipo_movimentacao, quantidade, quantidade_anterior, quantidade_posterior, usuario_responsavel, motivo, valor_unitario, valor_total) 
VALUES 
(@produto_teste_id, 'Entrada', 5.00, 0, 5.00, 'Sistema', 'Estoque inicial - Teste', 10.00, 50.00);

-- 5.4. Verificar se a quantidade permaneceu correta (5 unidades)
SELECT 
    id,
    codigo,
    nome,
    quantidade_estoque,
    preco_unitario,
    (quantidade_estoque * preco_unitario) AS valor_total
FROM produtos_estoque
WHERE id = @produto_teste_id;

-- Resultado esperado:
-- quantidade_estoque = 5.00 (NÃO 10.00)
-- valor_total = 50.00 (NÃO 100.00)

-- 5.5. Limpar produto de teste
DELETE FROM movimentacoes_estoque WHERE produto_id = @produto_teste_id;
DELETE FROM produtos_estoque WHERE id = @produto_teste_id;


-- PASSO 6: Verificar integridade geral do estoque
-- =====================================================
SELECT 
    COUNT(*) AS total_produtos,
    SUM(quantidade_estoque) AS quantidade_total,
    SUM(quantidade_estoque * preco_unitario) AS valor_total_estoque,
    AVG(quantidade_estoque) AS media_quantidade,
    MIN(quantidade_estoque) AS menor_estoque,
    MAX(quantidade_estoque) AS maior_estoque
FROM produtos_estoque
WHERE ativo = 1;


-- PASSO 7: Verificar produtos com estoque baixo
-- =====================================================
SELECT 
    codigo,
    nome,
    quantidade_estoque,
    estoque_minimo,
    (estoque_minimo - quantidade_estoque) AS deficit,
    preco_unitario,
    (estoque_minimo - quantidade_estoque) * preco_unitario AS valor_reposicao
FROM produtos_estoque
WHERE quantidade_estoque <= estoque_minimo
AND ativo = 1
ORDER BY deficit DESC;


-- =====================================================
-- RESULTADO ESPERADO APÓS CORREÇÃO
-- =====================================================
/*
✅ PASSO 1: Nenhum trigger encontrado
✅ PASSO 2: Quantidades corretas (não duplicadas)
✅ PASSO 3: Movimentações coerentes
✅ PASSO 5: Produto teste com 5 unidades (não 10)
✅ PASSO 6: Valores totais coerentes
✅ PASSO 7: Produtos com estoque baixo identificados corretamente

Se todos os passos estiverem OK, a correção foi aplicada com sucesso!
*/
