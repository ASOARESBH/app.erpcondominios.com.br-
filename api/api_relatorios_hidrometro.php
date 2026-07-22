<?php
// =====================================================
// API DE RELATÓRIOS GERENCIAIS — HIDRÔMETROS
// =====================================================
// Complementa api_leituras.php?relatorio=1 (Relatório Geral de Consumo,
// que continua servindo o tipo "geral" sem alterações). Este arquivo cobre
// os 6 novos tipos de análise do módulo Hidrômetros > Relatórios:
//   evolucao | alertas | inativos | ranking | financeiro | unidade
//
// Nao altera banco de dados, arquitetura MVC nem regras de negocio
// existentes — apenas consultas de leitura (SELECT) sobre as tabelas
// já existentes (leituras, hidrometros, hidrometros_historico, moradores).

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array('sucesso' => $sucesso, 'mensagem' => $mensagem);
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$conexao = conectar_banco();
$tenant_id = exigirTenantId();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    retornar_json(false, 'Método não suportado');
}

// ========== ORDENAÇÃO NATURAL DE UNIDADES ==========
// Mesma regra usada em api_leituras.php e no restante do módulo:
// Administrativo primeiro, depois ordem numérica (Gleba 1, 2 ... 10, 11).
function _compararUnidadesNaturalRel($a, $b) {
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

$tipo = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';

// Filtros comuns (cada tipo usa o subconjunto que faz sentido)
$data_de  = isset($_GET['data_de'])  ? trim($_GET['data_de'])  : '';
$data_ate = isset($_GET['data_ate']) ? trim($_GET['data_ate']) : '';
$unidade  = isset($_GET['unidade'])  ? trim($_GET['unidade'])  : '';
$motivo   = isset($_GET['motivo'])   ? trim($_GET['motivo'])   : '';

// =====================================================================
// EVOLUÇÃO DE CONSUMO — comparativo mensal
// =====================================================================
if ($tipo === 'evolucao') {
    $where  = ['1=1'];
    $params = [];
    $tipos  = '';

    if ($data_de !== '')  { $where[] = 'DATE(l.data_leitura) >= ?'; $params[] = $data_de;  $tipos .= 's'; }
    if ($data_ate !== '') { $where[] = 'DATE(l.data_leitura) <= ?'; $params[] = $data_ate; $tipos .= 's'; }
    if ($unidade !== '')  { $where[] = 'l.unidade = ?';             $params[] = $unidade;  $tipos .= 's'; }
    $where_sql = implode(' AND ', $where);

    // Série mensal (para o gráfico)
    $sql_mensal = "SELECT DATE_FORMAT(l.data_leitura, '%Y-%m') as mes_key,
                          DATE_FORMAT(l.data_leitura, '%m/%Y') as mes_label,
                          COUNT(*) as leituras,
                          SUM(l.consumo) as consumo_total,
                          SUM(l.valor_total) as valor_total
                   FROM leituras l WHERE tenant_id = $tenant_id AND $where_sql
                   GROUP BY mes_key
                   ORDER BY mes_key ASC";

    $mensal = [];
    if ($params) {
        $stmt = $conexao->prepare($sql_mensal);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $mensal[] = $row; }
        $stmt->close();
    } else {
        $res = $conexao->query($sql_mensal);
        while ($row = $res->fetch_assoc()) { $mensal[] = $row; }
    }

    // Tabela detalhada: mês x unidade
    $sql_detalhado = "SELECT DATE_FORMAT(l.data_leitura, '%Y-%m') as mes_key,
                              DATE_FORMAT(l.data_leitura, '%m/%Y') as mes_label,
                              l.unidade,
                              SUM(l.consumo) as consumo_total,
                              SUM(l.valor_total) as valor_total
                       FROM leituras l WHERE tenant_id = $tenant_id AND $where_sql
                       GROUP BY mes_key, l.unidade
                       ORDER BY mes_key ASC";

    $detalhado = [];
    if ($params) {
        $stmt = $conexao->prepare($sql_detalhado);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $detalhado[] = $row; }
        $stmt->close();
    } else {
        $res = $conexao->query($sql_detalhado);
        while ($row = $res->fetch_assoc()) { $detalhado[] = $row; }
    }
    // Desempate por unidade em ordem natural dentro do mesmo mês
    usort($detalhado, function ($a, $b) {
        if ($a['mes_key'] !== $b['mes_key']) return strcmp($a['mes_key'], $b['mes_key']);
        return _compararUnidadesNaturalRel($a['unidade'], $b['unidade']);
    });

    retornar_json(true, 'Evolução de consumo gerada com sucesso', [
        'mensal'     => $mensal,
        'detalhado'  => $detalhado,
    ]);
}

