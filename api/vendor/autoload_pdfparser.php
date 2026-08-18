<?php
/**
 * Autoloader mínimo para Smalot PDFParser e symfony/polyfill-mbstring.
 * As dependências são entregues junto da aplicação porque o HostGator não
 * disponibiliza Composer nem o binário pdftotext em todos os planos.
 */

$vendorRaiz = __DIR__;

$polyfillClasse = $vendorRaiz . '/symfony/polyfill-mbstring/Mbstring.php';
if (is_file($polyfillClasse)) {
    require_once $polyfillClasse;
}

$polyfill = $vendorRaiz . '/symfony/polyfill-mbstring/bootstrap.php';
if (is_file($polyfill)) {
    require_once $polyfill;
}

spl_autoload_register(static function ($classe) use ($vendorRaiz) {
    $prefixo = 'Smalot\\PdfParser\\';
    if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
        return;
    }

    $arquivo = $vendorRaiz . '/smalot/pdfparser/src/' . str_replace('\\', '/', $classe) . '.php';
    if (is_file($arquivo)) {
        require_once $arquivo;
    }
});

?>
