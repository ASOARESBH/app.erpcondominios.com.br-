<?php
// =====================================================
// API DASHBOARD - DADOS DE ÁGUA E ABASTECIMENTO
// =====================================================

ob_start();
require_once 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// ========== TOTAL DE MORADORES ==========
if ($metodo === 'GET' && isset($_GET['total_moradores'])) {
    $sql = "SELECT COUNT(*) as total FROM moradores WHERE ativo = 1";
    $resultado = $conexao->query($sql);
    $dados = $resultado->fetch_assoc();
    retornar_json(true, "Total de moradores", $dados);
}

// ========== TOP 10 MORADORES COM MAIOR CONSUMO DE ÁGUA ==========
if ($metodo === 'GET' && isset($_GET['top_consumo_agua'])) {
    $sql = "
        SELECT 
            m.id,
            m.nome as nome_morador,
            m.unidade,
            COALESCE(SUM(l.consumo), 0) as consumo_total,
            COALESCE(SUM(l.valor_total), 0) as valor_total,
            MAX(l.data_leitura) as ultima_leitura,
            MAX(l.leitura_atual) as ultima_leitura_valor,
            COUNT(l.id) as total_leituras,
            COALESCE(ROUND(AVG(l.consumo), 2), 0) as consumo_medio
        FROM moradores m
        LEFT JOIN leituras l ON m.id = l.morador_id
        WHERE m.ativo = 1
        GROUP BY m.id, m.nome, m.unidade
        ORDER BY consumo_total DESC
        LIMIT 10
    ";
    
    $resultado = $conexao->query($sql);
    $dados = array();
    
    if ($resultado && $resultado->num_rows > 0) {
        $posicao = 1;
        while ($row = $resultado->fetch_assoc()) {
            $row['posicao'] = $posicao;
            $row['consumo_total'] = floatval($row['consumo_total']);
            $row['valor_total'] = floatval($row['valor_total']);
            $row['consumo_medio'] = floatval($row['consumo_medio']);
            $row['ultima_leitura_valor'] = floatval($row['ultima_leitura_valor']);
            $row['total_leituras'] = intval($row['total_leituras']);
            
            if ($row['ultima_leitura']) {
                $row['ultima_leitura_formatada'] = date('d/m/Y H:i', strtotime($row['ultima_leitura']));
            } else {
                $row['ultima_leitura_formatada'] = 'Sem leitura';
            }
            
            $dados[] = $row;
            $posicao++;
        }
    }
    
    retornar_json(true, "Top 10 moradores com maior consumo de água", $dados);
}

// ========== SALDO DE ABASTECIMENTO DE VEÍCULOS ==========
if ($metodo === 'GET' && isset($_GET['saldo_abastecimento'])) {
    $sql = "
        SELECT 
            s.id,
            s.valor as saldo_atual,
            s.valor_minimo as saldo_minimo,
            s.data_atualizacao,
            CASE 
                WHEN s.valor < s.valor_minimo THEN 'Crítico'
                WHEN s.valor < (s.valor_minimo * 1.5) THEN 'Baixo'
                ELSE 'Normal'
            END as status_saldo,
            CASE 
                WHEN s.valor < s.valor_minimo THEN '#dc3545'
                WHEN s.valor < (s.valor_minimo * 1.5) THEN '#ffc107'
                ELSE '#28a745'
            END as cor_status,
            (
                SELECT COUNT(*) FROM abastecimento_lancamentos 
                WHERE DATE(data_abastecimento) = CURDATE()
            ) as abastecimentos_hoje,
            (
                SELECT COALESCE(SUM(litros), 0) FROM abastecimento_lancamentos 
                WHERE DATE(data_abastecimento) = CURDATE()
            ) as litros_abastecidos_hoje,
            (
                SELECT COALESCE(SUM(valor), 0) FROM abastecimento_lancamentos 
                WHERE DATE(data_abastecimento) = CURDATE()
            ) as valor_gasto_hoje
        FROM abastecimento_saldo s
        LIMIT 1
    ";
    
    $resultado = $conexao->query($sql);
    
    if ($resultado && $resultado->num_rows > 0) {
        $saldo = $resultado->fetch_assoc();
        $saldo['saldo_atual'] = floatval($saldo['saldo_atual']);
        $saldo['saldo_minimo'] = floatval($saldo['saldo_minimo']);
        $saldo['abastecimentos_hoje'] = intval($saldo['abastecimentos_hoje']);
        $saldo['litros_abastecidos_hoje'] = floatval($saldo['litros_abastecidos_hoje']);
        $saldo['valor_gasto_hoje'] = floatval($saldo['valor_gasto_hoje']);
        
        if ($saldo['data_atualizacao']) {
            $saldo['data_atualizacao_formatada'] = date('d/m/Y H:i', strtotime($saldo['data_atualizacao']));
        }
        
        retornar_json(true, "Saldo de abastecimento", $saldo);
    } else {
        retornar_json(true, "Nenhum saldo encontrado", array(
            'saldo_atual' => 0,
            'saldo_minimo' => 0,
            'status_saldo' => 'Sem dados',
            'cor_status' => '#6c757d'
        ));
    }
}

