<?php
/**
 * ERP Condomínios — regras compartilhadas de ronda.
 *
 * Não produz saída e não acessa sessão. As APIs administrativa e móvel devem
 * chamar estas funções para manter idênticos o ciclo, SLA e dias ativos.
 */

if (!function_exists('rv_dias_normalizar')) {
    function rv_dias_normalizar($dias): string {
        if (is_string($dias)) {
            $dias = preg_split('/\s*,\s*/', trim($dias));
        }
        if (!is_array($dias)) {
            $dias = [];
        }

        $saida = [];
        foreach ($dias as $dia) {
            $dia = (int)$dia;
            if ($dia >= 0 && $dia <= 6 && !in_array($dia, $saida, true)) {
                $saida[] = $dia;
            }
        }
        sort($saida);
        return $saida ? implode(',', $saida) : '0,1,2,3,4,5,6';
    }
}

if (!function_exists('rv_rota_ativa_hoje')) {
    function rv_rota_ativa_hoje(array $rota, ?int $timestamp = null): bool {
        $timestamp = $timestamp ?: time();
        $dia = (int)date('w', $timestamp);
        $dias = array_filter(explode(',', (string)($rota['dias_semana'] ?? '')), 'strlen');
        return in_array((string)$dia, $dias, true);
    }
}

if (!function_exists('rv_ciclo')) {
    function rv_ciclo(array $rota, ?int $timestamp = null): array {
        $timestamp = $timestamp ?: time();
        $data = date('Y-m-d', $timestamp);
        $inicio = strtotime($data . ' ' . (($rota['hora_inicio'] ?? '') ?: '00:00:00'));
        $intervalo = max(5, (int)($rota['intervalo_minutos'] ?? 0)) * 60;
        $indice = $timestamp < $inicio ? -1 : (int)floor(($timestamp - $inicio) / $intervalo);
        $previsto = $inicio + (max(0, $indice) * $intervalo);
        $limiteRepeticoes = max(1, (int)($rota['repeticoes_por_dia'] ?? 0));
        $horaFim = (string)($rota['hora_fim'] ?? '');
        $fimConfigurado = $horaFim !== '' ? strtotime($data . ' ' . $horaFim) : null;
        $ativo = $indice >= 0
            && $indice < $limiteRepeticoes
            && (!$fimConfigurado || $timestamp <= $fimConfigurado);

        return [
            'indice' => $indice,
            'ativo' => $ativo,
            'previsto_em' => date('Y-m-d H:i:s', $previsto),
            'chave_base' => date('Ymd', $timestamp) . ':' . max(0, $indice),
            'tolerancia_segundos' => max(0, (int)($rota['tolerancia_minutos'] ?? 0)) * 60,
            'timestamp_previsto' => $previsto,
        ];
    }
}

if (!function_exists('rv_status_sla')) {
    function rv_status_sla(array $rota, ?int $timestamp = null): array {
        $timestamp = $timestamp ?: time();
        $ciclo = rv_ciclo($rota, $timestamp);
        if (!$ciclo['ativo']) {
            return ['fora_janela', 0, $ciclo];
        }

        $atraso = max(0, (int)floor(
            ($timestamp - ($ciclo['timestamp_previsto'] + $ciclo['tolerancia_segundos'])) / 60
        ));
        return [$atraso > 0 ? 'atrasado' : 'no_prazo', $atraso, $ciclo];
    }
}
