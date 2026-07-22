<?php
// ============================================================
// API de Unidades — Módulo de Gestão de Unidades
// Associação Serra da Liberdade
// ============================================================
ob_start();
require_once 'config.php';
require_once 'auth_helper.php';

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$metodo  = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// Usuário autenticado (best-effort, apenas para fins de auditoria em logs_sistema;
// não bloqueia a requisição caso não haja sessão ativa).
$usuarioLogado = obterUsuarioAutenticado();
$usuarioAtual  = $usuarioLogado['nome'] ?? null;

// ============================================================
// HELPER: Auto-popular Gleba 1-187 + ADMINISTRATIVO
// ============================================================
function garantir_unidades_padrao($conexao) {
    $result = $conexao->query("SELECT COUNT(*) as total FROM unidades WHERE bloco = 'Gleba'");
    $row    = $result->fetch_assoc();
    if (intval($row['total']) >= 187) return;
    for ($i = 1; $i <= 187; $i++) {
        $nome = "Gleba $i";
        $stmt = $conexao->prepare("INSERT IGNORE INTO unidades (nome, descricao, bloco, ativo) VALUES (?, 'Serra da Liberdade', 'Gleba', 1)");
        $stmt->bind_param("s", $nome);
        $stmt->execute();
        $stmt->close();
    }
    $conexao->query("INSERT IGNORE INTO unidades (nome, descricao, bloco, ativo) VALUES ('ADMINISTRATIVO', 'Área administrativa da associação', 'ADMIN', 1)");
}

// ============================================================
// GET — LISTAR / SELECT / POPULAR
// ============================================================
if ($metodo === 'GET') {
    $acao = isset($_GET['acao']) ? $_GET['acao'] : 'listar';

    // Popular Gleba 1-187
    if ($acao === 'popular') {
        garantir_unidades_padrao($conexao);
        $result = $conexao->query("SELECT COUNT(*) as total FROM unidades");
        $row    = $result->fetch_assoc();
        retornar_json(true, "Unidades populadas com sucesso", ['total' => intval($row['total'])]);
    }

    // Para selects/dropdowns (retorna id + nome ordenado numericamente)
    if ($acao === 'select' || isset($_GET['select'])) {
        $sql = "SELECT id, nome, bloco FROM unidades WHERE ativo = 1
                ORDER BY
                    CASE WHEN bloco = 'ADMIN' THEN 9999 ELSE CAST(SUBSTRING_INDEX(nome, ' ', -1) AS UNSIGNED) END ASC,
                    nome ASC";
        $resultado = $conexao->query($sql);
        $unidades  = [];
        while ($row = $resultado->fetch_assoc()) $unidades[] = $row;
        retornar_json(true, "Unidades listadas", $unidades);
    }

    // Listar com paginação e filtros
    $busca      = isset($_GET['busca'])      ? sanitizar($conexao, trim($_GET['busca'])) : '';
    $bloco      = isset($_GET['bloco'])      ? sanitizar($conexao, trim($_GET['bloco'])) : '';
    $ativo_fil  = isset($_GET['ativo'])      ? intval($_GET['ativo']) : -1;
    $por_pagina = isset($_GET['por_pagina']) ? max(1, intval($_GET['por_pagina'])) : 50;
    $pagina     = isset($_GET['pagina'])     ? max(1, intval($_GET['pagina'])) : 1;
    $offset     = ($pagina - 1) * $por_pagina;

    $where  = "WHERE 1=1";
    $params = [];
    $tipos  = "";

    if ($busca) {
        $where .= " AND (nome LIKE ? OR descricao LIKE ? OR bloco LIKE ?)";
        $b = "%$busca%";
        $params[] = $b; $params[] = $b; $params[] = $b;
        $tipos .= "sss";
    }
    if ($bloco) {
        $where .= " AND bloco = ?";
        $params[] = $bloco;
        $tipos .= "s";
    }
    if ($ativo_fil >= 0) {
        $where .= " AND ativo = ?";
        $params[] = $ativo_fil;
        $tipos .= "i";
    }

    // Total
    $sql_count = "SELECT COUNT(*) as total FROM unidades $where";
    if ($params) {
        $stmt = $conexao->prepare($sql_count);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    } else {
        $res = $conexao->query($sql_count);
    }
    $total = intval($res->fetch_assoc()['total']);

    // Registros
    $sql = "SELECT id, nome, descricao, bloco, ativo,
                   DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro_fmt,
                   (SELECT COUNT(*) FROM moradores WHERE unidade = unidades.nome) as total_moradores,
                   (SELECT COUNT(*) FROM hidrometros WHERE unidade = unidades.nome) as total_hidrometros
            FROM unidades $where
            ORDER BY
                CASE WHEN bloco = 'ADMIN' THEN 9999 ELSE CAST(SUBSTRING_INDEX(nome, ' ', -1) AS UNSIGNED) END ASC,
                nome ASC
            LIMIT ? OFFSET ?";

    $params[] = $por_pagina;
    $params[] = $offset;
    $tipos .= "ii";

    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($tipos, ...$params);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $unidades  = [];
    while ($row = $resultado->fetch_assoc()) $unidades[] = $row;
    $stmt->close();

    retornar_json(true, "Unidades listadas com sucesso", [
        'itens'      => $unidades,
        'total'      => $total,
        'paginas'    => ceil($total / $por_pagina),
        'pagina'     => $pagina,
        'por_pagina' => $por_pagina
    ]);
}

