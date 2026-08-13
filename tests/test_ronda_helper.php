<?php
require_once __DIR__ . '/../api/helpers/ronda_helper.php';

$rota = [
    'hora_inicio' => '08:00:00',
    'hora_fim' => '12:00:00',
    'intervalo_minutos' => 60,
    'repeticoes_por_dia' => 4,
    'tolerancia_minutos' => 10,
    'dias_semana' => '0,1,2,3,4,5,6',
];

function assert_ronda($condicao, $mensagem) {
    if (!$condicao) {
        fwrite(STDERR, "FALHA: {$mensagem}\n");
        exit(1);
    }
}

assert_ronda(rv_dias_normalizar([5, 1, 1, 9, -1]) === '1,5', 'normalização de dias');
assert_ronda(rv_rota_ativa_hoje($rota, strtotime('2026-08-13 09:00:00')) === true, 'rota ativa no dia configurado');

[$statusNoPrazo, $atrasoNoPrazo, $cicloNoPrazo] = rv_status_sla($rota, strtotime('2026-08-13 09:05:00'));
assert_ronda($statusNoPrazo === 'no_prazo' && $atrasoNoPrazo === 0, 'SLA no prazo');
assert_ronda($cicloNoPrazo['chave_base'] === '20260813:1', 'chave de ciclo esperada');

[$statusAtrasado, $atrasoAtrasado] = rv_status_sla($rota, strtotime('2026-08-13 09:12:00'));
assert_ronda($statusAtrasado === 'atrasado' && $atrasoAtrasado === 2, 'SLA atrasado');

[$statusForaJanela] = rv_status_sla($rota, strtotime('2026-08-13 12:01:00'));
assert_ronda($statusForaJanela === 'fora_janela', 'janela encerrada');

echo "OK: regras compartilhadas de ciclo e SLA validadas.\n";
