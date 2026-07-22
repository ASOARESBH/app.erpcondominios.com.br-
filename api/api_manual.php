<?php
/**
 * API do Manual do Sistema (Base de Conhecimento Inteligente)
 * Integração total com ERP Condomínios
 */
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;

ob_start();
header('Content-Type: application/json; charset=utf-8');
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');

$conexao = conectar_banco();
if (!$conexao) {
    ob_end_clean();
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de conexão com o banco de dados.']);
    exit;
}

// Verifica autenticação
$sessao = verificarAutenticacao($conexao);
$tenant_id = exigirTenantId();
if (!$sessao) {
    ob_end_clean();
    echo json_encode(['sucesso' => false, 'mensagem' => 'Não autorizado.']);
    exit;
}

// Inicializa tabelas
_criar_tabelas($conexao);

// Leitura de parâmetros
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$acao = $_GET['acao'] ?? $_POST['acao'] ?? $body['acao'] ?? '';

// Roteamento
ob_end_clean();
try {
    switch ($acao) {
        case 'dashboard':           _dashboard($conexao, $sessao); break;
        case 'listar_modulos':      _listar_modulos($conexao); break;
        case 'listar_artigos':      _listar_artigos($conexao, $sessao); break;
        case 'buscar':              _buscar($conexao, $sessao); break;
        case 'obter_artigo':        _obter_artigo($conexao, $sessao); break;
        case 'salvar_artigo':       _salvar_artigo($conexao, $sessao, $body); break;
        case 'excluir_artigo':      _excluir_artigo($conexao, $sessao); break;
        case 'favoritar':           _favoritar($conexao, $sessao, $body); break;
        case 'avaliar':             _avaliar($conexao, $sessao, $body); break;
        case 'listar_historico':    _listar_historico($conexao, $sessao); break;
        case 'upload_imagem':       _upload_imagem($conexao, $sessao); break;
        case 'artigos_pendentes':   _artigos_pendentes($conexao, $sessao); break;
        default:
            echo json_encode(['sucesso' => false, 'mensagem' => 'Ação não reconhecida: ' . $acao]);
    }
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno: ' . $e->getMessage()]);
}

// ==========================================
// FUNÇÕES DE INFRAESTRUTURA
// ==========================================
function _criar_tabelas($db) {
    $sql = [
        "CREATE TABLE IF NOT EXISTS manual_modulos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            icone VARCHAR(50) DEFAULT 'fas fa-cube',
            page_id VARCHAR(50) NOT NULL UNIQUE,
            ordem INT DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS manual_categorias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            modulo_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            ordem INT DEFAULT 0,
            FOREIGN KEY (modulo_id) REFERENCES manual_modulos(id)
        )",
        "CREATE TABLE IF NOT EXISTS manual_artigos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            modulo_id INT NOT NULL,
            categoria_id INT,
            titulo VARCHAR(200) NOT NULL,
            resumo TEXT,
            conteudo LONGTEXT,
            tags VARCHAR(255),
            video_url VARCHAR(255),
            status ENUM('rascunho','publicado','desatualizado') DEFAULT 'publicado',
            versao VARCHAR(20) DEFAULT '1.0',
            visualizacoes INT DEFAULT 0,
            criado_por INT NOT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_por INT,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (modulo_id) REFERENCES manual_modulos(id),
            FOREIGN KEY (categoria_id) REFERENCES manual_categorias(id)
        )",
        "CREATE TABLE IF NOT EXISTS manual_historico (
            id INT AUTO_INCREMENT PRIMARY KEY,
            artigo_id INT NOT NULL,
            versao VARCHAR(20) NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            conteudo LONGTEXT,
            modificado_por INT NOT NULL,
            modificado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (artigo_id) REFERENCES manual_artigos(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS manual_buscas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            termo VARCHAR(100) NOT NULL,
            usuario_id INT,
            resultados INT DEFAULT 0,
            data_busca DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS manual_favoritos (
            usuario_id INT NOT NULL,
            artigo_id INT NOT NULL,
            data_favorito DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (usuario_id, artigo_id),
            FOREIGN KEY (artigo_id) REFERENCES manual_artigos(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS manual_avaliacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            artigo_id INT NOT NULL,
            usuario_id INT NOT NULL,
            util BOOLEAN NOT NULL,
            comentario TEXT,
            data_avaliacao DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (artigo_id, usuario_id),
            FOREIGN KEY (artigo_id) REFERENCES manual_artigos(id) ON DELETE CASCADE
        )"
    ];

    foreach ($sql as $q) {
        $db->query($q);
    }

    // Popular módulos padrão se vazio
    $res = $db->query("SELECT COUNT(*) as c FROM manual_modulos");
    if ($res && $res->fetch_assoc()['c'] == 0) {
        $modulos = [
            ['Dashboard', 'fas fa-chart-line', 'dashboard', 1],
            ['Moradores', 'fas fa-users', 'moradores', 2],
            ['Veículos', 'fas fa-car', 'veiculos', 3],
            ['Visitantes', 'fas fa-user-friends', 'visitantes', 4],
            ['Controle de Acesso', 'fas fa-door-open', 'acesso', 5],
            ['Registro Manual', 'fas fa-clipboard-list', 'registro', 6],
            ['Hidrômetros', 'fas fa-tint', 'hidrometro', 7],
            ['Financeiro', 'fas fa-money-bill-wave', 'financeiro', 8],
            ['RH', 'fas fa-id-badge', 'rh', 9],
            ['Ordens de Serviço', 'fas fa-wrench', 'ordens_servico', 10],
            ['Projetos', 'fas fa-project-diagram', 'projetos', 11],
            ['Marketplace', 'fas fa-store', 'marketplace', 12],
            ['GED', 'fas fa-folder-open', 'documentos', 13],
            ['Relatórios', 'fas fa-chart-bar', 'relatorios', 14],
            ['Configurações', 'fas fa-cog', 'configuracao', 15]
        ];
        
        foreach ($modulos as $m) {
            $db->query("INSERT INTO manual_modulos (nome, icone, page_id, ordem) VALUES ('{$m[0]}', '{$m[1]}', '{$m[2]}', {$m[3]})");
        }
    }
}