// =====================================================================
// ALERTAS DE CONSUMO — detecção automática de consumos suspeitos
// =====================================================================
// Regra (documentada — não existe regra prévia no sistema, esta é nova):
//   - Média histórica de cada hidrômetro = média do consumo das leituras
//     dentro do período filtrado, EXCLUINDO a leitura mais recente (que é
//     a que está sendo avaliada quanto à oscilação).
//   - "Sem consumo": a leitura mais recente do período tem consumo = 0,
//     ou o hidrômetro não teve nenhuma leitura no período (usa a última
//     leitura já registrada em qualquer época como referência).
//   - Oscilação: (última - média) / média × 100
//       >= 20%  → Oscilação Alta (vermelho)
//       10–20%  → Oscilação Moderada (amarelo)
//   - Última < 30% da média → Possível imóvel vazio.
if ($tipo === 'alertas') {
    $where_h = ['h.ativo = 1'];
    $params_h = [];
    $tipos_h = '';
    if ($unidade !== '') { $where_h[] = 'h.unidade = ?'; $params_h[] = $unidade; $tipos_h .= 's'; }
    $where_h_sql = implode(' AND ', $where_h);

    // Hidrômetros ativos + última leitura já registrada (qualquer época) como fallback
    $sql_hidros = "SELECT h.id, h.unidade, h.numero_hidrometro, h.data_instalacao,
                          m.nome as morador_nome,
                          (SELECT l2.consumo FROM leituras l2 WHERE tenant_id = $tenant_id AND l2.hidrometro_id = h.id ORDER BY l2.data_leitura DESC LIMIT 1) as ultima_consumo_geral,
                          (SELECT DATE_FORMAT(l3.data_leitura, '%d/%m/%Y') FROM leituras l3 WHERE tenant_id = $tenant_id AND l3.hidrometro_id = h.id ORDER BY l3.data_leitura DESC LIMIT 1) as ultima_data_geral
                   FROM hidrometros h
                   LEFT JOIN moradores m ON h.morador_id = m.id
                   WHERE $where_h_sql";

    $hidrometros = [];
    if ($params_h) {
        $stmt = $conexao->prepare($sql_hidros);
        $stmt->bind_param($tipos_h, ...$params_h);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $hidrometros[$row['id']] = $row; }
        $stmt->close();
    } else {
        $res = $conexao->query($sql_hidros);
        while ($row = $res->fetch_assoc()) { $hidrometros[$row['id']] = $row; }
    }

    if (empty($hidrometros)) {
        retornar_json(true, 'Nenhum hidrômetro ativo encontrado', ['resumo' => ['zero' => 0, 'moderado' => 0, 'alto' => 0, 'vazio' => 0, 'total' => 0], 'alertas' => []]);
    }

    $ids = array_keys($hidrometros);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $where_l = ["l.hidrometro_id IN ($placeholders)"];
    $params_l = $ids;
    $tipos_l = str_repeat('i', count($ids));
    if ($data_de !== '')  { $where_l[] = 'DATE(l.data_leitura) >= ?'; $params_l[] = $data_de;  $tipos_l .= 's'; }
    if ($data_ate !== '') { $where_l[] = 'DATE(l.data_leitura) <= ?'; $params_l[] = $data_ate; $tipos_l .= 's'; }
    $where_l_sql = implode(' AND ', $where_l);

    $sql_leituras = "SELECT l.hidrometro_id, l.consumo, DATE_FORMAT(l.data_leitura, '%d/%m/%Y') as data_fmt, l.data_leitura
                      FROM leituras l WHERE tenant_id = $tenant_id AND $where_l_sql
                      ORDER BY l.hidrometro_id ASC, l.data_leitura ASC";
    $stmt = $conexao->prepare($sql_leituras);
    $stmt->bind_param($tipos_l, ...$params_l);
    $stmt->execute();
    $res = $stmt->get_result();

    $leiturasPorHidro = [];
    while ($row = $res->fetch_assoc()) {
        $leiturasPorHidro[$row['hidrometro_id']][] = $row;
    }
    $stmt->close();

    $alertas = [];
    $resumo = ['zero' => 0, 'moderado' => 0, 'alto' => 0, 'vazio' => 0];

    foreach ($hidrometros as $id => $h) {
        $leituras = $leiturasPorHidro[$id] ?? [];
        $n = count($leituras);

        $categoria = null;
        $consumoAtual = null;
        $consumoMedio = null;
        $oscilacaoPct = null;
        $ultimaData = null;
        $diasSemConsumo = null;
        $mensagem = '';

        if ($n === 0) {
            // Nenhuma leitura no período filtrado: usa a última leitura já registrada (se houver)
            $ultimaData = $h['ultima_data_geral'];
            $consumoAtual = $h['ultima_consumo_geral'] !== null ? floatval($h['ultima_consumo_geral']) : null;
            $baseData = $h['ultima_data_geral']
                ? DateTime::createFromFormat('d/m/Y', $h['ultima_data_geral'])
                : DateTime::createFromFormat('Y-m-d H:i:s', $h['data_instalacao']);
            $diasSemConsumo = $baseData ? (new DateTime())->diff($baseData)->days : null;
            if ($consumoAtual === null || $consumoAtual == 0) {
                $categoria = 'zero';
            }
        } else {
            $ultima = $leituras[$n - 1];
            $consumoAtual = floatval($ultima['consumo']);
            $ultimaData = $ultima['data_fmt'];

            if ($consumoAtual == 0) {
                $categoria = 'zero';
                $baseData = DateTime::createFromFormat('d/m/Y', $ultimaData);
                $diasSemConsumo = $baseData ? (new DateTime())->diff($baseData)->days : null;
            } elseif ($n >= 2) {
                $anteriores = array_slice($leituras, 0, $n - 1);
                $somaAnt = array_sum(array_map(fn($l) => floatval($l['consumo']), $anteriores));
                $consumoMedio = $somaAnt / count($anteriores);

                if ($consumoMedio > 0) {
                    $oscilacaoPct = (($consumoAtual - $consumoMedio) / $consumoMedio) * 100;

                    if ($consumoAtual < $consumoMedio * 0.30) {
                        $categoria = 'vazio';
                        $mensagem = 'Consumo muito abaixo da média histórica — possível imóvel vazio.';
                    } elseif ($oscilacaoPct >= 20) {
                        $categoria = 'alto';
                        $mensagem = 'Possível: vazamento, furto de água, erro de leitura ou mudança de consumo.';
                    } elseif ($oscilacaoPct >= 10) {
                        $categoria = 'moderado';
                        $mensagem = 'Oscilação acima do padrão histórico — acompanhar na próxima leitura.';
                    }
                }
            }
        }

        if ($categoria !== null) {
            $resumo[$categoria]++;
            $alertas[] = [
                'hidrometro_id'     => (int) $id,
                'unidade'           => $h['unidade'],
                'morador_nome'      => $h['morador_nome'],
                'numero_hidrometro' => $h['numero_hidrometro'],
                'categoria'         => $categoria,
                'consumo_medio'     => $consumoMedio !== null ? round($consumoMedio, 2) : null,
                'consumo_atual'     => $consumoAtual !== null ? round($consumoAtual, 2) : null,
                'oscilacao_pct'     => $oscilacaoPct !== null ? round($oscilacaoPct, 1) : null,
                'ultima_leitura'    => $ultimaData,
                'dias_sem_consumo'  => $diasSemConsumo,
                'mensagem'          => $mensagem,
            ];
        }
    }

    // Mais severo primeiro: Alta > Vazio > Moderada > Zero; empate por unidade (ordem natural)
    $ordemSeveridade = ['alto' => 0, 'vazio' => 1, 'moderado' => 2, 'zero' => 3];
    usort($alertas, function ($a, $b) use ($ordemSeveridade) {
        $sa = $ordemSeveridade[$a['categoria']];
        $sb = $ordemSeveridade[$b['categoria']];
        if ($sa !== $sb) return $sa <=> $sb;
        return _compararUnidadesNaturalRel($a['unidade'], $b['unidade']);
    });

    $resumo['total'] = $resumo['zero'] + $resumo['moderado'] + $resumo['alto'] + $resumo['vazio'];

    retornar_json(true, 'Análise de alertas concluída', ['resumo' => $resumo, 'alertas' => $alertas]);
}

