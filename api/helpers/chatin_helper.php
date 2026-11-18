<?php
require_once __DIR__ . '/../db/DatabaseDialect.php';
/**
 * ERP CONDOMÍNIOS — CHAT-IN (núcleo)
 *
 * Comunicação interna entre usuários do painel (USUARIO) e moradores (MORADOR),
 * mais avisos automáticos do condomínio (remetente SISTEMA: "Portaria", "Administração").
 *
 * Usado por:
 *   - api/api_chatin.php (painel, portal e app)
 *   - integrações: controle de acesso (visitante chegou), documentos, assembleia, eventos
 *
 * Privacidade: o chat expõe apenas nome + unidade (morador) ou nome + função (usuário).
 * Nunca CPF, e-mail, telefone ou endereço. Tudo filtrado por tenant_id.
 * Tempo real: polling (~5 s) pelos clientes + push FCM para moradores no app.
 */

require_once __DIR__ . '/protocol_notification_helper.php';

if (!defined('CHATIN_MAX_CARACTERES')) {
    define('CHATIN_MAX_CARACTERES', 2000);
    define('CHATIN_LIMITE_MSG_POR_MINUTO', 20);
    define('CHATIN_ONLINE_SEGUNDOS', 60);
    define('CHATIN_PRESENCA_THROTTLE_SEGUNDOS', 15);
    define('CHATIN_TIPOS_PESSOA', ['USUARIO', 'MORADOR']);
}

class ChatInErro extends RuntimeException {
    public $status;
    public $codigo;
    public $retryAfter;
    public function __construct($mensagem, $status = 400, $codigo = 'CHATIN_ERRO', $retryAfter = 0) {
        parent::__construct($mensagem);
        $this->status = (int)$status;
        $this->codigo = (string)$codigo;
        $this->retryAfter = max(0, (int)$retryAfter);
    }
}

function chatin_q(mysqli $c, $sql, $tipos = '', array $params = []) {
    $stmt = $c->prepare($sql);
    if (!$stmt) throw new RuntimeException('Chat-In: falha ao preparar consulta: ' . $c->error, (int)$c->errno);
    if ($tipos !== '') {
        $refs = [$tipos];
        foreach ($params as $i => $v) $refs[] = &$params[$i];
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    if (!$stmt->execute()) {
        $erro = $stmt->error; $errno = (int)$stmt->errno; $stmt->close();
        throw new RuntimeException('Chat-In: falha ao executar consulta: ' . $erro, $errno);
    }
    return $stmt;
}

function chatin_linhas(mysqli $c, $sql, $tipos = '', array $params = []) {
    $stmt = chatin_q($c, $sql, $tipos, $params);
    $res = $stmt->get_result();
    $linhas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $linhas;
}

function chatin_linha(mysqli $c, $sql, $tipos = '', array $params = []) {
    $linhas = chatin_linhas($c, $sql, $tipos, $params);
    return $linhas ? $linhas[0] : null;
}

function chatin_tabela_existe(mysqli $c, $tabela) {
    static $cache = [];
    if (array_key_exists($tabela, $cache)) return $cache[$tabela];
    try {
        return $cache[$tabela] = \ERP\Database\DatabaseDialect::tableExists($c, (string)$tabela);
    } catch (Throwable $e) {
        return $cache[$tabela] = false;
    }
}

function chatin_colunas_tabela(mysqli $c, $tabela) {
    static $cache = [];
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$tabela)) return [];
    $k = spl_object_id($c) . ':' . $tabela;
    if (array_key_exists($k, $cache)) return $cache[$k];
    if (!chatin_tabela_existe($c, $tabela)) return $cache[$k] = [];
    $schemaExpr = \ERP\Database\DatabaseDialect::isPostgres($c) ? 'current_schema()' : 'DATABASE()';
    $stmt = $c->prepare("SELECT column_name AS field_name, data_type AS type_name FROM information_schema.columns WHERE table_schema = $schemaExpr AND table_name = ?");
    if (!$stmt || !$stmt->bind_param('s', $tabela) || !$stmt->execute()) {
        if ($stmt) $stmt->close();
        return $cache[$k] = [];
    }
    $r = $stmt->get_result();
    if (!$r) { $stmt->close(); return $cache[$k] = []; }
    $colunas = [];
    while ($linha = $r->fetch_assoc()) $colunas[$linha['field_name']] = ['Type' => (string)$linha['type_name']];
    $r->free();
    $stmt->close();
    return $cache[$k] = $colunas;
}

function chatin_coluna_existe(mysqli $c, $tabela, $coluna) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$coluna)) return false;
    return isset(chatin_colunas_tabela($c, $tabela)[$coluna]);
}

function chatin_instalado(mysqli $c) {
    return chatin_tabela_existe($c, 'chatin_conversas') && chatin_tabela_existe($c, 'chatin_mensagens');
}

function chatin_v2_instalado(mysqli $c) {
    // A procedure cria a tabela de configuração somente depois de validar/aplicar
    // preferências, presença e ENUM; usá-la como marcador evita SHOW COLUMNS em cada polling.
    return chatin_instalado($c) && chatin_tabela_existe($c, 'chatin_configuracoes');
}