function _esc($db, $str) {
    return $db->real_escape_string($str ?? '');
}

function _pode_editar($sessao) {
    $permissao = strtolower($sessao['permissao'] ?? '');
    return in_array($permissao, ['admin', 'sindico', 'administrador', 'supervisor']);
}

// ==========================================
// ENDPOINTS
// ==========================================
function _dashboard($db, $sessao) {
    $stats = [
        'total_artigos' => 0,
        'buscas_hoje' => 0,
        'mais_acessados' => [],
        'buscas_sem_resultado' => [],
        'avaliacoes_positivas' => 0,
        'modulos_populares' => []
    ];

    // Totais
    $r = $db->query("SELECT COUNT(*) c FROM manual_artigos WHERE status='publicado'");
    if ($r) $stats['total_artigos'] = $r->fetch_assoc()['c'];

    $r = $db->query("SELECT COUNT(*) c FROM manual_buscas WHERE DATE(data_busca) = CURDATE()");
    if ($r) $stats['buscas_hoje'] = $r->fetch_assoc()['c'];

    $r = $db->query("SELECT COUNT(*) c FROM manual_avaliacoes WHERE util=1");
    if ($r) $stats['avaliacoes_positivas'] = $r->fetch_assoc()['c'];

    // Mais acessados
    $r = $db->query("SELECT id, titulo, visualizacoes FROM manual_artigos ORDER BY visualizacoes DESC LIMIT 5");
    if ($r) while ($row = $r->fetch_assoc()) $stats['mais_acessados'][] = $row;

    // Buscas sem resultado
    $r = $db->query("SELECT termo, COUNT(*) total FROM manual_buscas WHERE resultados=0 GROUP BY termo ORDER BY total DESC LIMIT 5");
    if ($r) while ($row = $r->fetch_assoc()) $stats['buscas_sem_resultado'][] = $row;

    // Módulos populares
    $r = $db->query("
        SELECT m.nome, m.icone, SUM(a.visualizacoes) total_views 
        FROM manual_modulos m 
        JOIN manual_artigos a ON a.modulo_id = m.id 
        GROUP BY m.id ORDER BY total_views DESC LIMIT 5
    ");
    if ($r) while ($row = $r->fetch_assoc()) $stats['modulos_populares'][] = $row;

    echo json_encode(['sucesso' => true, 'dados' => $stats]);
}

function _listar_modulos($db) {
    $res = $db->query("SELECT * FROM manual_modulos ORDER BY ordem ASC");
    $modulos = [];
    if ($res) while ($row = $res->fetch_assoc()) $modulos[] = $row;
    echo json_encode(['sucesso' => true, 'modulos' => $modulos]);
}

function _listar_artigos($db, $sessao) {
    $modulo_id = (int)($_GET['modulo_id'] ?? 0);
    $page_id = _esc($db, $_GET['page_id'] ?? '');
    
    $where = "a.status != 'rascunho'";
    if (_pode_editar($sessao)) $where = "1=1"; // Admin vê tudo
    
    if ($modulo_id) $where .= " AND a.modulo_id = $modulo_id";
    if ($page_id) $where .= " AND m.page_id = '$page_id'";

    $sql = "
        SELECT a.id, a.titulo, a.resumo, a.status, a.versao, a.visualizacoes, a.atualizado_em,
               m.nome as modulo_nome, m.page_id, m.icone as modulo_icone,
               (SELECT COUNT(*) FROM manual_favoritos WHERE artigo_id=a.id AND usuario_id={$sessao['id']}) as is_favorito
        FROM manual_artigos a
        JOIN manual_modulos m ON m.id = a.modulo_id
        WHERE $where
        ORDER BY a.atualizado_em DESC
    ";

    $res = $db->query($sql);
    $artigos = [];
    if ($res) while ($row = $res->fetch_assoc()) $artigos[] = $row;
    
    echo json_encode(['sucesso' => true, 'artigos' => $artigos]);
}

function _buscar($db, $sessao) {
    $termo = _esc($db, $_GET['q'] ?? '');
    if (strlen($termo) < 2) {
        echo json_encode(['sucesso' => true, 'resultados' => []]);
        return;
    }

    $where = "a.status = 'publicado'";
    $sql = "
        SELECT a.id, a.titulo, a.resumo, m.nome as modulo_nome, m.page_id, m.icone
        FROM manual_artigos a
        JOIN manual_modulos m ON m.id = a.modulo_id
        WHERE $where AND (
            a.titulo LIKE '%$termo%' OR 
            a.resumo LIKE '%$termo%' OR 
            a.conteudo LIKE '%$termo%' OR 
            a.tags LIKE '%$termo%'
        )
        ORDER BY a.visualizacoes DESC LIMIT 20
    ";

    $res = $db->query($sql);
    $resultados = [];
    if ($res) while ($row = $res->fetch_assoc()) $resultados[] = $row;

    // Registrar busca
    $uid = (int)($sessao['id'] ?? 0);
    $qtd = count($resultados);
    $db->query("INSERT INTO manual_buscas (termo, usuario_id, resultados) VALUES ('$termo', $uid, $qtd)");

    echo json_encode(['sucesso' => true, 'resultados' => $resultados]);
}

function _obter_artigo($db, $sessao) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'ID não informado.']);
        return;
    }

    // Incrementar visualizações
    $db->query("UPDATE manual_artigos SET visualizacoes = visualizacoes + 1 WHERE id = $id");

    $sql = "
        SELECT a.*, m.nome as modulo_nome, m.page_id, m.icone as modulo_icone,
               (SELECT COUNT(*) FROM manual_favoritos WHERE artigo_id=a.id AND usuario_id={$sessao['id']}) as is_favorito,
               (SELECT util FROM manual_avaliacoes WHERE artigo_id=a.id AND usuario_id={$sessao['id']} LIMIT 1) as minha_avaliacao,
               u1.nome as autor_nome, u2.nome as atualizador_nome
        FROM manual_artigos a
        JOIN manual_modulos m ON m.id = a.modulo_id
        LEFT JOIN usuarios u1 ON u1.id = a.criado_por
        LEFT JOIN usuarios u2 ON u2.id = a.atualizado_por
        WHERE a.id = $id
    ";

    $res = $db->query($sql);
    if ($res && $row = $res->fetch_assoc()) {
        echo json_encode(['sucesso' => true, 'artigo' => $row]);
    } else {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Artigo não encontrado.']);
    }
}

