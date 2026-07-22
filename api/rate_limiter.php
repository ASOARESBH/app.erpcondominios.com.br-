<?php
/**
 * =====================================================
 * RATE LIMITER - PROTEÇÃO CONTRA FORÇA BRUTA
 * =====================================================
 * 
 * Implementa proteção contra ataques de força bruta
 * Suporta Redis (se disponível) ou armazenamento em arquivo
 * 
 * Uso:
 * $limiter = new RateLimiter();
 * if (!$limiter->isAllowed('login:192.168.1.1', 5, 300)) {
 *     // Muitas tentativas
 * }
 */

class RateLimiter {
    private $redis = null;
    private $max_attempts = 5;
    private $window_seconds = 300; // 5 minutos
    private $storage_dir = null;
    private $enabled = true;
    
    /**
     * Construtor
     */
    public function __construct() {
        // Tentar conectar ao Redis
        if (extension_loaded('redis')) {
            try {
                $this->redis = new Redis();
                if (@$this->redis->connect('127.0.0.1', 6379, 1)) {
                    error_log('RateLimiter: Redis conectado com sucesso');
                } else {
                    $this->redis = null;
                }
            } catch (Exception $e) {
                error_log('RateLimiter: Falha ao conectar Redis - ' . $e->getMessage());
                $this->redis = null;
            }
        }
        
        // Configurar diretório de armazenamento
        $this->storage_dir = sys_get_temp_dir() . '/rate_limit_' . md5($_SERVER['HTTP_HOST']);
        if (!file_exists($this->storage_dir)) {
            @mkdir($this->storage_dir, 0755, true);
        }
    }
    
    /**
     * Verificar se uma requisição é permitida
     * 
     * @param string $identifier Identificador único (ex: "login:192.168.1.1")
     * @param int $max_attempts Máximo de tentativas permitidas
     * @param int $window_seconds Janela de tempo em segundos
     * @return bool true se permitido, false se limite excedido
     */
    public function isAllowed($identifier, $max_attempts = null, $window_seconds = null) {
        if (!$this->enabled) {
            return true;
        }
        
        $max_attempts = $max_attempts ?? $this->max_attempts;
        $window_seconds = $window_seconds ?? $this->window_seconds;
        
        if ($this->redis) {
            return $this->checkRedis($identifier, $max_attempts, $window_seconds);
        } else {
            return $this->checkFile($identifier, $max_attempts, $window_seconds);
        }
    }
    
    /**
     * Obter tentativas restantes
     * 
     * @param string $identifier Identificador único
     * @param int $max_attempts Máximo de tentativas
     * @return int Número de tentativas restantes
     */
    public function getRemainingAttempts($identifier, $max_attempts = null) {
        $max_attempts = $max_attempts ?? $this->max_attempts;
        
        if ($this->redis) {
            $attempts = (int)$this->redis->get("rate_limit:{$identifier}") ?? 0;
        } else {
            $attempts = $this->getFileAttempts($identifier);
        }
        
        return max(0, $max_attempts - $attempts);
    }
    
    /**
     * Resetar contador para um identificador
     * 
     * @param string $identifier Identificador único
     * @return bool
     */
    public function reset($identifier) {
        if ($this->redis) {
            return $this->redis->del("rate_limit:{$identifier}") > 0;
        } else {
            $file = $this->getFilePath($identifier);
            if (file_exists($file)) {
                return @unlink($file);
            }
            return true;
        }
    }
    
    /**
     * Desabilitar rate limiter (para testes)
     */
    public function disable() {
        $this->enabled = false;
    }
    
    /**
     * Habilitar rate limiter
     */
    public function enable() {
        $this->enabled = true;
    }
    
    /**
     * Verificar limite usando Redis
     */
    private function checkRedis($identifier, $max_attempts, $window_seconds) {
        $key = "rate_limit:{$identifier}";
        
        try {
            $attempts = $this->redis->incr($key);
            
            if ($attempts === 1) {
                $this->redis->expire($key, $window_seconds);
            }
            
            return $attempts <= $max_attempts;
        } catch (Exception $e) {
            error_log('RateLimiter Redis Error: ' . $e->getMessage());
            // Fallback para arquivo se Redis falhar
            return $this->checkFile($identifier, $max_attempts, $window_seconds);
        }
    }
    
