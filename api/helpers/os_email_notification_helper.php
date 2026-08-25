<?php
/**
 * Notificações por e-mail para eventos de Ordens de Serviço.
 *
 * O disparo é não bloqueante: a O.S. permanece criada/finalizada mesmo que o
 * provider de e-mail esteja indisponível. Os envios e falhas ficam registrados
 * pelo dispatcher central em email_log/email_delivery_logs.
 */
require_once __DIR__ . '/email_alertas_dispatcher.php';

if (!function_exists('os_email_destinatarios')) {
    /**
     * Resolve um morador específico quando informado; sem morador_id, resolve
     * todos os moradores ativos da unidade no tenant atual.
     */
    function os_email_destinatarios(mysqli $conexao, int $tenantId, ?int $moradorId, string $unidade): array
    {
        $destinatarios = [];
        if ($moradorId !== null && $moradorId > 0) {
            $stmt = $conexao->prepare(
                'SELECT id, nome, email FROM moradores WHERE tenant_id = ? AND id = ? AND ativo = 1 LIMIT 1'
            );
            if ($stmt) {
                $stmt->bind_param('ii', $tenantId, $moradorId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) $destinatarios[] = $row;
            }
        }
        if (!$destinatarios && trim($unidade) !== '') {
            $stmt = $conexao->prepare(
                'SELECT id, nome, email FROM moradores WHERE tenant_id = ? AND unidade = ? AND ativo = 1'
            );
            if ($stmt) {
                $unidade = trim($unidade);
                $stmt->bind_param('is', $tenantId, $unidade);
                $stmt->execute();
                $destinatarios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        }
        return $destinatarios;
    }
}

if (!function_exists('os_email_notificar_evento')) {
    /**
     * Envia o aviso correspondente ao evento da O.S. sem interromper o fluxo.
     */
    function os_email_notificar_evento(
        mysqli $conexao,
        int $tenantId,
        string $evento,
        int $osId,
        string $numero,
        string $titulo,
        ?int $moradorId,
        string $unidade,
        string $observacao = ''
    ): array {
        $destinatarios = os_email_destinatarios($conexao, $tenantId, $moradorId, $unidade);
        if (!$destinatarios) {
            error_log('[OS][Email] Nenhum morador destinatário para O.S. ' . $numero . ' (unidade ' . $unidade . ')');
            return ['enviados' => 0, 'falhas' => 0, 'motivo' => 'sem_destinatario'];
        }

        $codigo = $evento === 'fechamento' ? 'os.fechamento' : 'os.abertura';
        $totais = ['enviados' => 0, 'falhas' => 0];
        $linkLogin = 'https://app.erpcondominios.com.br/frontend/login.html';
        $variaveis = [
            'numero_os'      => htmlspecialchars($numero, ENT_QUOTES, 'UTF-8'),
            'titulo_os'      => htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8'),
            'unidade'        => htmlspecialchars($unidade !== '' ? $unidade : 'sua unidade', ENT_QUOTES, 'UTF-8'),
            'nome_morador'   => '',
            'observacao'     => htmlspecialchars($observacao, ENT_QUOTES, 'UTF-8'),
            'link_login'     => $linkLogin,
            'os_id'          => (string)$osId,
        ];

        foreach ($destinatarios as &$destinatario) {
            $destinatario['nome'] = (string)($destinatario['nome'] ?? '');
            $variaveis['nome_morador'] = htmlspecialchars($destinatario['nome'], ENT_QUOTES, 'UTF-8');
            $resultado = alerta_email_disparar(
                $conexao,
                $tenantId,
                $codigo,
                $variaveis,
                [['email' => $destinatario['email'] ?? '', 'nome' => $destinatario['nome']]]
            );
            $totais['enviados'] = ($totais['enviados'] ?? 0) + (int)($resultado['enviados'] ?? 0);
            $totais['falhas']   = ($totais['falhas'] ?? 0) + (int)($resultado['falhas'] ?? 0);
        }
        unset($destinatario);

        $totais['motivo'] = ($totais['enviados'] ?? 0) > 0 ? 'ok' : 'todas_falharam';
        error_log('[OS][Email] ' . $codigo . ' O.S. ' . $numero . ' enviados=' . ($totais['enviados'] ?? 0) . ' falhas=' . ($totais['falhas'] ?? 0));
        return $totais;
    }
}
