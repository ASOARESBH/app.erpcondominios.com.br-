<?php
// =====================================================
// API PARA CRUD DE USUÁRIOS
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
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar autenticação — GET permite gerente, demais operações exigem admin
verificarAutenticacao(true);
$tenant_id = exigirTenantId();

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// RBAC assume a autorização efetiva quando as tabelas já foram migradas.
// Antes da migration, preserva a hierarquia legada para não interromper o ERP.
$rbac_ativo = rbacTabelasDisponiveis($conexao);
if ($rbac_ativo) {
    rbacExigirPoliticaApi($conexao);
} elseif ($metodo !== 'GET') {
    verificarPermissao('admin');
} else {
    verificarPermissao('gerente');
}

// ========== TOGGLE STATUS (ATIVAR / INATIVAR) ==========
if ($metodo === 'PATCH') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id    = intval($dados['id'] ?? 0);
    $acao  = trim($dados['acao'] ?? '');

    if ($id <= 0 || !in_array($acao, ['ativar', 'inativar'])) {
        retornar_json(false, 'Parâmetros inválidos. Informe id e acao (ativar|inativar)');
    }

    // Não permitir inativar o administrador principal (ID 1)
    if ($id == 1 && $acao === 'inativar') {
        retornar_json(false, 'Não é possível inativar o administrador principal do sistema');
    }

    // Buscar dados atuais sempre no tenant da sessão.
    $stmt = $conexao->prepare('SELECT nome, ativo, permissao FROM usuarios WHERE tenant_id = ? AND id = ? LIMIT 1');
    $stmt->bind_param('ii', $tenant_id, $id);
    $stmt->execute();
    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$usuario) {
        retornar_json(false, 'Usuário não encontrado');
    }

    // Verificar se já está no estado desejado
    $novo_ativo = ($acao === 'ativar') ? 1 : 0;
    if ((int)$usuario['ativo'] === $novo_ativo) {
        $estado = $novo_ativo ? 'ativo' : 'inativo';
        retornar_json(true, "Usuário já está {$estado}", ['ativo' => $novo_ativo]);
    }

    // Aplicar alteração somente no tenant da sessão.
    $stmt = $conexao->prepare('UPDATE usuarios SET ativo = ? WHERE tenant_id = ? AND id = ?');
    $stmt->bind_param('iii', $novo_ativo, $tenant_id, $id);

    if ($stmt->execute()) {
        $stmt->close();

        // Encerrar sessões ativas do usuário ao inativar
        if ($novo_ativo === 0) {
            // Invalidar sessões do mesmo tenant sem interpolar parâmetros do usuário.
            $stmt_sessao = $conexao->prepare('DELETE FROM sessoes_usuarios WHERE usuario_id = ? AND tenant_id = ?');
            if ($stmt_sessao) { $stmt_sessao->bind_param('ii', $id, $tenant_id); $stmt_sessao->execute(); $stmt_sessao->close(); }
            if ($rbac_ativo) {
                $stmt_rbac = $conexao->prepare("UPDATE rbac_sessoes SET status='REVOGADA', encerrada_em=NOW(), motivo_encerramento='USUARIO_INATIVADO', encerrado_por_usuario_id=? WHERE usuario_id=? AND tenant_id=? AND status='ATIVA'");
                if ($stmt_rbac) { $ator=(int)($_SESSION['usuario_id'] ?? 0); $stmt_rbac->bind_param('iii',$ator,$id,$tenant_id); $stmt_rbac->execute(); $stmt_rbac->close(); }
            }
        }

        $acao_log = $novo_ativo ? 'USUARIO_ATIVADO' : 'USUARIO_INATIVADO';
        $msg_log  = $novo_ativo
            ? "Usuário ATIVADO: {$usuario['nome']} (ID: {$id})"
            : "Usuário INATIVADO: {$usuario['nome']} (ID: {$id}) — acesso ao sistema bloqueado";
        if ($rbac_ativo) {
            rbacInvalidarCache($conexao, $tenant_id);
            rbacAuditar($conexao, ['modulo_chave'=>'usuarios','acao'=>$novo_ativo ? 'DESBLOQUEAR' : 'BLOQUEAR','registro_tipo'=>'usuarios','registro_id'=>$id,'dados_antes'=>['ativo'=>(int)$usuario['ativo']],'dados_depois'=>['ativo'=>$novo_ativo],'resultado'=>'SUCESSO','status_http'=>200]);
        }
        registrar_log($acao_log, $msg_log, $usuario['nome']);

        $msg_ret = $novo_ativo
            ? "Usuário {$usuario['nome']} ativado com sucesso. O acesso ao sistema foi restaurado."
            : "Usuário {$usuario['nome']} inativado. O acesso ao sistema foi bloqueado. Todo o histórico foi preservado.";

        retornar_json(true, $msg_ret, ['ativo' => $novo_ativo, 'nome' => $usuario['nome']]);
    } else {
        retornar_json(false, 'Erro ao alterar status: ' . $stmt->error);
    }
}