function _salvar_artigo($db, $sessao, $body) {
    if (!_pode_editar($sessao)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Sem permissão para editar.']);
        return;
    }

    $id = (int)($body['id'] ?? 0);
    $modulo_id = (int)($body['modulo_id'] ?? 0);
    $titulo = _esc($db, $body['titulo'] ?? '');
    $resumo = _esc($db, $body['resumo'] ?? '');
    $conteudo = _esc($db, $body['conteudo'] ?? '');
    $tags = _esc($db, $body['tags'] ?? '');
    $video_url = _esc($db, $body['video_url'] ?? '');
    $status = _esc($db, $body['status'] ?? 'publicado');
    $uid = (int)$sessao['id'];

    if (!$titulo || !$modulo_id) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Título e Módulo são obrigatórios.']);
        return;
    }

    if ($id > 0) {
        // Obter versão atual
        $res = $db->query("SELECT versao, titulo, conteudo FROM manual_artigos WHERE id = $id");
        $antigo = $res->fetch_assoc();
        
        // Incrementar versão (ex: 1.0 -> 1.1)
        $v_parts = explode('.', $antigo['versao']);
        $nova_versao = $v_parts[0] . '.' . ((int)($v_parts[1] ?? 0) + 1);

        // Salvar histórico
        $t_antigo = _esc($db, $antigo['titulo']);
        $c_antigo = _esc($db, $antigo['conteudo']);
        $db->query("INSERT INTO manual_historico (artigo_id, versao, titulo, conteudo, modificado_por) 
                    VALUES ($id, '{$antigo['versao']}', '$t_antigo', '$c_antigo', $uid)");

        // Atualizar artigo
        $sql = "UPDATE manual_artigos SET 
                modulo_id=$modulo_id, titulo='$titulo', resumo='$resumo', conteudo='$conteudo', 
                tags='$tags', video_url='$video_url', status='$status', versao='$nova_versao', 
                atualizado_por=$uid, atualizado_em=NOW() 
                WHERE id=$id";
        $db->query($sql);
        $msg = 'Artigo atualizado com sucesso (Versão '.$nova_versao.').';
    } else {
        // Inserir novo
        $sql = "INSERT INTO manual_artigos (modulo_id, titulo, resumo, conteudo, tags, video_url, status, criado_por, atualizado_por) 
                VALUES ($modulo_id, '$titulo', '$resumo', '$conteudo', '$tags', '$video_url', '$status', $uid, $uid)";
        $db->query($sql);
        $id = $db->insert_id;
        $msg = 'Artigo criado com sucesso.';
    }

    echo json_encode(['sucesso' => true, 'mensagem' => $msg, 'id' => $id]);
}

