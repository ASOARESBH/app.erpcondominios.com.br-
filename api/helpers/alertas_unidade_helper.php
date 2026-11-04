<?php
/**
 * Helper do domínio Alertas da Unidade.
 *
 * Contrato de integração para api_registros.php/api_rfid.php:
 *   alertas_unidade_avaliar(mysqli $c, int $tenantId, array $ctx, bool $forUpdate=false): array
 *   alertas_unidade_criar_token_ciencia(mysqli $c, int $tenantId, int $usuarioId,
 *       array $ctx, array $alertas, array $decisoes): string
 *   alertas_unidade_validar_consumir_token(mysqli $db, int $tenantId, int $usuarioId,
 *       array $contextoCanonico, ?string $token, int $registroAcessoId,
 *       array $decisoesEsperadas = []): array
 *   alertas_unidade_construir_contexto_registro(mysqli $db, int $tenantId,
 *       array $registro): array
 *   alertas_unidade_registrar_eventos(mysqli $c, int $tenantId, array $ctx,
 *       int $registroId, array $alertas, array $decisoes, array $opcoes=[]): array
 *   alertas_unidade_notificar_morador(mysqli $c, int $tenantId, array $alerta,
 *       int $moradorId, ?int $registroId, array $ctx, string $decisao): array
 *
 * A API de ciência cria somente um token opaco (SHA-256 persistido). A ciência
 * permitida só é inserida por validar_consumir_token(), dentro da mesma
 * transação que gravou o registro_acesso (o chamador deve iniciar/confirmar ou
 * desfazer essa transação). A negativa, que não libera acesso, pode ser gravada
 * diretamente por registrar_eventos() sem registro_acesso_id.
 *
 * Campos incluídos no contexto hash do token: contexto, unidade, morador_id,
 * dependente_id, tipo_pessoa, visitante_id, SHA-256 do documento normalizado,
 * tipo_documento, placa normalizada, tipo_acesso e data_hora normalizada. O
 * tenant_id e usuario_id são também chaves da consulta/linha do token; não são
 * aceitos do payload e não são substituídos pelos valores do contexto.
 */

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/protocol_notification_helper.php';

if (!function_exists('alertas_unidade_normalizar_documento')) {
    function alertas_unidade_normalizar_documento($documento, $tipo = '') {
        $valor = strtoupper(trim((string)$documento));
        $tipo = strtoupper(trim((string)$tipo));
        if ($tipo === 'CPF') return preg_replace('/[^0-9]/', '', $valor);
        return preg_replace('/[^A-Z0-9]/', '', $valor);
    }
}

if (!function_exists('alertas_unidade_normalizar_placa')) {
    function alertas_unidade_normalizar_placa($placa) {
        $valor = strtoupper(trim((string)$placa));
        return preg_replace('/[^A-Z0-9]/', '', $valor);
    }
}

if (!function_exists('alertas_unidade_mascarar_documento')) {
    function alertas_unidade_mascarar_documento($documento, $tipo = '') {
        $normalizado = alertas_unidade_normalizar_documento($documento, $tipo);
        if ($normalizado === '') return null;
        if (strtoupper(trim((string)$tipo)) === 'CPF' && strlen($normalizado) === 11) {
            return '***.' . substr($normalizado, 3, 3) . '.' . substr($normalizado, 6, 3) . '-**';
        }
        if (strlen($normalizado) <= 4) return str_repeat('*', max(3, strlen($normalizado)));
        return str_repeat('*', max(3, strlen($normalizado) - 4)) . substr($normalizado, -4);
    }
}

if (!function_exists('alertas_unidade_mapear_tipo')) {
    function alertas_unidade_mapear_tipo($tipo) {
        $valor = strtoupper(trim((string)$tipo));
        $valor = strtr($valor, ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C']);
        $valor = preg_replace('/[^A-Z0-9]+/', '_', $valor);
        $mapa = [
            'VISITANTE' => 'VISITANTE',
            'PRESTADOR' => 'PRESTADOR',
            'ENTREGADOR_AUTORIZADO' => 'ENTREGADOR_AUTORIZADO',
            'ENTREGADOR' => 'ENTREGADOR_AUTORIZADO',
            'FAMILIAR' => 'FAMILIAR',
            'MORADOR' => 'MORADOR',
            'DEPENDENTE' => 'DEPENDENTE',
            'VEICULO' => 'VEICULO',
            'VEICULO_PLACA' => 'VEICULO',
            'TODOS' => 'TODOS',
        ];
        return $mapa[$valor] ?? null;
    }
}

if (!function_exists('alertas_unidade_usuario_pode_override')) {
    function alertas_unidade_usuario_pode_override(mysqli $db, int $usuarioId, int $tenantId): bool {
        if ($usuarioId <= 0 || $tenantId <= 0) return false;
        if (function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($db) && function_exists('rbacPode')) {
            return rbacPode($db, 'moradores', 'aprovar', $usuarioId, $tenantId);
        }
        $perfil = strtolower(trim((string)($_SESSION['usuario_permissao'] ?? $_SESSION['perfil'] ?? $_SESSION['tipo_usuario'] ?? '')));
        return in_array($perfil, ['admin','administrador','gerente','supervisor'], true);
    }
}

if (!function_exists('alertas_unidade_migration_instalada')) {
    function alertas_unidade_migration_instalada(mysqli $db): bool {
        $tabelas = ['alertas_unidade','alertas_unidade_alvos','alertas_unidade_acionamentos','alertas_unidade_ciencia_tokens','alertas_unidade_ciencias','alertas_unidade_historico','alertas_unidade_rate_limits','alertas_unidade_notificacoes','alertas_unidade_lembretes','alertas_unidade_preferencias','alertas_unidade_cache_ids'];
        $stmt = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE BINARY TABLE_SCHEMA=BINARY DATABASE() AND BINARY TABLE_NAME=BINARY ? LIMIT 1');
        if (!$stmt) {
            error_log('Alertas da Unidade: não foi possível preparar a verificação da migration. MySQL errno=' . (int)$db->errno);
            return false;
        }
        foreach ($tabelas as $tabela) {
            $stmt->bind_param('s', $tabela);
            if (!$stmt->execute()) {
                error_log('Alertas da Unidade: falha ao verificar migration. MySQL errno=' . (int)$stmt->errno);
                $stmt->close();
                return false;
            }
            $resultado = $stmt->get_result();
            if (!$resultado || !$resultado->fetch_row()) {
                error_log('Alertas da Unidade: tabela obrigatória ausente na migration: ' . $tabela);
                $stmt->close();
                return false;
            }
        }
        $stmt->close();
        return true;
    }
}

if (!function_exists('alertas_unidade_morador_push_ativo')) {
    function alertas_unidade_morador_push_ativo(mysqli $db, int $tenantId, int $moradorId): bool {
        if ($tenantId <= 0 || $moradorId <= 0) return false;
        $stmt = au_query($db, 'SELECT push_ativo FROM alertas_unidade_preferencias WHERE tenant_id=? AND morador_id=? LIMIT 1', 'ii', [$tenantId,$moradorId]);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !$row || (int)$row['push_ativo'] === 1;
    }
}

if (!function_exists('au_stmt_bind')) {
    function au_stmt_bind(mysqli_stmt $stmt, $tipos, array &$parametros) {
        if ($tipos === '') return;
        $bind = [$tipos];
        foreach ($parametros as $i => &$valor) $bind[] = &$valor;
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
}

if (!function_exists('au_query')) {
    function au_query(mysqli $c, $sql, $tipos = '', array $parametros = []) {
        $stmt = $c->prepare($sql);
        if (!$stmt) throw new RuntimeException('Consulta indisponível.', (int)$c->errno);
        if ($tipos !== '') au_stmt_bind($stmt, $tipos, $parametros);
        if (!$stmt->execute()) {
            $errno = (int)$stmt->errno;
            $stmt->close();
            throw new RuntimeException('Consulta indisponível.', $errno);
        }
        return $stmt;
    }
}

if (!function_exists('alertas_unidade_contar_ativos_unidade')) {
    function alertas_unidade_contar_ativos_unidade(mysqli $c, int $tenantId, string $unidade, int $excluirId = 0): int {
        $agora = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $stmt = au_query($c, "SELECT COUNT(*) total FROM alertas_unidade
            WHERE tenant_id=? AND unidade=? AND id<>? AND status='ATIVO'
              AND (vigencia_fim IS NULL OR vigencia_fim>=?)", 'isis', [$tenantId, $unidade, $excluirId, $agora]);
        $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $total;
    }
}

if (!function_exists('au_rows')) {
    function au_rows(mysqli_stmt $stmt) {
        $resultado = $stmt->get_result();
        $linhas = [];
        if ($resultado) while ($linha = $resultado->fetch_assoc()) $linhas[] = $linha;
        return $linhas;
    }
}

if (!function_exists('alertas_unidade_invalidar_cache')) {
    function alertas_unidade_invalidar_cache(mysqli $c, int $tenantId): void {
        if ($tenantId <= 0) return;
        $stmt = au_query($c, 'DELETE FROM alertas_unidade_cache_ids WHERE tenant_id=?', 'i', [$tenantId]);
        $stmt->close();
    }
}

if (!function_exists('alertas_unidade_ids_cache')) {
    function alertas_unidade_ids_cache(mysqli $c, int $tenantId, string $unidade): ?array {
        $unidade = trim($unidade);
        if ($tenantId <= 0 || $unidade === '') return null;
        $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $stmt = au_query($c, 'SELECT ids_json,atualizado_em FROM alertas_unidade_cache_ids WHERE tenant_id=? AND unidade=? LIMIT 1', 'is', [$tenantId,$unidade]);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            try {
                $atualizado = new DateTime((string)$row['atualizado_em'], new DateTimeZone('America/Sao_Paulo'));
                $idade = $agora->getTimestamp() - $atualizado->getTimestamp();
                $ids = json_decode((string)$row['ids_json'], true);
                if ($idade >= 0 && $idade <= 30 && is_array($ids)) {
                    return array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) { return $id > 0; })));
                }
            } catch (Throwable $ignorado) {}
        }
        $stmt = au_query($c, "SELECT id FROM alertas_unidade WHERE tenant_id=? AND status='ATIVO' ORDER BY id", 'i', [$tenantId]);
        $ids = [];
        foreach (au_rows($stmt) as $item) $ids[] = (int)$item['id'];
        $stmt->close();
        $idsJson = json_encode($ids);
        if (!is_string($idsJson)) $idsJson = '[]';
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $stmt = au_query($c, 'INSERT INTO alertas_unidade_cache_ids (tenant_id,unidade,ids_json,atualizado_em) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE ids_json=VALUES(ids_json),atualizado_em=VALUES(atualizado_em)', 'isss', [$tenantId,$unidade,$idsJson,$agoraSql]);
        $stmt->close();
        return $ids;
    }
}

