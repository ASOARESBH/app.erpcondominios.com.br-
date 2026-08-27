<?php
/** Helper central de internacionalização para APIs, PDFs, e-mails e jobs. */
if (!function_exists('erp_locales_suportados')) {
    function erp_locales_suportados(): array { return ['pt-BR','en-US','es-ES']; }
    function erp_normalizar_locale($locale): string {
        $raw = str_replace('_', '-', trim((string)$locale));
        foreach (erp_locales_suportados() as $permitido) if (strcasecmp($permitido, $raw) === 0) return $permitido;
        $idioma = strtolower(substr($raw, 0, 2));
        foreach (erp_locales_suportados() as $permitido) if (strtolower(substr($permitido, 0, 2)) === $idioma) return $permitido;
        return 'pt-BR';
    }
    function erp_locale_atual(?array $usuario = null, ?array $tenant = null): string {
        $candidatos = [$usuario['locale'] ?? null, $_SESSION['locale'] ?? null, $tenant['locale'] ?? null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null, 'pt-BR'];
        foreach ($candidatos as $candidato) { if ($candidato) return erp_normalizar_locale(explode(',', (string)$candidato)[0]); }
        return 'pt-BR';
    }
    function erp_catalogo($locale = 'pt-BR'): array {
        static $cache = [];
        $locale = erp_normalizar_locale($locale);
        if (isset($cache[$locale])) return $cache[$locale];
        $arquivo = __DIR__ . '/../../frontend/i18n/' . $locale . '.json';
        $conteudo = is_file($arquivo) ? json_decode((string)file_get_contents($arquivo), true) : [];
        return $cache[$locale] = is_array($conteudo) ? $conteudo : [];
    }
    function erp_t(string $chave, array $parametros = [], ?string $locale = null): string {
        $catalogo = erp_catalogo($locale ?: erp_locale_atual());
        $texto = $catalogo[$chave] ?? erp_catalogo('pt-BR')[$chave] ?? $chave;
        foreach ($parametros as $nome => $valor) $texto = str_replace('{{' . $nome . '}}', (string)$valor, $texto);
        return $texto;
    }
    function erp_formatar_numero($valor, ?string $locale = null, int $casas = 2): string {
        $locale = erp_normalizar_locale($locale ?: erp_locale_atual());
        $formatador = class_exists('NumberFormatter') ? new NumberFormatter($locale, NumberFormatter::DECIMAL) : null;
        if ($formatador) { $formatador->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $casas); $formatador->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $casas); return $formatador->format((float)$valor); }
        return number_format((float)$valor, $casas, $locale === 'pt-BR' ? ',' : '.', $locale === 'pt-BR' ? '.' : ',');
    }
    function erp_formatar_moeda($valor, string $moeda = 'BRL', ?string $locale = null): string {
        $locale = erp_normalizar_locale($locale ?: erp_locale_atual());
        if (class_exists('NumberFormatter')) { $formatador = new NumberFormatter($locale, NumberFormatter::CURRENCY); return $formatador->formatCurrency((float)$valor, $moeda); }
        return $moeda . ' ' . erp_formatar_numero($valor, $locale, 2);
    }
}
