<?php
/**
 * Disparo diário dos alertas financeiro.conta_vencendo / financeiro.conta_vencida.
 *
 * Escopo: contas_pagar apenas (variáveis do template usam "fornecedor" —
 * é sobre o que o condomínio deve pagar, não inadimplência de morador).
 *
 * Anti-spam: cada conta só dispara "vencendo" uma vez e "vencida" uma vez —
 * controlado pelas colunas alerta_vencimento_enviado_em/alerta_vencida_enviado_em,
 * carimbadas na primeira vez que o alerta sai. Sem isso, uma conta vencida
 * disparia todo dia, para sempre.
 */

require_once __DIR__ . '/email_alertas_dispatcher.php';

if (!function_exists('_cron_financeiro_garantir_colunas')) {
    function _cron_financeiro_garantir_colunas($conexao) {
        $cols = [];
        $res = mysqli_query($conexao, "DESCRIBE contas_pagar");
        if ($res) while ($r = mysqli_fetch_assoc($res)) $cols[] = $r['Field'];
        if (!in_array('alerta_vencimento_enviado_em', $cols, true)) {
            mysqli_query($conexao, "ALTER TABLE contas_pagar ADD COLUMN `alerta_vencimento_enviado_em` DATETIME NULL DEFAULT NULL");
        }
        if (!in_array('alerta_vencida_enviado_em', $cols, true)) {
            mysqli_query($conexao, "ALTER TABLE contas_pagar ADD COLUMN `alerta_vencida_enviado_em` DATETIME NULL DEFAULT NULL");
        }
    }
}

if (!function_exists('cron_financeiro_processar_tenant')) {
    /** Processa um único tenant. Retorna um resumo do que foi disparado. */
    function cron_financeiro_processar_tenant($conexao, int $tenantId, int $diasJanela = 3): array {
        _cron_financeiro_garantir_colunas($conexao);
        $resumo = ['vencendo' => 0, 'vencida' => 0];

        $admins = admins_do_tenant($conexao, $tenantId);
        if (empty($admins)) return $resumo;

        // ── Contas a vencer nos próximos N dias, ainda não avisadas ────────
        $resV = mysqli_query($conexao, "
            SELECT id, fornecedor_nome, descricao, data_vencimento,
                   COALESCE(saldo_devedor, valor_original - COALESCE(valor_pago,0)) AS valor,
                   DATEDIFF(data_vencimento, CURDATE()) AS dias_para_vencer
            FROM contas_pagar
            WHERE tenant_id=$tenantId AND ativo=1 AND status IN ('PENDENTE','PARCIAL')
              AND alerta_vencimento_enviado_em IS NULL
              AND data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $diasJanela DAY)");
        if ($resV) {
            while ($conta = mysqli_fetch_assoc($resV)) {
                $variaveis = [
                    'fornecedor'        => $conta['fornecedor_nome'],
                    'descricao'         => $conta['descricao'],
                    'data_vencimento'   => date('d/m/Y', strtotime($conta['data_vencimento'])),
                    'valor'             => number_format((float) $conta['valor'], 2, ',', '.'),
                    'dias_para_vencer'  => (int) $conta['dias_para_vencer'],
                ];
                $r = alerta_email_disparar($conexao, $tenantId, 'financeiro.conta_vencendo', $variaveis, $admins);
                mysqli_query($conexao, "UPDATE contas_pagar SET alerta_vencimento_enviado_em=NOW() WHERE tenant_id=$tenantId AND id=" . (int) $conta['id']);
                if ($r['disparado']) $resumo['vencendo']++;
            }
        }

        // ── Contas já vencidas e ainda não avisadas ─────────────────────────
        $resD = mysqli_query($conexao, "
            SELECT id, fornecedor_nome, descricao, data_vencimento,
                   COALESCE(saldo_devedor, valor_original - COALESCE(valor_pago,0)) AS valor
            FROM contas_pagar
            WHERE tenant_id=$tenantId AND ativo=1 AND status IN ('PENDENTE','PARCIAL')
              AND alerta_vencida_enviado_em IS NULL
              AND data_vencimento < CURDATE()");
        if ($resD) {
            while ($conta = mysqli_fetch_assoc($resD)) {
                $variaveis = [
                    'fornecedor'      => $conta['fornecedor_nome'],
                    'descricao'       => $conta['descricao'],
                    'data_vencimento' => date('d/m/Y', strtotime($conta['data_vencimento'])),
                    'valor'           => number_format((float) $conta['valor'], 2, ',', '.'),
                ];
                $r = alerta_email_disparar($conexao, $tenantId, 'financeiro.conta_vencida', $variaveis, $admins);
                mysqli_query($conexao, "UPDATE contas_pagar SET alerta_vencida_enviado_em=NOW() WHERE tenant_id=$tenantId AND id=" . (int) $conta['id']);
                if ($r['disparado']) $resumo['vencida']++;
            }
        }

        return $resumo;
    }
}

if (!function_exists('cron_financeiro_processar_todos')) {
    /** Roda o processamento para todos os tenants ativos. */
    function cron_financeiro_processar_todos($conexao): array {
        $resultado = ['tenants_processados' => 0, 'vencendo' => 0, 'vencida' => 0];
        $res = mysqli_query($conexao, "SELECT id FROM tenants WHERE status='ativo'");
        if (!$res) return $resultado;
        while ($t = mysqli_fetch_assoc($res)) {
            $tenantId = (int) $t['id'];
            $r = cron_financeiro_processar_tenant($conexao, $tenantId);
            $resultado['tenants_processados']++;
            $resultado['vencendo'] += $r['vencendo'];
            $resultado['vencida']  += $r['vencida'];
        }
        return $resultado;
    }
}
