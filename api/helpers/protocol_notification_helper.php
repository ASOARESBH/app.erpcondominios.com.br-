<?php
/**
 * Notificações de encomendas do Portal do Morador.
 *
 * Este arquivo é chamado pelo módulo administrativo de Protocolos. Ele cria
 * primeiro um evento persistente para o morador e tenta enviar push apenas
 * como complemento. Uma indisponibilidade do Firebase não pode impedir o
 * registro do protocolo nem a confirmação da entrega.
 */

if (!function_exists('protocolo_notificacao_debug')) {
    function protocolo_notificacao_debug($evento, array $contexto = []) {
        $dados = $contexto ? ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE) : '';
        error_log('[NotificacaoEncomenda] ' . $evento . $dados);
    }
}

if (!function_exists('protocolo_notificacao_tabela_existe')) {
    function protocolo_notificacao_tabela_existe(mysqli $conexao, $tabela) {
        $tabela_segura = $conexao->real_escape_string($tabela);
        $resultado = $conexao->query("SHOW TABLES LIKE '{$tabela_segura}'");
        return $resultado && $resultado->num_rows > 0;
    }
}

if (!function_exists('protocolo_notificacao_config')) {
    function protocolo_notificacao_config(mysqli $conexao, int $tenant_id) {
        if (!protocolo_notificacao_tabela_existe($conexao, 'pwa_configuracoes')) {
            return [];
        }

        $configuracoes = [];
        $stmt = $conexao->prepare('SELECT chave, valor FROM pwa_configuracoes WHERE tenant_id = ?');
        if (!$stmt) return $configuracoes;
        $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($linha = $resultado->fetch_assoc()) {
            $configuracoes[$linha['chave']] = $linha['valor'];
        }
        $stmt->close();
        return $configuracoes;
    }
}

if (!function_exists('protocolo_notificacao_token_oauth')) {
    /**
     * Obtém um token OAuth2 temporário para a API HTTP v1 do FCM sem expor
     * credenciais ao aplicativo. O arquivo da conta de serviço deve ficar
     * fora da área pública, em config/firebase/service-account.json.
     */
    function protocolo_notificacao_token_oauth() {
        static $token_cache = null;
        static $expira_em = 0;
        if ($token_cache && $expira_em > time() + 120) {
            return $token_cache;
        }

        $arquivo_conta = dirname(__DIR__, 2) . '/config/firebase/service-account.json';
        if (!is_file($arquivo_conta) || !is_readable($arquivo_conta)) {
            protocolo_notificacao_debug('firebase_service_account_ausente');
            return null;
        }

        $conta = json_decode(file_get_contents($arquivo_conta), true);
        if (empty($conta['client_email']) || empty($conta['private_key'])) {
            protocolo_notificacao_debug('firebase_service_account_invalida');
            return null;
        }

        $base64url = function ($valor) {
            return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
        };
        $agora = time();
        $cabecalho = $base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $base64url(json_encode([
            'iss' => $conta['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $agora,
            'exp' => $agora + 3600,
        ]));
        $assinatura_base = $cabecalho . '.' . $payload;
        $chave = openssl_pkey_get_private($conta['private_key']);
        if (!$chave || !openssl_sign($assinatura_base, $assinatura, $chave, OPENSSL_ALGO_SHA256)) {
            protocolo_notificacao_debug('firebase_assinatura_falhou');
            return null;
        }

        $jwt = $assinatura_base . '.' . $base64url($assinatura);
        $curl = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
        ]);
        $resposta = curl_exec($curl);
        $erro = curl_error($curl);
        curl_close($curl);
        if ($erro) {
            protocolo_notificacao_debug('firebase_oauth_erro', ['erro' => $erro]);
            return null;
        }

        $dados = json_decode($resposta, true);
        if (empty($dados['access_token'])) {
            protocolo_notificacao_debug('firebase_oauth_sem_token');
            return null;
        }

        $token_cache = $dados['access_token'];
        $expira_em = $agora + (int)($dados['expires_in'] ?? 3600);
        return $token_cache;
    }
}

