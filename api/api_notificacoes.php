<?php
// =====================================================
// API PARA GERENCIAMENTO DE NOTIFICAÇÕES
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
header('Access-Control-Allow-Origin: https://asl.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar autenticação
verificarAutenticacao(true, 'operador');

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// Para operações de escrita, verificar permissão de admin
if ($metodo !== 'GET') {
    verificarPermissao('admin');
}

// Criar diretório de uploads se não existir
$upload_dir = 'uploads/notificacoes/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// ========== LISTAR NOTIFICAÇÕES ==========
if ($metodo === 'GET' && !isset($_GET['id']) && !isset($_GET['relatorio'])) {
    $sql = "SELECT n.*,
            DATE_FORMAT(n.data_hora, '%d/%m/%Y %H:%i') as data_hora_formatada,
            (SELECT COUNT(*) FROM notificacoes_visualizacoes WHERE notificacao_id = n.id) as total_visualizacoes,
            (SELECT COUNT(*) FROM notificacoes_downloads WHERE notificacao_id = n.id) as total_downloads
            FROM notificacoes n
            WHERE n.ativo = 1
            ORDER BY n.data_hora DESC, n.numero_sequencial DESC";
    
    $resultado = $conexao->query($sql);
    $notificacoes = array();
    
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $notificacoes[] = $row;
        }
    }
    
    retornar_json(true, "Notificações listadas com sucesso", $notificacoes);
}

// ========== OBTER NOTIFICAÇÃO POR ID ==========
if ($metodo === 'GET' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    $stmt = $conexao->prepare("SELECT *, DATE_FORMAT(data_hora, '%Y-%m-%dT%H:%i') as data_hora_input FROM notificacoes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $notificacao = $resultado->fetch_assoc();
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(true, "Notificação encontrada", $notificacao);
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Notificação não encontrada");
    }
}

// ========== RELATÓRIO DE NOTIFICAÇÃO ==========
if ($metodo === 'GET' && isset($_GET['relatorio'])) {
    $id = intval($_GET['relatorio']);
    
    // Buscar dados da notificação
    $stmt = $conexao->prepare("SELECT *, DATE_FORMAT(data_hora, '%d/%m/%Y %H:%i') as data_hora_formatada FROM notificacoes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Notificação não encontrada");
    }
    
    $notificacao = $resultado->fetch_assoc();
    $stmt->close();
    
    // Buscar todos os moradores e suas interações
    $sql = "SELECT 
            m.id,
            m.nome,
            m.unidade,
            CASE WHEN v.id IS NOT NULL THEN 1 ELSE 0 END as visualizou,
            CASE WHEN d.id IS NOT NULL THEN 1 ELSE 0 END as baixou,
            v.data_visualizacao,
            d.data_download
            FROM moradores m
            LEFT JOIN notificacoes_visualizacoes v ON v.morador_id = m.id AND v.notificacao_id = ?
            LEFT JOIN (
                SELECT morador_id, notificacao_id, MIN(data_download) as data_download, MIN(id) as id
                FROM notificacoes_downloads
                WHERE notificacao_id = ?
                GROUP BY morador_id, notificacao_id
            ) d ON d.morador_id = m.id AND d.notificacao_id = ?
            WHERE m.ativo = 1
            ORDER BY m.unidade ASC, m.nome ASC";
    
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("iii", $id, $id, $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $moradores = array();
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $moradores[] = $row;
        }
    }
    
    $stmt->close();
    fechar_conexao($conexao);
    
    $dados = array(
        'notificacao' => $notificacao,
        'moradores' => $moradores
    );
    
    retornar_json(true, "Relatório gerado com sucesso", $dados);
}

