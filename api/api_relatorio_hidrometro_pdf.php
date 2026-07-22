<?php
/**
 * ============================================================
 * RELATORIOS DE HIDROMETROS — GERADOR DE PDF/IMPRESSAO
 * ============================================================
 * Mesmo template padrao do restante do ERP (ver api_relatorio_acessos_pdf.php).
 * Identidade visual: azul #1e3a8a / #2563eb + logo da associacao.
 * Cobre os 7 tipos de relatorio do modulo Hidrometros > Relatorios,
 * usando as mesmas consultas/regras de api_relatorios_hidrometro.php
 * e api_leituras.php?relatorio=1 (tipo "geral").
 *
 * Filtros aceitos via GET:
 *   tipo      — geral | evolucao | alertas | inativos | ranking | financeiro | unidade
 *   data_de   — Data inicial YYYY-MM-DD
 *   data_ate  — Data final YYYY-MM-DD
 *   unidade   — Filtro exato por unidade (obrigatorio para tipo=unidade)
 *   motivo    — Filtro de texto (somente tipo=inativos)
 *   print     — Se "true", dispara window.print() automaticamente
 *
 * @version 2.0.0
 */
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

$conn    = conectar_banco();
$usuario = verificarAutenticacao(false, 'operador');
$tenant_id = exigirTenantId();

date_default_timezone_set('America/Sao_Paulo');

// ── Dados da empresa ───────────────────────────────────────────
$empresa = [];
$res_emp = $conn->query("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM empresa LIMIT 1");
if ($res_emp && $res_emp->num_rows > 0) {
    $empresa = $res_emp->fetch_assoc();
}
$nome_empresa = !empty($empresa['nome_fantasia'])  ? $empresa['nome_fantasia']
              : (!empty($empresa['razao_social'])  ? $empresa['razao_social']
              : 'ASSOCIACAO SERRA DA LIBERDADE');
$cnpj_empresa = !empty($empresa['cnpj']) ? $empresa['cnpj'] : '28.231.106/0001-15';

$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'asl.erpcondominios.com.br';
$logo_url  = !empty($empresa['logo_url'])
           ? $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
           : $protocolo . '://' . $host . '/assets/images/logo.jpeg';

// ── Filtros comuns ──────────────────────────────────────────────
$tipo           = trim($_GET['tipo'] ?? 'geral');
$data_de        = trim($_GET['data_de']  ?? '');
$data_ate       = trim($_GET['data_ate'] ?? '');
$filtro_unidade = trim($_GET['unidade']  ?? '');
$filtro_motivo  = trim($_GET['motivo']   ?? '');
$filtro_status     = trim($_GET['status']    ?? '');
$filtro_ordenacao  = trim($_GET['ordenacao'] ?? 'unidade');
$auto_print     = ($_GET['print'] ?? '') === 'true';

$data_de_fmt  = $data_de  !== '' ? date('d/m/Y', strtotime($data_de))  : null;
$data_ate_fmt = $data_ate !== '' ? date('d/m/Y', strtotime($data_ate)) : null;

$periodo_txt = ($data_de_fmt || $data_ate_fmt)
    ? 'Periodo: ' . ($data_de_fmt ?: 'inicio') . ' a ' . ($data_ate_fmt ?: 'hoje')
    : 'Periodo: todas as datas';
$unidade_txt = $filtro_unidade !== '' ? "Unidade: {$filtro_unidade}" : 'Unidade: todas';

$data_geracao  = date('d/m/Y \a\s H:i');
$operador_nome = $usuario ? ($usuario['nome'] ?? 'Sistema') : 'Sistema';

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtM3($v) { return number_format((float) $v, 2, ',', '.') . ' m&sup3;'; }
function fmtRS($v) { return 'R$ ' . number_format((float) $v, 2, ',', '.'); }

// ── Ordenacao natural de unidades (Administrativo primeiro, depois numerica) ──
// Mesma regra usada em api_leituras.php / api_relatorios_hidrometro.php.
function _compararUnidadesNaturalPdf($a, $b) {
    $isAdmin = function ($str) { return stripos((string) $str, 'adm') !== false; };
    $admA = $isAdmin($a);
    $admB = $isAdmin($b);
    if ($admA && !$admB) return -1;
    if (!$admA && $admB) return 1;

    $numKey = function ($str) {
        return preg_match('/(\d+)/', (string) $str, $m) ? (int) $m[1] : null;
    };
    $nA = $numKey($a);
    $nB = $numKey($b);
    if ($nA !== null && $nB !== null && $nA !== $nB) {
        return $nA <=> $nB;
    }
    return strnatcasecmp((string) $a, (string) $b);
}

// Variáveis que cada branch de $tipo preenche para a renderização genérica abaixo
$titulo_relatorio = 'Relatório de Consumo de Água';
$subtitulo        = '&#128167; Consumo de Água';
$kpis             = [];      // [['valor'=>..,'label'=>..], ...] (grid de 4)
$colunas          = [];
$linhas_tabela    = [];      // cada item = array de células HTML já formatadas
$colspan_vazio    = 8;
$msg_vazio        = 'Nenhum registro encontrado com os filtros aplicados';
$titulo_tabela    = '';
$colunas2         = null;
$linhas2          = null;
$titulo_tabela2   = '';
$grafico          = null;    // ['labels'=>[], 'label1'=>'', 'dados1'=>[], 'label2'=>null, 'dados2'=>null, 'tipo'=>'bar']
$resumo_alertas   = null;    // ['zero'=>..,'moderado'=>..,'alto'=>..,'vazio'=>..,'total'=>..]

