<?php
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
    public function __construct($mensagem, $status = 400, $codigo = 'CHATIN_ERRO') {
        parent::__construct($mensagem);
        $this->status = (int)$status;
        $this->codigo = (string)$codigo;
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
    // SHOW TABLES (padrão do projeto): não depende de privilégio em INFORMATION_SCHEMA.
    $r = $c->query("SHOW TABLES LIKE '" . $c->real_escape_string((string)$tabela) . "'");
    return $cache[$tabela] = ($r && $r->num_rows > 0);
}

function chatin_coluna_existe(mysqli $c, $tabela, $coluna) {
    static $cache = [];
    $k = $tabela . '.' . $coluna;
    if (array_key_exists($k, $cache)) return $cache[$k];
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$tabela) || !chatin_tabela_existe($c, $tabela)) return $cache[$k] = false;
    $r = $c->query("SHOW COLUMNS FROM `" . $tabela . "` LIKE '" . $c->real_escape_string((string)$coluna) . "'");
    return $cache[$k] = ($r && $r->num_rows > 0);
}

function chatin_instalado(mysqli $c) {
    return chatin_tabela_existe($c, 'chatin_conversas') && chatin_tabela_existe($c, 'chatin_mensagens');
}

function chatin_texto($valor, $limite) {
    $valor = trim(strip_tags((string)$valor));
    $valor = preg_replace("/\r\n?/", "\n", $valor);
    $valor = preg_replace("/\n{4,}/", "\n\n\n", $valor);
    return function_exists('mb_substr') ? mb_substr($valor, 0, $limite, 'UTF-8') : substr($valor, 0, $limite);
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
        $u = chatin_tabela_existe($c, 'usuario_tenant')
            ? chatin_linha($c, 'SELECT u.id, u.nome, u.funcao FROM usuarios u INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = ? AND COALESCE(ut.ativo, 1) = 1 WHERE u.id = ? AND u.ativo = 1 LIMIT 1', 'ii', [$tenantId, $id])
            : chatin_linha($c, 'SELECT id, nome, funcao FROM usuarios WHERE id = ? AND ativo = 1 LIMIT 1', 'i', [$id]);
        if (!$u) return null;
        $funcao = trim((string)($u['funcao'] ?? ''));
        return ['tipo' => 'USUARIO', 'id' => (int)$u['id'], 'nome' => (string)$u['nome'], 'unidade' => null,
            'subtitulo' => 'Administração' . ($funcao !== '' ? ' · ' . $funcao : '')];
    }
    return null;
}

function chatin_preferencias(mysqli $c, $tenantId, $tipo, $id) {
    $p = chatin_tabela_existe($c, 'chatin_preferencias')
        ? chatin_linha($c, 'SELECT visivel_busca, mostrar_online, notificar_push FROM chatin_preferencias WHERE tenant_id = ? AND participante_tipo = ? AND participante_id = ?', 'isi', [(int)$tenantId, $tipo, (int)$id])
        : null;
    return [
        'visivel_busca' => $p ? (int)$p['visivel_busca'] === 1 : true,
        'mostrar_online' => $p ? (int)$p['mostrar_online'] === 1 : true,
        'notificar_push' => $p ? (int)$p['notificar_push'] === 1 : true,
    ];
}

function chatin_salvar_preferencias(mysqli $c, $tenantId, array $eu, array $dados) {
    $atual = chatin_preferencias($c, $tenantId, $eu['tipo'], $eu['id']);
    $vis = array_key_exists('visivel_busca', $dados) ? (!empty($dados['visivel_busca']) ? 1 : 0) : ($atual['visivel_busca'] ? 1 : 0);
    $onl = array_key_exists('mostrar_online', $dados) ? (!empty($dados['mostrar_online']) ? 1 : 0) : ($atual['mostrar_online'] ? 1 : 0);
    $psh = array_key_exists('notificar_push', $dados) ? (!empty($dados['notificar_push']) ? 1 : 0) : ($atual['notificar_push'] ? 1 : 0);
    chatin_q($c, 'INSERT INTO chatin_preferencias (tenant_id, participante_tipo, participante_id, visivel_busca, mostrar_online, notificar_push)
        VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE visivel_busca = VALUES(visivel_busca), mostrar_online = VALUES(mostrar_online), notificar_push = VALUES(notificar_push)',
        'isiiii', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $vis, $onl, $psh])->close();
    return chatin_preferencias($c, $tenantId, $eu['tipo'], $eu['id']);
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