// =====================================================================
// HISTÓRICO DE HIDRÔMETROS INATIVOS
// =====================================================================
if ($tipo === 'inativos') {
    $where  = ['h.ativo = 0'];
    $params = [];
    $tipos  = '';

    if ($unidade !== '') { $where[] = 'h.unidade = ?'; $params[] = $unidade; $tipos .= 's'; }
    if ($data_de !== '')  { $where[] = 'DATE(inat.data_alteracao) >= ?'; $params[] = $data_de;  $tipos .= 's'; }
    if ($data_ate !== '') { $where[] = 'DATE(inat.data_alteracao) <= ?'; $params[] = $data_ate; $tipos .= 's'; }
    if ($motivo !== '')   { $where[] = 'inat.observacao LIKE ?'; $params[] = '%' . $motivo . '%'; $tipos .= 's'; }
    $where_sql = implode(' AND ', $where);

    // Evento de inativação mais recente de cada hidrômetro (histórico já existente — sem nova tabela)
    $sql = "SELECT h.id, h.unidade, m.nome as morador_nome, h.numero_hidrometro,
                   h.data_instalacao,
                   DATE_FORMAT(h.data_instalacao, '%d/%m/%Y') as data_instalacao_fmt,
                   inat.data_alteracao as data_inativacao,
                   DATE_FORMAT(inat.data_alteracao, '%d/%m/%Y') as data_inativacao_fmt,
                   inat.observacao as motivo,
                   (SELECT leitura_atual FROM leituras WHERE tenant_id = $tenant_id AND hidrometro_id = h.id ORDER BY data_leitura DESC LIMIT 1) as ultima_leitura
            FROM hidrometros h
            LEFT JOIN moradores m ON h.morador_id = m.id
            LEFT JOIN (
                SELECT hh1.hidrometro_id, hh1.data_alteracao, hh1.observacao
                FROM hidrometros_historico hh1
                INNER JOIN (
                    SELECT hidrometro_id, MAX(data_alteracao) as max_data
                    FROM hidrometros_historico WHERE tenant_id = $tenant_id AND campo_alterado = 'ativo' AND valor_novo = '0'
                    GROUP BY hidrometro_id
                ) ult ON ult.hidrometro_id = hh1.hidrometro_id AND ult.max_data = hh1.data_alteracao
                WHERE hh1.campo_alterado = 'ativo' AND hh1.valor_novo = '0'
            ) inat ON inat.hidrometro_id = h.id
            WHERE $where_sql";

    $linhas = [];
    if ($params) {
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $linhas[] = $row; }
        $stmt->close();
    } else {
        $res = $conexao->query($sql);
        while ($row = $res->fetch_assoc()) { $linhas[] = $row; }
    }

    foreach ($linhas as &$l) {
        if ($l['data_inativacao']) {
            $inst = DateTime::createFromFormat('Y-m-d H:i:s', $l['data_instalacao']);
            $inat = DateTime::createFromFormat('Y-m-d H:i:s', $l['data_inativacao']);
            $l['tempo_operacao'] = ($inst && $inat) ? $inst->diff($inat)->days . ' dias' : '—';
        } else {
            $l['tempo_operacao'] = '—';
        }
        unset($l['data_instalacao'], $l['data_inativacao']);
    }
    unset($l);

    usort($linhas, fn($a, $b) => _compararUnidadesNaturalRel($a['unidade'], $b['unidade']));

    retornar_json(true, 'Hidrômetros inativos listados com sucesso', $linhas);
}