function chatin_config_tenant(mysqli $c, $tenantId, $forcarReleitura = false) {
    static $cache = [];
    $cacheKey = spl_object_id($c) . ':' . (int)$tenantId;
    if (!$forcarReleitura && array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    $padrao = [
        'ativo' => true, 'usuario_usuario' => true, 'usuario_morador' => true,
        'morador_inicia_com_equipe' => true, 'morador_morador' => true,
        'recurso_sons' => true, 'recurso_cutucar' => true, 'recurso_digitando' => true,
        'recurso_emojis' => true, 'recurso_push_app' => true,
        'grupos_visiveis_moradores_json' => '[]', 'filtro_moradores_json' => '[]',
    ];
    if (!chatin_tabela_existe($c, 'chatin_configuracoes')) return $cache[$cacheKey] = $padrao;
    foreach (['ativo','usuario_usuario','usuario_morador','morador_inicia_com_equipe','morador_morador','recurso_sons',
        'recurso_cutucar','recurso_digitando','recurso_emojis','recurso_push_app','grupos_visiveis_moradores_json','filtro_moradores_json'] as $coluna) {
        if (!chatin_coluna_existe($c, 'chatin_configuracoes', $coluna)) {
            unset($cache[$cacheKey]);
            throw new ChatInErro('A configuração Chat-In está incompleta; aplique a migration aprovada.', 503, 'MIGRATION_V2_PENDENTE');
        }
    }
    $linha = chatin_linha($c, 'SELECT ativo, usuario_usuario, usuario_morador, morador_inicia_com_equipe, morador_morador, recurso_sons, recurso_cutucar, recurso_digitando, recurso_emojis, recurso_push_app, grupos_visiveis_moradores_json, filtro_moradores_json FROM chatin_configuracoes WHERE tenant_id = ? LIMIT 1', 'i', [(int)$tenantId]);
    if (!$linha) return $cache[$cacheKey] = $padrao;
    foreach ($padrao as $chave => $valor) {
        if (strpos($chave, 'recurso_') === 0 || in_array($chave, ['ativo','usuario_usuario','usuario_morador','morador_inicia_com_equipe','morador_morador'], true)) $padrao[$chave] = (int)$linha[$chave] === 1;
        else $padrao[$chave] = (string)($linha[$chave] ?? '[]');
    }
    return $cache[$cacheKey] = $padrao;
}

function chatin_config_publica(mysqli $c, $tenantId) {
    $config = chatin_config_tenant($c, $tenantId);
    return [
        'ativo' => (bool)$config['ativo'],
        'recurso_sons' => (bool)$config['recurso_sons'],
        'recurso_cutucar' => (bool)$config['recurso_cutucar'],
        'recurso_digitando' => (bool)$config['recurso_digitando'],
        'recurso_emojis' => (bool)$config['recurso_emojis'],
        'recurso_push_app' => (bool)$config['recurso_push_app'],
    ];
}

function chatin_texto($valor, $limite) {
    $valor = trim(strip_tags((string)$valor));
    $valor = preg_replace("/\r\n?/", "\n", $valor);
    $valor = preg_replace("/\n{4,}/", "\n\n\n", $valor);
    return function_exists('mb_substr') ? mb_substr($valor, 0, $limite, 'UTF-8') : substr($valor, 0, $limite);
}

function chatin_booleano_estrito($valor) {
    if (is_bool($valor)) return $valor ? 1 : 0;
    if (is_int($valor) && ($valor === 0 || $valor === 1)) return $valor;
    if (is_string($valor)) {
        $normalizado = strtolower(trim($valor));
        if ($normalizado === '0' || $normalizado === 'false') return 0;
        if ($normalizado === '1' || $normalizado === 'true') return 1;
    }
    throw new ChatInErro('A preferência deve receber um valor booleano válido.', 422, 'PREFERENCIA_INVALIDA');
}

function chatin_contem_emoji($valor) {
    $resultado = preg_match('/[\p{Extended_Pictographic}\p{Regional_Indicator}]|[#*0-9]\x{FE0F}?\x{20E3}/u', (string)$valor);
    if ($resultado === false) throw new ChatInErro('A mensagem contém caracteres inválidos.', 422, 'MENSAGEM_INVALIDA');
    return $resultado === 1;
}

function chatin_tamanho($valor) {
    return function_exists('mb_strlen') ? mb_strlen((string)$valor, 'UTF-8') : strlen((string)$valor);
}

function chatin_sigla($tipo) {
    return $tipo === 'USUARIO' ? 'U' : ($tipo === 'MORADOR' ? 'M' : 'S');
}

/** Chave estável de conversa direta entre duas pessoas (ordem independente). */
function chatin_chave_direta(array $a, array $b) {
    $x = chatin_sigla($a['tipo']) . (int)$a['id'];
    $y = chatin_sigla($b['tipo']) . (int)$b['id'];
    return strcmp($x, $y) <= 0 ? "$x|$y" : "$y|$x";
}

/** Identidade pública (sem dados sensíveis) de uma pessoa ativa do tenant, ou null. */
function chatin_pessoa(mysqli $c, $tenantId, $tipo, $id) {
    $tenantId = (int)$tenantId; $id = (int)$id;
    if ($id <= 0) return null;
    if ($tipo === 'MORADOR') {
        $m = chatin_linha($c, 'SELECT id, nome, unidade FROM moradores WHERE tenant_id = ? AND id = ? AND ativo = 1 LIMIT 1', 'ii', [$tenantId, $id]);
        if (!$m) return null;
        $unidade = trim((string)$m['unidade']);
        return ['tipo' => 'MORADOR', 'id' => (int)$m['id'], 'nome' => (string)$m['nome'], 'unidade' => $unidade,
            'subtitulo' => $unidade !== '' ? 'Unidade ' . $unidade : 'Morador'];
    }
    if ($tipo === 'USUARIO') {
        if (chatin_tabela_existe($c, 'usuario_tenant')) {
            $filtroSuper = chatin_coluna_existe($c, 'usuarios', 'permissao') ? " AND LOWER(COALESCE(u.permissao, '')) <> 'super_admin'" : '';
            $u = chatin_linha($c, 'SELECT u.id, u.nome, u.funcao FROM usuarios u INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = ? AND COALESCE(ut.ativo, 1) = 1 WHERE u.id = ? AND u.ativo = 1' . $filtroSuper . ' LIMIT 1', 'ii', [$tenantId, $id]);
        } elseif (chatin_coluna_existe($c, 'usuarios', 'tenant_id')) {
            $filtroSuper = chatin_coluna_existe($c, 'usuarios', 'permissao') ? " AND LOWER(COALESCE(permissao, '')) <> 'super_admin'" : '';
            $u = chatin_linha($c, 'SELECT id, nome, funcao FROM usuarios WHERE tenant_id = ? AND id = ? AND ativo = 1' . $filtroSuper . ' LIMIT 1', 'ii', [$tenantId, $id]);
        } else {
            // Sem vínculo tenant-aware, não permitir busca global em uma instalação incompleta.
            $u = null;
        }
        if (!$u) return null;
        $funcao = trim((string)($u['funcao'] ?? ''));
        return ['tipo' => 'USUARIO', 'id' => (int)$u['id'], 'nome' => (string)$u['nome'], 'unidade' => null,
            'subtitulo' => 'Administração' . ($funcao !== '' ? ' · ' . $funcao : '')];
    }
    return null;
}

/** Bloqueia a identidade ativa no tenant durante uma escrita Chat-In. */
function chatin_pessoa_bloquear(mysqli $c, $tenantId, $tipo, $id) {
    $tenantId = (int)$tenantId; $id = (int)$id;
    if ($tenantId <= 0 || $id <= 0) return false;
    if ($tipo === 'MORADOR') {
        return chatin_linha($c, 'SELECT id FROM moradores WHERE tenant_id = ? AND id = ? AND ativo = 1 LIMIT 1 FOR UPDATE',
            'ii', [$tenantId, $id]) !== null;
    }
    if ($tipo !== 'USUARIO') return false;
    $temPermissao = chatin_coluna_existe($c, 'usuarios', 'permissao');
    $filtroSuper = $temPermissao ? " AND LOWER(COALESCE(u.permissao, '')) <> 'super_admin'" : '';
    if (chatin_tabela_existe($c, 'usuario_tenant')) {
        return chatin_linha($c, 'SELECT u.id FROM usuarios u
            INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = ? AND COALESCE(ut.ativo, 1) = 1
            WHERE u.id = ? AND u.ativo = 1' . $filtroSuper . ' LIMIT 1 FOR UPDATE', 'ii', [$tenantId, $id]) !== null;
    }
    if (!chatin_coluna_existe($c, 'usuarios', 'tenant_id')) return false;
    $filtroSuperDireto = $temPermissao ? " AND LOWER(COALESCE(permissao, '')) <> 'super_admin'" : '';
    return chatin_linha($c, 'SELECT id FROM usuarios WHERE tenant_id = ? AND id = ? AND ativo = 1' . $filtroSuperDireto . ' LIMIT 1 FOR UPDATE',
        'ii', [$tenantId, $id]) !== null;
}

function chatin_preferencias(mysqli $c, $tenantId, $tipo, $id, $bloqueante = false) {
    $v2 = chatin_coluna_existe($c, 'chatin_preferencias', 'som_ativo')
        && chatin_coluna_existe($c, 'chatin_preferencias', 'som_tipo')
        && chatin_coluna_existe($c, 'chatin_preferencias', 'som_volume')
        && chatin_coluna_existe($c, 'chatin_preferencias', 'notificacao_navegador')
        && chatin_coluna_existe($c, 'chatin_preferencias', 'permitir_cutucar');
    $campos = $v2
        ? 'visivel_busca, mostrar_online, notificar_push, som_ativo, som_tipo, som_volume, notificacao_navegador, permitir_cutucar'
        : 'visivel_busca, mostrar_online, notificar_push';
    $p = chatin_tabela_existe($c, 'chatin_preferencias')
        ? chatin_linha($c, 'SELECT ' . $campos . ' FROM chatin_preferencias WHERE tenant_id = ? AND participante_tipo = ? AND participante_id = ? LIMIT 1' . ($bloqueante ? ' FOR UPDATE' : ''), 'isi', [(int)$tenantId, $tipo, (int)$id])
        : null;
    return [
        'visivel_busca' => $p ? (int)$p['visivel_busca'] === 1 : true,
        'mostrar_online' => $p ? (int)$p['mostrar_online'] === 1 : true,
        'notificar_push' => $p ? (int)$p['notificar_push'] === 1 : true,
        'som_ativo' => $v2 && $p ? (int)$p['som_ativo'] === 1 : true,
        'som_tipo' => $v2 && $p && in_array($p['som_tipo'], ['classico','campainha','suave'], true) ? $p['som_tipo'] : 'classico',
        'som_volume' => $v2 && $p ? max(0, min(100, (int)$p['som_volume'])) : 70,
        'notificacao_navegador' => $v2 && $p ? (int)$p['notificacao_navegador'] === 1 : false,
        'permitir_cutucar' => $v2 && $p ? (int)$p['permitir_cutucar'] === 1 : true,
    ];
}

function chatin_permitir_cutucar_bloquear(mysqli $c, $tenantId, $tipo, $id) {
    if (!chatin_tabela_existe($c, 'chatin_preferencias') || !chatin_coluna_existe($c, 'chatin_preferencias', 'permitir_cutucar')) return false;
    $linha = chatin_linha($c, 'SELECT permitir_cutucar FROM chatin_preferencias
        WHERE tenant_id = ? AND participante_tipo = ? AND participante_id = ? LIMIT 1 FOR UPDATE',
        'isi', [(int)$tenantId, $tipo, (int)$id]);
    return $linha ? (int)$linha['permitir_cutucar'] === 1 : true;
}

function chatin_salvar_preferencias(mysqli $c, $tenantId, array $eu, array $dados) {
    $permitidas = ['visivel_busca','mostrar_online','notificar_push','som_ativo','som_tipo','som_volume','notificacao_navegador','permitir_cutucar'];
    foreach (array_keys($dados) as $chave) {
        if (!in_array($chave, $permitidas, true)) throw new ChatInErro('A preferência contém uma opção não reconhecida.', 422, 'PREFERENCIA_INVALIDA');
    }
    $v2 = chatin_coluna_existe($c, 'chatin_preferencias', 'som_ativo')
        && chatin_coluna_existe($c, 'chatin_preferencias', 'som_tipo')
        && chatin_coluna_existe($c, 'chatin_preferencias', 'som_volume')
        && chatin_coluna_existe($c, 'chatin_preferencias', 'notificacao_navegador')
        && chatin_coluna_existe($c, 'chatin_preferencias', 'permitir_cutucar');
    if (!$v2 && array_intersect(array_keys($dados), ['som_ativo','som_tipo','som_volume','notificacao_navegador','permitir_cutucar'])) {
        throw new ChatInErro('As preferências avançadas ainda não foram instaladas.', 503, 'MIGRATION_V2_PENDENTE');
    }
    $c->begin_transaction();
    try {
        $config = chatin_config_tenant($c, $tenantId, true, true);
        $recursos = [
            'notificar_push' => 'recurso_push_app',
            'som_ativo' => 'recurso_sons', 'som_tipo' => 'recurso_sons', 'som_volume' => 'recurso_sons',
            'permitir_cutucar' => 'recurso_cutucar',
        ];
        foreach ($recursos as $campo => $recurso) {
            if (array_key_exists($campo, $dados) && empty($config[$recurso])) {
                throw new ChatInErro('Este recurso está desativado pelo condomínio.', 403, 'CHATIN_RECURSO_DESATIVADO');
            }
        }
        foreach (['visivel_busca','mostrar_online','notificar_push','som_ativo','notificacao_navegador','permitir_cutucar'] as $campo) {
            if (array_key_exists($campo, $dados)) $dados[$campo] = chatin_booleano_estrito($dados[$campo]);
        }
        $atual = chatin_preferencias($c, $tenantId, $eu['tipo'], $eu['id'], true);
        $vis = array_key_exists('visivel_busca', $dados) ? $dados['visivel_busca'] : ($atual['visivel_busca'] ? 1 : 0);
        $onl = array_key_exists('mostrar_online', $dados) ? $dados['mostrar_online'] : ($atual['mostrar_online'] ? 1 : 0);
        $psh = array_key_exists('notificar_push', $dados) ? $dados['notificar_push'] : ($atual['notificar_push'] ? 1 : 0);
        if ($v2) {
            $som = array_key_exists('som_ativo', $dados) ? $dados['som_ativo'] : ($atual['som_ativo'] ? 1 : 0);
            $tipoSom = array_key_exists('som_tipo', $dados) ? (string)$dados['som_tipo'] : $atual['som_tipo'];
            if (!in_array($tipoSom, ['classico','campainha','suave'], true)) throw new ChatInErro('Tipo de som inválido.', 422, 'PREFERENCIA_INVALIDA');
            if (array_key_exists('som_volume', $dados)) {
                if (!(is_int($dados['som_volume']) || (is_string($dados['som_volume']) && preg_match('/^[0-9]{1,3}$/', $dados['som_volume'])))) {
                    throw new ChatInErro('O volume deve ser um número inteiro de 0 a 100.', 422, 'PREFERENCIA_INVALIDA');
                }
                $volume = (int)$dados['som_volume'];
                if ($volume < 0 || $volume > 100) throw new ChatInErro('O volume deve ser um número inteiro de 0 a 100.', 422, 'PREFERENCIA_INVALIDA');
            } else $volume = (int)$atual['som_volume'];
            $nav = array_key_exists('notificacao_navegador', $dados) ? $dados['notificacao_navegador'] : ($atual['notificacao_navegador'] ? 1 : 0);
            $cutucar = array_key_exists('permitir_cutucar', $dados) ? $dados['permitir_cutucar'] : ($atual['permitir_cutucar'] ? 1 : 0);
            chatin_q($c, 'INSERT INTO chatin_preferencias (tenant_id, participante_tipo, participante_id, visivel_busca, mostrar_online, notificar_push, som_ativo, som_tipo, som_volume, notificacao_navegador, permitir_cutucar)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE visivel_busca = VALUES(visivel_busca), mostrar_online = VALUES(mostrar_online), notificar_push = VALUES(notificar_push), som_ativo = VALUES(som_ativo), som_tipo = VALUES(som_tipo), som_volume = VALUES(som_volume), notificacao_navegador = VALUES(notificacao_navegador), permitir_cutucar = VALUES(permitir_cutucar)',
                'isiiiiisiii', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $vis, $onl, $psh, $som, $tipoSom, $volume, $nav, $cutucar])->close();
        } else {
            chatin_q($c, 'INSERT INTO chatin_preferencias (tenant_id, participante_tipo, participante_id, visivel_busca, mostrar_online, notificar_push)
                VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE visivel_busca = VALUES(visivel_busca), mostrar_online = VALUES(mostrar_online), notificar_push = VALUES(notificar_push)',
                'isiiii', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $vis, $onl, $psh])->close();
        }
        $resultado = chatin_preferencias($c, $tenantId, $eu['tipo'], $eu['id']);
        $c->commit();
        return $resultado;
    } catch (Throwable $e) {
        try { $c->rollback(); } catch (Throwable $ignorar) { /* a conexão pode já ter encerrado a transação */ }
        throw $e;
    }
}

