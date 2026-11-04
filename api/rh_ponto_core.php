<?php
// =====================================================
// RH — Núcleo compartilhado de cálculo de jornada/ponto
// =====================================================
require_once __DIR__ . '/helpers/rh_banco_horas_helper.php';

// Usado por api_rh_ponto.php e api_rh_abono.php. Não define suas próprias
// conexões/headers — quem inclui este arquivo já deve ter feito o bootstrap
// (config.php, auth_helper.php) e ter $conn (mysqli) disponível.
//
// A Escala Manual (rh_escala.tipo = 'escala_manual') é resolvida por
// _escala_manual_dia()/_obter_parametros_jornada_dia() exatamente como os
// demais tipos de escala (controle_jornada, alternada, jornada_44h/40h/36h),
// de modo que _calcular_minutos()/_calcular_minutos_par() tratem qualquer
// tipo de escala de forma unificada — sem cópias duplicadas do parsing do
// JSON de escala_manual_semana.

if (!function_exists('_hora_valida')) {
function _hora_valida(?string $h): ?string {
    if ($h === '' || $h === null) return null;
    return preg_match('/^\d{2}:\d{2}$/', $h) ? $h . ':00' : (preg_match('/^\d{2}:\d{2}:\d{2}$/', $h) ? $h : null);
}
}

if (!function_exists('_hora_em_minutos')) {
function _hora_em_minutos(?string $h): int {
    if (!$h) return 0;
    [$hh, $mm] = explode(':', $h);
    return intval($hh) * 60 + intval($mm);
}
}

