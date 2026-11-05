<?php
/**
 * ERP CONDOMÍNIOS — CANAIS DE NOTIFICAÇÃO POR EVENTO
 *
 * Catálogo de eventos notificáveis e dos canais de entrega, configurável por condomínio
 * em Sistema > Controle de Acesso (tabela notificacao_canais_config).
 *
 * Canais disponíveis: EMAIL, APP (push FCM), CHATIN.
 * Canais preparados para integração futura: WHATSAPP, SMS — para ativar um provedor,
 * registre-o com notif_canais_registrar_provedor('WHATSAPP', function (...) {...})
 * num helper próprio e marque o canal como disponível no catálogo.
 */

if (!function_exists('notif_canais_catalogo')) {
    function notif_canais_catalogo() {
        return [
            'canais' => [
                'EMAIL'    => ['rotulo' => 'E-mail', 'icone' => 'fa-envelope', 'disponivel' => true,
                               'descricao' => 'Envia e-mail ao morador (modelo "acesso.visitante_registrado" em Alertas por E-mail).'],
                'APP'      => ['rotulo' => 'Aplicativo (push)', 'icone' => 'fa-mobile-alt', 'disponivel' => true,
                               'descricao' => 'Notificação no celular do morador e na caixa de avisos do app/portal.'],
                'CHATIN'   => ['rotulo' => 'Chat-In', 'icone' => 'fa-comments', 'disponivel' => true,
                               'descricao' => 'Mensagem "Seu visitante chegou" em Avisos do condomínio no Chat-In (portal e app).'],
                'WHATSAPP' => ['rotulo' => 'WhatsApp', 'icone' => 'fa-whatsapp', 'icone_marca' => true, 'disponivel' => false,
                               'descricao' => 'Em breve: requer integração com um provedor de WhatsApp Business.'],
                'SMS'      => ['rotulo' => 'SMS', 'icone' => 'fa-sms', 'disponivel' => false,
                               'descricao' => 'Em breve: requer integração com um provedor de SMS.'],
            ],
            'eventos' => [
                'acesso.visitante_chegou' => [
                    'grupo' => 'Controle de Acesso',
                    'rotulo' => 'Chegada de visitante',
                    'descricao' => 'Quando a portaria registra a ENTRADA de visitante, prestador, entregador ou familiar para a unidade.',
                    'padrao' => ['EMAIL' => 1, 'APP' => 1, 'CHATIN' => 0, 'WHATSAPP' => 0, 'SMS' => 0],
                ],
            ],
        ];
    }

    function notif_canais_tabela_existe(mysqli $c) {
        static $existe = null;
        if ($existe !== null) return $existe;
        $r = $c->query("SHOW TABLES LIKE 'notificacao_canais_config'");
        $existe = $r && $r->num_rows > 0;
        return $existe;
    }

    /** Mapa canal => bool para um evento (padrões do catálogo quando não configurado). */
    function notif_canais_do_evento(mysqli $c, $tenantId, $evento) {
        $cat = notif_canais_catalogo();
        $padrao = $cat['eventos'][$evento]['padrao'] ?? [];
        $mapa = [];
        foreach ($cat['canais'] as $canal => $meta) $mapa[$canal] = !empty($padrao[$canal]) && $meta['disponivel'];
        if (!notif_canais_tabela_existe($c)) return $mapa;
        $stmt = $c->prepare('SELECT canal, ativo FROM notificacao_canais_config WHERE tenant_id = ? AND evento = ?');
        if (!$stmt) return $mapa;
        $tenantId = (int)$tenantId;
        $stmt->bind_param('is', $tenantId, $evento);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $l) {
            if (isset($mapa[$l['canal']])) $mapa[$l['canal']] = (int)$l['ativo'] === 1 && $cat['canais'][$l['canal']]['disponivel'];
        }
        $stmt->close();
        return $mapa;
    }

    function notif_canal_ativo(mysqli $c, $tenantId, $evento, $canal) {
        $mapa = notif_canais_do_evento($c, $tenantId, $evento);
        return !empty($mapa[$canal]);
    }

    /** Configuração completa para a tela Sistema > Controle de Acesso. */
    function notif_canais_config_tela(mysqli $c, $tenantId) {
        $cat = notif_canais_catalogo();
        $eventos = [];
        foreach ($cat['eventos'] as $chave => $meta) {
            $eventos[] = $meta + ['chave' => $chave, 'canais_ativos' => notif_canais_do_evento($c, $tenantId, $chave)];
        }
        $canais = [];
        foreach ($cat['canais'] as $chave => $meta) $canais[] = $meta + ['chave' => $chave];
        return ['instalado' => notif_canais_tabela_existe($c), 'canais' => $canais, 'eventos' => $eventos];
    }

    function notif_canais_salvar(mysqli $c, $tenantId, $evento, array $canais, $usuarioId) {
        $cat = notif_canais_catalogo();
        if (!isset($cat['eventos'][$evento])) throw new InvalidArgumentException('Evento de notificação desconhecido.');
        if (!notif_canais_tabela_existe($c)) throw new RuntimeException('Tabela notificacao_canais_config ausente (rode a migration do Chat-In).');
        $stmt = $c->prepare('INSERT INTO notificacao_canais_config (tenant_id, evento, canal, ativo, atualizado_por) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE ativo = VALUES(ativo), atualizado_por = VALUES(atualizado_por)');
        if (!$stmt) throw new RuntimeException('Falha ao preparar gravação dos canais.');
        foreach ($cat['canais'] as $canal => $meta) {
            if (!array_key_exists($canal, $canais)) continue;
            $ativo = (!empty($canais[$canal]) && $meta['disponivel']) ? 1 : 0; // canal indisponível nunca é ativado
            $t = (int)$tenantId; $u = (int)$usuarioId;
            $stmt->bind_param('issii', $t, $evento, $canal, $ativo, $u);
            $stmt->execute();
        }
        $stmt->close();
        return notif_canais_do_evento($c, $tenantId, $evento);
    }

    // ── Provedores externos (WhatsApp, SMS…) ─────────────────────────────────
    $GLOBALS['__notif_canais_provedores'] = $GLOBALS['__notif_canais_provedores'] ?? [];

    /**
     * Registra um provedor para um canal externo.
     * $enviar = function (mysqli $c, int $tenantId, string $evento, array $destinatarios, array $mensagem): int
     *   $destinatarios: [['tipo'=>'MORADOR','id'=>..], ...]; $mensagem: ['titulo','corpo','referencia_tipo','referencia_id']
     */
    function notif_canais_registrar_provedor($canal, callable $enviar) {
        $GLOBALS['__notif_canais_provedores'][strtoupper($canal)] = $enviar;
    }

    /** Dispara canais externos ativos que tenham provedor registrado. Nunca interrompe o fluxo chamador. */
    function notif_canais_disparar_externos(mysqli $c, $tenantId, $evento, array $destinatarios, array $mensagem) {
        $resumo = [];
        foreach (['WHATSAPP', 'SMS'] as $canal) {
            if (!notif_canal_ativo($c, $tenantId, $evento, $canal)) continue;
            $prov = $GLOBALS['__notif_canais_provedores'][$canal] ?? null;
            if (!$prov) { $resumo[$canal] = 'sem_provedor'; continue; }
            try { $resumo[$canal] = (int)call_user_func($prov, $c, (int)$tenantId, $evento, $destinatarios, $mensagem); }
            catch (Throwable $e) { $resumo[$canal] = 'erro'; error_log('[NotifCanais][' . $canal . '] ' . get_class($e)); }
        }
        return $resumo;
    }
}