/** Atualiza presença (no máximo a cada CHATIN_PRESENCA_THROTTLE_SEGUNDOS, salvo mudança de conversa aberta). */
function chatin_tocar_presenca(mysqli $c, $tenantId, array $eu, $plataforma, $conversaAberta = null) {
    $plataforma = in_array($plataforma, ['painel', 'portal', 'app'], true) ? $plataforma : 'web';
    $conversaAberta = $conversaAberta ? (int)$conversaAberta : null;
    chatin_q($c, 'INSERT INTO chatin_presenca (tenant_id, participante_tipo, participante_id, ultimo_ping, plataforma, conversa_aberta_id)
        VALUES (?, ?, ?, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE
          plataforma = IF(ultimo_ping < NOW() - INTERVAL ' . CHATIN_PRESENCA_THROTTLE_SEGUNDOS . ' SECOND OR NOT (conversa_aberta_id <=> VALUES(conversa_aberta_id)), VALUES(plataforma), plataforma),
          ultimo_ping = IF(ultimo_ping < NOW() - INTERVAL ' . CHATIN_PRESENCA_THROTTLE_SEGUNDOS . ' SECOND OR NOT (conversa_aberta_id <=> VALUES(conversa_aberta_id)), NOW(), ultimo_ping),
          conversa_aberta_id = VALUES(conversa_aberta_id)',
        'isisi', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $plataforma, $conversaAberta])->close();
}

/**
 * Online de várias pessoas. $pessoas = [['tipo'=>..,'id'=>..], ...]. Retorna mapa "M12" => bool.
 * Respeita a preferência "mostrar_online" da pessoa observada.
 */
function chatin_online_mapa(mysqli $c, $tenantId, array $pessoas) {
    $mapa = [];
    if (!$pessoas) return $mapa;
    $por = ['USUARIO' => [], 'MORADOR' => []];
    foreach ($pessoas as $p) if (isset($por[$p['tipo']])) $por[$p['tipo']][] = (int)$p['id'];
    foreach ($por as $tipo => $ids) {
        if (!$ids) continue;
        $ids = array_values(array_unique($ids));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $linhas = chatin_linhas($c,
            "SELECT p.participante_id AS id, (p.ultimo_ping >= NOW() - INTERVAL " . CHATIN_ONLINE_SEGUNDOS . " SECOND) AS online,
                    COALESCE(pref.mostrar_online, 1) AS mostrar
             FROM chatin_presenca p
             LEFT JOIN chatin_preferencias pref ON pref.tenant_id = p.tenant_id AND pref.participante_tipo = p.participante_tipo AND pref.participante_id = p.participante_id
             WHERE p.tenant_id = ? AND p.participante_tipo = ? AND p.participante_id IN ($ph)",
            'is' . str_repeat('i', count($ids)), array_merge([(int)$tenantId, $tipo], $ids));
        foreach ($linhas as $l) $mapa[chatin_sigla($tipo) . (int)$l['id']] = ((int)$l['online'] === 1 && (int)$l['mostrar'] === 1);
    }
    return $mapa;
}

function chatin_bloqueado(mysqli $c, $tenantId, array $a, array $b, $bloqueante = false) {
    if (!in_array($a['tipo'], CHATIN_TIPOS_PESSOA, true) || !in_array($b['tipo'], CHATIN_TIPOS_PESSOA, true)) return false;
    if ($bloqueante) {
        $primeiro = chatin_linha($c, 'SELECT 1 AS x FROM chatin_bloqueios WHERE tenant_id = ? AND bloqueador_tipo = ? AND bloqueador_id = ? AND bloqueado_tipo = ? AND bloqueado_id = ? LIMIT 1 FOR UPDATE',
            'isisi', [(int)$tenantId, $a['tipo'], (int)$a['id'], $b['tipo'], (int)$b['id']]);
        $segundo = chatin_linha($c, 'SELECT 1 AS x FROM chatin_bloqueios WHERE tenant_id = ? AND bloqueador_tipo = ? AND bloqueador_id = ? AND bloqueado_tipo = ? AND bloqueado_id = ? LIMIT 1 FOR UPDATE',
            'isisi', [(int)$tenantId, $b['tipo'], (int)$b['id'], $a['tipo'], (int)$a['id']]);
        return $primeiro !== null || $segundo !== null;
    }
    $r = chatin_linha($c, 'SELECT 1 AS x FROM chatin_bloqueios WHERE tenant_id = ?
        AND ((bloqueador_tipo = ? AND bloqueador_id = ? AND bloqueado_tipo = ? AND bloqueado_id = ?)
          OR (bloqueador_tipo = ? AND bloqueador_id = ? AND bloqueado_tipo = ? AND bloqueado_id = ?)) LIMIT 1',
        'isisisisi', [(int)$tenantId, $a['tipo'], (int)$a['id'], $b['tipo'], (int)$b['id'], $b['tipo'], (int)$b['id'], $a['tipo'], (int)$a['id']]);
    return $r !== null;
}

function chatin_eu_bloqueei(mysqli $c, $tenantId, array $eu, array $outro) {
    $r = chatin_linha($c, 'SELECT 1 AS x FROM chatin_bloqueios WHERE tenant_id = ? AND bloqueador_tipo = ? AND bloqueador_id = ? AND bloqueado_tipo = ? AND bloqueado_id = ? LIMIT 1',
        'isisi', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $outro['tipo'], (int)$outro['id']]);
    return $r !== null;
}

function chatin_definir_bloqueio(mysqli $c, $tenantId, array $eu, array $outro, $bloquear) {
    if (!chatin_pessoa($c, $tenantId, $outro['tipo'], $outro['id'])) throw new ChatInErro('Contato não encontrado.', 404, 'CONTATO_NAO_ENCONTRADO');
    if ($eu['tipo'] === $outro['tipo'] && (int)$eu['id'] === (int)$outro['id']) throw new ChatInErro('Não é possível bloquear a si mesmo.', 422, 'BLOQUEIO_INVALIDO');
    if ($bloquear) {
        chatin_q($c, 'INSERT IGNORE INTO chatin_bloqueios (tenant_id, bloqueador_tipo, bloqueador_id, bloqueado_tipo, bloqueado_id) VALUES (?, ?, ?, ?, ?)',
            'isisi', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $outro['tipo'], (int)$outro['id']])->close();
    } else {
        chatin_q($c, 'DELETE FROM chatin_bloqueios WHERE tenant_id = ? AND bloqueador_tipo = ? AND bloqueador_id = ? AND bloqueado_tipo = ? AND bloqueado_id = ?',
            'isisi', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $outro['tipo'], (int)$outro['id']])->close();
    }
}

function chatin_garantir_participante(mysqli $c, $tenantId, $conversaId, $tipo, $id) {
    chatin_q($c, 'INSERT IGNORE INTO chatin_participantes (tenant_id, conversa_id, participante_tipo, participante_id) VALUES (?, ?, ?, ?)',
        'iisi', [(int)$tenantId, (int)$conversaId, $tipo, (int)$id])->close();
}

function chatin_obter_ou_criar_conversa(mysqli $c, $tenantId, $tipoConversa, $chave, array $participantes) {
    chatin_q($c, 'INSERT IGNORE INTO chatin_conversas (tenant_id, tipo, chave_unica) VALUES (?, ?, ?)', 'iss', [(int)$tenantId, $tipoConversa, $chave])->close();
    $conv = chatin_linha($c, 'SELECT id FROM chatin_conversas WHERE tenant_id = ? AND chave_unica = ? LIMIT 1', 'is', [(int)$tenantId, $chave]);
    if (!$conv) throw new RuntimeException('Chat-In: conversa não pôde ser criada.');
    foreach ($participantes as $p) chatin_garantir_participante($c, $tenantId, (int)$conv['id'], $p['tipo'], $p['id']);
    return (int)$conv['id'];
}