if (!function_exists('au_now')) {
    function au_now($valor = null) {
        $tz = new DateTimeZone('America/Sao_Paulo');
        if ($valor instanceof DateTimeInterface) return new DateTime($valor->format('Y-m-d H:i:s'), $tz);
        if ($valor === null || trim((string)$valor) === '') return new DateTime('now', $tz);
        $texto = trim((string)$valor);
        $formatos = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'];
        foreach ($formatos as $formato) {
            $data = DateTime::createFromFormat('!' . $formato, $texto, $tz);
            $erros = DateTime::getLastErrors();
            if ($data instanceof DateTime && (!$erros || (empty($erros['warning_count']) && empty($erros['error_count']))) && $data->format($formato) === $texto) return $data;
        }
        return null;
    }
}

if (!function_exists('au_contexto_canonico')) {
    function au_contexto_canonico(array $ctx) {
        $doc = $ctx['documento_normalizado'] ?? alertas_unidade_normalizar_documento($ctx['documento'] ?? '', $ctx['tipo_documento'] ?? '');
        $dataHora = trim((string)($ctx['data_hora'] ?? ''));
        $data = $dataHora !== '' ? au_now($dataHora) : null;
        $canonico = [
            'unidade' => trim((string)($ctx['unidade'] ?? '')),
            'morador_id' => (int)($ctx['morador_id'] ?? 0),
            'dependente_id' => (int)($ctx['dependente_id'] ?? 0),
            'tipo_pessoa' => alertas_unidade_mapear_tipo($ctx['tipo_pessoa'] ?? '') ?: strtoupper(trim((string)($ctx['tipo_pessoa'] ?? ''))),
            'visitante_id' => (int)($ctx['visitante_id'] ?? 0),
            'documento_hash' => $doc !== '' ? hash('sha256', $doc) : '',
            'tipo_documento' => strtoupper(trim((string)($ctx['tipo_documento'] ?? ''))),
            'placa' => alertas_unidade_normalizar_placa($ctx['placa'] ?? ''),
            'tipo_acesso' => trim((string)($ctx['tipo_acesso'] ?? 'Entrada')),
            'contexto' => strtoupper(trim((string)($ctx['contexto'] ?? 'REGISTRO_MANUAL'))),
            'data_hora' => $data ? $data->format('Y-m-d H:i:s') : '',
        ];
        ksort($canonico);
        return $canonico;
    }
}

