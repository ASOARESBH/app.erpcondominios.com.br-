<?php
// =====================================================
// RH — Núcleo compartilhado de cálculo de jornada/ponto
// =====================================================
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
function _buscar_escala($conn, int $colab_id): ?array {
    $stmt = $conn->prepare("SELECT * FROM rh_escala WHERE colaborador_id=? AND ativo=1 ORDER BY id ASC LIMIT 1");
    $stmt->bind_param('i', $colab_id); $stmt->execute();
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
 * Auto-migração aditiva: coluna de saída antecipada por lançamento, o
 * respectivo agregado no período, e a carga prevista agregada do período.
 * Idempotente (checa SHOW COLUMNS antes).
 */
function _ponto_garantir_colunas($conn): void {
    $res = $conn->query("SHOW COLUMNS FROM rh_ponto_lancamento");
    $cols = [];
    if ($res) while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
    if (!in_array('saida_antecipada_min', $cols)) {
        $conn->query("ALTER TABLE rh_ponto_lancamento ADD COLUMN saida_antecipada_min INT NOT NULL DEFAULT 0");
    }

    $res2 = $conn->query("SHOW COLUMNS FROM rh_ponto_periodo");
    $cols2 = [];
    if ($res2) while ($r = $res2->fetch_assoc()) $cols2[] = $r['Field'];
    if (!in_array('total_saida_antecipada_min', $cols2)) {
        $conn->query("ALTER TABLE rh_ponto_periodo ADD COLUMN total_saida_antecipada_min INT DEFAULT 0");
    }
    if (!in_array('total_carga_prevista_min', $cols2)) {
        $conn->query("ALTER TABLE rh_ponto_periodo ADD COLUMN total_carga_prevista_min INT DEFAULT 0");
    }
}
}

if (!function_exists('_abono_garantir_colunas')) {
/**
 * Auto-migração aditiva das colunas de abono em rh_ponto_lancamento.
 * Movida de api_rh_abono.php para aqui: agora é o próprio motor de cálculo
 * (_recalcular_totais/_bh_sincronizar) quem lê essas colunas, então precisa
 * garantir sua existência independentemente de qual API rodou primeiro.
 */
function _abono_garantir_colunas($conn): void {
    $res = $conn->query("SHOW COLUMNS FROM rh_ponto_lancamento");
    $cols = [];
    if ($res) { while ($r = $res->fetch_assoc()) $cols[] = $r['Field']; }

    $add = [];
    if (!in_array('abono_extras',        $cols)) $add[] = "ADD COLUMN abono_extras ENUM('nenhum','total','parcial') NOT NULL DEFAULT 'nenhum'";
    if (!in_array('abono_extras_min',    $cols)) $add[] = "ADD COLUMN abono_extras_min INT NOT NULL DEFAULT 0";
    if (!in_array('abono_falta',         $cols)) $add[] = "ADD COLUMN abono_falta TINYINT(1) NOT NULL DEFAULT 0";
    if (!in_array('abono_atraso',        $cols)) $add[] = "ADD COLUMN abono_atraso ENUM('nenhum','total','parcial') NOT NULL DEFAULT 'nenhum'";
    if (!in_array('abono_atraso_min',    $cols)) $add[] = "ADD COLUMN abono_atraso_min INT NOT NULL DEFAULT 0";
    if (!in_array('abono_justificativa', $cols)) $add[] = "ADD COLUMN abono_justificativa TEXT NULL";
    if (!in_array('abono_usuario',       $cols)) $add[] = "ADD COLUMN abono_usuario VARCHAR(100) NULL";
    if (!in_array('abono_data',          $cols)) $add[] = "ADD COLUMN abono_data DATETIME NULL";

    if ($add) {
        $conn->query("ALTER TABLE rh_ponto_lancamento " . implode(', ', $add));
    }
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
function _recalcular_totais($conn, int $periodo_id): void {
    _abono_garantir_colunas($conn);

    $rowP   = $conn->query("SELECT colaborador_id FROM rh_ponto_periodo WHERE id=$periodo_id")->fetch_assoc();
    $escala = $rowP ? _buscar_escala($conn, intval($rowP['colaborador_id'])) : null;

    $res = $conn->query(
        "SELECT data, tipo_dia, horas_trabalhadas_min, horas_extras_min, atraso_min, saida_antecipada_min,
                abono_extras, abono_extras_min, abono_falta, abono_atraso, abono_atraso_min
         FROM rh_ponto_lancamento WHERE periodo_id = $periodo_id"
    );

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
         WHERE id=?"
    );
    $v = [$trab, $ext, $atr, $sai, $falt, $folg, $prevista, $periodo_id];
    $stmt->bind_param('iiiiiiii', ...$v);
    $stmt->execute(); $stmt->close();
}
}