// =====================================================================
if ($tipo === 'geral') {
    $titulo_relatorio = 'Relatório Geral de Consumo';
    $subtitulo = '&#128167; Consumo de Água por Hidrômetro';
    $colunas = ['Unidade', 'Morador', 'Nº Hidrômetro', 'Qtd. Leituras', 'Consumo Total', 'Valor Total', 'Consumo Médio', 'Última Leitura'];
    $colspan_vazio = 8;

    $where = ['1=1']; $params = []; $types = '';
    if ($data_de !== '')  { $where[] = 'DATE(l.data_leitura) >= ?'; $params[] = $data_de;  $types .= 's'; }
    if ($data_ate !== '') { $where[] = 'DATE(l.data_leitura) <= ?'; $params[] = $data_ate; $types .= 's'; }
    if ($filtro_unidade !== '') { $where[] = 'l.unidade = ?'; $params[] = $filtro_unidade; $types .= 's'; }
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT l.unidade, l.consumo, l.valor_total, h.numero_hidrometro, m.nome AS morador_nome,
                   DATE_FORMAT(l.data_leitura, '%d/%m/%Y %H:%i') AS data_leitura_formatada
            FROM leituras l
            INNER JOIN hidrometros h ON l.hidrometro_id = h.id
            INNER JOIN moradores m ON l.morador_id = m.id
            WHERE $where_sql ORDER BY l.data_leitura ASC";
    $leituras = [];
    if ($params) {
        $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $leituras[] = $row; }
        $stmt->close();
    } else {
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) { $leituras[] = $row; }
    }

    $porHidro = [];
    foreach ($leituras as $l) {
        $key = $l['numero_hidrometro'] ?: $l['unidade'];
        if (!isset($porHidro[$key])) {
            $porHidro[$key] = ['unidade' => $l['unidade'], 'morador' => $l['morador_nome'], 'numero_hidrometro' => $l['numero_hidrometro'],
                                'leituras' => 0, 'consumo_total' => 0.0, 'valor_total' => 0.0, 'ultima_leitura' => ''];
        }
        $porHidro[$key]['leituras']++;
        $porHidro[$key]['consumo_total'] += floatval($l['consumo'] ?? 0);
        $porHidro[$key]['valor_total']   += floatval($l['valor_total'] ?? 0);
        if ($l['data_leitura_formatada'] > $porHidro[$key]['ultima_leitura']) $porHidro[$key]['ultima_leitura'] = $l['data_leitura_formatada'];
    }
    $linhas = array_values($porHidro);
    usort($linhas, fn($a, $b) => _compararUnidadesNaturalPdf($a['unidade'], $b['unidade']));

    $total_leituras = count($leituras);
    $consumo_total  = array_sum(array_map(fn($l) => floatval($l['consumo'] ?? 0), $leituras));
    $valor_total    = array_sum(array_map(fn($l) => floatval($l['valor_total'] ?? 0), $leituras));
    $consumo_medio  = $total_leituras > 0 ? $consumo_total / $total_leituras : 0;

    $kpis = [
        ['valor' => $total_leituras, 'label' => 'Total Leituras'],
        ['valor' => fmtM3($consumo_total), 'label' => 'Consumo Total'],
        ['valor' => fmtRS($valor_total), 'label' => 'Valor Total'],
        ['valor' => fmtM3($consumo_medio), 'label' => 'Consumo Médio'],
    ];
    $titulo_tabela = count($linhas) . ' hidrômetro(s) no período filtrado';

    foreach ($linhas as $h) {
        $linhas_tabela[] = [esc($h['unidade']), esc($h['morador']), esc($h['numero_hidrometro']),
            '<span style="text-align:center;display:block;">' . $h['leituras'] . '</span>',
            fmtM3($h['consumo_total']), fmtRS($h['valor_total']), fmtM3($h['consumo_total'] / $h['leituras']), esc($h['ultima_leitura'])];
    }

