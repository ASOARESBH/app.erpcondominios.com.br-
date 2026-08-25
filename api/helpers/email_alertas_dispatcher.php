<?php
/**
 * Motor de disparo dos alertas automáticos (email_alertas).
 *
 * Antes deste arquivo, NADA no sistema lia `email_alertas` fora da própria
 * tela de CRUD (Configurações > E-mail e Alertas) — os 9 modelos cadastrados
 * nunca disparavam de verdade. Este helper é o único lugar que efetivamente
 * lê um alerta, renderiza o template e envia, para todos os pontos de
 * disparo do sistema (novo usuário, novo morador, leitura de hidrômetro,
 * consumo alto, contas a vencer/vencidas, visitante registrado, aniversário
 * de colaborador).
 *
 * Decisão de design: este motor NUNCA decide "para quem" enviar. Cada ponto
 * de disparo já resolve os destinatários certos (o morador da unidade, os
 * admins do tenant etc.) e passa a lista pronta — `destinatario_tipo` na
 * tabela é só metadado informativo para a tela, não é usado aqui para
 * resolver e-mails (o valor está inconsistente em alguns registros, ex.:
 * "sistema.novo_usuario" está marcado como "morador").
 */

require_once __DIR__ . '/email_config_schema.php';

if (!function_exists('alerta_email_carregar')) {
    /** Carrega (e semeia se preciso) a linha de email_alertas de um tenant. Retorna null se o código não existe no catálogo. */
    function alerta_email_carregar($conexao, int $tenantId, string $codigo): ?array {
        email_config_garantir_tabelas($conexao);

        $codigoEsc = mysqli_real_escape_string($conexao, $codigo);
        $res = mysqli_query($conexao, "SELECT * FROM email_alertas WHERE tenant_id=$tenantId AND codigo='$codigoEsc' LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($row) return $row;

        // Tenant ainda não tem a semente padrão (ex.: tenant criado antes deste
        // motor existir) — semeia agora e tenta de novo.
        email_config_seed_alertas_padrao($conexao, $tenantId);
        $res = mysqli_query($conexao, "SELECT * FROM email_alertas WHERE tenant_id=$tenantId AND codigo='$codigoEsc' LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        return $row ?: null;
    }
}

if (!function_exists('email_config_variaveis_padrao')) {
    /** Variáveis presentes em todos os templates ({{sistema_nome}}, {{logo_url}}, {{data_envio}}). */
    function email_config_variaveis_padrao($conexao, int $tenantId): array {
        $sistemaNome = 'ERP Condomínios';
        $logoUrl = '';
        $res = mysqli_query($conexao, "SELECT nome_fantasia, razao_social, logo_url FROM tenants WHERE id=$tenantId LIMIT 1");
        if ($res && ($t = mysqli_fetch_assoc($res))) {
            $sistemaNome = $t['nome_fantasia'] ?: ($t['razao_social'] ?: $sistemaNome);
            $logoUrl = $t['logo_url'] ?: '';
        }
        return [
            'sistema_nome' => $sistemaNome,
            'logo_url'     => $logoUrl,
            'data_envio'   => date('d/m/Y H:i'),
        ];
    }
}

if (!function_exists('alerta_email_renderizar')) {
    /** Substitui {{chave}} no assunto/corpo. Se corpo_html estiver vazio, monta um corpo padrão simples. */
    function alerta_email_renderizar(array $alerta, array $variaveis): array {
        $busca = [];
        $troca = [];
        foreach ($variaveis as $chave => $valor) {
            $busca[] = '{{' . $chave . '}}';
            $troca[] = (string) $valor;
        }

        $assunto = str_replace($busca, $troca, (string) ($alerta['assunto'] ?? $alerta['nome'] ?? ''));

        $corpoBruto = trim((string) ($alerta['corpo_html'] ?? ''));
        if ($corpoBruto === '') {
            $linhas = '';
            foreach ($variaveis as $chave => $valor) {
                if (in_array($chave, ['sistema_nome', 'logo_url', 'data_envio'], true)) continue;
                $linhas .= '<tr><td style="padding:6px 12px;color:#64748b;">' . htmlspecialchars((string)$chave) . '</td>'
                         . '<td style="padding:6px 12px;font-weight:600;">' . htmlspecialchars((string)$valor) . '</td></tr>';
            }
            $corpoBruto = '<div style="font-family:Arial,sans-serif;background:#f1f5f9;padding:24px">'
                . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">'
                . '<div style="background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;padding:24px;text-align:center">'
                . '<h2 style="margin:0">' . htmlspecialchars((string)($alerta['nome'] ?? 'Notificação')) . '</h2></div>'
                . '<div style="padding:24px"><table style="width:100%;border-collapse:collapse;font-size:14px">' . $linhas . '</table></div>'
                . '<div style="text-align:center;padding:16px;font-size:12px;color:#94a3b8">{{sistema_nome}} — E-mail automático, não responda.</div>'
                . '</div></div>';
            $corpoBruto = str_replace($busca, $troca, $corpoBruto);
        } else {
            $corpoBruto = str_replace($busca, $troca, $corpoBruto);
        }

        return [$assunto, $corpoBruto];
    }
}

if (!function_exists('admins_do_tenant')) {
    /** Lista nome/e-mail dos administradores/gerentes ativos de um tenant (usado por destinatario_tipo=todos_admins). */
    function admins_do_tenant($conexao, int $tenantId): array {
        $lista = [];
        $res = mysqli_query($conexao, "SELECT nome, email FROM usuarios
            WHERE tenant_id=$tenantId AND permissao IN ('admin','gerente') AND ativo=1 AND email != '' AND LOWER(COALESCE(permissao,'')) <> 'super_admin'");
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $lista[] = ['email' => $r['email'], 'nome' => $r['nome']];
            }
        }
        return $lista;
    }
}

if (!function_exists('alerta_email_disparar')) {
    /**
     * Dispara um alerta para uma lista de destinatários já resolvida pelo chamador.
     * $destinatarios = [['email'=>'x@y.com','nome'=>'Fulano'], ...]
     * Nunca lança exceção — uma falha de e-mail não pode derrubar a operação de
     * negócio que chamou o disparo (mesmo princípio já usado em outros pontos
     * de notificação do sistema).
     */
    function alerta_email_disparar($conexao, int $tenantId, string $codigo, array $variaveis, array $destinatarios): array {
        $resultado = ['disparado' => false, 'motivo' => 'nao_processado', 'enviados' => 0, 'falhas' => 0];

        if (empty($destinatarios)) {
            $resultado['motivo'] = 'sem_destinatarios';
            return $resultado;
        }

        try {
            $alerta = alerta_email_carregar($conexao, $tenantId, $codigo);
            if (!$alerta) {
                $resultado['motivo'] = 'alerta_inexistente';
                return $resultado;
            }
            if ((int) $alerta['ativo'] !== 1) {
                $resultado['motivo'] = 'desativado';
                return $resultado;
            }

            $variaveisCompletas = array_merge(email_config_variaveis_padrao($conexao, $tenantId), $variaveis);
            [$assunto, $corpo] = alerta_email_renderizar($alerta, $variaveisCompletas);

            require_once __DIR__ . '/../EmailSender.php';
            $sender = new EmailSender($conexao, false);

            foreach ($destinatarios as $dest) {
                $email = trim((string) ($dest['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                $nome = (string) ($dest['nome'] ?? '');
                try {
                    $sender->enviar($email, $assunto, $corpo, $nome, [], $codigo, $tenantId);
                    $resultado['enviados']++;
                } catch (\Throwable $e) {
                    $resultado['falhas']++;
                    error_log('[EmailAlertas] Falha ao enviar ' . $codigo . ' para ' . $email . ': ' . $e->getMessage());
                }
            }

            $resultado['disparado'] = $resultado['enviados'] > 0;
            $resultado['motivo'] = $resultado['disparado'] ? 'ok' : 'todas_falharam';
        } catch (\Throwable $e) {
            error_log('[EmailAlertas] Erro ao disparar ' . $codigo . ' (tenant ' . $tenantId . '): ' . $e->getMessage());
            $resultado['motivo'] = 'excecao';
        }

        return $resultado;
    }
}
