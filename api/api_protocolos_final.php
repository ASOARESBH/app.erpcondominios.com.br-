<?php
/**
 * API de Protocolos de Mercadorias - VERSÃO FINAL CORRIGIDA
 * ERP Condomínio — Gestão Inteligente - ERP Condomínio
 * 
 * Versão simplificada e robusta sem dependências de Controllers
 */

// Limpar qualquer saída anterior
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

// Limpar buffer e definir headers
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Função para retornar JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array(
            'sucesso' => $sucesso,
            'mensagem' => $mensagem
        );
        if ($dados !== null) {
            $resposta['dados'] = $dados;
        }
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Verificar autenticação
verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// Para operações de escrita, verificar permissão de admin
if ($metodo !== 'GET') {
    verificarPermissao('admin');
}

try {
    // ========== LISTAR PROTOCOLOS ==========
    if ($metodo === 'GET') {
        if (isset($_GET['id'])) {
            // Buscar protocolo específico
            $id = intval($_GET['id']);
            $stmt = $conexao->prepare("SELECT p.*, 
                    u.nome as unidade_nome,
                    m.nome as morador_nome
                    FROM protocolos p
                    LEFT JOIN unidades u ON p.unidade_id = u.id
                    LEFT JOIN moradores m ON p.morador_id = m.id
                    WHERE p.id = ?");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            
            if ($protocolo = $resultado->fetch_assoc()) {
                retornar_json(true, "Protocolo encontrado", $protocolo);
            } else {
                retornar_json(false, "Protocolo não encontrado");
            }
            $stmt->close();
        } else {
            // Listar todos os protocolos
            $filtro = isset($_GET['status']) ? sanitizar($conexao, $_GET['status']) : '';
            
            $sql = "SELECT p.*, 
                    u.nome as unidade_nome,
                    m.nome as morador_nome
                    FROM protocolos p
                    LEFT JOIN unidades u ON p.unidade_id = u.id
                    LEFT JOIN moradores m ON p.morador_id = m.id";
            
            if ($filtro) {
                $sql .= " WHERE p.status = ?";
                $stmt = $conexao->prepare($sql . " ORDER BY p.data_hora_recebimento DESC");
                if (!$stmt) {
                    throw new Exception("Erro ao preparar query: " . $conexao->error);
                }
                $stmt->bind_param('s', $filtro);
                $stmt->execute();
                $resultado = $stmt->get_result();
            } else {
                $sql .= " ORDER BY p.data_hora_recebimento DESC";
                $resultado = $conexao->query($sql);
                if (!$resultado) {
                    throw new Exception("Erro ao executar query: " . $conexao->error);
                }
            }
            
            $protocolos = array();
            
            if ($resultado && $resultado->num_rows > 0) {
                while ($row = $resultado->fetch_assoc()) {
                    $protocolos[] = $row;
                }
            }
            
            retornar_json(true, "Protocolos listados com sucesso", $protocolos);
        }
    }
    
    // ========== CRIAR PROTOCOLO ==========
    if ($metodo === 'POST') {
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $unidade_id = intval($dados['unidade_id'] ?? 0);
        $morador_id = intval($dados['morador_id'] ?? 0);
        $descricao_mercadoria = sanitizar($conexao, $dados['descricao_mercadoria'] ?? '');
        $codigo_nf = sanitizar($conexao, $dados['codigo_nf'] ?? '');
        $pagina = isset($dados['pagina']) ? intval($dados['pagina']) : null;
        $data_hora_recebimento = sanitizar($conexao, $dados['data_hora_recebimento'] ?? '');
        $recebedor_portaria = sanitizar($conexao, $dados['recebedor_portaria'] ?? '');
        $observacao = sanitizar($conexao, $dados['observacao'] ?? '');
        
        // Validações
        if ($unidade_id <= 0) {
            retornar_json(false, "Unidade é obrigatória");
        }
        if ($morador_id <= 0) {
            retornar_json(false, "Morador é obrigatório");
        }
        if (empty($descricao_mercadoria)) {
            retornar_json(false, "Descrição da mercadoria é obrigatória");
        }
        if (empty($recebedor_portaria)) {
            retornar_json(false, "Recebedor é obrigatório");
        }
        if (empty($data_hora_recebimento)) {
            retornar_json(false, "Data e hora de recebimento são obrigatórias");
        }
        
        // Verificar se morador existe
        $stmt_check = $conexao->prepare("SELECT id FROM moradores WHERE id = ?");
        if (!$stmt_check) {
            throw new Exception("Erro ao preparar query de verificação: " . $conexao->error);
        }
        $stmt_check->bind_param('i', $morador_id);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows === 0) {
            $stmt_check->close();
            retornar_json(false, "Morador selecionado não existe no banco de dados");
        }
        $stmt_check->close();
        
        // Inserir protocolo
        if ($pagina !== null) {
            $stmt = $conexao->prepare("INSERT INTO protocolos 
                    (unidade_id, morador_id, descricao_mercadoria, codigo_nf, pagina, 
                     data_hora_recebimento, recebedor_portaria, observacao, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendente')");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar insert: " . $conexao->error);
            }
            
            $stmt->bind_param('iississs', 
                $unidade_id, 
                $morador_id, 
                $descricao_mercadoria, 
                $codigo_nf, 
                $pagina, 
                $data_hora_recebimento, 
                $recebedor_portaria,
                $observacao
            );
        } else {
            $stmt = $conexao->prepare("INSERT INTO protocolos 
                    (unidade_id, morador_id, descricao_mercadoria, codigo_nf, 
                     data_hora_recebimento, recebedor_portaria, observacao, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente')");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar insert: " . $conexao->error);
            }
            
            $stmt->bind_param('iisssss', 
                $unidade_id, 
                $morador_id, 
                $descricao_mercadoria, 
                $codigo_nf, 
                $data_hora_recebimento, 
                $recebedor_portaria,
                $observacao
            );
        }
        
        if ($stmt->execute()) {
            $id_inserido = $conexao->insert_id;
            registrar_log('PROTOCOLO_CRIADO', "Protocolo criado: $descricao_mercadoria (ID: $id_inserido)", $recebedor_portaria);
            retornar_json(true, "Protocolo cadastrado com sucesso", array('id' => $id_inserido));
        } else {
            throw new Exception("Erro ao cadastrar protocolo: " . $stmt->error);
        }
        $stmt->close();
    }
    
    // ========== ATUALIZAR PROTOCOLO ==========
    if ($metodo === 'PUT') {
        $dados = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($dados)) {
            retornar_json(false, "Dados inválidos. Esperado JSON válido");
        }
        
        $id = intval($dados['id'] ?? 0);
        
        if ($id <= 0) {
            retornar_json(false, "ID inválido");
        }
        
        // Verificar ação
        $acao = $dados['acao'] ?? '';
        
        if ($acao === 'entregar') {
            // Registrar entrega
            $nome_recebedor_morador = sanitizar($conexao, $dados['nome_recebedor_morador'] ?? '');
            $data_hora_entrega = sanitizar($conexao, $dados['data_hora_entrega'] ?? '');
            
            if (empty($nome_recebedor_morador)) {
                retornar_json(false, "Nome do recebedor é obrigatório");
            }
            if (empty($data_hora_entrega)) {
                retornar_json(false, "Data e hora da entrega são obrigatórias");
            }
            
            // Verificar se já foi entregue
            $stmt = $conexao->prepare("SELECT status FROM protocolos WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $protocolo = $resultado->fetch_assoc();
            $stmt->close();
            
            if (!$protocolo) {
                retornar_json(false, "Protocolo não encontrado");
            }
            
            if ($protocolo['status'] === 'entregue') {
                retornar_json(false, "Protocolo já foi entregue");
            }
            
            // Registrar entrega
            $stmt = $conexao->prepare("UPDATE protocolos SET 
                    status = 'entregue',
                    nome_recebedor_morador = ?,
                    data_hora_entrega = ?
                    WHERE id = ? AND status = 'pendente'");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar update: " . $conexao->error);
            }
            
            $stmt->bind_param('ssi', $nome_recebedor_morador, $data_hora_entrega, $id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                registrar_log('PROTOCOLO_ENTREGUE', "Protocolo entregue (ID: $id) para $nome_recebedor_morador", $nome_recebedor_morador);
                retornar_json(true, "Entrega registrada com sucesso");
            } else {
                retornar_json(false, "Erro ao registrar entrega ou protocolo já entregue");
            }
            $stmt->close();
        } else {
            // Atualizar protocolo normal
            $unidade_id = intval($dados['unidade_id'] ?? 0);
            $morador_id = intval($dados['morador_id'] ?? 0);
            $descricao_mercadoria = sanitizar($conexao, $dados['descricao_mercadoria'] ?? '');
            $codigo_nf = sanitizar($conexao, $dados['codigo_nf'] ?? '');
            $pagina = isset($dados['pagina']) ? intval($dados['pagina']) : null;
            $data_hora_recebimento = sanitizar($conexao, $dados['data_hora_recebimento'] ?? '');
            $recebedor_portaria = sanitizar($conexao, $dados['recebedor_portaria'] ?? '');
            $observacao = sanitizar($conexao, $dados['observacao'] ?? '');
            
            // Verificar se já foi entregue
            $stmt = $conexao->prepare("SELECT status FROM protocolos WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Erro ao preparar query: " . $conexao->error);
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $protocolo = $resultado->fetch_assoc();
            $stmt->close();
            
            if (!$protocolo) {
                retornar_json(false, "Protocolo não encontrado");
            }
            
            if ($protocolo['status'] === 'entregue') {
                retornar_json(false, "Não é possível editar protocolo já entregue");
            }
            
            // Atualizar
            if ($pagina !== null) {
                $stmt = $conexao->prepare("UPDATE protocolos SET 
                        unidade_id = ?, 
                        morador_id = ?, 
                        descricao_mercadoria = ?, 
                        codigo_nf = ?, 
                        pagina = ?,
                        data_hora_recebimento = ?,
                        recebedor_portaria = ?,
                        observacao = ?
                        WHERE id = ? AND status = 'pendente'");
                
                if (!$stmt) {
                    throw new Exception("Erro ao preparar update: " . $conexao->error);
                }
                
                $stmt->bind_param('iississsi', 
                    $unidade_id, 
                    $morador_id, 
                    $descricao_mercadoria, 
                    $codigo_nf, 
                    $pagina, 
                    $data_hora_recebimento, 
                    $recebedor_portaria,
                    $observacao, 
                    $id
                );
            } else {
                $stmt = $conexao->prepare("UPDATE protocolos SET 
                        unidade_id = ?, 
                        morador_id = ?, 
                        descricao_mercadoria = ?, 
                        codigo_nf = ?,
                        data_hora_recebimento = ?,
                        recebedor_portaria = ?,
                        observacao = ?
                        WHERE id = ? AND status = 'pendente'");
                
                if (!$stmt) {
                    throw new Exception("Erro ao preparar update: " . $conexao->error);
                }
                
                $stmt->bind_param('iisssssi', 
                    $unidade_id, 
                    $morador_id, 
                    $descricao_mercadoria, 
                    $codigo_nf, 
                    $data_hora_recebimento, 
                    $recebedor_portaria,
                    $observacao, 
                    $id
                );
            }
            
            if ($stmt->execute()) {
                registrar_log('PROTOCOLO_ATUALIZADO', "Protocolo atualizado (ID: $id)", $recebedor_portaria);
                retornar_json(true, "Protocolo atualizado com sucesso");
            } else {
                throw new Exception("Erro ao atualizar protocolo: " . $stmt->error);
            }
            $stmt->close();
        }
    }
    
    // ========== EXCLUIR PROTOCOLO ==========
    if ($metodo === 'DELETE') {
        $dados = json_decode(file_get_contents('php://input'), true);
        $id = intval($dados['id'] ?? 0);
        
        if ($id <= 0) {
            retornar_json(false, "ID inválido");
        }
        
        // Verificar se já foi entregue
        $stmt = $conexao->prepare("SELECT status, descricao_mercadoria FROM protocolos WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conexao->error);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $protocolo = $resultado->fetch_assoc();
        $stmt->close();
        
        if (!$protocolo) {
            retornar_json(false, "Protocolo não encontrado");
        }
        
        if ($protocolo['status'] === 'entregue') {
            retornar_json(false, "Não é possível excluir protocolo já entregue");
        }
        
        // Excluir
        $stmt = $conexao->prepare("DELETE FROM protocolos WHERE id = ? AND status = 'pendente'");
        if (!$stmt) {
            throw new Exception("Erro ao preparar delete: " . $conexao->error);
        }
        $stmt->bind_param('i', $id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            registrar_log('PROTOCOLO_EXCLUIDO', "Protocolo excluído: {$protocolo['descricao_mercadoria']} (ID: $id)", 'Sistema');
            retornar_json(true, "Protocolo excluído com sucesso");
        } else {
            retornar_json(false, "Erro ao excluir protocolo");
        }
        $stmt->close();
    }
    
} catch (Exception $e) {
    error_log("Erro em api_protocolos.php: " . $e->getMessage());
    registrar_log('PROTOCOLO_ERRO', "Erro: " . $e->getMessage(), 'Sistema');
    retornar_json(false, "Erro ao processar protocolo: " . $e->getMessage());
    
} finally {
    fechar_conexao($conexao);
}
?>