// =====================================================================
} elseif ($tipo === 'evolucao') {
    $titulo_relatorio = 'Evolução de Consumo';
    $subtitulo = '&#128200; Comparativo Mensal de Consumo';
    $colunas = ['Mês', 'Leituras', 'Consumo Total', 'Valor Total'];
    $colspan_vazio = 4;
    $colunas2 = ['Mês', 'Unidade', 'Consumo', 'Valor'];
    $titulo_tabela2 = 'Detalhamento por Unidade e Mês';

    $where = ['1=1']; $params = []; $types = '';
    if ($data_de !== '')  { $where[] = 'DATE(l.data_leitura) >= ?'; $params[] = $data_de;  $types .= 's'; }
    if ($data_ate !== '') { $where[] = 'DATE(l.data_leitura) <= ?'; $params[] = $data_ate; $types .= 's'; }
    if ($filtro_unidade !== '') { $where[] = 'l.unidade = ?'; $params[] = $filtro_unidade; $types .= 's'; }
    $where_sql = implode(' AND ', $where);

    $sql_mensal = "SELECT DATE_FORMAT(l.data_leitura,'%Y-%m') mes_key, DATE_FORMAT(l.data_leitura,'%m/%Y') mes_label,
                          COUNT(*) leituras, SUM(l.consumo) consumo_total, SUM(l.valor_total) valor_total
                   FROM leituras l WHERE $where_sql GROUP BY mes_key ORDER BY mes_key ASC";
    $mensal = [];
    if ($params) {
        $stmt = $conn->prepare($sql_mensal); $stmt->bind_param($types, ...$params); $stmt->execute();
        $res = $stmt->get_result(); while ($row = $res->fetch_assoc()) { $mensal[] = $row; } $stmt->close();
    } else {
        $res = $conn->query($sql_mensal); while ($row = $res->fetch_assoc()) { $mensal[] = $row; }
    }

    $sql_det = "SELECT DATE_FORMAT(l.data_leitura,'%Y-%m') mes_key, DATE_FORMAT(l.data_leitura,'%m/%Y') mes_label,
                       l.unidade, SUM(l.consumo) consumo_total, SUM(l.valor_total) valor_total
                FROM leituras l WHERE $where_sql GROUP BY mes_key, l.unidade ORDER BY mes_key ASC";
    $detalhado = [];
    if ($params) {
        $stmt = $conn->prepare($sql_det); $stmt->bind_param($types, ...$params); $stmt->execute();
        $res = $stmt->get_result(); while ($row = $res->fetch_assoc()) { $detalhado[] = $row; } $stmt->close();
    } else {
        $res = $conn->query($sql_det); while ($row = $res->fetch_assoc()) { $detalhado[] = $row; }
    }
    usort($detalhado, function ($a, $b) {
        if ($a['mes_key'] !== $b['mes_key']) return strcmp($a['mes_key'], $b['mes_key']);
        return _compararUnidadesNaturalPdf($a['unidade'], $b['unidade']);
    });

    $totalLeituras = array_sum(array_map(fn($m) => intval($m['leituras']), $mensal));
    $totalConsumo  = array_sum(array_map(fn($m) => floatval($m['consumo_total']), $mensal));
    $totalValor    = array_sum(array_map(fn($m) => floatval($m['valor_total']), $mensal));

    $kpis = [
        ['valor' => $totalLeituras, 'label' => 'Total Leituras'],
        ['valor' => fmtM3($totalConsumo), 'label' => 'Consumo Total'],
        ['valor' => fmtRS($totalValor), 'label' => 'Valor Total'],
        ['valor' => fmtM3(count($mensal) > 0 ? $totalConsumo / count($mensal) : 0), 'label' => 'Consumo Médio/Mês'],
    ];
    $titulo_tabela = count($mensal) . ' mês(es) no período selecionado';

    foreach ($mensal as $m) {
        $linhas_tabela[] = [esc($m['mes_label']),
            '<span style="text-align:center;display:block;">' . $m['leituras'] . '</span>',
            fmtM3($m['consumo_total']), fmtRS($m['valor_total'])];
    }
    $linhas2 = [];
    foreach ($detalhado as $d) {
        $linhas2[] = [esc($d['mes_label']), esc($d['unidade']), fmtM3($d['consumo_total']), fmtRS($d['valor_total'])];
    }
    if (count($mensal) > 0) {
        $grafico = ['labels' => array_map(fn($m) => $m['mes_label'], $mensal),
                    'label1' => 'Consumo Total (m³)', 'dados1' => array_map(fn($m) => round(floatval($m['consumo_total']), 2), $mensal),
                    'tipo' => 'bar'];
    }

// =====================================================================
} elseif ($tipo === 'alertas') {
    $titulo_relatorio = 'Alertas de Consumo';
    $subtitulo = '&#9888; Consumos Suspeitos Detectados';
    $colunas = ['Unidade', 'Morador', 'Nº Hidrômetro', 'Categoria', 'Detalhes', 'Última Leitura'];
    $colspan_vazio = 6;
    $msg_vazio = 'Nenhum alerta encontrado — consumo dentro do padrão em todas as unidades';

    $where_h = ['h.ativo = 1']; $params_h = []; $types_h = '';
    if ($filtro_unidade !== '') { $where_h[] = 'h.unidade = ?'; $params_h[] = $filtro_unidade; $types_h .= 's'; }
    $where_h_sql = implode(' AND ', $where_h);

    $sql_h = "SELECT h.id, h.unidade, h.numero_hidrometro, h.data_instalacao, m.nome morador_nome,
                     (SELECT l2.consumo FROM leituras l2 WHERE l2.hidrometro_id = h.id ORDER BY l2.data_leitura DESC LIMIT 1) ultima_consumo_geral,
                     (SELECT DATE_FORMAT(l3.data_leitura,'%d/%m/%Y') FROM leituras l3 WHERE l3.hidrometro_id = h.id ORDER BY l3.data_leitura DESC LIMIT 1) ultima_data_geral
              FROM hidrometros h LEFT JOIN moradores m ON h.morador_id = m.id WHERE $where_h_sql";
    $hidrometros = [];
    if ($params_h) {
        $stmt = $conn->prepare($sql_h); $stmt->bind_param($types_h, ...$params_h); $stmt->execute();
        $res = $stmt->get_result(); while ($row = $res->fetch_assoc()) { $hidrometros[$row['id']] = $row; } $stmt->close();
    } else {
        $res = $conn->query($sql_h); while ($row = $res->fetch_assoc()) { $hidrometros[$row['id']] = $row; }
    }

    $alertas = [];
    $resumo_alertas = ['zero' => 0, 'moderado' => 0, 'alto' => 0, 'vazio' => 0, 'total' => 0];

    if (!empty($hidrometros)) {
        $ids = array_keys($hidrometros);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $where_l = ["l.hidrometro_id IN ($ph)"]; $params_l = $ids; $types_l = str_repeat('i', count($ids));
        if ($data_de !== '')  { $where_l[] = 'DATE(l.data_leitura) >= ?'; $params_l[] = $data_de;  $types_l .= 's'; }
        if ($data_ate !== '') { $where_l[] = 'DATE(l.data_leitura) <= ?'; $params_l[] = $data_ate; $types_l .= 's'; }
        $sql_l = "SELECT l.hidrometro_id, l.consumo, DATE_FORMAT(l.data_leitura,'%d/%m/%Y') data_fmt
                  FROM leituras l WHERE " . implode(' AND ', $where_l) . " ORDER BY l.hidrometro_id ASC, l.data_leitura ASC";
        $stmt = $conn->prepare($sql_l); $stmt->bind_param($types_l, ...$params_l); $stmt->execute();
        $res = $stmt->get_result();
        $leiturasPorHidro = [];
        while ($row = $res->fetch_assoc()) { $leiturasPorHidro[$row['hidrometro_id']][] = $row; }
        $stmt->close();

        foreach ($hidrometros as $id => $h) {
            $leiturasH = $leiturasPorHidro[$id] ?? [];
            $n = count($leiturasH);
            $categoria = null; $consumoAtual = null; $consumoMedio = null; $oscPct = null; $ultimaData = null; $dias = null; $msg = '';

            if ($n === 0) {
                $ultimaData = $h['ultima_data_geral'];
                $consumoAtual = $h['ultima_consumo_geral'] !== null ? floatval($h['ultima_consumo_geral']) : null;
                $base = $h['ultima_data_geral'] ? DateTime::createFromFormat('d/m/Y', $h['ultima_data_geral']) : DateTime::createFromFormat('Y-m-d H:i:s', $h['data_instalacao']);
                $dias = $base ? (new DateTime())->diff($base)->days : null;
                if ($consumoAtual === null || $consumoAtual == 0) $categoria = 'zero';
            } else {
                $ultima = $leiturasH[$n - 1];
                $consumoAtual = floatval($ultima['consumo']);
                $ultimaData = $ultima['data_fmt'];
                if ($consumoAtual == 0) {
                    $categoria = 'zero';
                    $base = DateTime::createFromFormat('d/m/Y', $ultimaData);
                    $dias = $base ? (new DateTime())->diff($base)->days : null;
                } elseif ($n >= 2) {
                    $ant = array_slice($leiturasH, 0, $n - 1);
                    $consumoMedio = array_sum(array_map(fn($l) => floatval($l['consumo']), $ant)) / count($ant);
                    if ($consumoMedio > 0) {
                        $oscPct = (($consumoAtual - $consumoMedio) / $consumoMedio) * 100;
                        if ($consumoAtual < $consumoMedio * 0.30) { $categoria = 'vazio'; $msg = 'Consumo muito abaixo da média — possível imóvel vazio.'; }
                        elseif ($oscPct >= 20) { $categoria = 'alto'; $msg = 'Possível: vazamento, furto de água, erro de leitura ou mudança de consumo.'; }
                        elseif ($oscPct >= 10) { $categoria = 'moderado'; $msg = 'Oscilação acima do padrão histórico.'; }
                    }
                }
            }

            if ($categoria !== null) {
                $resumo_alertas[$categoria]++;
                $alertas[] = ['unidade' => $h['unidade'], 'morador_nome' => $h['morador_nome'], 'numero_hidrometro' => $h['numero_hidrometro'],
                    'categoria' => $categoria, 'consumo_medio' => $consumoMedio, 'consumo_atual' => $consumoAtual,
                    'oscilacao_pct' => $oscPct, 'ultima_leitura' => $ultimaData, 'dias_sem_consumo' => $dias, 'mensagem' => $msg];
            }
        }
    }
    $resumo_alertas['total'] = $resumo_alertas['zero'] + $resumo_alertas['moderado'] + $resumo_alertas['alto'] + $resumo_alertas['vazio'];

    $ordem = ['alto' => 0, 'vazio' => 1, 'moderado' => 2, 'zero' => 3];
    usort($alertas, function ($a, $b) use ($ordem) {
        if ($ordem[$a['categoria']] !== $ordem[$b['categoria']]) return $ordem[$a['categoria']] <=> $ordem[$b['categoria']];
        return _compararUnidadesNaturalPdf($a['unidade'], $b['unidade']);
    });

    $labelsCat = ['zero' => 'Sem Consumo', 'moderado' => 'Oscilação Moderada', 'alto' => 'Oscilação Alta', 'vazio' => 'Possível Imóvel Vazio'];
    $titulo_tabela = $resumo_alertas['total'] . ' alerta(s) encontrado(s)';
    foreach ($alertas as $a) {
        $detalhe = $a['categoria'] === 'zero'
            ? ($a['dias_sem_consumo'] !== null ? $a['dias_sem_consumo'] . ' dia(s) sem consumo' : 'Sem leituras registradas')
            : 'Média: ' . number_format((float) $a['consumo_medio'], 2, ',', '.') . ' m&sup3; | Última: ' . number_format((float) $a['consumo_atual'], 2, ',', '.') . ' m&sup3;'
              . ($a['oscilacao_pct'] !== null ? ' (' . ($a['oscilacao_pct'] > 0 ? '+' : '') . number_format($a['oscilacao_pct'], 1, ',', '.') . '%)' : '');
        $linhas_tabela[] = [esc($a['unidade']), esc($a['morador_nome'] ?: '—'), esc($a['numero_hidrometro']),
            esc($labelsCat[$a['categoria']] ?? $a['categoria']),
            $detalhe . ($a['mensagem'] ? '<br><span style="color:#78716c;font-size:8.5px;">' . esc($a['mensagem']) . '</span>' : ''),
            esc($a['ultima_leitura'] ?: '—')];
    }

// =====================================================================
} elseif ($tipo === 'inativos') {
    $titulo_relatorio = 'Histórico de Hidrômetros Inativos';
    $subtitulo = '&#128683; Hidrômetros Desativados';
    $colunas = ['Unidade', 'Morador', 'Nº Hidrômetro', 'Instalação', 'Inativação', 'Motivo', 'Última Leitura', 'Tempo em Operação'];
    $colspan_vazio = 8;
    $msg_vazio = 'Nenhum hidrômetro inativo encontrado para os filtros informados';

    $where = ['h.ativo = 0']; $params = []; $types = '';
    if ($filtro_unidade !== '') { $where[] = 'h.unidade = ?'; $params[] = $filtro_unidade; $types .= 's'; }
    if ($data_de !== '')  { $where[] = 'DATE(inat.data_alteracao) >= ?'; $params[] = $data_de;  $types .= 's'; }
    if ($data_ate !== '') { $where[] = 'DATE(inat.data_alteracao) <= ?'; $params[] = $data_ate; $types .= 's'; }
    if ($filtro_motivo !== '') { $where[] = 'inat.observacao LIKE ?'; $params[] = '%' . $filtro_motivo . '%'; $types .= 's'; }
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT h.unidade, m.nome morador_nome, h.numero_hidrometro, h.data_instalacao,
                   DATE_FORMAT(h.data_instalacao,'%d/%m/%Y') data_instalacao_fmt,
                   inat.data_alteracao data_inativacao, DATE_FORMAT(inat.data_alteracao,'%d/%m/%Y') data_inativacao_fmt,
                   inat.observacao motivo,
                   (SELECT leitura_atual FROM leituras WHERE hidrometro_id = h.id ORDER BY data_leitura DESC LIMIT 1) ultima_leitura
            FROM hidrometros h
            LEFT JOIN moradores m ON h.morador_id = m.id
            LEFT JOIN (
                SELECT hh1.hidrometro_id, hh1.data_alteracao, hh1.observacao
                FROM hidrometros_historico hh1
                INNER JOIN (SELECT hidrometro_id, MAX(data_alteracao) max_data FROM hidrometros_historico
                            WHERE campo_alterado = 'ativo' AND valor_novo = '0' GROUP BY hidrometro_id) ult
                    ON ult.hidrometro_id = hh1.hidrometro_id AND ult.max_data = hh1.data_alteracao
                WHERE hh1.campo_alterado = 'ativo' AND hh1.valor_novo = '0'
            ) inat ON inat.hidrometro_id = h.id
            WHERE $where_sql";
    $linhas = [];
    if ($params) {
        $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
        $res = $stmt->get_result(); while ($row = $res->fetch_assoc()) { $linhas[] = $row; } $stmt->close();
    } else {
        $res = $conn->query($sql); while ($row = $res->fetch_assoc()) { $linhas[] = $row; }
    }
    usort($linhas, fn($a, $b) => _compararUnidadesNaturalPdf($a['unidade'], $b['unidade']));

    $kpis = [['valor' => count($linhas), 'label' => 'Hidrômetros Inativos'], ['valor' => '—', 'label' => 'Consumo Total'],
             ['valor' => '—', 'label' => 'Valor Total'], ['valor' => '—', 'label' => 'Consumo Médio']];
    $titulo_tabela = count($linhas) . ' hidrômetro(s) inativo(s) encontrado(s)';

    foreach ($linhas as $h) {
        $tempo = '—';
        if ($h['data_inativacao']) {
            $inst = DateTime::createFromFormat('Y-m-d H:i:s', $h['data_instalacao']);
            $inat = DateTime::createFromFormat('Y-m-d H:i:s', $h['data_inativacao']);
            if ($inst && $inat) $tempo = $inst->diff($inat)->days . ' dias';
        }
        $linhas_tabela[] = [esc($h['unidade']), esc($h['morador_nome'] ?: '—'), esc($h['numero_hidrometro']),
            esc($h['data_instalacao_fmt']), esc($h['data_inativacao_fmt'] ?: '—'), esc($h['motivo'] ?: '—'),
            $h['ultima_leitura'] !== null ? fmtM3($h['ultima_leitura']) : 'Sem leitura', esc($tempo)];
    }

// =====================================================================
} elseif ($tipo === 'ranking') {
    $titulo_relatorio = 'Ranking de Consumo';
    $subtitulo = '&#127942; Maiores e Menores Consumidores';
    $colunas = ['#', 'Unidade', 'Morador', 'Nº Hidrômetro', 'Consumo Total', 'Valor Total'];
    $colspan_vazio = 6;
    $colunas2 = ['#', 'Unidade', 'Morador', 'Consumo Total'];
    $titulo_tabela2 = '10 Menores Consumidores';

    $where = ['1=1']; $params = []; $types = '';
    if ($data_de !== '')  { $where[] = 'DATE(l.data_leitura) >= ?'; $params[] = $data_de;  $types .= 's'; }
    if ($data_ate !== '') { $where[] = 'DATE(l.data_leitura) <= ?'; $params[] = $data_ate; $types .= 's'; }
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT l.unidade, m.nome morador_nome, h.numero_hidrometro, SUM(l.consumo) consumo_total, SUM(l.valor_total) valor_total, COUNT(*) leituras
            FROM leituras l INNER JOIN hidrometros h ON l.hidrometro_id = h.id INNER JOIN moradores m ON l.morador_id = m.id
            WHERE $where_sql GROUP BY l.hidrometro_id ORDER BY consumo_total DESC";
    $todos = [];
    if ($params) {
        $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
        $res = $stmt->get_result(); while ($row = $res->fetch_assoc()) { $todos[] = $row; } $stmt->close();
    } else {
        $res = $conn->query($sql); while ($row = $res->fetch_assoc()) { $todos[] = $row; }
    }
    $maiores = array_slice($todos, 0, 10);
    $menores = array_slice(array_reverse($todos), 0, 10);

    $totalConsumo = array_sum(array_map(fn($l) => floatval($l['consumo_total']), $todos));
    $totalValor   = array_sum(array_map(fn($l) => floatval($l['valor_total']), $todos));
    $kpis = [
        ['valor' => count($todos), 'label' => 'Hidrômetros no Ranking'],
        ['valor' => fmtM3($totalConsumo), 'label' => 'Consumo Total'],
        ['valor' => fmtRS($totalValor), 'label' => 'Valor Total'],
        ['valor' => fmtM3(count($todos) > 0 ? $totalConsumo / count($todos) : 0), 'label' => 'Consumo Médio'],
    ];
    $titulo_tabela = 'Top ' . count($maiores) . ' Maiores Consumidores';

    foreach ($maiores as $i => $h) {
        $linhas_tabela[] = ['#' . ($i + 1), esc($h['unidade']), esc($h['morador_nome']), esc($h['numero_hidrometro']),
            fmtM3($h['consumo_total']), fmtRS($h['valor_total'])];
    }
    $linhas2 = [];
    foreach ($menores as $i => $h) {
        $linhas2[] = ['#' . ($i + 1), esc($h['unidade']), esc($h['morador_nome']), fmtM3($h['consumo_total'])];
    }
    if (count($maiores) > 0) {
        $grafico = ['labels' => array_map(fn($h) => $h['unidade'], $maiores),
                    'label1' => 'Consumo Total (m³)', 'dados1' => array_map(fn($h) => round(floatval($h['consumo_total']), 2), $maiores),
                    'tipo' => 'bar'];
    }

// =====================================================================
} elseif ($tipo === 'financeiro') {
    $titulo_relatorio = 'Relatório Financeiro da Água';
    $subtitulo = '&#128176; Resumo Financeiro';
    $colunas = ['Unidade', 'Morador', 'Nº Hidrômetro', 'Leituras', 'Consumo Total', 'Valor Total'];
    $colspan_vazio = 6;

    $where = ['1=1']; $params = []; $types = '';
    if ($data_de !== '')  { $where[] = 'DATE(l.data_leitura) >= ?'; $params[] = $data_de;  $types .= 's'; }
    if ($data_ate !== '') { $where[] = 'DATE(l.data_leitura) <= ?'; $params[] = $data_ate; $types .= 's'; }
    if ($filtro_unidade !== '') { $where[] = 'l.unidade = ?'; $params[] = $filtro_unidade; $types .= 's'; }
    $where_sql = implode(' AND ', $where);

    $sql_tot = "SELECT COUNT(*) total_leituras, COALESCE(SUM(l.consumo),0) consumo_total, COALESCE(SUM(l.valor_total),0) valor_total,
                       COUNT(DISTINCT l.unidade) unidades_distintas
                FROM leituras l WHERE $where_sql";
    if ($params) {
        $stmt = $conn->prepare($sql_tot); $stmt->bind_param($types, ...$params); $stmt->execute();
        $totais = $stmt->get_result()->fetch_assoc(); $stmt->close();
    } else {
        $totais = $conn->query($sql_tot)->fetch_assoc();
    }
    $consumoTotal = floatval($totais['consumo_total']);
    $valorTotal   = floatval($totais['valor_total']);
    $unidadesQtd  = intval($totais['unidades_distintas']);
    $valorMedioUnidade = $unidadesQtd > 0 ? $valorTotal / $unidadesQtd : 0;
    $valorMedioM3      = $consumoTotal > 0 ? $valorTotal / $consumoTotal : 0;

    $kpis = [
        ['valor' => intval($totais['total_leituras']), 'label' => 'Total Leituras'],
        ['valor' => fmtM3($consumoTotal), 'label' => 'Consumo Total'],
        ['valor' => fmtRS($valorTotal), 'label' => 'Valor Cobrado (Receita)'],
        ['valor' => fmtRS($valorMedioM3), 'label' => 'Valor Médio por m³'],
    ];
    $titulo_tabela = 'Detalhamento por Unidade — Valor Médio/Unidade: ' . number_format($valorMedioUnidade, 2, ',', '.');

    $sql_mensal = "SELECT DATE_FORMAT(l.data_leitura,'%Y-%m') mes_key, DATE_FORMAT(l.data_leitura,'%m/%Y') mes_label,
                          SUM(l.consumo) consumo_total, SUM(l.valor_total) valor_total
                   FROM leituras l WHERE $where_sql GROUP BY mes_key ORDER BY mes_key ASC";
    $mensal = [];
    if ($params) {
        $stmt = $conn->prepare($sql_mensal); $stmt->bind_param($types, ...$params); $stmt->execute();
        $res = $stmt->get_result(); while ($row = $res->fetch_assoc()) { $mensal[] = $row; } $stmt->close();
    } else {
        $res = $conn->query($sql_mensal); while ($row = $res->fetch_assoc()) { $mensal[] = $row; }
    }
    if (count($mensal) > 0) {
        $grafico = ['labels' => array_map(fn($m) => $m['mes_label'], $mensal),
                    'label1' => 'Consumo (m³)', 'dados1' => array_map(fn($m) => round(floatval($m['consumo_total']), 2), $mensal),
                    'label2' => 'Receita (R$)', 'dados2' => array_map(fn($m) => round(floatval($m['valor_total']), 2), $mensal),
                    'tipo' => 'bar'];
    }

    $sql_tab = "SELECT l.unidade, m.nome morador_nome, h.numero_hidrometro, COUNT(*) leituras, SUM(l.consumo) consumo_total, SUM(l.valor_total) valor_total
                FROM leituras l INNER JOIN hidrometros h ON l.hidrometro_id = h.id INNER JOIN moradores m ON l.morador_id = m.id
                WHERE $where_sql GROUP BY l.hidrometro_id";
    $tabela = [];
    if ($params) {
        $stmt = $conn->prepare($sql_tab); $stmt->bind_param($types, ...$params); $stmt->execute();
        $res = $stmt->get_result(); while ($row = $res->fetch_assoc()) { $tabela[] = $row; } $stmt->close();
    } else {
        $res = $conn->query($sql_tab); while ($row = $res->fetch_assoc()) { $tabela[] = $row; }
    }
    usort($tabela, fn($a, $b) => _compararUnidadesNaturalPdf($a['unidade'], $b['unidade']));
    foreach ($tabela as $h) {
        $linhas_tabela[] = [esc($h['unidade']), esc($h['morador_nome']), esc($h['numero_hidrometro']),
            '<span style="text-align:center;display:block;">' . $h['leituras'] . '</span>', fmtM3($h['consumo_total']), fmtRS($h['valor_total'])];
    }

// =====================================================================
} elseif ($tipo === 'unidade') {
    $titulo_relatorio = 'Histórico Completo por Unidade';
    $subtitulo = '&#128220; Histórico de Leituras — ' . esc($filtro_unidade ?: '—');
    $colunas = ['Data', 'Leitura', 'Consumo', 'Valor'];
    $colspan_vazio = 4;
    $msg_vazio = 'Nenhuma leitura encontrada para esta unidade no período selecionado';

    if ($filtro_unidade === '') {
        $titulo_tabela = 'Nenhuma unidade informada';
    } else {
        $where = ['l.unidade = ?']; $params = [$filtro_unidade]; $types = 's';
        if ($data_de !== '')  { $where[] = 'DATE(l.data_leitura) >= ?'; $params[] = $data_de;  $types .= 's'; }
        if ($data_ate !== '') { $where[] = 'DATE(l.data_leitura) <= ?'; $params[] = $data_ate; $types .= 's'; }
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT DATE_FORMAT(l.data_leitura,'%d/%m/%Y') data_fmt, l.leitura_atual, l.consumo, l.valor_total
                FROM leituras l WHERE $where_sql ORDER BY l.data_leitura ASC";
        $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
        $res = $stmt->get_result();
        $leituras = [];
        while ($row = $res->fetch_assoc()) { $leituras[] = $row; }
        $stmt->close();

        $consumos = array_map(fn($l) => floatval($l['consumo']), $leituras);
        $consumoMedio = count($consumos) > 0 ? array_sum($consumos) / count($consumos) : 0;
        $maior = count($consumos) > 0 ? max($consumos) : 0;
        $menor = count($consumos) > 0 ? min($consumos) : 0;
        $totalValor = array_sum(array_map(fn($l) => floatval($l['valor_total']), $leituras));

        $kpis = [
            ['valor' => count($leituras), 'label' => 'Total Leituras'],
            ['valor' => fmtM3($consumoMedio), 'label' => 'Consumo Médio'],
            ['valor' => fmtM3($maior), 'label' => 'Maior Consumo'],
            ['valor' => fmtM3($menor), 'label' => 'Menor Consumo'],
        ];
        $titulo_tabela = count($leituras) . ' leitura(s) da unidade ' . esc($filtro_unidade);

        foreach ($leituras as $l) {
            $linhas_tabela[] = [esc($l['data_fmt']), fmtM3($l['leitura_atual']), fmtM3($l['consumo']), fmtRS($l['valor_total'])];
        }
        if (count($leituras) > 0) {
            $grafico = ['labels' => array_map(fn($l) => $l['data_fmt'], $leituras),
                        'label1' => 'Consumo (m³)', 'dados1' => array_map(fn($l) => round(floatval($l['consumo']), 2), $leituras),
                        'tipo' => 'line'];
        }
    }

// =====================================================================
// RELATÓRIO DE CONSUMO ANALÍTICO — leitura anterior x leitura atual do período
// =====================================================================
// Mesma regra de api_relatorios_hidrometro.php?tipo=analitico (duplicada
// aqui seguindo o padrão já usado pelos demais tipos deste arquivo).
} elseif ($tipo === 'analitico') {
    define('REL_ANALITICO_VALOR_M3_PDF', 6.16);
    define('REL_ANALITICO_VALOR_MINIMO_PDF', 61.60);
    define('REL_ANALITICO_CONSUMO_MINIMO_PDF', 10);

    $titulo_relatorio = 'Relatório Analítico de Consumo de Água';
    $subtitulo = '&#128202; Comparativo entre Leitura Anterior e Leitura Atual';
    $colunas = ['Unidade', 'Morador', 'Hidrômetro', 'Leitura Anterior', 'Data Anterior', 'Leitura Atual', 'Data Atual', 'Consumo', 'Valor', 'Situação'];
    $colspan_vazio = 10;
    $msg_vazio = 'Nenhum hidrômetro encontrado para os filtros informados';

    try {
        $idxCheck = $conn->query("SELECT COUNT(1) as qtd FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras' AND INDEX_NAME = 'idx_hidrometro_data'");
        if ($idxCheck && ($idxRow = $idxCheck->fetch_assoc()) && intval($idxRow['qtd']) === 0) {
            $conn->query("CREATE INDEX idx_hidrometro_data ON leituras(hidrometro_id, data_leitura)");
        }
    } catch (Throwable $e) { /* segue sem o índice — apenas performance */ }

    if ($data_de === '' || $data_ate === '') {
        $titulo_tabela = 'Informe a Data Inicial e a Data Final para gerar este relatório';
    } else {
        $where = ['1=1']; $params = []; $types = '';
        if ($filtro_status === 'ativos')       { $where[] = 'h.ativo = 1'; }
        elseif ($filtro_status === 'inativos') { $where[] = 'h.ativo = 0'; }
        if ($filtro_unidade !== '') { $where[] = 'h.unidade = ?'; $params[] = $filtro_unidade; $types .= 's'; }
        $where_sql = implode(' AND ', $where);

        // Subconsultas correlacionadas com ORDER BY ... LIMIT 1 (em vez de JOIN
        // por MAX(data_leitura)) para garantir exatamente 1 linha por hidrômetro
        // mesmo quando existem leituras com o mesmo timestamp.
        $sql = "SELECT h.unidade, h.numero_hidrometro, m.nome AS morador_nome,
                       (SELECT la.leitura_atual FROM leituras la WHERE la.hidrometro_id = h.id AND DATE(la.data_leitura) < ?
                        ORDER BY la.data_leitura DESC, la.id DESC LIMIT 1) AS leitura_anterior,
                       (SELECT la.data_leitura FROM leituras la WHERE la.hidrometro_id = h.id AND DATE(la.data_leitura) < ?
                        ORDER BY la.data_leitura DESC, la.id DESC LIMIT 1) AS data_anterior,
                       (SELECT lb.leitura_atual FROM leituras lb WHERE lb.hidrometro_id = h.id AND DATE(lb.data_leitura) BETWEEN ? AND ?
                        ORDER BY lb.data_leitura DESC, lb.id DESC LIMIT 1) AS leitura_atual,
                       (SELECT lb.data_leitura FROM leituras lb WHERE lb.hidrometro_id = h.id AND DATE(lb.data_leitura) BETWEEN ? AND ?
                        ORDER BY lb.data_leitura DESC, lb.id DESC LIMIT 1) AS data_atual,
                       (SELECT AVG(l4.consumo) FROM leituras l4
                        WHERE l4.hidrometro_id = h.id AND DATE(l4.data_leitura) < ?) AS consumo_medio_historico
                FROM hidrometros h
                LEFT JOIN moradores m ON h.morador_id = m.id
                WHERE $where_sql";

        $paramsFinal = array_merge([$data_de, $data_de, $data_de, $data_ate, $data_de, $data_ate, $data_de], $params);
        $typesFinal  = 'sssssss' . $types;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($typesFinal, ...$paramsFinal);
        $stmt->execute();
        $res = $stmt->get_result();
        $linhas = [];
        while ($row = $res->fetch_assoc()) { $linhas[] = $row; }
        $stmt->close();

        foreach ($linhas as &$l) {
            $temAnterior = $l['leitura_anterior'] !== null;
            $temAtual    = $l['leitura_atual']    !== null;
            $l['leitura_anterior'] = $temAnterior ? round(floatval($l['leitura_anterior']), 2) : null;
            $l['leitura_atual']    = $temAtual    ? round(floatval($l['leitura_atual']), 2)    : null;
            $l['data_anterior_fmt'] = ($temAnterior && $l['data_anterior']) ? date('d/m/Y', strtotime($l['data_anterior'])) : null;
            $l['data_atual_fmt']    = ($temAtual && $l['data_atual'])       ? date('d/m/Y', strtotime($l['data_atual']))    : null;

            if (!$temAnterior || !$temAtual) {
                $l['consumo'] = null; $l['valor'] = null;
                $l['situacao'] = 'zero';
                $l['situacao_label'] = !$temAnterior ? 'Sem leitura anterior' : 'Sem leitura no período';
                continue;
            }

            $consumo = max(0, $l['leitura_atual'] - $l['leitura_anterior']);
            $valor   = ($consumo <= REL_ANALITICO_CONSUMO_MINIMO_PDF) ? REL_ANALITICO_VALOR_MINIMO_PDF : ($consumo * REL_ANALITICO_VALOR_M3_PDF);
            $l['consumo'] = round($consumo, 2);
            $l['valor']   = round($valor, 2);

            $media = $l['consumo_medio_historico'] !== null ? floatval($l['consumo_medio_historico']) : null;
            if ($media === null || $media <= 0) {
                $l['situacao'] = 'normal'; $l['situacao_label'] = 'Normal';
            } else {
                $osc = (($consumo - $media) / $media) * 100;
                if ($osc >= 50) { $l['situacao'] = 'alto'; $l['situacao_label'] = 'Consumo Muito Alto'; }
                elseif ($osc >= 20) { $l['situacao'] = 'moderado'; $l['situacao_label'] = 'Consumo Alto'; }
                else { $l['situacao'] = 'normal'; $l['situacao_label'] = 'Normal'; }
            }
        }
        unset($l);

        switch ($filtro_ordenacao) {
            case 'maior_consumo': usort($linhas, fn($a, $b) => ($b['consumo'] ?? -1) <=> ($a['consumo'] ?? -1)); break;
            case 'menor_consumo': usort($linhas, fn($a, $b) => ($a['consumo'] ?? PHP_INT_MAX) <=> ($b['consumo'] ?? PHP_INT_MAX)); break;
            case 'maior_valor':   usort($linhas, fn($a, $b) => ($b['valor'] ?? -1) <=> ($a['valor'] ?? -1)); break;
            case 'data_leitura':  usort($linhas, fn($a, $b) => strcmp($b['data_atual'] ?? '', $a['data_atual'] ?? '')); break;
            default:              usort($linhas, fn($a, $b) => _compararUnidadesNaturalPdf($a['unidade'], $b['unidade']));
        }

        $comConsumo = array_values(array_filter($linhas, fn($l) => $l['consumo'] !== null));
        $consumoTotal = array_sum(array_map(fn($l) => $l['consumo'], $comConsumo));
        $valorTotal   = array_sum(array_map(fn($l) => $l['valor'], $comConsumo));
        $mediaPorUnidade = count($comConsumo) > 0 ? $consumoTotal / count($comConsumo) : 0;

        $kpis = [
            ['valor' => count($linhas), 'label' => 'Total de Unidades'],
            ['valor' => fmtM3($consumoTotal), 'label' => 'Consumo Total'],
            ['valor' => fmtRS($valorTotal), 'label' => 'Valor Total'],
            ['valor' => fmtM3($mediaPorUnidade), 'label' => 'Média por Unidade'],
        ];
        $titulo_tabela = count($linhas) . ' unidade(s) no período filtrado';

        $corSituacao = ['normal' => '#16a34a', 'moderado' => '#b45309', 'alto' => '#b91c1c', 'zero' => '#64748b'];
        $emojiSituacao = ['normal' => '&#128994;', 'moderado' => '&#128993;', 'alto' => '&#128308;', 'zero' => '&#9898;'];
        foreach ($linhas as $h) {
            $situacaoHtml = '<span style="color:' . $corSituacao[$h['situacao']] . ';font-weight:600;">'
                . $emojiSituacao[$h['situacao']] . ' ' . esc($h['situacao_label']) . '</span>';
            $linhas_tabela[] = [
                esc($h['unidade']), esc($h['morador_nome'] ?: '—'), esc($h['numero_hidrometro']),
                $h['leitura_anterior'] !== null ? fmtM3($h['leitura_anterior']) : '—',
                esc($h['data_anterior_fmt'] ?: '—'),
                $h['leitura_atual'] !== null ? fmtM3($h['leitura_atual']) : '—',
                esc($h['data_atual_fmt'] ?: '—'),
                $h['consumo'] !== null ? fmtM3($h['consumo']) : '—',
                $h['valor'] !== null ? fmtRS($h['valor']) : '—',
                $situacaoHtml,
            ];
        }

        $graficoUnidadeOrdenado = $comConsumo;
        usort($graficoUnidadeOrdenado, fn($a, $b) => _compararUnidadesNaturalPdf($a['unidade'], $b['unidade']));
        if (count($graficoUnidadeOrdenado) > 0) {
            $grafico = ['labels' => array_map(fn($l) => $l['unidade'], $graficoUnidadeOrdenado),
                        'label1' => 'Consumo (m³)', 'dados1' => array_map(fn($l) => $l['consumo'], $graficoUnidadeOrdenado),
                        'tipo' => 'bar'];
        }
    }
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatório <?= esc($titulo_relatorio) ?> — <?= esc($nome_empresa) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #1a1a2e; background: #f0f4f8; }

.btn-print {
    position: fixed; top: 16px; right: 16px; z-index: 9999;
    background: linear-gradient(135deg, #1e3a8a, #2563eb);
    color: #fff; border: none; border-radius: 8px;
    padding: 10px 22px; font-size: 13px; font-weight: 600;
    cursor: pointer; box-shadow: 0 4px 12px rgba(37,99,235,.4);
    display: flex; align-items: center; gap: 8px;
}
.btn-print:hover { transform: translateY(-1px); }

.relatorio {
    max-width: 1100px; margin: 20px auto; background: #fff;
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 8px 32px rgba(30,58,138,.12);
}

.header {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 60%, #3b82f6 100%);
    padding: 24px 32px; display: flex; align-items: center; gap: 20px; color: #fff;
}
.header-logo { width: 72px; height: 72px; border-radius: 10px; object-fit: contain; background: #fff; padding: 4px; flex-shrink: 0; }
.header-info { flex: 1; }
.header-info h1 { font-size: 18px; font-weight: 700; }
.header-info p  { font-size: 11px; opacity: .85; margin-top: 2px; }
.header-meta { text-align: right; font-size: 10px; opacity: .8; line-height: 1.7; }
.header-meta strong { font-size: 13px; opacity: 1; display: block; margin-bottom: 2px; }

.titulo-relatorio {
    background: #1e3a8a; color: #fff;
    padding: 10px 32px; font-size: 13px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px;
}
.titulo-relatorio .filtro-info { font-size: 10px; font-weight: 400; opacity: .8; text-transform: none; letter-spacing: 0; }

.kpis { display: grid; grid-template-columns: repeat(4, 1fr); border-bottom: 2px solid #e2e8f0; }
.kpi { padding: 14px 12px; text-align: center; border-right: 1px solid #e2e8f0; }
.kpi:last-child { border-right: none; }
.kpi-valor { font-size: 20px; font-weight: 800; color: #1e3a8a; line-height: 1.2; }
.kpi-label { font-size: 9px; text-transform: uppercase; letter-spacing: .8px; color: #64748b; margin-top: 4px; }
.kpi-valor.alerta-zero { color: #475569; }
.kpi-valor.alerta-moderado { color: #b45309; }
.kpi-valor.alerta-alto { color: #b91c1c; }
.kpi-valor.alerta-vazio { color: #9a3412; }

.secao { padding: 0 24px 24px; }
.secao-titulo {
    font-size: 12px; font-weight: 700; color: #1e3a8a;
    text-transform: uppercase; letter-spacing: .8px;
    padding: 16px 0 10px; border-bottom: 2px solid #2563eb; margin-bottom: 12px;
    display: flex; align-items: center; gap: 8px;
}
.secao-titulo::before {
    content: ''; display: inline-block; width: 4px; height: 16px;
    background: linear-gradient(180deg, #2563eb, #1e3a8a); border-radius: 2px;
}

.grafico-wrap { padding: 20px 24px 0; }
.grafico-wrap canvas { max-height: 280px; }

table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
thead tr { background: linear-gradient(90deg, #1e3a8a, #2563eb); color: #fff; }
thead th { padding: 8px 6px; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; }
tbody tr { border-bottom: 1px solid #f1f5f9; }
tbody tr:nth-child(even) { background: #f8fafc; }
tbody td { padding: 6px 6px; vertical-align: middle; }

.sem-dados { text-align: center; padding: 24px; color: #94a3b8; font-style: italic; }

.rodape {
    background: #1e3a8a; color: rgba(255,255,255,.75);
    padding: 12px 32px; font-size: 9px;
    display: flex; justify-content: space-between; align-items: center;
}
.rodape strong { color: #fff; }

@media print {
    html, body { background: #fff; }
    .btn-print { display: none !important; }
    .relatorio { box-shadow: none; border-radius: 0; margin: 0; max-width: 100%; }
    @page {
        margin: 8mm 6mm 14mm;
        size: A4 landscape;
        @bottom-center {
            content: "Página " counter(page) " de " counter(pages);
            font-size: 9px;
            color: #64748b;
        }
    }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
}
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">&#128438; Imprimir / Salvar PDF</button>

<div class="relatorio">

    <div class="header">
        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-info">
            <h1>ERP Condomínios</h1>
            <p><?= esc($nome_empresa) ?> — CNPJ: <?= esc($cnpj_empresa) ?></p>
            <p>Relatório: <?= esc($titulo_relatorio) ?></p>
        </div>
        <div class="header-meta">
            <strong><?= esc($titulo_relatorio) ?></strong>
            Emitido em: <?= $data_geracao ?><br>
            Usuário: <?= esc($operador_nome) ?><br>
            <?= esc($periodo_txt) ?> | <?= esc($unidade_txt) ?>
        </div>
    </div>

    <div class="titulo-relatorio">
        <span><?= $subtitulo ?></span>
        <span class="filtro-info"><?= esc($periodo_txt . ' | ' . $unidade_txt) ?></span>
    </div>

    <?php if ($tipo === 'alertas' && $resumo_alertas !== null): ?>
    <div class="kpis">
        <div class="kpi"><div class="kpi-valor alerta-zero"><?= $resumo_alertas['zero'] ?></div><div class="kpi-label">Consumo Zero</div></div>
        <div class="kpi"><div class="kpi-valor alerta-moderado"><?= $resumo_alertas['moderado'] ?></div><div class="kpi-label">Oscilação Moderada</div></div>
        <div class="kpi"><div class="kpi-valor alerta-alto"><?= $resumo_alertas['alto'] ?></div><div class="kpi-label">Oscilação Alta</div></div>
        <div class="kpi"><div class="kpi-valor alerta-vazio"><?= $resumo_alertas['vazio'] ?></div><div class="kpi-label">Possível Imóvel Vazio</div></div>
    </div>
    <?php elseif (!empty($kpis)): ?>
    <div class="kpis">
        <?php foreach ($kpis as $k): ?>
        <div class="kpi"><div class="kpi-valor"><?= $k['valor'] ?></div><div class="kpi-label"><?= esc($k['label']) ?></div></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($grafico !== null): ?>
    <div class="grafico-wrap">
        <canvas id="graficoRelatorio"></canvas>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
    (function () {
        var datasets = [{
            type: '<?= $grafico['tipo'] === 'line' ? 'line' : 'bar' ?>',
            label: <?= json_encode($grafico['label1']) ?>,
            data: <?= json_encode($grafico['dados1']) ?>,
            backgroundColor: 'rgba(37,99,235,0.7)',
            borderColor: '#1e3a8a',
            borderWidth: 2,
            borderRadius: 4,
            tension: 0.3,
            yAxisID: 'y'
        }];
        <?php if (!empty($grafico['dados2'])): ?>
        datasets.push({
            type: 'line',
            label: <?= json_encode($grafico['label2']) ?>,
            data: <?= json_encode($grafico['dados2']) ?>,
            borderColor: '#16a34a',
            backgroundColor: 'rgba(22,163,74,0.2)',
            borderWidth: 2,
            tension: 0.3,
            yAxisID: 'y1'
        });
        <?php endif; ?>
        new Chart(document.getElementById('graficoRelatorio'), {
            data: { labels: <?= json_encode($grafico['labels']) ?>, datasets: datasets },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y:  { type: 'linear', position: 'left', beginAtZero: true },
                    <?php if (!empty($grafico['dados2'])): ?>
                    y1: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } },
                    <?php endif; ?>
                },
            },
        });
    })();
    </script>
    <?php endif; ?>

    <div class="secao">
        <div class="secao-titulo"><?= esc($titulo_tabela) ?></div>
        <table>
            <thead>
                <tr><?php foreach ($colunas as $c): ?><th><?= esc($c) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php if (empty($linhas_tabela)): ?>
                <tr><td colspan="<?= $colspan_vazio ?>" class="sem-dados"><?= esc($msg_vazio) ?></td></tr>
            <?php else: ?>
                <?php foreach ($linhas_tabela as $linha): ?>
                <tr><?php foreach ($linha as $celula): ?><td><?= $celula ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($colunas2 !== null && $linhas2 !== null): ?>
    <div class="secao">
        <div class="secao-titulo"><?= esc($titulo_tabela2) ?></div>
        <table>
            <thead>
                <tr><?php foreach ($colunas2 as $c): ?><th><?= esc($c) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php if (empty($linhas2)): ?>
                <tr><td colspan="<?= count($colunas2) ?>" class="sem-dados">Nenhum registro encontrado</td></tr>
            <?php else: ?>
                <?php foreach ($linhas2 as $linha): ?>
                <tr><?php foreach ($linha as $celula): ?><td><?= $celula ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="rodape">
        <span>Documento emitido pelo <strong>ERP Condomínios</strong></span>
        <span><?= esc($periodo_txt) ?> | <?= esc($unidade_txt) ?></span>
    </div>

</div>

<?php if ($auto_print): ?>
<script>window.addEventListener('load', function() { setTimeout(function() { window.print(); }, 600); });</script>
<?php endif; ?>

</body>
</html>
