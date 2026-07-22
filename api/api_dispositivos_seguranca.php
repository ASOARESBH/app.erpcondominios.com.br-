<?php
// =====================================================
// API de Gerenciamento de Dispositivos de Segurança
// Sistema ERP Serra da Liberdade
// =====================================================

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

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Tratar requisições OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';
require_once 'auth_helper.php';

// Função para responder JSON
function responder($sucesso, $mensagem, $dados = null) {
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Obter ação
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

try {
    switch ($acao) {
        
        // =====================================================
        // LISTAR TODOS OS DISPOSITIVOS
        // =====================================================
        case 'listar':
            $sql = "SELECT 
                        d.*,
                        t.nome as tipo_nome,
                        t.icone as tipo_icone,
                        m.nome as marca_nome,
                        m.logo as marca_logo,
                        DATE_FORMAT(d.data_instalacao, '%d/%m/%Y') as data_instalacao_formatada,
                        DATE_FORMAT(d.data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro_formatada,
                        DATE_FORMAT(d.ultimo_status, '%d/%m/%Y %H:%i') as ultimo_status_formatado
                    FROM dispositivos_seguranca d
                    LEFT JOIN tipos_dispositivo t ON d.tipo_id = t.id
                    LEFT JOIN marcas_dispositivo m ON d.marca_id = m.id
                    ORDER BY d.area_instalacao, d.nome";
            
            $result = $conn->query($sql);
            $dispositivos = [];
            
            while ($row = $result->fetch_assoc()) {
                $dispositivos[] = $row;
            }
            
            responder(true, 'Dispositivos carregados com sucesso', $dispositivos);
            break;
        
        // =====================================================
        // BUSCAR DISPOSITIVO ESPECÍFICO
        // =====================================================
        case 'buscar':
            $id = $_GET['id'] ?? 0;
            
            $sql = "SELECT 
                        d.*,
                        t.nome as tipo_nome,
                        m.nome as marca_nome
                    FROM dispositivos_seguranca d
                    LEFT JOIN tipos_dispositivo t ON d.tipo_id = t.id
                    LEFT JOIN marcas_dispositivo m ON d.marca_id = m.id
                    WHERE d.id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                responder(true, 'Dispositivo encontrado', $row);
            } else {
                responder(false, 'Dispositivo não encontrado');
            }
            break;
        
        // =====================================================
        // CADASTRAR NOVO DISPOSITIVO
        // =====================================================
        case 'cadastrar':
            $nome = $_POST['nome'] ?? '';
            $tipo_id = $_POST['tipo_id'] ?? 0;
            $marca_id = $_POST['marca_id'] ?? 0;
            $modelo = $_POST['modelo'] ?? '';
            $area_instalacao = $_POST['area_instalacao'] ?? '';
            $id_dispositivo = $_POST['id_dispositivo'] ?? '';
            $ip_address = $_POST['ip_address'] ?? null;
            $porta = $_POST['porta'] ?? null;
            $usuario = $_POST['usuario'] ?? null;
            $senha = $_POST['senha'] ?? null;
            $data_instalacao = $_POST['data_instalacao'] ?? null;
            $observacoes = $_POST['observacoes'] ?? null;
            
            // Validações
            if (empty($nome)) {
                responder(false, 'Nome do dispositivo é obrigatório');
            }
            
            if (empty($tipo_id)) {
                responder(false, 'Tipo do dispositivo é obrigatório');
            }
            
            if (empty($area_instalacao)) {
                responder(false, 'Área de instalação é obrigatória');
            }
            
            // Verificar se ID do dispositivo já existe
            if (!empty($id_dispositivo)) {
                $sql = "SELECT id FROM dispositivos_seguranca WHERE id_dispositivo = ? AND id != 0";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('s', $id_dispositivo);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    responder(false, 'ID do dispositivo já está cadastrado');
                }
            }
            
            // Inserir
            $sql = "INSERT INTO dispositivos_seguranca 
                    (nome, tipo_id, marca_id, modelo, area_instalacao, id_dispositivo, 
                     ip_address, porta, usuario, senha, data_instalacao, observacoes, ativo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'siisssssisss',
                $nome, $tipo_id, $marca_id, $modelo, $area_instalacao, $id_dispositivo,
                $ip_address, $porta, $usuario, $senha, $data_instalacao, $observacoes
            );
            
            if ($stmt->execute()) {
                $id = $conn->insert_id;
                responder(true, 'Dispositivo cadastrado com sucesso', ['id' => $id]);
            } else {
                responder(false, 'Erro ao cadastrar dispositivo: ' . $conn->error);
            }
            break;
        
        // =====================================================
        // ATUALIZAR DISPOSITIVO
        // =====================================================
        case 'atualizar':
            $id = $_POST['id'] ?? 0;
            $nome = $_POST['nome'] ?? '';
            $tipo_id = $_POST['tipo_id'] ?? 0;
            $marca_id = $_POST['marca_id'] ?? 0;
            $modelo = $_POST['modelo'] ?? '';
            $area_instalacao = $_POST['area_instalacao'] ?? '';
            $id_dispositivo = $_POST['id_dispositivo'] ?? '';
            $ip_address = $_POST['ip_address'] ?? null;
            $porta = $_POST['porta'] ?? null;
            $usuario = $_POST['usuario'] ?? null;
            $senha = $_POST['senha'] ?? null;
            $data_instalacao = $_POST['data_instalacao'] ?? null;
            $observacoes = $_POST['observacoes'] ?? null;
            
            // Validações
            if (empty($id)) {
                responder(false, 'ID do dispositivo é obrigatório');
            }
            
            if (empty($nome)) {
                responder(false, 'Nome do dispositivo é obrigatório');
            }
            
            // Verificar se ID do dispositivo já existe em outro registro
            if (!empty($id_dispositivo)) {
                $sql = "SELECT id FROM dispositivos_seguranca WHERE id_dispositivo = ? AND id != ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('si', $id_dispositivo, $id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    responder(false, 'ID do dispositivo já está cadastrado em outro equipamento');
                }
            }
            
            // Atualizar
            $sql = "UPDATE dispositivos_seguranca SET
                    nome = ?,
                    tipo_id = ?,
                    marca_id = ?,
                    modelo = ?,
                    area_instalacao = ?,
                    id_dispositivo = ?,
                    ip_address = ?,
                    porta = ?,
                    usuario = ?,
                    senha = ?,
                    data_instalacao = ?,
                    observacoes = ?
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'siissssissssi',
                $nome, $tipo_id, $marca_id, $modelo, $area_instalacao, $id_dispositivo,
                $ip_address, $porta, $usuario, $senha, $data_instalacao, $observacoes, $id
            );
            
            if ($stmt->execute()) {
                responder(true, 'Dispositivo atualizado com sucesso');
            } else {
                responder(false, 'Erro ao atualizar dispositivo: ' . $conn->error);
            }
            break;
        
        // =====================================================
        // ALTERNAR STATUS (ATIVO/INATIVO)
        // =====================================================
        case 'alternar_status':
            $id = $_POST['id'] ?? 0;
            
            if (empty($id)) {
                responder(false, 'ID do dispositivo é obrigatório');
            }
            
            // Buscar status atual
            $sql = "SELECT ativo FROM dispositivos_seguranca WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $novo_status = $row['ativo'] == 1 ? 0 : 1;
                
                $sql = "UPDATE dispositivos_seguranca SET ativo = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ii', $novo_status, $id);
                
                if ($stmt->execute()) {
                    $mensagem = $novo_status == 1 ? 'Dispositivo ativado' : 'Dispositivo inativado';
                    responder(true, $mensagem, ['novo_status' => $novo_status]);
                } else {
                    responder(false, 'Erro ao alterar status');
                }
            } else {
                responder(false, 'Dispositivo não encontrado');
            }
            break;
        
        // =====================================================
        // EXCLUIR DISPOSITIVO
        // =====================================================
        case 'excluir':
            $id = $_POST['id'] ?? 0;
            
            if (empty($id)) {
                responder(false, 'ID do dispositivo é obrigatório');
            }
            
            $sql = "DELETE FROM dispositivos_seguranca WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $id);
            
            if ($stmt->execute()) {
                responder(true, 'Dispositivo excluído com sucesso');
            } else {
                responder(false, 'Erro ao excluir dispositivo: ' . $conn->error);
            }
            break;
        
        // =====================================================
        // LISTAR TIPOS DE DISPOSITIVO
        // =====================================================
        case 'listar_tipos':
            $sql = "SELECT * FROM tipos_dispositivo WHERE ativo = 1 ORDER BY nome";
            $result = $conn->query($sql);
            $tipos = [];
            
            while ($row = $result->fetch_assoc()) {
                $tipos[] = $row;
            }
            
            responder(true, 'Tipos carregados', $tipos);
            break;
        
        // =====================================================
        // LISTAR MARCAS
        // =====================================================
        case 'listar_marcas':
            $sql = "SELECT * FROM marcas_dispositivo WHERE ativo = 1 ORDER BY nome";
            $result = $conn->query($sql);
            $marcas = [];
            
            while ($row = $result->fetch_assoc()) {
                $marcas[] = $row;
            }
            
            responder(true, 'Marcas carregadas', $marcas);
            break;
        
        // =====================================================
        // LISTAR MODELOS POR MARCA
        // =====================================================
        case 'listar_modelos':
            $marca_id = $_GET['marca_id'] ?? 0;
            
            $sql = "SELECT * FROM modelos_dispositivo WHERE marca_id = ? AND ativo = 1 ORDER BY nome";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $marca_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $modelos = [];
            
            while ($row = $result->fetch_assoc()) {
                $modelos[] = $row;
            }
            
            responder(true, 'Modelos carregados', $modelos);
            break;
        
        // =====================================================
        // ESTATÍSTICAS
        // =====================================================
        case 'estatisticas':
            $stats = [];
            
            // Total de dispositivos
            $sql = "SELECT COUNT(*) as total FROM dispositivos_seguranca";
            $result = $conn->query($sql);
            $stats['total'] = $result->fetch_assoc()['total'];
            
            // Dispositivos ativos
            $sql = "SELECT COUNT(*) as total FROM dispositivos_seguranca WHERE ativo = 1";
            $result = $conn->query($sql);
            $stats['ativos'] = $result->fetch_assoc()['total'];
            
            // Dispositivos inativos
            $sql = "SELECT COUNT(*) as total FROM dispositivos_seguranca WHERE ativo = 0";
            $result = $conn->query($sql);
            $stats['inativos'] = $result->fetch_assoc()['total'];
            
            // Por tipo
            $sql = "SELECT 
                        t.nome as tipo,
                        t.icone,
                        COUNT(d.id) as total
                    FROM tipos_dispositivo t
                    LEFT JOIN dispositivos_seguranca d ON t.id = d.tipo_id
                    WHERE t.ativo = 1
                    GROUP BY t.id, t.nome, t.icone
                    ORDER BY total DESC";
            $result = $conn->query($sql);
            $stats['por_tipo'] = [];
            while ($row = $result->fetch_assoc()) {
                $stats['por_tipo'][] = $row;
            }
            
            responder(true, 'Estatísticas carregadas', $stats);
            break;
        
        default:
            responder(false, 'Ação não reconhecida');
    }
    
} catch (Exception $e) {
    responder(false, 'Erro no servidor: ' . $e->getMessage());
}

$conn->close();
?>
