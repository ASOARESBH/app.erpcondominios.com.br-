<?php

declare(strict_types=1);

/**
 * Mantém a ordenação histórica do GED sem SUBSTRING_INDEX/CAST AS UNSIGNED.
 * A lista de entrada deve vir de ORDER BY nome no banco, para preservar a
 * collation efetiva como critério estável quando os números forem iguais.
 */
function erpGedUnidadeNumeroOrdenacao(array $unidade): int
{
    if (strcasecmp(trim((string)($unidade['bloco'] ?? '')), 'ADMIN') === 0) {
        return 9999;
    }

    $nome = (string)($unidade['nome'] ?? '');
    $ultimoEspaco = strrpos($nome, ' ');
    $ultimoToken = $ultimoEspaco === false ? $nome : substr($nome, $ultimoEspaco + 1);
    if (!preg_match('/^[\t\n\r\f\v ]*([0-9]+)/', $ultimoToken, $match)) {
        return 0;
    }

    $numero = ltrim($match[1], '0');
    if ($numero === '') {
        return 0;
    }
    if (strlen($numero) > strlen((string)PHP_INT_MAX)
        || (strlen($numero) === strlen((string)PHP_INT_MAX) && strcmp($numero, (string)PHP_INT_MAX) > 0)) {
        return PHP_INT_MAX;
    }
    return (int)$numero;
}

/**
 * Ordena linhas já ordenadas por nome no banco; preserva a ordem nativa como
 * desempate, equivalente ao ORDER BY numérico, nome da consulta MySQL anterior.
 *
 * @param array<int, array<string, mixed>> $unidades
 * @return array<int, array<string, mixed>>
 */
function erpGedOrdenarUnidades(array $unidades): array
{
    $ordenadas = [];
    foreach (array_values($unidades) as $indice => $unidade) {
        if (!is_array($unidade)) {
            throw new InvalidArgumentException('Linha de unidade inválida.');
        }
        $ordenadas[] = [
            'indice' => $indice,
            'numero' => erpGedUnidadeNumeroOrdenacao($unidade),
            'unidade' => $unidade,
        ];
    }

    usort($ordenadas, static function (array $a, array $b): int {
        $comparacao = $a['numero'] <=> $b['numero'];
        return $comparacao !== 0 ? $comparacao : ($a['indice'] <=> $b['indice']);
    });

    return array_map(static fn(array $item): array => $item['unidade'], $ordenadas);
}
