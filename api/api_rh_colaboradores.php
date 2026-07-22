<?php
// =====================================================
// API: RH — COLABORADORES
// =====================================================
// GET    ?acao=listar[&busca=X&departamento=X&ativo=1]
// GET    ?acao=obter&id=N
// POST   ?acao=criar          (multipart/form-data ou JSON)
// POST   ?acao=atualizar&id=N (multipart/form-data)
// POST   ?acao=upload_foto&id=N (multipart com campo "foto")
// DELETE ?acao=excluir&id=N

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
require_once 'error_logger.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
$allowed = ['https://asl.erpcondominios.com.br','http://asl.erpcondominios.com.br','https://erpcondominios.com.br','http://erpcondominios.com.br','http://localhost','http://127.0.0.1'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed) ? $origin : 'https://asl.erpcondominios.com.br'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

define('RH_FOTO_DIR',  dirname(__DIR__) . '/uploads/rh_fotos/');
define('RH_FOTO_URL',  'uploads/rh_fotos/');
define('RH_MAX_SIZE',  5 * 1024 * 1024);
define('RH_FOTO_TIPOS', ['image/jpeg' => 'jpg','image/jpg' => 'jpg','image/png' => 'png','image/webp' => 'webp','image/gif' => 'gif']);

if (!is_dir(RH_FOTO_DIR)) mkdir(RH_FOTO_DIR, 0755, true);

try { verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId(); }
catch (Exception $e) { retornar_json(false, 'Não autenticado'); }

$metodo = $_SERVER['REQUEST_METHOD'];
$acao   = $_GET['acao'] ?? ($metodo === 'DELETE' ? 'excluir' : '');
$conn   = conectar_banco();
if (!$conn) retornar_json(false, 'Erro ao conectar ao banco');

// ── LISTAR ────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'listar') {
    $busca       = '%' . trim($_GET['busca']       ?? '') . '%';
    $departamento = trim($_GET['departamento']     ?? '');
    $ativo_filtro = isset($_GET['ativo']) ? intval($_GET['ativo']) : 1;

    $sql = "SELECT id, nome, cpf, cargo, departamento, tipo_contrato, data_admissao, celular, email, foto_path, ativo
            FROM rh_colaboradores WHERE tenant_id = $tenant_id AND ativo = ?
              AND (nome LIKE ? OR cpf LIKE ? OR cargo LIKE ? OR departamento LIKE ?)";
    $params = [$ativo_filtro, $busca, $busca, $busca, $busca];
    $types  = 'issss';

    if ($departamento !== '') {
        $sql     .= ' AND departamento = ?';
        $params[]  = $departamento;
        $types    .= 's';
    }
    $sql .= ' ORDER BY nome ASC';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) $list[] = $row;
    $stmt->close();
    fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

// ── OBTER ─────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'obter') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare("SELECT * FROM rh_colaboradores WHERE tenant_id = $tenant_id AND id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    fechar_conexao($conn);

    if (!$row) retornar_json(false, 'Colaborador não encontrado');
    retornar_json(true, 'OK', $row);
}

// ── DEPARTAMENTOS (lista para filtros) ────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'departamentos') {
    $res  = $conn->query("SELECT DISTINCT departamento FROM rh_colaboradores WHERE tenant_id = $tenant_id AND departamento IS NOT NULL AND departamento <> '' AND ativo=1 ORDER BY departamento");
    $list = [];
    while ($row = $res->fetch_row()) $list[] = $row[0];
    fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
}