// ========== LISTAR USUÁRIOS ==========
if ($metodo === 'GET') {
    if (isset($_GET['id'])) {
        // Buscar usuário específico
        $id = intval($_GET['id']);
        $stmt = $conexao->prepare("SELECT id, nome, email, funcao, departamento, permissao, ativo, COALESCE(sessao_inativa,0) AS sessao_inativa, DATE_FORMAT(data_criacao, '%d/%m/%Y %H:%i') as data_criacao FROM usuarios WHERE tenant_id = $tenant_id AND id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        if ($row = $resultado->fetch_assoc()) {
            retornar_json(true, "Usuário encontrado", $row);
        } else {
            retornar_json(false, "Usuário não encontrado");
        }
    } else {
        // Listar todos os usuários
        $sql = "SELECT id, nome, email, funcao, departamento, permissao, ativo,
                COALESCE(sessao_inativa,0) AS sessao_inativa,
                DATE_FORMAT(data_criacao, '%d/%m/%Y %H:%i') as data_criacao
                FROM usuarios WHERE tenant_id = $tenant_id ORDER BY nome ASC";
        
        $resultado = $conexao->query($sql);
        $usuarios = array();
        
        if ($resultado && $resultado->num_rows > 0) {
            while ($row = $resultado->fetch_assoc()) {
                $usuarios[] = $row;
            }
        }
        
        retornar_json(true, "Usuários listados com sucesso", $usuarios);
    }
}

