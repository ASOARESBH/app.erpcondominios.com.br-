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
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/tenant_file_storage_helper.php';

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

    // O caminho legado continua no banco para compatibilidade, mas o frontend
    // administrativo recebe a URL autenticada do BLOB para não depender de
    // /uploads/. A consulta sempre usa o tenant da sessão recebida pela API.
    $logoLegada = (string)$valor('logo_url');
    $logoSegura = null;
    if ($logoLegada !== '') {
        $stmtLogo = $conexao->prepare('SELECT id FROM tenant_arquivos WHERE tenant_id = ? AND caminho_legado = ? AND ativo = 1 LIMIT 1');
        if ($stmtLogo) {
            $stmtLogo->bind_param('is', $tenant_id, $logoLegada);
            $stmtLogo->execute();
            $arquivoLogo = $stmtLogo->get_result()->fetch_assoc();
            $stmtLogo->close();
            if ($arquivoLogo) $logoSegura = '/api/api_arquivos_tenant.php?acao=conteudo&id=' . (int)$arquivoLogo['id'];
        }
    }

    return [
        'id' => (int)($empresa['id'] ?? 0), 'tenant_id' => (int)$tenant_id, 'slug' => $tenant['slug'],
        'cnpj' => $valor('cnpj'), 'razao_social' => $valor('razao_social'), 'nome_fantasia' => $valor('nome_fantasia'),
        'endereco_rua' => $valor('endereco_rua'), 'endereco_numero' => $valor('endereco_numero'),
        'endereco_complemento' => $valor('endereco_complemento'), 'endereco_bairro' => $valor('endereco_bairro'),
        'endereco_cidade' => $valor('endereco_cidade', 'cidade'), 'endereco_estado' => $valor('endereco_estado', 'estado'),
        'endereco_cep' => $valor('endereco_cep'), 'email_principal' => $valor('email_principal'),
        'email_cobranca' => $valor('email_cobranca'), 'telefone' => $valor('telefone'),
        'logo_url' => $logoLegada, 'logo_url_segura' => $logoSegura, 'logo_nome_arquivo' => $empresa['logo_nome_arquivo'] ?? basename((string)($tenant['logo_url'] ?? '')),
        'situacao' => $empresa['situacao'] ?? (($tenant['status'] ?? 'ativo') === 'ativo' ? 'ativo' : 'inativo'),
        'plano' => $tenant['plano'] ?? 'basico', 'origem_dados' => $empresa ? 'empresa_e_tenant' : 'tenant'
    ];
}

function estrutura_layout_administradora_disponivel($conexao) {
    foreach (['administradoras_importacao', 'administradoras_layouts', 'empresa_administradora', 'empresa_administradora_layout'] as $tabela) {
        $stmt = $conexao->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        if (!$stmt) return false;
        $stmt->bind_param('s', $tabela);
        $stmt->execute();
        $linha = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($linha['total'] ?? 0) === 0) return false;
    }
    return true;
}

function obter_configuracao_administradora($conexao, $tenant_id) {
    $stmt = $conexao->prepare('SELECT ea.administradora_id, a.slug, a.nome FROM empresa_administradora ea INNER JOIN administradoras_importacao a ON a.id=ea.administradora_id WHERE ea.tenant_id=? AND ea.ativo=1 LIMIT 1');
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $config = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $config ?: null;
}