if (!function_exists('protocolo_notificacao_enviar_fcm')) {
    function protocolo_notificacao_enviar_fcm($token_dispositivo, $titulo, $corpo, array $dados, $project_id) {
        $oauth = protocolo_notificacao_token_oauth();
        if (!$oauth) {
            return ['sucesso' => false, 'invalido' => false, 'erro' => 'firebase_nao_configurado'];
        }

        $dados_string = [];
        foreach ($dados as $chave => $valor) {
            if ($valor !== null && $valor !== '') $dados_string[$chave] = (string)$valor;
        }

        $payload = [
            'message' => [
                'token' => $token_dispositivo,
                'notification' => [
                    'title' => $titulo,
                    'body' => $corpo,
                ],
                'data' => $dados_string,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'encomendas',
                        'sound' => 'default',
                        'notification_priority' => 'PRIORITY_HIGH',
                    ],
                ],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
            ],
        ];

        $curl = curl_init('https://fcm.googleapis.com/v1/projects/' . rawurlencode($project_id) . '/messages:send');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $oauth,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $resposta = curl_exec($curl);
        $codigo_http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $erro_curl = curl_error($curl);
        curl_close($curl);

        if ($erro_curl) {
            return ['sucesso' => false, 'invalido' => false, 'erro' => 'curl: ' . $erro_curl];
        }
        $dados_resposta = json_decode($resposta, true);
        if ($codigo_http === 200 && !empty($dados_resposta['name'])) {
            return ['sucesso' => true, 'invalido' => false];
        }
        $status = $dados_resposta['error']['status'] ?? '';
        return [
            'sucesso' => false,
            'invalido' => in_array($status, ['UNREGISTERED', 'INVALID_ARGUMENT'], true),
            'erro' => $dados_resposta['error']['message'] ?? 'HTTP ' . $codigo_http,
        ];
    }
}

