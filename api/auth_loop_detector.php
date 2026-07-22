<?php
/**
 * =====================================================
 * SISTEMA DE DETECÇÃO E RASTREAMENTO DE LOOP DE AUTENTICAÇÃO
 * =====================================================
 * 
 * Incluir em TODAS as APIs de autenticação:
 * require_once 'auth_loop_detector.php';
 * $detector = new AuthLoopDetector();
 * $detector->registrarTentativa('morador', $cpf, $_SERVER['REMOTE_ADDR']);
 * 
 * @author Sistema ERP
 * @version 1.0.0
 * @date 2026-01-30
 */

class AuthLoopDetector {
    
    private $log_dir = '/var/log/erp_auth/';
    private $max_tentativas = 5;
    private $tempo_bloqueio = 300; // 5 minutos
    
    public function __construct() {
        if (!is_dir($this->log_dir)) {
            @mkdir($this->log_dir, 0755, true);
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public function registrarTentativa($tipo_usuario, $usuario, $ip, $sucesso = false, $motivo = '') {
        $session_id = session_id();
        $timestamp = date('Y-m-d H:i:s');
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido';
        
        $log_data = [
            'timestamp' => $timestamp,
            'session_id' => $session_id,
            'tipo_usuario' => $tipo_usuario,
            'usuario' => $this->sanitizar($usuario),
            'ip' => $ip,
            'sucesso' => $sucesso ? 'SIM' : 'NÃO',
            'motivo' => $motivo,
            'user_agent' => $user_agent,
            'url' => $_SERVER['REQUEST_URI'] ?? 'Desconhecida',
            'metodo' => $_SERVER['REQUEST_METHOD'] ?? 'Desconhecido'
        ];
        
        $this->escreverLog($log_data);
        
        $eh_loop = $this->detectarLoop($tipo_usuario, $usuario, $ip);
        
        if ($eh_loop) {
            $this->ativarFallback($tipo_usuario, $usuario, $ip, $log_data);
        }
        
        return [
            'registrado' => true,
            'eh_loop' => $eh_loop,
            'session_id' => $session_id
        ];
    }
    
    private function detectarLoop($tipo_usuario, $usuario, $ip) {
        $chave_sessao = "auth_tentativas_{$tipo_usuario}_{$usuario}";
        
        if (!isset($_SESSION[$chave_sessao])) {
            $_SESSION[$chave_sessao] = [
                'tentativas' => 0,
                'primeira_tentativa' => time(),
                'ips' => [],
                'urls' => []
            ];
        }
        
        $_SESSION[$chave_sessao]['tentativas']++;
        $_SESSION[$chave_sessao]['ips'][] = $ip;
        $_SESSION[$chave_sessao]['urls'][] = $_SERVER['REQUEST_URI'] ?? '';
        
        $tentativas = $_SESSION[$chave_sessao]['tentativas'];
        $tempo_decorrido = time() - $_SESSION[$chave_sessao]['primeira_tentativa'];
        
        if ($tentativas > $this->max_tentativas && $tempo_decorrido < 30) {
            return true;
        }
        
        if ($tempo_decorrido > $this->tempo_bloqueio) {
            unset($_SESSION[$chave_sessao]);
        }
        
        return false;
    }
    
    private function ativarFallback($tipo_usuario, $usuario, $ip, $log_data) {
        $alerta = [
            'timestamp' => date('Y-m-d H:i:s'),
            'tipo' => 'LOOP_DETECTADO',
            'tipo_usuario' => $tipo_usuario,
            'usuario' => $this->sanitizar($usuario),
            'ip' => $ip,
            'acao' => 'BLOQUEIO_AUTOMATICO',
            'motivo' => 'Múltiplas tentativas de login falhadas detectadas'
        ];
        
        $this->escreverAlerta($alerta);
        $this->bloquearIP($ip, $tipo_usuario);
        $this->limparSessao($tipo_usuario, $usuario);
    }
    
    private function bloquearIP($ip, $tipo_usuario) {
        $arquivo_bloqueio = $this->log_dir . "bloqueados_{$tipo_usuario}.txt";
        
        $bloqueio = [
            'ip' => $ip,
            'timestamp' => time(),
            'expiracao' => time() + $this->tempo_bloqueio,
            'motivo' => 'Loop de autenticação detectado'
        ];
        
        $conteudo = json_encode($bloqueio) . "\n";
        @file_put_contents($arquivo_bloqueio, $conteudo, FILE_APPEND);
    }
    
    public function verificarIPBloqueado($ip, $tipo_usuario) {
        $arquivo_bloqueio = $this->log_dir . "bloqueados_{$tipo_usuario}.txt";
        
        if (!file_exists($arquivo_bloqueio)) {
            return false;
        }
        
        $linhas = file($arquivo_bloqueio, FILE_IGNORE_NEW_LINES);
        
        foreach ($linhas as $linha) {
            if (empty($linha)) continue;
            
            $bloqueio = json_decode($linha, true);
            
            if ($bloqueio['ip'] === $ip && time() < $bloqueio['expiracao']) {
                return true;
            }
        }
        
        return false;
    }
    
    private function limparSessao($tipo_usuario, $usuario) {
        $chaves_sessao = [
            "auth_tentativas_{$tipo_usuario}_{$usuario}",
            "{$tipo_usuario}_id",
            "{$tipo_usuario}_token",
            "portal_token",
            "morador_id",
            "fornecedor_id"
        ];
        
        foreach ($chaves_sessao as $chave) {
            unset($_SESSION[$chave]);
        }
    }
    
    private function escreverLog($log_data) {
        $arquivo_log = $this->log_dir . 'auth_' . date('Y-m-d') . '.log';
        $linha = json_encode($log_data, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($arquivo_log, $linha, FILE_APPEND);
    }
    
    private function escreverAlerta($alerta) {
        $arquivo_alerta = $this->log_dir . 'alertas_' . date('Y-m-d') . '.log';
        $linha = json_encode($alerta, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($arquivo_alerta, $linha, FILE_APPEND);
    }
    
    private function sanitizar($dados) {
        return htmlspecialchars($dados, ENT_QUOTES, 'UTF-8');
    }
    
    public function obterLogsUsuario($tipo_usuario, $usuario, $dias = 7) {
        $logs = [];
        
        for ($i = 0; $i < $dias; $i++) {
            $data = date('Y-m-d', strtotime("-$i days"));
            $arquivo_log = $this->log_dir . "auth_{$data}.log";
            
            if (!file_exists($arquivo_log)) {
                continue;
            }
            
            $linhas = file($arquivo_log, FILE_IGNORE_NEW_LINES);
            
            foreach ($linhas as $linha) {
                if (empty($linha)) continue;
                
                $log = json_decode($linha, true);
                
                if ($log['tipo_usuario'] === $tipo_usuario && 
                    strpos($log['usuario'], $usuario) !== false) {
                    $logs[] = $log;
                }
            }
        }
        
        return $logs;
    }
    
    public function obterAlertas($dias = 7) {
        $alertas = [];
        
        for ($i = 0; $i < $dias; $i++) {
            $data = date('Y-m-d', strtotime("-$i days"));
            $arquivo_alerta = $this->log_dir . "alertas_{$data}.log";
            
            if (!file_exists($arquivo_alerta)) {
                continue;
            }
            
            $linhas = file($arquivo_alerta, FILE_IGNORE_NEW_LINES);
            
            foreach ($linhas as $linha) {
                if (empty($linha)) continue;
                $alertas[] = json_decode($linha, true);
            }
        }
        
        return $alertas;
    }
    
    public function gerarRelatorioSeguranca() {
        $alertas = $this->obterAlertas(7);
        $loops_detectados = array_filter($alertas, function($a) {
            return $a['tipo'] === 'LOOP_DETECTADO';
        });
        
        return [
            'total_alertas' => count($alertas),
            'loops_detectados' => count($loops_detectados),
            'ips_bloqueados' => $this->contarIPsBloqueados(),
            'alertas_recentes' => array_slice($alertas, 0, 10)
        ];
    }
    
    private function contarIPsBloqueados() {
        $tipos = ['morador', 'fornecedor', 'associado'];
        $total = 0;
        
        foreach ($tipos as $tipo) {
            $arquivo = $this->log_dir . "bloqueados_{$tipo}.txt";
            if (file_exists($arquivo)) {
                $linhas = file($arquivo, FILE_IGNORE_NEW_LINES);
                $total += count(array_filter($linhas));
            }
        }
        
        return $total;
    }
}

function iniciarDetectorLoop() {
    return new AuthLoopDetector();
}
?>