// ========== CRIAR USUÁRIO ==========
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $nome = sanitizar($conexao, $dados['nome'] ?? '');
    $email = sanitizar($conexao, $dados['email'] ?? '');
    $senha = $dados['senha'] ?? '';
    $funcao = sanitizar($conexao, $dados['funcao'] ?? '');
    $departamento = sanitizar($conexao, $dados['departamento'] ?? '');
    $permissao = sanitizar($conexao, $dados['permissao'] ?? 'visualizador');
    // Menor privilégio: o formulário não pode criar Super Admin. Para atores
    // sem gestão de segurança, qualquer tentativa de perfil superior é reduzida.
    if ($permissao === 'super_admin') retornar_json(false, 'O perfil Super Admin não pode ser concedido por esta API.');
    if ($rbac_ativo && !rbacPode($conexao, 'usuarios', 'configurar') && $permissao !== 'visualizador') $permissao = 'visualizador';
    $ativo = isset($dados['ativo']) ? intval($dados['ativo']) : 1;
    $sessao_inativa = isset($dados['sessao_inativa']) ? intval($dados['sessao_inativa']) : 0;
    
    // Validações
    if (empty($nome) || empty($email) || empty($senha) || empty($funcao)) {
        retornar_json(false, "Todos os campos obrigatórios devem ser preenchidos");
    }
    
    // Verificar se email já existe
    $stmt = $conexao->prepare("SELECT id FROM usuarios WHERE tenant_id = $tenant_id AND email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $stmt->close();
        retornar_json(false, "Email já cadastrado no sistema");
    }
    $stmt->close();
    
    // Criptografar senha
    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
    
    // Inserir usuário no tenant atual; o tenant jamais é aceito do navegador.
    $stmt = $conexao->prepare("INSERT INTO usuarios (tenant_id, nome, email, senha, funcao, departamento, permissao, ativo, sessao_inativa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssii", $tenant_id, $nome, $email, $senha_hash, $funcao, $departamento, $permissao, $ativo, $sessao_inativa);
    
    if ($stmt->execute()) {
        $id_inserido = $conexao->insert_id;
        // Compatibilidade multi-tenant para rotinas que resolvem o contexto pelo vínculo.
        $vinculo = $conexao->prepare('INSERT IGNORE INTO usuario_tenant (usuario_id, tenant_id, ativo) VALUES (?, ?, 1)');
        if ($vinculo) { $vinculo->bind_param('ii', $id_inserido, $tenant_id); $vinculo->execute(); $vinculo->close(); }
        if ($rbac_ativo) {
            $slug_grupo_inicial = in_array($permissao, ['visualizador','operador','gerente','admin'], true) ? 'compat-' . $permissao : 'compat-visualizador';
            $grupo = $conexao->prepare("SELECT id FROM rbac_grupos WHERE tenant_id=? AND slug=? AND ativo=1 LIMIT 1");
            if ($grupo) {
                $grupo->bind_param('is', $tenant_id, $slug_grupo_inicial);
                $grupo->execute();
                $linha_grupo = $grupo->get_result()->fetch_assoc();
                $grupo->close();
                $grupo_id = (int)($linha_grupo['id'] ?? 0);
                if ($grupo_id > 0) {
                    $associacao = $conexao->prepare('INSERT IGNORE INTO rbac_usuario_grupos (usuario_id,tenant_id,grupo_id,ativo,atribuido_por_usuario_id) VALUES (?,?,?,?,?)');
                    if ($associacao) { $ator=(int)($_SESSION['usuario_id'] ?? 0); $um=1; $associacao->bind_param('iiiii',$id_inserido,$tenant_id,$grupo_id,$um,$ator); $associacao->execute(); $associacao->close(); }
                }
            }
            rbacInvalidarCache($conexao, $tenant_id);
            rbacAuditar($conexao, ['modulo_chave'=>'usuarios','acao'=>'CRIAR','registro_tipo'=>'usuarios','registro_id'=>$id_inserido,'dados_depois'=>['nome'=>$nome,'email'=>$email,'perfil'=>$permissao],'resultado'=>'SUCESSO','status_http'=>201]);
        }
        registrar_log('USUARIO_CRIADO', "Usuário criado: $nome (ID: $id_inserido)", $nome);
        retornar_json(true, "Usuário cadastrado com sucesso", array('id' => $id_inserido));
    } else {
        retornar_json(false, "Erro ao cadastrar usuário: " . $stmt->error);
    }
    
    $stmt->close();
}

