<?php
// =====================================================
// API: RH — RELATÓRIO PDF
// =====================================================
// GET ?tipo=totais_horas|espelho_ponto|faltas|horas_extras|atrasos|banco_horas|aniversariantes
//     &mes=N&ano=N  OU  &data_inicio=YYYY-MM-DD&data_fim=YYYY-MM-DD
//     [&departamento=X]  [&colaborador_id=N]

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
ob_end_clean();

try { verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId(); }
catch (Exception $e) {
    http_response_code(401);
    echo '<h2>Não autenticado. Faça login novamente.</h2>'; exit;
}

$conn = conectar_banco();
if (!$conn) { echo '<h2>Erro ao conectar ao banco.</h2>'; exit; }

// Garante colunas de abono na tabela de ponto (migração automática)
(function() use ($conn) {
    $res = $conn->query("SHOW COLUMNS FROM rh_ponto_lancamento");
    if (!$res) return;
    $cols = [];
    while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
    $add = [];
    if (!in_array('abono_extras',        $cols)) $add[] = "ADD COLUMN abono_extras ENUM('nenhum','total','parcial') NOT NULL DEFAULT 'nenhum'";
    if (!in_array('abono_extras_min',    $cols)) $add[] = "ADD COLUMN abono_extras_min INT NOT NULL DEFAULT 0";
    if (!in_array('abono_falta',         $cols)) $add[] = "ADD COLUMN abono_falta TINYINT(1) NOT NULL DEFAULT 0";
    if (!in_array('abono_atraso',        $cols)) $add[] = "ADD COLUMN abono_atraso ENUM('nenhum','total','parcial') NOT NULL DEFAULT 'nenhum'";
    if (!in_array('abono_atraso_min',    $cols)) $add[] = "ADD COLUMN abono_atraso_min INT NOT NULL DEFAULT 0";
    if (!in_array('abono_justificativa', $cols)) $add[] = "ADD COLUMN abono_justificativa TEXT NULL";
    if (!in_array('abono_usuario',       $cols)) $add[] = "ADD COLUMN abono_usuario VARCHAR(100) NULL";
    if (!in_array('abono_data',          $cols)) $add[] = "ADD COLUMN abono_data DATETIME NULL";
    if ($add) $conn->query("ALTER TABLE rh_ponto_lancamento " . implode(', ', $add));
})();

// ── Parâmetros ───────────────────────────────────────────────────────────────
$tipo      = trim($_GET['tipo']            ?? '');
$dept      = trim($_GET['departamento']    ?? '');
$colab_id  = intval($_GET['colaborador_id'] ?? 0);

$data_inicio_raw = trim($_GET['data_inicio'] ?? '');
$data_fim_raw    = trim($_GET['data_fim']    ?? '');
$mes_raw         = intval($_GET['mes']       ?? 0);
$ano_raw         = intval($_GET['ano']       ?? 0);

// Resolve período
if ($data_inicio_raw && $data_fim_raw) {
    $data_inicio = $data_inicio_raw;
    $data_fim    = $data_fim_raw;
    $label_periodo = date('d/m/Y', strtotime($data_inicio)) . ' a ' . date('d/m/Y', strtotime($data_fim));
    $tipo_periodo = 'personalizado';
} elseif ($mes_raw >= 1 && $ano_raw >= 2000) {
    $data_inicio = sprintf('%04d-%02d-01', $ano_raw, $mes_raw);
    $data_fim    = date('Y-m-t', strtotime($data_inicio));
    $meses_nome  = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    $label_periodo = "{$meses_nome[$mes_raw]}/{$ano_raw}";
    $tipo_periodo = 'mes';
} else {
    echo '<h2>Parâmetros de período inválidos.</h2>'; exit;
}

// ── Busca dados conforme tipo ─────────────────────────────────────────────────
$dados    = [];
$titulo   = '';
$subtitulo = '';
$colunas  = [];

function _min_h(?int $m): string {
    if (!$m || $m <= 0) return '00:00';
    return sprintf('%02d:%02d', intdiv($m,60), $m%60);
}

