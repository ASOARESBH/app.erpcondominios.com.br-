-- ============================================================================
-- ERP CONDOMÍNIO — CORREÇÃO DE AUTORIA HISTÓRICA DE VISITANTES
-- Compatível com MySQL / MariaDB 5.7
-- Pré-requisito: migration_autoria_visitantes_mysql57.sql já executada.
-- ============================================================================
--
-- Regra de segurança: somente atribui autor quando existir identificador
-- verificável e vínculo com o mesmo tenant. Registros sem evidência continuam
-- como LEGADO; não é permitido presumir o usuário atual como autor histórico.
-- ============================================================================

START TRANSACTION;

-- 1. Recuperar cadastros administrativos antigos quando o log contém o ID
--    exato do visitante e o nome de um usuário vinculado ao mesmo tenant.
--    A descrição histórica era gravada como: "Visitante cadastrado: ... ID: N)".
UPDATE visitantes v
INNER JOIN logs_sistema l
        ON l.usuario IS NOT NULL
       AND l.usuario <> ''
       AND l.descricao LIKE 'Visitante cadastrado%'
       AND l.descricao LIKE CONCAT('%ID: ', v.id, ')%')
INNER JOIN usuarios u
        ON u.nome = l.usuario
INNER JOIN usuario_tenant ut
        ON ut.usuario_id = u.id
       AND ut.tenant_id = v.tenant_id
SET v.cadastrado_por_tipo = 'FUNCIONARIO',
    v.cadastrado_por_usuario_id = u.id,
    v.cadastrado_por_nome = u.nome
WHERE v.cadastrado_por_usuario_id IS NULL
  AND v.cadastrado_por_morador_id IS NULL
  AND v.morador_id IS NULL
  AND (v.cadastrado_por_tipo IS NULL OR v.cadastrado_por_tipo = '' OR v.cadastrado_por_tipo = 'LEGADO');

-- 2. Recuperar cadastros administrativos quando o ID do usuário já estiver
--    preservado no próprio visitante e fizer parte do tenant correspondente.
UPDATE visitantes v
INNER JOIN usuario_tenant ut
        ON ut.tenant_id = v.tenant_id
       AND ut.usuario_id = v.cadastrado_por_usuario_id
       AND ut.ativo = 1
INNER JOIN usuarios u
        ON u.id = v.cadastrado_por_usuario_id
SET v.cadastrado_por_tipo = 'FUNCIONARIO',
    v.cadastrado_por_nome = u.nome
WHERE v.cadastrado_por_usuario_id IS NOT NULL
  AND (v.cadastrado_por_tipo <> 'FUNCIONARIO'
       OR v.cadastrado_por_nome IS NULL
       OR v.cadastrado_por_nome = ''
       OR v.cadastrado_por_nome = 'Cadastro legado');

-- 3. Recuperar cadastros do portal pelo autor explicitamente registrado ou,
--    nos registros antigos, pelo morador_id original.
UPDATE visitantes v
INNER JOIN moradores m
        ON m.id = COALESCE(v.cadastrado_por_morador_id, v.morador_id)
       AND m.tenant_id = v.tenant_id
SET v.cadastrado_por_tipo = 'MORADOR',
    v.cadastrado_por_morador_id = COALESCE(v.cadastrado_por_morador_id, v.morador_id),
    v.cadastrado_por_nome = m.nome
WHERE (v.cadastrado_por_morador_id IS NOT NULL OR v.morador_id IS NOT NULL)
  AND (v.cadastrado_por_tipo <> 'MORADOR'
       OR v.cadastrado_por_nome IS NULL
       OR v.cadastrado_por_nome = ''
       OR v.cadastrado_por_nome = 'Cadastro legado');

-- 4. Manter o rótulo LEGADO somente para linhas sem qualquer identificação
--    comprovável de usuário ou morador.
UPDATE visitantes v
SET v.cadastrado_por_tipo = 'LEGADO',
    v.cadastrado_por_nome = 'Cadastro legado'
WHERE v.cadastrado_por_usuario_id IS NULL
  AND v.cadastrado_por_morador_id IS NULL
  AND v.morador_id IS NULL
  AND (v.cadastrado_por_tipo IS NULL OR v.cadastrado_por_tipo = '' OR v.cadastrado_por_tipo = 'LEGADO')
  AND (v.cadastrado_por_nome IS NULL OR v.cadastrado_por_nome = '');

COMMIT;

-- Auditoria obrigatória pós-execução.
SELECT v.tenant_id,
       v.cadastrado_por_tipo,
       COUNT(*) AS total
FROM visitantes v
GROUP BY v.tenant_id, v.cadastrado_por_tipo
ORDER BY v.tenant_id, v.cadastrado_por_tipo;

SELECT v.id,
       v.tenant_id,
       v.nome_completo,
       v.cadastrado_por_nome,
       v.cadastrado_por_tipo,
       v.cadastrado_por_usuario_id,
       v.cadastrado_por_morador_id,
       v.morador_id
FROM visitantes v
WHERE v.cadastrado_por_tipo = 'LEGADO'
ORDER BY v.tenant_id, v.id DESC
LIMIT 100;
