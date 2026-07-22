<?php
// =====================================================
// API PARA VISUALIZAÇÃO E AUDITORIA DE LOGS DO SISTEMA
// =====================================================

// Limpar qualquer saída anterior
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

// Limpar buffer e definir headers
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

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// ========== LISTAR LOGS COM FILTROS ==========
if ($metodo === 'GET') {
    
    // Parâmetros de filtro
    $tipo = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
    $usuario = isset($_GET['usuario']) ? trim($_GET['usuario']) : '';
    $data_inicio = isset($_GET['data_inicio']) ? trim($_GET['data_inicio']) : '';
    $data_fim = isset($_GET['data_fim']) ? trim($_GET['data_fim']) : '';
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
    $limite = isset($_GET['limite']) ? intval($_GET['limite']) : 100;
    $pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
    
    // Ação especial: listar tipos
    if (isset($_GET['action']) && $_GET['action'] === 'tipos') {
        $sql = "SELECT DISTINCT tipo, COUNT(*) as total 
                FROM logs_sistema 
                GROUP BY tipo 
                ORDER BY tipo ASC";
        
        $resultado = $conexao->query($sql);
        $tipos = array();
        
        while ($row = $resultado->fetch_assoc()) {
            $tipos[] = $row;
        }
        
        retornar_json(true, "Tipos de logs listados com sucesso", $tipos);
        exit;
    }
    
    // Ação especial: exportar
    if (isset($_GET['action']) && $_GET['action'] === 'exportar') {
        $sql = "SELECT 
                    id, 
                    tipo, 
                    descricao, 
                    usuario, 
                    ip, 
                    DATE_FORMAT(data_hora, '%d/%m/%Y %H:%i:%s') as data_hora
                FROM logs_sistema 
                WHERE 1=1";
        
        $params = array();
        $types = '';
        
        if (!empty($tipo)) {
            $sql .= " AND tipo = ?";
            $params[] = $tipo;
            $types .= 's';
        }
        
        if (!empty($data_inicio)) {
            $sql .= " AND DATE(data_hora) >= ?";
            $params[] = $data_inicio;
            $types .= 's';
        }
        
        if (!empty($data_fim)) {
            $sql .= " AND DATE(data_hora) <= ?";
            $params[] = $data_fim;
            $types .= 's';
        }
        
        $sql .= " ORDER BY data_hora DESC";
        
        // Executar query
        $logs = array();
        
        if (!empty($params)) {
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $resultado = $stmt->get_result();
            
            while ($row = $resultado->fetch_assoc()) {
                $logs[] = $row;
            }
            
            $stmt->close();
        } else {
            $resultado = $conexao->query($sql);
            while ($row = $resultado->fetch_assoc()) {
                $logs[] = $row;
            }
        }
        
        retornar_json(true, "Logs exportados com sucesso", $logs);
        exit;
    }
    
    // Ação: relatório com filtros de categoria, severidade, usuário/IP
    if (isset($_GET['action']) && $_GET['action'] === 'relatorio') {
        $categoria  = isset($_GET['categoria'])   ? trim($_GET['categoria'])   : '';
        $severidade = isset($_GET['severidade'])  ? trim($_GET['severidade'])  : '';
        $rtipo      = isset($_GET['tipo'])        ? trim($_GET['tipo'])        : '';
        $rusuario   = isset($_GET['usuario'])     ? trim($_GET['usuario'])     : '';
        $rdi        = isset($_GET['data_inicio']) ? trim($_GET['data_inicio']) : date('Y-m-d', strtotime('-30 days'));
        $rdf        = isset($_GET['data_fim'])    ? trim($_GET['data_fim'])    : date('Y-m-d');
        $rpag       = max(1, intval($_GET['pagina'] ?? 1));
        $rlim       = min(9999, max(1, intval($_GET['limite'] ?? 50)));
        $roff       = ($rpag - 1) * $rlim;

        $categoria_map = [
            'auth'       => ['LOGIN_SUCESSO','LOGIN_FALHA','LOGIN_ERRO','LOGIN_BLOQUEADO','LOGIN_CLIENT_ERROR'],
            'acesso'     => ['ACESSO_NEGADO','ACESSO_RFID','ACESSO_NEGADO_RFID','IP_BLOQUEADO'],
            'rfid'       => ['ACESSO_RFID','ACESSO_NEGADO_RFID'],
            'sessao'     => ['SESSAO_VERIFICAR','SESSAO_RENOVADA'],
            'dados'      => ['OBTER_DADOS_ERRO'],
            'manutencao' => ['LIMPEZA_LOGS'],
        ];

        $where_parts = ['DATE(data_hora) BETWEEN ? AND ?'];
        $params      = [$rdi, $rdf];
        $types       = 'ss';

        if (!empty($rtipo)) {
            $where_parts[] = 'tipo = ?';
            $params[]  = $rtipo;
            $types    .= 's';
        } else {
            if (!empty($categoria) && isset($categoria_map[$categoria])) {
                $ctypes = $categoria_map[$categoria];
                $ph = implode(',', array_fill(0, count($ctypes), '?'));
                $where_parts[] = "tipo IN ($ph)";
                foreach ($ctypes as $ct) { $params[] = $ct; $types .= 's'; }
            }
            if (!empty($severidade)) {
                if ($severidade === 'critico') {
                    $where_parts[] = "tipo IN ('LOGIN_BLOQUEADO','ACESSO_NEGADO','ACESSO_NEGADO_RFID','IP_BLOQUEADO')";
                } elseif ($severidade === 'erro') {
                    $where_parts[] = "(tipo LIKE '%ERRO%' OR tipo LIKE '%FALHA%' OR tipo='LOGIN_CLIENT_ERROR')";
                } elseif ($severidade === 'aviso') {
                    $where_parts[] = "(tipo LIKE '%AVISO%' OR tipo='SESSAO_VERIFICAR')";
                } elseif ($severidade === 'info') {
                    $where_parts[] = "tipo IN ('LOGIN_SUCESSO','ACESSO_RFID','SESSAO_RENOVADA','LIMPEZA_LOGS')";
                }
            }
        }
        if (!empty($rusuario)) {
            $where_parts[] = '(usuario LIKE ? OR ip LIKE ?)';
            $params[] = "%{$rusuario}%";
            $params[] = "%{$rusuario}%";
            $types   .= 'ss';
        }

        $where = 'WHERE ' . implode(' AND ', $where_parts);

        // Resumo por tipo
        $stmt = $conexao->prepare("SELECT tipo, COUNT(*) as total, DATE_FORMAT(MAX(data_hora),'%d/%m/%Y %H:%i') as ultimo FROM logs_sistema $where GROUP BY tipo ORDER BY total DESC LIMIT 20");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $resumo = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $resumo[] = $row;
        $stmt->close();

        // Timeline
        $stmt = $conexao->prepare("SELECT DATE_FORMAT(data_hora,'%d/%m') as dia, COUNT(*) as total FROM logs_sistema $where GROUP BY DATE(data_hora) ORDER BY data_hora ASC LIMIT 30");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $timeline = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $timeline[] = $row;
        $stmt->close();

        // Top usuários
        $stmt = $conexao->prepare("SELECT usuario, COUNT(*) as total, DATE_FORMAT(MAX(data_hora),'%d/%m/%Y %H:%i') as ultimo_acesso FROM logs_sistema $where AND usuario IS NOT NULL GROUP BY usuario ORDER BY total DESC LIMIT 10");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $top_usuarios = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $top_usuarios[] = $row;
        $stmt->close();

        // Contagem total
        $stmt = $conexao->prepare("SELECT COUNT(*) as total FROM logs_sistema $where");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total_rel = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        // Logs paginados
        $params_l = array_merge($params, [$rlim, $roff]);
        $types_l  = $types . 'ii';
        $stmt = $conexao->prepare("SELECT id, tipo, descricao, usuario, ip, DATE_FORMAT(data_hora,'%d/%m/%Y %H:%i:%s') as data_hora_formatada FROM logs_sistema $where ORDER BY data_hora DESC LIMIT ? OFFSET ?");
        $stmt->bind_param($types_l, ...$params_l);
        $stmt->execute();
        $logs_rel = [];
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $logs_rel[] = $row;
        $stmt->close();

        retornar_json(true, 'Relatório gerado', [
            'resumo_por_tipo' => $resumo,
            'timeline'        => $timeline,
            'top_usuarios'    => $top_usuarios,
            'logs'            => $logs_rel,
            'paginacao'       => [
                'pagina_atual'    => $rpag,
                'total_paginas'   => max(1, (int)ceil($total_rel / $rlim)),
                'total_registros' => $total_rel,
            ],
        ]);
        exit;
    }

    // Calcular offset para paginação
    $offset = ($pagina - 1) * $limite;
    
    // Query base
    $sql = "SELECT 
                id, 
                tipo, 
                descricao, 
                usuario, 
                ip, 
                DATE_FORMAT(data_hora, '%d/%m/%Y %H:%i:%s') as data_hora_formatada,
                data_hora
            FROM logs_sistema 
            WHERE 1=1";
    
    $params = array();
    $types = '';
    
    // Aplicar filtros
    if (!empty($tipo)) {
        $sql .= " AND tipo = ?";
        $params[] = $tipo;
        $types .= 's';
    }
    
    if (!empty($usuario)) {
        $sql .= " AND usuario LIKE ?";
        $params[] = "%{$usuario}%";
        $types .= 's';
    }
    
    if (!empty($data_inicio)) {
        $sql .= " AND DATE(data_hora) >= ?";
        $params[] = $data_inicio;
        $types .= 's';
    }
    
    if (!empty($data_fim)) {
        $sql .= " AND DATE(data_hora) <= ?";
        $params[] = $data_fim;
        $types .= 's';
    }
    
    if (!empty($busca)) {
        $sql .= " AND (descricao LIKE ? OR usuario LIKE ? OR tipo LIKE ?)";
        $busca_param = "%{$busca}%";
        $params[] = $busca_param;
        $params[] = $busca_param;
        $params[] = $busca_param;
        $types .= 'sss';
    }
    
    // Contar total de registros (para paginação)
    $sql_count = str_replace("SELECT id, tipo, descricao, usuario, ip, DATE_FORMAT(data_hora, '%d/%m/%Y %H:%i:%s') as data_hora_formatada, data_hora", "SELECT COUNT(*) as total", $sql);
    
    if (!empty($params)) {
        $stmt_count = $conexao->prepare($sql_count);
        $stmt_count->bind_param($types, ...$params);
        $stmt_count->execute();
        $resultado_count = $stmt_count->get_result();
        $row_count = $resultado_count ? $resultado_count->fetch_assoc() : null;
        $total_registros = $row_count['total'] ?? 0;
        $stmt_count->close();
    } else {
        $resultado_count = $conexao->query($sql_count);
        $row_count = $resultado_count ? $resultado_count->fetch_assoc() : null;
        $total_registros = $row_count['total'] ?? 0;
    }
    
    // Ordenar por data mais recente
    $sql .= " ORDER BY data_hora DESC LIMIT ? OFFSET ?";
    $params[] = $limite;
    $params[] = $offset;
    $types .= 'ii';
    
    // Executar query principal
    $logs = array();
    
    if (!empty($params)) {
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        while ($row = $resultado->fetch_assoc()) {
            $logs[] = $row;
        }
        
        $stmt->close();
    } else {
        $resultado = $conexao->query($sql);
        while ($row = $resultado->fetch_assoc()) {
            $logs[] = $row;
        }
    }
    
    // Calcular informações de paginação
    $total_paginas = ceil($total_registros / $limite);
    
    retornar_json(true, "Logs listados com sucesso", array(
        'logs' => $logs,
        'paginacao' => array(
            'pagina_atual' => $pagina,
            'total_paginas' => $total_paginas,
            'total_registros' => $total_registros,
            'registros_por_pagina' => $limite
        )
    ));
}

