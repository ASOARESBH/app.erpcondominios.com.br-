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
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

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

// Segurança Multi-Tenant: toda leitura e gravação deve usar a sessão autenticada.
// A identidade visual pública é atendida por get_logo_empresa.php; esta API é administrativa.
$usuario = verificarAutenticacao(true, 'admin');
$tenant_id = exigirTenantId();
$usuario_id = (int)($usuario['id'] ?? $_SESSION['usuario_id'] ?? 0);

$conexao = conectar_banco();

function dados_empresa_consolidados($conexao, $tenant_id) {
    // tenants mantém a identidade comercial criada no Super-Admin.
    $stmtTenant = $conexao->prepare("SELECT id, slug, razao_social, nome_fantasia, cnpj, plano, status, logo_url, email_principal, telefone, cidade, estado FROM tenants WHERE id = ? LIMIT 1");
    if (!$stmtTenant) return null;
    $stmtTenant->bind_param('i', $tenant_id);
    $stmtTenant->execute();
    $tenant = $stmtTenant->get_result()->fetch_assoc();
    $stmtTenant->close();
    if (!$tenant) return null;

    // empresa complementa o tenant com endereço, contatos e informações operacionais.
    $empresa = null;
    $stmtEmpresa = $conexao->prepare("SELECT * FROM empresa WHERE tenant_id = ? LIMIT 1");
    if ($stmtEmpresa) {
        $stmtEmpresa->bind_param('i', $tenant_id);
        $stmtEmpresa->execute();
        $empresa = $stmtEmpresa->get_result()->fetch_assoc();
        $stmtEmpresa->close();
    }

    $valor = function($campoEmpresa, $campoTenant = null, $padrao = '') use ($empresa, $tenant) {
        if ($empresa && isset($empresa[$campoEmpresa]) && $empresa[$campoEmpresa] !== '') return $empresa[$campoEmpresa];
        $campoTenant = $campoTenant ?: $campoEmpresa;
        return $tenant[$campoTenant] ?? $padrao;
    };

    return [
        'id' => (int)($empresa['id'] ?? 0), 'tenant_id' => (int)$tenant_id, 'slug' => $tenant['slug'],
        'cnpj' => $valor('cnpj'), 'razao_social' => $valor('razao_social'), 'nome_fantasia' => $valor('nome_fantasia'),
        'endereco_rua' => $valor('endereco_rua'), 'endereco_numero' => $valor('endereco_numero'),
        'endereco_complemento' => $valor('endereco_complemento'), 'endereco_bairro' => $valor('endereco_bairro'),
        'endereco_cidade' => $valor('endereco_cidade', 'cidade'), 'endereco_estado' => $valor('endereco_estado', 'estado'),
        'endereco_cep' => $valor('endereco_cep'), 'email_principal' => $valor('email_principal'),
        'email_cobranca' => $valor('email_cobranca'), 'telefone' => $valor('telefone'),
        'logo_url' => $valor('logo_url'), 'logo_nome_arquivo' => $empresa['logo_nome_arquivo'] ?? basename((string)($tenant['logo_url'] ?? '')),
        'situacao' => $empresa['situacao'] ?? (($tenant['status'] ?? 'ativo') === 'ativo' ? 'ativo' : 'inativo'),
        'plano' => $tenant['plano'] ?? 'basico', 'origem_dados' => $empresa ? 'empresa_e_tenant' : 'tenant'
    ];
}

// OBTER DADOS DO TENANT ATIVO — consolida tabela tenants + detalhes da tabela empresa.
if ($action === 'obter' && $metodo === 'GET') {
    try {
        $dados = dados_empresa_consolidados($conexao, $tenant_id);
        if (!$dados) {
            error_log("[EMPRESA_MT] tenant_nao_encontrado tenant_id={$tenant_id} usuario_id={$usuario_id}");
            retornar_json(false, 'Condomínio ativo não encontrado', null);
        }
        error_log("[EMPRESA_MT] dados_carregados tenant_id={$tenant_id} origem={$dados['origem_dados']} usuario_id={$usuario_id}");
        retornar_json(true, 'Dados do condomínio obtidos com sucesso', $dados);
    } catch (Throwable $e) {
        error_log("[EMPRESA_MT] erro_obter tenant_id={$tenant_id}: " . $e->getMessage());
        retornar_json(false, 'Erro ao obter dados do condomínio', null);
    }
}

