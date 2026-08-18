<?php
/**
 * Extração de texto de PDFs digitais sem binários do sistema operacional.
 * Utiliza Smalot PDFParser incluído localmente para compatibilidade com
 * hospedagem compartilhada HostGator sem pdftotext.
 */

require_once __DIR__ . '/../vendor/autoload_pdfparser.php';

use Smalot\PdfParser\Parser;

function pdf_extrair_texto($arquivo) {
    if (!is_string($arquivo) || $arquivo === '' || !is_file($arquivo) || !is_readable($arquivo)) {
        throw new RuntimeException('O arquivo PDF temporário não está disponível para leitura.');
    }

    try {
        $parser = new Parser();
        $documento = $parser->parseFile($arquivo);
        $texto = (string)$documento->getText();
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/[\t ]+\n/u', "\n", $texto);
        $texto = trim((string)$texto);

        if ($texto === '') {
            throw new RuntimeException('O PDF não contém texto legível. Exporte o relatório BRCondos como PDF digital, sem escaneamento.');
        }

        return $texto;
    } catch (Throwable $erro) {
        error_log('[PDFExtractor] ' . $erro->getMessage());
        throw new RuntimeException('Não foi possível ler o texto do PDF enviado. Confirme se é o Relatório de Inadimplência Detalhado BRCondos.');
    }
}

?>