// ============================================================
// POST — CRIAR UNIDADE
// ============================================================
if ($metodo === 'POST') {
    $dados     = json_decode(file_get_contents('php://input'), true);
    $nome      = sanitizar($conexao, trim($dados['nome'] ?? ''));
    $descricao = sanitizar($conexao, trim($dados['descricao'] ?? ''));
    $bloco     = sanitizar($conexao, trim($dados['bloco'] ?? 'Gleba'));

    if (empty($nome)) retornar_json(false, "Nome da unidade é obrigatório");

    $stmt = $conexao->prepare("SELECT id FROM unidades WHERE nome = ?");
    $stmt->bind_param("s", $nome);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) retornar_json(false, "Unidade '$nome' já cadastrada no sistema");
    $stmt->close();

    $stmt = $conexao->prepare("INSERT INTO unidades (nome, descricao, bloco, ativo) VALUES (?, ?, ?, 1)");
    $stmt->bind_param("sss", $nome, $descricao, $bloco);
    if ($stmt->execute()) {
        $id = $conexao->insert_id;
        registrar_log('INFO', "Unidade cadastrada: $nome (ID: $id)", $usuarioAtual);
        retornar_json(true, "Unidade '$nome' cadastrada com sucesso", ['id' => $id]);
    }
    retornar_json(false, "Erro ao cadastrar: " . $stmt->error);
}