// ── CRIAR ─────────────────────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'criar') {
    $d = _extrair_dados_colaborador();
    if (empty($d['nome']))  retornar_json(false, 'Nome é obrigatório');
    if (empty($d['cpf']))   retornar_json(false, 'CPF é obrigatório');

    // Validação de datas
    if (!empty($d['data_demissao']) && !empty($d['data_admissao'])) {
        if ($d['data_demissao'] <= $d['data_admissao'])
            retornar_json(false, 'A data de demissão deve ser posterior à data de admissão.');
    }

    // CPF único
    $chk = $conn->prepare("SELECT id FROM rh_colaboradores WHERE tenant_id = $tenant_id AND cpf = ?");
    $chk->bind_param('s', $d['cpf']); $chk->execute(); $chk->store_result();
    if ($chk->num_rows > 0) { $chk->close(); retornar_json(false, 'CPF já cadastrado'); }
    $chk->close();

    $foto_path = null;
    if (!empty($_FILES['foto'])) $foto_path = _salvar_foto($_FILES['foto']);

    $stmt = $conn->prepare(
        "INSERT INTO rh_colaboradores
         (nome,cpf,rg,data_nascimento,sexo,estado_civil,cargo,departamento,tipo_contrato,
          data_admissao,data_demissao,salario,telefone,celular,email,
          cep,logradouro,numero,complemento,bairro,cidade,estado,
          banco,agencia,conta,pix,foto_path,observacoes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('sssssssssssdssssssssssssssss',
        $d['nome'],$d['cpf'],$d['rg'],$d['data_nascimento'],$d['sexo'],$d['estado_civil'],
        $d['cargo'],$d['departamento'],$d['tipo_contrato'],
        $d['data_admissao'],$d['data_demissao'],$d['salario'],
        $d['telefone'],$d['celular'],$d['email'],
        $d['cep'],$d['logradouro'],$d['numero'],$d['complemento'],$d['bairro'],$d['cidade'],$d['estado'],
        $d['banco'],$d['agencia'],$d['conta'],$d['pix'],$foto_path,$d['observacoes']
    );
    if (!$stmt->execute()) { $erro = $stmt->error ?: $conn->error; $stmt->close(); fechar_conexao($conn); retornar_json(false, 'Erro ao criar colaborador: ' . $erro); }
    $novo_id = $conn->insert_id;
    $stmt->close();
    fechar_conexao($conn);
    retornar_json(true, 'Colaborador criado com sucesso', ['id' => $novo_id]);
}

// ── ATUALIZAR ─────────────────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'atualizar') {
    $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $d = _extrair_dados_colaborador();

    // Validação de datas
    if (!empty($d['data_demissao']) && !empty($d['data_admissao'])) {
        if ($d['data_demissao'] <= $d['data_admissao'])
            retornar_json(false, 'A data de demissão deve ser posterior à data de admissão.');
    }

    // CPF único (exceto o próprio)
    $chk = $conn->prepare("SELECT id FROM rh_colaboradores WHERE tenant_id = $tenant_id AND cpf = ? AND id <> ?");
    $chk->bind_param('si', $d['cpf'], $id); $chk->execute(); $chk->store_result();
    if ($chk->num_rows > 0) { $chk->close(); retornar_json(false, 'CPF já em uso por outro colaborador'); }
    $chk->close();

    // Foto nova?
    $foto_path = null;
    if (!empty($_FILES['foto'])) {
        $foto_path = _salvar_foto($_FILES['foto']);
        // Remove foto antiga
        $old = $conn->prepare("SELECT foto_path FROM rh_colaboradores WHERE tenant_id = $tenant_id AND id=?");
        $old->bind_param('i',$id); $old->execute();
        $res = $old->get_result()->fetch_assoc(); $old->close();
        if (!empty($res['foto_path'])) @unlink(dirname(__DIR__) . '/' . $res['foto_path']);
    }

    // ativo: 0 se demissão preenchida, 1 se removida
    $ativo_novo = empty($d['data_demissao']) ? 1 : 0;

    $foto_sql = $foto_path !== null ? ', foto_path = ?' : '';
    $sql = "UPDATE rh_colaboradores SET
            nome=?,cpf=?,rg=?,data_nascimento=?,sexo=?,estado_civil=?,cargo=?,departamento=?,
            tipo_contrato=?,data_admissao=?,data_demissao=?,salario=?,telefone=?,celular=?,email=?,
            cep=?,logradouro=?,numero=?,complemento=?,bairro=?,cidade=?,estado=?,
            banco=?,agencia=?,conta=?,pix=?,observacoes=?$foto_sql WHERE tenant_id = $tenant_id AND id=?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { fechar_conexao($conn); retornar_json(false, 'Erro ao preparar SQL: ' . $conn->error); }

    // 11s + d(salario) + 15s = 27 base; +s se foto; +i para WHERE id
    $types = 'sssssssssss' . 'd' . 'sssssssssssssss';
    $vals  = [$d['nome'],$d['cpf'],$d['rg'],$d['data_nascimento'],$d['sexo'],$d['estado_civil'],
              $d['cargo'],$d['departamento'],$d['tipo_contrato'],
              $d['data_admissao'],$d['data_demissao'],$d['salario'],
              $d['telefone'],$d['celular'],$d['email'],
              $d['cep'],$d['logradouro'],$d['numero'],$d['complemento'],$d['bairro'],$d['cidade'],$d['estado'],
              $d['banco'],$d['agencia'],$d['conta'],$d['pix'],$d['observacoes']];
    if ($foto_path !== null) { $vals[] = $foto_path; $types .= 's'; }
    $vals[] = $id; $types .= 'i';
    $stmt->bind_param($types, ...$vals);

    if (!$stmt->execute()) { $erro = $stmt->error ?: $conn->error; $stmt->close(); fechar_conexao($conn); retornar_json(false, 'Erro ao atualizar colaborador: ' . $erro); }
    $stmt->close();

    // Atualiza ativo em query dedicada (evita conflito com o bind acima)
    $stmtA = $conn->prepare("UPDATE rh_colaboradores SET ativo=? WHERE tenant_id = $tenant_id AND id=?");
    if ($stmtA) {
        $stmtA->bind_param('ii', $ativo_novo, $id);
        $stmtA->execute();
        $stmtA->close();
    }

    fechar_conexao($conn);
    $msg = $ativo_novo === 0
        ? 'Colaborador atualizado e marcado como Inativo (demitido).'
        : 'Colaborador atualizado com sucesso.';
    retornar_json(true, $msg, ['ativo' => $ativo_novo]);
}