/** Conversa direta entre $eu e $outro (cria se necessário). Valida existência e bloqueio. */
function chatin_abrir_direta(mysqli $c, $tenantId, array $eu, array $outro) {
    if ($eu['tipo'] === $outro['tipo'] && (int)$eu['id'] === (int)$outro['id']) throw new ChatInErro('Escolha outra pessoa para conversar.', 422, 'CONVERSA_INVALIDA');
    $pessoa = chatin_pessoa($c, $tenantId, $outro['tipo'], $outro['id']);
    if (!$pessoa) throw new ChatInErro('Contato não encontrado neste condomínio.', 404, 'CONTATO_NAO_ENCONTRADO');
    $chave = chatin_chave_direta($eu, $outro);
    $existente = chatin_linha($c, 'SELECT id FROM chatin_conversas WHERE tenant_id = ? AND chave_unica = ? LIMIT 1', 'is', [(int)$tenantId, $chave]);
    if (!$existente) {
        if ($outro['tipo'] === 'USUARIO' && $eu['tipo'] === 'MORADOR' && !chatin_usuario_visivel_para_moradores($c, $tenantId, $outro['id'])) throw new ChatInErro('Este contato não está disponível para moradores.', 404, 'CONTATO_NAO_ENCONTRADO');
        chatin_exigir_conversa_permitida($c, $tenantId, $eu, $outro, 0, 'abrir');
        // Morador só inicia conversa com morador visível na busca (preferência de privacidade).
        if ($eu['tipo'] === 'MORADOR' && $outro['tipo'] === 'MORADOR' && !chatin_preferencias($c, $tenantId, 'MORADOR', $outro['id'])['visivel_busca']) {
            throw new ChatInErro('Este morador não está disponível para novas conversas.', 403, 'CONTATO_INDISPONIVEL');
        }
        if (chatin_bloqueado($c, $tenantId, $eu, $outro)) throw new ChatInErro('Não é possível iniciar conversa com este contato.', 403, 'CONTATO_BLOQUEADO');
    }
    return chatin_obter_ou_criar_conversa($c, $tenantId, 'DIRETA', $chave, [$eu, $outro]);
}

function chatin_participacao(mysqli $c, $tenantId, $conversaId, array $eu) {
    return chatin_linha($c, 'SELECT p.*, cv.tipo AS conversa_tipo FROM chatin_participantes p
        INNER JOIN chatin_conversas cv ON cv.id = p.conversa_id AND cv.tenant_id = p.tenant_id
        WHERE p.tenant_id = ? AND p.conversa_id = ? AND p.participante_tipo = ? AND p.participante_id = ? LIMIT 1',
        'iisi', [(int)$tenantId, (int)$conversaId, $eu['tipo'], (int)$eu['id']]);
}

function chatin_exigir_participacao(mysqli $c, $tenantId, $conversaId, array $eu) {
    $p = chatin_participacao($c, $tenantId, $conversaId, $eu);
    if (!$p) throw new ChatInErro('Conversa não encontrada.', 404, 'CONVERSA_NAO_ENCONTRADA');
    return $p;
}

/** Trava participantes da conversa e pessoas em ordem estável antes de serializar uma escrita. */
function chatin_bloquear_pessoas_conversa(mysqli $c, $tenantId, $conversaId, array $eu) {
    $participantes = chatin_linhas($c, 'SELECT participante_tipo, participante_id FROM chatin_participantes
        WHERE tenant_id = ? AND conversa_id = ? ORDER BY participante_tipo, participante_id FOR UPDATE',
        'ii', [(int)$tenantId, (int)$conversaId]);
    if (!$participantes) throw new ChatInErro('Conversa não encontrada.', 404, 'CONVERSA_NAO_ENCONTRADA');
    $pessoas = [];
    foreach ($participantes as $p) {
        if (in_array($p['participante_tipo'], CHATIN_TIPOS_PESSOA, true)) {
            $pessoas[$p['participante_tipo'] . ':' . (int)$p['participante_id']] = ['tipo' => $p['participante_tipo'], 'id' => (int)$p['participante_id']];
        }
    }
    $pessoas[$eu['tipo'] . ':' . (int)$eu['id']] = ['tipo' => $eu['tipo'], 'id' => (int)$eu['id']];
    $pessoas = array_values($pessoas);
    usort($pessoas, function ($a, $b) {
        $tipo = strcmp((string)$a['tipo'], (string)$b['tipo']);
        return $tipo !== 0 ? $tipo : ((int)$a['id'] <=> (int)$b['id']);
    });
    foreach ($pessoas as $pessoa) {
        if (!chatin_pessoa_bloquear($c, $tenantId, $pessoa['tipo'], $pessoa['id'])) {
            throw new ChatInErro('Participante inativo neste condomínio.', 410, 'CONTATO_INATIVO');
        }
    }
}

function chatin_usuario_tem_acesso(mysqli $c, $usuarioId, $tenantId) {
    static $cache = [];
    $chaveCache = (int)$tenantId . ':' . (int)$usuarioId;
    if (array_key_exists($chaveCache, $cache)) return $cache[$chaveCache];
    if (function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($c)) {
        if (!function_exists('rbacPode')) return $cache[$chaveCache] = false;
        $tinhaCache = array_key_exists('_rbac_cache', $_SESSION ?? []);
        $cacheOriginal = $_SESSION['_rbac_cache'] ?? null;
        $permitido = rbacPode($c, 'chatin', 'visualizar', (int)$usuarioId, (int)$tenantId);
        if ($tinhaCache) $_SESSION['_rbac_cache'] = $cacheOriginal;
        else unset($_SESSION['_rbac_cache']);
        return $cache[$chaveCache] = (bool)$permitido;
    }
    return $cache[$chaveCache] = false; // fail-closed: após a migration v2, RBAC ausente não concede acesso ao Chat-In
}

function chatin_relacao_ativa(array $a, array $b, array $config) {
    if (empty($config['ativo'])) return false;
    $tipos = [$a['tipo'], $b['tipo']]; sort($tipos);
    if ($tipos === ['USUARIO', 'USUARIO']) return !empty($config['usuario_usuario']);
    if ($tipos === ['MORADOR', 'MORADOR']) return !empty($config['morador_morador']);
    return !empty($config['usuario_morador']);
}

function chatin_conversa_pode_enviar(mysqli $c, $tenantId, array $eu, array $outro, $conversaId) {
    $config = chatin_config_tenant($c, $tenantId);
    if (!chatin_relacao_ativa($eu, $outro, $config)) return false;
    if ($outro['tipo'] === 'USUARIO' && !chatin_usuario_tem_acesso($c, $outro['id'], $tenantId)) return false;
    if ($eu['tipo'] === 'MORADOR' && $outro['tipo'] === 'USUARIO' && !chatin_usuario_visivel_para_moradores($c, $tenantId, $outro['id'])) return false;
    if ($eu['tipo'] === 'MORADOR' && $outro['tipo'] === 'USUARIO' && empty($config['morador_inicia_com_equipe'])) {
        if ((int)$conversaId <= 0) return false;
        $mensagemEquipe = chatin_linha($c, "SELECT 1 AS existe FROM chatin_mensagens WHERE tenant_id = ? AND conversa_id = ? AND remetente_tipo = 'USUARIO' LIMIT 1", 'ii', [(int)$tenantId, (int)$conversaId]);
        return $mensagemEquipe !== null; // morador pode responder, mas não iniciar o contato
    }
    return true;
}

function chatin_grupos_visiveis_moradores(mysqli $c, $tenantId) {
    $config = chatin_config_tenant($c, $tenantId);
    $ids = json_decode((string)$config['grupos_visiveis_moradores_json'], true);
    if (!is_array($ids)) return [];
    return array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) { return $id > 0; })));
}

function chatin_usuario_visivel_para_moradores(mysqli $c, $tenantId, $usuarioId) {
    if (!chatin_usuario_tem_acesso($c, $usuarioId, $tenantId)) return false;
    $grupos = chatin_grupos_visiveis_moradores($c, $tenantId);
    if (!$grupos) return true;
    $ph = implode(',', array_fill(0, count($grupos), '?'));
    $sql = 'SELECT 1 AS permitido FROM rbac_usuario_grupos ug
        INNER JOIN rbac_grupos g ON g.id = ug.grupo_id AND g.tenant_id = ug.tenant_id AND g.ativo = 1 AND g.excluido_em IS NULL
        WHERE ug.tenant_id = ? AND ug.usuario_id = ? AND ug.ativo = 1 AND ug.removido_em IS NULL AND g.id IN (' . $ph . ') LIMIT 1';
    $r = chatin_linha($c, $sql, 'ii' . str_repeat('i', count($grupos)), array_merge([(int)$tenantId, (int)$usuarioId], $grupos));
    return $r !== null;
}

function chatin_exigir_conversa_permitida(mysqli $c, $tenantId, array $eu, array $outro, $conversaId, $recurso = 'mensagem') {
    if (!chatin_conversa_pode_enviar($c, $tenantId, $eu, $outro, $conversaId)) {
        throw new ChatInErro('Esta conversa foi desativada pela configuração do condomínio e está somente para leitura.', 403, 'CHATIN_NAO_PERMITIDO');
    }
}

/** Demais participantes (pessoas) de uma conversa. */
function chatin_outros(mysqli $c, $tenantId, $conversaId, array $eu) {
    return chatin_linhas($c, 'SELECT participante_tipo AS tipo, participante_id AS id FROM chatin_participantes
        WHERE tenant_id = ? AND conversa_id = ? AND NOT (participante_tipo = ? AND participante_id = ?)',
        'iisi', [(int)$tenantId, (int)$conversaId, $eu['tipo'], (int)$eu['id']]);
}