// =====================================================================
// RANKING DE CONSUMO — maiores e menores consumidores
// =====================================================================
if ($tipo === 'ranking') {
    $where  = ['1=1'];
    $params = [];
    $tipos  = '';
    if ($data_de !== '')  { $where[] = 'DATE(l.data_leitura) >= ?'; $params[] = $data_de;  $tipos .= 's'; }
    if ($data_ate !== '') { $where[] = 'DATE(l.data_leitura) <= ?'; $params[] = $data_ate; $tipos .= 's'; }
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT l.unidade, m.nome as morador_nome, h.numero_hidrometro,
                   SUM(l.consumo) as consumo_total, SUM(l.valor_total) as valor_total, COUNT(*) as leituras
            FROM leituras l
            INNER JOIN hidrometros h ON l.hidrometro_id = h.id
            INNER JOIN moradores m ON l.morador_id = m.id
            WHERE $where_sql
            GROUP BY l.hidrometro_id
            ORDER BY consumo_total DESC";

    $linhas = [];
    if ($params) {
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $linhas[] = $row; }
        $stmt->close();
    } else {
        $res = $conexao->query($sql);
        while ($row = $res->fetch_assoc()) { $linhas[] = $row; }
    }

    $maiores = array_slice($linhas, 0, 10);
    $menores = array_slice(array_reverse($linhas), 0, 10);

    retornar_json(true, 'Ranking de consumo gerado com sucesso', [
        'maiores' => $maiores,
        'menores' => $menores,
    ]);
}

