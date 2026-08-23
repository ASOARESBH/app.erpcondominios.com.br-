<?php
/**
 * RBAC HELPER — ERP CONDOMÍNIO
 *
 * Núcleo incremental de autorização multi-tenant. Este arquivo não substitui a
 * autenticação: deve ser usado após verificarAutenticacao(). Enquanto as tabelas
 * RBAC ainda não existirem, o helper mantém compatibilidade sem bloquear o ERP.
 */

if (!function_exists('rbacTabelasDisponiveis')) {
    function rbacTabelasDisponiveis($conexao) {
        static $disponivel = null;
        if ($disponivel !== null) return $disponivel;
        $resultado = $conexao->query("SHOW TABLES LIKE 'rbac_modulos'");
        $disponivel = $resultado instanceof mysqli_result && $resultado->num_rows > 0;
        if ($resultado instanceof mysqli_result) $resultado->free();
        return $disponivel;
    }

    function rbacUuidV4() {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    function rbacClienteInfo() {
        $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        if ($ip && strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
        $navegador = strpos($agent, 'Edg/') !== false ? 'Edge' : (strpos($agent, 'Chrome/') !== false ? 'Chrome' : (strpos($agent, 'Firefox/') !== false ? 'Firefox' : (strpos($agent, 'Safari/') !== false ? 'Safari' : 'Outro')));
        $sistema = strpos($agent, 'Windows') !== false ? 'Windows' : (strpos($agent, 'Android') !== false ? 'Android' : (strpos($agent, 'iPhone') !== false || strpos($agent, 'iPad') !== false ? 'iOS' : (strpos($agent, 'Linux') !== false ? 'Linux' : 'Outro')));
        $dispositivo = preg_match('/Mobile|Android|iPhone|iPad/i', $agent) ? 'Mobile' : 'Desktop';
        return ['ip' => $ip ? substr($ip, 0, 45) : null, 'user_agent' => $agent, 'navegador' => $navegador, 'sistema' => $sistema, 'dispositivo' => $dispositivo];
    }

    function rbacSanitizarDadosAuditoria($dados) {
        if (!is_array($dados)) return $dados;
        $proibidos = ['senha', 'password', 'token', 'authorization', 'access_token', 'refresh_token', 'agent_secret'];
        $resultado = [];
        foreach ($dados as $chave => $valor) {
            if (in_array(strtolower((string)$chave), $proibidos, true)) {
                $resultado[$chave] = '[PROTEGIDO]';
            } elseif (is_array($valor)) {
                $resultado[$chave] = rbacSanitizarDadosAuditoria($valor);
            } else {
                $resultado[$chave] = $valor;
            }
        }
        return $resultado;
    }

    function rbacJsonAuditoria($dados) {
        if ($dados === null || $dados === '') return null;
        if (is_string($dados)) return $dados;
        return json_encode(rbacSanitizarDadosAuditoria($dados), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    function rbacSessaoAtualId() {
        return isset($_SESSION['_rbac_sessao_id']) ? (int)$_SESSION['_rbac_sessao_id'] : null;
    }

    function rbacAuditar($conexao, array $evento) {
        if (!rbacTabelasDisponiveis($conexao)) return false;
        $temAuditoria = $conexao->query("SHOW TABLES LIKE 'rbac_auditoria'");
        $existe = $temAuditoria instanceof mysqli_result && $temAuditoria->num_rows > 0;
        if ($temAuditoria instanceof mysqli_result) $temAuditoria->free();
        if (!$existe) return false;

        $tenantId = isset($evento['tenant_id']) ? (int)$evento['tenant_id'] : (int)($_SESSION['tenant_id'] ?? 0);
        $usuarioId = isset($evento['usuario_id']) ? (int)$evento['usuario_id'] : (int)($_SESSION['usuario_id'] ?? 0);
        $usuarioNome = substr($evento['usuario_nome'] ?? ($_SESSION['usuario_nome'] ?? 'Sistema'), 0, 160);
        $cliente = rbacClienteInfo();
        $antes = rbacJsonAuditoria($evento['dados_antes'] ?? null);
        $depois = rbacJsonAuditoria($evento['dados_depois'] ?? null);
        $hashAnterior = null;
        $st = $conexao->prepare('SELECT hash_evento FROM rbac_auditoria WHERE tenant_id <=> ? ORDER BY id DESC LIMIT 1');
        if ($st) {
            $st->bind_param('i', $tenantId);
            $st->execute();
            $linha = $st->get_result()->fetch_assoc();
            $st->close();
            $hashAnterior = $linha['hash_evento'] ?? null;
        }
        $uuid = rbacUuidV4();
        $ocorrido = date('Y-m-d H:i:s');
        $conteudoHash = implode('|', [$uuid, $tenantId, $usuarioId, $evento['modulo_chave'] ?? '', $evento['submodulo_chave'] ?? '', $evento['acao'] ?? '', $evento['resultado'] ?? 'SUCESSO', $evento['status_http'] ?? 200, $antes ?? '', $depois ?? '', $ocorrido, $hashAnterior ?? 'GENESIS']);
        $hashEvento = hash('sha256', $conteudoHash);
        $grupo = substr($evento['grupo_nome'] ?? '', 0, 120);
        $sessaoId = isset($evento['sessao_id']) ? (int)$evento['sessao_id'] : rbacSessaoAtualId();
        $modulo = substr($evento['modulo_chave'] ?? '', 0, 80);
        $submodulo = substr($evento['submodulo_chave'] ?? '', 0, 80);
        $acao = substr($evento['acao'] ?? 'EVENTO', 0, 80);
        $origem = substr($evento['origem'] ?? 'ERP_WEB', 0, 80);
        $metodo = substr($evento['metodo_http'] ?? ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 10);
        $endpoint = substr($evento['endpoint'] ?? basename($_SERVER['SCRIPT_NAME'] ?? ''), 0, 255);
        $registroTipo = substr($evento['registro_tipo'] ?? '', 0, 80);
        $registroId = substr((string)($evento['registro_id'] ?? ''), 0, 80);
        $resultado = in_array(($evento['resultado'] ?? 'SUCESSO'), ['SUCESSO','NEGADO','ERRO'], true) ? $evento['resultado'] : 'ERRO';
        $status = (int)($evento['status_http'] ?? ($resultado === 'NEGADO' ? 403 : 200));
        $motivo = substr($evento['motivo'] ?? '', 0, 500);

        $sql = 'INSERT INTO rbac_auditoria (evento_uuid,tenant_id,usuario_id,usuario_nome,grupo_nome,sessao_id,modulo_chave,submodulo_chave,acao,origem,metodo_http,endpoint,registro_tipo,registro_id,resultado,status_http,ip,user_agent,dados_antes,dados_depois,motivo,hash_anterior,hash_evento,ocorrido_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $st = $conexao->prepare($sql);
        if (!$st) return false;
        $st->bind_param('siisssissssssssissssssss', $uuid, $tenantId, $usuarioId, $usuarioNome, $grupo, $sessaoId, $modulo, $submodulo, $acao, $origem, $metodo, $endpoint, $registroTipo, $registroId, $resultado, $status, $cliente['ip'], $cliente['user_agent'], $antes, $depois, $motivo, $hashAnterior, $hashEvento, $ocorrido);
        $ok = $st->execute();
        $st->close();
        return $ok;
    }

    function rbacObterRevisao($conexao, $tenantId) {
        $st = $conexao->prepare('SELECT revisao_permissoes FROM rbac_configuracoes WHERE tenant_id = ? LIMIT 1');
        if (!$st) return 0;
        $st->bind_param('i', $tenantId);
        $st->execute();
        $linha = $st->get_result()->fetch_assoc();
        $st->close();
        return (int)($linha['revisao_permissoes'] ?? 0);
    }

    function rbacInvalidarCache($conexao, $tenantId) {
        $st = $conexao->prepare('UPDATE rbac_configuracoes SET revisao_permissoes = revisao_permissoes + 1 WHERE tenant_id = ?');
        if ($st) { $st->bind_param('i', $tenantId); $st->execute(); $st->close(); }
        unset($_SESSION['_rbac_cache']);
    }

    function rbacUsuarioEhSuperAdmin() {
        return strtolower((string)($_SESSION['usuario_permissao'] ?? '')) === 'super_admin';
    }

    /**
     * Administradores de tenant (permissao='admin') têm acesso total automático,
     * igual ao super_admin — mesma regra já aplicada no modo de compatibilidade
     * (api_permissoes_modulos.php) e comunicada ao usuário na Central de Usuários.
     * Sem isto, um admin criado num tenant sem grupos RBAC herdados fica sem
     * nenhuma permissão efetiva, pois o RBAC não concede nada por padrão.
     */
    function rbacUsuarioTemAcessoTotal() {
        $permissao = strtolower((string)($_SESSION['usuario_permissao'] ?? ''));
        return in_array($permissao, ['admin', 'super_admin'], true);
    }

    function rbacObterPermissoesEfetivas($conexao, $usuarioId, $tenantId, $forcarAtualizacao = false) {
        if (!rbacTabelasDisponiveis($conexao)) return ['modo_compatibilidade' => true, 'is_super_admin' => rbacUsuarioEhSuperAdmin(), 'is_admin_total' => rbacUsuarioTemAcessoTotal(), 'permitidas' => [], 'detalhes' => []];
        $revisao = rbacObterRevisao($conexao, $tenantId);
        $cache = $_SESSION['_rbac_cache'] ?? null;
        if (!$forcarAtualizacao && is_array($cache) && (int)($cache['usuario_id'] ?? 0) === (int)$usuarioId && (int)($cache['tenant_id'] ?? 0) === (int)$tenantId && (int)($cache['revisao'] ?? -1) === $revisao) return $cache;

        $efetivas = [];
        $detalhes = [];
        $grupos = [];
        $st = $conexao->prepare('SELECT g.id,g.nome FROM rbac_usuario_grupos ug INNER JOIN rbac_grupos g ON g.id=ug.grupo_id WHERE ug.usuario_id=? AND ug.tenant_id=? AND ug.ativo=1 AND ug.removido_em IS NULL AND g.ativo=1 AND g.excluido_em IS NULL');
        if ($st) {
            $st->bind_param('ii', $usuarioId, $tenantId);
            $st->execute();
            $rs = $st->get_result();
            while ($g = $rs->fetch_assoc()) $grupos[] = $g;
            $st->close();
        }
        foreach ($grupos as $grupo) {
            $st = $conexao->prepare('SELECT m.chave,p.acao,gp.efeito,gp.escopo_dados FROM rbac_grupo_permissoes gp INNER JOIN rbac_permissoes p ON p.id=gp.permissao_id INNER JOIN rbac_modulos m ON m.id=p.modulo_id WHERE gp.grupo_id=? AND p.ativo=1 AND m.ativo=1');
            if (!$st) continue;
            $st->bind_param('i', $grupo['id']);
            $st->execute();
            $rs = $st->get_result();
            while ($linha = $rs->fetch_assoc()) {
                $chave = $linha['chave'] . '.' . $linha['acao'];
                $detalhes[$chave][] = ['origem' => 'grupo:' . $grupo['nome'], 'efeito' => $linha['efeito'], 'escopo' => $linha['escopo_dados']];
            }
            $st->close();
        }
        $st = $conexao->prepare('SELECT m.chave,p.acao,up.efeito,up.escopo_dados FROM rbac_usuario_permissoes up INNER JOIN rbac_permissoes p ON p.id=up.permissao_id INNER JOIN rbac_modulos m ON m.id=p.modulo_id WHERE up.usuario_id=? AND up.tenant_id=? AND up.revogado_em IS NULL AND p.ativo=1 AND m.ativo=1');
        if ($st) {
            $st->bind_param('ii', $usuarioId, $tenantId);
            $st->execute();
            $rs = $st->get_result();
            while ($linha = $rs->fetch_assoc()) {
                $chave = $linha['chave'] . '.' . $linha['acao'];
                $detalhes[$chave][] = ['origem' => 'individual', 'efeito' => $linha['efeito'], 'escopo' => $linha['escopo_dados']];
            }
            $st->close();
        }
        foreach ($detalhes as $chave => $origens) {
            $negado = false; $permitido = false; $escopo = 'GLOBAL';
            foreach ($origens as $origem) {
                if ($origem['efeito'] === 'NEGAR') $negado = true;
                if ($origem['efeito'] === 'PERMITIR') { $permitido = true; $escopo = $origem['escopo']; }
            }
            $efetivas[$chave] = ['permitido' => !$negado && $permitido, 'escopo' => $escopo, 'origens' => $origens];
        }
        $resultado = ['modo_compatibilidade' => false, 'is_super_admin' => rbacUsuarioEhSuperAdmin(), 'is_admin_total' => rbacUsuarioTemAcessoTotal(), 'usuario_id' => (int)$usuarioId, 'tenant_id' => (int)$tenantId, 'revisao' => $revisao, 'grupos' => $grupos, 'permitidas' => $efetivas, 'detalhes' => $detalhes];
        $_SESSION['_rbac_cache'] = $resultado;
        return $resultado;
    }

    function rbacPode($conexao, $moduloChave, $acao, $usuarioId = null, $tenantId = null) {
        $usuarioId = $usuarioId ?: (int)($_SESSION['usuario_id'] ?? 0);
        $tenantId = $tenantId ?: (int)($_SESSION['tenant_id'] ?? 0);
        if ($usuarioId <= 0 || $tenantId <= 0) return false;
        if (rbacUsuarioTemAcessoTotal()) return true;
        $efetivas = rbacObterPermissoesEfetivas($conexao, $usuarioId, $tenantId);
        if ($efetivas['modo_compatibilidade']) return true;
        $registro = $efetivas['permitidas'][$moduloChave . '.' . $acao] ?? null;
        return $registro && !empty($registro['permitido']);
    }

    /**
     * Aplica a política RBAC registrada para o endpoint e método HTTP atuais.
     * Quando não há política ainda, preserva compatibilidade durante a implantação.
     */
    function rbacExigirPoliticaApi($conexao, $acaoRequisicao = null) {
        if (!rbacTabelasDisponiveis($conexao)) return true;
        $tabela = $conexao->query("SHOW TABLES LIKE 'rbac_politicas_api'");
        $existe = $tabela instanceof mysqli_result && $tabela->num_rows > 0;
        if ($tabela instanceof mysqli_result) $tabela->free();
        if (!$existe) return true;
        $endpoint = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($acaoRequisicao === null) $acaoRequisicao = $_GET['acao'] ?? $_GET['action'] ?? $_POST['acao'] ?? $_POST['action'] ?? '';
        $acaoRequisicao = substr((string)$acaoRequisicao, 0, 80);
        $sql = "SELECT modulo_chave,acao_permissao FROM rbac_politicas_api WHERE endpoint=? AND ativo=1 AND (metodo_http IS NULL OR metodo_http='' OR metodo_http=?) AND (acao_requisicao='' OR acao_requisicao=?) ORDER BY CHAR_LENGTH(acao_requisicao) DESC, CASE WHEN metodo_http IS NULL OR metodo_http='' THEN 0 ELSE 1 END DESC LIMIT 1";
        $st = $conexao->prepare($sql);
        if (!$st) return true;
        $st->bind_param('sss', $endpoint, $metodo, $acaoRequisicao);
        $st->execute();
        $politica = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$politica) return true;
        return rbacExigir($conexao, $politica['modulo_chave'], $politica['acao_permissao'], ['submodulo_chave' => $acaoRequisicao ?: null]);
    }

    function rbacExigir($conexao, $moduloChave, $acao, array $contexto = []) {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
        if (rbacPode($conexao, $moduloChave, $acao, $usuarioId, $tenantId)) return true;
        rbacAuditar($conexao, [
            'tenant_id' => $tenantId,
            'usuario_id' => $usuarioId,
            'modulo_chave' => $moduloChave,
            'submodulo_chave' => $contexto['submodulo_chave'] ?? null,
            'acao' => 'ACESSO_NEGADO',
            'resultado' => 'NEGADO',
            'status_http' => 403,
            'registro_tipo' => $contexto['registro_tipo'] ?? null,
            'registro_id' => $contexto['registro_id'] ?? null,
            'motivo' => 'Permissão necessária: ' . $moduloChave . '.' . $acao,
            'dados_depois' => ['permissao_necessaria' => $moduloChave . '.' . $acao]
        ]);
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'mensagem' => 'Acesso não autorizado para esta operação.', 'codigo' => 'RBAC_DENIED', 'permissao_necessaria' => $moduloChave . '.' . $acao], JSON_UNESCAPED_UNICODE);
        exit;
    }
}


if (!function_exists('rbacSessaoDisponivel')) {
    function rbacSessaoDisponivel($conexao) {
        $res = $conexao->query("SHOW TABLES LIKE 'rbac_sessoes'");
        $ok = $res instanceof mysqli_result && $res->num_rows > 0;
        if ($res instanceof mysqli_result) $res->free();
        return $ok;
    }

    function rbacRegistrarOuAtualizarSessao($conexao, $usuarioId, $tenantId, $sessaoInativa = false) {
        if (!rbacTabelasDisponiveis($conexao) || !rbacSessaoDisponivel($conexao) || $usuarioId <= 0 || $tenantId <= 0) return null;
        if (session_status() === PHP_SESSION_NONE) session_start();
        $hash = hash('sha256', session_id());
        $cliente = rbacClienteInfo();
        $timeout = $sessaoInativa ? null : 480;
        $id = (int)($_SESSION['_rbac_sessao_id'] ?? 0);
        if ($id > 0) {
            $st = $conexao->prepare("UPDATE rbac_sessoes SET ultima_atividade_em=NOW() WHERE id=? AND tenant_id=? AND usuario_id=? AND status='ATIVA'");
            if ($st) { $st->bind_param('iii',$id,$tenantId,$usuarioId); $st->execute(); $ok=$st->affected_rows >= 0; $st->close(); if ($ok) return $id; }
        }
        $sql = "INSERT INTO rbac_sessoes (tenant_id,usuario_id,sessao_hash,status,sessao_inativa,timeout_minutos,ip,user_agent,dispositivo,navegador,sistema_operacional) VALUES (?,?,?,'ATIVA',?,?,?,?,?,?,?)";
        $st = $conexao->prepare($sql);
        if (!$st) return null;
        $sessaoInativaInt = $sessaoInativa ? 1 : 0;
        $st->bind_param('iisiisssss',$tenantId,$usuarioId,$hash,$sessaoInativaInt,$timeout,$cliente['ip'],$cliente['user_agent'],$cliente['dispositivo'],$cliente['navegador'],$cliente['sistema']);
        $ok = $st->execute(); $novoId = $ok ? (int)$conexao->insert_id : 0; $st->close();
        if ($novoId > 0) {
            $_SESSION['_rbac_sessao_id'] = $novoId;
            $_SESSION['_rbac_sessao_hash'] = $hash;
            rbacAuditar($conexao,['tenant_id'=>$tenantId,'usuario_id'=>$usuarioId,'sessao_id'=>$novoId,'modulo_chave'=>'seguranca','submodulo_chave'=>'sessoes','acao'=>'LOGIN','resultado'=>'SUCESSO','status_http'=>200]);
            return $novoId;
        }
        return null;
    }

    function rbacValidarSessaoAtual($conexao, $usuarioId, $tenantId) {
        if (!rbacTabelasDisponiveis($conexao) || !rbacSessaoDisponivel($conexao)) return true;
        $id=(int)($_SESSION['_rbac_sessao_id'] ?? 0);
        if ($id<=0) return rbacRegistrarOuAtualizarSessao($conexao,$usuarioId,$tenantId,!empty($_SESSION['sessao_inativa'])) !== null;
        $st=$conexao->prepare("SELECT id FROM rbac_sessoes WHERE id=? AND usuario_id=? AND tenant_id=? AND status='ATIVA' LIMIT 1");
        if (!$st) return true;
        $st->bind_param('iii',$id,$usuarioId,$tenantId); $st->execute(); $linha=$st->get_result()->fetch_assoc(); $st->close();
        if (!$linha) return false;
        $st=$conexao->prepare('UPDATE rbac_sessoes SET ultima_atividade_em=NOW() WHERE id=?');
        if ($st) { $st->bind_param('i',$id); $st->execute(); $st->close(); }
        return true;
    }

    function rbacEncerrarSessaoAtual($conexao, $motivo='LOGOUT') {
        if (!rbacSessaoDisponivel($conexao)) return false;
        $id=(int)($_SESSION['_rbac_sessao_id'] ?? 0);
        if ($id<=0) return false;
        $usuarioId=(int)($_SESSION['usuario_id'] ?? 0); $tenantId=(int)($_SESSION['tenant_id'] ?? 0);
        $st=$conexao->prepare("UPDATE rbac_sessoes SET status='ENCERRADA',encerrada_em=NOW(),motivo_encerramento=?,encerrado_por_usuario_id=? WHERE id=? AND usuario_id=? AND tenant_id=? AND status='ATIVA'");
        if (!$st) return false;
        $st->bind_param('siiii',$motivo,$usuarioId,$id,$usuarioId,$tenantId); $ok=$st->execute(); $st->close();
        if ($ok) rbacAuditar($conexao,['tenant_id'=>$tenantId,'usuario_id'=>$usuarioId,'sessao_id'=>$id,'modulo_chave'=>'seguranca','submodulo_chave'=>'sessoes','acao'=>'LOGOUT','resultado'=>'SUCESSO','status_http'=>200,'motivo'=>$motivo]);
        return $ok;
    }
}

if (!function_exists('rbacSeedGruposCompatibilidade')) {
    /**
     * Garante que um tenant possua os 4 grupos RBAC de compatibilidade
     * (compat-visualizador/operador/gerente/admin) usados como vínculo padrão
     * ao criar usuários (ver api_usuarios.php). A migração original só criou
     * estes grupos para os tenants existentes na época; tenants criados depois
     * ficavam sem nenhum grupo, e um usuário 'admin' sem grupo nem permissão
     * individual não tinha nenhum acesso efetivo sob RBAC. Chamar isto ao
     * criar um tenant (api_tenants.php) evita repetir o problema.
     */
    function rbacSeedGruposCompatibilidade($conexao, $tenantId) {
        if (!rbacTabelasDisponiveis($conexao)) return;
        $tenantId = (int)$tenantId;
        if ($tenantId <= 0) return;

        $grupos = [
            'compat-visualizador' => 'Visualizador (compatibilidade)',
            'compat-operador'     => 'Operador (compatibilidade)',
            'compat-gerente'      => 'Gerente (compatibilidade)',
            'compat-admin'        => 'Administrador (compatibilidade)',
        ];
        foreach ($grupos as $slug => $nome) {
            $descricao = 'Grupo criado a partir do perfil legado ' . substr($slug, 7);
            $st = $conexao->prepare('INSERT IGNORE INTO rbac_grupos (tenant_id,slug,nome,descricao,ativo,protegido) VALUES (?,?,?,?,1,1)');
            if (!$st) continue;
            $st->bind_param('isss', $tenantId, $slug, $nome, $descricao);
            $st->execute();
            $st->close();
        }

        // compat-admin recebe todas as permissões do catálogo (acesso total).
        $conexao->query("INSERT IGNORE INTO rbac_grupo_permissoes (grupo_id,permissao_id,efeito,escopo_dados)
            SELECT g.id, p.id, 'PERMITIR', 'GLOBAL'
            FROM rbac_grupos g CROSS JOIN rbac_permissoes p
            WHERE g.tenant_id = $tenantId AND g.slug = 'compat-admin' AND g.ativo = 1");

        // compat-gerente: ações operacionais em módulos até o nível gerente.
        $conexao->query("INSERT IGNORE INTO rbac_grupo_permissoes (grupo_id,permissao_id,efeito,escopo_dados)
            SELECT g.id, p.id, 'PERMITIR', 'GLOBAL'
            FROM rbac_grupos g
            INNER JOIN rbac_permissoes p ON p.acao IN ('visualizar','criar','editar','exportar','imprimir')
            INNER JOIN rbac_modulos m ON m.id = p.modulo_id
            WHERE g.tenant_id = $tenantId AND g.slug = 'compat-gerente' AND m.perfil_compatibilidade IN ('visualizador','operador','gerente')");
        $conexao->query("INSERT IGNORE INTO rbac_grupo_permissoes (grupo_id,permissao_id,efeito,escopo_dados)
            SELECT g.id, p.id, 'PERMITIR', 'GLOBAL'
            FROM rbac_grupos g
            INNER JOIN rbac_modulos m ON m.chave = 'usuarios'
            INNER JOIN rbac_permissoes p ON p.modulo_id = m.id AND p.acao = 'visualizar'
            WHERE g.tenant_id = $tenantId AND g.slug = 'compat-gerente'");

        // compat-operador: ações básicas em módulos até o nível operador.
        $conexao->query("INSERT IGNORE INTO rbac_grupo_permissoes (grupo_id,permissao_id,efeito,escopo_dados)
            SELECT g.id, p.id, 'PERMITIR', 'GLOBAL'
            FROM rbac_grupos g
            INNER JOIN rbac_permissoes p ON p.acao IN ('visualizar','criar','editar','exportar','imprimir')
            INNER JOIN rbac_modulos m ON m.id = p.modulo_id
            WHERE g.tenant_id = $tenantId AND g.slug = 'compat-operador' AND m.perfil_compatibilidade IN ('visualizador','operador')");

        // compat-visualizador: apenas leitura em módulos de nível visualizador.
        $conexao->query("INSERT IGNORE INTO rbac_grupo_permissoes (grupo_id,permissao_id,efeito,escopo_dados)
            SELECT g.id, p.id, 'PERMITIR', 'GLOBAL'
            FROM rbac_grupos g
            INNER JOIN rbac_permissoes p ON p.acao = 'visualizar'
            INNER JOIN rbac_modulos m ON m.id = p.modulo_id
            WHERE g.tenant_id = $tenantId AND g.slug = 'compat-visualizador' AND m.perfil_compatibilidade = 'visualizador'");

        $conexao->query("INSERT IGNORE INTO rbac_configuracoes (tenant_id, revisao_permissoes) VALUES ($tenantId, 1)");
    }
}