// ========== ÚLTIMO LANÇAMENTO DE ABASTECIMENTO ==========
if ($metodo === 'GET' && isset($_GET['ultimo_lancamento_abastecimento'])) {
    $sql = "
        SELECT 
            al.id,
            al.veiculo_id,
            av.placa,
            av.modelo,
            al.data_abastecimento,
            al.km_abastecimento,
            al.litros,
            al.valor,
            al.tipo_combustivel,
            al.usuario_logado,
            al.data_registro,
            DATE_FORMAT(al.data_abastecimento, '%d/%m/%Y %H:%i') as data_abastecimento_formatada,
            DATE_FORMAT(al.data_registro, '%d/%m/%Y %H:%i') as data_registro_formatada
        FROM abastecimento_lancamentos al
        INNER JOIN abastecimento_veiculos av ON al.veiculo_id = av.id
        ORDER BY al.data_abastecimento DESC
        LIMIT 1
    ";
    
    $resultado = $conexao->query($sql);
    
    if ($resultado && $resultado->num_rows > 0) {
        $lancamento = $resultado->fetch_assoc();
        $lancamento['km_abastecimento'] = intval($lancamento['km_abastecimento']);
        $lancamento['litros'] = floatval($lancamento['litros']);
        $lancamento['valor'] = floatval($lancamento['valor']);
        
        retornar_json(true, "Último lançamento de abastecimento", $lancamento);
    } else {
        retornar_json(true, "Nenhum lançamento encontrado", array());
    }
}

// ========== HISTÓRICO DE ABASTECIMENTOS (ÚLTIMOS 10) ==========
if ($metodo === 'GET' && isset($_GET['historico_abastecimentos'])) {
    $sql = "
        SELECT 
            al.id,
            al.veiculo_id,
            av.placa,
            av.modelo,
            al.data_abastecimento,
            al.km_abastecimento,
            al.litros,
            al.valor,
            al.tipo_combustivel,
            al.usuario_logado,
            DATE_FORMAT(al.data_abastecimento, '%d/%m/%Y %H:%i') as data_abastecimento_formatada
        FROM abastecimento_lancamentos al
        INNER JOIN abastecimento_veiculos av ON al.veiculo_id = av.id
        ORDER BY al.data_abastecimento DESC
        LIMIT 10
    ";
    
    $resultado = $conexao->query($sql);
    $dados = array();
    
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $row['km_abastecimento'] = intval($row['km_abastecimento']);
            $row['litros'] = floatval($row['litros']);
            $row['valor'] = floatval($row['valor']);
            $dados[] = $row;
        }
    }
    
    retornar_json(true, "Histórico de abastecimentos", $dados);
}

// ========== ESTATÍSTICAS GERAIS DO DASHBOARD ==========
if ($metodo === 'GET' && isset($_GET['estatisticas_gerais'])) {
    $resultado_moradores = $conexao->query("SELECT COUNT(*) as total FROM moradores WHERE ativo = 1");
    $total_moradores = $resultado_moradores ? $resultado_moradores->fetch_assoc()['total'] : 0;
    
    $resultado_consumo = $conexao->query("SELECT COALESCE(SUM(consumo), 0) as total_consumo FROM leituras");
    $total_consumo = $resultado_consumo ? floatval($resultado_consumo->fetch_assoc()['total_consumo']) : 0;
    
    $resultado_valor = $conexao->query("SELECT COALESCE(SUM(valor_total), 0) as total_valor FROM leituras");
    $total_valor = $resultado_valor ? floatval($resultado_valor->fetch_assoc()['total_valor']) : 0;
    
    $resultado_leituras = $conexao->query("SELECT COUNT(*) as total FROM leituras");
    $total_leituras = $resultado_leituras ? $resultado_leituras->fetch_assoc()['total'] : 0;
    
    $resultado_saldo = $conexao->query("SELECT valor as saldo_atual FROM abastecimento_saldo LIMIT 1");
    $saldo_abastecimento = 0;
    if ($resultado_saldo && $resultado_saldo->num_rows > 0) {
        $saldo_abastecimento = floatval($resultado_saldo->fetch_assoc()['saldo_atual']);
    }
    
    $dados = array(
        'total_moradores' => intval($total_moradores),
        'total_consumo_agua' => round($total_consumo, 2),
        'total_valor_agua' => round($total_valor, 2),
        'total_leituras' => intval($total_leituras),
        'saldo_abastecimento' => round($saldo_abastecimento, 2),
        'consumo_medio_por_morador' => $total_moradores > 0 ? round($total_consumo / $total_moradores, 2) : 0
    );
    
    retornar_json(true, "Estatísticas gerais do dashboard", $dados);
}

fechar_conexao($conexao);
?>
