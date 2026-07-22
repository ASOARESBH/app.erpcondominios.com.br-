<?php
// =====================================================
// API MARKETPLACE - PORTAL DO MORADOR
// =====================================================
// Endpoints: vitrine, ramos, detalhe, contratar, meus_pedidos, cancelar,
//            confirmar_conclusao, avaliar, historico
// Autenticação: token Bearer via sessoes_portal
// Status ENUM banco: enviado, em_analise, aceito, recusado, em_execucao,
//                    finalizado_morador, finalizado_fornecedor, concluido, cancelado

// ob_start ANTES de qualquer include para capturar output acidental
// (whitespace, BOM, erros de include) que causaria HTTP 500
session_start();
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';

// Limpar qualquer output acidental dos includes
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ========== HELPERS ==========
function pm_json($ok, $msg, $dados = null, $code = 200) {
    http_response_code($code);
    $r = ['sucesso' => $ok, 'mensagem' => $msg];
    if ($dados !== null) $r['dados'] = $dados;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

function pm_token() {
    // Método 1: getallheaders()
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        foreach ($h as $k => $v) {
            if (strtolower($k) === 'authorization') {
                if (preg_match('/Bearer\s+(.+)/i', $v, $m)) return trim($m[1]);
            }
        }
    }
    // Método 2: $_SERVER fallback
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $m)) return trim($m[1]);
    // Método 3: query string / POST
    return $_GET['token'] ?? $_POST['token'] ?? null;
}

