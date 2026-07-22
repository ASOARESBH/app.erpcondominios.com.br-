<?php
// =====================================================
// IMAGENS DO MÓDULO PROJETOS — capa e fotos de obra
// =====================================================
// uploads/projetos_capas e uploads/projetos_fotos ficam com "Deny from all"
// no .htaccess (ver _os_garantir_esquema_projetos em api_ordens_servico.php)
// — este é o ÚNICO caminho para obter essas imagens. Nunca serve o arquivo
// sem antes validar:
//   1) o arquivo realmente existe;
//   2) o projeto está publicado (projeto_publico=1) OU quem pede é um
//      administrador autenticado do ERP (que pode ver mesmo antes de
//      publicar, para pré-visualizar o que está configurando).
// Não há distinção adicional para o Portal do Morador: uma vez que o
// projeto é público, a imagem é, por definição, pública também — por
// isso <img> comuns funcionam (sem precisar anexar token em cada tag).
//
// Endpoints (GET):
//   ?tipo=capa&os_id=123[&thumb=1]   — capa atual (com fallback em cascata)
//   ?tipo=foto&id=456[&thumb=1]      — uma foto específica de interação

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
ob_end_clean();

header('X-Content-Type-Options: nosniff');

function img_negar($msg = 'Acesso negado.', $code = 403) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['sucesso' => false, 'mensagem' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// Serve o arquivo e finaliza a execução se ele existir; caso contrário,
// apenas retorna false (o chamador tenta o próximo item da cascata).
function img_servir_se_existir($caminho) {
    if (!$caminho || !is_file($caminho)) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($caminho) ?: 'image/jpeg';
    if (ob_get_length()) ob_clean();
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($caminho));
    readfile($caminho);
    exit;
}

function img_servir_padrao() {
    $caminho = dirname(__DIR__) . '/assets/images/projeto_capa_padrao.svg';
    if (is_file($caminho)) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=86400');
        readfile($caminho);
        exit;
    }
    img_negar('Imagem padrão não encontrada.', 404);
}

// Caminho da miniatura correspondente a um arquivo original, se existir.
function img_caminho_thumb($dir, $arquivo) {
    $base = pathinfo($arquivo, PATHINFO_FILENAME);
    return $dir . '/' . $base . '_thumb.webp';
}

$conn = conectar_banco();
if (!$conn) img_negar('Erro ao conectar ao banco de dados.', 500);
$conn->set_charset('utf8mb4');

$tipo    = $_GET['tipo'] ?? 'capa';
$osId    = (int)($_GET['os_id'] ?? 0);
$fotoId  = (int)($_GET['id'] ?? 0);
$thumb   = !empty($_GET['thumb']);

if (!in_array($tipo, ['capa', 'foto'], true)) img_negar('Tipo inválido.', 400);

$dirCapas = dirname(__DIR__) . '/uploads/projetos_capas';
$dirFotos = dirname(__DIR__) . '/uploads/projetos_fotos';

$fotoRow = null;
if ($tipo === 'foto') {
    if (!$fotoId) img_negar('ID inválido.', 400);
    $res = $conn->query(
        "SELECT f.arquivo, i.os_id FROM os_interacao_fotos f
         JOIN os_interacoes i ON i.id = f.interacao_id
         WHERE f.id = $fotoId LIMIT 1"
    );
    $fotoRow = $res ? $res->fetch_assoc() : null;
    if (!$fotoRow) img_negar('Foto não encontrada.', 404);
    $osId = (int)$fotoRow['os_id'];
}

if (!$osId) img_negar('os_id inválido.', 400);

$res = $conn->query("SELECT projeto_publico, projeto_imagem_capa FROM os_chamados WHERE id = $osId LIMIT 1");
$os  = $res ? $res->fetch_assoc() : null;
if (!$os) img_negar('Projeto não encontrado.', 404);

// ── Autorização ──────────────────────────────────────
// Admin autenticado do ERP sempre pode ver (inclusive antes de publicar,
// para pré-visualizar). Qualquer outro caso exige projeto publicado.
$usuarioAdmin = verificarAutenticacao(false);
$autorizado   = $usuarioAdmin || (int)$os['projeto_publico'] === 1;
if (!$autorizado) img_negar('Este projeto não está disponível.', 403);

// ── Servir ────────────────────────────────────────────
if ($tipo === 'foto') {
    $arquivo = basename($fotoRow['arquivo']);
    if ($thumb) img_servir_se_existir(img_caminho_thumb($dirFotos, $arquivo));
    img_servir_se_existir($dirFotos . '/' . $arquivo);
    img_servir_padrao();
}

// tipo === 'capa' — prioridade: projeto_imagem_capa → primeira foto → padrão
if (!empty($os['projeto_imagem_capa'])) {
    $arquivo = basename($os['projeto_imagem_capa']);
    if ($thumb) img_servir_se_existir(img_caminho_thumb($dirCapas, $arquivo));
    img_servir_se_existir($dirCapas . '/' . $arquivo);
}

$resPrimeira = $conn->query(
    "SELECT f.arquivo FROM os_interacao_fotos f
     JOIN os_interacoes i ON i.id = f.interacao_id
     WHERE i.os_id = $osId
     ORDER BY f.criado_em ASC LIMIT 1"
);
$primeira = $resPrimeira ? $resPrimeira->fetch_assoc() : null;
if ($primeira) {
    $arquivo = basename($primeira['arquivo']);
    if ($thumb) img_servir_se_existir(img_caminho_thumb($dirFotos, $arquivo));
    img_servir_se_existir($dirFotos . '/' . $arquivo);
}

img_servir_padrao();
