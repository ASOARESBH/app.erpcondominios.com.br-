<?php
/**
 * Disparo diário do alerta rh.aniversario_colaborador.
 * Não é anti-spam por registro (como o financeiro) porque é um resumo do
 * dia — se rodar mais de uma vez no mesmo dia, o mesmo resumo sai de novo,
 * o que é aceitável para um cron diário único.
 */

require_once __DIR__ . '/email_alertas_dispatcher.php';

if (!function_exists('cron_rh_processar_tenant')) {
    function cron_rh_processar_tenant($conexao, int $tenantId): array {
        $resumo = ['aniversariantes' => 0, 'disparado' => false];

        $res = mysqli_query($conexao, "
            SELECT nome, data_nascimento FROM rh_colaboradores
            WHERE tenant_id=$tenantId AND ativo=1
              AND data_nascimento IS NOT NULL
              AND MONTH(data_nascimento)=MONTH(CURDATE()) AND DAY(data_nascimento)=DAY(CURDATE())");
        if (!$res) return $resumo;

        $nomes = [];
        while ($c = mysqli_fetch_assoc($res)) $nomes[] = $c['nome'];
        if (empty($nomes)) return $resumo;

        $resumo['aniversariantes'] = count($nomes);
        $admins = admins_do_tenant($conexao, $tenantId);
        if (empty($admins)) return $resumo;

        $variaveis = ['lista_aniversariantes' => implode(', ', $nomes)];
        $r = alerta_email_disparar($conexao, $tenantId, 'rh.aniversario_colaborador', $variaveis, $admins);
        $resumo['disparado'] = $r['disparado'];
        return $resumo;
    }
}

if (!function_exists('cron_rh_processar_todos')) {
    function cron_rh_processar_todos($conexao): array {
        $resultado = ['tenants_processados' => 0, 'tenants_com_aniversariante' => 0, 'total_aniversariantes' => 0];
        $res = mysqli_query($conexao, "SELECT id FROM tenants WHERE status='ativo'");
        if (!$res) return $resultado;
        while ($t = mysqli_fetch_assoc($res)) {
            $tenantId = (int) $t['id'];
            $r = cron_rh_processar_tenant($conexao, $tenantId);
            $resultado['tenants_processados']++;
            if ($r['aniversariantes'] > 0) {
                $resultado['tenants_com_aniversariante']++;
                $resultado['total_aniversariantes'] += $r['aniversariantes'];
            }
        }
        return $resultado;
    }
}
