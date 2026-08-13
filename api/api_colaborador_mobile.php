<?php
/**
 * API MOBILE — PORTAL DO COLABORADOR
 *
 * Fluxos: autenticação por e-mail/senha, sessão Bearer, chamados, pesquisa de
 * moradores, recebimento de protocolos, leitura QR e entrega autenticada.
 *
 * O tenant é obtido exclusivamente da sessão móvel. O aplicativo nunca envia
 * tenant_id nas operações autenticadas.
 */
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/helpers/tenant_file_storage_helper.php';
require_once __DIR__ . '/helpers/ronda_helper.php';
// O login não depende do módulo de notificações. Em instalações parciais, uma
// ausência deste helper não pode provocar erro 500 ao autenticar o colaborador.
$cm_helper_protocolos = __DIR__ . '/helpers/protocol_notification_helper.php';
if (is_file($cm_helper_protocolos)) {
    require_once $cm_helper_protocolos;
}
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://app.erpcondominios.com.br');
}
header('Access-Control-Allow-Credentials: false');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function cm_json($sucesso, $mensagem, $dados = null, $codigo = 200) {
    http_response_code($codigo);
    $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
    if ($dados !== null) $resposta['dados'] = $dados;
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    exit;
}

function cm_log($evento, array $dados = []) {
    $seguros = [];
    foreach ($dados as $chave => $valor) {
        if (in_array($chave, ['senha', 'token', 'cpf'], true)) continue;
        $seguros[$chave] = $valor;
    }
    error_log('[ColaboradorMobile] ' . $evento . ' ' . json_encode($seguros, JSON_UNESCAPED_UNICODE));
}

function cm_input() {
    $corpo = file_get_contents('php://input');
    $json = $corpo ? json_decode($corpo, true) : null;
    return is_array($json) ? $json : $_POST;
}

function cm_tabela_existe(mysqli $conexao, $tabela) {
    $stmt = $conexao->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    if (!$stmt) return false;
    $stmt->bind_param('s', $tabela);
    $stmt->execute();
    $existe = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $existe;
}

function cm_token_bearer() {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$authorization && function_exists('getallheaders')) {
        foreach (getallheaders() as $nome => $valor) {
            if (strtolower($nome) === 'authorization') {
                $authorization = $valor;
                break;
            }
        }
    }
    return preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m) ? trim($m[1]) : null;
}

function cm_nivel_permissao($permissao) {
    $niveis = ['visualizador' => 1, 'operador' => 2, 'gerente' => 3, 'admin' => 4, 'super_admin' => 5];
    return $niveis[$permissao] ?? 0;
}

function cm_autenticar(mysqli $conexao, $minima = 'operador') {
    $token = cm_token_bearer();
    if (!$token || strlen($token) < 40) cm_json(false, 'Sessão do colaborador não informada.', null, 401);

    $hash = hash('sha256', $token);
    $stmt = $conexao->prepare(
        "SELECT s.id AS sessao_id, s.usuario_id, s.tenant_id,
                u.nome, u.email, u.funcao, u.departamento, u.permissao,
                ut.permissao AS permissao_tenant,
                t.nome_fantasia, t.razao_social, t.slug
         FROM sessoes_colaborador_mobile s
         INNER JOIN usuarios u ON u.id = s.usuario_id
         INNER JOIN tenants t ON t.id = s.tenant_id
         INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = s.tenant_id AND ut.ativo = 1
         WHERE s.token_hash = ? AND s.ativo = 1 AND s.data_expiracao > NOW()
           AND u.ativo = 1 AND t.status = 'ativo'
         LIMIT 1"
    );
    if (!$stmt) cm_json(false, 'Não foi possível validar a sessão.', null, 500);
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $dados = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$dados) cm_json(false, 'Sessão inválida, expirada ou sem acesso ao condomínio.', null, 401);
    $permissao_efetiva = cm_nivel_permissao($dados['permissao_tenant']) < cm_nivel_permissao($dados['permissao'])
        ? $dados['permissao_tenant']
        : $dados['permissao'];
    $dados['permissao'] = $permissao_efetiva;
    if (cm_nivel_permissao($permissao_efetiva) < cm_nivel_permissao($minima)) {
        cm_json(false, 'Permissão insuficiente para esta operação.', null, 403);
    }

    $stmt = $conexao->prepare('UPDATE sessoes_colaborador_mobile SET ultimo_uso = NOW() WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $dados['sessao_id']);
        $stmt->execute();
        $stmt->close();
    }
    return $dados;
}

function cm_cpf($valor) {
    return preg_replace('/\D+/', '', (string)$valor);
}

function cm_coluna_existe(mysqli $conexao, $tabela, $coluna) {
    $stmt = $conexao->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    if (!$stmt) return false;
    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $encontrada = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $encontrada;
}

function cm_numero_os(mysqli $conexao, $tenant_id) {
    $ano = date('Y');
    $stmt = $conexao->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED)) AS ultimo
         FROM os_chamados WHERE tenant_id = ? AND numero LIKE ?"
    );
    $like = '%-' . $ano . '-%';
    $stmt->bind_param('is', $tenant_id, $like);
    $stmt->execute();
    $ultimo = (int)($stmt->get_result()->fetch_assoc()['ultimo'] ?? 0);
    $stmt->close();
    return 'OS-' . $ano . '-' . str_pad((string)($ultimo + 1), 4, '0', STR_PAD_LEFT);
}

function cm_calcular_valor_agua($consumo) {
    // Mantém a regra vigente de api_leituras.php: mínimo de 10 m³ / R$ 61,60
    // e R$ 6,16 por m³ acima do mínimo.
    return $consumo <= 10 ? 61.60 : $consumo * 6.16;
}

function cm_validar_hidrometro_mobile(mysqli $conexao, $tenant_id, $hidrometro_id) {
    $stmt = $conexao->prepare(
        "SELECT h.id, h.morador_id, h.unidade, h.numero_hidrometro, h.numero_lacre, h.ativo,
                m.nome AS morador_nome
         FROM hidrometros h
         INNER JOIN moradores m ON m.id = h.morador_id
         WHERE m.tenant_id = ? AND h.id = ? LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('ii', $tenant_id, $hidrometro_id);
    $stmt->execute();
    $hidrometro = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $hidrometro ?: null;
}

function cm_buscar_protocolo_qr(mysqli $conexao, $tenant_id, $codigo) {
    $id = 0;
    if (preg_match('/(?:PROTOCOLO:|PROTOCOLO-|^)(\d+)$/i', trim($codigo), $m)) $id = (int)$m[1];

    if ($id > 0) {
        $stmt = $conexao->prepare(
            "SELECT p.*, m.nome AS morador_nome, m.cpf AS morador_cpf, u.nome AS unidade_nome
             FROM protocolos p
             INNER JOIN moradores m ON m.id = p.morador_id AND m.tenant_id = p.tenant_id
             LEFT JOIN unidades u ON u.id = p.unidade_id AND u.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $tenant_id, $id);
    } else {
        $stmt = $conexao->prepare(
            "SELECT p.*, m.nome AS morador_nome, m.cpf AS morador_cpf, u.nome AS unidade_nome
             FROM protocolos p
             INNER JOIN moradores m ON m.id = p.morador_id AND m.tenant_id = p.tenant_id
             LEFT JOIN unidades u ON u.id = p.unidade_id AND u.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.codigo_nf = ?
             ORDER BY FIELD(p.status, 'pendente', 'entregue'), p.data_hora_recebimento DESC LIMIT 1"
        );
        $stmt->bind_param('is', $tenant_id, $codigo);
    }
    $stmt->execute();
    $protocolo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $protocolo ?: null;
}

function cm_vigilante_colaboradores_da_sessao(mysqli $conexao, int $tenant_id, string $email): array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [];
    $stmt = $conexao->prepare(
        "SELECT id, nome, cargo, departamento FROM rh_colaboradores
         WHERE tenant_id = ? AND LOWER(email) = ? AND ativo = 1
         ORDER BY id ASC LIMIT 2"
    );
    if (!$stmt) return [];
    $stmt->bind_param('is', $tenant_id, $email);
    $stmt->execute();
    $colaboradores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $colaboradores;
}