switch ($tipo) {
    // ── Totais de horas ──────────────────────────────────────────────────────
    case 'totais_horas':
        $titulo   = 'Totais de Horas';
        $subtitulo = $label_periodo . ($dept ? " · $dept" : '');
        $colunas  = ['Nome','Cargo','Departamento','Contrato','Trabalhado','Extra','Atraso','Faltas','Folgas','Status'];

        if ($tipo_periodo === 'personalizado') {
            $sql = "SELECT c.nome, c.cargo, c.departamento, c.tipo_contrato,
                           COALESCE(SUM(l.horas_trabalhadas_min),0) as trab,
                           COALESCE(SUM(l.horas_extras_min),0)       as extra,
                           COALESCE(SUM(l.atraso_min),0)             as atraso,
                           COALESCE(SUM(l.tipo_dia='falta'),0)       as faltas,
                           COALESCE(SUM(l.tipo_dia='folga'),0)       as folgas,
                           'personalizado' as status
                    FROM rh_colaboradores c
                    LEFT JOIN rh_ponto_lancamento l ON l.colaborador_id = c.id AND l.data BETWEEN ? AND ?
                    WHERE c.ativo=1";
            $params = [$data_inicio, $data_fim]; $types = 'ss';
        } else {
            $sql = "SELECT c.nome, c.cargo, c.departamento, c.tipo_contrato,
                           COALESCE(p.total_horas_trabalhadas_min,0) as trab,
                           COALESCE(p.total_horas_extras_min,0)       as extra,
                           COALESCE(p.total_atraso_min,0)             as atraso,
                           COALESCE(p.total_faltas,0)                 as faltas,
                           COALESCE(p.total_folgas,0)                 as folgas,
                           COALESCE(p.status,'—')                     as status
                    FROM rh_colaboradores c
                    LEFT JOIN rh_ponto_periodo p ON p.colaborador_id=c.id AND p.mes=? AND p.ano=?
                    WHERE c.ativo=1";
            $params = [$mes_raw, $ano_raw]; $types = 'ii';
        }
        if ($dept) { $sql .= ' AND c.departamento=?'; $params[] = $dept; $types .= 's'; }
        $sql .= ($tipo_periodo === 'personalizado' ? ' GROUP BY c.id' : '') . ' ORDER BY c.departamento,c.nome';
        $th_total_trab  = 0;
        $th_total_extra = 0;
        $th_total_atr   = 0;
        $th_total_falt  = 0;
        $th_total_folg  = 0;
        $st = $conn->prepare($sql); $st->bind_param($types, ...$params); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $th_total_trab  += (int)$r['trab'];
            $th_total_extra += (int)$r['extra'];
            $th_total_atr   += (int)$r['atraso'];
            $th_total_falt  += (int)$r['faltas'];
            $th_total_folg  += (int)$r['folgas'];
            $dados[] = [
                $r['nome'], $r['cargo']??'—', $r['departamento']??'—', strtoupper($r['tipo_contrato']??'—'),
                _min_h((int)$r['trab']), _min_h((int)$r['extra']), _min_h((int)$r['atraso']),
                $r['faltas'], $r['folgas'],
                '<span class="badge '.($r['status']==='fechado'?'badge-red':($r['status']==='aberto'?'badge-green':'badge-gray')).'">'.$r['status'].'</span>'
            ];
        }
        $st->close();
        break;

    // ── Espelho de ponto ─────────────────────────────────────────────────────
    case 'espelho_ponto':
        if ($colab_id <= 0) { echo '<h2>colaborador_id obrigatório.</h2>'; exit; }
        $titulo   = 'Espelho de Ponto';
        $colunas  = ['Data','Dia','Tipo','Entrada','Saída Alm.','Ret. Alm.','Saída','Trabalhado','Extra','Atraso','Obs','Abono'];

        $dias_pt = ['Monday'=>'Segunda','Tuesday'=>'Terça','Wednesday'=>'Quarta',
                    'Thursday'=>'Quinta','Friday'=>'Sexta','Saturday'=>'Sábado','Sunday'=>'Domingo'];
        $tipos_pt = ['normal'=>'Normal','folga'=>'Folga','falta'=>'Falta','feriado'=>'Feriado',
                     'meio_periodo'=>'Meio Per.','afastamento'=>'Afastamento','horas_extras'=>'Hrs. Extras'];

        $sc = $conn->prepare("SELECT nome,cargo,departamento,tipo_contrato FROM rh_colaboradores WHERE id=?");
        $sc->bind_param('i', $colab_id); $sc->execute();
        $colab = $sc->get_result()->fetch_assoc(); $sc->close();
        $subtitulo = ($colab['nome']??'') . ' · ' . ($colab['cargo']??'') . ' · ' . $label_periodo;

        // Totalizadores para o espelho
        $esp_total_trab  = 0;
        $esp_total_extra = 0;
        $esp_total_atr   = 0;
        $esp_total_falt  = 0;
        $esp_total_folg  = 0;

        $st = $conn->prepare(
            "SELECT DATE_FORMAT(data,'%d/%m/%Y') as d, DAYNAME(data) as dn,
                    TIME_FORMAT(hora_entrada,'%H:%i') as he,
                    TIME_FORMAT(hora_almoco_saida,'%H:%i') as has,
                    TIME_FORMAT(hora_almoco_retorno,'%H:%i') as har,
                    TIME_FORMAT(hora_saida,'%H:%i') as hs,
                    horas_trabalhadas_min, horas_extras_min, atraso_min, tipo_dia, observacoes,
                    abono_extras, abono_extras_min, abono_falta,
                    abono_atraso, abono_atraso_min, abono_justificativa, abono_usuario
             FROM rh_ponto_lancamento WHERE colaborador_id=? AND data BETWEEN ? AND ? ORDER BY data"
        );
        $st->bind_param('iss', $colab_id, $data_inicio, $data_fim); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $trab  = (int)$r['horas_trabalhadas_min'];
            $extra = (int)$r['horas_extras_min'];
            $atr   = (int)$r['atraso_min'];
            $esp_total_trab  += $trab;
            $esp_total_extra += $extra;
            $esp_total_atr   += $atr;
            if ($r['tipo_dia'] === 'falta')  $esp_total_falt++;
            if ($r['tipo_dia'] === 'folga')  $esp_total_folg++;

            $nomeDia  = $dias_pt[$r['dn']] ?? ($r['dn'] ?? '—');
            $tipoLabel = $tipos_pt[$r['tipo_dia']] ?? ($r['tipo_dia'] ?? 'Normal');

            $rowCls = '';
            if ($r['tipo_dia'] === 'folga')  $rowCls = 'style="background:#eff6ff;"';
            if ($r['tipo_dia'] === 'falta')  $rowCls = 'style="background:#fff1f2;"';
            if ($r['tipo_dia'] === 'feriado') $rowCls = 'style="background:#fef9c3;"';

            // Monta célula de abono
            $abono_partes = [];
            if (($r['abono_extras'] ?? 'nenhum') !== 'nenhum') {
                $txt = $r['abono_extras'] === 'total'
                    ? 'Extra: Total ('._min_h($extra).')'
                    : 'Extra: Parcial ('._min_h((int)($r['abono_extras_min']??0)).')';
                $abono_partes[] = '<span style="color:#16a34a;font-weight:600;">'.$txt.'</span>';
            }
            if (($r['abono_atraso'] ?? 'nenhum') !== 'nenhum') {
                $txt = $r['abono_atraso'] === 'total'
                    ? 'Atraso: Total ('._min_h($atr).')'
                    : 'Atraso: Parcial ('._min_h((int)($r['abono_atraso_min']??0)).')';
                $abono_partes[] = '<span style="color:#2563eb;font-weight:600;">'.$txt.'</span>';
            }
            if (!empty($r['abono_falta'])) {
                $abono_partes[] = '<span style="color:#7c3aed;font-weight:600;">Falta abonada</span>';
            }
            if (!empty($r['abono_justificativa'])) {
                $abono_partes[] = '<em style="color:#64748b;font-size:10px;">'.htmlspecialchars($r['abono_justificativa']).'</em>';
            }
            $abono_cell = $abono_partes ? implode('<br>', $abono_partes) : '—';

            $dados[] = [
                '__rowcls__' => $rowCls,
                $r['d'],
                $nomeDia,
                $tipoLabel,
                $r['he']  ?: '—',
                $r['has'] ?: '—',
                $r['har'] ?: '—',
                $r['hs']  ?: '—',
                $trab  > 0 ? _min_h($trab)  : '—',
                $extra > 0 ? '<span style="color:#16a34a;font-weight:600;">'._min_h($extra).'</span>' : '—',
                $atr   > 0 ? '<span style="color:#dc2626;font-weight:600;">'._min_h($atr).'</span>'   : '—',
                htmlspecialchars($r['observacoes']??''),
                $abono_cell,
            ];
        }
        $st->close();
        break;

    // ── Faltas e Afastamentos ────────────────────────────────────────────────
    case 'faltas':
        $titulo   = 'Faltas e Afastamentos';
        $subtitulo = $label_periodo . ($dept ? " · $dept" : '');
        $colunas  = ['Nome','Cargo','Departamento','Data','Tipo','Observação'];
        $sql = "SELECT c.nome, c.cargo, c.departamento,
                       DATE_FORMAT(l.data,'%d/%m/%Y') as d, l.tipo_dia, l.observacoes
                FROM rh_ponto_lancamento l
                JOIN rh_colaboradores c ON c.id=l.colaborador_id
                WHERE l.data BETWEEN ? AND ? AND l.tipo_dia IN ('falta','afastamento') AND c.ativo=1";
        $params = [$data_inicio, $data_fim]; $types = 'ss';
        if ($dept) { $sql .= ' AND c.departamento=?'; $params[] = $dept; $types .= 's'; }
        $sql .= ' ORDER BY c.nome,l.data';
        $st = $conn->prepare($sql); $st->bind_param($types, ...$params); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc())
            $dados[] = [$r['nome'],$r['cargo']??'—',$r['departamento']??'—',$r['d'],$r['tipo_dia'],htmlspecialchars($r['observacoes']??'')];
        $st->close();
        break;

    // ── Horas Extras ─────────────────────────────────────────────────────────
    case 'horas_extras':
        $titulo   = 'Horas Extras';
        $subtitulo = $label_periodo . ($dept ? " · $dept" : '');
        $colunas  = ['Nome','Cargo','Departamento','Horas Extras','Horas Trabalhadas'];
        $sql = "SELECT c.nome, c.cargo, c.departamento,
                       SUM(l.horas_extras_min) as extra, SUM(l.horas_trabalhadas_min) as trab
                FROM rh_ponto_lancamento l
                JOIN rh_colaboradores c ON c.id=l.colaborador_id
                WHERE l.data BETWEEN ? AND ? AND l.horas_extras_min>0 AND c.ativo=1";
        $params = [$data_inicio, $data_fim]; $types = 'ss';
        if ($dept) { $sql .= ' AND c.departamento=?'; $params[] = $dept; $types .= 's'; }
        $sql .= ' GROUP BY c.id ORDER BY extra DESC';
        $he_total_extra = 0;
        $he_total_trab  = 0;
        $st = $conn->prepare($sql); $st->bind_param($types, ...$params); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $he_total_extra += (int)$r['extra'];
            $he_total_trab  += (int)$r['trab'];
            $dados[] = [$r['nome'],$r['cargo']??'—',$r['departamento']??'—',
                '<span style="color:#16a34a;font-weight:600;">'._min_h((int)$r['extra']).'</span>',
                _min_h((int)$r['trab'])];
        }
        $st->close();
        break;

    // ── Atrasos ───────────────────────────────────────────────────────────────
    case 'atrasos':
        $titulo   = 'Atrasos';
        $subtitulo = $label_periodo . ($dept ? " · $dept" : '');
        $colunas  = ['Nome','Cargo','Departamento','Data','Hora Entrada','Atraso'];
        $sql = "SELECT c.nome, c.cargo, c.departamento,
                       DATE_FORMAT(l.data,'%d/%m/%Y') as d,
                       TIME_FORMAT(l.hora_entrada,'%H:%i') as he, l.atraso_min
                FROM rh_ponto_lancamento l
                JOIN rh_colaboradores c ON c.id=l.colaborador_id
                WHERE l.data BETWEEN ? AND ? AND l.atraso_min>0 AND c.ativo=1";
        $params = [$data_inicio, $data_fim]; $types = 'ss';
        if ($dept) { $sql .= ' AND c.departamento=?'; $params[] = $dept; $types .= 's'; }
        $sql .= ' ORDER BY c.nome,l.data';
        $st = $conn->prepare($sql); $st->bind_param($types, ...$params); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc())
            $dados[] = [$r['nome'],$r['cargo']??'—',$r['departamento']??'—',$r['d'],$r['he']??'—',
                '<span style="color:#dc2626;font-weight:600;">'._min_h((int)$r['atraso_min']).'</span>'];
        $st->close();
        break;

    // ── Banco de Horas ────────────────────────────────────────────────────────
    // Espelha exatamente a lógica de api_rh_relatorios.php (acao=banco_horas):
    // usa o extrato ledger (rh_banco_horas) quando existir — mesma fonte de
    // dados que a tela mostra — e só cai no agregado legado (rh_ponto_periodo)
    // como fallback, para quando a tabela ledger ainda não foi criada.
    case 'banco_horas':
        if ($colab_id <= 0) { echo '<h2>colaborador_id obrigatório.</h2>'; exit; }
        $titulo   = 'Banco de Horas';
        $sc = $conn->prepare("SELECT nome,cargo,departamento FROM rh_colaboradores WHERE id=?");
        $sc->bind_param('i', $colab_id); $sc->execute();
        $colab = $sc->get_result()->fetch_assoc(); $sc->close();
        $subtitulo = ($colab['nome']??'') . ' · ' . $label_periodo;

        $bh_tabela_existe = $conn->query("SHOW TABLES LIKE 'rh_banco_horas'")->num_rows > 0;

        if ($bh_tabela_existe) {
            $colunas = ['Data','Tipo','Minutos','Descrição','Saldo Corrente','Usuário'];
            $typeLabelPdf = ['credito'=>'Crédito Extra','debito'=>'Débito','abatimento'=>'Abatimento','pagamento'=>'Pagamento'];
            $typeColorPdf = ['credito'=>'#16a34a','debito'=>'#dc2626','abatimento'=>'#7c3aed','pagamento'=>'#0369a1'];

            $st = $conn->prepare(
                "SELECT DATE_FORMAT(data,'%d/%m/%Y') as data_fmt, tipo, minutos, descricao, usuario
                 FROM rh_banco_horas WHERE colaborador_id=? ORDER BY data ASC, id ASC"
            );
            $st->bind_param('i', $colab_id);
            $st->execute();
            $res = $st->get_result();
            $bh_saldo = 0; $bh_credito = 0; $bh_debito = 0;
            while ($r = $res->fetch_assoc()) {
                $min = (int)$r['minutos'];
                if ($r['tipo'] === 'credito') { $bh_saldo += $min; $bh_credito += $min; $sinal = '+'; }
                else                          { $bh_saldo -= $min; $bh_debito  += $min; $sinal = '-'; }
                $cor = $typeColorPdf[$r['tipo']] ?? '#334155';
                $dados[] = [
                    $r['data_fmt'],
                    '<span style="color:'.$cor.';font-weight:600;">'.($typeLabelPdf[$r['tipo']] ?? $r['tipo']).'</span>',
                    '<span style="color:'.$cor.';">'.$sinal._min_h($min).'</span>',
                    htmlspecialchars($r['descricao'] ?? ''),
                    '<strong style="color:'.($bh_saldo>=0?'#16a34a':'#dc2626').';">'.($bh_saldo<0?'-':'')._min_h(abs($bh_saldo)).'</strong>',
                    htmlspecialchars($r['usuario'] ?? 'Sistema'),
                ];
            }
            $st->close();
            $bh_saldo_total = $bh_credito - $bh_debito;
        } else {
            // Fallback: agrega por mês a partir de rh_ponto_periodo (comportamento legado)
            $colunas    = ['Mês/Ano','Trabalhado','Extra','Atraso','Saldo Mês','Acumulado'];
            $meses_nome = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

            $st = $conn->prepare(
                "SELECT mes, ano, total_horas_trabalhadas_min, total_horas_extras_min, total_atraso_min
                 FROM rh_ponto_periodo WHERE colaborador_id=? AND
                 (ano > ? OR (ano=? AND mes>=?)) AND (ano < ? OR (ano=? AND mes<=?))
                 ORDER BY ano,mes"
            );
            $ano_i = (int)substr($data_inicio,0,4); $mes_i = (int)substr($data_inicio,5,2);
            $ano_f = (int)substr($data_fim,0,4);    $mes_f = (int)substr($data_fim,5,2);
            $st->bind_param('iiiiiii', $colab_id, $ano_i, $ano_i, $mes_i, $ano_f, $ano_f, $mes_f);
            $st->execute();
            $res = $st->get_result(); $total = 0;
            while ($r = $res->fetch_assoc()) {
                $saldo = (int)$r['total_horas_extras_min'] - (int)$r['total_atraso_min'];
                $total += $saldo;
                $dados[] = [
                    $meses_nome[$r['mes']].'/'.$r['ano'],
                    _min_h((int)$r['total_horas_trabalhadas_min']),
                    '<span style="color:#16a34a;">'._min_h((int)$r['total_horas_extras_min']).'</span>',
                    '<span style="color:#dc2626;">'._min_h((int)$r['total_atraso_min']).'</span>',
                    '<span style="color:'.($saldo>=0?'#16a34a':'#dc2626').';font-weight:600;">'.($saldo<0?'-':'')._min_h(abs($saldo)).'</span>',
                    '<strong style="color:'.($total>=0?'#1e3a8a':'#dc2626').';">'.($total<0?'-':'')._min_h(abs($total)).'</strong>'
                ];
            }
            $st->close();
            $bh_saldo_total = $total;
        }
        break;

    // ── Aniversariantes ───────────────────────────────────────────────────────
    case 'aniversariantes':
        $mes_aniv = $mes_raw >= 1 ? $mes_raw : (int)date('m');
        $meses_nome = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
        $titulo   = 'Aniversariantes';
        $subtitulo = $meses_nome[$mes_aniv] . '/' . ($ano_raw >= 2000 ? $ano_raw : date('Y'));
        $colunas  = ['Nome','Cargo','Departamento','Data Nasc.','Aniversário'];
        $st = $conn->prepare(
            "SELECT nome,cargo,departamento,DATE_FORMAT(data_nascimento,'%d/%m/%Y') as dn,
                    DATE_FORMAT(data_nascimento,'%d/%m') as aniv
             FROM rh_colaboradores WHERE MONTH(data_nascimento)=? AND ativo=1 ORDER BY DAY(data_nascimento),nome"
        );
        $st->bind_param('i', $mes_aniv); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc())
            $dados[] = [$r['nome'],$r['cargo']??'—',$r['departamento']??'—',$r['dn'],'🎂 '.$r['aniv']];
        $st->close();
        break;

    default:
        echo '<h2>Tipo de relatório inválido.</h2>'; exit;
}

