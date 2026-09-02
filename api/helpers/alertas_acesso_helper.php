<?php
if (!function_exists('alertas_acesso_normalizar')) {
    function alertas_acesso_normalizar($valor): string {
        $valor = trim((string)($valor ?? ''));
        $valor = function_exists('mb_strtolower') ? mb_strtolower($valor, 'UTF-8') : strtolower($valor);
        return preg_replace('/\s+/u', ' ', $valor) ?? $valor;
    }
}

if (!function_exists('alertas_acesso_match')) {
    function alertas_acesso_match(string $atual, string $operador, string $esperado): bool {
        $atual = alertas_acesso_normalizar($atual);
        $esperado = alertas_acesso_normalizar($esperado);
        if ($atual === '' || $esperado === '') return false;
        if ($operador === 'contem') return strpos($atual, $esperado) !== false;
        if ($operador === 'comeca_com') return strpos($atual, $esperado) === 0;
        return $atual === $esperado;
    }
}

if (!function_exists('alertas_acesso_processar_evento')) {
    function alertas_acesso_processar_evento(mysqli $conexao, int $tenant_id, string $origem, array $evento, ?string $evento_uuid = null): array {
        if ($tenant_id <= 0) return [];
        $evento_uuid = $evento_uuid ?: hash('sha256', $origem . '|' . json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '|' . microtime(true));
        $campos = [
            'placa' => (string)($evento['placa'] ?? ''),
            'modelo' => (string)($evento['modelo'] ?? ''),
            'cor' => (string)($evento['cor'] ?? ''),
            'pessoa_nome' => (string)($evento['pessoa_nome'] ?? $evento['morador_nome'] ?? ''),
            'pessoa_cpf' => (string)($evento['pessoa_cpf'] ?? $evento['cpf'] ?? ''),
            'telefone' => (string)($evento['telefone'] ?? $evento['celular'] ?? ''),
            'unidade' => (string)($evento['unidade'] ?? $evento['morador_unidade'] ?? ''),
            'observacao' => (string)($evento['observacao'] ?? $evento['descricao'] ?? ''),
        ];
        $alertas = [];
        $res = $conexao->query("SELECT * FROM alertas_acesso WHERE tenant_id = " . (int)$tenant_id . " AND ativo = 1 ORDER BY severidade DESC, id ASC");
        if (!$res) return [];
        while ($alerta = $res->fetch_assoc()) {
            $stmt = $conexao->prepare('SELECT tipo,campo,operador,valor FROM alertas_acesso_criterios WHERE tenant_id=? AND alerta_id=? ORDER BY id ASC');
            if (!$stmt) continue;
            $aid = (int)$alerta['id'];
            $stmt->bind_param('ii', $tenant_id, $aid);
            $stmt->execute();
            $criterios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (!$criterios) continue;
            $corresponde = true;
            foreach ($criterios as $criterio) {
                $campo = (string)$criterio['campo'];
                if (!array_key_exists($campo, $campos) || !alertas_acesso_match($campos[$campo], (string)$criterio['operador'], (string)$criterio['valor'])) {
                    $corresponde = false;
                    break;
                }
            }
            if (!$corresponde) continue;
            $dados_json = json_encode(['origem' => $origem, 'evento' => $evento, 'alerta' => $alerta['nome']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmtEv = $conexao->prepare("INSERT INTO alertas_acesso_eventos (tenant_id, alerta_id, evento_uuid, origem, dados_json, status) VALUES (?,?,?,?,?,'pendente') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            if (!$stmtEv) continue;
            $stmtEv->bind_param('iisss', $tenant_id, $aid, $evento_uuid, $origem, $dados_json);
            if (!$stmtEv->execute()) { $stmtEv->close(); continue; }
            $evento_id = (int)$conexao->insert_id;
            $stmtEv->close();
            $canais = json_decode((string)$alerta['canais_json'], true);
            if (!is_array($canais) || !$canais) $canais = ['sistema'];
            foreach (array_unique($canais) as $canal) {
                if (!in_array($canal, ['sistema','email','whatsapp'], true)) continue;
                $detalhe = $canal === 'sistema' ? 'Alerta disponível no sistema' : 'Aguardando dispatcher do canal';
                $stmtEnt = $conexao->prepare('INSERT IGNORE INTO alertas_acesso_entregas (tenant_id,evento_id,canal,status,detalhe) VALUES (?,?,?,\'pendente\',?)');
                if ($stmtEnt) { $stmtEnt->bind_param('iiss', $tenant_id, $evento_id, $canal, $detalhe); $stmtEnt->execute(); $stmtEnt->close(); }
                if ($canal === 'sistema') {
                    $usuarios = $conexao->query("SELECT id FROM usuarios WHERE tenant_id=" . (int)$tenant_id . " AND ativo=1");
                    if ($usuarios) while ($usuario = $usuarios->fetch_assoc()) {
                        $uid = (int)$usuario['id'];
                        $stmtRead = $conexao->prepare('INSERT IGNORE INTO alertas_acesso_leituras (tenant_id,evento_id,usuario_id) VALUES (?,?,?)');
                        if ($stmtRead) { $stmtRead->bind_param('iii', $tenant_id, $evento_id, $uid); $stmtRead->execute(); $stmtRead->close(); }
                    }
                }
            }
            $conexao->query("UPDATE alertas_acesso_eventos SET status='notificado' WHERE tenant_id=" . (int)$tenant_id . " AND id=" . $evento_id . " AND status='pendente'");
            $alertas[] = ['id' => $aid, 'evento_id' => $evento_id, 'nome' => $alerta['nome'], 'severidade' => $alerta['severidade'], 'canais' => $canais];
        }
        return $alertas;
    }
}