// =====================================================================
// RELATÓRIO FINANCEIRO DA ÁGUA
// =====================================================================
if ($tipo === 'financeiro') {
    $where  = ['1=1'];
    $params = [];
    $tipos  = '';
    if ($data_de !== '')  { $where[] = 'DATE(l.data_leitura) >= ?'; $params[] = $data_de;  $tipos .= 's'; }
    if ($data_ate !== '') { $where[] = 'DATE(l.data_leitura) <= ?'; $params[] = $data_ate; $tipos .= 's'; }
    if ($unidade !== '')  { $where[] = 'l.unidade = ?';             $params[] = $unidade;  $tipos .= 's'; }
    $where_sql = implode(' AND ', $where);

    // Totais gerais
    $sql_totais = "SELECT COUNT(*) as total_leituras, COALESCE(SUM(l.consumo),0) as consumo_total,
                          COALESCE(SUM(l.valor_total),0) as valor_total,
                          COUNT(DISTINCT l.unidade) as unidades_distintas
                   FROM leituras l WHERE tenant_id = $tenant_id AND $where_sql";
    if ($params) {
        $stmt = $conexao->prepare($sql_totais);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $totais = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $totais = $conexao->query($sql_totais)->fetch_assoc();
    }

    $consumoTotal = floatval($totais['consumo_total']);
    $valorTotal   = floatval($totais['valor_total']);
    $unidadesQtd  = intval($totais['unidades_distintas']);

    $resumo = [
        'total_leituras'        => intval($totais['total_leituras']),
        'consumo_total'         => round($consumoTotal, 2),
        'valor_cobrado'         => round($valorTotal, 2),
        'receita_gerada'        => round($valorTotal, 2),
        'valor_medio_unidade'   => $unidadesQtd > 0 ? round($valorTotal / $unidadesQtd, 2) : 0,
        'valor_medio_m3'        => $consumoTotal > 0 ? round($valorTotal / $consumoTotal, 2) : 0,
    ];

    // Série mensal para o gráfico Consumo × Receita
    $sql_mensal = "SELECT DATE_FORMAT(l.data_leitura, '%Y-%m') as mes_key,
                          DATE_FORMAT(l.data_leitura, '%m/%Y') as mes_label,
                          SUM(l.consumo) as consumo_total, SUM(l.valor_total) as valor_total
                   FROM leituras l WHERE tenant_id = $tenant_id AND $where_sql
                   GROUP BY mes_key ORDER BY mes_key ASC";
    $mensal = [];
    if ($params) {
        $stmt = $conexao->prepare($sql_mensal);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $mensal[] = $row; }
        $stmt->close();
    } else {
        $res = $conexao->query($sql_mensal);
        while ($row = $res->fetch_assoc()) { $mensal[] = $row; }
    }

    // Tabela por unidade/hidrômetro (mesma lógica de agrupamento do relatório geral)
    $sql_tabela = "SELECT l.unidade, m.nome as morador_nome, h.numero_hidrometro,
                          COUNT(*) as leituras, SUM(l.consumo) as consumo_total, SUM(l.valor_total) as valor_total
                   FROM leituras l
                   INNER JOIN hidrometros h ON l.hidrometro_id = h.id
                   INNER JOIN moradores m ON l.morador_id = m.id
                   WHERE $where_sql
                   GROUP BY l.hidrometro_id";
    $tabela = [];
    if ($params) {
        $stmt = $conexao->prepare($sql_tabela);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $tabela[] = $row; }
        $stmt->close();
    } else {
        $res = $conexao->query($sql_tabela);
        while ($row = $res->fetch_assoc()) { $tabela[] = $row; }
    }
    usort($tabela, fn($a, $b) => _compararUnidadesNaturalRel($a['unidade'], $b['unidade']));

    retornar_json(true, 'Relatório financeiro gerado com sucesso', [
        'resumo'  => $resumo,
        'mensal'  => $mensal,
        'tabela'  => $tabela,
    ]);
}