function cm_vigilante_ponto_por_token(mysqli $conexao, string $token): ?array {
    $stmt = $conexao->prepare(
        "SELECT p.id AS ponto_id, p.tenant_id, p.rota_id, p.nome AS ponto_nome,
                p.localizacao, p.instrucoes, p.ordem,
                r.nome AS rota_nome, r.descricao AS rota_descricao, r.hora_inicio,
                r.hora_fim, r.intervalo_minutos, r.repeticoes_por_dia,
                r.tolerancia_minutos, r.dias_semana
         FROM ronda_pontos p
         INNER JOIN ronda_rotas r ON r.id = p.rota_id AND r.tenant_id = p.tenant_id
         INNER JOIN tenants t ON t.id = p.tenant_id
         WHERE p.token_qr = ? AND p.ativo = 1 AND r.ativo = 1 AND t.status = 'ativo'
         LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $ponto = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ponto ?: null;
}

function cm_vigilante_opcoes_rota(mysqli $conexao, int $tenant_id, int $rota_id, int $sessao_id): array {
    $stmt = $conexao->prepare(
        "SELECT c.id, c.nome, c.cargo, c.departamento
         FROM ronda_vigilantes rv
         INNER JOIN rh_colaboradores c ON c.id = rv.colaborador_id AND c.tenant_id = rv.tenant_id
         WHERE rv.tenant_id = ? AND rv.rota_id = ? AND rv.ativo = 1 AND c.ativo = 1
         ORDER BY c.nome ASC"
    );
    if (!$stmt) return [];
    $stmt->bind_param('ii', $tenant_id, $rota_id);
    $stmt->execute();
    $vigilantes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $expira_em = time() + 300;
    $opcoes = [];
    foreach ($vigilantes as $vigilante) {
        $conteudo = implode('|', [$sessao_id, $tenant_id, $rota_id, (int)$vigilante['id'], $expira_em]);
        $assinatura = hash_hmac('sha256', $conteudo, DB_PASS);
        $opcoes[] = [
            'opcao' => rtrim(strtr(base64_encode($conteudo . '|' . $assinatura), '+/', '-_'), '='),
            'nome' => $vigilante['nome'],
            'cargo' => $vigilante['cargo'],
            'departamento' => $vigilante['departamento'],
        ];
    }
    return $opcoes;
}

function cm_vigilante_validar_opcao(mysqli $conexao, string $opcao, int $sessao_id, int $tenant_id, int $rota_id): ?array {
    $opcao = trim($opcao);
    if ($opcao === '' || !preg_match('/^[A-Za-z0-9_-]{40,512}$/', $opcao)) return null;
    $codificado = strtr($opcao, '-_', '+/');
    $codificado .= str_repeat('=', (4 - strlen($codificado) % 4) % 4);
    $decodificado = base64_decode($codificado, true);
    if ($decodificado === false) return null;
    $partes = explode('|', $decodificado);
    if (count($partes) !== 6) return null;
    [$sessao, $tenant, $rota, $colaborador, $expira_em, $assinatura] = $partes;
    if (!ctype_digit($sessao) || !ctype_digit($tenant) || !ctype_digit($rota)
        || !ctype_digit($colaborador) || !ctype_digit($expira_em)
        || (int)$sessao !== $sessao_id || (int)$tenant !== $tenant_id
        || (int)$rota !== $rota_id || (int)$expira_em < time()) return null;
    $conteudo = implode('|', [$sessao, $tenant, $rota, $colaborador, $expira_em]);
    if (!hash_equals(hash_hmac('sha256', $conteudo, DB_PASS), $assinatura)) return null;

    $stmt = $conexao->prepare(
        "SELECT c.id, c.nome, c.cargo, c.departamento
         FROM ronda_vigilantes rv
         INNER JOIN rh_colaboradores c ON c.id = rv.colaborador_id AND c.tenant_id = rv.tenant_id
         WHERE rv.tenant_id = ? AND rv.rota_id = ? AND rv.colaborador_id = ?
           AND rv.ativo = 1 AND c.ativo = 1 LIMIT 1"
    );
    if (!$stmt) return null;
    $colaborador_id = (int)$colaborador;
    $stmt->bind_param('iii', $tenant_id, $rota_id, $colaborador_id);
    $stmt->execute();
    $vigilante = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $vigilante ?: null;
}

function cm_vigilante_auditar(mysqli $conexao, int $tenant_id, int $rota_id, int $usuario_id, string $descricao, array $dados = []): void {
    $json = $dados ? json_encode($dados, JSON_UNESCAPED_UNICODE) : null;
    $ip = substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $acao = 'LEITURA_QR_MOBILE';
    $stmt = $conexao->prepare('INSERT INTO ronda_auditoria (tenant_id, rota_id, usuario_id, acao, descricao, dados_json, ip) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) return;
    $stmt->bind_param('iiissss', $tenant_id, $rota_id, $usuario_id, $acao, $descricao, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

function cm_login(mysqli $conexao, array $dados) {
    $email = strtolower(trim((string)($dados['email'] ?? '')));
    $senha = (string)($dados['senha'] ?? '');
    $tenant_selecionado = (int)($dados['tenant_id'] ?? 0);
    $dispositivo = substr(trim((string)($dados['dispositivo'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'mobile'))), 0, 500);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
        cm_json(false, 'Informe um e-mail válido e a senha.');
    }

    // Evita o fatal "bind_param() on bool" quando a migração do módulo ainda
    // não foi executada no HostGator. O app recebe uma resposta tratável.
    if (!cm_tabela_existe($conexao, 'sessoes_colaborador_mobile')) {
        cm_log('login_bloqueado', [
            'email' => $email,
            'motivo' => 'migracao_sessoes_colaborador_ausente',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido',
        ]);
        cm_json(
            false,
            'O Portal do Colaborador ainda não foi instalado no servidor. Execute a migração migration_portal_colaborador_mobile.sql.',
            null,
            503
        );
    }

    $stmt = $conexao->prepare(
        'SELECT id, nome, email, senha, funcao, departamento, permissao, ativo FROM usuarios WHERE LOWER(email) = ? LIMIT 1'
    );
    if (!$stmt) {
        cm_log('login_erro', ['motivo' => 'consulta_usuario_indisponivel']);
        cm_json(false, 'Não foi possível validar o acesso agora.', null, 503);
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$usuario || (int)$usuario['ativo'] !== 1 || !password_verify($senha, $usuario['senha'])) {
        cm_log('login_negado', ['email' => $email, 'motivo' => 'credenciais_invalidas']);
        cm_json(false, 'E-mail ou senha incorretos.', null, 401);
    }
    if (cm_nivel_permissao($usuario['permissao']) < cm_nivel_permissao('operador')) {
        cm_log('login_negado', ['usuario_id' => $usuario['id'], 'motivo' => 'permissao']);
        cm_json(false, 'Este usuário não possui perfil operacional para o Portal do Colaborador.', null, 403);
    }

    $stmt = $conexao->prepare(
        "SELECT t.id, t.slug, COALESCE(NULLIF(t.nome_fantasia, ''), t.razao_social) AS nome, ut.permissao
         FROM usuario_tenant ut
         INNER JOIN tenants t ON t.id = ut.tenant_id
         WHERE ut.usuario_id = ? AND ut.ativo = 1 AND t.status = 'ativo'
         ORDER BY t.nome_fantasia, t.razao_social"
    );
    if (!$stmt) {
        cm_log('login_erro', ['usuario_id' => $usuario['id'], 'motivo' => 'vinculo_tenant_indisponivel']);
        cm_json(false, 'A configuração de condomínios deste usuário ainda não está disponível.', null, 503);
    }
    $stmt->bind_param('i', $usuario['id']);
    $stmt->execute();
    $tenants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$tenants) {
        cm_log('login_negado', ['usuario_id' => $usuario['id'], 'motivo' => 'sem_vinculo_tenant']);
        cm_json(false, 'Seu usuário não possui vínculo ativo com nenhum condomínio.', null, 403);
    }

    $tenant = null;
    if ($tenant_selecionado > 0) {
        foreach ($tenants as $item) {
            if ((int)$item['id'] === $tenant_selecionado) {
                $tenant = $item;
                break;
            }
        }
        if (!$tenant) cm_json(false, 'O condomínio selecionado não está autorizado para este usuário.', null, 403);
    } elseif (count($tenants) === 1) {
        $tenant = $tenants[0];
    } else {
        cm_json(true, 'Selecione o condomínio para continuar.', [
            'requer_selecao_tenant' => true,
            'tenants' => array_map(function($item) {
                return ['id' => (int)$item['id'], 'nome' => $item['nome'], 'slug' => $item['slug']];
            }, $tenants)
        ]);
    }

    // A permissão efetiva não pode exceder a do vínculo no tenant.
    $permissao = cm_nivel_permissao($tenant['permissao']) < cm_nivel_permissao($usuario['permissao'])
        ? $tenant['permissao'] : $usuario['permissao'];
    if (cm_nivel_permissao($permissao) < cm_nivel_permissao('operador')) {
        cm_json(false, 'Seu vínculo com este condomínio não possui permissão operacional.', null, 403);
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expiracao = date('Y-m-d H:i:s', strtotime('+8 hours'));
    $stmt = $conexao->prepare('UPDATE sessoes_colaborador_mobile SET ativo = 0 WHERE usuario_id = ? AND tenant_id = ? AND ativo = 1');
    if (!$stmt) {
        cm_log('login_erro', ['usuario_id' => $usuario['id'], 'tenant_id' => $tenant['id'], 'motivo' => 'sessao_indisponivel']);
        cm_json(false, 'A sessão móvel não está disponível. Confirme a migração do Portal do Colaborador.', null, 503);
    }
    $stmt->bind_param('ii', $usuario['id'], $tenant['id']);
    $stmt->execute();
    $stmt->close();

    $stmt = $conexao->prepare(
        'INSERT INTO sessoes_colaborador_mobile (usuario_id, tenant_id, token_hash, dispositivo, data_expiracao, ultimo_uso, ativo) VALUES (?, ?, ?, ?, ?, NOW(), 1)'
    );
    if (!$stmt) {
        cm_log('login_erro', ['usuario_id' => $usuario['id'], 'tenant_id' => $tenant['id'], 'motivo' => 'insert_sessao_indisponivel']);
        cm_json(false, 'Não foi possível criar a sessão móvel. Confirme a migração do Portal do Colaborador.', null, 503);
    }
    $stmt->bind_param('iisss', $usuario['id'], $tenant['id'], $hash, $dispositivo, $expiracao);
    if (!$stmt->execute()) cm_json(false, 'Não foi possível iniciar a sessão do colaborador.', null, 500);
    $stmt->close();

    cm_log('login_sucesso', ['usuario_id' => $usuario['id'], 'tenant_id' => $tenant['id']]);
    cm_json(true, 'Login do colaborador realizado com sucesso.', [
        'token' => $token,
        'expira_em' => $expiracao,
        'usuario' => [
            'id' => (int)$usuario['id'],
            'nome' => $usuario['nome'],
            'email' => $usuario['email'],
            'funcao' => $usuario['funcao'],
            'departamento' => $usuario['departamento'],
            'permissao' => $permissao,
        ],
        'tenant' => ['id' => (int)$tenant['id'], 'nome' => $tenant['nome'], 'slug' => $tenant['slug']],
    ]);
}

$conexao = conectar_banco();
if (!$conexao) cm_json(false, 'Não foi possível conectar ao banco.', null, 500);
$metodo = $_SERVER['REQUEST_METHOD'];
$dados = cm_input();
$acao = trim((string)($_GET['action'] ?? $dados['action'] ?? ''));

if ($acao === 'login' && $metodo === 'POST') cm_login($conexao, $dados);

$usuario = cm_autenticar($conexao, 'operador');
$tenant_id = (int)$usuario['tenant_id'];
$usuario_id = (int)$usuario['usuario_id'];

switch ($acao) {
    case 'sessao':
        cm_json(true, 'Sessão válida.', [
            'usuario' => ['id' => $usuario_id, 'nome' => $usuario['nome'], 'email' => $usuario['email'], 'funcao' => $usuario['funcao'], 'permissao' => $usuario['permissao']],
            'tenant' => ['id' => $tenant_id, 'nome' => $usuario['nome_fantasia'] ?: $usuario['razao_social'], 'slug' => $usuario['slug']],
        ]);
        break;

    case 'logout':
        if ($metodo !== 'POST') cm_json(false, 'Método não permitido.', null, 405);
        $stmt = $conexao->prepare('UPDATE sessoes_colaborador_mobile SET ativo = 0 WHERE id = ? AND tenant_id = ?');
        $stmt->bind_param('ii', $usuario['sessao_id'], $tenant_id);
        $stmt->execute();
        $stmt->close();
        cm_log('logout', ['usuario_id' => $usuario_id, 'tenant_id' => $tenant_id]);
        cm_json(true, 'Sessão encerrada.');
        break;

    case 'dashboard':
        $resultado = ['protocolos_pendentes' => 0, 'chamados_abertos' => 0, 'entregas_hoje' => 0];
        $stmt = $conexao->prepare("SELECT COUNT(*) AS total FROM protocolos WHERE tenant_id = ? AND status = 'pendente'");
        $stmt->bind_param('i', $tenant_id); $stmt->execute();
        $resultado['protocolos_pendentes'] = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
        $stmt = $conexao->prepare("SELECT COUNT(*) AS total FROM os_chamados WHERE tenant_id = ? AND status IN ('aberto','andamento')");
        $stmt->bind_param('i', $tenant_id); $stmt->execute();
        $resultado['chamados_abertos'] = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
        $stmt = $conexao->prepare("SELECT COUNT(*) AS total FROM protocolos WHERE tenant_id = ? AND status = 'entregue' AND DATE(data_hora_entrega) = CURDATE()");
        $stmt->bind_param('i', $tenant_id); $stmt->execute();
        $resultado['entregas_hoje'] = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
        cm_json(true, 'Painel carregado.', $resultado);
        break;

    case 'vigilante_qr_detalhe':
        if ($metodo !== 'GET') cm_json(false, 'Método não permitido.', null, 405);
        $token_qr = strtolower(trim((string)($_GET['token'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $token_qr)) cm_json(false, 'QR Code inválido.', null, 400);
        $ponto = cm_vigilante_ponto_por_token($conexao, $token_qr);
        if (!$ponto) cm_json(false, 'Este ponto QR está inativo ou não foi encontrado.', null, 404);
        if ((int)$ponto['tenant_id'] !== $tenant_id) {
            cm_log('vigilante_qr_bloqueado', ['tenant_id' => $tenant_id, 'usuario_id' => $usuario_id, 'motivo' => 'tenant_divergente']);
            cm_json(false, 'Este QR Code não pertence ao condomínio da sua sessão.', null, 403);
        }
        $colaboradores = cm_vigilante_colaboradores_da_sessao($conexao, $tenant_id, (string)$usuario['email']);
        $requer_selecao = count($colaboradores) !== 1;
        $dados_ponto = [
            'ponto' => ['nome' => $ponto['ponto_nome'], 'localizacao' => $ponto['localizacao'], 'ordem' => (int)$ponto['ordem']],
            'rota' => [
                'nome' => $ponto['rota_nome'], 'descricao' => $ponto['rota_descricao'],
                'hora_inicio' => $ponto['hora_inicio'], 'hora_fim' => $ponto['hora_fim'],
                'intervalo_minutos' => (int)$ponto['intervalo_minutos'],
                'tolerancia_minutos' => (int)$ponto['tolerancia_minutos'],
            ],
            'instrucoes' => $ponto['instrucoes'],
            'requer_selecao_vigilante' => $requer_selecao,
        ];
        if ($requer_selecao) {
            $dados_ponto['opcoes_vigilante'] = cm_vigilante_opcoes_rota($conexao, $tenant_id, (int)$ponto['rota_id'], (int)$usuario['sessao_id']);
            $dados_ponto['mensagem_identidade'] = 'Seu acesso não foi associado automaticamente a um colaborador ativo. Selecione o vigilante vinculado à rota.';
        } else {
            $dados_ponto['vigilante'] = ['nome' => $colaboradores[0]['nome']];
        }
        cm_log('vigilante_qr_validado', ['tenant_id' => $tenant_id, 'usuario_id' => $usuario_id, 'ponto_id' => $ponto['ponto_id'], 'rota_id' => $ponto['rota_id']]);
        cm_json(true, 'Ponto de ronda validado.', $dados_ponto);
        break;

    case 'vigilante_registrar_leitura':
        if ($metodo !== 'POST') cm_json(false, 'Método não permitido.', null, 405);
        $token_qr = strtolower(trim((string)($dados['token'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $token_qr)) cm_json(false, 'QR Code inválido.', null, 400);
        $ponto = cm_vigilante_ponto_por_token($conexao, $token_qr);
        if (!$ponto) cm_json(false, 'Ponto QR inválido ou inativo.', null, 404);
        if ((int)$ponto['tenant_id'] !== $tenant_id) {
            cm_log('vigilante_registro_bloqueado', ['tenant_id' => $tenant_id, 'usuario_id' => $usuario_id, 'motivo' => 'tenant_divergente']);
            cm_json(false, 'Este QR Code não pertence ao condomínio da sua sessão.', null, 403);
        }
        $colaboradores = cm_vigilante_colaboradores_da_sessao($conexao, $tenant_id, (string)$usuario['email']);
        if (count($colaboradores) === 1) {
            $vigilante = $colaboradores[0];
        } else {
            $vigilante = cm_vigilante_validar_opcao($conexao, (string)($dados['opcao_vigilante'] ?? ''), (int)$usuario['sessao_id'], $tenant_id, (int)$ponto['rota_id']);
            if (!$vigilante) cm_json(false, 'Confirme o vigilante vinculado à rota antes de registrar a leitura.', null, 409);
        }
        $colaborador_id = (int)$vigilante['id'];
        $stmt = $conexao->prepare('SELECT 1 FROM ronda_vigilantes WHERE tenant_id = ? AND rota_id = ? AND colaborador_id = ? AND ativo = 1 LIMIT 1');
        if (!$stmt) cm_json(false, 'Não foi possível validar o vínculo do vigilante.', null, 503);
        $stmt->bind_param('iii', $tenant_id, $ponto['rota_id'], $colaborador_id);
        $stmt->execute();
        $vinculo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$vinculo) cm_json(false, 'O vigilante da sessão não está vinculado a esta rota.', null, 403);
        if (!rv_rota_ativa_hoje($ponto)) cm_json(false, 'Esta rota não está programada para hoje.', null, 409);
        [$status_sla, $atraso_minutos, $ciclo] = rv_status_sla($ponto);
        if ($status_sla === 'fora_janela') cm_json(false, 'Esta leitura está fora da janela programada da rota.', null, 409);

        $latitude = null; $longitude = null; $precisao = null;
        $tem_gps = isset($dados['latitude']) || isset($dados['longitude']) || isset($dados['precisao_metros']);
        if ($tem_gps) {
            if (!is_numeric($dados['latitude'] ?? null) || !is_numeric($dados['longitude'] ?? null)
                || (isset($dados['precisao_metros']) && !is_numeric($dados['precisao_metros']))) {
                cm_json(false, 'Dados de localização inválidos.', null, 400);
            }
            $latitude = (float)$dados['latitude'];
            $longitude = (float)$dados['longitude'];
            $precisao = isset($dados['precisao_metros']) ? (float)$dados['precisao_metros'] : null;
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180
                || ($precisao !== null && ($precisao < 0 || $precisao > 100000))) {
                cm_json(false, 'Dados de localização inválidos.', null, 400);
            }
        }
        $ciclo_chave = hash('sha256', $tenant_id . '|' . $ponto['rota_id'] . '|' . $colaborador_id . '|' . $ciclo['chave_base']);
        $previsto_em = $ciclo['previsto_em'];
        $ip = substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $user_agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $stmt = $conexao->prepare('INSERT INTO ronda_registros (tenant_id, rota_id, ponto_id, colaborador_id, ciclo_chave, previsto_em, status_sla, atraso_minutos, latitude, longitude, precisao_metros, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) cm_json(false, 'Não foi possível preparar o registro da ronda.', null, 503);
        $stmt->bind_param('iiiisssidddss', $tenant_id, $ponto['rota_id'], $ponto['ponto_id'], $colaborador_id, $ciclo_chave, $previsto_em, $status_sla, $atraso_minutos, $latitude, $longitude, $precisao, $ip, $user_agent);
        $registrado = $stmt->execute();
        $errno = $stmt->errno;
        $stmt->close();
        if (!$registrado && $errno === 1062) cm_json(false, 'Este ponto já foi registrado neste ciclo de ronda.', ['status_sla' => $status_sla], 409);
        if (!$registrado) cm_json(false, 'Não foi possível registrar a leitura da ronda.', null, 500);
        cm_vigilante_auditar($conexao, $tenant_id, (int)$ponto['rota_id'], $usuario_id, 'Leitura registrada no aplicativo no ponto ' . $ponto['ponto_nome'], ['colaborador_id' => $colaborador_id, 'ponto_id' => (int)$ponto['ponto_id'], 'status_sla' => $status_sla, 'atraso_minutos' => $atraso_minutos]);
        cm_log('vigilante_leitura_registrada', ['tenant_id' => $tenant_id, 'usuario_id' => $usuario_id, 'ponto_id' => $ponto['ponto_id'], 'rota_id' => $ponto['rota_id'], 'status_sla' => $status_sla]);
        cm_json(true, $status_sla === 'atrasado' ? 'Leitura registrada com atraso de SLA.' : 'Leitura de ronda registrada no prazo.', ['status_sla' => $status_sla, 'atraso_minutos' => $atraso_minutos, 'ponto' => $ponto['ponto_nome'], 'rota' => $ponto['rota_nome']]);
        break;

    case 'vigilante_historico_hoje':
        if ($metodo !== 'GET') cm_json(false, 'Método não permitido.', null, 405);
        $colaboradores = cm_vigilante_colaboradores_da_sessao($conexao, $tenant_id, (string)$usuario['email']);
        if (count($colaboradores) !== 1) cm_json(false, 'Seu acesso não está associado de forma única a um colaborador ativo. Procure a administração para regularizar o cadastro.', null, 409);
        $colaborador_id = (int)$colaboradores[0]['id'];
        $stmt = $conexao->prepare("SELECT rr.id, rr.registrado_em, rr.status_sla, rr.atraso_minutos, p.nome AS ponto, p.localizacao, r.nome AS rota FROM ronda_registros rr INNER JOIN ronda_pontos p ON p.id = rr.ponto_id AND p.tenant_id = rr.tenant_id INNER JOIN ronda_rotas r ON r.id = rr.rota_id AND r.tenant_id = rr.tenant_id WHERE rr.tenant_id = ? AND rr.colaborador_id = ? AND DATE(rr.registrado_em) = CURDATE() ORDER BY rr.registrado_em DESC LIMIT 20");
        if (!$stmt) cm_json(false, 'Não foi possível carregar o histórico de rondas.', null, 503);
        $stmt->bind_param('ii', $tenant_id, $colaborador_id);
        $stmt->execute();
        $historico = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        cm_json(true, 'Histórico de rondas carregado.', $historico);
        break;

    case 'hidrometros_leiturista':
        $morador_id = (int)($_GET['morador_id'] ?? 0);
        if ($morador_id <= 0) cm_json(false, 'Selecione um morador para consultar o hidrômetro.');
        // Hidrômetros pertencem ao tenant por meio do morador. A tabela legada
        // hidrometros não possui tenant_id em todas as instalações, portanto o
        // isolamento é aplicado pelo join moradores.tenant_id + leituras.tenant_id.
        foreach ([['moradores', 'tenant_id'], ['leituras', 'tenant_id']] as $requisito) {
            if (!cm_coluna_existe($conexao, $requisito[0], $requisito[1])) {
                cm_json(false, 'A estrutura multi-tenant de hidrômetros ainda não foi instalada no servidor.', null, 503);
            }
        }
        $stmt = $conexao->prepare(
            "SELECT h.id, h.numero_hidrometro, h.numero_lacre, h.unidade, h.ativo,
                    m.id AS morador_id, m.nome AS morador_nome,
                    (SELECT l.leitura_atual FROM leituras l
                     WHERE l.tenant_id = ? AND l.hidrometro_id = h.id
                     ORDER BY l.data_leitura DESC LIMIT 1) AS leitura_anterior,
                    (SELECT l.data_leitura FROM leituras l
                     WHERE l.tenant_id = ? AND l.hidrometro_id = h.id
                     ORDER BY l.data_leitura DESC LIMIT 1) AS data_ultima_leitura,
                    (SELECT COUNT(*) FROM leituras l
                     WHERE l.tenant_id = ? AND l.hidrometro_id = h.id
                       AND MONTH(l.data_leitura) = MONTH(CURDATE())
                       AND YEAR(l.data_leitura) = YEAR(CURDATE())) AS leitura_no_mes
             FROM hidrometros h
             INNER JOIN moradores m ON m.id = h.morador_id
             WHERE m.tenant_id = ? AND h.morador_id = ? AND h.ativo = 1
             ORDER BY h.numero_hidrometro ASC"
        );
        if (!$stmt) cm_json(false, 'Não foi possível consultar os hidrômetros.', null, 503);
        $stmt->bind_param('iiiii', $tenant_id, $tenant_id, $tenant_id, $tenant_id, $morador_id);
        $stmt->execute();
        $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        cm_log('hidrometros_consultados', ['tenant_id' => $tenant_id, 'usuario_id' => $usuario_id, 'morador_id' => $morador_id, 'total' => count($itens)]);
        cm_json(true, 'Hidrômetros carregados.', $itens);
        break;

    case 'foto_hidrometro':
        if ($metodo !== 'POST') cm_json(false, 'Método não permitido.', null, 405);
        if (!cm_coluna_existe($conexao, 'leituras_fotos', 'client_uuid')) {
            cm_json(false, 'A estrutura de evidências móveis ainda não foi instalada. Execute migration_leiturista_hidrometros_mobile.sql.', null, 503);
        }
        $hidrometro_id = (int)($_POST['hidrometro_id'] ?? 0);
        $client_uuid = trim((string)($_POST['client_uuid'] ?? ''));
        if ($hidrometro_id <= 0 || !preg_match('/^[a-zA-Z0-9-]{16,64}$/', $client_uuid)) {
            cm_json(false, 'Hidrômetro ou identificador da leitura inválido.');
        }
        $hidrometro = cm_validar_hidrometro_mobile($conexao, $tenant_id, $hidrometro_id);
        if (!$hidrometro || (int)$hidrometro['ativo'] !== 1) cm_json(false, 'Hidrômetro não encontrado ou inativo.', null, 404);
        $stmt = $conexao->prepare('SELECT id FROM leituras_fotos WHERE tenant_id = ? AND client_uuid = ? LIMIT 1');
        $stmt->bind_param('is', $tenant_id, $client_uuid); $stmt->execute();
        $foto_existente = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($foto_existente) cm_json(true, 'Foto já recebida anteriormente.', ['id' => (int)$foto_existente['id'], 'idempotente' => true]);
        if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            cm_json(false, 'Envie uma foto válida do hidrômetro.');
        }
        $arquivo = $_FILES['arquivo'];
        if ((int)$arquivo['size'] > 8 * 1024 * 1024) cm_json(false, 'A foto excede o limite de 8 MB.');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $tipo_mime = $finfo->file($arquivo['tmp_name']);
        $tipos = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($tipos[$tipo_mime])) cm_json(false, 'Formato de foto não permitido. Use JPG, PNG ou WEBP.');
        $nome_servidor = 'leitura_mobile_' . $hidrometro_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $tipos[$tipo_mime];
        $caminho_rel = 'uploads/leituras_fotos/tenant_' . $tenant_id . '/' . $nome_servidor;
        try {
            tenant_file_gravar_upload($conexao, $tenant_id, $arquivo, 'leitura_foto', $caminho_rel, false);
        } catch (Throwable $e) {
            cm_log('foto_hidrometro_erro', ['tenant_id' => $tenant_id, 'usuario_id' => $usuario_id, 'motivo' => 'armazenamento']);
            cm_json(false, 'Não foi possível armazenar a foto.', null, 500);
        }
        $origem = 'camera'; $nome_original = substr((string)$arquivo['name'], 0, 255); $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $conexao->prepare(
            "INSERT INTO leituras_fotos
                (tenant_id, leitura_id, hidrometro_id, client_uuid, nome_arquivo, nome_original, caminho, tipo_mime, tamanho_bytes, origem, lancado_por_tipo, lancado_por_id, lancado_por_nome, ip_origem)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'usuario', ?, ?, ?)"
        );
        if (!$stmt) cm_json(false, 'Não foi possível registrar a foto.', null, 503);
        $stmt->bind_param('iisssssisiss', $tenant_id, $hidrometro_id, $client_uuid, $nome_servidor, $nome_original, $caminho_rel, $tipo_mime, $arquivo['size'], $origem, $usuario_id, $usuario['nome'], $ip);
        if (!$stmt->execute()) {
            $stmt->close();
            try { tenant_file_desativar_caminho($conexao, $tenant_id, $caminho_rel); } catch (Throwable $e) {}
            cm_json(false, 'Não foi possível registrar a foto.', null, 500);
        }
        $foto_id = (int)$conexao->insert_id; $stmt->close();
        cm_log('foto_hidrometro_recebida', ['tenant_id' => $tenant_id, 'usuario_id' => $usuario_id, 'hidrometro_id' => $hidrometro_id, 'foto_id' => $foto_id]);
        cm_json(true, 'Foto recebida.', ['id' => $foto_id]);
        break;

    case 'registrar_leitura_hidrometro':
        if ($metodo !== 'POST') cm_json(false, 'Método não permitido.', null, 405);
        if (!cm_coluna_existe($conexao, 'leituras', 'client_uuid')) {
            cm_json(false, 'A estrutura de leituras móveis ainda não foi instalada. Execute migration_leiturista_hidrometros_mobile.sql.', null, 503);
        }
        $hidrometro_id = (int)($dados['hidrometro_id'] ?? 0);
        $client_uuid = trim((string)($dados['client_uuid'] ?? ''));
        $leitura_atual = isset($dados['leitura_atual']) && is_numeric($dados['leitura_atual']) ? (float)$dados['leitura_atual'] : -1;
        $data_leitura = trim((string)($dados['data_leitura'] ?? date('Y-m-d H:i:s')));
        $observacao = substr(trim((string)($dados['observacao'] ?? '')), 0, 1000);
        $foto_id = isset($dados['foto_id']) ? (int)$dados['foto_id'] : 0;
        if ($hidrometro_id <= 0 || !preg_match('/^[a-zA-Z0-9-]{16,64}$/', $client_uuid) || $leitura_atual < 0 || strtotime($data_leitura) === false) {
            cm_json(false, 'Dados da leitura inválidos.');
        }
        $stmt = $conexao->prepare('SELECT id, consumo, valor_total FROM leituras WHERE tenant_id = ? AND client_uuid = ? LIMIT 1');
        $stmt->bind_param('is', $tenant_id, $client_uuid); $stmt->execute();
        $leitura_existente = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($leitura_existente) cm_json(true, 'Leitura já sincronizada anteriormente.', ['id' => (int)$leitura_existente['id'], 'consumo' => (float)$leitura_existente['consumo'], 'valor_total' => (float)$leitura_existente['valor_total'], 'idempotente' => true]);
        $hidrometro = cm_validar_hidrometro_mobile($conexao, $tenant_id, $hidrometro_id);
        if (!$hidrometro || (int)$hidrometro['ativo'] !== 1) cm_json(false, 'Hidrômetro não encontrado ou inativo.', null, 404);
        $mes = (int)date('m', strtotime($data_leitura)); $ano = (int)date('Y', strtotime($data_leitura));
        $stmt = $conexao->prepare('SELECT id FROM leituras WHERE tenant_id = ? AND hidrometro_id = ? AND MONTH(data_leitura) = ? AND YEAR(data_leitura) = ? LIMIT 1');
        $stmt->bind_param('iiii', $tenant_id, $hidrometro_id, $mes, $ano); $stmt->execute();
        $ja_lancada = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($ja_lancada) cm_json(false, 'Já existe uma leitura registrada para este hidrômetro nesta competência.', null, 409);
        $stmt = $conexao->prepare('SELECT leitura_atual FROM leituras WHERE tenant_id = ? AND hidrometro_id = ? ORDER BY data_leitura DESC LIMIT 1');
        $stmt->bind_param('ii', $tenant_id, $hidrometro_id); $stmt->execute();
        $ultima = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $anterior = $ultima ? (float)$ultima['leitura_atual'] : 0.0;
        if ($leitura_atual < $anterior) cm_json(false, 'A leitura informada não pode ser menor que a última leitura (' . $anterior . ' m³).');
        $consumo = $leitura_atual - $anterior; $valor_mc = 6.16; $valor_min = 61.60; $valor_total = cm_calcular_valor_agua($consumo);
        $stmt = $conexao->prepare(
            "INSERT INTO leituras
                (tenant_id, client_uuid, hidrometro_id, morador_id, unidade, leitura_anterior, leitura_atual, consumo, valor_metro_cubico, valor_minimo, valor_total, data_leitura, observacao, lancado_por_tipo, lancado_por_id, lancado_por_nome)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'usuario', ?, ?)"
        );
        if (!$stmt) cm_json(false, 'Não foi possível preparar o lançamento da leitura.', null, 503);
        $stmt->bind_param('isiisddddddssiss', $tenant_id, $client_uuid, $hidrometro_id, $hidrometro['morador_id'], $hidrometro['unidade'], $anterior, $leitura_atual, $consumo, $valor_mc, $valor_min, $valor_total, $data_leitura, $observacao, $usuario_id, $usuario['nome']);
        if (!$stmt->execute()) { $stmt->close(); cm_json(false, 'Não foi possível registrar a leitura.', null, 500); }
        $leitura_id = (int)$conexao->insert_id; $stmt->close();
        $foto_vinculada = false;
        if ($foto_id > 0) {
            $stmt = $conexao->prepare('UPDATE leituras_fotos SET leitura_id = ? WHERE tenant_id = ? AND id = ? AND hidrometro_id = ? AND leitura_id IS NULL');
            if ($stmt) { $stmt->bind_param('iiii', $leitura_id, $tenant_id, $foto_id, $hidrometro_id); $stmt->execute(); $foto_vinculada = $stmt->affected_rows > 0; $stmt->close(); }
        }
        cm_log('leitura_hidrometro_registrada', ['tenant_id' => $tenant_id, 'usuario_id' => $usuario_id, 'hidrometro_id' => $hidrometro_id, 'leitura_id' => $leitura_id, 'foto_vinculada' => $foto_vinculada]);
        cm_json(true, 'Leitura registrada com sucesso.', ['id' => $leitura_id, 'consumo' => $consumo, 'valor_total' => $valor_total, 'foto_vinculada' => $foto_vinculada]);
        break;

    case 'moradores':
        $busca = trim((string)($_GET['busca'] ?? ''));
        $limite = min(50, max(1, (int)($_GET['limite'] ?? 20)));
        $like = '%' . $busca . '%';
        $stmt = $conexao->prepare(
            "SELECT m.id, m.nome, m.unidade, m.email, u.id AS unidade_id
             FROM moradores m
             LEFT JOIN unidades u ON u.tenant_id = m.tenant_id AND u.nome = m.unidade
             WHERE m.tenant_id = ? AND m.ativo = 1 AND (m.nome LIKE ? OR m.unidade LIKE ? OR m.cpf LIKE ?)
             ORDER BY m.nome ASC LIMIT ?"
        );
        $stmt->bind_param('isssi', $tenant_id, $like, $like, $like, $limite);
        $stmt->execute();
        $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        cm_json(true, 'Moradores carregados.', $itens);
        break;

    case 'assuntos':
        $tem_tenant = cm_coluna_existe($conexao, 'os_assuntos', 'tenant_id');
        $sql = $tem_tenant
            ? 'SELECT id, nome, departamento FROM os_assuntos WHERE ativo = 1 AND tenant_id = ? ORDER BY nome ASC'
            : 'SELECT id, nome, departamento FROM os_assuntos WHERE ativo = 1 ORDER BY nome ASC';
        $stmt = $conexao->prepare($sql);
        if ($tem_tenant) $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        cm_json(true, 'Assuntos carregados.', $itens);
        break;

    case 'chamados':
        $stmt = $conexao->prepare(
            "SELECT id, numero, titulo, status, prioridade, departamento, data_abertura, data_previsao, data_finalizacao
             FROM os_chamados WHERE tenant_id = ? AND criado_por_id = ? ORDER BY data_abertura DESC LIMIT 50"
        );
        $stmt->bind_param('ii', $tenant_id, $usuario_id);
        $stmt->execute();
        $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        cm_json(true, 'Chamados carregados.', $itens);
        break;

    case 'abrir_chamado':
        if ($metodo !== 'POST') cm_json(false, 'Método não permitido.', null, 405);
        $titulo = trim((string)($dados['titulo'] ?? ''));
        $descricao = trim((string)($dados['descricao'] ?? ''));
        $assunto_id = (int)($dados['assunto_id'] ?? 0);
        $departamento = trim((string)($dados['departamento'] ?? ''));
        $prioridade = trim((string)($dados['prioridade'] ?? 'media'));
        if ($titulo === '' || mb_strlen($titulo) > 200 || $descricao === '') cm_json(false, 'Informe título e descrição do chamado.');
        if (!in_array($prioridade, ['baixa', 'media', 'alta', 'urgente'], true)) $prioridade = 'media';
        if ($assunto_id > 0 && $departamento === '') {
            $stmt = $conexao->prepare('SELECT departamento FROM os_assuntos WHERE id = ? AND ativo = 1 LIMIT 1');
            $stmt->bind_param('i', $assunto_id); $stmt->execute();
            $departamento = (string)($stmt->get_result()->fetch_assoc()['departamento'] ?? ''); $stmt->close();
        }
        $numero = cm_numero_os($conexao, $tenant_id);
        $stmt = $conexao->prepare(
            "INSERT INTO os_chamados (tenant_id, numero, titulo, assunto_id, departamento, prioridade, status, descricao, origem_portal, criado_por_id, criado_por_nome)
             VALUES (?, ?, ?, NULLIF(?, 0), ?, ?, 'aberto', ?, 'portal_colaborador', ?, ?)"
        );
        $stmt->bind_param('ississsis', $tenant_id, $numero, $titulo, $assunto_id, $departamento, $prioridade, $descricao, $usuario_id, $usuario['nome']);
        if (!$stmt->execute()) cm_json(false, 'Não foi possível abrir o chamado.', null, 500);
        $os_id = $conexao->insert_id; $stmt->close();
        $mensagem = 'Chamado aberto pelo Portal do Colaborador.';
        $stmt = $conexao->prepare("INSERT INTO os_interacoes (os_id, tipo, mensagem, usuario_id, usuario_nome) VALUES (?, 'comentario', ?, ?, ?)");
        if ($stmt) { $stmt->bind_param('isis', $os_id, $mensagem, $usuario_id, $usuario['nome']); $stmt->execute(); $stmt->close(); }
        cm_log('chamado_aberto', ['usuario_id' => $usuario_id, 'tenant_id' => $tenant_id, 'os_id' => $os_id]);
        cm_json(true, 'Chamado aberto com sucesso.', ['id' => $os_id, 'numero' => $numero]);
        break;

    case 'protocolos':
        // A leitura só é permitida quando as três tabelas já têm isolamento
        // multi-tenant. Sem tenant_id não há como listar dados com segurança.
        $requisitos = [
            ['protocolos', 'tenant_id'],
            ['moradores', 'tenant_id'],
            ['unidades', 'tenant_id'],
        ];
        foreach ($requisitos as $requisito) {
            if (!cm_coluna_existe($conexao, $requisito[0], $requisito[1])) {
                cm_log('protocolos_bloqueados', [
                    'tenant_id' => $tenant_id,
                    'motivo' => 'coluna_tenant_ausente',
                    'tabela' => $requisito[0],
                ]);
                cm_json(
                    false,
                    'A estrutura multi-tenant de protocolos ainda não foi instalada no servidor. Execute migration_multitenant_fase1.sql antes de usar este módulo.',
                    null,
                    503
                );
            }
        }

        $status = trim((string)($_GET['status'] ?? ''));
        $status_valido = in_array($status, ['pendente', 'entregue'], true) ? $status : '';
        $sql = "SELECT p.id, p.codigo_nf, p.descricao_mercadoria, p.pagina,
                       p.status, p.data_hora_recebimento, p.data_hora_entrega,
                       p.nome_recebedor_morador, m.nome AS morador_nome,
                       u.nome AS unidade_nome
                FROM protocolos p
                LEFT JOIN moradores m ON m.id = p.morador_id AND m.tenant_id = p.tenant_id
                LEFT JOIN unidades u ON u.id = p.unidade_id AND u.tenant_id = p.tenant_id
                WHERE p.tenant_id = ?";
        if ($status_valido !== '') {
            $sql .= ' AND p.status = ?';
        }
        $sql .= ' ORDER BY p.data_hora_recebimento DESC LIMIT 100';
        $stmt = $conexao->prepare($sql);
        if (!$stmt) {
            cm_log('protocolos_erro', [
                'tenant_id' => $tenant_id,
                'motivo' => 'consulta_indisponivel',
            ]);
            cm_json(false, 'Não foi possível consultar protocolos no servidor.', null, 503);
        }
        if ($status_valido !== '') {
            $stmt->bind_param('is', $tenant_id, $status_valido);
        } else {
            $stmt->bind_param('i', $tenant_id);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            cm_log('protocolos_erro', [
                'tenant_id' => $tenant_id,
                'motivo' => 'execucao_indisponivel',
            ]);
            cm_json(false, 'Não foi possível carregar protocolos no momento.', null, 503);
        }
        $resultado = $stmt->get_result();
        $itens = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        cm_log('protocolos_listados', [
            'tenant_id' => $tenant_id,
            'status' => $status_valido ?: 'todos',
            'total' => count($itens),
        ]);
        cm_json(true, 'Protocolos carregados.', $itens);
        break;

    case 'buscar_protocolo_qr':
        $codigo = trim((string)($_GET['codigo'] ?? $dados['codigo'] ?? ''));
        if ($codigo === '') cm_json(false, 'Leia ou informe o código da mercadoria.');
        $protocolo = cm_buscar_protocolo_qr($conexao, $tenant_id, $codigo);
        if (!$protocolo) cm_json(false, 'Nenhum protocolo encontrado para este QR Code.', null, 404);
        cm_json(true, 'Protocolo localizado.', $protocolo);
        break;

    case 'receber_protocolo':
        if ($metodo !== 'POST') cm_json(false, 'Método não permitido.', null, 405);
        $morador_id = (int)($dados['morador_id'] ?? 0);
        $descricao = trim((string)($dados['descricao_mercadoria'] ?? ''));
        $codigo_nf = trim((string)($dados['codigo_nf'] ?? ''));
        $pagina = isset($dados['pagina']) && $dados['pagina'] !== '' ? (int)$dados['pagina'] : null;
        $data_hora = trim((string)($dados['data_hora_recebimento'] ?? date('Y-m-d H:i:s')));
        $observacao = trim((string)($dados['observacao'] ?? ''));
        if ($morador_id <= 0 || $descricao === '' || $data_hora === '') cm_json(false, 'Morador, descrição e data/hora são obrigatórios.');
        $stmt = $conexao->prepare(
            "SELECT m.id, m.unidade, u.id AS unidade_id FROM moradores m
             LEFT JOIN unidades u ON u.tenant_id = m.tenant_id AND u.nome = m.unidade
             WHERE m.tenant_id = ? AND m.id = ? AND m.ativo = 1 LIMIT 1"
        );
        $stmt->bind_param('ii', $tenant_id, $morador_id); $stmt->execute();
        $morador = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$morador || empty($morador['unidade_id'])) cm_json(false, 'Morador ou unidade não encontrados neste condomínio.');
        if ($pagina !== null) {
            $stmt = $conexao->prepare("INSERT INTO protocolos (tenant_id, unidade_id, morador_id, descricao_mercadoria, codigo_nf, pagina, data_hora_recebimento, recebedor_portaria, observacao, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente')");
            $stmt->bind_param('iiississs', $tenant_id, $morador['unidade_id'], $morador_id, $descricao, $codigo_nf, $pagina, $data_hora, $usuario['nome'], $observacao);
        } else {
            $stmt = $conexao->prepare("INSERT INTO protocolos (tenant_id, unidade_id, morador_id, descricao_mercadoria, codigo_nf, data_hora_recebimento, recebedor_portaria, observacao, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendente')");
            $stmt->bind_param('iiisssss', $tenant_id, $morador['unidade_id'], $morador_id, $descricao, $codigo_nf, $data_hora, $usuario['nome'], $observacao);
        }
        if (!$stmt->execute()) cm_json(false, 'Não foi possível registrar o protocolo.', null, 500);
        $protocolo_id = $conexao->insert_id; $stmt->close();
        $notificacao = protocolo_criar_notificacao_morador($conexao, $tenant_id, $morador_id, $protocolo_id, 'mercadoria_chegou', $descricao);
        cm_log('protocolo_recebido', ['usuario_id' => $usuario_id, 'tenant_id' => $tenant_id, 'protocolo_id' => $protocolo_id]);
        cm_json(true, 'Mercadoria recebida e morador notificado.', ['id' => $protocolo_id, 'notificacao' => $notificacao]);
        break;

    case 'entregar_protocolo':
        if ($metodo !== 'POST') cm_json(false, 'Método não permitido.', null, 405);
        $protocolo_id = (int)($dados['protocolo_id'] ?? 0);
        $nome_recebedor = trim((string)($dados['nome_recebedor'] ?? ''));
        $cpf_confirmacao = cm_cpf($dados['cpf_confirmacao'] ?? '');
        if ($protocolo_id <= 0 || $nome_recebedor === '' || strlen($cpf_confirmacao) !== 11) {
            cm_json(false, 'Informe o recebedor e confirme o CPF completo do morador.');
        }
        $stmt = $conexao->prepare(
            "SELECT p.id, p.status, p.morador_id, p.descricao_mercadoria, m.nome AS morador_nome, m.cpf AS morador_cpf
             FROM protocolos p INNER JOIN moradores m ON m.id = p.morador_id AND m.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $tenant_id, $protocolo_id); $stmt->execute();
        $protocolo = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$protocolo) cm_json(false, 'Protocolo não encontrado neste condomínio.', null, 404);
        if ($protocolo['status'] === 'entregue') cm_json(false, 'Este protocolo já foi entregue.');
        if (!hash_equals(cm_cpf($protocolo['morador_cpf']), $cpf_confirmacao)) {
            cm_log('entrega_negada', ['usuario_id' => $usuario_id, 'tenant_id' => $tenant_id, 'protocolo_id' => $protocolo_id, 'motivo' => 'cpf_invalido']);
            cm_json(false, 'CPF não confere com o morador responsável.', null, 403);
        }
        $agora = date('Y-m-d H:i:s');
        $stmt = $conexao->prepare("UPDATE protocolos SET status = 'entregue', nome_recebedor_morador = ?, data_hora_entrega = ? WHERE tenant_id = ? AND id = ? AND status = 'pendente'");
        $stmt->bind_param('ssii', $nome_recebedor, $agora, $tenant_id, $protocolo_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) cm_json(false, 'Não foi possível confirmar a entrega.', null, 409);
        $stmt->close();
        $notificacao = protocolo_criar_notificacao_morador($conexao, $tenant_id, (int)$protocolo['morador_id'], $protocolo_id, 'mercadoria_entregue', $protocolo['descricao_mercadoria'], $nome_recebedor);
        cm_log('protocolo_entregue', ['usuario_id' => $usuario_id, 'tenant_id' => $tenant_id, 'protocolo_id' => $protocolo_id]);
        cm_json(true, 'Entrega confirmada e morador notificado.', ['notificacao' => $notificacao]);
        break;

    default:
        cm_json(false, 'Ação do Portal do Colaborador não encontrada.', null, 404);
}
?>