function chatin_digitando_atualizar(mysqli $c, $tenantId, array $eu, $conversaId, $digitando, $plataforma = 'web') {
    if (!$digitando) {
        chatin_q($c, 'UPDATE chatin_presenca SET digitando_conversa_id = NULL, digitando_ate = NULL WHERE tenant_id = ? AND participante_tipo = ? AND participante_id = ? AND digitando_conversa_id = ?',
            'isii', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], (int)$conversaId])->close();
        return false;
    }
    $c->begin_transaction();
    try {
        $config = chatin_config_tenant($c, $tenantId, true, true);
        if (empty($config['ativo'])) throw new ChatInErro('O Chat-In está desativado neste condomínio.', 403, 'CHATIN_DESATIVADO');
        if (empty($config['recurso_digitando'])) throw new ChatInErro('O indicador de digitação está desativado neste condomínio.', 403, 'CHATIN_RECURSO_DESATIVADO');
        $conversa = chatin_linha($c, 'SELECT id, tipo FROM chatin_conversas WHERE tenant_id = ? AND id = ? FOR UPDATE', 'ii', [(int)$tenantId, (int)$conversaId]);
        if (!$conversa) throw new ChatInErro('Conversa não encontrada.', 404, 'CONVERSA_NAO_ENCONTRADA');
        chatin_bloquear_pessoas_conversa($c, $tenantId, $conversaId, $eu);
        $part = chatin_exigir_participacao($c, $tenantId, $conversaId, $eu);
        if ($part['conversa_tipo'] !== 'DIRETA') throw new ChatInErro('Este canal não aceita sinais de digitação.', 403, 'CONVERSA_SOMENTE_LEITURA');
        $outros = chatin_outros($c, $tenantId, $conversaId, $eu);
        if (count($outros) !== 1) throw new ChatInErro('Contato indisponível neste condomínio.', 410, 'CONTATO_INATIVO');
        $destino = $outros[0];
        if (chatin_bloqueado($c, $tenantId, $eu, $destino, true)) throw new ChatInErro('Conversa indisponível.', 403, 'CONTATO_BLOQUEADO');
        chatin_config_tenant($c, $tenantId, true);
        chatin_exigir_conversa_permitida($c, $tenantId, $eu, $destino, $conversaId, 'digitando');
        if (!chatin_preferencias($c, $tenantId, $eu['tipo'], $eu['id'], true)['mostrar_online']) {
            chatin_q($c, 'UPDATE chatin_presenca SET digitando_conversa_id = NULL, digitando_ate = NULL WHERE tenant_id = ? AND participante_tipo = ? AND participante_id = ? AND digitando_conversa_id = ?',
                'isii', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], (int)$conversaId])->close();
            $c->commit();
            return false;
        }
        // Expira em 6 s; a condição mantém no máximo uma atualização por segundo, mesmo com chamadas repetidas.
        $plataforma = in_array($plataforma, ['painel','portal','app'], true) ? $plataforma : 'web';
        chatin_q($c, 'INSERT INTO chatin_presenca (tenant_id, participante_tipo, participante_id, ultimo_ping, plataforma, conversa_aberta_id, digitando_conversa_id, digitando_ate)
            VALUES (?, ?, ?, NOW(), ?, NULL, ?, DATE_ADD(NOW(), INTERVAL 6 SECOND))
            ON DUPLICATE KEY UPDATE
              digitando_conversa_id = IF(digitando_ate IS NULL OR digitando_ate <= NOW() + INTERVAL 5 SECOND, VALUES(digitando_conversa_id), digitando_conversa_id),
              digitando_ate = IF(digitando_ate IS NULL OR digitando_ate <= NOW() + INTERVAL 5 SECOND, VALUES(digitando_ate), digitando_ate)',
            'isisi', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $plataforma, (int)$conversaId])->close();
        $c->commit();
        return true;
    } catch (Throwable $e) {
        try { $c->rollback(); } catch (Throwable $ignorar) { /* a conexão pode já ter encerrado a transação */ }
        throw $e;
    }
}

function chatin_contato_digitando(mysqli $c, $tenantId, array $eu, $conversaId) {
    chatin_exigir_participacao($c, $tenantId, $conversaId, $eu);
    if (!chatin_config_tenant($c, $tenantId)['recurso_digitando']) return false;
    $outros = chatin_outros($c, $tenantId, $conversaId, $eu);
    if (count($outros) !== 1 || chatin_bloqueado($c, $tenantId, $eu, $outros[0])) return false;
    if (!chatin_conversa_pode_enviar($c, $tenantId, $eu, $outros[0], $conversaId)) return false;
    $r = chatin_linha($c, 'SELECT 1 AS ativo FROM chatin_presenca p
        LEFT JOIN chatin_preferencias pref ON pref.tenant_id = p.tenant_id AND pref.participante_tipo = p.participante_tipo AND pref.participante_id = p.participante_id
        WHERE p.tenant_id = ? AND p.participante_tipo = ? AND p.participante_id = ? AND p.digitando_conversa_id = ?
          AND p.digitando_ate > NOW() AND COALESCE(pref.mostrar_online, 1) = 1 LIMIT 1',
        'isii', [(int)$tenantId, $outros[0]['tipo'], (int)$outros[0]['id'], (int)$conversaId]);
    return $r !== null;
}

function chatin_emitir_cutucada(mysqli $c, $tenantId, array $eu, $conversaId) {
    $c->begin_transaction();
    try {
        // Releitura bloqueante: serializa todas as cutucadas nesta conversa antes da contagem.
        $config = chatin_config_tenant($c, $tenantId, true, true);
        if (empty($config['ativo'])) throw new ChatInErro('O Chat-In está desativado neste condomínio.', 403, 'CHATIN_DESATIVADO');
        if (empty($config['recurso_cutucar'])) throw new ChatInErro('Cutucar está desativado neste condomínio.', 403, 'CHATIN_RECURSO_DESATIVADO');
        chatin_config_tenant($c, $tenantId, true);
        $bloqueio = chatin_linha($c, 'SELECT id, tipo FROM chatin_conversas WHERE tenant_id = ? AND id = ? FOR UPDATE', 'ii', [(int)$tenantId, (int)$conversaId]);
        if (!$bloqueio) throw new ChatInErro('Conversa não encontrada.', 404, 'CONVERSA_NAO_ENCONTRADA');
        chatin_bloquear_pessoas_conversa($c, $tenantId, $conversaId, $eu);
        $part = chatin_exigir_participacao($c, $tenantId, $conversaId, $eu);
        if ($part['conversa_tipo'] !== 'DIRETA') throw new ChatInErro('Cutucar só está disponível em conversas diretas.', 403, 'CONVERSA_SOMENTE_LEITURA');
        $outros = chatin_outros($c, $tenantId, $conversaId, $eu);
        if (count($outros) !== 1) throw new ChatInErro('Contato indisponível neste condomínio.', 410, 'CONTATO_INATIVO');
        $destino = $outros[0];
        if (chatin_bloqueado($c, $tenantId, $eu, $destino, true)) throw new ChatInErro('Conversa indisponível.', 403, 'CONTATO_BLOQUEADO');
        if (!chatin_permitir_cutucar_bloquear($c, $tenantId, $destino['tipo'], $destino['id'])) throw new ChatInErro('Este contato desativou a opção Cutucar.', 403, 'CUTUCAR_DESATIVADO');
        chatin_exigir_conversa_permitida($c, $tenantId, $eu, $destino, $conversaId, 'cutucar');
        $recente = chatin_linha($c, 'SELECT COUNT(*) AS n,
            MAX(CASE WHEN criado_em >= NOW() - INTERVAL 30 SECOND
                THEN GREATEST(1, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(criado_em, INTERVAL 30 SECOND)) + 1) ELSE 0 END) AS espera_curta,
            MIN(criado_em) AS mais_antiga
            FROM chatin_mensagens WHERE tenant_id = ? AND conversa_id = ? AND remetente_tipo = ? AND remetente_id = ? AND tipo = \'CUTUCAR\' AND criado_em >= NOW() - INTERVAL 10 MINUTE',
            'iisi', [(int)$tenantId, (int)$conversaId, $eu['tipo'], (int)$eu['id']]);
        $espera = (int)($recente['espera_curta'] ?? 0);
        if ((int)($recente['n'] ?? 0) >= 5 && !empty($recente['mais_antiga'])) {
            $limiteDezMinutos = chatin_linha($c, 'SELECT GREATEST(1, 600 - TIMESTAMPDIFF(SECOND, ?, NOW())) AS segundos', 's', [$recente['mais_antiga']]);
            $espera = max($espera, (int)($limiteDezMinutos['segundos'] ?? 1));
        }
        if ($espera > 0) throw new ChatInErro('Aguarde ' . $espera . ' s antes de enviar outra cutucada.', 429, 'CUTUCAR_LIMITE', $espera);
        $id = chatin_inserir_mensagem($c, $tenantId, $conversaId, $eu, 'cutucada', 'CUTUCAR');
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        throw $e;
    }
    if ($destino['tipo'] === 'MORADOR') chatin_push_morador($c, $tenantId, (int)$destino['id'], chatin_texto($eu['nome'], 80) . ' cutucou você', 'Toque para abrir o Chat-In.', (int)$conversaId, 'chatin_cutucar');
    $linha = chatin_linha($c, 'SELECT * FROM chatin_mensagens WHERE tenant_id = ? AND conversa_id = ? AND id = ?', 'iii', [(int)$tenantId, (int)$conversaId, $id]);
    if (!$linha) throw new RuntimeException('Chat-In: mensagem criada não pôde ser recuperada.');
    return chatin_mensagem_publica($linha, $eu);
}