// ========== ATUALIZAR USUÁRIO ==========
if ($metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($dados['id'] ?? 0);
    $nome = sanitizar($conexao, $dados['nome'] ?? '');
    $email = sanitizar($conexao, $dados['email'] ?? '');
    $funcao = sanitizar($conexao, $dados['funcao'] ?? '');
    $departamento = sanitizar($conexao, $dados['departamento'] ?? '');
    $permissao = sanitizar($conexao, $dados['permissao'] ?? 'operador');
    $ativo = isset($dados['ativo']) ? intval($dados['ativo']) : 1;
    $sessao_inativa = isset($dados['sessao_inativa']) ? intval($dados['sessao_inativa']) : 0;
    
    // Validações
    if ($id <= 0 || empty($nome) || empty($email) || empty($funcao)) {
        retornar_json(false, "Dados inválidos para atualização");
    }
    
    // Carregar estado anterior somente no tenant da sessão, para auditoria e
    // bloqueio de alteração de privilégio sem a permissão administrativa.
    $estado = $conexao->prepare('SELECT nome,email,funcao,departamento,permissao,ativo,sessao_inativa FROM usuarios WHERE tenant_id=? AND id=? LIMIT 1');
    $estado->bind_param('ii', $tenant_id, $id);
    $estado->execute();
    $antes = $estado->get_result()->fetch_assoc();
    $estado->close();
    if (!$antes) retornar_json(false, 'Usuário não encontrado neste condomínio');
    if ($permissao === 'super_admin') retornar_json(false, 'O perfil Super Admin não pode ser concedido por esta API.');
    if ($rbac_ativo && !rbacPode($conexao, 'usuarios', 'configurar') && $permissao !== $antes['permissao']) {
        retornar_json(false, 'Você não possui autorização para alterar o perfil de acesso deste usuário.');
    }
    if ($id === (int)($_SESSION['usuario_id'] ?? 0) && $permissao !== $antes['permissao']) {
        retornar_json(false, 'Não é permitido alterar o próprio perfil de acesso.');
    }

    // Verificar se email já existe em outro usuário
    $stmt = $conexao->prepare('SELECT id FROM usuarios WHERE tenant_id = ? AND email = ? AND id != ?');
    $stmt->bind_param("isi", $tenant_id, $email, $id);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $stmt->close();
        retornar_json(false, "Email já cadastrado para outro usuário");
    }
    $stmt->close();
    
    // Atualizar com ou sem senha
    if (isset($dados['senha']) && !empty($dados['senha']) && $dados['senha'] !== '********') {
        $senha_hash = password_hash($dados['senha'], PASSWORD_DEFAULT);
        $stmt = $conexao->prepare('UPDATE usuarios SET nome=?, email=?, senha=?, funcao=?, departamento=?, permissao=?, ativo=?, sessao_inativa=? WHERE tenant_id=? AND id=?');
        $stmt->bind_param("sssssssiii", $nome, $email, $senha_hash, $funcao, $departamento, $permissao, $ativo, $sessao_inativa, $tenant_id, $id);
    } else {
        $stmt = $conexao->prepare('UPDATE usuarios SET nome=?, email=?, funcao=?, departamento=?, permissao=?, ativo=?, sessao_inativa=? WHERE tenant_id=? AND id=?');
        $stmt->bind_param("ssssssiii", $nome, $email, $funcao, $departamento, $permissao, $ativo, $sessao_inativa, $tenant_id, $id);
    }
    
    if ($stmt->execute()) {
        if ($rbac_ativo) {
            rbacInvalidarCache($conexao, $tenant_id);
            rbacAuditar($conexao, ['modulo_chave'=>'usuarios','acao'=>'EDITAR','registro_tipo'=>'usuarios','registro_id'=>$id,'dados_antes'=>$antes,'dados_depois'=>['nome'=>$nome,'email'=>$email,'funcao'=>$funcao,'departamento'=>$departamento,'permissao'=>$permissao,'ativo'=>$ativo,'sessao_inativa'=>$sessao_inativa],'resultado'=>'SUCESSO','status_http'=>200]);
        }
        registrar_log('USUARIO_ATUALIZADO', "Usuário atualizado: $nome (ID: $id)", $nome);
        retornar_json(true, "Usuário atualizado com sucesso");
    } else {
        retornar_json(false, "Erro ao atualizar usuário: " . $stmt->error);
    }
    
    $stmt->close();
}

// ========== EXCLUIR USUÁRIO ==========
if ($metodo === 'DELETE') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id = intval($dados['id'] ?? 0);
    
    if ($id <= 0) {
        retornar_json(false, "ID inválido");
    }
    
    // Não permitir excluir o primeiro usuário (admin)
    if ($id == 1) {
        retornar_json(false, "Não é possível excluir o administrador principal");
    }
    
    // Buscar estado anterior no tenant atual para auditoria.
    $stmt = $conexao->prepare('SELECT id,nome,email,funcao,departamento,permissao,ativo FROM usuarios WHERE tenant_id = ? AND id = ?');
    $stmt->bind_param("ii", $tenant_id, $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $usuario = $resultado->fetch_assoc();
    $nome_usuario = $usuario['nome'] ?? 'Desconhecido';
    $stmt->close();
    
    // Excluir usuário somente no tenant atual.
    $stmt = $conexao->prepare('DELETE FROM usuarios WHERE tenant_id = ? AND id = ?');
    $stmt->bind_param("ii", $tenant_id, $id);
    
    if ($stmt->execute()) {
        if ($rbac_ativo) {
            rbacInvalidarCache($conexao, $tenant_id);
            rbacAuditar($conexao, ['modulo_chave'=>'usuarios','acao'=>'EXCLUIR','registro_tipo'=>'usuarios','registro_id'=>$id,'dados_antes'=>$usuario,'resultado'=>'SUCESSO','status_http'=>200]);
        }
        registrar_log('USUARIO_EXCLUIDO', "Usuário excluído: $nome_usuario (ID: $id)", $nome_usuario);
        retornar_json(true, "Usuário excluído com sucesso");
    } else {
        retornar_json(false, "Erro ao excluir usuário: " . $stmt->error);
    }
    
    $stmt->close();
}

fechar_conexao($conexao);
