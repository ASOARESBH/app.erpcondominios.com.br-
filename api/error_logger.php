<?php
/**
 * =====================================================
 * ERROR LOGGER - Sistema de Logging de Erros
 * =====================================================
 * 
 * Classe responsável por registrar erros da aplicação
 * em arquivo de log para auditoria e debugging.
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 25/01/2026
 * @version 1.0
 */

class ErrorLogger {
    
    private $logDir = 'logs';
    private $logFile = 'erros.log';
    private $maxLogSize = 5242880; // 5MB
    
    /**
     * Construtor - criar diretório de logs se não existir
     */
    public function __construct() {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
    
    /**
     * Registrar erro no arquivo de log
     * 
     * @param string $mensagem Mensagem de erro
     * @param string $tipo Tipo de erro (ERROR, WARNING, INFO)
     * @param array $contexto Contexto adicional (opcional)
     * @return bool True se registrado com sucesso
     */
    public function registrar($mensagem, $tipo = 'ERROR', $contexto = []) {
        try {
            $logPath = $this->logDir . '/' . $this->logFile;
            
            // Verificar tamanho do arquivo e fazer rotação se necessário
            if (file_exists($logPath) && filesize($logPath) > $this->maxLogSize) {
                $this->rotacionarLog($logPath);
            }
            
            // Preparar dados do log
            $timestamp = date('Y-m-d H:i:s');
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
            $usuario = $_SESSION['usuario_id'] ?? 'UNKNOWN';
            $metodo = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            $uri = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN';
            
            // Construir linha de log
            $logLine = "[$timestamp] [$tipo] [IP: $ip] [USER: $usuario] [$metodo $uri]\n";
            $logLine .= "Mensagem: $mensagem\n";
            
            // Adicionar contexto se fornecido
            if (!empty($contexto)) {
                $logLine .= "Contexto: " . json_encode($contexto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            }
            
            // Adicionar stack trace se disponível
            if (isset($contexto['stack_trace'])) {
                $logLine .= "Stack Trace:\n" . $contexto['stack_trace'] . "\n";
            }
            
            $logLine .= str_repeat("-", 80) . "\n\n";
            
            // Escrever no arquivo
            $resultado = file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
            
            return $resultado !== false;
            
        } catch (Exception $e) {
            error_log("Erro ao registrar log: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Registrar erro de API
     * 
     * @param string $acao Ação que causou o erro
     * @param string $mensagem Mensagem de erro
     * @param array $dados Dados enviados (opcional)
     * @param Exception $exception Exceção (opcional)
     */
    public function registrarErroAPI($acao, $mensagem, $dados = [], $exception = null) {
        $contexto = [
            'acao' => $acao,
            'dados_enviados' => $dados,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($exception) {
            $contexto['stack_trace'] = $exception->getTraceAsString();
            $contexto['arquivo'] = $exception->getFile();
            $contexto['linha'] = $exception->getLine();
        }
        
        return $this->registrar($mensagem, 'API_ERROR', $contexto);
    }
    
    /**
     * Registrar erro de validação
     * 
     * @param string $campo Campo que falhou na validação
     * @param string $mensagem Mensagem de validação
     * @param mixed $valor Valor enviado
     */
    public function registrarErroValidacao($campo, $mensagem, $valor = null) {
        $contexto = [
            'campo' => $campo,
            'valor' => $valor,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return $this->registrar("Validação falhou: $mensagem", 'VALIDATION_ERROR', $contexto);
    }
    
    /**
     * Registrar erro de banco de dados
     * 
     * @param string $query Query que falhou
     * @param string $mensagem Mensagem de erro
     * @param Exception $exception Exceção do banco
     */
    public function registrarErroBD($query, $mensagem, $exception = null) {
        $contexto = [
            'query' => $query,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($exception) {
            $contexto['stack_trace'] = $exception->getTraceAsString();
        }
        
        return $this->registrar("Erro de Banco de Dados: $mensagem", 'DATABASE_ERROR', $contexto);
    }
    
    /**
     * Registrar aviso
     * 
     * @param string $mensagem Mensagem de aviso
     * @param array $contexto Contexto adicional
     */
    public function registrarAviso($mensagem, $contexto = []) {
        return $this->registrar($mensagem, 'WARNING', $contexto);
    }
    
    /**
     * Registrar informação
     * 
     * @param string $mensagem Mensagem de informação
     * @param array $contexto Contexto adicional
     */
    public function registrarInfo($mensagem, $contexto = []) {
        return $this->registrar($mensagem, 'INFO', $contexto);
    }
    
    /**
     * Rotacionar arquivo de log
     * 
     * @param string $logPath Caminho do arquivo de log
     */
    private function rotacionarLog($logPath) {
        $timestamp = date('Y-m-d_H-i-s');
        $novoNome = str_replace('.log', "_$timestamp.log", $logPath);
        
        if (rename($logPath, $novoNome)) {
            // Comprimir arquivo antigo se possível
            if (function_exists('gzcompress')) {
                $conteudo = file_get_contents($novoNome);
                $comprimido = gzcompress($conteudo, 9);
                file_put_contents($novoNome . '.gz', $comprimido);
                unlink($novoNome);
            }
        }
    }
    
    /**
     * Obter últimas linhas do log
     * 
     * @param int $linhas Número de linhas a retornar
     * @return array Array com as linhas
     */
    public function obterUltimas($linhas = 100) {
        $logPath = $this->logDir . '/' . $this->logFile;
        
        if (!file_exists($logPath)) {
            return [];
        }
        
        $conteudo = file_get_contents($logPath);
        $todasAsLinhas = explode("\n", $conteudo);
        
        return array_slice($todasAsLinhas, -$linhas);
    }
    
    /**
     * Limpar arquivo de log
     * 
     * @return bool True se limpado com sucesso
     */
    public function limpar() {
        $logPath = $this->logDir . '/' . $this->logFile;
        
        if (file_exists($logPath)) {
            return unlink($logPath);
        }
        
        return true;
    }
}

// Criar instância global
$errorLogger = new ErrorLogger();

// Definir manipulador de erros personalizado
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    global $errorLogger;
    
    $tipoErro = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE_ERROR',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE_ERROR',
        E_CORE_WARNING => 'CORE_WARNING',
        E_COMPILE_ERROR => 'COMPILE_ERROR',
        E_COMPILE_WARNING => 'COMPILE_WARNING',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
        E_STRICT => 'STRICT',
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER_DEPRECATED'
    ];
    
    $tipo = $tipoErro[$errno] ?? 'UNKNOWN_ERROR';
    
    $errorLogger->registrar(
        "[$tipo] $errstr em $errfile:$errline",
        $tipo,
        ['arquivo' => $errfile, 'linha' => $errline]
    );
    
    return false;
});

// Definir manipulador de exceções não capturadas
set_exception_handler(function($exception) {
    global $errorLogger;
    
    $errorLogger->registrar(
        $exception->getMessage(),
        'UNCAUGHT_EXCEPTION',
        [
            'arquivo' => $exception->getFile(),
            'linha' => $exception->getLine(),
            'stack_trace' => $exception->getTraceAsString()
        ]
    );
    
    // Retornar resposta JSON de erro
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro interno do servidor. Por favor, contate o administrador.',
        'erro_id' => uniqid('ERR_')
    ]);
    
    exit;
});

?>