if (!function_exists('au_contexto_hash')) {
    function au_contexto_hash(array $ctx) {
        return hash('sha256', json_encode(au_contexto_canonico($ctx), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('au_alertas_hash')) {
    function au_alertas_hash(array $alertas) {
        $ids = [];
        foreach ($alertas as $alerta) if ((int)($alerta['id'] ?? 0) > 0) $ids[] = (int)$alerta['id'];
        sort($ids, SORT_NUMERIC);
        return hash('sha256', implode(',', $ids));
    }
}

if (!function_exists('au_dias_validos')) {
    function au_dias_validos($valor) {
        if (is_array($valor)) $partes = $valor;
        else $partes = preg_split('/\s*,\s*/', trim((string)$valor), -1, PREG_SPLIT_NO_EMPTY);
        $dias = [];
        foreach ($partes as $dia) {
            $n = (int)$dia;
            if ($n >= 1 && $n <= 7) $dias[$n] = $n;
        }
        ksort($dias);
        return array_values($dias);
    }
}

if (!function_exists('au_janela_valida')) {
    function au_janela_valida(array $alerta, DateTime $data) {
        $dias = au_dias_validos($alerta['dias_semana'] ?? '');
        $horaInicio = trim((string)($alerta['hora_inicio'] ?? ''));
        $horaFim = trim((string)($alerta['hora_fim'] ?? ''));
        if (!$dias && $horaInicio === '' && $horaFim === '') return true;
        $diaAtual = (int)$data->format('N');
        if ($dias && $horaInicio === '' && $horaFim === '') return in_array($diaAtual, $dias, true);
        if ($horaInicio === '' || $horaFim === '') return false;
        $horaAtual = $data->format('H:i:s');
        $inicio = strlen($horaInicio) === 5 ? $horaInicio . ':00' : $horaInicio;
        $fim = strlen($horaFim) === 5 ? $horaFim . ':00' : $horaFim;
        if ($inicio <= $fim) {
            if ($horaAtual < $inicio || $horaAtual > $fim) return false;
            return !$dias || in_array($diaAtual, $dias, true);
        }
        // Janela que cruza meia-noite fica ancorada no dia da hora inicial:
        // 22:00 de segunda a 06:00 de terça ainda pertence à segunda.
        if ($horaAtual >= $inicio) return !$dias || in_array($diaAtual, $dias, true);
        if ($horaAtual <= $fim) {
            $diaAnterior = $diaAtual === 1 ? 7 : $diaAtual - 1;
            return !$dias || in_array($diaAnterior, $dias, true);
        }
        return false;
    }
}

if (!function_exists('au_publico_compativel')) {
    function au_publico_compativel($publico, $tipo, $placa) {
        $publico = strtoupper((string)$publico);
        $tipo = alertas_unidade_mapear_tipo($tipo) ?: strtoupper((string)$tipo);
        if ($publico === 'VEICULO') return trim((string)$placa) !== '';
        if ($publico === 'TODOS') return in_array($tipo, ['MORADOR','DEPENDENTE','VISITANTE','PRESTADOR','ENTREGADOR_AUTORIZADO','FAMILIAR'], true);
        return $publico === $tipo;
    }
}

if (!function_exists('au_target_match')) {
    function au_target_match(array $target, array $ctx, $doc, $placa) {
        $tipo = strtoupper((string)($target['tipo_alvo'] ?? ''));
        if ($tipo === 'VISITANTE') {
            return (int)($target['visitante_id'] ?? 0) > 0 && (int)($target['visitante_id'] ?? 0) === (int)($ctx['visitante_id'] ?? 0);
        }
        if ($tipo === 'DOCUMENTO') {
            return trim((string)($target['documento_normalizado'] ?? '')) !== '' && $doc !== '' && hash_equals((string)$target['documento_normalizado'], $doc);
        }
        if ($tipo === 'PLACA') {
            return trim((string)($target['placa_normalizada'] ?? '')) !== '' && $placa !== '' && hash_equals((string)$target['placa_normalizada'], $placa);
        }
        return false;
    }
}

if (!function_exists('alertas_unidade_avaliar')) {
    /** Avalia somente Entrada e nunca atravessa o tenant recebido pela sessão. */
    function alertas_unidade_avaliar(mysqli $c, int $tenantId, array $ctx, bool $forUpdate = false): array {
        if ($tenantId <= 0) return [];
        $tipoAcesso = strtoupper(trim((string)($ctx['tipo_acesso'] ?? 'Entrada')));
        if ($tipoAcesso !== 'ENTRADA') return [];
        $data = au_now($ctx['data_hora'] ?? null);
        if (!$data) return [];
        $dataSql = $data->format('Y-m-d H:i:s');
        $idsCache = $forUpdate ? null : alertas_unidade_ids_cache($c, $tenantId, (string)($ctx['unidade'] ?? ''));
        if (is_array($idsCache)) {
            if (!$idsCache) return [];
            $placeholdersCache = implode(',', array_fill(0, count($idsCache), '?'));
            $typesCache = 'i' . str_repeat('i', count($idsCache)) . 'ss';
            $paramsCache = array_merge([$tenantId], $idsCache, [$dataSql,$dataSql]);
            $stmt = au_query($c, "SELECT a.* FROM alertas_unidade a
                WHERE a.tenant_id = ? AND a.id IN ($placeholdersCache) AND a.status = 'ATIVO'
                  AND a.vigencia_inicio <= ? AND (a.vigencia_fim IS NULL OR a.vigencia_fim >= ?)
                ORDER BY a.id ASC", $typesCache, $paramsCache);
        } else {
            $stmt = au_query($c, "SELECT a.* FROM alertas_unidade a
                WHERE a.tenant_id = ? AND a.status = 'ATIVO'
                  AND a.vigencia_inicio <= ?
                  AND (a.vigencia_fim IS NULL OR a.vigencia_fim >= ?)
                ORDER BY a.id ASC" . ($forUpdate ? ' FOR UPDATE' : ''), 'iss', [$tenantId, $dataSql, $dataSql]);
        }
        $candidatos = au_rows($stmt);
        $stmt->close();
        if (!$candidatos) return [];

        $ids = [];
        foreach ($candidatos as $item) $ids[] = (int)$item['id'];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$tenantId], $ids);
        $stmt = au_query($c, "SELECT tenant_id,alerta_id,tipo_alvo,visitante_id,tipo_documento,documento_normalizado,placa_normalizada,nome_referencia
            FROM alertas_unidade_alvos WHERE tenant_id = ? AND alerta_id IN ($placeholders)", $types, $params);
        $alvosPorAlerta = [];
        foreach (au_rows($stmt) as $alvo) $alvosPorAlerta[(int)$alvo['alerta_id']][] = $alvo;
        $stmt->close();

        $unidade = trim((string)($ctx['unidade'] ?? ''));
        $tipo = alertas_unidade_mapear_tipo($ctx['tipo_pessoa'] ?? '') ?: strtoupper(trim((string)($ctx['tipo_pessoa'] ?? '')));
        $doc = $ctx['documento_normalizado'] ?? alertas_unidade_normalizar_documento($ctx['documento'] ?? '', $ctx['tipo_documento'] ?? '');
        $placa = alertas_unidade_normalizar_placa($ctx['placa'] ?? '');
        $visitanteId = (int)($ctx['visitante_id'] ?? 0);
        $resultado = [];
        foreach ($candidatos as $alerta) {
            if (!au_janela_valida($alerta, $data)) continue;
            if (!au_publico_compativel($alerta['publico'], $tipo, $placa)) continue;
            $mesmaUnidade = $unidade !== '' && hash_equals((string)$alerta['unidade'], $unidade);
            $divergencia = false;
            $match = false;
            $tipoAlvoCorrespondente = null;
            if ((string)$alerta['abrangencia'] === 'TODOS_DO_TIPO') {
                $match = $mesmaUnidade;
            } else {
                foreach ($alvosPorAlerta[(int)$alerta['id']] ?? [] as $alvo) {
                    $ctxAlvo = $ctx;
                    $ctxAlvo['visitante_id'] = $visitanteId;
                    if (au_target_match($alvo, $ctxAlvo, $doc, $placa)) {
                        $match = true;
                        $tipoAlvoCorrespondente = (string)($alvo['tipo_alvo'] ?? '');
                        $divergencia = !$mesmaUnidade;
                        break;
                    }
                }
            }
            if (!$match) continue;
            $linha = [
                'id' => (int)$alerta['id'], 'tenant_id' => $tenantId,
                'unidade' => (string)$alerta['unidade'], 'titular_tipo' => (string)$alerta['titular_tipo'],
                'morador_id' => (int)$alerta['morador_id'], 'dependente_id' => $alerta['dependente_id'] === null ? null : (int)$alerta['dependente_id'],
                'titulo' => (string)$alerta['titulo'], 'mensagem' => (string)$alerta['mensagem'],
                'nivel' => (string)$alerta['nivel'], 'acao' => (string)$alerta['acao'],
                'publico' => (string)$alerta['publico'], 'abrangencia' => (string)$alerta['abrangencia'],
                'vigencia_inicio' => $alerta['vigencia_inicio'], 'vigencia_fim' => $alerta['vigencia_fim'],
                'dias_semana' => au_dias_validos($alerta['dias_semana'] ?? ''), 'hora_inicio' => $alerta['hora_inicio'], 'hora_fim' => $alerta['hora_fim'],
                'instrucao_operador' => $alerta['instrucao_operador'], 'telefone_contato' => $alerta['telefone_contato'],
                'status' => 'ATIVO', 'origem' => (string)$alerta['origem'],
                'criado_por_tipo' => (string)$alerta['criado_por_tipo'],
                'total_acionamentos' => (int)$alerta['total_acionamentos'],
                'divergencia_unidade' => $divergencia,
                'tipo_alvo_correspondente' => $tipoAlvoCorrespondente,
            ];
            $resultado[] = $linha;
        }
        usort($resultado, function ($a, $b) {
            $niveis = ['CRITICO' => 3, 'ATENCAO' => 2, 'INFORMATIVO' => 1];
            $acoes = ['BLOQUEAR' => 4, 'CONTATAR_MORADOR' => 3, 'EXIGIR_CIENTE' => 2, 'APENAS_AVISAR' => 1];
            $na = $niveis[$a['nivel']] ?? 0; $nb = $niveis[$b['nivel']] ?? 0;
            if ($na !== $nb) return $nb <=> $na;
            return ($acoes[$b['acao']] ?? 0) <=> ($acoes[$a['acao']] ?? 0);
        });
        return $resultado;
    }
}

if (!function_exists('au_token_context_snapshot')) {
    function au_token_context_snapshot(array $ctx) {
        return au_contexto_canonico($ctx);
    }
}

if (!function_exists('alertas_unidade_construir_contexto_registro')) {
    /**
     * Constrói o contexto que as APIs canônicas devem usar no preflight e no
     * consumo. IDs recebidos são confirmados no tenant; unidade de morador ou
     * dependente é sempre derivada do cadastro, nunca do payload.
     */
    function alertas_unidade_construir_contexto_registro(mysqli $db, int $tenantId, array $registro): array {
        if ($tenantId <= 0) throw new InvalidArgumentException('Tenant inválido.');
        $moradorId = (int)($registro['morador_id'] ?? 0);
        $dependenteId = (int)($registro['dependente_id'] ?? 0);
        $unidade = '';
        if ($moradorId > 0) {
            $stmt = au_query($db, 'SELECT id,unidade,ativo FROM moradores WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1', 'ii', [$tenantId, $moradorId]);
            $morador = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$morador) throw new RuntimeException('Contexto de morador indisponível.');
            $unidade = (string)$morador['unidade'];
        }
        if ($dependenteId > 0) {
            $stmt = au_query($db, 'SELECT id,morador_id,ativo FROM dependentes WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1', 'ii', [$tenantId, $dependenteId]);
            $dependente = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$dependente || ($moradorId > 0 && (int)$dependente['morador_id'] !== $moradorId)) throw new RuntimeException('Contexto de dependente indisponível.');
            if ($moradorId <= 0) {
                $moradorId = (int)$dependente['morador_id'];
                $stmt = au_query($db, 'SELECT unidade FROM moradores WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1', 'ii', [$tenantId, $moradorId]);
                $morador = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$morador) throw new RuntimeException('Contexto de unidade indisponível.');
                $unidade = (string)$morador['unidade'];
            }
        }
        $visitanteId = (int)($registro['visitante_id'] ?? 0);
        $pessoaNome = trim((string)($registro['pessoa_nome'] ?? $registro['nome_visitante'] ?? ''));
        if ($visitanteId > 0) {
            $stmt = au_query($db, 'SELECT id,nome_completo FROM visitantes WHERE tenant_id=? AND id=? AND ativo=1 LIMIT 1', 'ii', [$tenantId, $visitanteId]);
            $visitante = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$visitante) throw new RuntimeException('Contexto de visitante indisponível.');
            $pessoaNome = (string)$visitante['nome_completo'];
        }
        $contexto = strtoupper(trim((string)($registro['contexto'] ?? 'REGISTRO_MANUAL')));
        if (!in_array($contexto, ['REGISTRO_MANUAL','CONTROLE_ACESSO','RFID','CONVIDADOS','LPR'], true)) throw new InvalidArgumentException('Contexto de registro inválido.');
        $tipoAcesso = trim((string)($registro['tipo_acesso'] ?? 'Entrada'));
        $tipoPessoa = alertas_unidade_mapear_tipo($registro['tipo_pessoa'] ?? $registro['tipo'] ?? '') ?: strtoupper(trim((string)($registro['tipo_pessoa'] ?? '')));
        return [
            'contexto' => $contexto,
            'unidade' => $unidade !== '' ? $unidade : trim((string)($registro['unidade'] ?? '')),
            'morador_id' => $moradorId,
            'dependente_id' => $dependenteId,
            'tipo_pessoa' => $tipoPessoa,
            'visitante_id' => $visitanteId,
            'documento' => $registro['documento'] ?? '',
            'documento_normalizado' => alertas_unidade_normalizar_documento($registro['documento'] ?? '', $registro['tipo_documento'] ?? ''),
            'tipo_documento' => strtoupper(trim((string)($registro['tipo_documento'] ?? ''))),
            'placa' => alertas_unidade_normalizar_placa($registro['placa'] ?? ''),
            'tipo_acesso' => $tipoAcesso,
            'data_hora' => $registro['data_hora'] ?? $registro['data_entrada'] ?? $registro['entrada_em'] ?? date('Y-m-d H:i:s'),
            'pessoa_nome' => $pessoaNome,
        ];
    }
}