// ============================================================
// PUT — ATUALIZAR UNIDADE (propaga renomeação para moradores/hidrometros)
// ============================================================
if ($metodo === 'PUT') {
    $dados     = json_decode(file_get_contents('php://input'), true);
    $id        = intval($dados['id'] ?? 0);
    $nome      = sanitizar($conexao, trim($dados['nome'] ?? ''));
    $descricao = sanitizar($conexao, trim($dados['descricao'] ?? ''));
    $bloco     = sanitizar($conexao, trim($dados['bloco'] ?? 'Gleba'));
    $ativo     = isset($dados['ativo']) ? intval($dados['ativo']) : 1;

    if ($id <= 0) retornar_json(false, "ID inválido");
    if (empty($nome)) retornar_json(false, "Nome da unidade é obrigatório");

    $stmt = $conexao->prepare("SELECT id FROM unidades WHERE nome = ? AND id != ?");
    $stmt->bind_param("si", $nome, $id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) retornar_json(false, "Nome '$nome' já pertence a outra unidade");
    $stmt->close();

    // Buscar valores antigos (para auditoria e para propagar renomeação)
    $stmt = $conexao->prepare("SELECT nome, descricao, bloco, ativo FROM unidades WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $antiga = $res->fetch_assoc();
    $stmt->close();
    if (!$antiga) retornar_json(false, "Unidade não encontrada");
    $nome_antigo = $antiga['nome'];

    $stmt = $conexao->prepare("UPDATE unidades SET nome = ?, descricao = ?, bloco = ?, ativo = ? WHERE id = ?");
    $stmt->bind_param("sssii", $nome, $descricao, $bloco, $ativo, $id);
    if ($stmt->execute()) {
        // Propagar renomeação para moradores e hidrometros
        if ($nome_antigo !== $nome) {
            $s = $conexao->prepare("UPDATE moradores SET unidade = ? WHERE unidade = ?");
            $s->bind_param("ss", $nome, $nome_antigo); $s->execute(); $s->close();
            $s = $conexao->prepare("UPDATE hidrometros SET unidade = ? WHERE unidade = ?");
            $s->bind_param("ss", $nome, $nome_antigo); $s->execute(); $s->close();
        }

        // Auditoria: usuário, IP e data/hora já são registrados automaticamente
        // pela função registrar_log() (colunas usuario/ip/data_hora de logs_sistema);
        // aqui registramos os valores antigos e novos da unidade alterada.
        $descricaoLog = sprintf(
            "Unidade ID %d alterada — nome: '%s' -> '%s'; descricao: '%s' -> '%s'; bloco: '%s' -> '%s'; ativo: '%s' -> '%s'",
            $id, $nome_antigo, $nome,
            $antiga['descricao'] ?? '', $descricao,
            $antiga['bloco'] ?? '', $bloco,
            $antiga['ativo'], $ativo
        );
        registrar_log('INFO', $descricaoLog, $usuarioAtual);

        retornar_json(true, "Unidade atualizada com sucesso");
    }
    retornar_json(false, "Erro ao atualizar: " . $stmt->error);
}

// ============================================================
// PATCH — TOGGLE ATIVO/INATIVO
// ============================================================
if ($metodo === 'PATCH') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id    = intval($dados['id'] ?? 0);
    $ativo = intval($dados['ativo'] ?? 0);
    if ($id <= 0) retornar_json(false, "ID inválido");

    $stmt = $conexao->prepare("UPDATE unidades SET ativo = ? WHERE id = ?");
    $stmt->bind_param("ii", $ativo, $id);
    if ($stmt->execute()) {
        $status = $ativo ? 'ativada' : 'inativada';
        registrar_log('INFO', "Unidade $status (ID: $id)", $usuarioAtual);
        retornar_json(true, "Unidade $status com sucesso");
    }
    retornar_json(false, "Erro: " . $stmt->error);
}

// ============================================================
// DELETE — EXCLUIR UNIDADE
// ============================================================
if ($metodo === 'DELETE') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id    = intval($dados['id'] ?? 0);
    if ($id <= 0) retornar_json(false, "ID inválido");

    $stmt = $conexao->prepare("SELECT nome FROM unidades WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $unidade = $res->fetch_assoc();
    $stmt->close();
    if (!$unidade) retornar_json(false, "Unidade não encontrada");
    $nome = $unidade['nome'];

    // Verificar vínculos
    $stmt = $conexao->prepare("SELECT COUNT(*) as t FROM moradores WHERE unidade = ?");
    $stmt->bind_param("s", $nome); $stmt->execute();
    $tm = $stmt->get_result()->fetch_assoc()['t']; $stmt->close();
    if ($tm > 0) retornar_json(false, "Não é possível excluir. $tm morador(es) vinculado(s) a '$nome'.");

    $stmt = $conexao->prepare("SELECT COUNT(*) as t FROM hidrometros WHERE unidade = ?");
    $stmt->bind_param("s", $nome); $stmt->execute();
    $th = $stmt->get_result()->fetch_assoc()['t']; $stmt->close();
    if ($th > 0) retornar_json(false, "Não é possível excluir. $th hidrômetro(s) vinculado(s) a '$nome'.");

    $stmt = $conexao->prepare("DELETE FROM unidades WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        registrar_log('INFO', "Unidade excluída: $nome (ID: $id)", $usuarioAtual);
        retornar_json(true, "Unidade '$nome' excluída com sucesso");
    }
    retornar_json(false, "Erro ao excluir: " . $stmt->error);
}

fechar_conexao($conexao);