// ========== OBTER ESTATÍSTICAS DE LOGS ==========
if ($metodo === 'POST' && isset($_GET['action']) && $_GET['action'] === 'estatisticas') {
    
    $data_inicio = isset($_POST['data_inicio']) ? trim($_POST['data_inicio']) : date('Y-m-d', strtotime('-30 days'));
    $data_fim = isset($_POST['data_fim']) ? trim($_POST['data_fim']) : date('Y-m-d');
    
    // Total de logs por tipo
    $sql_tipos = "SELECT 
                    tipo, 
                    COUNT(*) as total,
                    DATE_FORMAT(MAX(data_hora), '%d/%m/%Y %H:%i:%s') as ultimo_registro
                FROM logs_sistema 
                WHERE DATE(data_hora) BETWEEN ? AND ?
                GROUP BY tipo 
                ORDER BY total DESC";
    
    $stmt_tipos = $conexao->prepare($sql_tipos);
    $stmt_tipos->bind_param("ss", $data_inicio, $data_fim);
    $stmt_tipos->execute();
    $resultado_tipos = $stmt_tipos->get_result();
    
    $logs_por_tipo = array();
    while ($row = $resultado_tipos->fetch_assoc()) {
        $logs_por_tipo[] = $row;
    }
    $stmt_tipos->close();
    
    // Total de logs por usuário
    $sql_usuarios = "SELECT 
                        usuario, 
                        COUNT(*) as total,
                        DATE_FORMAT(MAX(data_hora), '%d/%m/%Y %H:%i:%s') as ultimo_acesso
                    FROM logs_sistema 
                    WHERE DATE(data_hora) BETWEEN ? AND ?
                        AND usuario IS NOT NULL
                    GROUP BY usuario 
                    ORDER BY total DESC
                    LIMIT 10";
    
    $stmt_usuarios = $conexao->prepare($sql_usuarios);
    $stmt_usuarios->bind_param("ss", $data_inicio, $data_fim);
    $stmt_usuarios->execute();
    $resultado_usuarios = $stmt_usuarios->get_result();
    
    $logs_por_usuario = array();
    while ($row = $resultado_usuarios->fetch_assoc()) {
        $logs_por_usuario[] = $row;
    }
    $stmt_usuarios->close();
    
    // Logs por dia
    $sql_timeline = "SELECT 
                        DATE_FORMAT(data_hora, '%d/%m') as dia,
                        COUNT(*) as total
                    FROM logs_sistema 
                    WHERE DATE(data_hora) BETWEEN ? AND ?
                    GROUP BY DATE(data_hora)
                    ORDER BY data_hora ASC";
    
    $stmt_timeline = $conexao->prepare($sql_timeline);
    $stmt_timeline->bind_param("ss", $data_inicio, $data_fim);
    $stmt_timeline->execute();
    $resultado_timeline = $stmt_timeline->get_result();
    
    $timeline = array();
    while ($row = $resultado_timeline->fetch_assoc()) {
        $timeline[] = $row;
    }
    $stmt_timeline->close();
    
    // Total geral de logs
    $sql_total = "SELECT COUNT(*) as total FROM logs_sistema WHERE DATE(data_hora) BETWEEN ? AND ?";
    $stmt_total = $conexao->prepare($sql_total);
    $stmt_total->bind_param("ss", $data_inicio, $data_fim);
    $stmt_total->execute();
    $resultado_total = $stmt_total->get_result();
    $total_geral = $resultado_total->fetch_assoc()['total'];
    $stmt_total->close();
    
    retornar_json(true, "Estatísticas geradas com sucesso", array(
        'total_geral' => $total_geral,
        'logs_por_tipo' => $logs_por_tipo,
        'logs_por_usuario' => $logs_por_usuario,
        'timeline' => $timeline,
        'periodo' => array(
            'inicio' => date('d/m/Y', strtotime($data_inicio)),
            'fim' => date('d/m/Y', strtotime($data_fim))
        )
    ));
}

// ========== LIMPAR LOGS ANTIGOS ==========
if ($metodo === 'DELETE' && isset($_GET['action']) && $_GET['action'] === 'limpar') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $dias = isset($dados['dias']) ? intval($dados['dias']) : 90;
    
    // Validação de segurança - mínimo 30 dias
    if ($dias < 30) {
        retornar_json(false, "Por segurança, não é possível limpar logs com menos de 30 dias");
    }
    
    $sql = "DELETE FROM logs_sistema WHERE data_hora < DATE_SUB(NOW(), INTERVAL ? DAY)";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $dias);
    
    if ($stmt->execute()) {
        $registros_excluidos = $stmt->affected_rows;
        $stmt->close();
        
        // Registrar a limpeza
        registrar_log('LIMPEZA_LOGS', "Logs antigos foram limpos: {$registros_excluidos} registros removidos (mais de {$dias} dias)", $_SESSION['usuario_nome'] ?? 'Sistema');
        
        retornar_json(true, "Logs antigos limpos com sucesso", array('registros_excluidos' => $registros_excluidos));
    } else {
        retornar_json(false, "Erro ao limpar logs: " . $stmt->error);
    }
}

fechar_conexao($conexao);
