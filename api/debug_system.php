<?php
// =====================================================
// SISTEMA DE DEBUG E LOGS CENTRALIZADO
// =====================================================

class DebugSystem {
    private static $log_file = null;
    private static $debug_mode = true;
    private static $logs = [];
    
    /**
     * Inicializar sistema de debug
     */
    public static function init($debug_mode = true) {
        self::$debug_mode = $debug_mode;
        self::$log_file = __DIR__ . '/logs/debug_' . date('Y-m-d') . '.log';
        
        // Criar pasta de logs se não existir
        $log_dir = __DIR__ . '/logs';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0777, true);
        }
        
        // Registrar início da requisição
        self::log('INICIO', 'Nova requisição iniciada', [
            'timestamp' => date('Y-m-d H:i:s'),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A'
        ]);
    }
    
    /**
     * Registrar log
     */
    public static function log($tipo, $mensagem, $dados = null) {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s.u'),
            'tipo' => $tipo,
            'mensagem' => $mensagem,
            'dados' => $dados,
            'memoria' => self::formatBytes(memory_get_usage()),
            'tempo_execucao' => self::getExecutionTime() . 's'
        ];
        
        // Adicionar ao array de logs
        self::$logs[] = $log_entry;
        
        // Escrever no arquivo se debug mode ativo
        if (self::$debug_mode && self::$log_file) {
            $log_text = sprintf(
                "[%s] [%s] %s\n%s\n%s\n\n",
                $log_entry['timestamp'],
                $tipo,
                $mensagem,
                $dados ? json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '',
                str_repeat('-', 80)
            );
            
            file_put_contents(self::$log_file, $log_text, FILE_APPEND);
        }
    }
    
    /**
     * Log de erro
     */
    public static function error($mensagem, $exception = null) {
        $dados = [];
        
        if ($exception instanceof Exception) {
            $dados = [
                'erro' => $exception->getMessage(),
                'arquivo' => $exception->getFile(),
                'linha' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }
        
        self::log('ERRO', $mensagem, $dados);
    }
    
    /**
     * Log de sucesso
     */
    public static function success($mensagem, $dados = null) {
        self::log('SUCESSO', $mensagem, $dados);
    }
    
    /**
     * Log de warning
     */
    public static function warning($mensagem, $dados = null) {
        self::log('WARNING', $mensagem, $dados);
    }
    
    /**
     * Log de info
     */
    public static function info($mensagem, $dados = null) {
        self::log('INFO', $mensagem, $dados);
    }
    
    /**
     * Log de debug
     */
    public static function debug($mensagem, $dados = null) {
        self::log('DEBUG', $mensagem, $dados);
    }
    
    /**
     * Log de requisição HTTP
     */
    public static function logRequest() {
        $dados = [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
            'query_string' => $_SERVER['QUERY_STRING'] ?? 'N/A',
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'N/A',
            'headers' => self::getAllHeaders(),
            'get' => $_GET,
            'post' => self::sanitizePostData($_POST),
            'files' => $_FILES,
            'cookies' => $_COOKIE,
            'session' => isset($_SESSION) ? $_SESSION : 'Não iniciada'
        ];
        
        self::log('REQUEST', 'Dados da requisição HTTP', $dados);
    }
    
    /**
     * Log de resposta
     */
    public static function logResponse($response_data) {
        self::log('RESPONSE', 'Resposta enviada', $response_data);
    }
    
    /**
     * Log de query SQL
     */
    public static function logQuery($query, $params = null) {
        self::log('SQL', 'Query executada', [
            'query' => $query,
            'params' => $params
        ]);
    }
    
    /**
     * Obter todos os logs
     */
    public static function getLogs() {
        return self::$logs;
    }
    
    /**
     * Retornar logs como JSON
     */
    public static function getLogsJson() {
        return json_encode(self::$logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Limpar logs
     */
    public static function clearLogs() {
        self::$logs = [];
    }
    
    /**
     * Obter tempo de execução
     */
    private static function getExecutionTime() {
        return number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 4);
    }
    
    /**
     * Formatar bytes
     */
    private static function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Obter todos os headers
     */
    private static function getAllHeaders() {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
    
    /**
     * Sanitizar dados POST (esconder senhas)
     */
    private static function sanitizePostData($post) {
        $sanitized = $post;
        $sensitive_fields = ['senha', 'password', 'pass', 'pwd', 'token', 'secret'];
        
        foreach ($sensitive_fields as $field) {
            if (isset($sanitized[$field])) {
                $sanitized[$field] = '***HIDDEN***';
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Finalizar e salvar resumo
     */
    public static function finalize() {
        self::log('FIM', 'Requisição finalizada', [
            'total_logs' => count(self::$logs),
            'tempo_total' => self::getExecutionTime() . 's',
            'memoria_pico' => self::formatBytes(memory_get_peak_usage())
        ]);
    }
}

// Inicializar automaticamente
DebugSystem::init(true);

// Registrar shutdown function para finalizar logs
register_shutdown_function(function() {
    DebugSystem::finalize();
});
?>