function _excluir_artigo($db, $sessao) {
    if (!_pode_editar($sessao)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Sem permissão para excluir.']);
        return;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db->query("DELETE FROM manual_artigos WHERE id = $id");
        echo json_encode(['sucesso' => true, 'mensagem' => 'Artigo excluído.']);
    } else {
        echo json_encode(['sucesso' => false, 'mensagem' => 'ID inválido.']);
    }
}

function _favoritar($db, $sessao, $body) {
    $artigo_id = (int)($body['artigo_id'] ?? 0);
    $uid = (int)$sessao['id'];
    
    if (!$artigo_id) return;

    $res = $db->query("SELECT 1 FROM manual_favoritos WHERE artigo_id=$artigo_id AND usuario_id=$uid");
    if ($res && $res->num_rows > 0) {
        $db->query("DELETE FROM manual_favoritos WHERE artigo_id=$artigo_id AND usuario_id=$uid");
        $favorito = false;
    } else {
        $db->query("INSERT INTO manual_favoritos (artigo_id, usuario_id) VALUES ($artigo_id, $uid)");
        $favorito = true;
    }

    echo json_encode(['sucesso' => true, 'favorito' => $favorito]);
}

function _avaliar($db, $sessao, $body) {
    $artigo_id = (int)($body['artigo_id'] ?? 0);
    $util = (int)($body['util'] ?? 1);
    $comentario = _esc($db, $body['comentario'] ?? '');
    $uid = (int)$sessao['id'];

    if (!$artigo_id) return;

    $db->query("INSERT INTO manual_avaliacoes (artigo_id, usuario_id, util, comentario) 
                VALUES ($artigo_id, $uid, $util, '$comentario')
                ON DUPLICATE KEY UPDATE util=$util, comentario='$comentario', data_avaliacao=NOW()");

    echo json_encode(['sucesso' => true, 'mensagem' => 'Avaliação registrada. Obrigado!']);
}

function _listar_historico($db, $sessao) {
    $artigo_id = (int)($_GET['artigo_id'] ?? 0);
    if (!$artigo_id) return;

    $sql = "SELECT h.id, h.versao, h.titulo, h.modificado_em, u.nome as autor
            FROM manual_historico h
            LEFT JOIN usuarios u ON u.id = h.modificado_por
            WHERE h.artigo_id = $artigo_id
            ORDER BY h.modificado_em DESC";
            
    $res = $db->query($sql);
    $historico = [];
    if ($res) while ($row = $res->fetch_assoc()) $historico[] = $row;

    echo json_encode(['sucesso' => true, 'historico' => $historico]);
}

function _artigos_pendentes($db, $sessao) {
    $sql = "SELECT a.id, a.titulo, m.nome as modulo_nome, a.atualizado_em
            FROM manual_artigos a
            JOIN manual_modulos m ON m.id = a.modulo_id
            WHERE a.status = 'desatualizado'
            ORDER BY a.atualizado_em DESC";
            
    $res = $db->query($sql);
    $pendentes = [];
    if ($res) while ($row = $res->fetch_assoc()) $pendentes[] = $row;

    echo json_encode(['sucesso' => true, 'pendentes' => $pendentes]);
}

function _upload_imagem($db, $sessao) {
    if (!_pode_editar($sessao)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Sem permissão.']);
        return;
    }

    if (!isset($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no upload.']);
        return;
    }

    $file = $_FILES['imagem'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowed)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Formato não permitido.']);
        return;
    }

    $dir = '../uploads/manual/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $filename = 'manual_' . time() . '_' . rand(1000,9999) . '.' . $ext;
    $path = $dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $path)) {
        $url = 'https://app.erpcondominios.com.br/uploads/manual/' . $filename;
        echo json_encode(['sucesso' => true, 'url' => $url]);
    } else {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Falha ao salvar arquivo.']);
    }
}
