<?php

declare(strict_types=1);

function pwa_config_publica(array $config): array
{
    $camposPublicos = [
        'fcm_api_key', 'fcm_auth_domain', 'fcm_project_id', 'fcm_storage_bucket',
        'fcm_messaging_sender_id', 'fcm_app_id', 'fcm_vapid_key',
        'pwa_ativo', 'pwa_nome_app', 'pwa_tema_cor', 'pwa_install_url',
        'pwa_versao', 'pwa_cache_version', 'pwa_modo_manutencao',
        'push_visitante_ativo', 'push_inadimplencia_ativo', 'push_comunicado_ativo',
        'push_os_ativo', 'push_urgente_ativo', 'push_chatin_ativo',
    ];
    return array_intersect_key($config, array_flip($camposPublicos));
}

function pwa_mesclar_config_tenant(array $institucional, array $tenant): array
{
    return array_merge($institucional, $tenant);
}

function pwa_parse_positive_id(mixed $value): ?int
{
    if (!is_int($value) && !is_string($value)) return null;
    $raw = (string)$value;
    if (!preg_match('/^[1-9][0-9]{0,9}$/D', $raw)) return null;
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    return $id === false ? null : $id;
}

function pwa_paginacao(mixed $paginaValor, mixed $limiteValor, int $limiteMaximo, int $paginaMaxima = 1000000): array
{
    $inteiro = static function (mixed $value, int $default): int {
        if (is_int($value)) return $value;
        if (!is_string($value) || !preg_match('/^-?[0-9]+$/D', $value)) return $default;
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? $default : $parsed;
    };

    $limiteMaximo = max(1, min($limiteMaximo, 1000));
    $paginaMaxima = max(1, min($paginaMaxima, 1000000));
    $limite = max(1, min($inteiro($limiteValor, 20), $limiteMaximo));
    $pagina = max(1, min($inteiro($paginaValor, 1), $paginaMaxima));
    return ['pagina' => $pagina, 'limite' => $limite, 'offset' => ($pagina - 1) * $limite];
}

function pwa_resolver_destinatario_push(array $payload): ?array
{
    $destinatario = $payload['destinatario'] ?? null;
    if (!is_string($destinatario) || !in_array($destinatario, ['todos', 'morador', 'unidade'], true)) return null;

    $moradorInformado = array_key_exists('morador_id', $payload) && $payload['morador_id'] !== null && $payload['morador_id'] !== '';
    $unidadeInformada = array_key_exists('unidade_id', $payload) && $payload['unidade_id'] !== null && $payload['unidade_id'] !== '';
    $moradorId = $moradorInformado ? pwa_parse_positive_id($payload['morador_id']) : null;
    $unidadeId = $unidadeInformada ? pwa_parse_positive_id($payload['unidade_id']) : null;

    if (($moradorInformado && $moradorId === null) || ($unidadeInformada && $unidadeId === null)) return null;
    if ($destinatario === 'todos' && ($moradorInformado || $unidadeInformada)) return null;
    if ($destinatario === 'morador' && (!$moradorId || $unidadeInformada)) return null;
    if ($destinatario === 'unidade' && (!$unidadeId || $moradorInformado)) return null;

    return ['destinatario' => $destinatario, 'morador_id' => $moradorId, 'unidade_id' => $unidadeId];
}

function pwa_resolver_destinatario_automatico(array $payload): ?array
{
    $destino = pwa_resolver_destinatario_push($payload);
    if ($destino === null || !in_array($destino['destinatario'], ['todos', 'morador'], true)) return null;
    return $destino;
}

function pwa_schema_sessao_portal_exigido(string $action): ?array
{
    return in_array($action, ['registrar_token', 'remover_token', 'marcar_lida', 'listar_minhas'], true)
        ? ['token', 'morador_id', 'ativo', 'data_expiracao']
        : null;
}

function pwa_url_destino_valida(string $url): bool
{
    if ($url === '' || str_contains($url, "\r") || str_contains($url, "\n") || str_starts_with($url, '//')) {
        return false;
    }

    $normalizada = $url;
    for ($i = 0; $i < 3; $i++) {
        if (str_contains($normalizada, '\\')) return false;
        $decodificada = rawurldecode($normalizada);
        if ($decodificada === $normalizada) break;
        $normalizada = $decodificada;
    }
    if (str_contains($normalizada, '\\') || str_starts_with($normalizada, '//')
        || preg_match('/[\x00-\x20\x7f]/', $normalizada)) return false;

    $parts = parse_url($normalizada);
    return is_array($parts)
        && str_starts_with((string)($parts['path'] ?? ''), '/')
        && !isset($parts['scheme'])
        && !isset($parts['host'])
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

function pwa_origem_permitida(string $origin): bool
{
    return in_array($origin, [
        'https://app.erpcondominios.com.br',
        'http://localhost',
        'http://127.0.0.1',
    ], true);
}