function chatin_inserir_mensagem(mysqli $c, $tenantId, $conversaId, array $remetente, $corpo, $tipo = 'TEXTO', $titulo = null, $refTipo = null, $refId = null, $idem = null) {
    $nome = chatin_texto($remetente['nome'] ?? '', 150);
    chatin_q($c, 'INSERT INTO chatin_mensagens (tenant_id, conversa_id, remetente_tipo, remetente_id, remetente_nome, tipo, corpo, titulo, referencia_tipo, referencia_id, idempotency_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'iisisssssis', [(int)$tenantId, (int)$conversaId, $remetente['tipo'], (int)($remetente['id'] ?? 0), $nome, $tipo, $corpo, $titulo, $refTipo, $refId, $idem])->close();
    $id = (int)$c->insert_id;
    chatin_q($c, 'UPDATE chatin_conversas SET ultima_mensagem_id = ?, ultima_mensagem_em = NOW() WHERE tenant_id = ? AND id = ?', 'iii', [$id, (int)$tenantId, (int)$conversaId])->close();
    if (in_array($remetente['tipo'], CHATIN_TIPOS_PESSOA, true)) {
        chatin_q($c, 'UPDATE chatin_participantes SET ultima_leitura_id = GREATEST(ultima_leitura_id, ?), arquivado = 0 WHERE tenant_id = ? AND conversa_id = ? AND participante_tipo = ? AND participante_id = ?',
            'iiisi', [$id, (int)$tenantId, (int)$conversaId, $remetente['tipo'], (int)$remetente['id']])->close();
    }
    // Nova mensagem reabre a conversa arquivada pelos demais.
    chatin_q($c, 'UPDATE chatin_participantes SET arquivado = 0 WHERE tenant_id = ? AND conversa_id = ?', 'ii', [(int)$tenantId, (int)$conversaId])->close();
    return $id;
}

/** Envio de mensagem de texto por uma pessoa. Retorna a mensagem formatada. */
function chatin_enviar(mysqli $c, $tenantId, array $eu, $conversaId, $corpo, $idem = null) {
    $corpo = chatin_texto($corpo, CHATIN_MAX_CARACTERES + 1);
    if ($corpo === '') throw new ChatInErro('Digite uma mensagem.', 422, 'MENSAGEM_VAZIA');
    if (chatin_tamanho($corpo) > CHATIN_MAX_CARACTERES) throw new ChatInErro('A mensagem deve ter até ' . CHATIN_MAX_CARACTERES . ' caracteres.', 422, 'MENSAGEM_LONGA');
    if ($idem !== null) {
        $idem = (string)$idem;
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $idem)) throw new ChatInErro('Chave idempotente inválida.', 422, 'IDEMPOTENCY_INVALIDA');
    }
    $c->begin_transaction();
    try {
        $config = chatin_config_tenant($c, $tenantId, true, true);
        if (empty($config['ativo'])) throw new ChatInErro('O Chat-In está desativado neste condomínio.', 403, 'CHATIN_DESATIVADO');
        $conv = chatin_linha($c, 'SELECT id, tipo FROM chatin_conversas WHERE tenant_id = ? AND id = ? FOR UPDATE', 'ii', [(int)$tenantId, (int)$conversaId]);
        if (!$conv) throw new ChatInErro('Conversa não encontrada.', 404, 'CONVERSA_NAO_ENCONTRADA');
        chatin_bloquear_pessoas_conversa($c, $tenantId, $conversaId, $eu);
        $part = chatin_exigir_participacao($c, $tenantId, $conversaId, $eu);

        if ($idem !== null) {
            $ja = chatin_linha($c, 'SELECT id, conversa_id, tipo, corpo FROM chatin_mensagens WHERE tenant_id = ? AND remetente_tipo = ? AND remetente_id = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE',
                'isis', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $idem]);
            if ($ja) {
                if ((int)$ja['conversa_id'] !== (int)$conversaId || $ja['tipo'] !== 'TEXTO' || (string)$ja['corpo'] !== $corpo) {
                    throw new ChatInErro('A chave de idempotência já foi usada em outra mensagem.', 409, 'IDEMPOTENCY_CONFLICT');
                }
                $linhaAnterior = chatin_linha($c, 'SELECT * FROM chatin_mensagens WHERE tenant_id = ? AND conversa_id = ? AND id = ?',
                    'iii', [(int)$tenantId, (int)$conversaId, (int)$ja['id']]);
                if (!$linhaAnterior) throw new RuntimeException('Chat-In: registro idempotente não pôde ser recuperado.');
                $c->commit();
                return chatin_mensagem_publica($linhaAnterior, $eu) + ['idempotente' => true];
            }
        }

        if ($part['conversa_tipo'] !== 'DIRETA') throw new ChatInErro('Esta conversa recebe apenas avisos do condomínio.', 403, 'CONVERSA_SOMENTE_LEITURA');
        $configCompleta = chatin_config_tenant($c, $tenantId, true);
        if (!$configCompleta['ativo']) throw new ChatInErro('O Chat-In está desativado neste condomínio.', 403, 'CHATIN_DESATIVADO');
        if (empty($configCompleta['recurso_emojis']) && chatin_contem_emoji($corpo)) throw new ChatInErro('Emojis estão desativados neste condomínio.', 403, 'CHATIN_EMOJI_DESATIVADO');
        $outros = chatin_outros($c, $tenantId, $conversaId, $eu);
        foreach ($outros as $o) {
            if (chatin_bloqueado($c, $tenantId, $eu, $o, true)) throw new ChatInErro('Não é possível enviar mensagens para este contato.', 403, 'CONTATO_BLOQUEADO');
            chatin_exigir_conversa_permitida($c, $tenantId, $eu, $o, $conversaId, 'mensagem');
        }
        $recentes = chatin_linhas($c, 'SELECT id FROM chatin_mensagens WHERE tenant_id = ? AND remetente_tipo = ? AND remetente_id = ? AND criado_em >= NOW() - INTERVAL 60 SECOND ORDER BY id DESC LIMIT ' . (CHATIN_LIMITE_MSG_POR_MINUTO + 1) . ' FOR UPDATE',
            'isi', [(int)$tenantId, $eu['tipo'], (int)$eu['id']]);
        if (count($recentes) >= CHATIN_LIMITE_MSG_POR_MINUTO) throw new ChatInErro('Muitas mensagens em pouco tempo. Aguarde alguns segundos.', 429, 'LIMITE_MENSAGENS');

        $id = chatin_inserir_mensagem($c, $tenantId, $conversaId, $eu, $corpo, 'TEXTO', null, null, null, $idem);
        $c->commit();
    } catch (Throwable $e) {
        try { $c->rollback(); } catch (Throwable $ignorar) { /* a conexão pode já ter encerrado a transação */ }
        throw $e;
    }
    foreach ($outros as $o) {
        if ($o['tipo'] === 'MORADOR') {
            $preview = function_exists('mb_substr') ? mb_substr($corpo, 0, 140, 'UTF-8') : substr($corpo, 0, 140);
            chatin_push_morador($c, $tenantId, (int)$o['id'], $eu['nome'], $preview, (int)$conversaId, 'chatin_mensagem');
        }
    }
    $linha = chatin_linha($c, 'SELECT * FROM chatin_mensagens WHERE tenant_id = ? AND conversa_id = ? AND id = ?',
        'iii', [(int)$tenantId, (int)$conversaId, (int)$id]);
    if (!$linha) throw new RuntimeException('Chat-In: mensagem enviada não pôde ser recuperada.');
    return chatin_mensagem_publica($linha, $eu);
}

/**
 * Aviso automático para uma lista de pessoas (conversa "Avisos do condomínio" de cada uma).
 * $destinatarios = [['tipo'=>'MORADOR','id'=>12], ...]. Retorna quantos foram entregues.
 */
function chatin_enviar_aviso(mysqli $c, $tenantId, array $destinatarios, $remetenteNome, $titulo, $corpo, $refTipo = null, $refId = null) {
    if (!chatin_instalado($c)) return 0;
    $titulo = chatin_texto($titulo, 150);
    $corpo = chatin_texto($corpo, CHATIN_MAX_CARACTERES);
    $remetente = ['tipo' => 'SISTEMA', 'id' => 0, 'nome' => chatin_texto($remetenteNome ?: 'Administração', 150)];
    $entregues = 0; $vistos = []; $pushPendentes = []; $suprimido = false;
    foreach ($destinatarios as $d) {
        $tipo = $d['tipo'] ?? ''; $id = (int)($d['id'] ?? 0);
        if (!in_array($tipo, CHATIN_TIPOS_PESSOA, true) || $id <= 0) continue;
        $k = chatin_sigla($tipo) . $id;
        if (isset($vistos[$k])) continue;
        $vistos[$k] = true;
        try {
            $c->begin_transaction();
            $configTenant = chatin_config_tenant($c, $tenantId, true, true);
            if (empty($configTenant['ativo'])) {
                $c->rollback();
                $suprimido = true;
                break;
            }
            if (!chatin_pessoa($c, $tenantId, $tipo, $id)) {
                $c->rollback();
                continue;
            }
            $conversaId = chatin_obter_ou_criar_conversa($c, $tenantId, 'AVISOS', 'AVISOS|' . $k, [['tipo' => $tipo, 'id' => $id], ['tipo' => 'SISTEMA', 'id' => 0]]);
            chatin_inserir_mensagem($c, $tenantId, $conversaId, $remetente, $corpo, 'AVISO', $titulo, $refTipo, $refId, null);
            $c->commit();
            $entregues++;
            if ($tipo === 'MORADOR') $pushPendentes[] = ['id' => $id, 'conversa_id' => $conversaId];
        } catch (Throwable $e) {
            try { $c->rollback(); } catch (Throwable $ignorar) { /* a conexão pode já ter encerrado a transação */ }
            error_log('[ChatIn][Aviso] falha tenant=' . (int)$tenantId . ' tipo=' . $tipo . ' ' . get_class($e) . ' codigo=' . (int)$e->getCode());
        }
    }
    if ($suprimido) error_log('[ChatIn][Aviso] suprimido: módulo desativado no tenant=' . (int)$tenantId);
    foreach ($pushPendentes as $pendente) {
        chatin_push_morador($c, $tenantId, (int)$pendente['id'], $titulo !== '' ? $titulo : $remetente['nome'], $corpo,
            (int)$pendente['conversa_id'], 'chatin_aviso');
    }
    return $entregues;
}

/** Moradores ativos do tenant (opcionalmente só de algumas unidades) como destinatários. */
function chatin_destinatarios_moradores(mysqli $c, $tenantId, array $unidades = null) {
    if ($unidades !== null) {
        $unidades = array_values(array_filter(array_map(function ($u) { return trim((string)$u); }, $unidades), 'strlen'));
        if (!$unidades) return [];
        $ph = implode(',', array_fill(0, count($unidades), '?'));
        $linhas = chatin_linhas($c, "SELECT id FROM moradores WHERE tenant_id = ? AND ativo = 1 AND unidade IN ($ph)", 'i' . str_repeat('s', count($unidades)), array_merge([(int)$tenantId], $unidades));
    } else {
        $linhas = chatin_linhas($c, 'SELECT id FROM moradores WHERE tenant_id = ? AND ativo = 1', 'i', [(int)$tenantId]);
    }
    return array_map(function ($l) { return ['tipo' => 'MORADOR', 'id' => (int)$l['id']]; }, $linhas);
}

/**
 * Push FCM para o morador (app). Respeita preferência pessoal, gate tenant `recurso_push_app`
 * (migrado de `push_chatin_ativo`) e presença em conversa aberta no app.
 */
