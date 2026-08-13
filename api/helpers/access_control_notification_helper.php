<?php
/**
 * Notificações de Controle de Acesso para veículos vinculados a moradores.
 *
 * Reutiliza o transporte FCM aprovado para protocolos, mas persiste um evento
 * próprio por veículo. Falhas de notificação nunca bloqueiam o cadastro.
 */

require_once __DIR__ . '/protocol_notification_helper.php';

if (!function_exists('controle_acesso_notificacao_coluna_existe')) {
    function controle_acesso_notificacao_coluna_existe(mysqli $conexao, string $tabela, string $coluna): bool {
        $stmt = $conexao->prepare(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        if (!$stmt) return false;
        $stmt->bind_param('ss', $tabela, $coluna);
        $stmt->execute();
        $existe = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $existe;
    }
}

if (!function_exists('controle_acesso_notificacao_coluna_permite_nulo')) {
    function controle_acesso_notificacao_coluna_permite_nulo(mysqli $conexao, string $tabela, string $coluna): bool {
        $stmt = $conexao->prepare(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        if (!$stmt) return false;
        $stmt->bind_param('ss', $tabela, $coluna);
        $stmt->execute();
        $linha = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ($linha['IS_NULLABLE'] ?? 'NO') === 'YES';
    }
}

if (!function_exists('controle_acesso_notificacao_auditoria')) {
    /**
     * Auditoria operacional sem senha, token completo ou dados de documento.
     * O arquivo permite identificar se a quebra ocorreu antes da persistência,
     * na resolução do destinatário, no token ou na resposta do FCM.
     */
    function controle_acesso_notificacao_auditoria(string $etapa, array $contexto = []): void {
        unset($contexto['fcm_token'], $contexto['authorization'], $contexto['private_key']);
        $linha = '[' . date('Y-m-d H:i:s') . '] [ACCESS_NOTIFICATION] ' . $etapa;
        if ($contexto) $linha .= ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $linha .= PHP_EOL;

        $diretorio = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($diretorio)) @mkdir($diretorio, 0750, true);
        @file_put_contents($diretorio . '/access_notification.log', $linha, FILE_APPEND | LOCK_EX);
        error_log(rtrim($linha));
    }
}

if (!function_exists('controle_acesso_notificacao_debug')) {
    function controle_acesso_notificacao_debug(string $evento, array $contexto = []): void {
        controle_acesso_notificacao_auditoria($evento, $contexto);
        protocolo_notificacao_debug('controle_acesso_' . $evento, $contexto);
    }
}

if (!function_exists('controle_acesso_criar_notificacao_veiculo')) {
    /**
     * Cria o aviso de veículo cadastrado e tenta enviá-lo aos dispositivos do
     * morador. O cadastro do veículo continua válido mesmo sem Firebase.
     */
    function controle_acesso_criar_notificacao_veiculo(
        mysqli $conexao,
        int $tenant_id,
        int $morador_id,
        int $veiculo_id,
        string $placa,
        string $modelo = ''
    ): array {
        if (!protocolo_notificacao_tabela_existe($conexao, 'notificacoes_morador') ||
            !controle_acesso_notificacao_coluna_existe($conexao, 'notificacoes_morador', 'veiculo_id') ||
            !controle_acesso_notificacao_coluna_permite_nulo($conexao, 'notificacoes_morador', 'protocolo_id')) {
            controle_acesso_notificacao_debug('migracao_pendente', [
                'tenant_id' => $tenant_id,
                'morador_id' => $morador_id,
                'veiculo_id' => $veiculo_id,
            ]);
            return ['sucesso' => false, 'motivo' => 'migracao_pendente'];
        }

        $placa = strtoupper(trim($placa));
        $modelo = trim($modelo);
        $titulo = 'Veículo cadastrado';
        $corpo = 'O veículo ' . ($placa ?: 'sem placa informada') .
            ($modelo !== '' ? ' (' . $modelo . ')' : '') .
            ' foi cadastrado para a sua unidade.';
        $tipo = 'veiculo_cadastrado';

        $stmt = $conexao->prepare(
            "INSERT INTO notificacoes_morador
             (tenant_id, morador_id, protocolo_id, veiculo_id, tipo, titulo, mensagem, descricao_mercadoria, push_status)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'pendente')
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id), atualizado_em = NOW(), push_status = 'pendente', push_detalhe = NULL"
        );
        if (!$stmt) {
            controle_acesso_notificacao_debug('insert_preparo_falhou', ['veiculo_id' => $veiculo_id]);
            return ['sucesso' => false, 'motivo' => 'insert_falhou'];
        }
        $stmt->bind_param('iiissss', $tenant_id, $morador_id, $veiculo_id, $tipo, $titulo, $corpo, $placa);
        if (!$stmt->execute()) {
            controle_acesso_notificacao_debug('insert_execucao_falhou', [
                'tenant_id' => $tenant_id,
                'morador_id' => $morador_id,
                'veiculo_id' => $veiculo_id,
                'erro' => $stmt->error,
            ]);
            $stmt->close();
            return ['sucesso' => false, 'motivo' => 'insert_falhou'];
        }
        $notificacao_id = (int)$conexao->insert_id;
        $stmt->close();

        $configuracoes = protocolo_notificacao_config($conexao, $tenant_id);
        if (($configuracoes['push_controle_acesso_ativo'] ?? '1') === '0') {
            controle_acesso_atualizar_push($conexao, $tenant_id, $notificacao_id, 'desativado', 'Alertas de Controle de Acesso desativados para o tenant.');
            return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => 'desativado'];
        }

        if (!protocolo_notificacao_tabela_existe($conexao, 'pwa_fcm_tokens')) {
            controle_acesso_atualizar_push($conexao, $tenant_id, $notificacao_id, 'sem_token', 'Nenhum dispositivo registrado para o morador.');
            return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => 'sem_token'];
        }

        $project_id = trim((string)($configuracoes['fcm_project_id'] ?? ''));
        if ($project_id === '' || strpos($project_id, 'SUBSTITUA') === 0) {
            controle_acesso_atualizar_push($conexao, $tenant_id, $notificacao_id, 'nao_configurado', 'Projeto Firebase não configurado.');
            return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => 'nao_configurado'];
        }

        $stmt_tokens = $conexao->prepare(
            'SELECT id, fcm_token FROM pwa_fcm_tokens WHERE tenant_id = ? AND morador_id = ? AND ativo = 1'
        );
        if (!$stmt_tokens) return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => 'falhou'];
        $stmt_tokens->bind_param('ii', $tenant_id, $morador_id);
        $stmt_tokens->execute();
        $tokens = $stmt_tokens->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_tokens->close();
        if (!$tokens) {
            controle_acesso_atualizar_push($conexao, $tenant_id, $notificacao_id, 'sem_token', 'Nenhum dispositivo ativo para o morador.');
            return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => 'sem_token'];
        }

        $enviados = 0;
        $falhas = 0;
        foreach ($tokens as $token) {
            $resultado = protocolo_notificacao_enviar_fcm(
                $token['fcm_token'],
                'Controle de Acesso',
                $corpo,
                [
                    'tipo' => $tipo,
                    'origem' => 'controle_acesso',
                    'notificacao_id' => $notificacao_id,
                    'veiculo_id' => $veiculo_id,
                    'rota' => '/home/notifications',
                ],
                $project_id,
                'controle_acesso'
            );
            if ($resultado['sucesso']) {
                $enviados++;
            } else {
                $falhas++;
                if ($resultado['invalido']) {
                    $stmt_desativar = $conexao->prepare('UPDATE pwa_fcm_tokens SET ativo = 0 WHERE tenant_id = ? AND id = ?');
                    if ($stmt_desativar) {
                        $stmt_desativar->bind_param('ii', $tenant_id, $token['id']);
                        $stmt_desativar->execute();
                        $stmt_desativar->close();
                    }
                }
            }
        }

        $status = $enviados > 0 ? 'enviado' : 'falhou';
        $detalhe = $enviados > 0
            ? "Push de veículo entregue a {$enviados} dispositivo(s); falhas: {$falhas}."
            : 'Não foi possível apresentar a notificação de veículo no dispositivo.';
        controle_acesso_atualizar_push($conexao, $tenant_id, $notificacao_id, $status, $detalhe);
        controle_acesso_notificacao_debug('evento_processado', [
            'tenant_id' => $tenant_id,
            'morador_id' => $morador_id,
            'veiculo_id' => $veiculo_id,
            'notificacao_id' => $notificacao_id,
            'enviados' => $enviados,
            'falhas' => $falhas,
        ]);
        return ['sucesso' => true, 'notificacao_id' => $notificacao_id, 'push' => $status];
    }
}