if (!function_exists('au_alerta_snapshot')) {
    function au_alerta_snapshot(array $a) {
        return [
            'id' => (int)($a['id'] ?? 0), 'unidade' => (string)($a['unidade'] ?? ''),
            'titulo' => (string)($a['titulo'] ?? ''), 'mensagem' => (string)($a['mensagem'] ?? ''),
            'nivel' => (string)($a['nivel'] ?? ''), 'acao' => (string)($a['acao'] ?? ''),
            'publico' => (string)($a['publico'] ?? ''), 'abrangencia' => (string)($a['abrangencia'] ?? ''),
            'instrucao_operador' => (string)($a['instrucao_operador'] ?? ''),
            'telefone_contato' => (string)($a['telefone_contato'] ?? ''),
            'divergencia_unidade' => !empty($a['divergencia_unidade']),
        ];
    }
}

if (!function_exists('alertas_unidade_criar_token_ciencia')) {
    function alertas_unidade_criar_token_ciencia(mysqli $c, int $tenantId, int $usuarioId, array $ctx, array $alertas, array $decisoes): string {
        if ($tenantId <= 0 || $usuarioId <= 0 || !$alertas) throw new RuntimeException('Não foi possível criar a ciência.');
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $contextoHash = au_contexto_hash($ctx);
        $alertasHash = au_alertas_hash($alertas);
        $contextoJson = json_encode(au_token_context_snapshot($ctx), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $decisoesSeguras = [];
        foreach ($decisoes as $decisao) {
            $decisoesSeguras[] = [
                'alerta_id' => (int)($decisao['alerta_id'] ?? 0),
                'decisao' => strtoupper(trim((string)($decisao['decisao'] ?? ''))),
                'justificativa' => isset($decisao['justificativa']) ? mb_substr(trim((string)$decisao['justificativa']), 0, 500, 'UTF-8') : null,
            ];
        }
        $decisoesJson = json_encode($decisoesSeguras, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $snapshots = [];
        foreach ($alertas as $alerta) $snapshots[] = au_alerta_snapshot($alerta);
        $alertasJson = json_encode($snapshots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $expira = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->modify('+5 minutes')->format('Y-m-d H:i:s');
        $stmt = au_query($c, 'INSERT INTO alertas_unidade_ciencia_tokens (tenant_id,usuario_id,token_hash,contexto_hash,alertas_hash,contexto_snapshot_json,decisoes_json,alertas_snapshot_json,expira_em) VALUES (?,?,?,?,?,?,?,?,?)', 'iisssssss', [$tenantId,$usuarioId,$tokenHash,$contextoHash,$alertasHash,$contextoJson,$decisoesJson,$alertasJson,$expira]);
        $stmt->close();
        return $token;
    }
}

if (!function_exists('alertas_unidade_registrar_eventos')) {
    function alertas_unidade_registrar_eventos(mysqli $c, int $tenantId, array $ctx, int $registroId, array $alertas, array $decisoes, array $opcoes = []): array {
        $porId = [];
        foreach ($decisoes as $decisao) $porId[(int)($decisao['alerta_id'] ?? 0)] = $decisao;
        $usuarioId = (int)($ctx['_usuario_id'] ?? $opcoes['usuario_id'] ?? 0);
        $usuarioNome = mb_substr(trim((string)($ctx['_usuario_nome'] ?? $opcoes['usuario_nome'] ?? 'Usuário autenticado')), 0, 150, 'UTF-8');
        $contexto = strtoupper(trim((string)($ctx['contexto'] ?? 'REGISTRO_MANUAL')));
        $contextos = ['REGISTRO_MANUAL','CONTROLE_ACESSO','RFID','CONVIDADOS','LPR','PORTAL'];
        if (!in_array($contexto, $contextos, true)) $contexto = 'REGISTRO_MANUAL';
        $pessoaNome = mb_substr(trim((string)($ctx['pessoa_nome'] ?? $ctx['nome_visitante'] ?? '')), 0, 200, 'UTF-8');
        $doc = $ctx['documento_normalizado'] ?? alertas_unidade_normalizar_documento($ctx['documento'] ?? '', $ctx['tipo_documento'] ?? '');
        $docMask = alertas_unidade_mascarar_documento($doc, $ctx['tipo_documento'] ?? '');
        $placa = alertas_unidade_normalizar_placa($ctx['placa'] ?? '');
        $inseridos = []; $notificar = [];
        foreach ($alertas as $alerta) {
            $id = (int)$alerta['id'];
            if (!isset($porId[$id])) continue;
            $item = $porId[$id];
            $decisao = strtoupper(trim((string)($item['decisao'] ?? '')));
            $validas = ['CIENTE_LIBERADO','CIENTE_NAO_LIBERADO','CONTATO_MORADOR_AUTORIZOU','CONTATO_MORADOR_NEGOU','OVERRIDE_SUPERVISOR'];
            if (!in_array($decisao, $validas, true)) continue;
            $justificativa = trim((string)($item['justificativa'] ?? ''));
            $snapshot = json_encode(array_merge(au_alerta_snapshot($alerta), ['decisao'=>$decisao, 'justificativa'=>$justificativa]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $tokenId = (int)($opcoes['token_id'] ?? 0);
            $supervisorId = (int)($opcoes['supervisor_usuario_id'] ?? 0);
            $tipoPessoa = trim((string)($ctx['tipo_pessoa'] ?? '')) ?: null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;
            $paramsCiencia = [
                $tenantId, $id, $tokenId > 0 ? $tokenId : null, $contexto,
                $registroId > 0 ? $registroId : null, $decisao, $justificativa !== '' ? $justificativa : null,
                $usuarioId, $usuarioNome, $supervisorId > 0 ? $supervisorId : null,
                $pessoaNome !== '' ? $pessoaNome : null, $docMask, $tipoPessoa,
                $placa !== '' ? $placa : null, !empty($alerta['divergencia_unidade']) ? 1 : 0,
                $snapshot, $ip, $userAgent,
            ];
            $stmt = au_query($c, 'INSERT INTO alertas_unidade_ciencias
                (tenant_id,alerta_id,token_id,contexto,registro_acesso_id,decisao,justificativa,usuario_id,usuario_nome,supervisor_usuario_id,pessoa_nome,pessoa_documento_mascarado,tipo_pessoa,placa,divergencia_unidade,snapshot_json,ip,user_agent)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'iiisissisissssisss', $paramsCiencia);
            $stmt->close();

            $stmt = au_query($c, "INSERT INTO alertas_unidade_acionamentos
                (tenant_id,alerta_id,registro_acesso_id,contexto,decisao,usuario_id,usuario_nome,pessoa_nome,pessoa_documento_mascarado,tipo_pessoa,placa,divergencia_unidade,snapshot_json)
                VALUES (?,?,NULLIF(?,0),?,?,NULLIF(?,0),?,?,?,?,?,?,?)", 'iiississsssis', [$tenantId,$id,$registroId,$contexto,$decisao,$usuarioId,$usuarioNome,$pessoaNome,$docMask,$ctx['tipo_pessoa'] ?? null,$placa,!empty($alerta['divergencia_unidade']) ? 1 : 0,$snapshot]);
            $stmt->close();
            $stmt = au_query($c, 'UPDATE alertas_unidade SET total_acionamentos = total_acionamentos + 1, ultimo_acionamento_em = NOW() WHERE tenant_id = ? AND id = ?', 'ii', [$tenantId,$id]);
            $stmt->close();
            $inseridos[] = $id;
            $notificar[] = ['alerta'=>$alerta,'decisao'=>$decisao,'morador_id'=>(int)$alerta['morador_id']];
        }
        return ['ok'=>true,'alertas_ids'=>$inseridos,'notificacoes'=>$notificar];
    }
}

if (!function_exists('alertas_unidade_registrar_avisos')) {
    function alertas_unidade_registrar_avisos(mysqli $c, int $tenantId, array $ctx, int $registroId, array $alertas, int $usuarioId, string $usuarioNome): array {
        $notificacoes = [];
        $pessoaNome = mb_substr(trim((string)($ctx['pessoa_nome'] ?? $ctx['nome_visitante'] ?? '')), 0, 200, 'UTF-8');
        $doc = $ctx['documento_normalizado'] ?? alertas_unidade_normalizar_documento($ctx['documento'] ?? '', $ctx['tipo_documento'] ?? '');
        $docMask = alertas_unidade_mascarar_documento($doc, $ctx['tipo_documento'] ?? '');
        $placa = alertas_unidade_normalizar_placa($ctx['placa'] ?? '');
        $contexto = strtoupper(trim((string)($ctx['contexto'] ?? 'REGISTRO_MANUAL')));
        foreach ($alertas as $alerta) {
            if ((string)($alerta['acao'] ?? '') !== 'APENAS_AVISAR') continue;
            $snapshot = json_encode(array_merge(au_alerta_snapshot($alerta), ['decisao'=>'APENAS_AVISAR']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = au_query($c, "INSERT INTO alertas_unidade_acionamentos
                (tenant_id,alerta_id,registro_acesso_id,contexto,decisao,usuario_id,usuario_nome,pessoa_nome,pessoa_documento_mascarado,tipo_pessoa,placa,divergencia_unidade,snapshot_json)
                VALUES (?,?,NULLIF(?,0),?,'APENAS_AVISAR',NULLIF(?,0),?,?,?,?,?,?,?)", 'iiisisssssis', [
                $tenantId,(int)$alerta['id'],$registroId,$contexto,$usuarioId,$usuarioNome ?: null,
                $pessoaNome ?: null,$docMask,$ctx['tipo_pessoa'] ?? null,$placa ?: null,
                !empty($alerta['divergencia_unidade']) ? 1 : 0,$snapshot
            ]);
            $stmt->close();
            $stmt = au_query($c, 'UPDATE alertas_unidade SET total_acionamentos=total_acionamentos+1,ultimo_acionamento_em=NOW() WHERE tenant_id=? AND id=?', 'ii', [$tenantId,(int)$alerta['id']]);
            $stmt->close();
            $notificacoes[] = ['alerta'=>$alerta,'decisao'=>'APENAS_AVISAR','morador_id'=>(int)($alerta['morador_id'] ?? 0)];
        }
        return $notificacoes;
    }
}

if (!function_exists('alertas_unidade_registrar_automaticos')) {
    function alertas_unidade_registrar_automaticos(mysqli $c, int $tenantId, array $ctx, int $registroId, array $alertas, int $usuarioId, string $usuarioNome, bool $bloqueado): array {
        $notificacoes = [];
        $pessoaNome = trim((string)($ctx['pessoa_nome'] ?? ''));
        if (function_exists('mb_substr')) $pessoaNome = mb_substr($pessoaNome, 0, 200, 'UTF-8');
        else $pessoaNome = substr($pessoaNome, 0, 200);
        $documento = $ctx['documento_normalizado'] ?? alertas_unidade_normalizar_documento($ctx['documento'] ?? '', $ctx['tipo_documento'] ?? '');
        $mascarado = alertas_unidade_mascarar_documento($documento, $ctx['tipo_documento'] ?? '');
        $placa = alertas_unidade_normalizar_placa($ctx['placa'] ?? '');
        $contexto = strtoupper(trim((string)($ctx['contexto'] ?? 'CONTROLE_ACESSO')));
        foreach ($alertas as $alerta) {
            $acao = strtoupper((string)($alerta['acao'] ?? ''));
            $decisao = $acao === 'APENAS_AVISAR' ? 'APENAS_AVISAR' : (($bloqueado && $acao === 'BLOQUEAR') ? 'BLOQUEADO_AUTOMATICO' : 'CIENCIA_PENDENTE');
            $snapshot = json_encode(array_merge(au_alerta_snapshot($alerta), ['decisao'=>$decisao]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = au_query($c, 'INSERT INTO alertas_unidade_acionamentos
                (tenant_id,alerta_id,registro_acesso_id,contexto,decisao,usuario_id,usuario_nome,pessoa_nome,pessoa_documento_mascarado,tipo_pessoa,placa,divergencia_unidade,snapshot_json)
                VALUES (?,?,NULLIF(?,0),?,?,NULLIF(?,0),?,?,?,?,?,?,?)', 'iiississsssis', [
                $tenantId,(int)$alerta['id'],$registroId,$contexto,$decisao,$usuarioId,$usuarioNome ?: null,
                $pessoaNome ?: null,$mascarado,$ctx['tipo_pessoa'] ?? null,$placa ?: null,
                !empty($alerta['divergencia_unidade']) ? 1 : 0,$snapshot
            ]);
            $stmt->close();
            $stmt = au_query($c, 'UPDATE alertas_unidade SET total_acionamentos=total_acionamentos+1,ultimo_acionamento_em=NOW() WHERE tenant_id=? AND id=?', 'ii', [$tenantId,(int)$alerta['id']]);
            $stmt->close();
            $notificacoes[] = ['alerta'=>$alerta,'decisao'=>$decisao,'morador_id'=>(int)($alerta['morador_id'] ?? 0)];
        }
        return $notificacoes;
    }
}

if (!function_exists('alertas_unidade_validar_consumir_token')) {
    /**
     * Valida e consome uma ciência dentro da transação do registro de acesso.
     * O chamador deve abrir a transação antes desta função e fazer COMMIT/ROLLBACK
     * junto com o INSERT do registro. A linha do token é bloqueada com FOR UPDATE;
     * a atualização usado=1 é feita antes do retorno e as ciências/acionamentos
     * apontam para o registroAcessoId recebido.
     */
    function alertas_unidade_validar_consumir_token(mysqli $db, int $tenantId, int $usuarioId, array $contextoCanonico, ?string $token, int $registroAcessoId, array $decisoesEsperadas = []): array {
        $falha = static function ($codigo, $mensagem) {
            return ['sucesso'=>false,'codigo'=>$codigo,'alertas'=>[],'mensagem'=>$mensagem];
        };
        if ($tenantId <= 0 || $usuarioId <= 0 || $registroAcessoId <= 0) return $falha('CIENCIA_CONTEXTUAL_INVALIDA', 'Contexto de ciência inválido.');
        if ($token === null || trim($token) === '') return $falha('CIENCIA_TOKEN_AUSENTE', 'Token de ciência ausente.');
        $ctx = $contextoCanonico;
        $avaliados = alertas_unidade_avaliar($db, $tenantId, $ctx, true);
        $hashToken = hash('sha256', trim($token));
        $stmt = au_query($db, 'SELECT * FROM alertas_unidade_ciencia_tokens WHERE tenant_id=? AND usuario_id=? AND token_hash=? LIMIT 1 FOR UPDATE', 'iis', [$tenantId,$usuarioId,$hashToken]);
        $linha = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$linha) return $falha('CIENCIA_TOKEN_INVALIDO', 'Token de ciência inválido.');
        if ((int)$linha['usado'] === 1) return $falha('CIENCIA_TOKEN_REUTILIZADO', 'Token de ciência já utilizado.');
        if ((string)$linha['expira_em'] < date('Y-m-d H:i:s')) return $falha('CIENCIA_TOKEN_EXPIRADO', 'Token de ciência expirado.');
        if (!hash_equals((string)$linha['contexto_hash'], au_contexto_hash($ctx))) return $falha('CIENCIA_TOKEN_DIVERGENTE', 'Contexto de ciência divergente.');
        if (!hash_equals((string)$linha['alertas_hash'], au_alertas_hash($avaliados))) return $falha('ALERTA_REVALIDACAO_NECESSARIA', 'Os alertas aplicáveis mudaram.');
        $decisoes = json_decode((string)$linha['decisoes_json'], true);
        if (!is_array($decisoes)) return $falha('CIENCIA_TOKEN_INVALIDO', 'Decisões de ciência inválidas.');
        if ($decisoesEsperadas) {
            $esperadas = [];
            foreach ($decisoesEsperadas as $decisao) $esperadas[(int)($decisao['alerta_id'] ?? 0)] = strtoupper(trim((string)($decisao['decisao'] ?? '')));
            foreach ($decisoes as $decisao) {
                $id = (int)($decisao['alerta_id'] ?? 0);
                if (!isset($esperadas[$id]) || $esperadas[$id] !== strtoupper(trim((string)($decisao['decisao'] ?? '')))) return $falha('CIENCIA_DECISAO_DIVERGENTE', 'Decisão de ciência divergente.');
            }
        }
        $ctx['_usuario_id'] = $usuarioId;
        foreach ($decisoes as $decisao) {
            if (strtoupper(trim((string)($decisao['decisao'] ?? ''))) === 'OVERRIDE_SUPERVISOR') {
                $ctx['_supervisor_usuario_id'] = $usuarioId;
                break;
            }
        }
        $resultado = alertas_unidade_registrar_eventos($db, $tenantId, $ctx, $registroAcessoId, $avaliados, $decisoes, [
            'token_id'=>(int)$linha['id'],
            'usuario_id'=>$usuarioId,
            'usuario_nome'=>$ctx['_usuario_nome'] ?? 'Usuário autenticado',
            'supervisor_usuario_id'=>(int)($ctx['_supervisor_usuario_id'] ?? 0),
        ]);
        $avisos = alertas_unidade_registrar_avisos($db, $tenantId, $ctx, $registroAcessoId, $avaliados, $usuarioId, (string)($ctx['_usuario_nome'] ?? 'Usuário autenticado'));
        $stmt = au_query($db, 'UPDATE alertas_unidade_ciencia_tokens SET usado=1,usado_em=NOW(),registro_acesso_id=? WHERE tenant_id=? AND id=? AND usado=0', 'iii', [$registroAcessoId,$tenantId,(int)$linha['id']]);
        if ($stmt->affected_rows !== 1) { $stmt->close(); return $falha('CIENCIA_TOKEN_REUTILIZADO', 'Token de ciência já utilizado.'); }
        $stmt->close();
        return ['sucesso'=>true,'codigo'=>'CIENCIA_CONSUMIDA','alertas'=>$avaliados,'notificacoes'=>array_merge($resultado['notificacoes'] ?? [],$avisos),'mensagem'=>'Ciência validada e consumida no registro.','token_id'=>(int)$linha['id'],'alertas_ids'=>$resultado['alertas_ids'] ?? []];
    }
}

if (!function_exists('alertas_unidade_notificar_morador')) {
    function alertas_unidade_notificar_morador(mysqli $c, int $tenantId, array $alerta, int $moradorId, ?int $registroId, array $ctx, string $decisao): array {
        $transacaoIniciada = false;
        try {
            if ($tenantId <= 0 || $moradorId <= 0 || (int)($alerta['id'] ?? 0) <= 0) return ['sucesso'=>false,'motivo'=>'destinatario_invalido'];
            $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
            $inicio = date('Y-m-d H:i:00', floor($agora->getTimestamp() / 600) * 600);
            $nome = trim((string)($ctx['pessoa_nome'] ?? $ctx['nome_visitante'] ?? ''));
            $mensagem = 'Seu alerta "' . mb_substr((string)($alerta['titulo'] ?? ''), 0, 120, 'UTF-8') . '" foi acionado na portaria às ' . $agora->format('H:i') . ($nome !== '' ? ' — ' . mb_substr($nome, 0, 100, 'UTF-8') : '') . ' — decisão: ' . $decisao . '.';
            $titulo = 'Alerta da unidade acionado';
            $c->begin_transaction();
            $transacaoIniciada = true;
            $stmt = au_query($c, 'INSERT IGNORE INTO alertas_unidade_notificacoes (tenant_id,alerta_id,registro_acesso_id,morador_id,janela_inicio,titulo,mensagem) VALUES (?, ?, NULLIF(?,0), ?, ?, ?, ?)', 'iiiisss', [$tenantId,(int)$alerta['id'],$registroId ?: 0,$moradorId,$inicio,$titulo,$mensagem]);
            $persistiu = $stmt->affected_rows > 0;
            $stmt->close();
            if (!$persistiu) { $c->commit(); $transacaoIniciada = false; return ['sucesso'=>true,'deduplicada'=>true]; }

            $notificacaoId = 0;
            $notificacoesExiste = protocolo_notificacao_tabela_existe($c, 'notificacoes_morador');
            if ($notificacoesExiste) {
                $stmt = au_query($c, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notificacoes_morador' AND COLUMN_NAME IN ('alerta_unidade_id','registro_acesso_id')", '', []);
                $colunasNotificacao = [];
                foreach (au_rows($stmt) as $coluna) $colunasNotificacao[(string)$coluna['COLUMN_NAME']] = true;
                $stmt->close();
                $colunas = ['tenant_id','morador_id','protocolo_id','tipo','titulo','mensagem','descricao_mercadoria','nome_recebedor','push_status'];
                $valores = ['?','?','NULL',"'alerta_unidade_acionado'",'?','?','?', 'NULL',"'pendente'"];
                $tipos = 'iisss';
                $paramsNotificacao = [$tenantId,$moradorId,$titulo,$mensagem,$mensagem];
                if (isset($colunasNotificacao['alerta_unidade_id'])) { $colunas[]='alerta_unidade_id'; $valores[]='?'; $tipos.='i'; $paramsNotificacao[]=(int)$alerta['id']; }
                if (isset($colunasNotificacao['registro_acesso_id'])) { $colunas[]='registro_acesso_id'; $valores[]='NULLIF(?,0)'; $tipos.='i'; $paramsNotificacao[]=$registroId ?: 0; }
                $sql = 'INSERT INTO notificacoes_morador (' . implode(',', $colunas) . ') VALUES (' . implode(',', $valores) . ')';
                $stmt = au_query($c, $sql, $tipos, $paramsNotificacao);
                $notificacaoId = (int)$c->insert_id;
                $stmt->close();
                if ($notificacaoId > 0) {
                    $stmt = au_query($c, 'UPDATE alertas_unidade_notificacoes SET notificacao_morador_id=? WHERE tenant_id=? AND alerta_id=? AND morador_id=? AND janela_inicio=?', 'iiiis', [$notificacaoId,$tenantId,(int)$alerta['id'],$moradorId,$inicio]);
                    $stmt->close();
                }
            }

            $c->commit();
            $transacaoIniciada = false;
            $config = protocolo_notificacao_config($c, $tenantId);
            $pushTenantAtivo = ($config['push_alertas_unidade_ativo'] ?? '1') !== '0';
            $pushMoradorAtivo = alertas_unidade_morador_push_ativo($c, $tenantId, $moradorId);
            $pushAtivo = $pushTenantAtivo && $pushMoradorAtivo;
            $status = 'desativado';
            $detalhe = !$pushTenantAtivo ? 'Push desativado para Alertas da Unidade pelo condomínio.' : (!$pushMoradorAtivo ? 'Push desativado nas preferências do morador.' : '');
            $enviados = 0; $falhas = 0;
            if ($pushAtivo && $notificacoesExiste) {
                $projectId = trim((string)($config['fcm_project_id'] ?? ''));
                if ($projectId === '' || strpos($projectId, 'SUBSTITUA') === 0) {
                    $status = 'nao_configurado'; $detalhe = 'Firebase não configurado.';
                } elseif (!protocolo_notificacao_tabela_existe($c, 'pwa_fcm_tokens')) {
                    $status = 'sem_token'; $detalhe = 'Tabela de tokens push indisponível.';
                } else {
                    $tokens = au_rows(au_query($c, 'SELECT id,fcm_token FROM pwa_fcm_tokens WHERE tenant_id=? AND morador_id=? AND ativo=1', 'ii', [$tenantId,$moradorId]));
                    if (!$tokens) { $status = 'sem_token'; $detalhe = 'Nenhum dispositivo ativo para o morador.'; }
                    else {
                        foreach ($tokens as $item) {
                            try {
                                $r = protocolo_notificacao_enviar_fcm($item['fcm_token'],$titulo,$mensagem,['tipo'=>'alerta_unidade_acionado','notificacao_id'=>$notificacaoId,'alerta_id'=>(int)$alerta['id'],'registro_acesso_id'=>$registroId,'rota'=>'/home/notifications'],$projectId,'controle_acesso');
                                if (!empty($r['sucesso'])) $enviados++;
                                else {
                                    $falhas++;
                                    if (!empty($r['invalido'])) { $desativar = au_query($c,'UPDATE pwa_fcm_tokens SET ativo=0 WHERE tenant_id=? AND id=?','ii',[$tenantId,(int)$item['id']]); $desativar->close(); }
                                }
                            } catch (Throwable $e) { $falhas++; }
                        }
                        $status = $enviados > 0 ? 'enviado' : 'falhou';
                        $detalhe = "Push entregue a {$enviados} dispositivo(s); falhas: {$falhas}.";
                    }
                }
            } elseif (!$notificacoesExiste) {
                $status = 'nao_configurado'; $detalhe = 'Tabela de notificações do Portal indisponível.';
            }
            if ($notificacaoId > 0) {
                $stmt = au_query($c, 'UPDATE notificacoes_morador SET push_status=?,push_detalhe=? WHERE tenant_id=? AND id=?', 'ssii', [$status,$detalhe,$tenantId,$notificacaoId]);
                $stmt->close();
            }
            $stmt = au_query($c, 'UPDATE alertas_unidade_notificacoes SET push_status=?,push_detalhe=? WHERE tenant_id=? AND alerta_id=? AND morador_id=? AND janela_inicio=?', 'ssiiis', [$status,$detalhe,$tenantId,(int)$alerta['id'],$moradorId,$inicio]);
            $stmt->close();
            return ['sucesso'=>true,'deduplicada'=>false,'notificacao_id'=>$notificacaoId,'push'=>$status];
        } catch (Throwable $e) {
            if ($transacaoIniciada) { try { $c->rollback(); } catch (Throwable $ignored) {} }
            error_log('[AlertasUnidade] notificação best-effort indisponível.');
            return ['sucesso'=>false,'motivo'=>'notificacao_indisponivel'];
        }
    }
}

if (!function_exists('alertas_unidade_notificar_expiracao')) {
    function alertas_unidade_notificar_expiracao(mysqli $c, array $alerta): array {
        $tenantId = (int)($alerta['tenant_id'] ?? 0);
        $alertaId = (int)($alerta['id'] ?? 0);
        $moradorId = (int)($alerta['criado_por_morador_id'] ?? 0);
        if ($moradorId <= 0) $moradorId = (int)($alerta['morador_id'] ?? 0);
        $vigenciaFim = (string)($alerta['vigencia_fim'] ?? '');
        if ($tenantId <= 0 || $alertaId <= 0 || $moradorId <= 0 || $vigenciaFim === '') return ['sucesso'=>false,'motivo'=>'dados_invalidos'];

        $transacaoIniciada = false;
        try {
            $titulo = 'Seu alerta termina amanhã';
            $tituloAlerta = function_exists('mb_substr') ? mb_substr((string)($alerta['titulo'] ?? ''),0,120,'UTF-8') : substr((string)($alerta['titulo'] ?? ''),0,120);
            $mensagem = 'Seu alerta "' . $tituloAlerta . '" para a unidade ' . (string)($alerta['unidade'] ?? '') . ' termina em 24 horas, em ' . (new DateTime($vigenciaFim, new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i') . '. Acesse Meus Alertas para revisar ou prorrogar.';
            $c->begin_transaction();
            $transacaoIniciada = true;
            $stmt = au_query($c, "INSERT IGNORE INTO alertas_unidade_lembretes (tenant_id,alerta_id,morador_id,tipo,vigencia_fim) VALUES (?,?,?,'EXPIRACAO_24H',?)", 'iiis', [$tenantId,$alertaId,$moradorId,$vigenciaFim]);
            $novoLembrete = $stmt->affected_rows === 1;
            $lembreteId = (int)$c->insert_id;
            $stmt->close();
            if (!$novoLembrete) { $c->commit(); return ['sucesso'=>true,'deduplicado'=>true]; }

            if (!protocolo_notificacao_tabela_existe($c,'notificacoes_morador')) {
                $stmt = au_query($c, "UPDATE alertas_unidade_lembretes SET status='SEM_CANAL',push_status='nao_configurado',push_detalhe='Inbox do Portal indisponível.',processado_em=NOW() WHERE tenant_id=? AND id=?", 'ii', [$tenantId,$lembreteId]);
                $stmt->close();
                $c->commit(); $transacaoIniciada = false;
                return ['sucesso'=>true,'sem_canal'=>true];
            }

            $stmt = au_query($c, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notificacoes_morador' AND COLUMN_NAME IN ('alerta_unidade_id','registro_acesso_id')", '', []);
            $colunasInbox = [];
            foreach (au_rows($stmt) as $coluna) $colunasInbox[(string)$coluna['COLUMN_NAME']] = true;
            $stmt->close();
            $colunasInsert = ['tenant_id','morador_id','protocolo_id','tipo','titulo','mensagem','descricao_mercadoria','nome_recebedor','push_status'];
            $valoresInsert = ['?','?','NULL',"'alerta_unidade_expira_24h'",'?','?','?','NULL',"'pendente'"];
            $tiposInsert = 'iisss';
            $paramsInsert = [$tenantId,$moradorId,$titulo,$mensagem,$mensagem];
            if (isset($colunasInbox['alerta_unidade_id'])) { $colunasInsert[]='alerta_unidade_id'; $valoresInsert[]='?'; $tiposInsert.='i'; $paramsInsert[]=$alertaId; }
            $sqlInbox = 'INSERT INTO notificacoes_morador (' . implode(',',$colunasInsert) . ') VALUES (' . implode(',',$valoresInsert) . ')';
            $stmt = au_query($c,$sqlInbox,$tiposInsert,$paramsInsert);
            $notificacaoId = (int)$c->insert_id;
            $stmt->close();
            $stmt = au_query($c, "UPDATE alertas_unidade_lembretes SET notificacao_morador_id=?,status='CRIADO',processado_em=NOW() WHERE tenant_id=? AND id=?", 'iii', [$notificacaoId,$tenantId,$lembreteId]);
            $stmt->close();
            $c->commit(); $transacaoIniciada = false;

            $config = protocolo_notificacao_config($c,$tenantId);
            $pushStatus = 'desativado';
            $pushTenantAtivo = ($config['push_alertas_unidade_ativo'] ?? '1') !== '0';
            $pushMoradorAtivo = alertas_unidade_morador_push_ativo($c, $tenantId, $moradorId);
            $pushDetalhe = !$pushTenantAtivo ? 'Push de Alertas da Unidade desativado para o tenant.' : (!$pushMoradorAtivo ? 'Push desativado nas preferências do morador.' : '');
            $enviados = 0; $falhas = 0;
            if ($pushTenantAtivo && $pushMoradorAtivo) {
                $projectId = trim((string)($config['fcm_project_id'] ?? ''));
                if ($projectId === '' || strpos($projectId,'SUBSTITUA') === 0) {
                    $pushStatus='nao_configurado'; $pushDetalhe='Firebase não configurado.';
                } elseif (!protocolo_notificacao_tabela_existe($c,'pwa_fcm_tokens')) {
                    $pushStatus='sem_token'; $pushDetalhe='Tabela de tokens push indisponível.';
                } else {
                    $stmt = au_query($c,'SELECT id,fcm_token FROM pwa_fcm_tokens WHERE tenant_id=? AND morador_id=? AND ativo=1','ii',[$tenantId,$moradorId]);
                    $tokens = au_rows($stmt); $stmt->close();
                    if (!$tokens) { $pushStatus='sem_token'; $pushDetalhe='Nenhum dispositivo ativo para o morador.'; }
                    else {
                        foreach ($tokens as $token) {
                            try {
                                $resultado = protocolo_notificacao_enviar_fcm($token['fcm_token'],$titulo,$mensagem,['tipo'=>'alerta_unidade_expira_24h','notificacao_id'=>$notificacaoId,'alerta_id'=>$alertaId,'rota'=>'/frontend/portal_morador.html?aba=alertas'],$projectId,'alertas_unidade');
                                if (!empty($resultado['sucesso'])) $enviados++;
                                else {
                                    $falhas++;
                                    if (!empty($resultado['invalido'])) { $stmt=au_query($c,'UPDATE pwa_fcm_tokens SET ativo=0 WHERE tenant_id=? AND id=?','ii',[$tenantId,(int)$token['id']]); $stmt->close(); }
                                }
                            } catch (Throwable $ignorado) { $falhas++; }
                        }
                        $pushStatus=$enviados>0?'enviado':'falhou';
                        $pushDetalhe="Push entregue a {$enviados} dispositivo(s); falhas: {$falhas}.";
                    }
                }
            }
            $stmt = au_query($c,'UPDATE notificacoes_morador SET push_status=?,push_detalhe=? WHERE tenant_id=? AND id=?','ssii',[$pushStatus,$pushDetalhe,$tenantId,$notificacaoId]); $stmt->close();
            $stmt = au_query($c,'UPDATE alertas_unidade_lembretes SET push_status=?,push_detalhe=? WHERE tenant_id=? AND id=?','ssii',[$pushStatus,$pushDetalhe,$tenantId,$lembreteId]); $stmt->close();
            return ['sucesso'=>true,'deduplicado'=>false,'notificacao_id'=>$notificacaoId,'push'=>$pushStatus];
        } catch (Throwable $e) {
            if ($transacaoIniciada) { try { $c->rollback(); } catch (Throwable $ignorado) {} }
            error_log('[AlertasUnidade] lembrete de expiração indisponível.');
            return ['sucesso'=>false,'motivo'=>'notificacao_indisponivel'];
        }
    }
}

if (!function_exists('alertas_unidade_processar_rotina_expiracao')) {
    function alertas_unidade_processar_rotina_expiracao(mysqli $c): array {
        $nomeLock = 'alertas_unidade_expiracao_v1';
        $stmt = au_query($c,'SELECT GET_LOCK(?,0) adquirido','s',[$nomeLock]);
        $lockAdquirido = (int)($stmt->get_result()->fetch_assoc()['adquirido'] ?? 0) === 1;
        $stmt->close();
        if (!$lockAdquirido) return ['lock_adquirido'=>false,'expirados'=>0,'lembretes'=>0,'falhas'=>0];

        $resultado = ['lock_adquirido'=>true,'expirados'=>0,'lembretes'=>0,'falhas'=>0];
        try {
            $agora = new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo'));
            $agoraSql = $agora->format('Y-m-d H:i:s');
            $inicioAviso = $agora->modify('+23 hours 50 minutes')->format('Y-m-d H:i:s');
            $fimAviso = $agora->modify('+24 hours 10 minutes')->format('Y-m-d H:i:s');
            // Esta enumeração global só identifica tenants com alertas ativos;
            // cada leitura e mutação subsequente é sempre limitada ao tenant atual.
            $stmt = au_query($c,"SELECT DISTINCT tenant_id FROM alertas_unidade WHERE status='ATIVO' ORDER BY tenant_id");
            $tenants = au_rows($stmt); $stmt->close();
            foreach ($tenants as $itemTenant) {
                $tenantId = (int)$itemTenant['tenant_id'];
                if ($tenantId <= 0) continue;
                $c->begin_transaction();
                try {
                    $stmt = au_query($c,"SELECT id,vigencia_fim FROM alertas_unidade WHERE tenant_id=? AND status='ATIVO' AND vigencia_fim IS NOT NULL AND vigencia_fim<=? ORDER BY vigencia_fim,id LIMIT 500 FOR UPDATE",'is',[$tenantId,$agoraSql]);
                    $expirados = au_rows($stmt); $stmt->close();
                    foreach ($expirados as $alerta) {
                        $id = (int)$alerta['id'];
                        $stmt = au_query($c,"UPDATE alertas_unidade SET status='EXPIRADO',atualizado_em=? WHERE tenant_id=? AND id=? AND status='ATIVO' AND vigencia_fim<=?",'siis',[$agoraSql,$tenantId,$id,$agoraSql]);
                        $atualizou = $stmt->affected_rows === 1; $stmt->close();
                        if (!$atualizou) continue;
                        $antes = json_encode(['status'=>'ATIVO','vigencia_fim'=>$alerta['vigencia_fim']],JSON_UNESCAPED_UNICODE);
                        $depois = json_encode(['status'=>'EXPIRADO','vigencia_fim'=>$alerta['vigencia_fim']],JSON_UNESCAPED_UNICODE);
                        $stmt = au_query($c,"INSERT INTO alertas_unidade_historico (tenant_id,alerta_id,operacao,usuario_id,usuario_nome,origem,dados_antes_json,dados_depois_json,motivo) VALUES (?,?,'EXPIRAR',NULL,'Rotina automática','SISTEMA',?,?,?)",'iisss',[$tenantId,$id,$antes,$depois,'Fim da vigência atingido.']);
                        $stmt->close(); $resultado['expirados']++;
                    }
                    $c->commit();
                } catch (Throwable $e) { $c->rollback(); throw $e; }

                $stmt = au_query($c,"SELECT a.tenant_id,a.id,a.morador_id,a.criado_por_morador_id,a.unidade,a.titulo,a.vigencia_fim FROM alertas_unidade a INNER JOIN moradores m ON m.tenant_id=a.tenant_id AND m.id=COALESCE(a.criado_por_morador_id,a.morador_id) AND m.ativo=1 WHERE a.tenant_id=? AND a.status='ATIVO' AND a.criado_por_tipo='MORADOR' AND a.vigencia_fim>? AND a.vigencia_fim<=? ORDER BY a.vigencia_fim,a.id LIMIT 500",'iss',[$tenantId,$inicioAviso,$fimAviso]);
                $lembretes = au_rows($stmt); $stmt->close();
                foreach ($lembretes as $alerta) {
                    $envio = alertas_unidade_notificar_expiracao($c,$alerta);
                    if (!empty($envio['deduplicado'])) continue;
                    if (!empty($envio['sucesso'])) $resultado['lembretes']++;
                    else $resultado['falhas']++;
                }
            }
            return $resultado;
        } finally {
            $stmt = au_query($c,'SELECT RELEASE_LOCK(?) liberado','s',[$nomeLock]);
            $stmt->close();
        }
    }
}