function chatin_bloqueado(mysqli $c, $tenantId, array $a, array $b) {
    if (!in_array($a['tipo'], CHATIN_TIPOS_PESSOA, true) || !in_array($b['tipo'], CHATIN_TIPOS_PESSOA, true)) return false;
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

/** Demais participantes (pessoas) de uma conversa. */
function chatin_outros(mysqli $c, $tenantId, $conversaId, array $eu) {
    return chatin_linhas($c, 'SELECT participante_tipo AS tipo, participante_id AS id FROM chatin_participantes
        WHERE tenant_id = ? AND conversa_id = ? AND NOT (participante_tipo = ? AND participante_id = ?)',
        'iisi', [(int)$tenantId, (int)$conversaId, $eu['tipo'], (int)$eu['id']]);
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
    $part = chatin_exigir_participacao($c, $tenantId, $conversaId, $eu);
    if ($part['conversa_tipo'] !== 'DIRETA') throw new ChatInErro('Esta conversa recebe apenas avisos do condomínio.', 403, 'CONVERSA_SOMENTE_LEITURA');
    $corpo = chatin_texto($corpo, CHATIN_MAX_CARACTERES + 1);
    if ($corpo === '') throw new ChatInErro('Digite uma mensagem.', 422, 'MENSAGEM_VAZIA');
    if (chatin_tamanho($corpo) > CHATIN_MAX_CARACTERES) throw new ChatInErro('A mensagem deve ter até ' . CHATIN_MAX_CARACTERES . ' caracteres.', 422, 'MENSAGEM_LONGA');
    $idem = $idem !== null ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$idem), 0, 40) : null;
    if ($idem === '') $idem = null;
    if ($idem !== null) {
        $ja = chatin_linha($c, 'SELECT id FROM chatin_mensagens WHERE tenant_id = ? AND remetente_tipo = ? AND remetente_id = ? AND idempotency_key = ? LIMIT 1',
            'isis', [(int)$tenantId, $eu['tipo'], (int)$eu['id'], $idem]);
        if ($ja) return chatin_mensagem_publica(chatin_linha($c, 'SELECT * FROM chatin_mensagens WHERE id = ?', 'i', [(int)$ja['id']]), $eu) + ['idempotente' => true];
    }
    $outros = chatin_outros($c, $tenantId, $conversaId, $eu);
    foreach ($outros as $o) {
        if (!chatin_pessoa($c, $tenantId, $o['tipo'], $o['id'])) throw new ChatInErro('Este contato não está mais ativo no condomínio.', 410, 'CONTATO_INATIVO');
        if (chatin_bloqueado($c, $tenantId, $eu, $o)) throw new ChatInErro('Não é possível enviar mensagens para este contato.', 403, 'CONTATO_BLOQUEADO');
    }
    $recentes = chatin_linha($c, 'SELECT COUNT(*) AS n FROM chatin_mensagens WHERE tenant_id = ? AND remetente_tipo = ? AND remetente_id = ? AND criado_em >= NOW() - INTERVAL 60 SECOND',
        'isi', [(int)$tenantId, $eu['tipo'], (int)$eu['id']]);
    if ((int)($recentes['n'] ?? 0) >= CHATIN_LIMITE_MSG_POR_MINUTO) throw new ChatInErro('Muitas mensagens em pouco tempo. Aguarde alguns segundos.', 429, 'LIMITE_MENSAGENS');

    $id = chatin_inserir_mensagem($c, $tenantId, $conversaId, $eu, $corpo, 'TEXTO', null, null, null, $idem);
    foreach ($outros as $o) {
        if ($o['tipo'] === 'MORADOR') {
            $preview = function_exists('mb_substr') ? mb_substr($corpo, 0, 140, 'UTF-8') : substr($corpo, 0, 140);
            chatin_push_morador($c, $tenantId, (int)$o['id'], $eu['nome'], $preview, (int)$conversaId, 'chatin_mensagem');
        }
    }
    return chatin_mensagem_publica(chatin_linha($c, 'SELECT * FROM chatin_mensagens WHERE id = ?', 'i', [$id]), $eu);
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
    $entregues = 0; $vistos = [];
    foreach ($destinatarios as $d) {
        $tipo = $d['tipo'] ?? ''; $id = (int)($d['id'] ?? 0);
        if (!in_array($tipo, CHATIN_TIPOS_PESSOA, true) || $id <= 0) continue;
        $k = chatin_sigla($tipo) . $id;
        if (isset($vistos[$k])) continue;
        $vistos[$k] = true;
        try {
            $conversaId = chatin_obter_ou_criar_conversa($c, $tenantId, 'AVISOS', 'AVISOS|' . $k, [['tipo' => $tipo, 'id' => $id], ['tipo' => 'SISTEMA', 'id' => 0]]);
            chatin_inserir_mensagem($c, $tenantId, $conversaId, $remetente, $corpo, 'AVISO', $titulo, $refTipo, $refId, null);
            if ($tipo === 'MORADOR') chatin_push_morador($c, $tenantId, $id, $titulo !== '' ? $titulo : $remetente['nome'], $corpo, $conversaId, 'chatin_aviso');
            $entregues++;
        } catch (Throwable $e) {
            error_log('[ChatIn][Aviso] falha destinatario=' . $k . ' ' . get_class($e) . ' codigo=' . (int)$e->getCode());
        }
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
 * Push FCM para o morador (app). Respeita: preferência notificar_push, chave pwa
 * "push_chatin_ativo" do tenant e evita push se ele está com a mesma conversa aberta no app.
 */
function chatin_push_morador(mysqli $c, $tenantId, $moradorId, $titulo, $corpo, $conversaId, $tipoEvento) {
    try {
        if (!chatin_preferencias($c, $tenantId, 'MORADOR', $moradorId)['notificar_push']) return 'preferencia_desativada';
        $config = function_exists('protocolo_notificacao_config') ? protocolo_notificacao_config($c, (int)$tenantId) : [];
        if (($config['push_chatin_ativo'] ?? '1') === '0') return 'desativado';
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
    if ($ateId <= 0) {
        $u = chatin_linha($c, 'SELECT ultima_mensagem_id FROM chatin_conversas WHERE tenant_id = ? AND id = ?', 'ii', [(int)$tenantId, (int)$conversaId]);
        $ateId = (int)($u['ultima_mensagem_id'] ?? 0);
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
            m.corpo AS ult_corpo, m.titulo AS ult_titulo, m.remetente_tipo AS ult_rem_tipo, m.remetente_id AS ult_rem_id, m.remetente_nome AS ult_rem_nome, m.criado_em AS ult_em
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
        $conversas[] = [
            'id' => (int)$l['id'],
            'tipo' => $l['tipo'],
            'titulo' => $l['tipo'] === 'AVISOS' ? 'Avisos do condomínio' : ($contato['nome'] ?? 'Conversa'),
            'subtitulo' => $l['tipo'] === 'AVISOS' ? 'Portaria e administração' : ($contato['subtitulo'] ?? ''),
            'contato' => $contato,
            'somente_leitura' => $l['tipo'] !== 'DIRETA',
            'arquivada' => (int)$l['arquivado'] === 1,
            'nao_lidas' => $naoLidas[(int)$l['id']] ?? 0,
            'ultima' => $l['ult_em'] === null ? null : [
                'corpo' => function_exists('mb_substr') ? mb_substr((string)($l['ult_titulo'] ?: $l['ult_corpo']), 0, 120, 'UTF-8') : substr((string)($l['ult_titulo'] ?: $l['ult_corpo']), 0, 120),
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

/**
 * Busca de contatos. Morador vê moradores visíveis (preferência) e usuários do painel;
 * usuário do painel vê todos os moradores e usuários. Bloqueios ocultam o contato.
 */
function chatin_buscar_contatos(mysqli $c, $tenantId, array $eu, $termo, $limite = 30) {
    $termo = trim((string)$termo);
    $limite = max(1, min(50, (int)$limite));
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termo) . '%';
    $resultado = [];

    $sqlM = "SELECT m.id, m.nome, m.unidade FROM moradores m
        LEFT JOIN chatin_preferencias pref ON pref.tenant_id = m.tenant_id AND pref.participante_tipo = 'MORADOR' AND pref.participante_id = m.id
        WHERE m.tenant_id = ? AND m.ativo = 1";
    $tiposM = 'i'; $paramsM = [(int)$tenantId];
    if ($termo !== '') { $sqlM .= ' AND (m.nome LIKE ? OR m.unidade LIKE ?)'; $tiposM .= 'ss'; $paramsM[] = $like; $paramsM[] = $like; }
    if ($eu['tipo'] === 'MORADOR') { $sqlM .= ' AND m.id <> ? AND COALESCE(pref.visivel_busca, 1) = 1'; $tiposM .= 'i'; $paramsM[] = (int)$eu['id']; }
    $sqlM .= ' ORDER BY m.nome ASC LIMIT ' . $limite;
    foreach (chatin_linhas($c, $sqlM, $tiposM, $paramsM) as $m) {
        $unidade = trim((string)$m['unidade']);
        $resultado[] = ['tipo' => 'MORADOR', 'id' => (int)$m['id'], 'nome' => (string)$m['nome'], 'unidade' => $unidade, 'subtitulo' => $unidade !== '' ? 'Unidade ' . $unidade : 'Morador'];
    }

    $temUT = chatin_tabela_existe($c, 'usuario_tenant');
    $sqlU = $temUT
        ? 'SELECT u.id, u.nome, u.funcao FROM usuarios u INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = ? AND COALESCE(ut.ativo, 1) = 1 WHERE u.ativo = 1'
        : 'SELECT u.id, u.nome, u.funcao FROM usuarios u WHERE u.ativo = 1';
    $tiposU = $temUT ? 'i' : ''; $paramsU = $temUT ? [(int)$tenantId] : [];
    // Equipe da plataforma (super_admin) não aparece como contato dos condomínios.
    if (chatin_coluna_existe($c, 'usuarios', 'permissao')) $sqlU .= " AND LOWER(COALESCE(u.permissao, '')) <> 'super_admin'";
    if ($termo !== '') { $sqlU .= ' AND (u.nome LIKE ? OR u.funcao LIKE ?)'; $tiposU .= 'ss'; $paramsU[] = $like; $paramsU[] = $like; }
    if ($eu['tipo'] === 'USUARIO') { $sqlU .= ' AND u.id <> ?'; $tiposU .= 'i'; $paramsU[] = (int)$eu['id']; }
    $sqlU .= ' ORDER BY u.nome ASC LIMIT ' . $limite;
    foreach (chatin_linhas($c, $sqlU, $tiposU, $paramsU) as $u) {
        $funcao = trim((string)($u['funcao'] ?? ''));
        $resultado[] = ['tipo' => 'USUARIO', 'id' => (int)$u['id'], 'nome' => (string)$u['nome'], 'unidade' => null, 'subtitulo' => 'Administração' . ($funcao !== '' ? ' · ' . $funcao : '')];
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
