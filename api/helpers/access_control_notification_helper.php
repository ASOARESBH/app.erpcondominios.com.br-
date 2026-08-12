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

if (!function_exists('controle_acesso_notificacao_debug')) {
    function controle_acesso_notificacao_debug(string $evento, array $contexto = []): void {
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
            !controle_acesso_notificacao_coluna_existe($conexao, 'notificacoes_morador', 'veiculo_id')) {
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