    /**
     * Verificar limite usando arquivo
     */
    private function checkFile($identifier, $max_attempts, $window_seconds) {
        $file = $this->getFilePath($identifier);
        $now = time();
        
        if (file_exists($file)) {
            $data = @json_decode(file_get_contents($file), true);
            
            if (!$data || !isset($data['timestamp']) || !isset($data['attempts'])) {
                // Arquivo corrompido, resetar
                @unlink($file);
                return $this->createNewRecord($file, $now);
            }
            
            // Verificar se a janela expirou
            if ($now - $data['timestamp'] > $window_seconds) {
                // Janela expirou, resetar
                @unlink($file);
                return $this->createNewRecord($file, $now);
            }
            
            // Incrementar tentativas
            $data['attempts']++;
            $data['last_attempt'] = $now;
            
            @file_put_contents($file, json_encode($data), LOCK_EX);
            
            return $data['attempts'] <= $max_attempts;
        } else {
            // Primeira tentativa
            return $this->createNewRecord($file, $now);
        }
    }
    
    /**
     * Criar novo registro de limite
     */
    private function createNewRecord($file, $now) {
        $data = [
            'attempts' => 1,
            'timestamp' => $now,
            'last_attempt' => $now
        ];
        
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }
    
    /**
     * Obter caminho do arquivo de limite
     */
    private function getFilePath($identifier) {
        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);
        return $this->storage_dir . '/' . $safe_name . '.json';
    }
    
    /**
     * Obter tentativas do arquivo
     */
    private function getFileAttempts($identifier) {
        $file = $this->getFilePath($identifier);
        
        if (file_exists($file)) {
            $data = @json_decode(file_get_contents($file), true);
            return $data['attempts'] ?? 0;
        }
        
        return 0;
    }
    
    /**
     * Limpar arquivos expirados (executar periodicamente)
     */
    public function cleanup() {
        if (!is_dir($this->storage_dir)) {
            return;
        }
        
        $now = time();
        $files = @scandir($this->storage_dir);
        
        if (!$files) {
            return;
        }
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $filepath = $this->storage_dir . '/' . $file;
            
            if (!is_file($filepath)) {
                continue;
            }
            
            $data = @json_decode(file_get_contents($filepath), true);
            
            if (!$data || !isset($data['timestamp'])) {
                @unlink($filepath);
                continue;
            }
            
            // Remover arquivos com mais de 1 hora
            if ($now - $data['timestamp'] > 3600) {
                @unlink($filepath);
            }
        }
    }
    
    /**
     * Obter estatísticas de rate limiting
     */
    public function getStats() {
        $stats = [
            'backend' => $this->redis ? 'Redis' : 'File',
            'enabled' => $this->enabled,
            'storage_dir' => $this->storage_dir
        ];
        
        if (!$this->redis && is_dir($this->storage_dir)) {
            $files = @scandir($this->storage_dir);
            $stats['active_records'] = count($files) - 2; // Excluir . e ..
        }
        
        return $stats;
    }
}

/**
 * Função auxiliar para obter IP do cliente
 */
function getClientIP() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        // Cloudflare
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Proxy
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        return $_SERVER['REMOTE_ADDR'];
    }
    return '0.0.0.0';
}

/**
 * Função auxiliar para retornar erro de rate limit
 */
function retornarRateLimitExcedido($limiter, $identifier, $max_attempts = 5) {
    $remaining = $limiter->getRemainingAttempts($identifier, $max_attempts);
    
    http_response_code(429); // Too Many Requests
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Muitas tentativas. Tente novamente em alguns minutos.',
        'codigo' => 'RATE_LIMIT_EXCEEDED',
        'tentativas_restantes' => max(0, $remaining),
        'tempo_espera_segundos' => 300
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
