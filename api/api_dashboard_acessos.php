<?php
// =====================================================
// API PARA DADOS DO DASHBOARD - GRÁFICO DE ACESSOS
// =====================================================

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
// Função para retornar JSON
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

// Buscar acessos dos últimos 7 dias agrupados por placa
$sql_placas = "SELECT 
                v.placa,
                u.nome as unidade,
                COUNT(*) as total_acessos
               FROM registros r
               INNER JOIN veiculos v ON r.veiculo_id = v.id
               INNER JOIN unidades u ON v.unidade_id = u.id
               WHERE r.data_hora_entrada >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               GROUP BY v.placa, u.nome
               ORDER BY total_acessos DESC
               LIMIT 10";

$resultado_placas = $conexao->query($sql_placas);
$dados_placas = array();

if ($resultado_placas && $resultado_placas->num_rows > 0) {
    while ($row = $resultado_placas->fetch_assoc()) {
        $dados_placas[] = $row;
    }
}

// Buscar acessos dos últimos 7 dias agrupados por unidade
$sql_unidades = "SELECT 
                  u.nome as unidade,
                  COUNT(*) as total_acessos
                 FROM registros r
                 INNER JOIN veiculos v ON r.veiculo_id = v.id
                 INNER JOIN unidades u ON v.unidade_id = u.id
                 WHERE r.data_hora_entrada >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY u.nome
                 ORDER BY total_acessos DESC
                 LIMIT 10";

$resultado_unidades = $conexao->query($sql_unidades);
$dados_unidades = array();

if ($resultado_unidades && $resultado_unidades->num_rows > 0) {
    while ($row = $resultado_unidades->fetch_assoc()) {
        $dados_unidades[] = $row;
    }
}

// Buscar total de acessos por dia (últimos 7 dias)
$sql_dias = "SELECT 
              DATE(r.data_hora_entrada) as data,
              COUNT(*) as total_acessos
             FROM registros r
             WHERE r.data_hora_entrada >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(r.data_hora_entrada)
             ORDER BY data ASC";

$resultado_dias = $conexao->query($sql_dias);
$dados_dias = array();

if ($resultado_dias && $resultado_dias->num_rows > 0) {
    while ($row = $resultado_dias->fetch_assoc()) {
        $dados_dias[] = array(
            'data' => date('d/m', strtotime($row['data'])),
            'total' => intval($row['total_acessos'])
        );
    }
}

fechar_conexao($conexao);

$resposta = array(
    'sucesso' => true,
    'dados' => array(
        'placas' => $dados_placas,
        'unidades' => $dados_unidades,
        'dias' => $dados_dias
    )
);

echo json_encode($resposta, JSON_UNESCAPED_UNICODE);

