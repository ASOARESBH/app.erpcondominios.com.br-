-- Diagnóstico somente leitura: não altera O.S. nem cadastro de moradores.
-- Lista vínculos em que a unidade copiada na abertura da O.S. difere da unidade atual.
SELECT
    o.tenant_id,
    o.id AS os_id,
    o.numero AS os_numero,
    o.morador_id,
    o.morador_nome AS os_morador_nome,
    TRIM(COALESCE(o.morador_unidade, '')) AS unidade_registrada_os,
    TRIM(COALESCE(m.nome, '')) AS morador_nome_atual,
    TRIM(COALESCE(m.unidade, '')) AS unidade_atual_cadastro
FROM os_chamados o
JOIN moradores m
  ON m.tenant_id = o.tenant_id
 AND m.id = o.morador_id
WHERE o.morador_id IS NOT NULL
  AND UPPER(TRIM(COALESCE(o.morador_unidade, ''))) <> UPPER(TRIM(COALESCE(m.unidade, '')))
ORDER BY o.tenant_id, o.morador_unidade, o.numero;
