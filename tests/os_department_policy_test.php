<?php
require_once __DIR__ . '/../api/helpers/os_department_policy.php';

function os_policy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$managerWithoutMatrix = os_department_policy_state([], true, false);
os_policy_assert($managerWithoutMatrix['active'] === false, 'gestor legado sem restrição mantém compatibilidade');
os_policy_assert($managerWithoutMatrix['can_view_all'] === true, 'gestor legado sem restrição preserva visão tenant-wide');

$operatorWithoutMatrix = os_department_policy_state([], false, false);
os_policy_assert($operatorWithoutMatrix['active'] === true, 'operador sem matriz ativa política restritiva');
os_policy_assert($operatorWithoutMatrix['can_view_all'] === false, 'operador sem matriz não recebe visão ampla');

$restrictedManagerWithoutMatrix = os_department_policy_state([], true, true);
os_policy_assert($restrictedManagerWithoutMatrix['active'] === true, 'gestor restritivo sem matriz permanece restrito');
os_policy_assert($restrictedManagerWithoutMatrix['can_view_all'] === false, 'gestor restritivo sem matriz não recebe visão ampla');

$managerWithMatrix = os_department_policy_state(['PORTARIA' => ['visualizar' => 1]], true, false);
os_policy_assert($managerWithMatrix['active'] === true, 'matriz explícita ativa a política');
os_policy_assert($managerWithMatrix['can_view_all'] === false, 'matriz explícita não é contornada por perfil gestor');

fwrite(STDOUT, "PASS: política departamental O.S. falha fechada\n");