function listar_layouts_administradora($conexao, $tenant_id) {
    $stmt = $conexao->prepare('SELECT al.id, al.administradora_id, a.slug AS administradora_slug, a.nome AS administradora_nome, al.codigo, al.modulo, al.nome, al.descricao, al.formato_aceito, al.status_implantacao, al.ordem, CASE WHEN eal.ativo=1 THEN 1 ELSE 0 END AS selecionado FROM administradoras_layouts al INNER JOIN administradoras_importacao a ON a.id=al.administradora_id LEFT JOIN empresa_administradora_layout eal ON eal.administradora_layout_id=al.id AND eal.tenant_id=? WHERE al.ativo=1 AND a.ativo=1 ORDER BY a.ordem, al.ordem, al.nome');
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $layouts = [];
    while ($linha = $resultado->fetch_assoc()) $layouts[] = $linha;
    $stmt->close();
    return $layouts;
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

// LAYOUT ADMINISTRADORA — cadastro de preferência de importação do tenant autenticado.
if ($action === 'administradoras_layouts' && $metodo === 'GET') {
    try {
        if (!estrutura_layout_administradora_disponivel($conexao)) {
            retornar_json(false, 'Estrutura de Layout Administradora não instalada. Execute a migration_layout_administradora_mysql57.sql.', null);
        }
        $administradoras = [];
        $res = $conexao->query('SELECT id,slug,nome,descricao FROM administradoras_importacao WHERE ativo=1 ORDER BY ordem,nome');
        while ($linha = $res->fetch_assoc()) $administradoras[] = $linha;
        $config = obter_configuracao_administradora($conexao, $tenant_id);
        $layouts = listar_layouts_administradora($conexao, $tenant_id);
        error_log("[EMPRESA_MT] administradora_layouts_carregado tenant_id={$tenant_id} usuario_id={$usuario_id} layouts=" . count($layouts));
        retornar_json(true, 'Configuração de administradora carregada.', ['administradoras'=>$administradoras, 'configuracao'=>$config, 'layouts'=>$layouts]);
    } catch (Throwable $e) {
        error_log("[EMPRESA_MT] erro_administradora_layouts tenant_id={$tenant_id}: " . $e->getMessage());
        retornar_json(false, 'Erro ao carregar Layout Administradora.', null);
    }
}

if ($action === 'salvar_layout_administradora' && $metodo === 'POST') {
    try {
        if (!estrutura_layout_administradora_disponivel($conexao)) {
            retornar_json(false, 'Estrutura de Layout Administradora não instalada. Execute a migration_layout_administradora_mysql57.sql.', null);
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) retornar_json(false, 'Dados de Layout Administradora inválidos.', null);
        $administradoraId = (int)($input['administradora_id'] ?? 0);
        $layoutIds = array_values(array_unique(array_filter(array_map('intval', (array)($input['layout_ids'] ?? [])), static function($id) { return $id > 0; })));
        if ($administradoraId <= 0) retornar_json(false, 'Selecione a administradora do empreendimento.', null);
        if (!$layoutIds) retornar_json(false, 'Selecione pelo menos um layout analítico para importação.', null);

        $stmtAdministradora = $conexao->prepare('SELECT id,nome FROM administradoras_importacao WHERE id=? AND ativo=1 LIMIT 1');
        $stmtAdministradora->bind_param('i', $administradoraId);
        $stmtAdministradora->execute();
        $administradora = $stmtAdministradora->get_result()->fetch_assoc();
        $stmtAdministradora->close();
        if (!$administradora) retornar_json(false, 'Administradora selecionada não está disponível.', null);

        $marcadores = implode(',', array_fill(0, count($layoutIds), '?'));
        $tipos = 'i' . str_repeat('i', count($layoutIds));
        $parametros = array_merge([$administradoraId], $layoutIds);
        $stmtLayouts = $conexao->prepare("SELECT id,modulo,nome FROM administradoras_layouts WHERE administradora_id=? AND ativo=1 AND id IN ({$marcadores}) ORDER BY ordem,id");
        if (!$stmtLayouts) throw new RuntimeException($conexao->error);
        $referencias = [];
        $referencias[] = $tipos;
        foreach ($parametros as $indice => $valor) $referencias[] = &$parametros[$indice];
        call_user_func_array([$stmtLayouts, 'bind_param'], $referencias);
        $stmtLayouts->execute();
        $layoutsValidos = [];
        $modulos = [];
        $resultadoLayouts = $stmtLayouts->get_result();
        while ($layout = $resultadoLayouts->fetch_assoc()) {
            if (isset($modulos[$layout['modulo']])) retornar_json(false, 'Selecione somente um layout por módulo analítico.', null);
            $layoutsValidos[] = $layout;
            $modulos[$layout['modulo']] = true;
        }
        $stmtLayouts->close();
        if (count($layoutsValidos) !== count($layoutIds)) retornar_json(false, 'Um ou mais layouts não pertencem à administradora selecionada.', null);

        $conexao->begin_transaction();
        try {
            $stmtConfig = $conexao->prepare('INSERT INTO empresa_administradora (tenant_id,administradora_id,ativo,usuario_atualizacao_id) VALUES (?,?,1,?) ON DUPLICATE KEY UPDATE administradora_id=VALUES(administradora_id),ativo=1,usuario_atualizacao_id=VALUES(usuario_atualizacao_id)');
            $stmtConfig->bind_param('iii', $tenant_id, $administradoraId, $usuario_id);
            if (!$stmtConfig->execute()) throw new RuntimeException($stmtConfig->error);
            $stmtConfig->close();

            $stmtDesativar = $conexao->prepare('UPDATE empresa_administradora_layout SET ativo=0, usuario_atualizacao_id=? WHERE tenant_id=?');
            $stmtDesativar->bind_param('ii', $usuario_id, $tenant_id);
            if (!$stmtDesativar->execute()) throw new RuntimeException($stmtDesativar->error);
            $stmtDesativar->close();

            $stmtSalvar = $conexao->prepare('INSERT INTO empresa_administradora_layout (tenant_id,administradora_layout_id,modulo,ativo,usuario_atualizacao_id) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE administradora_layout_id=VALUES(administradora_layout_id),ativo=1,usuario_atualizacao_id=VALUES(usuario_atualizacao_id)');
            foreach ($layoutsValidos as $layout) {
                $layoutId = (int)$layout['id'];
                $modulo = (string)$layout['modulo'];
                $stmtSalvar->bind_param('iisi', $tenant_id, $layoutId, $modulo, $usuario_id);
                if (!$stmtSalvar->execute()) throw new RuntimeException($stmtSalvar->error);
            }
            $stmtSalvar->close();
            $conexao->commit();
            error_log("[EMPRESA_MT] administradora_layouts_salvos tenant_id={$tenant_id} administradora_id={$administradoraId} usuario_id={$usuario_id} layouts=" . count($layoutsValidos));
            retornar_json(true, 'Administradora e layouts de importação salvos.', ['administradora'=>$administradora, 'layout_ids'=>array_map(static function($item) { return (int)$item['id']; }, $layoutsValidos)]);
        } catch (Throwable $e) {
            $conexao->rollback();
            throw $e;
        }
    } catch (Throwable $e) {
        error_log("[EMPRESA_MT] erro_salvar_administradora_layouts tenant_id={$tenant_id}: " . $e->getMessage());
        retornar_json(false, 'Erro ao salvar Layout Administradora.', null);
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
        
        // Armazenamento Multi-Tenant centralizado no banco. O caminho legado é
        // preservado somente como chave de compatibilidade para links existentes.
        $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        $nome_arquivo = 'logo.' . $extensao;
        $url_relativa = 'uploads/logo/tenant_' . $tenant_id . '/' . $nome_arquivo;
        $arquivoBanco = tenant_file_gravar_upload(
            $conexao,
            (int)$tenant_id,
            $arquivo,
            'logo_tenant',
            $url_relativa,
            false,
            (int)$usuario_id
        );
        // caminho_legado permanece somente para links antigos; a interface nova
        // deve usar a URL autenticada do BLOB retornada pelo helper central.
        $url_relativa = $arquivoBanco['caminho_legado'];
        $url_segura = $arquivoBanco['url'];

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

        // A sessão mantém a URL segura para consumidores novos; o banco retém
        // caminho_legado como chave de compatibilidade do importador/rewrite.
        $_SESSION['tenant_logo_url'] = $url_segura;
        error_log("[EMPRESA_MT] logo_atualizada tenant_id={$tenant_id} usuario_id={$usuario_id} arquivo={$nome_arquivo}");
        retornar_json(true, 'Logo atualizada com sucesso', [
            'url' => $url_relativa,
            'url_segura' => $url_segura,
            'caminho_legado' => $url_relativa,
            'tenant_id' => $tenant_id
        ]);
        
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