// ATUALIZAR DADOS — grava detalhes na tabela empresa e sincroniza a identidade no tenant.
if ($action === 'atualizar' && $metodo === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) retornar_json(false, 'Dados do formulário inválidos');
        foreach (['cnpj','razao_social','nome_fantasia','endereco_rua','endereco_numero','endereco_complemento','endereco_bairro','endereco_cidade','endereco_estado','endereco_cep','email_principal','email_cobranca','telefone'] as $campo) {
            ${$campo} = trim((string)($input[$campo] ?? ''));
        }
        $situacao = ($input['situacao'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo';
        if ($razao_social === '' || $email_principal === '') retornar_json(false, 'Razão Social e E-mail principal são obrigatórios');
        if (!filter_var($email_principal, FILTER_VALIDATE_EMAIL)) retornar_json(false, 'E-mail principal inválido');
        if ($email_cobranca !== '' && !filter_var($email_cobranca, FILTER_VALIDATE_EMAIL)) retornar_json(false, 'E-mail de cobrança inválido');

        $conexao->begin_transaction();
        $dados_anteriores = null;
        try {
            $stmtAnterior = $conexao->prepare('SELECT * FROM empresa WHERE tenant_id = ? LIMIT 1');
            $stmtAnterior->bind_param('i', $tenant_id);
            $stmtAnterior->execute();
            $dados_anteriores = $stmtAnterior->get_result()->fetch_assoc();
            $stmtAnterior->close();

            if ($dados_anteriores) {
                $empresa_id = (int)$dados_anteriores['id'];
                $stmt = $conexao->prepare('UPDATE empresa SET cnpj=?, razao_social=?, nome_fantasia=?, endereco_rua=?, endereco_numero=?, endereco_complemento=?, endereco_bairro=?, endereco_cidade=?, endereco_estado=?, endereco_cep=?, email_principal=?, email_cobranca=?, telefone=?, situacao=?, usuario_atualizacao_id=? WHERE tenant_id=? AND id=?');
                $stmt->bind_param('ssssssssssssssiii', $cnpj, $razao_social, $nome_fantasia, $endereco_rua, $endereco_numero, $endereco_complemento, $endereco_bairro, $endereco_cidade, $endereco_estado, $endereco_cep, $email_principal, $email_cobranca, $telefone, $situacao, $usuario_id, $tenant_id, $empresa_id);
                $stmt->execute();
                $stmt->close();
                $acao_log = 'atualizar';
            } else {
                $stmt = $conexao->prepare('INSERT INTO empresa (tenant_id, cnpj, razao_social, nome_fantasia, endereco_rua, endereco_numero, endereco_complemento, endereco_bairro, endereco_cidade, endereco_estado, endereco_cep, email_principal, email_cobranca, telefone, situacao, usuario_criacao_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issssssssssssssi', $tenant_id, $cnpj, $razao_social, $nome_fantasia, $endereco_rua, $endereco_numero, $endereco_complemento, $endereco_bairro, $endereco_cidade, $endereco_estado, $endereco_cep, $email_principal, $email_cobranca, $telefone, $situacao, $usuario_id);
                $stmt->execute();
                $empresa_id = (int)$stmt->insert_id;
                $stmt->close();
                $acao_log = 'criar';
            }

            $status_tenant = $situacao === 'ativo' ? 'ativo' : 'inativo';
            $stmtTenant = $conexao->prepare('UPDATE tenants SET cnpj=?, razao_social=?, nome_fantasia=?, email_principal=?, telefone=?, cidade=?, estado=?, status=? WHERE id=?');
            $stmtTenant->bind_param('ssssssssi', $cnpj, $razao_social, $nome_fantasia, $email_principal, $telefone, $endereco_cidade, $endereco_estado, $status_tenant, $tenant_id);
            $stmtTenant->execute();
            $stmtTenant->close();

            $dados_novos = ['cnpj'=>$cnpj,'razao_social'=>$razao_social,'nome_fantasia'=>$nome_fantasia,'email_principal'=>$email_principal,'telefone'=>$telefone,'situacao'=>$situacao];
            registrar_log_empresa($empresa_id, $acao_log, $dados_anteriores, $dados_novos, $usuario_id);
            $conexao->commit();
            $_SESSION['tenant_nome'] = $nome_fantasia ?: $razao_social;
            $_SESSION['tenant_razao_social'] = $razao_social;
            error_log("[EMPRESA_MT] dados_salvos tenant_id={$tenant_id} empresa_id={$empresa_id} usuario_id={$usuario_id}");
            retornar_json(true, 'Dados do condomínio salvos e sincronizados com o tenant', ['empresa_id'=>$empresa_id,'tenant_id'=>$tenant_id]);
        } catch (Throwable $e) {
            $conexao->rollback();
            throw $e;
        }
    } catch (Throwable $e) {
        error_log("[EMPRESA_MT] erro_atualizar tenant_id={$tenant_id}: " . $e->getMessage());
        retornar_json(false, 'Erro ao atualizar dados do condomínio');
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
        
        // ── Multi-Tenant: pasta separada por tenant_id ──────────────────────
        // Cada condomínio tem sua própria pasta: uploads/logo/tenant_{id}/
        $diretorio_upload = dirname(__DIR__) . '/uploads/logo/tenant_' . $tenant_id;
        if (!is_dir($diretorio_upload)) {
            mkdir($diretorio_upload, 0755, true);
        }

        // Remover logos anteriores deste tenant para manter apenas uma
        $arquivos_existentes = glob($diretorio_upload . '/logo.*');
        foreach ($arquivos_existentes as $arq) {
            if (is_file($arq)) {
                unlink($arq);
            }
        }

        // Definir novo nome fixo para o tenant
        $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        $nome_arquivo = 'logo.' . $extensao;
        $caminho_completo = $diretorio_upload . '/' . $nome_arquivo;

        if (!move_uploaded_file($arquivo['tmp_name'], $caminho_completo)) {
            error_log("[API EMPRESA] Erro ao mover arquivo para: $caminho_completo");
            retornar_json(false, 'Erro ao salvar arquivo');
        }

        // URL relativa isolada por tenant
        $url_relativa = 'uploads/logo/tenant_' . $tenant_id . '/' . $nome_arquivo;

        // Atualizar somente o registro associado ao tenant atual. Nunca usar id=1,
        // pois o primeiro registro da tabela pode pertencer a outro condomínio.
        $conexao->begin_transaction();
        $stmt = $conexao->prepare("UPDATE empresa SET logo_url = ?, logo_nome_arquivo = ?, usuario_atualizacao_id = ? WHERE tenant_id = ?");
        if ($stmt) {
            $stmt->bind_param("ssii", $url_relativa, $nome_arquivo, $usuario_id, $tenant_id);
            $stmt->execute();
            $stmt->close();
        }

        // tenants é a fonte usada pelo sidebar, Super-Admin e relatórios.
        $stmt2 = $conexao->prepare("UPDATE tenants SET logo_url = ? WHERE id = ?");
        if (!$stmt2) throw new Exception('Não foi possível preparar a atualização da logo do tenant');
        $stmt2->bind_param("si", $url_relativa, $tenant_id);
        $stmt2->execute();
        $stmt2->close();
        $conexao->commit();

        $_SESSION['tenant_logo_url'] = $url_relativa;
        error_log("[EMPRESA_MT] logo_atualizada tenant_id={$tenant_id} usuario_id={$usuario_id} arquivo={$nome_arquivo}");
        retornar_json(true, 'Logo atualizada com sucesso', ['url' => $url_relativa, 'tenant_id' => $tenant_id]);
        
    } catch (Throwable $e) {
        if (isset($conexao) && $conexao instanceof mysqli) {
            @$conexao->rollback();
        }
        error_log("[EMPRESA_MT] erro_upload_logo tenant_id={$tenant_id}: " . $e->getMessage());
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
                'user_agent' => 'ERP-Condominios/2.0'
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