if (!function_exists('protocolo_criar_notificacao_morador')) {
    /**
     * Cria um evento de encomenda e tenta apresentá-lo no display dos
     * dispositivos autorizados pelo morador.
     *
     * @return array Resultado persistido, usado exclusivamente em logs de debug.
     */
    function protocolo_criar_notificacao_morador(
        mysqli $conexao,
        int $tenant_id,
        int $morador_id,
        int $protocolo_id,
        string $tipo,
        string $descricao_mercadoria,
        ?string $nome_recebedor = null
    ) {
        if (!protocolo_notificacao_tabela_existe($conexao, 'notificacoes_morador')) {
            protocolo_notificacao_debug('tabela_notificacoes_morador_ausente', ['protocolo_id' => $protocolo_id]);
            return ['sucesso' => false, 'motivo' => 'tabela_ausente'];
        }

        $tipo = $tipo === 'mercadoria_entregue' ? 'mercadoria_entregue' : 'mercadoria_chegou';
        $titulo = $tipo === 'mercadoria_chegou' ? 'Sua encomenda chegou' : 'Mercadoria recebida';
        $corpo = $tipo === 'mercadoria_chegou'
            ? 'Descrição da mercadoria: ' . $descricao_mercadoria
            : 'Descrição da mercadoria: ' . $descricao_mercadoria . '. Recebida por: ' . ($nome_recebedor ?: 'Não informado') . '.';

        // A chave única por tenant/protocolo/evento evita duplicidade em retentativas.
        $stmt = $conexao->prepare(
            "INSERT INTO notificacoes_morador
             (tenant_id, morador_id, protocolo_id, tipo, titulo, mensagem, descricao_mercadoria, nome_recebedor, push_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendente')
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), atualizado_em = NOW()"
        );
        if (!$stmt) {
            protocolo_notificacao_debug('insert_evento_falhou', ['erro' => $conexao->error, 'protocolo_id' => $protocolo_id]);
            return ['sucesso' => false, 'motivo' => 'insert_falhou'];
        }
        $stmt->bind_param('iiisssss', $tenant_id, $morador_id, $protocolo_id, $tipo, $titulo, $corpo, $descricao_mercadoria, $nome_recebedor);
        if (!$stmt->execute()) {
            protocolo_notificacao_debug('insert_evento_execucao_falhou', ['erro' => $stmt->error, 'protocolo_id' => $protocolo_id]);
            $stmt->close();
            return ['sucesso' => false, 'motivo' => 'insert_falhou'];
        }
        $notificacao_id = (int)$conexao->insert_id;
        $stmt->close();

        $configuracoes = protocolo_notificacao_config($conexao, $tenant_id);
        if (($configuracoes['push_encomenda_ativo'] ?? '1') === '0') {
            $stmt = $conexao->prepare("UPDATE notificacoes_morador SET push_status = 'desativado' WHERE tenant_id = ? AND id = ?");
            $stmt->bind_param('ii', $tenant_id, $notificacao_id);
            $stmt->execute();
            $stmt->close();
            return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => 'desativado'];
        }

        if (!protocolo_notificacao_tabela_existe($conexao, 'pwa_fcm_tokens')) {
            $stmt = $conexao->prepare("UPDATE notificacoes_morador SET push_status = 'sem_token' WHERE tenant_id = ? AND id = ?");
            $stmt->bind_param('ii', $tenant_id, $notificacao_id);
            $stmt->execute();
            $stmt->close();
            return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => 'sem_token'];
        }

        $project_id = trim((string)($configuracoes['fcm_project_id'] ?? ''));
        if ($project_id === '' || strpos($project_id, 'SUBSTITUA') === 0) {
            $stmt = $conexao->prepare("UPDATE notificacoes_morador SET push_status = 'nao_configurado' WHERE tenant_id = ? AND id = ?");
            $stmt->bind_param('ii', $tenant_id, $notificacao_id);
            $stmt->execute();
            $stmt->close();
            return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => 'nao_configurado'];
        }

        $stmt_tokens = $conexao->prepare(
            'SELECT id, fcm_token FROM pwa_fcm_tokens WHERE tenant_id = ? AND morador_id = ? AND ativo = 1'
        );
        $stmt_tokens->bind_param('ii', $tenant_id, $morador_id);
        $stmt_tokens->execute();
        $tokens = $stmt_tokens->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_tokens->close();

        if (!$tokens) {
            $stmt = $conexao->prepare("UPDATE notificacoes_morador SET push_status = 'sem_token' WHERE tenant_id = ? AND id = ?");
            $stmt->bind_param('ii', $tenant_id, $notificacao_id);
            $stmt->execute();
            $stmt->close();
            return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => 'sem_token'];
        }

        $enviados = 0;
        $falhas = 0;
        foreach ($tokens as $token) {
            $resultado = protocolo_notificacao_enviar_fcm(
                $token['fcm_token'],
                $titulo,
                $corpo,
                [
                    'tipo' => $tipo,
                    'notificacao_id' => $notificacao_id,
                    'protocolo_id' => $protocolo_id,
                    'rota' => '/home/notifications',
                ],
                $project_id
            );
            if ($resultado['sucesso']) {
                $enviados++;
            } else {
                $falhas++;
                if ($resultado['invalido']) {
                    $stmt_desativar = $conexao->prepare('UPDATE pwa_fcm_tokens SET ativo = 0 WHERE tenant_id = ? AND id = ?');
                    $stmt_desativar->bind_param('ii', $tenant_id, $token['id']);
                    $stmt_desativar->execute();
                    $stmt_desativar->close();
                }
            }
        }

        $status = $enviados > 0 ? 'enviado' : 'falhou';
        $detalhe = $enviados > 0
            ? "Push entregue a {$enviados} dispositivo(s); falhas: {$falhas}."
            : 'Não foi possível apresentar a notificação no dispositivo.';
        $stmt = $conexao->prepare('UPDATE notificacoes_morador SET push_status = ?, push_detalhe = ? WHERE tenant_id = ? AND id = ?');
        $stmt->bind_param('ssii', $status, $detalhe, $tenant_id, $notificacao_id);
        $stmt->execute();
        $stmt->close();

        protocolo_notificacao_debug('evento_processado', [
            'tenant_id' => $tenant_id,
            'morador_id' => $morador_id,
            'protocolo_id' => $protocolo_id,
            'tipo' => $tipo,
            'enviados' => $enviados,
            'falhas' => $falhas,
        ]);

        return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => $status];
    }
}
?>
