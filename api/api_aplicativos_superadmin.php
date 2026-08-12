<?php
/**
 * API de Aplicativos — Portal Super-Admin
 * Catálogo global e histórico de versões APK / Google Play.
 * Não aceita tenant_id: o recurso é institucional e restrito a super_admin.
 */
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $origin) || preg_match('/^https?:\/\/localhost(:\d+)?$/', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

verificarAutenticacao(true, 'super_admin');

function apps_ok($dados = null, $mensagem = 'OK') {
    $retorno = ['sucesso' => true, 'mensagem' => $mensagem];
    if ($dados !== null) $retorno['dados'] = $dados;
    echo json_encode($retorno, JSON_UNESCAPED_UNICODE);
    exit;
}

function apps_err($mensagem, $codigo = 400) {
    http_response_code($codigo);
    echo json_encode(['sucesso' => false, 'mensagem' => $mensagem], JSON_UNESCAPED_UNICODE);
    exit;
}

// A administração de releases é institucional. Ao entrar em uma unidade, o
// Super-Admin opera como tenant e deve retornar ao painel global para alterá-la.
if (isset($_SESSION['superadmin_tenant_original'])) {
    apps_err('Retorne ao Painel Super-Admin para administrar aplicativos.', 403);
}

function apps_log($conexao, $acao, $descricao, $aplicativoId = null, $versaoId = null) {
    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
    $ip = substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $stmt = $conexao->prepare(
        'INSERT INTO aplicativos_versionamento_log (aplicativo_id, versao_id, usuario_id, acao, descricao, ip)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) return;
    $stmt->bind_param('iiisss', $aplicativoId, $versaoId, $usuarioId, $acao, $descricao, $ip);
    $stmt->execute();
    $stmt->close();
}

function apps_listar($conexao) {
    $sql = "SELECT a.*, 
                   (SELECT COUNT(*) FROM aplicativos_versoes v WHERE v.aplicativo_id = a.id) AS total_versoes,
                   (SELECT MAX(v2.version_code) FROM aplicativos_versoes v2 WHERE v2.aplicativo_id = a.id AND v2.status = 'publicado') AS version_code_publicado
            FROM aplicativos_catalogo a
            ORDER BY a.status = 'ativo' DESC, a.nome ASC";
    $result = $conexao->query($sql);
    $aplicativos = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $versoes = [];
    $resultVersoes = $conexao->query(
        "SELECT v.*, a.nome AS aplicativo_nome, a.chave AS aplicativo_chave
         FROM aplicativos_versoes v
         INNER JOIN aplicativos_catalogo a ON a.id = v.aplicativo_id
         ORDER BY a.nome ASC, v.version_code DESC"
    );
    if ($resultVersoes) $versoes = $resultVersoes->fetch_all(MYSQLI_ASSOC);

    foreach ($aplicativos as &$aplicativo) {
        $aplicativo['versoes'] = [];
        foreach ($versoes as $versao) {
            if ((int)$versao['aplicativo_id'] === (int)$aplicativo['id']) {
                $aplicativo['versoes'][] = $versao;
            }
        }
    }
    unset($aplicativo);
    apps_ok(['aplicativos' => $aplicativos]);
}

function apps_salvar_aplicativo($conexao, $input) {
    $id = (int)($input['id'] ?? 0);
    $chave = strtolower(trim((string)($input['chave'] ?? '')));
    $chave = preg_replace('/[^a-z0-9_\-]/', '_', $chave);
    $nome = trim((string)($input['nome'] ?? ''));
    $plataforma = (string)($input['plataforma'] ?? 'android');
    $packageName = trim((string)($input['package_name'] ?? ''));
    $playUrl = trim((string)($input['google_play_url'] ?? ''));
    $playPackage = trim((string)($input['google_play_package'] ?? ''));
    $descricao = trim((string)($input['descricao'] ?? ''));
    $status = (string)($input['status'] ?? 'ativo');

    if ($chave === '' || $nome === '') apps_err('Informe a chave e o nome do aplicativo.');
    if (!in_array($plataforma, ['android', 'ios', 'web'], true)) apps_err('Plataforma inválida.');
    if (!in_array($status, ['ativo', 'inativo'], true)) apps_err('Status inválido.');
    if ($playUrl !== '' && (!filter_var($playUrl, FILTER_VALIDATE_URL) || stripos($playUrl, 'https://') !== 0)) {
        apps_err('O link da Google Play deve ser uma URL HTTPS válida.');
    }

    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
    if ($id > 0) {
        $stmt = $conexao->prepare(
            'UPDATE aplicativos_catalogo
             SET chave = ?, nome = ?, plataforma = ?, package_name = NULLIF(?, \'\'),
                 google_play_url = NULLIF(?, \'\'), google_play_package = NULLIF(?, \'\'),
                 descricao = NULLIF(?, \'\'), status = ?
             WHERE id = ?'
        );
        if (!$stmt) apps_err('Não foi possível preparar a atualização do aplicativo.', 500);
        $stmt->bind_param('ssssssssi', $chave, $nome, $plataforma, $packageName, $playUrl, $playPackage, $descricao, $status, $id);
        $stmt->execute();
        $stmt->close();
        apps_log($conexao, 'APLICATIVO_ATUALIZADO', "Aplicativo atualizado: {$chave}", $id);
        apps_ok(['id' => $id], 'Aplicativo atualizado com sucesso.');
    }

    $stmt = $conexao->prepare(
        'INSERT INTO aplicativos_catalogo
         (chave, nome, plataforma, package_name, google_play_url, google_play_package, descricao, status, criado_por_usuario_id)
         VALUES (?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?)'
    );
    if (!$stmt) apps_err('Não foi possível preparar o cadastro do aplicativo.', 500);
    $stmt->bind_param('ssssssssi', $chave, $nome, $plataforma, $packageName, $playUrl, $playPackage, $descricao, $status, $usuarioId);
    $stmt->execute();
    $novoId = (int)$conexao->insert_id;
    $stmt->close();
    apps_log($conexao, 'APLICATIVO_CRIADO', "Aplicativo criado: {$chave}", $novoId);
    apps_ok(['id' => $novoId], 'Aplicativo cadastrado com sucesso.');
}

function apps_salvar_versao($conexao, $input) {
    $id = (int)($input['id'] ?? 0);
    $aplicativoId = (int)($input['aplicativo_id'] ?? 0);
    $versaoNome = trim((string)($input['versao_nome'] ?? ''));
    $versionCode = (int)($input['version_code'] ?? 0);
    $canal = (string)($input['canal'] ?? 'interno');
    $status = (string)($input['status'] ?? 'rascunho');
    $distribuicao = (string)($input['distribuicao'] ?? 'apk_direto');
    $urlApk = trim((string)($input['url_download_apk'] ?? ''));
    $tamanho = trim((string)($input['tamanho_bytes'] ?? ''));
    $sha256 = strtolower(trim((string)($input['sha256'] ?? '')));
    $minSdk = trim((string)($input['min_sdk'] ?? ''));
    $targetSdk = trim((string)($input['target_sdk'] ?? ''));
    $obrigatoria = !empty($input['obrigatoria']) ? 1 : 0;
    $notas = trim((string)($input['notas_liberacao'] ?? ''));
    $playTrack = trim((string)($input['google_play_track'] ?? ''));
    $playReleaseId = trim((string)($input['google_play_release_id'] ?? ''));

    if ($aplicativoId <= 0) apps_err('Selecione o aplicativo.');
    if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?$/', $versaoNome)) apps_err('Use o formato de versão 1.0.0.');
    if ($versionCode <= 0) apps_err('O version code deve ser maior que zero.');
    if (!in_array($canal, ['interno', 'teste', 'producao'], true)) apps_err('Canal inválido.');
    if (!in_array($status, ['rascunho', 'publicado', 'arquivado'], true)) apps_err('Status inválido.');
    if (!in_array($distribuicao, ['apk_direto', 'google_play', 'ambos'], true)) apps_err('Tipo de distribuição inválido.');
    if ($urlApk !== '' && (!filter_var($urlApk, FILTER_VALIDATE_URL) || stripos($urlApk, 'https://') !== 0)) apps_err('A URL do APK deve ser HTTPS válida.');
    if ($sha256 !== '' && !preg_match('/^[a-f0-9]{64}$/', $sha256)) apps_err('O SHA-256 deve possuir 64 caracteres hexadecimais.');
    if ($tamanho !== '' && (!ctype_digit($tamanho) || (int)$tamanho < 0)) apps_err('Tamanho do APK inválido.');

    $check = $conexao->prepare('SELECT id FROM aplicativos_catalogo WHERE id = ? LIMIT 1');
    $check->bind_param('i', $aplicativoId);
    $check->execute();
    $aplicativoExiste = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$aplicativoExiste) apps_err('Aplicativo não encontrado.', 404);

    $tamanhoBytes = $tamanho === '' ? null : (int)$tamanho;
    $publicadoEm = $status === 'publicado' ? date('Y-m-d H:i:s') : null;
    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

    if ($id > 0) {
        $stmt = $conexao->prepare(
            'UPDATE aplicativos_versoes SET aplicativo_id = ?, versao_nome = ?, version_code = ?, canal = ?, status = ?, distribuicao = ?,
             url_download_apk = NULLIF(?, \'\'), tamanho_bytes = ?, sha256 = NULLIF(?, \'\'), min_sdk = NULLIF(?, \'\'),
             target_sdk = NULLIF(?, \'\'), obrigatoria = ?, notas_liberacao = NULLIF(?, \'\'),
             google_play_track = NULLIF(?, \'\'), google_play_release_id = NULLIF(?, \'\'),
             publicado_em = CASE WHEN ? = \'publicado\' THEN COALESCE(publicado_em, ?) ELSE NULL END
             WHERE id = ?'
        );
        if (!$stmt) apps_err('Não foi possível preparar a atualização da versão.', 500);
        $stmt->bind_param('isissssisssisssssi', $aplicativoId, $versaoNome, $versionCode, $canal, $status, $distribuicao,
            $urlApk, $tamanhoBytes, $sha256, $minSdk, $targetSdk, $obrigatoria, $notas, $playTrack, $playReleaseId, $status, $publicadoEm, $id);
        $stmt->execute();
        $stmt->close();
        apps_log($conexao, 'VERSAO_ATUALIZADA', "Release {$versaoNome} (code {$versionCode}) atualizado", $aplicativoId, $id);
        apps_ok(['id' => $id], 'Versão atualizada com sucesso.');
    }

    $stmt = $conexao->prepare(
        'INSERT INTO aplicativos_versoes
         (aplicativo_id, versao_nome, version_code, canal, status, distribuicao, url_download_apk, tamanho_bytes, sha256, min_sdk, target_sdk, obrigatoria, notas_liberacao, google_play_track, google_play_release_id, publicado_em, criado_por_usuario_id)
         VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?)'
    );
    if (!$stmt) apps_err('Não foi possível preparar o cadastro da versão.', 500);
    $stmt->bind_param('isissssisssissssi', $aplicativoId, $versaoNome, $versionCode, $canal, $status, $distribuicao,
        $urlApk, $tamanhoBytes, $sha256, $minSdk, $targetSdk, $obrigatoria, $notas, $playTrack, $playReleaseId, $publicadoEm, $usuarioId);
    $stmt->execute();
    $novoId = (int)$conexao->insert_id;
    $stmt->close();
    apps_log($conexao, 'VERSAO_CRIADA', "Release {$versaoNome} (code {$versionCode}) criada", $aplicativoId, $novoId);
    apps_ok(['id' => $novoId], 'Versão cadastrada com sucesso.');
}

function apps_publicar_versao($conexao, $input) {
    $versaoId = (int)($input['id'] ?? 0);
    if ($versaoId <= 0) apps_err('ID da versão obrigatório.');

    $stmt = $conexao->prepare('SELECT aplicativo_id, versao_nome, version_code, canal FROM aplicativos_versoes WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $versaoId);
    $stmt->execute();
    $versao = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$versao) apps_err('Versão não encontrada.', 404);

    $conexao->begin_transaction();
    try {
        if ($versao['canal'] === 'producao') {
            $arquivar = $conexao->prepare("UPDATE aplicativos_versoes SET status = 'arquivado' WHERE aplicativo_id = ? AND canal = 'producao' AND status = 'publicado' AND id != ?");
            $arquivar->bind_param('ii', $versao['aplicativo_id'], $versaoId);
            $arquivar->execute();
            $arquivar->close();
        }
        $publicar = $conexao->prepare("UPDATE aplicativos_versoes SET status = 'publicado', publicado_em = NOW() WHERE id = ?");
        $publicar->bind_param('i', $versaoId);
        $publicar->execute();
        $publicar->close();
        $conexao->commit();
    } catch (Throwable $erro) {
        $conexao->rollback();
        throw $erro;
    }

    apps_log($conexao, 'VERSAO_PUBLICADA', "Release {$versao['versao_nome']} (code {$versao['version_code']}) publicada no canal {$versao['canal']}", (int)$versao['aplicativo_id'], $versaoId);
    apps_ok(null, 'Versão publicada com sucesso.');
}

function apps_arquivar_versao($conexao, $input) {
    $versaoId = (int)($input['id'] ?? 0);
    if ($versaoId <= 0) apps_err('ID da versão obrigatório.');
    $stmt = $conexao->prepare("UPDATE aplicativos_versoes SET status = 'arquivado' WHERE id = ?");
    $stmt->bind_param('i', $versaoId);
    $stmt->execute();
    $afetados = $stmt->affected_rows;
    $stmt->close();
    if ($afetados < 1) apps_err('Versão não encontrada.', 404);
    apps_log($conexao, 'VERSAO_ARQUIVADA', "Versão ID {$versaoId} arquivada", null, $versaoId);
    apps_ok(null, 'Versão arquivada.');
}

$conexao = null;
try {
    $conexao = conectar_banco();
    $action = $_GET['action'] ?? $_POST['action'] ?? 'listar';
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;

    if ($action === 'listar') apps_listar($conexao);
    if ($action === 'salvar_aplicativo') apps_salvar_aplicativo($conexao, $input);
    if ($action === 'salvar_versao') apps_salvar_versao($conexao, $input);
    if ($action === 'publicar_versao') apps_publicar_versao($conexao, $input);
    if ($action === 'arquivar_versao') apps_arquivar_versao($conexao, $input);
    apps_err('Ação não reconhecida.');
} catch (Throwable $erro) {
    error_log('[api_aplicativos_superadmin] ' . $erro->getMessage());
    apps_err('Não foi possível processar a solicitação de aplicativos.', 500);
}
?>
