<?php
/**
 * Script de teste para verificar se PHPMailer está acessível
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== TESTE DE PHPMAILER ===\n\n";

// 1. Verificar diretório
$phpmailer_path = dirname(__DIR__) . '/PHPMailer/';
echo "1. Caminho esperado: $phpmailer_path\n";
echo "   Existe? " . (is_dir($phpmailer_path) ? "✅ SIM" : "❌ NÃO") . "\n\n";

// 2. Verificar arquivos
$arquivos = ['PHPMailer.php', 'SMTP.php', 'Exception.php'];
echo "2. Arquivos necessários:\n";
foreach ($arquivos as $arquivo) {
    $caminho = $phpmailer_path . $arquivo;
    $existe = file_exists($caminho);
    echo "   - $arquivo: " . ($existe ? "✅ ENCONTRADO" : "❌ NÃO ENCONTRADO") . "\n";
    if ($existe) {
        echo "     Caminho: $caminho\n";
        echo "     Tamanho: " . filesize($caminho) . " bytes\n";
    }
}

echo "\n3. Tentando incluir PHPMailer...\n";
try {
    require_once $phpmailer_path . 'PHPMailer.php';
    require_once $phpmailer_path . 'SMTP.php';
    require_once $phpmailer_path . 'Exception.php';
    
    use PHPMailer\PHPMailer\PHPMailer;
    
    echo "   ✅ PHPMailer incluído com sucesso!\n";
    
    // Tentar criar instância
    $mail = new PHPMailer(true);
    echo "   ✅ Instância de PHPMailer criada com sucesso!\n";
    
} catch (Exception $e) {
    echo "   ❌ Erro ao incluir PHPMailer: " . $e->getMessage() . "\n";
}

echo "\n=== FIM DO TESTE ===\n";
?>
