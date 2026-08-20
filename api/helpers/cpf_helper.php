<?php
/**
 * Utilitários de CPF compartilhados pelas APIs do ERP.
 * Não acessa sessão ou banco de dados; apenas normaliza e valida valores.
 */

if (!function_exists('cpf_somente_digitos')) {
    function cpf_somente_digitos($valor) {
        return preg_replace('/\D/', '', (string)$valor);
    }
}

if (!function_exists('cpf_valido')) {
    function cpf_valido($valor) {
        $cpf = cpf_somente_digitos($valor);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($posicao = 9; $posicao < 11; $posicao++) {
            $soma = 0;
            for ($indice = 0; $indice < $posicao; $indice++) {
                $soma += (int)$cpf[$indice] * (($posicao + 1) - $indice);
            }

            $digito = ($soma % 11) < 2 ? 0 : 11 - ($soma % 11);
            if ((int)$cpf[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('cpf_formatar')) {
    function cpf_formatar($valor) {
        $cpf = cpf_somente_digitos($valor);
        if (strlen($cpf) !== 11) {
            return $cpf;
        }

        return substr($cpf, 0, 3) . '.'
            . substr($cpf, 3, 3) . '.'
            . substr($cpf, 6, 3) . '-'
            . substr($cpf, 9, 2);
    }
}