if (!function_exists('controle_acesso_criar_notificacao_registro')) {
    /**
     * Notifica o morador vinculado ao registro de entrada/saída. Quando a
     * origem informa apenas a unidade, todos os moradores ativos dessa unidade
     * recebem o mesmo evento, sempre filtrados pelo tenant autenticado.
     */
    function controle_acesso_criar_notificacao_registro(
        mysqli $conexao,
        int $tenant_id,
        int $registro_acesso_id,
        ?int $morador_id,
        string $unidade_destino,
        string $tipo_acesso,
        string $tipo_pessoa,
        string $placa = '',
        string $modelo = '',
        ?string $data_hora = null
    ): array {
        if (!protocolo_notificacao_tabela_existe($conexao, 'notificacoes_morador') ||
            !controle_acesso_notificacao_coluna_existe($conexao, 'notificacoes_morador', 'registro_acesso_id') ||
            !controle_acesso_notificacao_coluna_permite_nulo($conexao, 'notificacoes_morador', 'protocolo_id')) {
            controle_acesso_notificacao_debug('registro_migracao_pendente', [
                'tenant_id' => $tenant_id,
                'registro_acesso_id' => $registro_acesso_id,
            ]);
            return ['sucesso' => false, 'motivo' => 'migracao_pendente', 'destinatarios' => 0];
        }

        $destinatarios = [];
        if ($morador_id !== null && $morador_id > 0) {
            $stmt_morador = $conexao->prepare('SELECT id, unidade FROM moradores WHERE tenant_id = ? AND id = ? AND ativo = 1 LIMIT 1');
            if ($stmt_morador) {
                $stmt_morador->bind_param('ii', $tenant_id, $morador_id);
                $stmt_morador->execute();
                $resultado = $stmt_morador->get_result()->fetch_assoc();
                $stmt_morador->close();
                if ($resultado) $destinatarios[] = $resultado;
            }
        } elseif (trim($unidade_destino) !== '') {
            $stmt_unidade = $conexao->prepare('SELECT id, unidade FROM moradores WHERE tenant_id = ? AND unidade = ? AND ativo = 1');
            if ($stmt_unidade) {
                $unidade_destino = trim($unidade_destino);
                $stmt_unidade->bind_param('is', $tenant_id, $unidade_destino);
                $stmt_unidade->execute();
                $destinatarios = $stmt_unidade->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_unidade->close();
            }
        }

        if (!$destinatarios) {
            controle_acesso_notificacao_debug('registro_sem_destinatario', [
                'tenant_id' => $tenant_id,
                'registro_acesso_id' => $registro_acesso_id,
                'morador_id' => $morador_id,
                'unidade' => $unidade_destino,
            ]);
            return ['sucesso' => true, 'motivo' => 'sem_destinatario', 'destinatarios' => 0];
        }

        controle_acesso_notificacao_auditoria('destinatarios_resolvidos', [
            'registro_acesso_id' => $registro_acesso_id,
            'tenant_id' => $tenant_id,
            'morador_origem_id' => $morador_id,
            'unidade' => $unidade_destino,
            'destinatarios' => array_map(static fn($item) => (int)$item['id'], $destinatarios),
        ]);

        $entrada = trim($tipo_acesso) !== 'Saída';
        $tipo_evento = $entrada ? 'acesso_entrada' : 'acesso_saida';
        $titulo = $entrada ? 'Entrada registrada' : 'Saída registrada';
        $unidade = trim($unidade_destino) !== '' ? trim($unidade_destino) : ($destinatarios[0]['unidade'] ?? 'sua unidade');
        $placa = strtoupper(trim($placa));
        $categoria = trim($tipo_pessoa) !== '' ? trim($tipo_pessoa) : 'Acesso';
        $corpo = ($entrada ? 'Entrada' : 'Saída') . ' de ' . $categoria . ' registrada para ' . $unidade . '.';
        if ($placa !== '') $corpo .= ' Placa ' . $placa . '.';
        if (trim($modelo) !== '') $corpo .= ' Veículo: ' . trim($modelo) . '.';
        if ($data_hora) $corpo .= ' Horário: ' . date('d/m/Y H:i', strtotime($data_hora)) . '.';

        $configuracoes = protocolo_notificacao_config($conexao, $tenant_id);
        $project_id = trim((string)($configuracoes['fcm_project_id'] ?? ''));
        $permitir_push = ($configuracoes['push_controle_acesso_ativo'] ?? '1') !== '0';
        $resumo = ['sucesso' => true, 'destinatarios' => count($destinatarios), 'persistidas' => 0, 'push_enviados' => 0];

        foreach ($destinatarios as $destinatario) {
            $id_morador = (int)$destinatario['id'];
            $stmt = $conexao->prepare(
                "INSERT INTO notificacoes_morador
                 (tenant_id, morador_id, protocolo_id, registro_acesso_id, tipo, titulo, mensagem, descricao_mercadoria, push_status)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, '', 'pendente')
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), atualizado_em = NOW(), push_status = 'pendente', push_detalhe = NULL"
            );
            if (!$stmt) continue;
            $stmt->bind_param('iiisss', $tenant_id, $id_morador, $registro_acesso_id, $tipo_evento, $titulo, $corpo);
            if (!$stmt->execute()) {
                $stmt->close();
                continue;
            }
            $notificacao_id = (int)$conexao->insert_id;
            $stmt->close();
            $resumo['persistidas']++;
            controle_acesso_notificacao_auditoria('evento_persistido', [
                'notificacao_id' => $notificacao_id,
                'registro_acesso_id' => $registro_acesso_id,
                'tenant_id' => $tenant_id,
                'morador_id' => $id_morador,
                'tipo' => $tipo_evento,
            ]);

            if (!$permitir_push) {
                controle_acesso_atualizar_push($conexao, $tenant_id, $notificacao_id, 'desativado', 'Alertas de Controle de Acesso desativados para o tenant.');
                continue;
            }
            if ($project_id === '' || strpos($project_id, 'SUBSTITUA') === 0 || !protocolo_notificacao_tabela_existe($conexao, 'pwa_fcm_tokens')) {
                controle_acesso_atualizar_push($conexao, $tenant_id, $notificacao_id, 'nao_configurado', 'Firebase ou registro de dispositivo não configurado.');
                continue;
            }

            $stmt_tokens = $conexao->prepare('SELECT id, fcm_token, plataforma, device_info FROM pwa_fcm_tokens WHERE tenant_id = ? AND morador_id = ? AND ativo = 1');
            if (!$stmt_tokens) continue;
            $stmt_tokens->bind_param('ii', $tenant_id, $id_morador);
            $stmt_tokens->execute();
            $tokens = $stmt_tokens->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_tokens->close();
            if (!$tokens) {
                controle_acesso_notificacao_auditoria('token_nao_encontrado', [
                    'notificacao_id' => $notificacao_id,
                    'registro_acesso_id' => $registro_acesso_id,
                    'tenant_id' => $tenant_id,
                    'morador_id' => $id_morador,
                ]);
                controle_acesso_atualizar_push($conexao, $tenant_id, $notificacao_id, 'sem_token', 'Nenhum dispositivo ativo para o morador.');
                continue;
            }

            controle_acesso_notificacao_auditoria('tokens_encontrados', [
                'notificacao_id' => $notificacao_id,
                'registro_acesso_id' => $registro_acesso_id,
                'tenant_id' => $tenant_id,
                'morador_id' => $id_morador,
                'tokens' => array_map(static fn($token) => [
                    'token_id' => (int)$token['id'],
                    'plataforma' => $token['plataforma'] ?? 'nao_informada',
                    'device_info' => $token['device_info'] ?? '',
                    'token_final' => substr((string)$token['fcm_token'], -12),
                ], $tokens),
            ]);

            $enviados = 0;
            foreach ($tokens as $token) {
                $push = protocolo_notificacao_enviar_fcm(
                    $token['fcm_token'],
                    'Controle de Acesso',
                    $corpo,
                    [
                        'tipo' => $tipo_evento,
                        'origem' => 'controle_acesso',
                        'notificacao_id' => $notificacao_id,
                        'registro_acesso_id' => $registro_acesso_id,
                        'rota' => '/home/notifications',
                    ],
                    $project_id,
                    'controle_acesso'
                );
                controle_acesso_notificacao_auditoria('fcm_resultado', [
                    'notificacao_id' => $notificacao_id,
                    'registro_acesso_id' => $registro_acesso_id,
                    'token_id' => (int)$token['id'],
                    'token_final' => substr((string)$token['fcm_token'], -12),
                    'plataforma' => $token['plataforma'] ?? 'nao_informada',
                    'fcm_aceito' => (bool)$push['sucesso'],
                    'fcm_message_id' => $push['message_id'] ?? null,
                    'erro' => $push['erro'] ?? null,
                    'status' => $push['status'] ?? null,
                ]);
                if ($push['sucesso']) {
                    $enviados++;
                } elseif ($push['invalido']) {
                    $stmt_desativar = $conexao->prepare('UPDATE pwa_fcm_tokens SET ativo = 0 WHERE tenant_id = ? AND id = ?');
                    if ($stmt_desativar) {
                        $id_token = (int)$token['id'];
                        $stmt_desativar->bind_param('ii', $tenant_id, $id_token);
                        $stmt_desativar->execute();
                        $stmt_desativar->close();
                    }
                }
            }
            $resumo['push_enviados'] += $enviados;
            controle_acesso_atualizar_push(
                $conexao,
                $tenant_id,
                $notificacao_id,
                $enviados > 0 ? 'enviado' : 'falhou',
                $enviados > 0 ? "Push de acesso enviado a {$enviados} dispositivo(s)." : 'Não foi possível apresentar o alerta de acesso no dispositivo.'
            );
        }

        controle_acesso_notificacao_debug('registro_processado', [
            'tenant_id' => $tenant_id,
            'registro_acesso_id' => $registro_acesso_id,
            'morador_id' => $morador_id,
            'unidade' => $unidade,
            'tipo' => $tipo_evento,
            'destinatarios' => $resumo['destinatarios'],
            'persistidas' => $resumo['persistidas'],
            'push_enviados' => $resumo['push_enviados'],
        ]);
        return $resumo;
    }
}

if (!function_exists('controle_acesso_atualizar_push')) {
    function controle_acesso_atualizar_push(mysqli $conexao, int $tenant_id, int $notificacao_id, string $status, string $detalhe): void {
        $stmt = $conexao->prepare('UPDATE notificacoes_morador SET push_status = ?, push_detalhe = ? WHERE tenant_id = ? AND id = ?');
        if (!$stmt) return;
        $stmt->bind_param('ssii', $status, $detalhe, $tenant_id, $notificacao_id);
        $stmt->execute();
        $stmt->close();
    }
}
?>
