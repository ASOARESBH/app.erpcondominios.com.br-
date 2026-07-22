<?php
/**
 * =====================================================
 * API DE GERENCIAMENTO DE DADOS DA EMPRESA
 * =====================================================
 * 
 * Endpoints:
 * - GET  /api_empresa.php?action=obter          -> Obter dados da empresa
 * - POST /api_empresa.php?action=atualizar      -> Atualizar dados da empresa
 * - POST /api_empresa.php?action=upload_logo    -> Upload de logo
 * - GET  /api_empresa.php?action=validar_cnpj   -> Validar CNPJ
 * - GET  /api_empresa.php?action=buscar_cnpj    -> Buscar dados do CNPJ
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';
require_once 'auth_helper.php';

function retornar_json($sucesso, $mensagem, $dados = null) {
    echo json_encode([
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function registrar_log_empresa($empresa_id, $acao, $dados_anteriores, $dados_novos, $usuario_id) {
    global $conexao;
    try {
        $ip_usuario = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
        $stmt = $conexao->prepare("
            INSERT INTO empresa_log (empresa_id, acao, dados_anteriores, dados_novos, usuario_id, ip_usuario)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            error_log("[API EMPRESA] Erro ao preparar statement de log: " . $conexao->error);
            return false;
        }
        $dados_ant_json = json_encode($dados_anteriores, JSON_UNESCAPED_UNICODE);
        $dados_nov_json = json_encode($dados_novos, JSON_UNESCAPED_UNICODE);
        $stmt->bind_param("isssii", $empresa_id, $acao, $dados_ant_json, $dados_nov_json, $usuario_id, $ip_usuario);
        if (!$stmt->execute()) {
            error_log("[API EMPRESA] Erro ao executar log: " . $stmt->error);
            return false;
        }
        $stmt->close();
        return true;
    } catch (Exception $e) {
        error_log("[API EMPRESA] Exceção ao registrar log: " . $e->getMessage());
        return false;
    }
}

$metodo = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Ação 'obter' é pública (usada na página de login para identidade visual)
// As demais ações exigem autenticacao de admin
if ($action !== 'obter') {
    $usuario = verificarAutenticacao(true, 'admin');
    $usuario_id = $usuario['id'];
} else {
    $usuario_id = null;
}

$conexao = conectar_banco();

// OBTER DADOS DA EMPRESA (público)
if ($action === 'obter' && $metodo === 'GET') {
    try {
        $stmt = $conexao->prepare("
            SELECT id, cnpj, razao_social, nome_fantasia,
                   endereco_rua, endereco_numero, endereco_complemento,
                   endereco_bairro, endereco_cidade, endereco_estado, endereco_cep,
                   email_principal, email_cobranca, telefone,
                   logo_url, logo_nome_arquivo, situacao,
                   data_criacao, data_atualizacao
            FROM empresa LIMIT 1
        ");
        if (!$stmt) {
            error_log("[API EMPRESA] Erro ao preparar query: " . $conexao->error);
            retornar_json(false, 'Erro ao buscar dados da empresa');
        }
        $stmt->execute();
        $resultado = $stmt->get_result();
        $empresa = $resultado->fetch_assoc();
        $stmt->close();
        if ($empresa) {
            retornar_json(true, 'Dados da empresa obtidos com sucesso', $empresa);
        } else {
            retornar_json(true, 'Nenhuma empresa cadastrada', null);
        }
    } catch (Exception $e) {
        error_log("[API EMPRESA] Exceção ao obter dados: " . $e->getMessage());
        retornar_json(false, 'Erro ao obter dados da empresa');
    }
}

// ATUALIZAR DADOS DA EMPRESA
if ($action === 'atualizar' && $metodo === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $cnpj = $input['cnpj'] ?? '';
        $razao_social = $input['razao_social'] ?? '';
        $nome_fantasia = $input['nome_fantasia'] ?? '';
        $endereco_rua = $input['endereco_rua'] ?? '';
        $endereco_numero = $input['endereco_numero'] ?? '';
        $endereco_complemento = $input['endereco_complemento'] ?? '';
        $endereco_bairro = $input['endereco_bairro'] ?? '';
        $endereco_cidade = $input['endereco_cidade'] ?? '';
        $endereco_estado = $input['endereco_estado'] ?? '';
        $endereco_cep = $input['endereco_cep'] ?? '';
        $email_principal = $input['email_principal'] ?? '';
        $email_cobranca = $input['email_cobranca'] ?? '';
        $telefone = $input['telefone'] ?? '';
        $situacao = $input['situacao'] ?? 'ativo';
        
        if (empty($cnpj) || empty($razao_social) || empty($email_principal)) {
            retornar_json(false, 'CNPJ, Razão Social e E-mail principal são obrigatórios');
        }
        if (!filter_var($email_principal, FILTER_VALIDATE_EMAIL)) {
            retornar_json(false, 'E-mail principal inválido');
        }
        if (!empty($email_cobranca) && !filter_var($email_cobranca, FILTER_VALIDATE_EMAIL)) {
            retornar_json(false, 'E-mail de cobrança inválido');
        }
        
        $stmt_anterior = $conexao->prepare("SELECT * FROM empresa LIMIT 1");
        $stmt_anterior->execute();
        $resultado_anterior = $stmt_anterior->get_result();
        $dados_anteriores = $resultado_anterior->fetch_assoc();
        $stmt_anterior->close();
        
        if ($dados_anteriores) {
            $stmt = $conexao->prepare("
                UPDATE empresa
                SET cnpj = ?, razao_social = ?, nome_fantasia = ?,
                    endereco_rua = ?, endereco_numero = ?, endereco_complemento = ?,
                    endereco_bairro = ?, endereco_cidade = ?, endereco_estado = ?, endereco_cep = ?,
                    email_principal = ?, email_cobranca = ?, telefone = ?,
                    situacao = ?, usuario_atualizacao_id = ?
                WHERE id = ?
            ");
            if (!$stmt) {
                error_log("[API EMPRESA] Erro ao preparar update: " . $conexao->error);
                retornar_json(false, 'Erro ao atualizar dados da empresa');
            }
            $empresa_id = $dados_anteriores['id'];
            $stmt->bind_param(
                "ssssssssssssssii",
                $cnpj, $razao_social, $nome_fantasia,
                $endereco_rua, $endereco_numero, $endereco_complemento,
                $endereco_bairro, $endereco_cidade, $endereco_estado, $endereco_cep,
                $email_principal, $email_cobranca, $telefone,
                $situacao, $usuario_id, $empresa_id
            );
            if (!$stmt->execute()) {
                error_log("[API EMPRESA] Erro ao executar update: " . $stmt->error);
                retornar_json(false, 'Erro ao atualizar dados da empresa');
            }
            $stmt->close();
            
            $dados_novos = [
                'cnpj' => $cnpj, 'razao_social' => $razao_social, 'nome_fantasia' => $nome_fantasia,
                'endereco_rua' => $endereco_rua, 'endereco_numero' => $endereco_numero,
                'endereco_complemento' => $endereco_complemento, 'endereco_bairro' => $endereco_bairro,
                'endereco_cidade' => $endereco_cidade, 'endereco_estado' => $endereco_estado,
                'endereco_cep' => $endereco_cep, 'email_principal' => $email_principal,
                'email_cobranca' => $email_cobranca, 'telefone' => $telefone, 'situacao' => $situacao
            ];
            registrar_log_empresa($empresa_id, 'atualizar', $dados_anteriores, $dados_novos, $usuario_id);
            error_log("[API EMPRESA] Empresa atualizada: ID $empresa_id");
            retornar_json(true, 'Dados da empresa atualizados com sucesso', ['empresa_id' => $empresa_id]);
        } else {
            $stmt = $conexao->prepare("
                INSERT INTO empresa (
                    cnpj, razao_social, nome_fantasia,
                    endereco_rua, endereco_numero, endereco_complemento,
                    endereco_bairro, endereco_cidade, endereco_estado, endereco_cep,
                    email_principal, email_cobranca, telefone,
                    situacao, usuario_criacao_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                error_log("[API EMPRESA] Erro ao preparar insert: " . $conexao->error);
                retornar_json(false, 'Erro ao criar dados da empresa');
            }
            $stmt->bind_param(
                "ssssssssssssssi",
                $cnpj, $razao_social, $nome_fantasia,
                $endereco_rua, $endereco_numero, $endereco_complemento,
                $endereco_bairro, $endereco_cidade, $endereco_estado, $endereco_cep,
                $email_principal, $email_cobranca, $telefone,
                $situacao, $usuario_id
            );
            if (!$stmt->execute()) {
                error_log("[API EMPRESA] Erro ao executar insert: " . $stmt->error);
                retornar_json(false, 'Erro ao criar dados da empresa');
            }
            $empresa_id = $stmt->insert_id;
            $stmt->close();
            
            $dados_novos = [
                'cnpj' => $cnpj, 'razao_social' => $razao_social, 'nome_fantasia' => $nome_fantasia,
                'endereco_rua' => $endereco_rua, 'endereco_numero' => $endereco_numero,
                'endereco_complemento' => $endereco_complemento, 'endereco_bairro' => $endereco_bairro,
                'endereco_cidade' => $endereco_cidade, 'endereco_estado' => $endereco_estado,
                'endereco_cep' => $endereco_cep, 'email_principal' => $email_principal,
                'email_cobranca' => $email_cobranca, 'telefone' => $telefone, 'situacao' => $situacao
            ];
            registrar_log_empresa($empresa_id, 'criar', null, $dados_novos, $usuario_id);
            error_log("[API EMPRESA] Empresa criada: ID $empresa_id");
            retornar_json(true, 'Dados da empresa criados com sucesso', ['empresa_id' => $empresa_id]);
        }
    } catch (Exception $e) {
        error_log("[API EMPRESA] Exceção ao atualizar: " . $e->getMessage());
        retornar_json(false, 'Erro ao atualizar dados da empresa');
    }
}

// UPLOAD DE LOGO
if ($action === 'upload_logo' && $metodo === 'POST') {
    try {
        if (!isset($_FILES['logo'])) {
            retornar_json(false, 'Nenhum arquivo enviado');
        }
        $arquivo = $_FILES['logo'];
        $erro = $arquivo['error'];
        if ($erro !== UPLOAD_ERR_OK) {
            $mensagens_erro = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo muito grande (limite do servidor)',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande (limite do formulário)',
                UPLOAD_ERR_PARTIAL => 'Upload incompleto',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado',
                UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado',
                UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever arquivo',
                UPLOAD_ERR_EXTENSION => 'Extensão não permitida'
            ];
            $mensagem = $mensagens_erro[$erro] ?? 'Erro desconhecido no upload';
            error_log("[API EMPRESA] Erro de upload: $mensagem (código: $erro)");
            retornar_json(false, $mensagem);
        }
        
        $tipo_mime = mime_content_type($arquivo['tmp_name']);
        $tipos_permitidos = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($tipo_mime, $tipos_permitidos)) {
            error_log("[API EMPRESA] Tipo de arquivo não permitido: $tipo_mime");
            retornar_json(false, 'Tipo de arquivo não permitido. Use PNG, JPEG ou GIF');
        }
        
        $tamanho_maximo = 5 * 1024 * 1024;
        if ($arquivo['size'] > $tamanho_maximo) {
            error_log("[API EMPRESA] Arquivo muito grande: " . $arquivo['size'] . " bytes");
            retornar_json(false, 'Arquivo muito grande. Máximo 5MB');
        }
        
        // Caminho do diretório de uploads
        $diretorio_upload = dirname(__DIR__) . '/uploads/logo';
        if (!is_dir($diretorio_upload)) {
            mkdir($diretorio_upload, 0755, true);
        }
        
        // Remover logos anteriores para manter apenas uma
        $arquivos_existentes = glob($diretorio_upload . '/logo.*');
        foreach ($arquivos_existentes as $arq) {
            if (is_file($arq)) {
                unlink($arq);
            }
        }
        
        // Definir novo nome fixo
        $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        $nome_arquivo = 'logo.' . $extensao;
        $caminho_completo = $diretorio_upload . '/' . $nome_arquivo;
        
        if (!move_uploaded_file($arquivo['tmp_name'], $caminho_completo)) {
            error_log("[API EMPRESA] Erro ao mover arquivo para: $caminho_completo");
            retornar_json(false, 'Erro ao salvar arquivo');
        }
        
        // URL relativa para visualização no frontend
        $url_relativa = 'uploads/logo/' . $nome_arquivo;
        
        // Atualizar no banco de dados
        $stmt = $conexao->prepare("
            UPDATE empresa
            SET logo_url = ?, logo_nome_arquivo = ?, usuario_atualizacao_id = ?
            WHERE id = 1
        ");
        if ($stmt) {
            $stmt->bind_param("ssi", $url_relativa, $nome_arquivo, $usuario_id);
            $stmt->execute();
            $stmt->close();
        }
        
        error_log("[API EMPRESA] Logo atualizada com sucesso: $nome_arquivo");
        retornar_json(true, 'Logo atualizada com sucesso', ['url' => $url_relativa]);
        
    } catch (Exception $e) {
        error_log("[API EMPRESA] Exceção ao fazer upload: " . $e->getMessage());
        retornar_json(false, 'Erro ao fazer upload da logo');
    }
}

// VALIDAR CNPJ
if ($action === 'validar_cnpj' && $metodo === 'GET') {
    try {
        $cnpj = $_GET['cnpj'] ?? '';
        if (empty($cnpj)) {
            retornar_json(false, 'CNPJ não fornecido');
        }
        $cnpj_limpo = preg_replace('/[^0-9]/', '', $cnpj);
        if (strlen($cnpj_limpo) !== 14) {
            retornar_json(false, 'CNPJ deve conter 14 dígitos');
        }
        
        $tamanho = strlen($cnpj_limpo) - 2;
        $numeros = substr($cnpj_limpo, 0, $tamanho);
        $digitos = substr($cnpj_limpo, $tamanho);
        $soma = 0;
        $multiplicador = 2;
        for ($i = $tamanho - 1; $i >= 0; $i--) {
            $soma += $numeros[$i] * $multiplicador;
            $multiplicador++;
            if ($multiplicador > 9) $multiplicador = 2;
        }
        $resultado = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
        if ($resultado != $digitos[0]) {
            retornar_json(false, 'CNPJ inválido');
        }
        
        $soma = 0;
        $multiplicador = 2;
        for ($i = $tamanho; $i >= 0; $i--) {
            $soma += $cnpj_limpo[$i] * $multiplicador;
            $multiplicador++;
            if ($multiplicador > 9) $multiplicador = 2;
        }
        $resultado = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
        if ($resultado != $digitos[1]) {
            retornar_json(false, 'CNPJ inválido');
        }
        retornar_json(true, 'CNPJ válido', ['cnpj_formatado' => $cnpj_limpo]);
    } catch (Exception $e) {
        error_log("[API EMPRESA] Exceção ao validar CNPJ: " . $e->getMessage());
        retornar_json(false, 'Erro ao validar CNPJ');
    }
}

// BUSCAR DADOS DO CNPJ (API EXTERNA)
if ($action === 'buscar_cnpj' && $metodo === 'GET') {
    try {
        $cnpj = $_GET['cnpj'] ?? '';
        if (empty($cnpj)) {
            retornar_json(false, 'CNPJ não fornecido');
        }
        $cnpj_limpo = preg_replace('/[^0-9]/', '', $cnpj);
        if (strlen($cnpj_limpo) !== 14) {
            retornar_json(false, 'CNPJ deve conter 14 dígitos');
        }
        
        $url_api = "https://www.receitaws.com.br/v1/cnpj/$cnpj_limpo";
        $contexto = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'user_agent' => 'ERP-Serra-Liberdade/1.0'
            ]
        ]);
        $resposta = @file_get_contents($url_api, false, $contexto);
        if ($resposta === false) {
            error_log("[API EMPRESA] Erro ao consultar API de CNPJ");
            retornar_json(false, 'Erro ao consultar dados do CNPJ. Verifique a conexão com a internet');
        }
        $dados_cnpj = json_decode($resposta, true);
        if ($dados_cnpj && isset($dados_cnpj['status']) && $dados_cnpj['status'] === 'OK') {
            retornar_json(true, 'Dados do CNPJ obtidos com sucesso', [
                'razao_social' => $dados_cnpj['nome'] ?? '',
                'nome_fantasia' => $dados_cnpj['fantasia'] ?? '',
                'endereco_rua' => $dados_cnpj['logradouro'] ?? '',
                'endereco_numero' => $dados_cnpj['numero'] ?? '',
                'endereco_complemento' => $dados_cnpj['complemento'] ?? '',
                'endereco_bairro' => $dados_cnpj['bairro'] ?? '',
                'endereco_cidade' => $dados_cnpj['municipio'] ?? '',
                'endereco_estado' => $dados_cnpj['uf'] ?? '',
                'endereco_cep' => $dados_cnpj['cep'] ?? '',
                'telefone' => $dados_cnpj['telefone'] ?? '',
                'email_principal' => $dados_cnpj['email'] ?? ''
            ]);
        } else {
            retornar_json(false, 'CNPJ não encontrado na base de dados');
        }
    } catch (Exception $e) {
        error_log("[API EMPRESA] Exceção ao buscar CNPJ: " . $e->getMessage());
        retornar_json(false, 'Erro ao buscar dados do CNPJ');
    }
}

retornar_json(false, 'Ação não encontrada');
?>