// ========== CRIAR/ATUALIZAR NOTIFICAÇÃO ==========
if ($metodo === 'POST') {
    $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : 0;
    $data_hora = sanitizar($conexao, $_POST['data_hora'] ?? '');
    $assunto = sanitizar($conexao, $_POST['assunto'] ?? '');
    $resumo = sanitizar($conexao, $_POST['resumo'] ?? '');
    
    // Validações
    if (empty($data_hora) || empty($assunto) || empty($resumo)) {
        retornar_json(false, "Todos os campos obrigatórios devem ser preenchidos");
    }
    
    // Processar upload de arquivo
    $anexo_nome = null;
    $anexo_caminho = null;
    $anexo_tipo = null;
    
    if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Verificar erros de upload
        if ($_FILES['anexo']['error'] !== UPLOAD_ERR_OK) {
            $erro_msg = "Erro no upload: ";
            switch ($_FILES['anexo']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $erro_msg .= "Arquivo muito grande";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $erro_msg .= "Upload incompleto";
                    break;
                default:
                    $erro_msg .= "Erro desconhecido (" . $_FILES['anexo']['error'] . ")";
            }
            retornar_json(false, $erro_msg);
        }
        
        $arquivo = $_FILES['anexo'];
        $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        $extensoes_permitidas = array('pdf', 'jpg', 'jpeg', 'png');
        
        if (!in_array($extensao, $extensoes_permitidas)) {
            retornar_json(false, "Formato de arquivo não permitido. Use: PDF, JPG ou PNG");
        }
        
        if ($arquivo['size'] > 10 * 1024 * 1024) { // 10MB
            retornar_json(false, "Arquivo muito grande. Tamanho máximo: 10MB");
        }
        
        // Verificar se o diretório existe e tem permissão de escrita
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                retornar_json(false, "Erro: Não foi possível criar diretório de upload");
            }
        }
        
        if (!is_writable($upload_dir)) {
            retornar_json(false, "Erro: Diretório de upload sem permissão de escrita");
        }
        
        $anexo_nome = $arquivo['name'];
        $anexo_tipo = $arquivo['type'];
        $nome_arquivo = time() . '_' . uniqid() . '.' . $extensao;
        $anexo_caminho = $upload_dir . $nome_arquivo;
        
        if (!move_uploaded_file($arquivo['tmp_name'], $anexo_caminho)) {
            $erro = error_get_last();
            retornar_json(false, "Erro ao fazer upload do arquivo. Verifique permissões do diretório uploads/notificacoes/");
        }
    }
    
    if ($id > 0) {
        // ATUALIZAR
        $stmt = $conexao->prepare("SELECT anexo_caminho FROM notificacoes WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        if ($resultado->num_rows === 0) {
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(false, "Notificação não encontrada");
        }
        
        $notif_anterior = $resultado->fetch_assoc();
        $stmt->close();
        
        // Se novo arquivo foi enviado, excluir o anterior
        if ($anexo_caminho && $notif_anterior['anexo_caminho'] && file_exists($notif_anterior['anexo_caminho'])) {
            unlink($notif_anterior['anexo_caminho']);
        }
        
        if ($anexo_caminho) {
            $stmt = $conexao->prepare("UPDATE notificacoes SET data_hora = ?, assunto = ?, resumo = ?, anexo_nome = ?, anexo_caminho = ?, anexo_tipo = ? WHERE id = ?");
            $stmt->bind_param("ssssssi", $data_hora, $assunto, $resumo, $anexo_nome, $anexo_caminho, $anexo_tipo, $id);
        } else {
            $stmt = $conexao->prepare("UPDATE notificacoes SET data_hora = ?, assunto = ?, resumo = ? WHERE id = ?");
            $stmt->bind_param("sssi", $data_hora, $assunto, $resumo, $id);
        }
        
        if ($stmt->execute()) {
            registrar_log('INFO', "Notificação atualizada: ID $id");
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(true, "Notificação atualizada com sucesso");
        } else {
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(false, "Erro ao atualizar notificação: " . $stmt->error);
        }
        
    } else {
        // CRIAR
        // Obter próximo número sequencial
        $resultado = $conexao->query("SELECT MAX(numero_sequencial) as max_num FROM notificacoes");
        $row = $resultado->fetch_assoc();
        $numero_sequencial = ($row['max_num'] ?? 0) + 1;
        
        $stmt = $conexao->prepare("INSERT INTO notificacoes (numero_sequencial, data_hora, assunto, resumo, anexo_nome, anexo_caminho, anexo_tipo) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssss", $numero_sequencial, $data_hora, $assunto, $resumo, $anexo_nome, $anexo_caminho, $anexo_tipo);
        
        if ($stmt->execute()) {
            $novo_id = $conexao->insert_id;
            registrar_log('INFO', "Notificação criada: #$numero_sequencial (ID: $novo_id)");
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(true, "Notificação criada com sucesso", array('id' => $novo_id, 'numero' => $numero_sequencial));
        } else {
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(false, "Erro ao criar notificação: " . $stmt->error);
        }
    }
}

// ========== EXCLUIR NOTIFICAÇÃO ==========
if ($metodo === 'DELETE') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id = intval($dados['id'] ?? 0);
    
    if ($id <= 0) {
        retornar_json(false, "ID inválido");
    }
    
    // Buscar caminho do anexo antes de excluir
    $stmt = $conexao->prepare("SELECT anexo_caminho FROM notificacoes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $notif = $resultado->fetch_assoc();
        $stmt->close();
        
        // Excluir arquivo físico se existir
        if ($notif['anexo_caminho'] && file_exists($notif['anexo_caminho'])) {
            unlink($notif['anexo_caminho']);
        }
        
        // Marcar como inativo (soft delete)
        $stmt = $conexao->prepare("UPDATE notificacoes SET ativo = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            registrar_log('INFO', "Notificação excluída: ID $id");
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(true, "Notificação excluída com sucesso");
        } else {
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(false, "Erro ao excluir notificação");
        }
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, "Notificação não encontrada");
    }
}

fechar_conexao($conexao);
retornar_json(false, "Método não permitido");