if (!function_exists('_bh_garantir_tabela')) {
function _bh_garantir_tabela($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS rh_banco_horas (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        colaborador_id  INT NOT NULL,
        lancamento_id   INT NULL,
        data            DATE NOT NULL,
        tipo            ENUM('credito','debito','abatimento','pagamento') NOT NULL,
        minutos         INT NOT NULL DEFAULT 0,
        descricao       VARCHAR(255) NOT NULL DEFAULT '',
        usuario         VARCHAR(100) NULL,
        criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_colab_data (colaborador_id, data),
        INDEX idx_lancamento (lancamento_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
}

if (!function_exists('_bh_sincronizar')) {
/**
 * Sincroniza o banco de horas para um lançamento calculado.
 * Upsert: remove lançamentos automáticos anteriores do mesmo lancamento_id e reinsere.
 * Considera o abono já concedido (abono_extras/abono_atraso/abono_falta): a
 * fração abonada não gera crédito/débito no banco de horas.
 */
function _bh_sincronizar($conn, int $lancamento_id, int $colab_id, string $data, array $calc, ?array $escala): void {
    if (!$escala || empty($escala['banco_horas_ativo'])) return;

    // Para jornadas CLT, o domingo (DSR) é gerenciado exclusivamente por _dsr_sincronizar().
    $tiposComDSR = ['jornada_44h', 'jornada_40h', 'jornada_36h'];
    if (in_array($escala['tipo'] ?? '', $tiposComDSR) && intval(date('w', strtotime($data))) === 0) return;

    _bh_garantir_tabela($conn);
    _abono_garantir_colunas($conn);
    $conn->query("DELETE FROM rh_banco_horas WHERE lancamento_id = $lancamento_id AND tipo IN ('credito','debito')");

    $row = $conn->query(
        "SELECT tipo_dia, abono_extras, abono_extras_min, abono_falta, abono_atraso, abono_atraso_min
         FROM rh_ponto_lancamento WHERE id = $lancamento_id"
    );
    $row = $row ? $row->fetch_assoc() : null;
    if (!$row) return;

    $linhas = [];

    // Crédito: horas extras líquidas (após abono) — CLT Art. 59 §2°: máximo 2h/dia (120 min)
    $extraBruto   = (int)$calc['extras'];
    $extraAbonado = $row['abono_extras'] === 'total' ? $extraBruto
        : ($row['abono_extras'] === 'parcial' ? min((int)$row['abono_extras_min'], $extraBruto) : 0);
    $extraLiquido = max(0, $extraBruto - $extraAbonado);
    if ($extraLiquido > 0) {
        $linhas[] = ['credito', min($extraLiquido, 120), 'Horas extras automáticas'];
    }

    // Débito: atraso líquido (após abono)
    $atrasoBruto   = (int)$calc['atraso'];
    $atrasoAbonado = $row['abono_atraso'] === 'total' ? $atrasoBruto
        : ($row['abono_atraso'] === 'parcial' ? min((int)$row['abono_atraso_min'], $atrasoBruto) : 0);
    $atrasoLiquido = max(0, $atrasoBruto - $atrasoAbonado);
    if ($atrasoLiquido > 0) {
        $linhas[] = ['debito', $atrasoLiquido, 'Atraso'];
    }

    // Débito: saída antecipada (abono de saída antecipada não existe hoje no cadastro de abono)
    if (($calc['saida_antecipada'] ?? 0) > 0) {
        $linhas[] = ['debito', $calc['saida_antecipada'], 'Saída antecipada'];
    }

    // Débito: falta (carga diária completa do dia) — neutralizado se abono_falta=1
    if ($row['tipo_dia'] === 'falta' && empty($row['abono_falta'])) {
        $params      = _obter_parametros_jornada_dia($escala, $data);
        $cargaDiaria = $params['tem_controle'] ? $params['carga_min'] : intval($escala['carga_horaria_diaria_min'] ?? 480);
        $linhas[] = ['debito', $cargaDiaria, 'Falta'];
    }

    if (empty($linhas)) return;

    $st = $conn->prepare(
        "INSERT INTO rh_banco_horas (colaborador_id, lancamento_id, data, tipo, minutos, descricao, usuario)
         VALUES (?, ?, ?, ?, ?, ?, 'Sistema')"
    );
    foreach ($linhas as [$tipo, $min, $desc]) {
        $st->bind_param('iissis', $colab_id, $lancamento_id, $data, $tipo, $min, $desc);
        $st->execute();
    }
    $st->close();
}
}