function pm_auth($conexao) {
    $token = pm_token();
    if (!$token) pm_json(false, 'Token não informado.', null, 401);
    $stmt = $conexao->prepare("SELECT morador_id FROM sessoes_portal WHERE token = ? AND ativo = 1 AND data_expiracao > NOW() LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) pm_json(false, 'Sessão expirada. Faça login novamente.', null, 401);
    return (int)$r['morador_id'];
}

// ========== INICIALIZAÇÃO ==========
$conexao = conectar_banco();
$acao    = $_GET['acao'] ?? $_POST['acao'] ?? '';
$metodo  = $_SERVER['REQUEST_METHOD'];

try {

    // ============================================================
    // VITRINE — lista produtos/serviços ativos com filtros
    // GET ?acao=vitrine [&busca=] [&tipo=produto|servico] [&ramo_id=] [&pagina=] [&por_pagina=]
    // Não requer autenticação (vitrine pública)
    // ============================================================
    if ($acao === 'vitrine' && $metodo === 'GET') {
        $busca    = trim($_GET['busca'] ?? '');
        $tipo     = trim($_GET['tipo']  ?? '');
        $ramo_id  = intval($_GET['ramo_id'] ?? 0);
        $pagina   = max(1, intval($_GET['pagina']    ?? 1));
        $por_pag  = max(1, min(50, intval($_GET['por_pagina'] ?? 12)));
        $offset   = ($pagina - 1) * $por_pag;

        $where = "ps.ativo = 1 AND f.ativo = 1 AND f.aprovado = 1";
        $params = []; $types = '';

        if ($busca !== '') {
            $where .= " AND (ps.nome LIKE ? OR ps.descricao LIKE ? OR f.nome_estabelecimento LIKE ?)";
            $like = "%{$busca}%";
            $params[] = $like; $params[] = $like; $params[] = $like;
            $types .= 'sss';
        }
        if ($tipo !== '') {
            $where .= " AND ps.tipo = ?";
            $params[] = $tipo; $types .= 's';
        }
        if ($ramo_id > 0) {
            $where .= " AND f.ramo_atividade_id = ?";
            $params[] = $ramo_id; $types .= 'i';
        }

        // Total
        $sql_count = "SELECT COUNT(*) as total FROM produtos_servicos ps
                      JOIN fornecedores f ON ps.fornecedor_id = f.id
                      WHERE {$where}";
        $st = $conexao->prepare($sql_count);
        if ($types) $st->bind_param($types, ...$params);
        $st->execute();
        $total = (int)$st->get_result()->fetch_assoc()['total'];
        $st->close();

        // Dados
        $sql = "SELECT
                    ps.id,
                    ps.nome,
                    ps.tipo,
                    ps.descricao,
                    ps.valor,
                    ps.valor_negociavel,
                    ps.imagem,
                    ps.fornecedor_id,
                    f.nome_estabelecimento AS fornecedor_nome,
                    f.telefone             AS fornecedor_telefone,
                    f.email                AS fornecedor_email,
                    f.logo                 AS fornecedor_logo,
                    r.nome                 AS ramo_nome,
                    r.icone                AS ramo_icone,
                    COALESCE(ROUND(AVG(av.nota),1), 0)  AS media_nota,
                    COUNT(DISTINCT av.id)               AS total_avaliacoes
                FROM produtos_servicos ps
                JOIN fornecedores f    ON ps.fornecedor_id = f.id
                JOIN ramos_atividade r ON f.ramo_atividade_id = r.id
                LEFT JOIN avaliacoes av ON av.avaliado_id = f.id AND av.avaliado_tipo = 'fornecedor'
                WHERE {$where}
                GROUP BY ps.id
                ORDER BY media_nota DESC, ps.data_criacao DESC
                LIMIT ? OFFSET ?";

        $params[] = $por_pag; $types .= 'i';
        $params[] = $offset;  $types .= 'i';

        $st = $conexao->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        $itens = [];
        while ($row = $res->fetch_assoc()) $itens[] = $row;
        $st->close();

        pm_json(true, 'Vitrine carregada', [
            'itens'         => $itens,
            'total'         => $total,
            'pagina'        => $pagina,
            'por_pagina'    => $por_pag,
            'total_paginas' => (int)ceil($total / $por_pag),
        ]);
    }

    // ============================================================
    // RAMOS — lista categorias para filtro
    // GET ?acao=ramos
    // ============================================================
    if ($acao === 'ramos' && $metodo === 'GET') {
        $st = $conexao->prepare("SELECT id, nome, icone FROM ramos_atividade WHERE ativo = 1 ORDER BY nome ASC");
        $st->execute();
        $res = $st->get_result();
        $ramos = [];
        while ($row = $res->fetch_assoc()) $ramos[] = $row;
        $st->close();
        pm_json(true, 'Ramos carregados', $ramos);
    }

    // ============================================================
    // DETALHE — produto/serviço com avaliações recentes
    // GET ?acao=detalhe&id=X
    // ============================================================
    if ($acao === 'detalhe' && $metodo === 'GET') {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) pm_json(false, 'ID inválido', null, 400);

        $st = $conexao->prepare("
            SELECT ps.id, ps.nome, ps.tipo, ps.descricao, ps.valor, ps.valor_negociavel, ps.imagem,
                   ps.fornecedor_id,
                   f.nome_estabelecimento AS fornecedor_nome,
                   f.telefone             AS fornecedor_telefone,
                   f.email                AS fornecedor_email,
                   f.logo                 AS fornecedor_logo,
                   f.descricao_negocio    AS fornecedor_descricao,
                   f.horario_funcionamento AS fornecedor_horario,
                   r.nome  AS ramo_nome,
                   r.icone AS ramo_icone,
                   COALESCE(ROUND(AVG(av.nota),1),0) AS media_nota,
                   COUNT(DISTINCT av.id)             AS total_avaliacoes
            FROM produtos_servicos ps
            JOIN fornecedores f    ON ps.fornecedor_id = f.id
            JOIN ramos_atividade r ON f.ramo_atividade_id = r.id
            LEFT JOIN avaliacoes av ON av.avaliado_id = f.id AND av.avaliado_tipo = 'fornecedor'
            WHERE ps.id = ? AND ps.ativo = 1 AND f.ativo = 1 AND f.aprovado = 1
            GROUP BY ps.id
        ");
        $st->bind_param('i', $id);
        $st->execute();
        $item = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$item) pm_json(false, 'Oferta não encontrada', null, 404);

        // Avaliações recentes do fornecedor
        $st2 = $conexao->prepare("
            SELECT av.nota, av.comentario, av.data_avaliacao, m.nome AS morador_nome
            FROM avaliacoes av
            JOIN moradores m ON av.avaliador_id = m.id
            WHERE av.avaliado_id = ? AND av.avaliado_tipo = 'fornecedor' AND av.avaliador_tipo = 'morador'
            ORDER BY av.data_avaliacao DESC LIMIT 5
        ");
        $st2->bind_param('i', $item['fornecedor_id']);
        $st2->execute();
        $res2 = $st2->get_result();
        $avs = [];
        while ($r = $res2->fetch_assoc()) $avs[] = $r;
        $st2->close();

        $item['avaliacoes_recentes'] = $avs;
        pm_json(true, 'Detalhe carregado', $item);
    }

    // ============================================================
    // CONTRATAR — morador solicita um serviço/produto
    // POST ?acao=contratar
    // Body: produto_servico_id, descricao_pedido, valor_proposto (opcional)
    // ============================================================
    if ($acao === 'contratar' && $metodo === 'POST') {
        $morador_id = pm_auth($conexao);

        $produto_id      = intval($_POST['produto_servico_id'] ?? 0);
        $descricao       = trim($_POST['descricao_pedido'] ?? '');
        $valor_proposto  = floatval($_POST['valor_proposto'] ?? 0);

        if ($produto_id <= 0) pm_json(false, 'Produto/serviço inválido', null, 400);
        if ($descricao === '') pm_json(false, 'Descreva o que você precisa', null, 400);

        // Buscar produto
        $st = $conexao->prepare("SELECT id, fornecedor_id, valor FROM produtos_servicos WHERE id = ? AND ativo = 1");
        $st->bind_param('i', $produto_id);
        $st->execute();
        $prod = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$prod) pm_json(false, 'Oferta não encontrada ou inativa', null, 404);

        $fornecedor_id = (int)$prod['fornecedor_id'];
        if ($valor_proposto <= 0) $valor_proposto = (float)($prod['valor'] ?? 0);

        // Inserir pedido
        // CORREÇÃO: bind_param era 'iiids' (errado), correto é 'iiisd'
        // Ordem: morador_id(i), fornecedor_id(i), produto_id(i), descricao(s), valor_proposto(d)
        $st = $conexao->prepare("
            INSERT INTO pedidos (morador_id, fornecedor_id, produto_servico_id, descricao_pedido, valor_proposto, status, data_pedido)
            VALUES (?, ?, ?, ?, ?, 'enviado', NOW())
        ");
        $st->bind_param('iiisd', $morador_id, $fornecedor_id, $produto_id, $descricao, $valor_proposto);
        if (!$st->execute()) pm_json(false, 'Erro ao criar pedido: ' . $st->error, null, 500);
        $pedido_id = $st->insert_id;
        $st->close();

        // Registrar histórico
        // CORREÇÃO: status 'aguardando' não existe no ENUM — usar 'enviado'
        $st = $conexao->prepare("
            INSERT INTO historico_status_pedido (pedido_id, status_anterior, status_novo, usuario_tipo, usuario_id, observacao)
            VALUES (?, NULL, 'enviado', 'morador', ?, 'Pedido criado pelo morador')
        ");
        $st->bind_param('ii', $pedido_id, $morador_id);
        $st->execute(); $st->close();

        pm_json(true, 'Pedido enviado com sucesso! O fornecedor será notificado.', ['pedido_id' => $pedido_id, 'status' => 'enviado'], 201);
    }

    // ============================================================
    // MEUS PEDIDOS — lista pedidos do morador logado
    // GET ?acao=meus_pedidos [&status=]
    // ============================================================
    if ($acao === 'meus_pedidos' && $metodo === 'GET') {
        $morador_id = pm_auth($conexao);
        $status_filtro = trim($_GET['status'] ?? '');

        $where = "p.morador_id = ?";
        $params = [$morador_id]; $types = 'i';

        if ($status_filtro !== '') {
            $where .= " AND p.status = ?";
            $params[] = $status_filtro; $types .= 's';
        }

        // CORREÇÃO: LEFT JOIN em produtos_servicos pois produto_servico_id pode ser NULL
        $st = $conexao->prepare("
            SELECT p.id, p.status, p.descricao_pedido, p.valor_proposto, p.motivo_recusa,
                   p.data_pedido, p.data_aceite, p.data_inicio_execucao, p.data_finalizacao,
                   ps.nome  AS produto_nome,
                   ps.tipo  AS produto_tipo,
                   ps.imagem AS produto_imagem,
                   f.nome_estabelecimento AS fornecedor_nome,
                   f.telefone             AS fornecedor_telefone,
                   f.email                AS fornecedor_email,
                   f.logo                 AS fornecedor_logo,
                   r.nome  AS ramo_nome,
                   r.icone AS ramo_icone,
                   (SELECT COUNT(*) FROM avaliacoes av WHERE av.pedido_id = p.id AND av.avaliador_tipo = 'morador') AS ja_avaliou
            FROM pedidos p
            LEFT JOIN produtos_servicos ps ON p.produto_servico_id = ps.id
            JOIN fornecedores f       ON p.fornecedor_id = f.id
            JOIN ramos_atividade r    ON f.ramo_atividade_id = r.id
            WHERE {$where}
            ORDER BY p.data_pedido DESC
        ");
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        $pedidos = [];
        while ($row = $res->fetch_assoc()) $pedidos[] = $row;
        $st->close();

        pm_json(true, 'Pedidos carregados', $pedidos);
    }

    // ============================================================
    // CANCELAR — morador cancela pedido enviado ou em análise
    // POST ?acao=cancelar
    // Body: pedido_id, motivo (opcional)
    // ============================================================
    if ($acao === 'cancelar' && $metodo === 'POST') {
        $morador_id = pm_auth($conexao);
        $pedido_id  = intval($_POST['pedido_id'] ?? 0);
        $motivo     = trim($_POST['motivo'] ?? 'Cancelado pelo morador');

        if ($pedido_id <= 0) pm_json(false, 'Pedido inválido', null, 400);

        $st = $conexao->prepare("SELECT id, status FROM pedidos WHERE id = ? AND morador_id = ?");
        $st->bind_param('ii', $pedido_id, $morador_id);
        $st->execute();
        $ped = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$ped) pm_json(false, 'Pedido não encontrado', null, 404);

        // CORREÇÃO: status canceláveis são 'enviado' e 'em_analise' (não 'aguardando')
        if (!in_array($ped['status'], ['enviado', 'em_analise'])) {
            pm_json(false, 'Somente pedidos enviados ou em análise podem ser cancelados pelo morador', null, 400);
        }

        $status_anterior = $ped['status'];

        $st = $conexao->prepare("UPDATE pedidos SET status = 'cancelado', motivo_recusa = ? WHERE id = ?");
        $st->bind_param('si', $motivo, $pedido_id);
        $st->execute(); $st->close();

        $st = $conexao->prepare("
            INSERT INTO historico_status_pedido (pedido_id, status_anterior, status_novo, usuario_tipo, usuario_id, observacao)
            VALUES (?, ?, 'cancelado', 'morador', ?, ?)
        ");
        $st->bind_param('isis', $pedido_id, $status_anterior, $morador_id, $motivo);
        $st->execute(); $st->close();

        pm_json(true, 'Pedido cancelado com sucesso');
    }

    // ============================================================
    // CONFIRMAR CONCLUSÃO — morador confirma que serviço foi entregue
    // POST ?acao=confirmar_conclusao
    // Body: pedido_id
    // ============================================================
    if ($acao === 'confirmar_conclusao' && $metodo === 'POST') {
        $morador_id = pm_auth($conexao);
        $pedido_id  = intval($_POST['pedido_id'] ?? 0);

        if ($pedido_id <= 0) pm_json(false, 'Pedido inválido', null, 400);

        $st = $conexao->prepare("SELECT id, status FROM pedidos WHERE id = ? AND morador_id = ?");
        $st->bind_param('ii', $pedido_id, $morador_id);
        $st->execute();
        $ped = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$ped) pm_json(false, 'Pedido não encontrado', null, 404);

        // CORREÇÃO: status correto do ENUM é 'em_execucao' (não 'executando')
        if ($ped['status'] !== 'em_execucao') {
            pm_json(false, 'Somente pedidos em execução podem ser confirmados', null, 400);
        }

        // CORREÇÃO: status correto do ENUM é 'finalizado_morador' (não 'finalizado')
        $st = $conexao->prepare("UPDATE pedidos SET status = 'finalizado_morador', data_finalizacao = NOW() WHERE id = ?");
        $st->bind_param('i', $pedido_id);
        $st->execute(); $st->close();

        $obs = 'Conclusão confirmada pelo morador';
        $st = $conexao->prepare("
            INSERT INTO historico_status_pedido (pedido_id, status_anterior, status_novo, usuario_tipo, usuario_id, observacao)
            VALUES (?, 'em_execucao', 'finalizado_morador', 'morador', ?, ?)
        ");
        $st->bind_param('iis', $pedido_id, $morador_id, $obs);
        $st->execute(); $st->close();

        pm_json(true, 'Serviço confirmado! Agora você pode avaliar o fornecedor.');
    }

    // ============================================================
    // AVALIAR — morador avalia o fornecedor após conclusão
    // POST ?acao=avaliar
    // Body: pedido_id, nota (1-5), comentario (opcional)
    // ============================================================
    if ($acao === 'avaliar' && $metodo === 'POST') {
        $morador_id = pm_auth($conexao);
        $pedido_id  = intval($_POST['pedido_id'] ?? 0);
        $nota       = intval($_POST['nota'] ?? 0);
        $comentario = trim($_POST['comentario'] ?? '');

        if ($pedido_id <= 0) pm_json(false, 'Pedido inválido', null, 400);
        if ($nota < 1 || $nota > 5) pm_json(false, 'Nota deve ser entre 1 e 5', null, 400);

        // Verificar pedido
        $st = $conexao->prepare("SELECT id, fornecedor_id, status FROM pedidos WHERE id = ? AND morador_id = ?");
        $st->bind_param('ii', $pedido_id, $morador_id);
        $st->execute();
        $ped = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$ped) pm_json(false, 'Pedido não encontrado', null, 404);

        // CORREÇÃO: status correto do ENUM é 'finalizado_morador' (não 'finalizado')
        // Aceitar também 'finalizado_fornecedor' e 'concluido' para flexibilidade
        if (!in_array($ped['status'], ['finalizado_morador', 'finalizado_fornecedor', 'concluido'])) {
            pm_json(false, 'Somente pedidos finalizados podem ser avaliados', null, 400);
        }

        $fornecedor_id = (int)$ped['fornecedor_id'];

        // Verificar se já avaliou
        $st = $conexao->prepare("SELECT id FROM avaliacoes WHERE pedido_id = ? AND avaliador_tipo = 'morador' AND avaliador_id = ?");
        $st->bind_param('ii', $pedido_id, $morador_id);
        $st->execute();
        $ja = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ja) pm_json(false, 'Você já avaliou este pedido', null, 400);

        // Inserir avaliação
        $st = $conexao->prepare("
            INSERT INTO avaliacoes (pedido_id, avaliador_tipo, avaliador_id, avaliado_tipo, avaliado_id, nota, comentario)
            VALUES (?, 'morador', ?, 'fornecedor', ?, ?, ?)
        ");
        $st->bind_param('iiiis', $pedido_id, $morador_id, $fornecedor_id, $nota, $comentario);
        if (!$st->execute()) pm_json(false, 'Erro ao salvar avaliação: ' . $st->error, null, 500);
        $st->close();

        // Atualizar status para concluido
        $status_anterior = $ped['status'];
        $st = $conexao->prepare("UPDATE pedidos SET status = 'concluido', data_conclusao = NOW() WHERE id = ?");
        $st->bind_param('i', $pedido_id);
        $st->execute(); $st->close();

        $obs = 'Avaliação registrada pelo morador';
        $st = $conexao->prepare("
            INSERT INTO historico_status_pedido (pedido_id, status_anterior, status_novo, usuario_tipo, usuario_id, observacao)
            VALUES (?, ?, 'concluido', 'morador', ?, ?)
        ");
        $st->bind_param('isis', $pedido_id, $status_anterior, $morador_id, $obs);
        $st->execute(); $st->close();

        pm_json(true, 'Avaliação registrada! Obrigado pelo feedback.');
    }

    // ============================================================
    // HISTORICO — histórico de status de um pedido
    // GET ?acao=historico&pedido_id=X
    // ============================================================
    if ($acao === 'historico' && $metodo === 'GET') {
        $morador_id = pm_auth($conexao);
        $pedido_id  = intval($_GET['pedido_id'] ?? 0);

        if ($pedido_id <= 0) pm_json(false, 'Pedido inválido', null, 400);

        // Verificar que pedido pertence ao morador
        $st = $conexao->prepare("SELECT id FROM pedidos WHERE id = ? AND morador_id = ?");
        $st->bind_param('ii', $pedido_id, $morador_id);
        $st->execute();
        if (!$st->get_result()->fetch_assoc()) pm_json(false, 'Pedido não encontrado', null, 404);
        $st->close();

        $st = $conexao->prepare("
            SELECT status_anterior, status_novo, usuario_tipo, observacao, data_mudanca
            FROM historico_status_pedido
            WHERE pedido_id = ?
            ORDER BY data_mudanca ASC
        ");
        $st->bind_param('i', $pedido_id);
        $st->execute();
        $res = $st->get_result();
        $hist = [];
        while ($row = $res->fetch_assoc()) $hist[] = $row;
        $st->close();

        pm_json(true, 'Histórico carregado', $hist);
    }

    pm_json(false, 'Ação inválida', null, 400);

} catch (Exception $e) {
    fechar_conexao($conexao ?? null);
    pm_json(false, $e->getMessage(), null, 500);
}

fechar_conexao($conexao);
?>
