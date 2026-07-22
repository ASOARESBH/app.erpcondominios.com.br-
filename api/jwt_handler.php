<?php
/**
 * =====================================================
 * JWT HANDLER - AUTENTICAÇÃO POR TOKEN JWT
 * =====================================================
 * 
 * Implementa autenticação por JSON Web Tokens (JWT)
 * Suporta geração, validação e revogação de tokens
 * 
 * Uso:
 * $jwt = new JWTHandler();
 * $token = $jwt->generateToken($usuario_id, $usuario_nome, $permissao);
 * $payload = $jwt->validateToken($token);
 */

class JWTHandler {
    private $secret_key;
    private $algorithm = 'HS256';
    private $expiration = 3600; // 1 hora
    private $refresh_expiration = 604800; // 7 dias
    private $blacklist_file;
    
    /**
     * Construtor
     */
    public function __construct($secret_key = null) {
        // Usar chave do ambiente ou gerar uma
        $this->secret_key = $secret_key ?? getenv('JWT_SECRET_KEY');
        
        if (!$this->secret_key) {
            // Gerar chave se não existir
            $this->secret_key = bin2hex(random_bytes(32));
            error_log("JWT: Chave gerada automaticamente. Defina JWT_SECRET_KEY no .env");
        }
        
        // Arquivo de blacklist de tokens
        $this->blacklist_file = sys_get_temp_dir() . '/jwt_blacklist_' . md5($_SERVER['HTTP_HOST']) . '.json';
    }
    
    /**
     * Gerar token JWT
     */
    public function generateToken($usuario_id, $usuario_nome, $permissao, $expiration = null) {
        $expiration = $expiration ?? $this->expiration;
        
        $header = [
            'alg' => $this->algorithm,
            'typ' => 'JWT'
        ];
        
        $payload = [
            'iss' => $_SERVER['HTTP_HOST'] ?? 'api',
            'iat' => time(),
            'exp' => time() + $expiration,
            'usuario_id' => (int)$usuario_id,
            'usuario_nome' => (string)$usuario_nome,
            'permissao' => (string)$permissao,
            'ip' => $this->getClientIP(),
            'user_agent_hash' => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''),
            'jti' => bin2hex(random_bytes(16)) // ID único do token
        ];
        
        $header_encoded = $this->base64UrlEncode(json_encode($header));
        $payload_encoded = $this->base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac(
            'sha256',
            "{$header_encoded}.{$payload_encoded}",
            $this->secret_key,
            true
        );
        $signature_encoded = $this->base64UrlEncode($signature);
        
        return "{$header_encoded}.{$payload_encoded}.{$signature_encoded}";
    }
    
    /**
     * Gerar refresh token
     */
    public function generateRefreshToken($usuario_id, $usuario_nome, $permissao) {
        return $this->generateToken($usuario_id, $usuario_nome, $permissao, $this->refresh_expiration);
    }
    
    /**
     * Validar token JWT
     */
    public function validateToken($token) {
        if (!$token || !is_string($token)) {
            return false;
        }
        
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            error_log("JWT: Token inválido - formato incorreto");
            return false;
        }
        
        list($header_encoded, $payload_encoded, $signature_encoded) = $parts;
        
        // Validar assinatura
        $signature = hash_hmac(
            'sha256',
            "{$header_encoded}.{$payload_encoded}",
            $this->secret_key,
            true
        );
        $signature_expected = $this->base64UrlEncode($signature);
        
        if (!hash_equals($signature_encoded, $signature_expected)) {
            error_log("JWT: Assinatura inválida");
            return false;
        }
        
        // Decodificar payload
        $payload = json_decode($this->base64UrlDecode($payload_encoded), true);
        
        if (!$payload) {
            error_log("JWT: Payload inválido");
            return false;
        }
        
        // Verificar expiração
        if ($payload['exp'] < time()) {
            error_log("JWT: Token expirado");
            return false;
        }
        
        // Verificar se está na blacklist
        if ($this->isTokenBlacklisted($payload['jti'] ?? null)) {
            error_log("JWT: Token na blacklist");
            return false;
        }
        
        // Validar IP (proteção contra session hijacking)
        $current_ip = $this->getClientIP();
        if ($payload['ip'] !== $current_ip) {
            error_log("JWT: IP não corresponde - Token: {$payload['ip']}, Atual: {$current_ip}");
            // Comentar esta linha para permitir proxies
            // return false;
        }
        
        return $payload;
    }
    
    /**
     * Revogar token (adicionar à blacklist)
     */
    public function revokeToken($token) {
        $payload = $this->validateToken($token);
        
        if (!$payload || !isset($payload['jti'])) {
            return false;
        }
        
        return $this->addToBlacklist($payload['jti'], $payload['exp']);
    }
    
    /**
     * Renovar token
     */
    public function refreshToken($refresh_token) {
        $payload = $this->validateToken($refresh_token);
        
        if (!$payload) {
            return false;
        }
        
        // Gerar novo token
        return $this->generateToken(
            $payload['usuario_id'],
            $payload['usuario_nome'],
            $payload['permissao']
        );
    }
    
    /**
     * Obter payload sem validação (apenas decodificar)
     */
    public function getPayload($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return null;
        }
        
        return json_decode($this->base64UrlDecode($parts[1]), true);
    }
    
    /**
     * Adicionar token à blacklist
     */
    private function addToBlacklist($jti, $expiration) {
        $blacklist = $this->getBlacklist();
        $blacklist[$jti] = $expiration;
        
        // Limpar tokens expirados
        $now = time();
        foreach ($blacklist as $token_jti => $exp) {
            if ($exp < $now) {
                unlink($blacklist[$token_jti]);
            }
        }
        
        return @file_put_contents(
            $this->blacklist_file,
            json_encode($blacklist),
            LOCK_EX
        ) !== false;
    }
    
    /**
     * Verificar se token está na blacklist
     */
    private function isTokenBlacklisted($jti) {
        if (!$jti) {
            return false;
        }
        
        $blacklist = $this->getBlacklist();
        return isset($blacklist[$jti]);
    }
    
    /**
     * Obter blacklist de tokens
     */
    private function getBlacklist() {
        if (!file_exists($this->blacklist_file)) {
            return [];
        }
        
        $content = @file_get_contents($this->blacklist_file);
        return $content ? json_decode($content, true) : [];
    }
    
    /**
     * Codificar em Base64 URL-safe
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Decodificar Base64 URL-safe
     */
    private function base64UrlDecode($data) {
        $padding = 4 - strlen($data) % 4;
        if ($padding !== 4) {
            $data .= str_repeat('=', $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Obter IP do cliente
     */
    private function getClientIP() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return '0.0.0.0';
    }
}

/**
 * Middleware para validar JWT
 */
function verificarJWT() {
    $headers = getallheaders();
    $token = null;
    
    // Procurar token no header Authorization
    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }
    
    // Se não encontrar no header, procurar em cookie
    if (!$token && isset($_COOKIE['auth_token'])) {
        $token = $_COOKIE['auth_token'];
    }
    
    if (!$token) {
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Token não fornecido',
            'codigo' => 'NO_TOKEN'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $jwt = new JWTHandler();
    $payload = $jwt->validateToken($token);
    
    if (!$payload) {
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Token inválido ou expirado',
            'codigo' => 'INVALID_TOKEN'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    return $payload;
}

/**
 * Função auxiliar para extrair token do header
 */
function extractTokenFromHeader() {
    $headers = getallheaders();
    
    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    
    return $_COOKIE['auth_token'] ?? null;
}
?>