// =====================================================================
// HISTÓRICO COMPLETO POR UNIDADE
// =====================================================================
if ($tipo === 'unidade') {
    if ($unidade === '') {
        retornar_json(false, 'Selecione uma unidade para gerar este relatório.');
    }

    $where  = ['l.unidade = ?'];
    $params = [$unidade];
    $tipos  = 's';
    if ($data_de !== '')  { $where[] = 'DATE(l.data_leitura) >= ?'; $params[] = $data_de;  $tipos .= 's'; }
    if ($data_ate !== '') { $where[] = 'DATE(l.data_leitura) <= ?'; $params[] = $data_ate; $tipos .= 's'; }
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT DATE_FORMAT(l.data_leitura, '%d/%m/%Y') as data_fmt, l.data_leitura,
                   l.leitura_atual, l.consumo, l.valor_total, m.nome as morador_nome, h.numero_hidrometro
            FROM leituras l
            INNER JOIN hidrometros h ON l.hidrometro_id = h.id
            INNER JOIN moradores m ON l.morador_id = m.id
            WHERE $where_sql
            ORDER BY l.data_leitura ASC";

    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($tipos, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $leituras = [];
    while ($row = $res->fetch_assoc()) { $leituras[] = $row; }
    $stmt->close();

    $consumos = array_map(fn($l) => floatval($l['consumo']), $leituras);
    $resumo = [
        'unidade'         => $unidade,
        'total_leituras'  => count($leituras),
        'consumo_medio'   => count($consumos) > 0 ? round(array_sum($consumos) / count($consumos), 2) : 0,
        'maior_consumo'   => count($consumos) > 0 ? round(max($consumos), 2) : 0,
        'menor_consumo'   => count($consumos) > 0 ? round(min($consumos), 2) : 0,
    ];

    retornar_json(true, 'Histórico da unidade gerado com sucesso', [
        'resumo'   => $resumo,
        'leituras' => $leituras,
    ]);
}

// =====================================================================
// RELATÓRIO DE CONSUMO ANALÍTICO — leitura anterior x leitura atual do período
// =====================================================================
// Regra (nova — não existe cálculo equivalente pronto no sistema):
//   - Leitura anterior = última leitura do hidrômetro com data anterior ao
//     período informado. Leitura atual = última leitura dentro do período
//     (a mais recente, se houver mais de uma no intervalo).
//   - Consumo = leitura_atual - leitura_anterior (valores do mostrador do
//     hidrômetro, não a coluna "consumo" já gravada em cada leitura, que
//     reflete o ciclo próprio daquela leitura individual).
//   - Valor: mesma regra de calcularValor() em api_leituras.php (consumo
//     <= 10 m³ cobra o valor mínimo fixo; acima disso, consumo × valor do
//     m³). Constantes replicadas aqui — arquivo isolado, sem acesso às
//     constantes definidas em api_leituras.php.
//   - Situação: compara o consumo do período com a média histórica de
//     consumo do hidrômetro (leituras anteriores ao período). <20% =
//     Normal, 20–50% = Alto, >=50% = Muito Alto. Sem leitura anterior OU
//     sem leitura no período = "Sem leitura" (não calcula consumo/valor).
if ($tipo === 'analitico') {
    if (!defined('REL_ANALITICO_VALOR_M3')) {
        define('REL_ANALITICO_VALOR_M3', 6.16);
        define('REL_ANALITICO_VALOR_MINIMO', 61.60);
        define('REL_ANALITICO_CONSUMO_MINIMO', 10);
    }

    if ($data_de === '' || $data_ate === '') {
        retornar_json(false, 'Informe a Data Inicial e a Data Final para gerar o relatório.');
    }

    // Índice composto para acelerar a busca da última leitura por hidrômetro
    // (criação idempotente — não bloqueia o relatório caso não seja possível criar).
    try {
        $idxCheck = $conexao->query("SELECT COUNT(1) as qtd FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leituras' AND INDEX_NAME = 'idx_hidrometro_data'");
        if ($idxCheck && ($idxRow = $idxCheck->fetch_assoc()) && intval($idxRow['qtd']) === 0) {
            $conexao->query("CREATE INDEX idx_hidrometro_data ON leituras(hidrometro_id, data_leitura)");
        }
    } catch (Throwable $e) {
        // Segue sem o índice — impacta apenas performance, não a corretude do relatório.
    }

    $status_analitico    = isset($_GET['status'])    ? trim($_GET['status'])    : '';
    $ordenacao_analitico = isset($_GET['ordenacao'])  ? trim($_GET['ordenacao']) : 'unidade';

    $where  = ['1=1'];
    $params = [];
    $types  = '';
    if ($status_analitico === 'ativos')        { $where[] = 'h.ativo = 1'; }
    elseif ($status_analitico === 'inativos')  { $where[] = 'h.ativo = 0'; }
    if ($unidade !== '') { $where[] = 'h.unidade = ?'; $params[] = $unidade; $types .= 's'; }
    $where_sql = implode(' AND ', $where);

    // Subconsultas correlacionadas com ORDER BY ... LIMIT 1 (em vez de JOIN por
    // MAX(data_leitura)) para garantir exatamente 1 linha por hidrômetro mesmo
    // quando existem leituras com o mesmo timestamp (caso real observado nos
    // dados: lançamentos coletivos gravados com data_leitura idêntica).
    $sql = "SELECT h.id AS hidrometro_id, h.unidade, h.numero_hidrometro, h.ativo,
                   m.nome AS morador_nome,
                   (SELECT la.leitura_atual FROM leituras la WHERE tenant_id = $tenant_id AND la.hidrometro_id = h.id AND DATE(la.data_leitura) < ?
                    ORDER BY la.data_leitura DESC, la.id DESC LIMIT 1) AS leitura_anterior,
                   (SELECT la.data_leitura FROM leituras la WHERE tenant_id = $tenant_id AND la.hidrometro_id = h.id AND DATE(la.data_leitura) < ?
                    ORDER BY la.data_leitura DESC, la.id DESC LIMIT 1) AS data_anterior,
                   (SELECT lb.leitura_atual FROM leituras lb WHERE tenant_id = $tenant_id AND lb.hidrometro_id = h.id AND DATE(lb.data_leitura) BETWEEN ? AND ?
                    ORDER BY lb.data_leitura DESC, lb.id DESC LIMIT 1) AS leitura_atual,
                   (SELECT lb.data_leitura FROM leituras lb WHERE tenant_id = $tenant_id AND lb.hidrometro_id = h.id AND DATE(lb.data_leitura) BETWEEN ? AND ?
                    ORDER BY lb.data_leitura DESC, lb.id DESC LIMIT 1) AS data_atual,
                   (SELECT AVG(l4.consumo) FROM leituras l4 WHERE tenant_id = $tenant_id AND l4.hidrometro_id = h.id AND DATE(l4.data_leitura) < ?) AS consumo_medio_historico
            FROM hidrometros h
            LEFT JOIN moradores m ON h.morador_id = m.id
            WHERE $where_sql";

    $paramsFinal = array_merge([$data_de, $data_de, $data_de, $data_ate, $data_de, $data_ate, $data_de], $params);
    $typesFinal  = 'sssssss' . $types;

    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($typesFinal, ...$paramsFinal);
    $stmt->execute();
    $res = $stmt->get_result();
    $linhas = [];
    while ($row = $res->fetch_assoc()) { $linhas[] = $row; }
    $stmt->close();

    foreach ($linhas as &$l) {
        $temAnterior = $l['leitura_anterior'] !== null;
        $temAtual    = $l['leitura_atual']    !== null;

        $l['leitura_anterior']  = $temAnterior ? round(floatval($l['leitura_anterior']), 2) : null;
        $l['leitura_atual']     = $temAtual    ? round(floatval($l['leitura_atual']), 2)    : null;
        $l['data_anterior_fmt'] = ($temAnterior && $l['data_anterior']) ? date('d/m/Y', strtotime($l['data_anterior'])) : null;
        $l['data_atual_fmt']    = ($temAtual && $l['data_atual'])       ? date('d/m/Y', strtotime($l['data_atual']))    : null;

        if (!$temAnterior || !$temAtual) {
            $l['consumo']        = null;
            $l['valor']          = null;
            $l['situacao']       = 'zero';
            $l['situacao_label'] = !$temAnterior ? 'Sem leitura anterior' : 'Sem leitura no período';
            continue;
        }

        $consumo = max(0, $l['leitura_atual'] - $l['leitura_anterior']);
        $valor   = ($consumo <= REL_ANALITICO_CONSUMO_MINIMO) ? REL_ANALITICO_VALOR_MINIMO : ($consumo * REL_ANALITICO_VALOR_M3);

        $l['consumo'] = round($consumo, 2);
        $l['valor']   = round($valor, 2);

        $media = $l['consumo_medio_historico'] !== null ? floatval($l['consumo_medio_historico']) : null;
        if ($media === null || $media <= 0) {
            $l['situacao'] = 'normal';
            $l['situacao_label'] = 'Normal';
        } else {
            $osc = (($consumo - $media) / $media) * 100;
            if ($osc >= 50) { $l['situacao'] = 'alto';     $l['situacao_label'] = 'Consumo Muito Alto'; }
            elseif ($osc >= 20) { $l['situacao'] = 'moderado'; $l['situacao_label'] = 'Consumo Alto'; }
            else { $l['situacao'] = 'normal'; $l['situacao_label'] = 'Normal'; }
        }
    }
    unset($l);

    switch ($ordenacao_analitico) {
        case 'maior_consumo':
            usort($linhas, fn($a, $b) => ($b['consumo'] ?? -1) <=> ($a['consumo'] ?? -1));
            break;
        case 'menor_consumo':
            usort($linhas, fn($a, $b) => ($a['consumo'] ?? PHP_INT_MAX) <=> ($b['consumo'] ?? PHP_INT_MAX));
            break;
        case 'maior_valor':
            usort($linhas, fn($a, $b) => ($b['valor'] ?? -1) <=> ($a['valor'] ?? -1));
            break;
        case 'data_leitura':
            usort($linhas, fn($a, $b) => strcmp($b['data_atual'] ?? '', $a['data_atual'] ?? ''));
            break;
        default:
            usort($linhas, fn($a, $b) => _compararUnidadesNaturalRel($a['unidade'], $b['unidade']));
    }

    $comConsumo = array_values(array_filter($linhas, fn($l) => $l['consumo'] !== null));
    $consumoTotal = array_sum(array_map(fn($l) => $l['consumo'], $comConsumo));
    $valorTotal   = array_sum(array_map(fn($l) => $l['valor'], $comConsumo));
    $mediaPorUnidade = count($comConsumo) > 0 ? $consumoTotal / count($comConsumo) : 0;

    $maiorConsumo = null; $menorConsumo = null; $maiorFaturamento = null;
    foreach ($comConsumo as $l) {
        if ($maiorConsumo === null || $l['consumo'] > $maiorConsumo['consumo']) $maiorConsumo = $l;
        if ($menorConsumo === null || $l['consumo'] < $menorConsumo['consumo']) $menorConsumo = $l;
        if ($maiorFaturamento === null || $l['valor'] > $maiorFaturamento['valor']) $maiorFaturamento = $l;
    }

    $resumo = [
        'total_unidades'    => count($linhas),
        'consumo_total'     => round($consumoTotal, 2),
        'valor_total'       => round($valorTotal, 2),
        'media_por_unidade' => round($mediaPorUnidade, 2),
        'media_geral'       => round($mediaPorUnidade, 2),
        'maior_consumo'     => $maiorConsumo ? ['unidade' => $maiorConsumo['unidade'], 'valor' => $maiorConsumo['consumo']] : null,
        'menor_consumo'     => $menorConsumo ? ['unidade' => $menorConsumo['unidade'], 'valor' => $menorConsumo['consumo']] : null,
        'maior_faturamento' => $maiorFaturamento ? ['unidade' => $maiorFaturamento['unidade'], 'valor' => $maiorFaturamento['valor']] : null,
    ];

    $graficoUnidadeOrdenado = $comConsumo;
    usort($graficoUnidadeOrdenado, fn($a, $b) => _compararUnidadesNaturalRel($a['unidade'], $b['unidade']));
    $grafico_unidade = [
        'labels'  => array_map(fn($l) => $l['unidade'], $graficoUnidadeOrdenado),
        'valores' => array_map(fn($l) => $l['consumo'], $graficoUnidadeOrdenado),
    ];

    $porMes = [];
    foreach ($comConsumo as $l) {
        if (!$l['data_atual']) continue;
        $mesKey = date('Y-m', strtotime($l['data_atual']));
        if (!isset($porMes[$mesKey])) $porMes[$mesKey] = ['mes_label' => date('m/Y', strtotime($l['data_atual'])), 'consumo' => 0.0];
        $porMes[$mesKey]['consumo'] += $l['consumo'];
    }
    ksort($porMes);
    $grafico_evolucao = [
        'labels'  => array_map(fn($m) => $m['mes_label'], array_values($porMes)),
        'valores' => array_map(fn($m) => round($m['consumo'], 2), array_values($porMes)),
    ];

    $faixas = ['0–10 m³' => 0, '11–20 m³' => 0, '21–30 m³' => 0, 'Acima de 30 m³' => 0];
    foreach ($comConsumo as $l) {
        $c = $l['consumo'];
        if ($c <= 10) $faixas['0–10 m³']++;
        elseif ($c <= 20) $faixas['11–20 m³']++;
        elseif ($c <= 30) $faixas['21–30 m³']++;
        else $faixas['Acima de 30 m³']++;
    }
    $grafico_distribuicao = ['labels' => array_keys($faixas), 'valores' => array_values($faixas)];

    retornar_json(true, 'Relatório analítico de consumo gerado com sucesso', [
        'linhas'               => $linhas,
        'resumo'               => $resumo,
        'grafico_unidade'      => $grafico_unidade,
        'grafico_evolucao'     => $grafico_evolucao,
        'grafico_distribuicao' => $grafico_distribuicao,
    ]);
}

retornar_json(false, 'Tipo de relatório inválido ou não informado.');