// ── UPLOAD FOTO (avulso) ──────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'upload_foto') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');
    if (empty($_FILES['foto'])) retornar_json(false, 'Nenhuma foto enviada');

    $foto_path = _salvar_foto($_FILES['foto']);

    $old = $conn->prepare("SELECT foto_path FROM rh_colaboradores WHERE tenant_id = $tenant_id AND id=?");
    $old->bind_param('i',$id); $old->execute();
    $res = $old->get_result()->fetch_assoc(); $old->close();
    if (!empty($res['foto_path'])) @unlink(dirname(__DIR__) . '/' . $res['foto_path']);

    $stmt = $conn->prepare("UPDATE rh_colaboradores SET foto_path=? WHERE tenant_id = $tenant_id AND id=?");
    $stmt->bind_param('si', $foto_path, $id);
    $stmt->execute(); $stmt->close(); fechar_conexao($conn);
    retornar_json(true, 'Foto atualizada', ['foto_path' => $foto_path]);
}

// ── EXCLUIR (soft delete) ─────────────────────────────────────────────────────
if ($metodo === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = intval($body['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) retornar_json(false, 'ID inválido');

    $stmt = $conn->prepare("UPDATE rh_colaboradores SET ativo=0 WHERE tenant_id = $tenant_id AND id=?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute(); $stmt->close(); fechar_conexao($conn);
    retornar_json($ok, $ok ? 'Colaborador removido' : 'Erro ao remover');
}

fechar_conexao($conn);
retornar_json(false, 'Ação não reconhecida');

// ── HELPERS ───────────────────────────────────────────────────────────────────
function _extrair_dados_colaborador() {
    $src = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);
    $s   = fn($k) => isset($src[$k]) && $src[$k] !== '' ? trim($src[$k]) : null;
    return [
        'nome'            => $s('nome'),
        'cpf'             => preg_replace('/\D/', '', $s('cpf') ?? ''),
        'rg'              => $s('rg'),
        'data_nascimento' => $s('data_nascimento'),
        'sexo'            => $s('sexo'),
        'estado_civil'    => $s('estado_civil'),
        'cargo'           => $s('cargo'),
        'departamento'    => $s('departamento'),
        'tipo_contrato'   => $s('tipo_contrato') ?? 'clt',
        'data_admissao'   => $s('data_admissao'),
        'data_demissao'   => $s('data_demissao'),
        'salario'         => isset($src['salario']) && $src['salario'] !== '' ? floatval($src['salario']) : null,
        'telefone'        => $s('telefone'),
        'celular'         => $s('celular'),
        'email'           => $s('email'),
        'cep'             => $s('cep'),
        'logradouro'      => $s('logradouro'),
        'numero'          => $s('numero'),
        'complemento'     => $s('complemento'),
        'bairro'          => $s('bairro'),
        'cidade'          => $s('cidade'),
        'estado'          => $s('estado'),
        'banco'           => $s('banco'),
        'agencia'         => $s('agencia'),
        'conta'           => $s('conta'),
        'pix'             => $s('pix'),
        'observacoes'     => $s('observacoes'),
    ];
}

function _salvar_foto(array $file): string {
    if ($file['error'] !== UPLOAD_ERR_OK) retornar_json(false, 'Erro no upload da foto');
    if ($file['size'] > RH_MAX_SIZE) retornar_json(false, 'Foto excede 5 MB');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset(RH_FOTO_TIPOS[$mime])) retornar_json(false, 'Formato de foto inválido (JPG, PNG, WEBP, GIF)');

    $ext      = RH_FOTO_TIPOS[$mime];
    $filename = 'foto_' . time() . '_' . uniqid() . '.' . $ext;
    $dest     = RH_FOTO_DIR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) retornar_json(false, 'Falha ao salvar foto');
    return RH_FOTO_URL . $filename;
}
?>
