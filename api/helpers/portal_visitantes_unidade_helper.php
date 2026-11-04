<?php
/**
 * Escopo de visitantes do Portal do Morador por unidade (Gleba).
 *
 * A tabela `visitantes` não guarda unidade: o vínculo com a Gleba é derivado
 * dos moradores ATIVOS da mesma unidade, no mesmo tenant, por três evidências:
 *   1. `visitantes.morador_id` (vínculo legado);
 *   2. `visitantes.cadastrado_por_morador_id` (autoria pelo portal/app);
 *   3. autorização em `acessos_visitantes` emitida para um morador da unidade
 *      (cobre visitantes da base geral cadastrados pela portaria/painel).
 *
 * A unidade é sempre obtida do cadastro do morador autenticado (nunca do
 * cliente) e comparada com a mesma normalização já usada pelo histórico de
 * acessos do portal: caixa, espaços e prefixo "GLEBA " ignorados.
 * O morador autenticado sempre enxerga os próprios cadastros, mesmo se a
 * unidade estiver vazia no cadastro.
 */

if (!function_exists('pvu_unidade_morador')) {
    function pvu_unidade_morador(mysqli $conexao, int $tenant_id, int $morador_id): string {
        $stmt = $conexao->prepare('SELECT unidade FROM moradores WHERE tenant_id = ? AND id = ? AND ativo = 1 LIMIT 1');
        if (!$stmt) return '';
        $stmt->bind_param('ii', $tenant_id, $morador_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return trim((string)($row['unidade'] ?? ''));
    }
}

if (!function_exists('pvu_condicao_visitante_unidade')) {
    /**
     * Retorna [sqlCondicao, tipos, parametros] para uso em WHERE.
     * $alias é o alias da tabela `visitantes` na consulta (ex.: 'v').
     */
    function pvu_condicao_visitante_unidade(string $alias, int $tenant_id, int $morador_id, string $unidade): array {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $alias)) {
            throw new InvalidArgumentException('Alias inválido.');
        }
        $a = $alias;

        if ($unidade === '') {
            // Sem unidade identificada: restringe ao próprio morador.
            $sub = 'SELECT pvu_m.id FROM moradores pvu_m WHERE pvu_m.tenant_id = ? AND pvu_m.id = ?';
            $subTipos = 'ii';
            $subParams = [$tenant_id, $morador_id];
        } else {
            $sub = "SELECT pvu_m.id FROM moradores pvu_m
                    WHERE pvu_m.tenant_id = ? AND pvu_m.ativo = 1
                      AND REPLACE(UPPER(TRIM(COALESCE(pvu_m.unidade, ''))), 'GLEBA ', '') = REPLACE(UPPER(TRIM(?)), 'GLEBA ', '')";
            $subTipos = 'is';
            $subParams = [$tenant_id, $unidade];
        }

        $sql = "(
                {$a}.morador_id = ?
             OR {$a}.cadastrado_por_morador_id = ?
             OR {$a}.morador_id IN ({$sub})
             OR {$a}.cadastrado_por_morador_id IN ({$sub})
             OR EXISTS (
                    SELECT 1 FROM acessos_visitantes pvu_a
                    WHERE pvu_a.tenant_id = {$a}.tenant_id
                      AND pvu_a.visitante_id = {$a}.id
                      AND pvu_a.morador_id IN ({$sub})
                )
            )";
        $tipos = 'ii' . $subTipos . $subTipos . $subTipos;
        $params = array_merge([$morador_id, $morador_id], $subParams, $subParams, $subParams);
        return [$sql, $tipos, $params];
    }
}

if (!function_exists('pvu_bind')) {
    function pvu_bind(mysqli_stmt $stmt, string $tipos, array $params): bool {
        $refs = [$tipos];
        foreach ($params as $i => $valor) {
            $refs[] = &$params[$i];
        }
        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}