// Busca dados completos da empresa para cabeçalho/rodapé/assinatura
$empresa_info = ['razao_social'=>'','nome_fantasia'=>'','cnpj'=>'','telefone'=>'','email_principal'=>'','endereco_rua'=>'','endereco_cidade'=>'','endereco_estado'=>''];
$res_emp = $conn->query("SELECT razao_social, nome_fantasia, cnpj, telefone, email_principal, endereco_rua, endereco_cidade, endereco_estado FROM empresa LIMIT 1");
if ($res_emp && $row_emp = $res_emp->fetch_assoc()) {
    foreach ($row_emp as $k => $v) {
        if ($v !== null) $empresa_info[$k] = $v;
    }
}

fechar_conexao($conn);

// ── Logo da associação ────────────────────────────────────────────────────────
$logo_path = __DIR__ . '/../frontend/assets/img/logo.png';
$logo_b64  = '';
if (file_exists($logo_path)) {
    $logo_b64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

$total_registros = count($dados);
$data_geracao    = date('d/m/Y H:i');
$usuario_nome    = $_SESSION['usuario_nome'] ?? 'Sistema';
$nome_empresa    = $empresa_info['razao_social'] ?: ($empresa_info['nome_fantasia'] ?: 'ERP Condomínio');
$cnpj_empresa    = $empresa_info['cnpj'] ?: '';
$cidade_empresa  = $empresa_info['endereco_cidade'] ?: '';
$estado_empresa  = $empresa_info['endereco_estado'] ?: '';

// Para o texto de declaração: mês por extenso e ano do período
$meses_decl = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$mes_decl   = $mes_raw >= 1 ? ($meses_decl[$mes_raw] ?? '') : '';
$ano_decl   = $ano_raw >= 2000 ? $ano_raw : (int)date('Y');
// Para período personalizado tenta extrair mês/ano do início
if (!$mes_decl && $data_inicio_raw) {
    $mes_decl = $meses_decl[(int)date('m', strtotime($data_inicio_raw))] ?? '';
    $ano_decl = (int)date('Y', strtotime($data_inicio_raw));
}
$periodo_decl = $mes_decl ? "mês de {$mes_decl} de {$ano_decl}" : "período de {$label_periodo}";

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titulo) ?> — <?= htmlspecialchars($subtitulo) ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1e293b; background: #f8fafc; }

  /* ── Cabeçalho ── */
  .header { background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #fff; padding: 24px 32px; display: flex; align-items: center; gap: 20px; }
  .header img { height: 60px; width: auto; border-radius: 8px; background: #fff; padding: 4px; }
  .header-info h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
  .header-info p  { font-size: 13px; opacity: .85; }
  .header-meta { margin-left: auto; text-align: right; font-size: 11px; opacity: .8; line-height: 1.6; }

  /* ── KPIs ── */
  .kpis { display: flex; gap: 16px; padding: 20px 32px; background: #fff; border-bottom: 1px solid #e2e8f0; }
  .kpi  { flex: 1; background: #f0f4ff; border-radius: 10px; padding: 14px 18px; border-left: 4px solid #2563eb; }
  .kpi-label { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #64748b; margin-bottom: 4px; }
  .kpi-value { font-size: 22px; font-weight: 700; color: #1e3a8a; }

  /* ── Tabela ── */
  .content { padding: 24px 32px; }
  .section-title { font-size: 15px; font-weight: 700; color: #1e3a8a; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid #2563eb; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
  thead tr { background: linear-gradient(90deg, #1e3a8a, #2563eb); color: #fff; }
  thead th { padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; font-weight: 600; white-space: nowrap; }
  tbody tr:nth-child(even) { background: #f8fafc; }
  tbody tr:hover { background: #eff6ff; }
  tbody td { padding: 9px 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
  .empty { text-align: center; padding: 32px; color: #94a3b8; font-style: italic; }

  /* ── Badges ── */
  .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
  .badge-green { background: #dcfce7; color: #16a34a; }
  .badge-red   { background: #fee2e2; color: #dc2626; }
  .badge-gray  { background: #f1f5f9; color: #64748b; }

  /* ── Rodapé ── */
  .footer { background: #1e3a8a; color: rgba(255,255,255,.7); text-align: center; padding: 14px 32px; font-size: 11px; margin-top: 24px; }

  /* ── Botão Imprimir ── */
  .print-bar { background: #fff; padding: 14px 32px; border-bottom: 1px solid #e2e8f0; display: flex; gap: 10px; align-items: center; }
  .btn-print { background: #2563eb; color: #fff; border: none; padding: 9px 22px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; }
  .btn-print:hover { background: #1d4ed8; }
  .btn-close { background: #f1f5f9; color: #475569; border: none; padding: 9px 18px; border-radius: 8px; font-size: 13px; cursor: pointer; }

  /* ── Espelho de Ponto — bloco de info do colaborador ── */
  .esp-info { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 20px; margin-bottom:16px; display:flex; gap:24px; flex-wrap:wrap; }
  .esp-info-item { display:flex; flex-direction:column; gap:2px; }
  .esp-info-label { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; }
  .esp-info-value { font-size:13px; font-weight:600; color:#1e293b; }

  /* ── Totais do espelho ── */
  .esp-totais { display:flex; gap:12px; margin-bottom:16px; }
  .esp-kpi { flex:1; background:#f0f4ff; border-radius:8px; padding:12px 16px; border-left:3px solid #2563eb; text-align:center; }
  .esp-kpi.red  { border-left-color:#dc2626; background:#fff1f2; }
  .esp-kpi.green{ border-left-color:#16a34a; background:#f0fdf4; }
  .esp-kpi.gray { border-left-color:#64748b; background:#f8fafc; }
  .esp-kpi-label { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; margin-bottom:4px; }
  .esp-kpi-value { font-size:18px; font-weight:700; color:#1e3a8a; }
  .esp-kpi.red  .esp-kpi-value { color:#dc2626; }
  .esp-kpi.green .esp-kpi-value { color:#16a34a; }
  .esp-kpi.gray .esp-kpi-value { color:#475569; }

  /* ── Cabeçalho especial do espelho (substitui o .header genérico) ── */
  .esp-header { background:#fff; border-bottom:3px solid #1e3a8a; padding:20px 32px; display:flex; align-items:center; gap:24px; }
  .esp-header img { height:72px; width:auto; }
  .esp-header-empresa { flex:1; }
  .esp-header-empresa h2 { font-size:17px; font-weight:800; color:#1e3a8a; letter-spacing:.2px; margin-bottom:2px; }
  .esp-header-empresa p { font-size:11px; color:#475569; line-height:1.5; }
  .esp-header-doc { text-align:right; }
  .esp-header-doc h1 { font-size:15px; font-weight:700; color:#1e293b; text-transform:uppercase; letter-spacing:1px; }
  .esp-header-doc p { font-size:11px; color:#64748b; margin-top:2px; }

  /* ── Declaração ── */
  .declaracao { margin:28px 0 0; padding:20px 24px; background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #1e3a8a; border-radius:0 8px 8px 0; page-break-inside:avoid; }
  .declaracao p { font-size:12px; color:#1e293b; line-height:1.9; text-align:justify; }

  /* ── Assinaturas ── */
  .assinaturas { margin-top: 32px; padding: 0 0 20px; page-break-inside: avoid; }
  .assinaturas-titulo { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; margin-bottom:28px; padding-bottom:6px; border-bottom:1px solid #e2e8f0; }
  .assinaturas-grid { display:flex; gap:60px; }
  .assinatura-bloco { flex:1; }
  .assinatura-linha { border-bottom: 1.5px solid #1e293b; margin-bottom:8px; height:44px; }
  .assinatura-nome { font-size:12px; font-weight:600; color:#1e293b; margin-bottom:2px; }
  .assinatura-cargo { font-size:10px; color:#64748b; }
  .assinatura-data { font-size:10px; color:#94a3b8; margin-top:4px; }

  @media print {
    .print-bar { display: none !important; }
    body { background: #fff; }
    .header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    thead tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .footer { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .kpi  { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .esp-kpi { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .esp-header { border-bottom-color: #1e3a8a !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .declaracao { -webkit-print-color-adjust: exact; print-color-adjust: exact; border-left-color:#1e3a8a !important; background:#f8fafc !important; }
    .assinaturas { margin-top: 40px; page-break-inside: avoid; }
    tfoot tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<!-- Barra de impressão -->
<div class="print-bar">
  <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
  <button class="btn-close" onclick="window.close()">✕ Fechar</button>
  <span style="margin-left:auto;color:#64748b;font-size:12px;">
    <?= $total_registros ?> registro(s) encontrado(s)
  </span>
</div>

<?php if ($tipo === 'espelho_ponto' || $tipo === 'horas_extras' || $tipo === 'totais_horas'): ?>
<!-- Cabeçalho da empresa -->
<div class="esp-header">
  <?php if ($logo_b64): ?>
    <img src="<?= $logo_b64 ?>" alt="Logo">
  <?php endif; ?>
  <div class="esp-header-empresa">
    <h2><?= htmlspecialchars($nome_empresa) ?></h2>
    <p>
      <?= $cnpj_empresa ? 'CNPJ: '.htmlspecialchars($cnpj_empresa) : '' ?>
      <?php if ($cidade_empresa): ?>
        <?= $cnpj_empresa ? ' &nbsp;·&nbsp; ' : '' ?><?= htmlspecialchars($cidade_empresa) ?><?= $estado_empresa ? '/' . htmlspecialchars($estado_empresa) : '' ?>
      <?php endif; ?>
      <?php if ($empresa_info['telefone'] ?? ''): ?>
        &nbsp;·&nbsp; <?= htmlspecialchars($empresa_info['telefone']) ?>
      <?php endif; ?>
    </p>
    <?php if ($empresa_info['email_principal'] ?? ''): ?>
    <p><?= htmlspecialchars($empresa_info['email_principal']) ?></p>
    <?php endif; ?>
  </div>
  <div class="esp-header-doc">
    <h1><?= htmlspecialchars($titulo) ?></h1>
    <p><?= htmlspecialchars($label_periodo) ?></p>
    <?php if ($dept): ?><p style="font-size:10px;color:#64748b;"><?= htmlspecialchars($dept) ?></p><?php endif; ?>
    <p style="margin-top:4px;font-size:10px;color:#94a3b8;">Emitido em: <?= $data_geracao ?></p>
  </div>
</div>
<?php else: ?>
<!-- Cabeçalho genérico -->
<div class="header">
  <?php if ($logo_b64): ?>
    <img src="<?= $logo_b64 ?>" alt="Logo">
  <?php endif; ?>
  <div class="header-info">
    <h1><?= htmlspecialchars($titulo) ?></h1>
    <p><?= htmlspecialchars($subtitulo) ?></p>
  </div>
  <div class="header-meta">
    Gerado em: <?= $data_geracao ?><br>
    Operador: <?= htmlspecialchars($usuario_nome) ?><br>
    Período: <?= htmlspecialchars($label_periodo) ?>
  </div>
</div>

<!-- KPIs -->
<div class="kpis">
  <?php if ($tipo === 'banco_horas'): ?>
  <div class="kpi">
    <div class="kpi-label">Colaborador</div>
    <div class="kpi-value" style="font-size:14px;"><?= htmlspecialchars($colab['nome'] ?? '—') ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-label">Lançamentos</div>
    <div class="kpi-value"><?= $total_registros ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-label">Saldo Atual</div>
    <div class="kpi-value" style="color:<?= ($bh_saldo_total ?? 0) >= 0 ? '#16a34a' : '#dc2626' ?>;">
      <?= (($bh_saldo_total ?? 0) < 0 ? '-' : '') . _min_h(abs($bh_saldo_total ?? 0)) ?>
    </div>
  </div>
  <?php else: ?>
  <div class="kpi">
    <div class="kpi-label">Total de Registros</div>
    <div class="kpi-value"><?= $total_registros ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-label">Período</div>
    <div class="kpi-value" style="font-size:14px;"><?= htmlspecialchars($label_periodo) ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-label">Tipo de Relatório</div>
    <div class="kpi-value" style="font-size:14px;"><?= htmlspecialchars($titulo) ?></div>
  </div>
  <?php if ($dept): ?>
  <div class="kpi">
    <div class="kpi-label">Departamento</div>
    <div class="kpi-value" style="font-size:14px;"><?= htmlspecialchars($dept) ?></div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Conteúdo -->
<div class="content">

<?php if ($tipo === 'espelho_ponto'): ?>
  <!-- ── Espelho de Ponto: layout dedicado ─────────────────────────────── -->
  <div class="esp-info">
    <div class="esp-info-item">
      <span class="esp-info-label">Colaborador</span>
      <span class="esp-info-value"><?= htmlspecialchars($colab['nome']??'—') ?></span>
    </div>
    <div class="esp-info-item">
      <span class="esp-info-label">Cargo</span>
      <span class="esp-info-value"><?= htmlspecialchars($colab['cargo']??'—') ?></span>
    </div>
    <div class="esp-info-item">
      <span class="esp-info-label">Departamento</span>
      <span class="esp-info-value"><?= htmlspecialchars($colab['departamento']??'—') ?></span>
    </div>
    <div class="esp-info-item">
      <span class="esp-info-label">Contrato</span>
      <span class="esp-info-value"><?= strtoupper(htmlspecialchars($colab['tipo_contrato']??'—')) ?></span>
    </div>
    <div class="esp-info-item">
      <span class="esp-info-label">Período</span>
      <span class="esp-info-value"><?= htmlspecialchars($label_periodo) ?></span>
    </div>
  </div>

  <div class="esp-totais">
    <div class="esp-kpi">
      <div class="esp-kpi-label">Horas Trabalhadas</div>
      <div class="esp-kpi-value"><?= _min_h($esp_total_trab) ?></div>
    </div>
    <div class="esp-kpi green">
      <div class="esp-kpi-label">Horas Extras</div>
      <div class="esp-kpi-value"><?= _min_h($esp_total_extra) ?></div>
    </div>
    <div class="esp-kpi red">
      <div class="esp-kpi-label">Atrasos</div>
      <div class="esp-kpi-value"><?= _min_h($esp_total_atr) ?></div>
    </div>
    <div class="esp-kpi gray">
      <div class="esp-kpi-label">Faltas</div>
      <div class="esp-kpi-value"><?= $esp_total_falt ?></div>
    </div>
    <div class="esp-kpi gray">
      <div class="esp-kpi-label">Folgas</div>
      <div class="esp-kpi-value"><?= $esp_total_folg ?></div>
    </div>
  </div>

  <?php if (empty($dados)): ?>
    <div class="empty">Nenhum lançamento encontrado para o período informado.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <?php foreach ($colunas as $col): ?>
          <th><?= htmlspecialchars($col) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($dados as $linha):
          $rowcls = $linha['__rowcls__'] ?? '';
          unset($linha['__rowcls__']); ?>
        <tr <?= $rowcls ?>>
          <?php foreach ($linha as $cel): ?>
            <td><?= $cel ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="background:#f0f4ff;font-weight:700;border-top:2px solid #2563eb;">
        <td colspan="7" style="text-align:right;padding:10px 12px;">Totais do Período:</td>
        <td style="padding:10px 12px;"><?= _min_h($esp_total_trab) ?></td>
        <td style="padding:10px 12px;color:#16a34a;"><?= _min_h($esp_total_extra) ?></td>
        <td style="padding:10px 12px;color:#dc2626;"><?= _min_h($esp_total_atr) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>

  <!-- Declaração -->
  <div class="declaracao">
    <p>
      Declaro para os devidos fins de direito que conferi com atenção os horários de entrada, saídas e intervalos
      registrados neste espelho de ponto, correspondentes ao <?= htmlspecialchars($periodo_decl) ?>,
      achando-os fiéis e corretos à jornada efetivamente trabalhada.
    </p>
    <p style="margin-top:10px;">
      Confirmo que não prestei nenhum outro serviço ou hora extraordinária além das que constam neste documento,
      bem como usufruí regularmente dos meus intervalos para repouso e alimentação.
      Por ser a expressão da verdade, firmo a presente assinatura.
    </p>
  </div>

  <!-- Assinaturas -->
  <div class="assinaturas">
    <div class="assinaturas-titulo">Assinaturas</div>
    <div class="assinaturas-grid">
      <div class="assinatura-bloco">
        <div class="assinatura-linha"></div>
        <div class="assinatura-nome"><?= htmlspecialchars($colab['nome']??'Colaborador') ?></div>
        <div class="assinatura-cargo"><?= htmlspecialchars($colab['cargo']??'') ?></div>
        <div class="assinatura-data">Assinatura do Empregado</div>
      </div>
      <div class="assinatura-bloco">
        <div class="assinatura-linha"></div>
        <div class="assinatura-nome"><?= htmlspecialchars($nome_empresa) ?></div>
        <?php if ($cnpj_empresa): ?>
        <div class="assinatura-cargo">CNPJ: <?= htmlspecialchars($cnpj_empresa) ?></div>
        <?php endif; ?>
        <div class="assinatura-data">Assinatura e Carimbo da Associação</div>
      </div>
    </div>
    <?php
    $local_ass = $cidade_empresa ? htmlspecialchars($cidade_empresa).($estado_empresa ? '/'.htmlspecialchars($estado_empresa) : '') : '';
    $data_ass  = date('d \d\e F \d\e Y');
    // Traduz nome do mês que date() retorna em inglês
    $meses_ass = ['January'=>'janeiro','February'=>'fevereiro','March'=>'março','April'=>'abril',
                  'May'=>'maio','June'=>'junho','July'=>'julho','August'=>'agosto',
                  'September'=>'setembro','October'=>'outubro','November'=>'novembro','December'=>'dezembro'];
    $data_ass  = str_replace(array_keys($meses_ass), array_values($meses_ass), $data_ass);
    ?>
    <p style="margin-top:20px;font-size:11px;color:#64748b;text-align:right;">
      <?= $local_ass ? $local_ass.', ' : '' ?><?= $data_ass ?>
    </p>
  </div>

<?php else: ?>
  <!-- ── Layout genérico para demais relatórios ─────────────────────────── -->
  <div class="section-title"><?= htmlspecialchars($titulo) ?> — <?= htmlspecialchars($subtitulo) ?></div>

  <?php if ($tipo === 'horas_extras'): ?>
  <!-- Totalizadores rápidos — Horas Extras -->
  <div class="esp-totais" style="margin-bottom:16px;">
    <div class="esp-kpi">
      <div class="esp-kpi-label">Colaboradores</div>
      <div class="esp-kpi-value"><?= count($dados) ?></div>
    </div>
    <div class="esp-kpi green">
      <div class="esp-kpi-label">Total Horas Extras</div>
      <div class="esp-kpi-value"><?= _min_h($he_total_extra) ?></div>
    </div>
    <div class="esp-kpi">
      <div class="esp-kpi-label">Total Horas Trabalhadas</div>
      <div class="esp-kpi-value"><?= _min_h($he_total_trab) ?></div>
    </div>
    <div class="esp-kpi gray">
      <div class="esp-kpi-label">Período</div>
      <div class="esp-kpi-value" style="font-size:14px;"><?= htmlspecialchars($label_periodo) ?></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tipo === 'totais_horas'): ?>
  <!-- Totalizadores rápidos — Totais de Horas -->
  <div class="esp-totais" style="margin-bottom:16px;">
    <div class="esp-kpi">
      <div class="esp-kpi-label">Colaboradores</div>
      <div class="esp-kpi-value"><?= count($dados) ?></div>
    </div>
    <div class="esp-kpi">
      <div class="esp-kpi-label">Total Trabalhado</div>
      <div class="esp-kpi-value"><?= _min_h($th_total_trab) ?></div>
    </div>
    <div class="esp-kpi green">
      <div class="esp-kpi-label">Total Extras</div>
      <div class="esp-kpi-value"><?= _min_h($th_total_extra) ?></div>
    </div>
    <div class="esp-kpi red">
      <div class="esp-kpi-label">Total Atrasos</div>
      <div class="esp-kpi-value"><?= _min_h($th_total_atr) ?></div>
    </div>
    <div class="esp-kpi gray">
      <div class="esp-kpi-label">Faltas / Folgas</div>
      <div class="esp-kpi-value"><?= $th_total_falt ?> / <?= $th_total_folg ?></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($dados)): ?>
    <div class="empty">Nenhum dado encontrado para o período informado.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <?php foreach ($colunas as $col): ?>
          <th><?= htmlspecialchars($col) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($dados as $linha): ?>
        <tr>
          <?php foreach ($linha as $cel): ?>
            <td><?= $cel ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($tipo === 'horas_extras'): ?>
    <tfoot>
      <tr style="background:#f0f4ff;font-weight:700;border-top:2px solid #2563eb;">
        <td colspan="3" style="text-align:right;padding:10px 12px;">Total Geral:</td>
        <td style="padding:10px 12px;color:#16a34a;font-weight:700;"><?= _min_h($he_total_extra) ?></td>
        <td style="padding:10px 12px;font-weight:700;"><?= _min_h($he_total_trab) ?></td>
      </tr>
    </tfoot>
    <?php endif; ?>
    <?php if ($tipo === 'totais_horas'): ?>
    <tfoot>
      <tr style="background:#f0f4ff;font-weight:700;border-top:2px solid #2563eb;">
        <td colspan="4" style="text-align:right;padding:10px 12px;">Total Geral:</td>
        <td style="padding:10px 12px;"><?= _min_h($th_total_trab) ?></td>
        <td style="padding:10px 12px;color:#16a34a;"><?= _min_h($th_total_extra) ?></td>
        <td style="padding:10px 12px;color:#dc2626;"><?= _min_h($th_total_atr) ?></td>
        <td style="padding:10px 12px;"><?= $th_total_falt ?></td>
        <td style="padding:10px 12px;"><?= $th_total_folg ?></td>
        <td></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>

  <?php if ($tipo === 'horas_extras'): ?>
  <!-- Assinatura da Associação -->
  <div class="assinaturas" style="margin-top:40px;">
    <div class="assinaturas-titulo">Assinatura</div>
    <div style="max-width:360px;">
      <div class="assinatura-linha"></div>
      <div class="assinatura-nome"><?= htmlspecialchars($nome_empresa) ?></div>
      <?php if ($cnpj_empresa): ?>
      <div class="assinatura-cargo">CNPJ: <?= htmlspecialchars($cnpj_empresa) ?></div>
      <?php endif; ?>
      <div class="assinatura-data">Assinatura e Carimbo da Associação</div>
    </div>
    <?php
    $local_he = $cidade_empresa ? htmlspecialchars($cidade_empresa).($estado_empresa ? '/'.htmlspecialchars($estado_empresa) : '') : '';
    $data_he  = date('d \d\e F \d\e Y');
    $meses_he = ['January'=>'janeiro','February'=>'fevereiro','March'=>'março','April'=>'abril',
                 'May'=>'maio','June'=>'junho','July'=>'julho','August'=>'agosto',
                 'September'=>'setembro','October'=>'outubro','November'=>'novembro','December'=>'dezembro'];
    $data_he  = str_replace(array_keys($meses_he), array_values($meses_he), $data_he);
    ?>
    <p style="margin-top:20px;font-size:11px;color:#64748b;text-align:right;">
      <?= $local_he ? $local_he.', ' : '' ?><?= $data_he ?>
    </p>
  </div>
  <?php endif; ?>

  <?php endif; ?>
<?php endif; ?>

</div>

<!-- Rodapé -->
<div class="footer">
  <?= htmlspecialchars($nome_empresa) ?>
  <?= $cnpj_empresa ? ' &nbsp;|&nbsp; CNPJ: '.htmlspecialchars($cnpj_empresa) : '' ?>
  &nbsp;|&nbsp; Sistema ERP Condomínios &nbsp;|&nbsp;
  Relatório gerado em <?= $data_geracao ?> por <?= htmlspecialchars($usuario_nome) ?>
</div>

</body>
</html>
