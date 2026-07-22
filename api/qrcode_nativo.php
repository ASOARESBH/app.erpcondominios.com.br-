<?php
/**
 * Gerador Nativo de QR Code
 * Usa biblioteca PHP pura (sem dependências externas)
 */

require_once 'qrcode_lib.php';

class QRCodeNativo {
    
    /**
     * Gera QR Code em PNG (base64)
     */
    public static function gerarPNG($dados, $tamanho = 300) {
        try {
            // Criar instância do gerador
            $options = [
                's' => 'qr-h',  // QR Code com correção de erro alta
                'sf' => 8,      // Scale factor
                'p' => 10,      // Padding
                'bc' => '#FFFFFF', // Background branco
                'fc' => '#000000'  // Foreground preto
            ];
            
            $generator = new QRCode($dados, $options);
            
            // Renderizar imagem
            $image = $generator->render_image();
            
            if (!$image) {
                error_log("[QR NATIVO] Erro ao renderizar imagem");
                return false;
            }
            
            // Capturar output da imagem em buffer
            ob_start();
            imagepng($image);
            $image_data = ob_get_clean();
            imagedestroy($image);
            
            if (!$image_data) {
                error_log("[QR NATIVO] Erro ao capturar dados da imagem");
                return false;
            }
            
            // Converter para base64
            $base64 = 'data:image/png;base64,' . base64_encode($image_data);
            
            error_log("[QR NATIVO] QR Code gerado com sucesso. Tamanho: " . strlen($image_data) . " bytes");
            
            return $base64;
            
        } catch (Exception $e) {
            error_log("[QR NATIVO] Exceção: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Gera QR Code e salva em arquivo
     */
    public static function gerarArquivo($dados, $caminho_arquivo) {
        try {
            $options = [
                's' => 'qr-h',
                'sf' => 8,
                'p' => 10,
                'bc' => '#FFFFFF',
                'fc' => '#000000'
            ];
            
            $generator = new QRCode($dados, $options);
            $image = $generator->render_image();
            
            if (!$image) {
                return false;
            }
            
            // Salvar em arquivo
            $sucesso = imagepng($image, $caminho_arquivo);
            imagedestroy($image);
            
            if ($sucesso) {
                error_log("[QR NATIVO] QR Code salvo em: $caminho_arquivo");
                return true;
            } else {
                error_log("[QR NATIVO] Erro ao salvar arquivo: $caminho_arquivo");
                return false;
            }
            
        } catch (Exception $e) {
            error_log("[QR NATIVO] Exceção ao salvar arquivo: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Gera QR Code em SVG
     */
    public static function gerarSVG($dados) {
        try {
            $options = [
                's' => 'qr-h',
                'sf' => 8,
                'p' => 10
            ];
            
            $generator = new QRCode($dados, $options);
            
            // Renderizar SVG
            // Nota: A biblioteca qrcode.php não tem método SVG nativo
            // Então vamos usar PNG mesmo
            return self::gerarPNG($dados);
            
        } catch (Exception $e) {
            error_log("[QR NATIVO] Exceção SVG: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Output direto da imagem (para usar em <img src="qrcode.php?token=...">)
     */
    public static function outputDireto($dados) {
        try {
            $options = [
                's' => 'qr-h',
                'sf' => 8,
                'p' => 10,
                'bc' => '#FFFFFF',
                'fc' => '#000000'
            ];
            
            $generator = new QRCode($dados, $options);
            
            // Enviar header
            header('Content-Type: image/png');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Output direto
            $generator->output_image();
            
            return true;
            
        } catch (Exception $e) {
            error_log("[QR NATIVO] Exceção output direto: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Testa se a biblioteca está funcionando
     */
    public static function testar() {
        $dados_teste = json_encode([
            'teste' => true,
            'timestamp' => time(),
            'mensagem' => 'QR Code Nativo Funcionando!'
        ]);
        
        $resultado = self::gerarPNG($dados_teste);
        
        return [
            'funcionando' => $resultado !== false,
            'tamanho_base64' => $resultado ? strlen($resultado) : 0,
            'biblioteca' => 'qrcode.php (MIT License)',
            'versao_php' => PHP_VERSION,
            'gd_disponivel' => extension_loaded('gd')
        ];
    }
    
    /**
     * Verifica requisitos do sistema
     */
    public static function verificarRequisitos() {
        $requisitos = [
            'php_version' => PHP_VERSION,
            'gd_extension' => extension_loaded('gd'),
            'qrcode_lib' => file_exists(__DIR__ . '/qrcode_lib.php'),
            'funcoes_imagem' => [
                'imagecreate' => function_exists('imagecreate'),
                'imagepng' => function_exists('imagepng'),
                'imagedestroy' => function_exists('imagedestroy'),
                'imagecolorallocate' => function_exists('imagecolorallocate'),
                'imagesetpixel' => function_exists('imagesetpixel')
            ]
        ];
        
        $tudo_ok = $requisitos['gd_extension'] && 
                   $requisitos['qrcode_lib'] && 
                   $requisitos['funcoes_imagem']['imagecreate'] &&
                   $requisitos['funcoes_imagem']['imagepng'];
        
        return [
            'requisitos_ok' => $tudo_ok,
            'detalhes' => $requisitos
        ];
    }
}
?>
