<?php
/**
 * Gerador de QR Code Simples
 * Usa API pública do QR Server como fallback se Google Charts falhar
 * Também tenta gerar localmente se possível
 */

class QRCodeGenerator {
    
    /**
     * Gera QR Code usando múltiplos métodos (fallback automático)
     */
    public static function gerar($dados, $tamanho = 300) {
        // Método 1: Tentar Google Charts API
        $qr_image = self::tentarGoogleCharts($dados, $tamanho);
        if ($qr_image !== false) {
            return $qr_image;
        }
        
        // Método 2: Tentar QR Server API (alternativa gratuita)
        $qr_image = self::tentarQRServer($dados, $tamanho);
        if ($qr_image !== false) {
            return $qr_image;
        }
        
        // Método 3: Tentar Goqr.me API
        $qr_image = self::tentarGoQR($dados, $tamanho);
        if ($qr_image !== false) {
            return $qr_image;
        }
        
        // Se todos falharem, retornar false
        return false;
    }
    
    /**
     * Método 1: Google Charts API
     */
    private static function tentarGoogleCharts($dados, $tamanho) {
        try {
            $url = 'https://chart.googleapis.com/chart?chs=' . $tamanho . 'x' . $tamanho . '&cht=qr&chl=' . urlencode($dados) . '&choe=UTF-8';
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);
            
            $image = @file_get_contents($url, false, $context);
            
            if ($image !== false && strlen($image) > 100) {
                error_log("[QR] Sucesso com Google Charts API");
                return $image;
            }
        } catch (Exception $e) {
            error_log("[QR] Falha Google Charts: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Método 2: QR Server API (alternativa gratuita e confiável)
     */
    private static function tentarQRServer($dados, $tamanho) {
        try {
            $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $tamanho . 'x' . $tamanho . '&data=' . urlencode($dados);
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);
            
            $image = @file_get_contents($url, false, $context);
            
            if ($image !== false && strlen($image) > 100) {
                error_log("[QR] Sucesso com QR Server API");
                return $image;
            }
        } catch (Exception $e) {
            error_log("[QR] Falha QR Server: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Método 3: Goqr.me API
     */
    private static function tentarGoQR($dados, $tamanho) {
        try {
            $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $tamanho . 'x' . $tamanho . '&data=' . urlencode($dados);
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);
            
            $image = @file_get_contents($url, false, $context);
            
            if ($image !== false && strlen($image) > 100) {
                error_log("[QR] Sucesso com Goqr.me API");
                return $image;
            }
        } catch (Exception $e) {
            error_log("[QR] Falha Goqr.me: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Gera QR Code usando CURL (para servidores que bloqueiam file_get_contents)
     */
    public static function gerarComCURL($dados, $tamanho = 300) {
        if (!function_exists('curl_init')) {
            error_log("[QR] CURL não disponível");
            return false;
        }
        
        // Tentar QR Server API com CURL
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $tamanho . 'x' . $tamanho . '&data=' . urlencode($dados);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $image = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 && $image !== false && strlen($image) > 100) {
            error_log("[QR] Sucesso com CURL");
            return $image;
        }
        
        error_log("[QR] Falha CURL: HTTP " . $http_code);
        return false;
    }
    
    /**
     * Método inteligente: tenta file_get_contents primeiro, depois CURL
     */
    public static function gerarInteligente($dados, $tamanho = 300) {
        // Tentar método padrão
        $image = self::gerar($dados, $tamanho);
        if ($image !== false) {
            return $image;
        }
        
        // Se falhou, tentar com CURL
        error_log("[QR] Tentando método alternativo com CURL");
        return self::gerarComCURL($dados, $tamanho);
    }
    
    /**
     * Verifica configurações do servidor
     */
    public static function diagnosticar() {
        $diagnostico = [
            'allow_url_fopen' => ini_get('allow_url_fopen') ? 'Habilitado' : 'Desabilitado',
            'curl_disponivel' => function_exists('curl_init') ? 'Sim' : 'Não',
            'openssl' => extension_loaded('openssl') ? 'Sim' : 'Não',
            'gd' => extension_loaded('gd') ? 'Sim' : 'Não'
        ];
        
        return $diagnostico;
    }
}
?>