if (!function_exists('_buscar_escala')) {
function _buscar_escala($conn, int $colab_id, ?int $tenant_id = null): ?array {
    $tenant_id = $tenant_id ?: (int)($_SESSION['tenant_id'] ?? 0);
    if ($tenant_id <= 0) return null;
    $stmt = $conn->prepare("SELECT * FROM rh_escala WHERE tenant_id=? AND colaborador_id=? AND ativo=1 ORDER BY id ASC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('ii', $tenant_id, $colab_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}
}

if (!function_exists('_escala_manual_dia')) {
/**
 * Resolve a configuração do dia da semana correspondente a $data dentro de
 * rh_escala.escala_manual_semana (JSON por dia: seg/ter/qua/qui/sex/sab/dom).
 * Única implementação do parsing — substitui as cópias que existiam em
 * _escala_manual_injetar_carga(), _gerar_dias_mes() e _bh_sincronizar().
 */
function _escala_manual_dia(?array $escala, string $data): array {
    $default = [
        'ativo' => false, 'hora_entrada' => null, 'hora_almoco_saida' => null,
        'hora_almoco_retorno' => null, 'hora_saida' => null, 'intervalo_min' => 0, 'carga_min' => 0,
    ];
    if (!$escala) return $default;

    $mapDow  = ['Sun'=>'dom','Mon'=>'seg','Tue'=>'ter','Wed'=>'qua','Thu'=>'qui','Fri'=>'sex','Sat'=>'sab'];
    $diaNome = $mapDow[date('D', strtotime($data))] ?? '';
    $semana  = json_decode($escala['escala_manual_semana'] ?? '{}', true) ?? [];
    $cfg     = $semana[$diaNome] ?? [];

    if (empty($cfg['ativo'])) return $default;

    $entMin = _hora_em_minutos($cfg['hora_entrada'] ?? '00:00');
    $saiMin = _hora_em_minutos($cfg['hora_saida']   ?? '00:00');
    if ($saiMin < $entMin) $saiMin += 1440; // turno cruza meia-noite (ex.: 19:00 -> 07:00)
    $intMin = intval($cfg['intervalo_min'] ?? 0);
    $carga  = max(0, $saiMin - $entMin - $intMin);

    return [
        'ativo'               => true,
        'hora_entrada'        => $cfg['hora_entrada']        ?? null,
        'hora_almoco_saida'   => $cfg['hora_almoco_saida']   ?? null,
        'hora_almoco_retorno' => $cfg['hora_almoco_retorno'] ?? null,
        'hora_saida'          => $cfg['hora_saida']          ?? null,
        'intervalo_min'       => $intMin,
        'carga_min'           => $carga,
    ];
}
}

if (!function_exists('_obter_parametros_jornada_dia')) {
/**
 * Resolve os parâmetros da jornada esperada de um colaborador num dia
 * específico, para QUALQUER tipo de escala (inclui escala_manual). Ponto
 * central de leitura da escala — usado por _calcular_minutos(),
 * _calcular_minutos_par() e _recalcular_totais() para decidir extra/atraso/
 * saída antecipada/carga prevista de forma consistente.
 */
function _obter_parametros_jornada_dia(?array $escala, string $data): array {
    $semControle = [
        'tem_controle' => false, 'dia_ativo' => true, 'carga_min' => 0,
        'hora_entrada_esperada' => null, 'hora_saida_esperada' => null, 'tolerancia_min' => 10,
    ];
    if (!$escala) return $semControle;

    $tipo = $escala['tipo'] ?? '';

    if ($tipo === 'escala_manual') {
        $dia = _escala_manual_dia($escala, $data);
        return [
            'tem_controle'          => true,
            'dia_ativo'             => $dia['ativo'],
            'carga_min'             => $dia['carga_min'],
            'hora_entrada_esperada' => $dia['hora_entrada'],
            'hora_saida_esperada'   => $dia['hora_saida'],
            'tolerancia_min'        => intval($escala['tolerancia_minutos'] ?? 10),
        ];
    }

    $tiposComControle = ['controle_jornada', 'alternada', 'jornada_44h', 'jornada_40h', 'jornada_36h'];
    if (in_array($tipo, $tiposComControle)) {
        return [
            'tem_controle'          => true,
            // Dias fora da escala fixa (dias_trabalho/semana alternada) já chegam
            // como tipo_dia='folga' via _gerar_dias_mes — aqui sempre é dia ativo.
            'dia_ativo'             => true,
            'carga_min'             => intval($escala['carga_horaria_diaria_min'] ?? 480),
            'hora_entrada_esperada' => $escala['hora_entrada'] ?? '08:00',
            'hora_saida_esperada'   => $escala['hora_saida']   ?? null,
            'tolerancia_min'        => intval($escala['tolerancia_minutos'] ?? 10),
        ];
    }

    return $semControle;
}
}

if (!function_exists('_calcular_minutos')) {
function _calcular_minutos(?string $he, ?string $has, ?string $har, ?string $hs, string $tipo_dia, ?array $escala, string $data = ''): array {
    $resultado = ['trabalhadas' => 0, 'extras' => 0, 'atraso' => 0, 'saida_antecipada' => 0];

    // Falta: sempre zero, mesmo que horário tenha sido preenchido por engano.
    if ($tipo_dia === 'falta') return $resultado;

    if (!$he || !$hs) return $resultado;

    $mHe  = _hora_em_minutos($he);
    $mHs  = _hora_em_minutos($hs);
    $mHas = _hora_em_minutos($has ?: '12:00');
    $mHar = _hora_em_minutos($har ?: $has ?: '13:00');

    // Turnos que cruzam meia-noite (ex.: 12x36 — entra 18:00, sai 06:00 do dia seguinte)
    if ($mHs  < $mHe)  $mHs  += 1440;
    if ($mHas < $mHe)  $mHas += 1440;
    if ($mHar < $mHas) $mHar += 1440;

    $intervalo   = max(0, $mHar - $mHas);
    $trabalhadas = max(0, ($mHs - $mHe) - $intervalo);
    $resultado['trabalhadas'] = $trabalhadas;

    $params = _obter_parametros_jornada_dia($escala, $data);

    // Dia sem expediente previsto (folga/feriado/afastamento/horas_extras, ou dia
    // inativo da escala manual) mas com horário batido -> tudo é hora extra.
    $semExpedientePrevisto = in_array($tipo_dia, ['folga', 'feriado', 'afastamento', 'horas_extras'])
        || ($params['tem_controle'] && !$params['dia_ativo']);
    if ($semExpedientePrevisto) {
        $resultado['extras'] = $trabalhadas;
        return $resultado;
    }

    if ($params['tem_controle']) {
        $carga      = $params['carga_min'];
        $tolerancia = $params['tolerancia_min'];

        if ($trabalhadas > ($carga + $tolerancia)) {
            $resultado['extras'] = $trabalhadas - $carga;
        }

        if ($params['hora_entrada_esperada']) {
            $diffEntrada = $mHe - _hora_em_minutos($params['hora_entrada_esperada']);
            if ($diffEntrada > $tolerancia && $diffEntrada < 240) {
                $resultado['atraso'] = $diffEntrada;
            }
        }

        if ($params['hora_saida_esperada']) {
            $mEsperadoSaida = _hora_em_minutos($params['hora_saida_esperada']);
            if ($mEsperadoSaida < $mHe) $mEsperadoSaida += 1440; // escala noturna
            $diffSaida = $mEsperadoSaida - $mHs;
            if ($diffSaida > $tolerancia && $diffSaida < 240) {
                $resultado['saida_antecipada'] = $diffSaida;
            }
        }
    }

    return $resultado;
}
}

if (!function_exists('_calcular_minutos_par')) {
// Calcula horas de um turno que SEMPRE cruza meia-noite:
// entrada em $he (dia N), saída em $hs_next (dia N+1).
function _calcular_minutos_par(string $he, ?string $has_e, ?string $har_e, string $hs_next, string $tipo_dia, ?array $escala, string $data = ''): array {
    $resultado = ['trabalhadas' => 0, 'extras' => 0, 'atraso' => 0, 'saida_antecipada' => 0];
    if ($tipo_dia === 'falta') return $resultado;

    $mHe  = _hora_em_minutos($he);
    $mHs  = _hora_em_minutos($hs_next) + 1440; // sempre dia seguinte
    $mHas = _hora_em_minutos($has_e ?: '12:00');
    $mHar = _hora_em_minutos($har_e ?: $has_e ?: '13:00');

    if ($mHas < $mHe)  $mHas += 1440;
    if ($mHar < $mHas) $mHar += 1440;

    $intervalo   = max(0, $mHar - $mHas);
    $trabalhadas = max(0, $mHs - $mHe - $intervalo);
    $resultado['trabalhadas'] = $trabalhadas;

    $params = _obter_parametros_jornada_dia($escala, $data);

    $semExpedientePrevisto = in_array($tipo_dia, ['folga', 'feriado', 'afastamento', 'horas_extras'])
        || ($params['tem_controle'] && !$params['dia_ativo']);
    if ($semExpedientePrevisto) {
        $resultado['extras'] = $trabalhadas;
        return $resultado;
    }

    if ($params['tem_controle']) {
        $carga      = $params['carga_min'];
        $tolerancia = $params['tolerancia_min'];

        if ($trabalhadas > ($carga + $tolerancia)) {
            $resultado['extras'] = $trabalhadas - $carga;
        }
        if ($params['hora_entrada_esperada']) {
            $diffEntrada = $mHe - _hora_em_minutos($params['hora_entrada_esperada']);
            if ($diffEntrada > $tolerancia && $diffEntrada < 240) {
                $resultado['atraso'] = $diffEntrada;
            }
        }
        if ($params['hora_saida_esperada']) {
            // Turno sempre cruza meia-noite: hora esperada de saída também é no dia seguinte.
            $mEsperadoSaida = _hora_em_minutos($params['hora_saida_esperada']) + 1440;
            $diffSaida = $mEsperadoSaida - $mHs;
            if ($diffSaida > $tolerancia && $diffSaida < 240) {
                $resultado['saida_antecipada'] = $diffSaida;
            }
        }
    }

    return $resultado;
}
}

if (!function_exists('_ponto_garantir_colunas')) {
/**
 * Valida o schema necessário. DDL deve ser aplicado na migration de deploy,
 * nunca em uma requisição concorrente de Ponto.
 */
function _ponto_garantir_colunas($conn): void {
    foreach (['rh_ponto_lancamento'=>['saida_antecipada_min'],'rh_ponto_periodo'=>['total_saida_antecipada_min','total_carga_prevista_min']] as $table=>$required) {
        $res=$conn->query('SHOW COLUMNS FROM `'.$table.'`');
        if (!$res) throw new RuntimeException('Schema RH pendente; aplique migration_rh_banco_horas_mapeamento_mysql57.sql.');
        $columns=[];while($r=$res->fetch_assoc())$columns[]=$r['Field'];$res->free();
        foreach($required as $column) if(!in_array($column,$columns,true)) throw new RuntimeException('Schema RH pendente; aplique migration_rh_banco_horas_mapeamento_mysql57.sql.');
    }
}
}

if (!function_exists('_abono_garantir_colunas')) {
/**
 * Valida campos de Abono usados pelo motor compartilhado; DDL permanece
 * exclusivamente na migration de deploy para evitar locks/commits implícitos.
 */
function _abono_garantir_colunas($conn): void {
    $res = $conn->query("SHOW COLUMNS FROM rh_ponto_lancamento");
    if (!$res) throw new RuntimeException('Schema de Abono indisponível; aplique migration_rh_banco_horas_mapeamento_mysql57.sql.');
    $cols=[];while($r=$res->fetch_assoc())$cols[]=$r['Field'];$res->free();
    foreach(['abono_extras','abono_extras_min','abono_falta','abono_atraso','abono_atraso_min','abono_justificativa','abono_usuario','abono_data'] as $column) if(!in_array($column,$cols,true)) throw new RuntimeException('Schema de Abono pendente; aplique migration_rh_banco_horas_mapeamento_mysql57.sql.');
}
}

if (!function_exists('_recalcular_totais')) {
/**
 * Agrega os totais do período. Reescrito para loop em PHP (em vez de uma
 * única query agregada) por dois motivos: (1) a carga prevista de
 * escala_manual depende do JSON por dia, resolvido via
 * _obter_parametros_jornada_dia(); (2) o abono (total/parcial) precisa ser
 * líquido — subtraído do bruto antes de somar aos totais do período.
 * Não muda horas_extras_min/atraso_min por lançamento (fato bruto do dia);
 * só o agregado do período reflete o abono.
 */
function _recalcular_totais($conn, int $periodo_id, ?int $tenant_id = null): void {
    $tenant_id = $tenant_id ?: (int)($_SESSION['tenant_id'] ?? 0);
    if ($tenant_id <= 0) return;
    _abono_garantir_colunas($conn);

    $stmtPeriodo = $conn->prepare('SELECT colaborador_id FROM rh_ponto_periodo WHERE tenant_id = ? AND id = ? LIMIT 1');
    if (!$stmtPeriodo) return;
    $stmtPeriodo->bind_param('ii', $tenant_id, $periodo_id);
    if (!$stmtPeriodo->execute()) { $stmtPeriodo->close(); return; }
    $rowP = $stmtPeriodo->get_result()->fetch_assoc();
    $stmtPeriodo->close();
    $escala = $rowP ? _buscar_escala($conn, (int)$rowP['colaborador_id'], $tenant_id) : null;

    $stmtRows = $conn->prepare(
        "SELECT data, tipo_dia, horas_trabalhadas_min, horas_extras_min, atraso_min, saida_antecipada_min,
                abono_extras, abono_extras_min, abono_falta, abono_atraso, abono_atraso_min
         FROM rh_ponto_lancamento WHERE tenant_id = ? AND periodo_id = ?"
    );
    if (!$stmtRows) return;
    $stmtRows->bind_param('ii', $tenant_id, $periodo_id);
    if (!$stmtRows->execute()) { $stmtRows->close(); return; }
    $res = $stmtRows->get_result();

    $trab = $ext = $atr = $sai = $falt = $folg = $prevista = 0;
    while ($t = $res->fetch_assoc()) {
        $trab += (int)$t['horas_trabalhadas_min'];

        $extraBruto  = (int)$t['horas_extras_min'];
        $atrasoBruto = (int)$t['atraso_min'];

        $extraAbonado = $t['abono_extras'] === 'total' ? $extraBruto
            : ($t['abono_extras'] === 'parcial' ? min((int)$t['abono_extras_min'], $extraBruto) : 0);
        $atrasoAbonado = $t['abono_atraso'] === 'total' ? $atrasoBruto
            : ($t['abono_atraso'] === 'parcial' ? min((int)$t['abono_atraso_min'], $atrasoBruto) : 0);

        $ext += max(0, $extraBruto - $extraAbonado);
        $atr += max(0, $atrasoBruto - $atrasoAbonado);
        $sai += (int)$t['saida_antecipada_min'];

        if ($t['tipo_dia'] === 'falta' && empty($t['abono_falta'])) $falt++;
        if ($t['tipo_dia'] === 'folga') $folg++;

        $params = _obter_parametros_jornada_dia($escala, $t['data']);
        if ($params['tem_controle'] && $params['dia_ativo']) $prevista += $params['carga_min'];
    }

    $stmt = $conn->prepare(
        "UPDATE rh_ponto_periodo SET
         total_horas_trabalhadas_min=?, total_horas_extras_min=?,
         total_atraso_min=?, total_saida_antecipada_min=?, total_faltas=?, total_folgas=?,
         total_carga_prevista_min=?
         WHERE tenant_id=? AND id=?"
    );
    $stmt->bind_param('iiiiiiiii', $trab, $ext, $atr, $sai, $falt, $folg, $prevista, $tenant_id, $periodo_id);
    $stmt->execute(); $stmt->close();
    $stmtRows->close();
}
}

if (!function_exists('bh_calcular_dias_periodo')) {
/** Calcula um período reutilizando as regras de jornada e o pareamento 12x36. */
function bh_calcular_dias_periodo(mysqli $conn, int $tenantId, int $periodoId, ?array $escala, bool $forUpdate = false): array {
    $stPeriodo = $conn->prepare('SELECT id, colaborador_id, status FROM rh_ponto_periodo WHERE tenant_id=? AND id=? LIMIT 1');
    if (!$stPeriodo) throw new RuntimeException('Falha ao preparar consulta do período.');
    $stPeriodo->bind_param('ii', $tenantId, $periodoId);
    if (!$stPeriodo->execute()) { $stPeriodo->close(); throw new RuntimeException('Falha ao consultar período.'); }
    $periodo = $stPeriodo->get_result()->fetch_assoc();
    $stPeriodo->close();
    if (!$periodo) throw new RuntimeException('Período não encontrado.');
    _abono_garantir_colunas($conn);
    $sql = 'SELECT l.*, p.status AS periodo_status FROM rh_ponto_lancamento l
            INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id
            WHERE l.tenant_id=? AND l.periodo_id=? ORDER BY l.data ASC, l.id ASC';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $st = $conn->prepare($sql);
    if (!$st) throw new RuntimeException('Falha ao preparar consulta dos lançamentos.');
    $st->bind_param('ii', $tenantId, $periodoId);
    if (!$st->execute()) { $st->close(); throw new RuntimeException('Falha ao consultar lançamentos.'); }
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $extraRow = null;
    if ($rows) {
        $first = $rows[0];
        if (empty($first['hora_entrada']) && !empty($first['hora_saida'])) {
            $previousSql = 'SELECT l.*, p.status AS periodo_status FROM rh_ponto_lancamento l
                            INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id
                            WHERE l.tenant_id=? AND l.colaborador_id=? AND l.data=DATE_SUB(?, INTERVAL 1 DAY)
                              AND l.hora_entrada IS NOT NULL AND l.hora_saida IS NULL ORDER BY l.id DESC LIMIT 1';
            if ($forUpdate) $previousSql .= ' FOR UPDATE';
            $previous = $conn->prepare($previousSql);
            if (!$previous) throw new RuntimeException('Falha ao preparar consulta do turno noturno anterior.');
            $colabId = (int)$periodo['colaborador_id'];
            $firstDate = (string)$first['data'];
            $previous->bind_param('iis', $tenantId, $colabId, $firstDate);
            if (!$previous->execute()) { $previous->close(); throw new RuntimeException('Falha ao consultar turno noturno anterior.'); }
            $parentRow = $previous->get_result()->fetch_assoc() ?: null;
            $previous->close();
            if ($parentRow) array_unshift($rows, $parentRow);
        }
        $last = $rows[count($rows) - 1];
        if (!empty($last['hora_entrada']) && empty($last['hora_saida'])) {
            $nextSql =
                'SELECT l.*, p.status AS periodo_status FROM rh_ponto_lancamento l
                 INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id
                 WHERE l.tenant_id=? AND l.colaborador_id=? AND l.data=DATE_ADD(?, INTERVAL 1 DAY)
                 ORDER BY l.id ASC LIMIT 1';
            if ($forUpdate) $nextSql .= ' FOR UPDATE';
            $next = $conn->prepare($nextSql);
            if (!$next) throw new RuntimeException('Falha ao preparar consulta de turno noturno.');
            $colabId = (int)$periodo['colaborador_id'];
            $dataLast = (string)$last['data'];
            $next->bind_param('iis', $tenantId, $colabId, $dataLast);
            if (!$next->execute()) { $next->close(); throw new RuntimeException('Falha ao consultar turno noturno.'); }
            $extraRow = $next->get_result()->fetch_assoc() ?: null;
            $next->close();
        }
    }
    $itens = [];
    $marcadoresAtualizados = [];
    for ($i = 0, $n = count($rows); $i < $n;) {
        $row = $rows[$i];
        $he = $row['hora_entrada'] ?: null;
        $hs = $row['hora_saida'] ?: null;
        $has = $row['hora_almoco_saida'] ?: null;
        $har = $row['hora_almoco_retorno'] ?: null;
        $calc = null;
        $nextRow = null;
        $skipNext = false;
        if ($he && $hs) {
            $calc = _calcular_minutos($he, $has, $har, $hs, (string)$row['tipo_dia'], $escala, (string)$row['data']);
        } elseif ($he && !$hs) {
            if (($i + 1) < $n) {
                $candidate = $rows[$i + 1];
                if ((string)$candidate['data'] === date('Y-m-d', strtotime((string)$row['data'] . ' +1 day')) &&
                    empty($candidate['hora_entrada']) && !empty($candidate['hora_saida'])) $nextRow = $candidate;
            } elseif ($extraRow && empty($extraRow['hora_entrada']) && !empty($extraRow['hora_saida'])) {
                $nextRow = $extraRow;
            }
            if ($nextRow) {
                $calc = _calcular_minutos_par($he, $has, $har, (string)$nextRow['hora_saida'], (string)$row['tipo_dia'], $escala, (string)$row['data']);
                if (($i + 1) < $n && (int)$nextRow['id'] === (int)$rows[$i + 1]['id']) $skipNext = true;
                else $marcadoresAtualizados[] = ['row'=>$nextRow, 'pareado_de'=>(string)$row['data']];
            }
        }
        if ($calc === null) $calc = ['trabalhadas'=>0, 'extras'=>0, 'atraso'=>0, 'saida_antecipada'=>0];
        $itens[] = ['row' => $row, 'calc' => $calc, 'pareado_data' => $nextRow ? (string)$nextRow['data'] : null];
        if ($skipNext) {
            $zero = ['trabalhadas'=>0, 'extras'=>0, 'atraso'=>0, 'saida_antecipada'=>0];
            $itens[] = ['row'=>$nextRow, 'calc'=>$zero, 'marcador'=>true, 'pareado_de'=>(string)$row['data']];
            $i += 2;
        } else {
            $i++;
        }
    }
    foreach ($marcadoresAtualizados as $marcador) {
        $itens[] = ['row'=>$marcador['row'], 'calc'=>['trabalhadas'=>0,'extras'=>0,'atraso'=>0,'saida_antecipada'=>0], 'marcador'=>true, 'pareado_de'=>$marcador['pareado_de']];
    }
    return ['periodo'=>$periodo, 'itens'=>$itens];
}
}

if (!function_exists('bh_processar_periodo')) {
/** Persiste cálculo e ledger automático; pode participar da transação/lock de lote externo. */
function bh_processar_periodo(mysqli $conn, int $tenantId, int $periodoId, ?array $escala, array $opcoes = []): array {
    $colabId = (int)($opcoes['colaborador_id'] ?? 0);
    if ($colabId <= 0) {
        $stColab = $conn->prepare('SELECT colaborador_id FROM rh_ponto_periodo WHERE tenant_id=? AND id=? LIMIT 1');
        if (!$stColab) throw new RuntimeException('Falha ao resolver colaborador do período.');
        $stColab->bind_param('ii', $tenantId, $periodoId);
        if (!$stColab->execute()) { $stColab->close(); throw new RuntimeException('Falha ao resolver colaborador do período.'); }
        $colabId = (int)($stColab->get_result()->fetch_assoc()['colaborador_id'] ?? 0);
        $stColab->close();
    }
    if ($colabId <= 0) throw new RuntimeException('Período não encontrado.');
    $lockExterno = !empty($opcoes['lock_ja_adquirido']);
    $transacaoExterna = !empty($opcoes['transacao_ja_ativa']);
    $lockAdquirido = false;
    $transacaoAtiva = false;
    try {
        $lockAdquirido = $lockExterno || bh_obter_lock_colaborador($conn, $tenantId, $colabId);
        if (!$lockAdquirido) throw new RuntimeException('Lock indisponível para recalcular período.');
        if (!$transacaoExterna) {
            if (!$conn->begin_transaction()) throw new RuntimeException('Falha ao iniciar recálculo do período.');
            $transacaoAtiva = true;
        }
        $lock = $conn->prepare('SELECT status FROM rh_ponto_periodo WHERE tenant_id=? AND id=? AND colaborador_id=? LIMIT 1 FOR UPDATE');
        if (!$lock) throw new RuntimeException('Falha ao preparar validação do período.');
        $lock->bind_param('iii', $tenantId, $periodoId, $colabId);
        if (!$lock->execute()) { $lock->close(); throw new RuntimeException('Falha ao validar status do período.'); }
        $periodo = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$periodo) throw new RuntimeException('Período não encontrado.');
        if (($periodo['status'] ?? '') !== 'aberto' && empty($opcoes['incluir_fechados'])) {
            throw new DomainException('PERIODO_FECHADO');
        }
        $calculado = bh_calcular_dias_periodo($conn, $tenantId, $periodoId, $escala);
        $upd = $conn->prepare(
            'UPDATE rh_ponto_lancamento SET horas_trabalhadas_min=?,horas_extras_min=?,atraso_min=?,saida_antecipada_min=?
             WHERE tenant_id=? AND colaborador_id=? AND periodo_id=? AND id=?'
        );
        if (!$upd) throw new RuntimeException('Falha ao preparar atualização de ponto.');
        $atualizados = 0;
        $periodosAfetados = [$periodoId=>true];
        foreach ($calculado['itens'] as $item) {
            $row = $item['row']; $calc = $item['calc'];
            $dateRow = (string)$row['data'];
            if (!empty($opcoes['data_inicio']) && !empty($opcoes['data_fim'])) {
                $inRange = $dateRow >= (string)$opcoes['data_inicio'] && $dateRow <= (string)$opcoes['data_fim'];
                $pairedInRange = !empty($item['pareado_data']) && (string)$item['pareado_data'] >= (string)$opcoes['data_inicio'] && (string)$item['pareado_data'] <= (string)$opcoes['data_fim'];
                $parentInRange = !empty($item['pareado_de']) && (string)$item['pareado_de'] >= (string)$opcoes['data_inicio'] && (string)$item['pareado_de'] <= (string)$opcoes['data_fim'];
                if (!$inRange && !$pairedInRange && !$parentInRange) continue;
            }
            if (($row['periodo_status'] ?? '') !== 'aberto' && empty($opcoes['incluir_fechados'])) continue;
            $horas = (int)$calc['trabalhadas']; $extras = (int)$calc['extras'];
            $atraso = (int)$calc['atraso']; $saida = (int)$calc['saida_antecipada'];
            $idLanc = (int)$row['id']; $colabRow = (int)$row['colaborador_id']; $periodoRow = (int)$row['periodo_id'];
            $upd->bind_param('iiiiiiii', $horas, $extras, $atraso, $saida, $tenantId, $colabRow, $periodoRow, $idLanc);
            if (!$upd->execute()) throw new RuntimeException('Falha ao atualizar totais diários do ponto.');
            if (empty($item['marcador'])) {
                _bh_sincronizar($conn, $idLanc, $colabRow, (string)$row['data'], $calc, $escala, $tenantId, [
                    'lock_ja_adquirido'=>true, 'transacao_ja_ativa'=>true,
                    'reprocessamento_id'=>$opcoes['reprocessamento_id'] ?? null,
                ]);
            }
            $periodosAfetados[$periodoRow] = true;
            $atualizados++;
        }
        $upd->close();
        if (!empty($opcoes['sincronizar_dsr'])) {
            $semanas = [];
            foreach ($calculado['itens'] as $item) {
                $dataDia = (string)$item['row']['data'];
                if (!empty($opcoes['data_inicio']) && !empty($opcoes['data_fim'])) {
                    $inRange = $dataDia >= (string)$opcoes['data_inicio'] && $dataDia <= (string)$opcoes['data_fim'];
                    $pairedInRange = !empty($item['pareado_data']) && (string)$item['pareado_data'] >= (string)$opcoes['data_inicio'] && (string)$item['pareado_data'] <= (string)$opcoes['data_fim'];
                    $parentInRange = !empty($item['pareado_de']) && (string)$item['pareado_de'] >= (string)$opcoes['data_inicio'] && (string)$item['pareado_de'] <= (string)$opcoes['data_fim'];
                    if (!$inRange && !$pairedInRange && !$parentInRange) continue;
                }
                $semana = date('o-W', strtotime($dataDia));
                if (!isset($semanas[$semana])) {
                    $semanas[$semana] = true;
                    _bh_dsr_sincronizar($conn, $colabId, $dataDia, $escala, $tenantId, [
                        'lock_ja_adquirido'=>true, 'transacao_ja_ativa'=>true,
                        'reprocessamento_id'=>$opcoes['reprocessamento_id'] ?? null,
                    ]);
                }
            }
        }
        foreach (array_keys($periodosAfetados) as $periodoAfetado) _recalcular_totais($conn, (int)$periodoAfetado, $tenantId);
        if (!$transacaoExterna) {
            if (!$conn->commit()) throw new RuntimeException('Falha ao confirmar recálculo do período.');
            $transacaoAtiva = false;
        }
        return ['atualizados'=>$atualizados, 'itens'=>$calculado['itens'], 'periodo'=>$calculado['periodo']];
    } catch (Throwable $e) {
        if ($transacaoAtiva) $conn->rollback();
        if ($transacaoExterna) throw $e;
        throw $e;
    } finally {
        if ($lockAdquirido && !$lockExterno) bh_liberar_lock_colaborador($conn, $tenantId, $colabId);
    }
}
}

if (!function_exists('_bh_garantir_tabela')) {
function _bh_garantir_tabela($conn): void { bh_garantir_tabela($conn); }
}

if (!function_exists('_bh_sincronizar')) {
/**
 * Sincroniza o banco de horas para um lançamento calculado.
 * Upsert: remove lançamentos automáticos anteriores do mesmo lancamento_id e reinsere.
 * Considera o abono já concedido (abono_extras/abono_atraso/abono_falta): a
 * fração abonada não gera crédito/débito no banco de horas.
 */
function _bh_sincronizar($conn, int $lancamento_id, int $colab_id, string $data, array $calc, ?array $escala, ?int $tenant_id = null, array $opcoes = []): void {
    if (!$escala || empty($escala['banco_horas_ativo'])) return;
    $tenant_id = $tenant_id ?: (int)($_SESSION['tenant_id'] ?? 0);
    if ($tenant_id <= 0) {
        error_log('[RH Banco de Horas] Sincronização cancelada sem tenant autenticado.');
        return;
    }

    // Para jornadas CLT, o domingo (DSR) é gerenciado exclusivamente por _dsr_sincronizar().
    $tiposComDSR = ['jornada_44h', 'jornada_40h', 'jornada_36h'];
    if (in_array($escala['tipo'] ?? '', $tiposComDSR) && intval(date('w', strtotime($data))) === 0) return;

    $lockAdquirido = false;
    $lockExterno = !empty($opcoes['lock_ja_adquirido']);
    $transacaoExterna = !empty($opcoes['transacao_ja_ativa']);
    $transacaoAtiva = false;
    try {
        _bh_garantir_tabela($conn);
        _abono_garantir_colunas($conn);
        $lockAdquirido = $lockExterno || bh_obter_lock_colaborador($conn, $tenant_id, $colab_id);
        if (!$lockAdquirido) {
            throw new RuntimeException('Lock indisponível durante sincronização automática.');
        }
        if (!$transacaoExterna) {
            if (!$conn->begin_transaction()) throw new RuntimeException('Falha ao iniciar transação da sincronização.');
            $transacaoAtiva = true;
        }
        $stDelete = $conn->prepare("DELETE FROM rh_banco_horas WHERE tenant_id=? AND colaborador_id=? AND lancamento_id=? AND tipo IN ('credito','debito','excedente')");
        if (!$stDelete) throw new RuntimeException('Falha ao preparar remoção de créditos/débitos antigos.');
        $stDelete->bind_param('iii', $tenant_id, $colab_id, $lancamento_id);
        if (!$stDelete->execute()) { $stDelete->close(); throw new RuntimeException('Falha ao remover créditos/débitos antigos.'); }
        $stDelete->close();

        $stRow = $conn->prepare(
            "SELECT tipo_dia, abono_extras, abono_extras_min, abono_falta, abono_atraso, abono_atraso_min
             FROM rh_ponto_lancamento WHERE tenant_id=? AND colaborador_id=? AND id=? LIMIT 1"
        );
        if (!$stRow) throw new RuntimeException('Falha ao preparar consulta do lançamento de ponto.');
        $stRow->bind_param('iii', $tenant_id, $colab_id, $lancamento_id);
        if (!$stRow->execute()) { $stRow->close(); throw new RuntimeException('Falha ao consultar lançamento de ponto.'); }
        $row = $stRow->get_result()->fetch_assoc();
        $stRow->close();
        if (!$row) {
            if (!$transacaoExterna) {
                if (!$conn->commit()) throw new RuntimeException('Falha ao confirmar remoção de lançamento obsoleto.');
                $transacaoAtiva = false;
            }
            return;
        }

    $apuracao = bh_apurar_dia($calc, $row, $escala, $data);
    $linhas = [];
    if ($apuracao['credito'] > 0) $linhas[] = ['credito', $apuracao['credito'], 'Horas extras automáticas', $apuracao, $apuracao['extra_liquido']];
    if ($apuracao['excedente'] > 0) $linhas[] = ['excedente', $apuracao['excedente'], 'Excedente de horas extras — decisão do RH', $apuracao, $apuracao['excedente']];
    if ($apuracao['atraso_liquido'] > 0) $linhas[] = ['debito', $apuracao['atraso_liquido'], 'Atraso', $apuracao, $apuracao['atraso_liquido']];
    if ($apuracao['saida_antecipada'] > 0) $linhas[] = ['debito', $apuracao['saida_antecipada'], 'Saída antecipada', $apuracao, $apuracao['saida_antecipada']];
    if ($apuracao['falta_min'] > 0) $linhas[] = ['debito', $apuracao['falta_min'], 'Falta', $apuracao, $apuracao['falta_min']];

        if (empty($linhas)) {
            if (!$transacaoExterna) {
                if (!$conn->commit()) throw new RuntimeException('Falha ao confirmar remoção de lançamento obsoleto.');
                $transacaoAtiva = false;
            }
            return;
        }

        $st = $conn->prepare(
            "INSERT INTO rh_banco_horas
             (tenant_id, colaborador_id, lancamento_id, data, tipo, minutos, descricao, usuario,
              minutos_apurados, limite_aplicado_min, regra_aplicada, versao_calculo, reprocessamento_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Sistema', ?, ?, ?, '2026.11', ?)"
        );
        if (!$st) throw new RuntimeException('Falha ao preparar inserção automática no Banco de Horas.');
        foreach ($linhas as [$tipo, $min, $desc, $dadosApurados, $minutosApurados]) {
            $minutosApurados = (int)$minutosApurados;
            $limiteAplicado = (int)$dadosApurados['limite_dia'];
            $regraAplicada = (string)$dadosApurados['regra_aplicada'];
            $reprocessamentoId = isset($opcoes['reprocessamento_id']) ? (int)$opcoes['reprocessamento_id'] : null;
            $st->bind_param('iiissisiisi', $tenant_id, $colab_id, $lancamento_id, $data, $tipo, $min, $desc, $minutosApurados, $limiteAplicado, $regraAplicada, $reprocessamentoId);
            if (!$st->execute()) throw new RuntimeException('Falha ao inserir lançamento automático no Banco de Horas.');
        }
        $st->close();
        if (!$transacaoExterna) {
            if (!$conn->commit()) throw new RuntimeException('Falha ao confirmar sincronização automática.');
            $transacaoAtiva = false;
        }
    } catch (Throwable $e) {
        error_log('[RH Banco de Horas] Falha na sincronização automática; tenant=' . $tenant_id . '; lançamento=' . $lancamento_id . '; erro=' . $e->getMessage());
        if ($transacaoAtiva) $conn->rollback();
        throw $e;
    } finally {
        if ($lockAdquirido && !$lockExterno) bh_liberar_lock_colaborador($conn, $tenant_id, $colab_id);
    }
}
}

if (!function_exists('_bh_dsr_sincronizar')) {
/** Sincroniza o DSR da semana, podendo participar de lock/transação externos. */
function _bh_dsr_sincronizar(mysqli $conn, int $colab_id, string $data, ?array $escala, ?int $tenant_id = null, array $opcoes = []): void {
    if (!$escala || empty($escala['banco_horas_ativo'])) return;
    $tenant_id = $tenant_id ?: (int)($_SESSION['tenant_id'] ?? 0);
    if ($tenant_id <= 0) return;
    $tipo = (string)($escala['tipo'] ?? '');
    $mensalPadrao = ['jornada_44h' => 13200, 'jornada_40h' => 12000, 'jornada_36h' => 10800];
    if (!isset($mensalPadrao[$tipo])) return;
    $mensalMin = (int)($escala['carga_horaria_mensal_min'] ?? 0);
    $cargaDsr = $mensalMin > 0 ? (int)round($mensalMin / 30) : (int)round($mensalPadrao[$tipo] / 30);
    $lockExterno = !empty($opcoes['lock_ja_adquirido']);
    $transacaoExterna = !empty($opcoes['transacao_ja_ativa']);
    $lockAdquirido = false;
    $transacaoAtiva = false;
    try {
        $lockAdquirido = $lockExterno || bh_obter_lock_colaborador($conn, $tenant_id, $colab_id);
        if (!$lockAdquirido) throw new RuntimeException('Lock indisponível durante sincronização do DSR.');
        _bh_garantir_tabela($conn);
        $dow = (int)date('w', strtotime($data));
        $segundaTs = strtotime($data) + (($dow === 0 ? -6 : 1 - $dow) * 86400);
        $segunda = date('Y-m-d', $segundaTs);
        $domingo = date('Y-m-d', $segundaTs + 6 * 86400);
        $st = $conn->prepare(
            'SELECT l.id, l.data, l.tipo_dia, l.abono_falta, DAYOFWEEK(l.data) AS mysql_dow, p.status AS periodo_status
             FROM rh_ponto_lancamento l INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id
             WHERE l.tenant_id=? AND l.colaborador_id=? AND l.data BETWEEN ? AND ? ORDER BY l.data ASC'
        );
        if (!$st) throw new RuntimeException('Falha ao preparar consulta da semana DSR.');
        $st->bind_param('iiss', $tenant_id, $colab_id, $segunda, $domingo);
        if (!$st->execute()) { $st->close(); throw new RuntimeException('Falha ao consultar semana DSR.'); }
        $semana = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        $diasUteisMap = [
            'jornada_44h' => ['seg','ter','qua','qui','sex','sab'],
            'jornada_40h' => ['seg','ter','qua','qui','sex'],
            'jornada_36h' => ['seg','ter','qua','qui','sex','sab'],
        ];
        $mapDow = [0=>'dom',1=>'seg',2=>'ter',3=>'qua',4=>'qui',5=>'sex',6=>'sab'];
        $domingoRow = null;
        $hasFalta = false;
        foreach ($semana as $row) {
            $dowPhp = ((int)$row['mysql_dow'] === 1) ? 0 : ((int)$row['mysql_dow'] - 1);
            $diaNome = $mapDow[$dowPhp] ?? '';
            if ($diaNome === 'dom') $domingoRow = $row;
            elseif (in_array($diaNome, $diasUteisMap[$tipo], true) && $row['tipo_dia'] === 'falta' && empty($row['abono_falta'])) $hasFalta = true;
        }
        if (!$domingoRow) return;
        $sunId = (int)$domingoRow['id'];
        $sunData = (string)$domingoRow['data'];
        $tipoAtual = (string)$domingoRow['tipo_dia'];
        if (($domingoRow['periodo_status'] ?? '') !== 'aberto' && empty($opcoes['incluir_fechados'])) return;
        if (in_array($tipoAtual, ['feriado','afastamento'], true)) return;
        $novoTipo = $hasFalta ? 'falta' : 'folga';
        if (!$transacaoExterna) {
            if (!$conn->begin_transaction()) throw new RuntimeException('Falha ao iniciar transação do DSR.');
            $transacaoAtiva = true;
        }
        if ($tipoAtual !== $novoTipo) {
            $upd = $conn->prepare('UPDATE rh_ponto_lancamento SET tipo_dia=?, horas_trabalhadas_min=0, horas_extras_min=0, atraso_min=0, saida_antecipada_min=0 WHERE tenant_id=? AND colaborador_id=? AND id=?');
            if (!$upd) throw new RuntimeException('Falha ao preparar atualização do DSR.');
            $upd->bind_param('siii', $novoTipo, $tenant_id, $colab_id, $sunId);
            if (!$upd->execute()) { $upd->close(); throw new RuntimeException('Falha ao atualizar DSR.'); }
            $upd->close();
        }
        $del = $conn->prepare("DELETE FROM rh_banco_horas WHERE tenant_id=? AND colaborador_id=? AND lancamento_id=? AND tipo IN ('credito','debito','excedente')");
        if (!$del) throw new RuntimeException('Falha ao preparar remoção dos lançamentos DSR anteriores.');
        $del->bind_param('iii', $tenant_id, $colab_id, $sunId);
        if (!$del->execute()) { $del->close(); throw new RuntimeException('Falha ao remover lançamentos DSR anteriores.'); }
        $del->close();
        if ($hasFalta) {
            $apuracao = bh_apurar_dia(['extras'=>0,'atraso'=>0,'saida_antecipada'=>0], [
                'tipo_dia' => $novoTipo, 'abono_falta' => 1, 'dsr_perdido_min' => $cargaDsr,
            ], $escala, $sunData);
            $descricao = 'DSR perdido — falta injustificada na semana';
            $regra = $apuracao['regra_aplicada'];
            $limite = $apuracao['limite_dia'];
            $minutosApurados = $apuracao['dsr_perdido_min'];
            $reprocessamentoId = isset($opcoes['reprocessamento_id']) ? (int)$opcoes['reprocessamento_id'] : null;
            $ins = $conn->prepare(
                "INSERT INTO rh_banco_horas (tenant_id,colaborador_id,lancamento_id,data,tipo,minutos,descricao,usuario,
                 minutos_apurados,limite_aplicado_min,regra_aplicada,versao_calculo,reprocessamento_id)
                 VALUES (?,?,?,?,'debito',? ,?,'Sistema',?,?,?,'2026.11',?)"
            );
            if (!$ins) throw new RuntimeException('Falha ao preparar débito de DSR.');
            $ins->bind_param('iiisisiisi', $tenant_id, $colab_id, $sunId, $sunData, $minutosApurados, $descricao,
                $minutosApurados, $limite, $regra, $reprocessamentoId);
            if (!$ins->execute()) { $ins->close(); throw new RuntimeException('Falha ao inserir débito de DSR.'); }
            $ins->close();
        }
        if (!$transacaoExterna) {
            if (!$conn->commit()) throw new RuntimeException('Falha ao confirmar sincronização do DSR.');
            $transacaoAtiva = false;
        }
    } catch (Throwable $e) {
        error_log('[RH Banco de Horas] Falha na sincronização de DSR; tenant=' . $tenant_id . '; colaborador=' . $colab_id . '; erro=' . $e->getMessage());
        if ($transacaoAtiva) $conn->rollback();
        throw $e;
    } finally {
        if ($lockAdquirido && !$lockExterno) bh_liberar_lock_colaborador($conn, $tenant_id, $colab_id);
    }
}
}
