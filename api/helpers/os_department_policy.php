<?php

declare(strict_types=1);

function os_department_policy_state(array $permissions, bool $isManager, bool $restricted): array
{
    $active = $permissions !== [] || !$isManager || $restricted;

    return [
        'active' => $active,
        'can_view_all' => $isManager && !$restricted && !$active,
    ];
}