function chatin_push_morador(mysqli $c, $tenantId, $moradorId, $titulo, $corpo, $conversaId, $tipoEvento) {
    try {
        if (!chatin_preferencias($c, $tenantId, 'MORADOR', $moradorId)['notificar_push']) return 'preferencia_desativada';
        $configTenant = chatin_config_tenant($c, $tenantId, true);
        if (!$configTenant['ativo'] || !$configTenant['recurso_push_app']) return 'desativado';
        $config = function_exists('protocolo_notificacao_config') ? protocolo_notificacao_config($c, (int)$tenantId) : [];
        $projectId = trim((string)($config['fcm_project_id'] ?? ''));
        if ($projectId === '' || strpos($projectId, 'SUBSTITUA') === 0 || !chatin_tabela_existe($c, 'pwa_fcm_tokens')) return 'nao_configurado';
        $aberta = chatin_linha($c, "SELECT 1 AS x FROM chatin_presenca WHERE tenant_id = ? AND participante_tipo = 'MORADOR' AND participante_id = ?
            AND plataforma = 'app' AND conversa_aberta_id = ? AND ultimo_ping >= NOW() - INTERVAL 20 SECOND LIMIT 1", 'iii', [(int)$tenantId, (int)$moradorId, (int)$conversaId]);
        if ($aberta) return 'conversa_aberta';
        $tokens = chatin_linhas($c, 'SELECT id, fcm_token FROM pwa_fcm_tokens WHERE tenant_id = ? AND morador_id = ? AND ativo = 1', 'ii', [(int)$tenantId, (int)$moradorId]);
        $enviados = 0;
        foreach ($tokens as $t) {
            $r = protocolo_notificacao_enviar_fcm($t['fcm_token'], 'Chat-In · ' . chatin_texto($titulo, 80), chatin_texto($corpo, 160), [
                'tipo' => $tipoEvento, 'origem' => 'chatin', 'conversa_id' => (int)$conversaId, 'rota' => '/home/chatin/' . (int)$conversaId,
            ], $projectId, 'chatin');
            if (!empty($r['sucesso'])) $enviados++;
            elseif (!empty($r['invalido'])) chatin_q($c, 'UPDATE pwa_fcm_tokens SET ativo = 0 WHERE tenant_id = ? AND id = ?', 'ii', [(int)$tenantId, (int)$t['id']])->close();
        }
        return $enviados > 0 ? 'enviado' : ($tokens ? 'falhou' : 'sem_token');
    } catch (Throwable $e) {
        error_log('[ChatIn][Push] ' . get_class($e) . ' codigo=' . (int)$e->getCode());
        return 'erro';
    }
}

function chatin_mensagem_publica(array $m, array $eu) {
    return [
        'id' => (int)$m['id'],
        'conversa_id' => (int)$m['conversa_id'],
        'tipo' => (string)$m['tipo'],
        'remetente' => ['tipo' => (string)$m['remetente_tipo'], 'id' => (int)$m['remetente_id'], 'nome' => (string)$m['remetente_nome']],
        'minha' => $m['remetente_tipo'] === $eu['tipo'] && (int)$m['remetente_id'] === (int)$eu['id'],
        'titulo' => $m['titulo'],
        'corpo' => (string)$m['corpo'],
        'referencia' => $m['referencia_tipo'] ? ['tipo' => (string)$m['referencia_tipo'], 'id' => $m['referencia_id'] === null ? null : (int)$m['referencia_id']] : null,
        'criado_em' => (string)$m['criado_em'],
    ];
}

function chatin_mensagens(mysqli $c, $tenantId, array $eu, $conversaId, $aposId = 0, $antesId = 0, $limite = 40) {
    chatin_exigir_participacao($c, $tenantId, $conversaId, $eu);
    $limite = max(1, min(100, (int)$limite));
    if ($aposId > 0) {
        $linhas = chatin_linhas($c, 'SELECT * FROM chatin_mensagens WHERE tenant_id = ? AND conversa_id = ? AND id > ? ORDER BY id ASC LIMIT ' . $limite, 'iii', [(int)$tenantId, (int)$conversaId, (int)$aposId]);
    } else {
        $sql = 'SELECT * FROM chatin_mensagens WHERE tenant_id = ? AND conversa_id = ?' . ($antesId > 0 ? ' AND id < ?' : '') . ' ORDER BY id DESC LIMIT ' . $limite;
        $linhas = array_reverse(chatin_linhas($c, $sql, $antesId > 0 ? 'iii' : 'ii', $antesId > 0 ? [(int)$tenantId, (int)$conversaId, (int)$antesId] : [(int)$tenantId, (int)$conversaId]));
    }
    return array_map(function ($m) use ($eu) { return chatin_mensagem_publica($m, $eu); }, $linhas);
}

function chatin_marcar_lida(mysqli $c, $tenantId, array $eu, $conversaId, $ateId = 0) {
    chatin_exigir_participacao($c, $tenantId, $conversaId, $eu);
    $u = chatin_linha($c, 'SELECT ultima_mensagem_id FROM chatin_conversas WHERE tenant_id = ? AND id = ?', 'ii', [(int)$tenantId, (int)$conversaId]);
    $ultimoId = (int)($u['ultima_mensagem_id'] ?? 0);
    $ateId = (int)$ateId;
    if ($ateId > $ultimoId) $ateId = $ultimoId;
    if ($ateId > 0) {
        $existe = chatin_linha($c, 'SELECT id FROM chatin_mensagens WHERE tenant_id = ? AND conversa_id = ? AND id = ? LIMIT 1',
            'iii', [(int)$tenantId, (int)$conversaId, $ateId]);
        if (!$existe) throw new ChatInErro('A mensagem indicada não pertence a esta conversa.', 422, 'LEITURA_INVALIDA');
    } else {
        $ateId = $ultimoId;
    }
    chatin_q($c, 'UPDATE chatin_participantes SET ultima_leitura_id = GREATEST(ultima_leitura_id, ?) WHERE tenant_id = ? AND conversa_id = ? AND participante_tipo = ? AND participante_id = ?',
        'iiisi', [(int)$ateId, (int)$tenantId, (int)$conversaId, $eu['tipo'], (int)$eu['id']])->close();
}

function chatin_arquivar(mysqli $c, $tenantId, array $eu, $conversaId, $arquivar) {
    chatin_exigir_participacao($c, $tenantId, $conversaId, $eu);
    chatin_q($c, 'UPDATE chatin_participantes SET arquivado = ? WHERE tenant_id = ? AND conversa_id = ? AND participante_tipo = ? AND participante_id = ?',
        'iiisi', [$arquivar ? 1 : 0, (int)$tenantId, (int)$conversaId, $eu['tipo'], (int)$eu['id']])->close();
}

/** Contagem de não lidas por conversa de $eu (mensagens de outros após ultima_leitura_id). */
function chatin_nao_lidas_mapa(mysqli $c, $tenantId, array $eu) {
    $linhas = chatin_linhas($c, 'SELECT p.conversa_id, COUNT(m.id) AS n FROM chatin_participantes p
        INNER JOIN chatin_mensagens m ON m.tenant_id = p.tenant_id AND m.conversa_id = p.conversa_id AND m.id > p.ultima_leitura_id
             AND NOT (m.remetente_tipo = p.participante_tipo AND m.remetente_id = p.participante_id)
        WHERE p.tenant_id = ? AND p.participante_tipo = ? AND p.participante_id = ?
        GROUP BY p.conversa_id', 'isi', [(int)$tenantId, $eu['tipo'], (int)$eu['id']]);
    $mapa = [];
    foreach ($linhas as $l) $mapa[(int)$l['conversa_id']] = (int)$l['n'];
    return $mapa;
}

/** Versão da caixa de $eu: muda quando chega/sai mensagem ou muda leitura (para o polling). */
function chatin_versao(mysqli $c, $tenantId, array $eu) {
    $r = chatin_linha($c, 'SELECT COALESCE(MAX(cv.ultima_mensagem_id), 0) AS ult, COALESCE(SUM(p.ultima_leitura_id), 0) AS lidas, COUNT(*) AS n, COALESCE(SUM(p.arquivado), 0) AS arq
        FROM chatin_participantes p INNER JOIN chatin_conversas cv ON cv.id = p.conversa_id AND cv.tenant_id = p.tenant_id
        WHERE p.tenant_id = ? AND p.participante_tipo = ? AND p.participante_id = ?', 'isi', [(int)$tenantId, $eu['tipo'], (int)$eu['id']]);
    return $r['ult'] . '.' . $r['lidas'] . '.' . $r['n'] . '.' . $r['arq'];
}

/** Lista de conversas de $eu (mais recentes primeiro), com contato, prévia, não lidas e online. */
function chatin_listar_conversas(mysqli $c, $tenantId, array $eu, $incluirArquivadas = false, $limite = 60) {
    $limite = max(1, min(200, (int)$limite));
    $linhas = chatin_linhas($c, 'SELECT cv.id, cv.tipo, cv.ultima_mensagem_em, p.arquivado, p.silenciado,
            m.tipo AS ult_tipo, m.corpo AS ult_corpo, m.titulo AS ult_titulo, m.remetente_tipo AS ult_rem_tipo, m.remetente_id AS ult_rem_id, m.remetente_nome AS ult_rem_nome, m.criado_em AS ult_em
        FROM chatin_participantes p
        INNER JOIN chatin_conversas cv ON cv.id = p.conversa_id AND cv.tenant_id = p.tenant_id
        LEFT JOIN chatin_mensagens m ON m.id = cv.ultima_mensagem_id AND m.tenant_id = cv.tenant_id
        WHERE p.tenant_id = ? AND p.participante_tipo = ? AND p.participante_id = ?' . ($incluirArquivadas ? '' : ' AND p.arquivado = 0') . '
          AND cv.ultima_mensagem_id IS NOT NULL
        ORDER BY cv.ultima_mensagem_em DESC, cv.id DESC LIMIT ' . $limite, 'isi', [(int)$tenantId, $eu['tipo'], (int)$eu['id']]);
    $naoLidas = chatin_nao_lidas_mapa($c, $tenantId, $eu);
    $conversas = []; $pessoas = [];
    foreach ($linhas as $l) {
        $contato = null;
        if ($l['tipo'] === 'DIRETA') {
            foreach (chatin_outros($c, $tenantId, (int)$l['id'], $eu) as $o) {
                $pessoa = chatin_pessoa($c, $tenantId, $o['tipo'], $o['id']);
                $contato = $pessoa ?: ['tipo' => $o['tipo'], 'id' => (int)$o['id'], 'nome' => 'Contato inativo', 'unidade' => null, 'subtitulo' => 'Indisponível'];
                $contato['ativo'] = (bool)$pessoa;
                $contato['bloqueado_por_mim'] = chatin_eu_bloqueei($c, $tenantId, $eu, $o);
                $pessoas[] = $o;
                break;
            }
        }
        $somenteLeitura = $l['tipo'] !== 'DIRETA';
        if ($l['tipo'] === 'DIRETA' && $contato && !empty($contato['ativo'])) {
            $outro = ['tipo' => $contato['tipo'], 'id' => (int)$contato['id']];
            $somenteLeitura = !chatin_conversa_pode_enviar($c, $tenantId, $eu, $outro, (int)$l['id']) || chatin_bloqueado($c, $tenantId, $eu, $outro);
        } elseif ($l['tipo'] === 'DIRETA') {
            $somenteLeitura = true;
        }
        $conversas[] = [
            'id' => (int)$l['id'],
            'tipo' => $l['tipo'],
            'titulo' => $l['tipo'] === 'AVISOS' ? 'Avisos do condomínio' : ($contato['nome'] ?? 'Conversa'),
            'subtitulo' => $l['tipo'] === 'AVISOS' ? 'Portaria e administração' : ($contato['subtitulo'] ?? ''),
            'contato' => $contato,
            'somente_leitura' => $somenteLeitura,
            'silenciado' => (int)$l['silenciado'] === 1,
            'arquivada' => (int)$l['arquivado'] === 1,
            'nao_lidas' => $naoLidas[(int)$l['id']] ?? 0,
            'ultima' => $l['ult_em'] === null ? null : [
                'corpo' => $l['ult_rem_tipo'] === 'SISTEMA' ? 'Aviso do condomínio' : ($l['ult_tipo'] === 'CUTUCAR' ? ((($l['ult_rem_tipo'] === $eu['tipo']) && (int)$l['ult_rem_id'] === (int)$eu['id']) ? 'Você cutucou o contato' : ((string)$l['ult_rem_nome'] . ' cutucou você')) : (function_exists('mb_substr') ? mb_substr((string)($l['ult_titulo'] ?: $l['ult_corpo']), 0, 120, 'UTF-8') : substr((string)($l['ult_titulo'] ?: $l['ult_corpo']), 0, 120))),
                'minha' => $l['ult_rem_tipo'] === $eu['tipo'] && (int)$l['ult_rem_id'] === (int)$eu['id'],
                'remetente_nome' => (string)$l['ult_rem_nome'],
                'em' => (string)$l['ult_em'],
            ],
        ];
    }
    $online = chatin_online_mapa($c, $tenantId, $pessoas);
    foreach ($conversas as &$cv) {
        if ($cv['contato']) $cv['contato']['online'] = $online[chatin_sigla($cv['contato']['tipo']) . $cv['contato']['id']] ?? false;
    }
    unset($cv);
    return $conversas;
}

function chatin_max_mensagem_visivel(mysqli $c, $tenantId, array $eu) {
    $r = chatin_linha($c, 'SELECT COALESCE(MAX(m.id), 0) AS max_id FROM chatin_mensagens m
        INNER JOIN chatin_participantes p ON p.tenant_id = m.tenant_id AND p.conversa_id = m.conversa_id
        WHERE p.tenant_id = ? AND p.participante_tipo = ? AND p.participante_id = ?',
        'isi', [(int)$tenantId, $eu['tipo'], (int)$eu['id']]);
    return (int)($r['max_id'] ?? 0);
}

/** Eventos novos sem corpo/preview: suficiente para som e notificação local. */
function chatin_eventos_novos(mysqli $c, $tenantId, array $eu, $desdeId, $limite = 100) {
    $limite = max(1, min(100, (int)$limite));
    $linhas = chatin_linhas($c, 'SELECT m.id, m.conversa_id, m.tipo, m.remetente_tipo, m.remetente_id, m.remetente_nome, p.silenciado,
            COALESCE(pref.permitir_cutucar, 1) AS permitir_cutucar
        FROM chatin_mensagens m
        INNER JOIN chatin_participantes p ON p.tenant_id = m.tenant_id AND p.conversa_id = m.conversa_id
        LEFT JOIN chatin_preferencias pref ON pref.tenant_id = p.tenant_id AND pref.participante_tipo = p.participante_tipo AND pref.participante_id = p.participante_id
        WHERE m.tenant_id = ? AND p.participante_tipo = ? AND p.participante_id = ? AND m.id > ?
        ORDER BY m.id ASC LIMIT ' . $limite,
        'isii', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], max(0, (int)$desdeId)]);
    return array_map(function ($l) {
        return [
            'id' => (int)$l['id'], 'conversa_id' => (int)$l['conversa_id'], 'tipo' => (string)$l['tipo'],
            'remetente_tipo' => (string)$l['remetente_tipo'], 'remetente_id' => (int)$l['remetente_id'],
            'remetente_nome' => (string)$l['remetente_nome'], 'silenciado' => (int)$l['silenciado'] === 1,
            'permitir_cutucar' => (int)$l['permitir_cutucar'] === 1,
        ];
    }, $linhas);
}

/**
 * Busca de contatos. Morador vê moradores visíveis (preferência) e usuários do painel;
 * usuário do painel vê todos os moradores e usuários. Bloqueios ocultam o contato.
 */
function chatin_buscar_contatos(mysqli $c, $tenantId, array $eu, $termo, $limite = 30) {
    $termo = trim((string)$termo);
    $limite = max(1, min(50, (int)$limite));
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termo) . '%';
    $resultado = [];
    $config = chatin_config_tenant($c, $tenantId);

    $verMoradores = $eu['tipo'] === 'USUARIO' ? !empty($config['usuario_morador']) : !empty($config['morador_morador']);
    if ($verMoradores) {
        $sqlM = "SELECT m.id, m.nome, m.unidade FROM moradores m
            LEFT JOIN chatin_preferencias pref ON pref.tenant_id = m.tenant_id AND pref.participante_tipo = 'MORADOR' AND pref.participante_id = m.id
            WHERE m.tenant_id = ? AND m.ativo = 1";
        $tiposM = 'i'; $paramsM = [(int)$tenantId];
        if ($termo !== '') { $sqlM .= ' AND (m.nome LIKE ? OR m.unidade LIKE ?)'; $tiposM .= 'ss'; $paramsM[] = $like; $paramsM[] = $like; }
        if ($eu['tipo'] === 'MORADOR') { $sqlM .= ' AND m.id <> ? AND COALESCE(pref.visivel_busca, 1) = 1'; $tiposM .= 'i'; $paramsM[] = (int)$eu['id']; }
        $sqlM .= ' ORDER BY m.nome ASC LIMIT ' . $limite;
        foreach (chatin_linhas($c, $sqlM, $tiposM, $paramsM) as $m) {
            $outro = ['tipo' => 'MORADOR', 'id' => (int)$m['id']];
            if (!chatin_conversa_pode_enviar($c, $tenantId, $eu, $outro, 0)) continue;
            $unidade = trim((string)$m['unidade']);
            $resultado[] = ['tipo' => 'MORADOR', 'id' => (int)$m['id'], 'nome' => (string)$m['nome'], 'unidade' => $unidade, 'subtitulo' => $unidade !== '' ? 'Unidade ' . $unidade : 'Morador'];
        }
    }

    $verUsuarios = $eu['tipo'] === 'USUARIO' ? !empty($config['usuario_usuario']) : !empty($config['usuario_morador']);
    if ($verUsuarios) {
        $temUT = chatin_tabela_existe($c, 'usuario_tenant');
        if ($temUT) {
            $sqlU = 'SELECT u.id, u.nome, u.funcao FROM usuarios u INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = ? AND COALESCE(ut.ativo, 1) = 1 WHERE u.ativo = 1';
            $tiposU = 'i'; $paramsU = [(int)$tenantId];
        } elseif (chatin_coluna_existe($c, 'usuarios', 'tenant_id')) {
            $sqlU = 'SELECT u.id, u.nome, u.funcao FROM usuarios u WHERE u.tenant_id = ? AND u.ativo = 1';
            $tiposU = 'i'; $paramsU = [(int)$tenantId];
        } else {
            $sqlU = null; $tiposU = ''; $paramsU = [];
        }
        if ($sqlU !== null) {
            if (chatin_coluna_existe($c, 'usuarios', 'permissao')) $sqlU .= " AND LOWER(COALESCE(u.permissao, '')) <> 'super_admin'";
            if ($termo !== '') { $sqlU .= ' AND (u.nome LIKE ? OR u.funcao LIKE ?)'; $tiposU .= 'ss'; $paramsU[] = $like; $paramsU[] = $like; }
            if ($eu['tipo'] === 'USUARIO') { $sqlU .= ' AND u.id <> ?'; $tiposU .= 'i'; $paramsU[] = (int)$eu['id']; }
            $sqlU .= ' ORDER BY u.nome ASC LIMIT ' . $limite;
            foreach (chatin_linhas($c, $sqlU, $tiposU, $paramsU) as $u) {
                $outro = ['tipo' => 'USUARIO', 'id' => (int)$u['id']];
                if ($eu['tipo'] === 'MORADOR' && !chatin_usuario_visivel_para_moradores($c, $tenantId, $u['id'])) continue;
                $chave = chatin_chave_direta($eu, $outro);
                $conv = chatin_linha($c, 'SELECT id FROM chatin_conversas WHERE tenant_id = ? AND chave_unica = ? LIMIT 1', 'is', [(int)$tenantId, $chave]);
                if (!chatin_conversa_pode_enviar($c, $tenantId, $eu, $outro, (int)($conv['id'] ?? 0))) continue;
                $funcao = trim((string)($u['funcao'] ?? ''));
                $resultado[] = ['tipo' => 'USUARIO', 'id' => (int)$u['id'], 'nome' => (string)$u['nome'], 'unidade' => null, 'subtitulo' => 'Administração' . ($funcao !== '' ? ' · ' . $funcao : '')];
            }
        }
    }

    // Remove bloqueados (nos dois sentidos).
    $bloq = chatin_linhas($c, 'SELECT bloqueador_tipo, bloqueador_id, bloqueado_tipo, bloqueado_id FROM chatin_bloqueios WHERE tenant_id = ?
        AND ((bloqueador_tipo = ? AND bloqueador_id = ?) OR (bloqueado_tipo = ? AND bloqueado_id = ?))', 'isisi', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $eu['tipo'], (int)$eu['id']]);
    $ocultos = [];
    foreach ($bloq as $b) {
        if ($b['bloqueador_tipo'] === $eu['tipo'] && (int)$b['bloqueador_id'] === (int)$eu['id']) $ocultos[chatin_sigla($b['bloqueado_tipo']) . (int)$b['bloqueado_id']] = true;
        else $ocultos[chatin_sigla($b['bloqueador_tipo']) . (int)$b['bloqueador_id']] = true;
    }
    $resultado = array_values(array_filter($resultado, function ($p) use ($ocultos) { return !isset($ocultos[chatin_sigla($p['tipo']) . $p['id']]); }));
    $online = chatin_online_mapa($c, $tenantId, $resultado);
    foreach ($resultado as &$p) $p['online'] = $online[chatin_sigla($p['tipo']) . $p['id']] ?? false;
    unset($p);
    usort($resultado, function ($a, $b) {
        if ($a['online'] !== $b['online']) return $a['online'] ? -1 : 1;
        return strcasecmp($a['nome'], $b['nome']);
    });
    return array_slice($resultado, 0, $limite);
}
