<?php
/**
 * Sistema de Debug para Acessos de Visitantes
 * Arquivo: visitante_acesso_debug.php
 * Data: 29/01/2026
 */

define('DEBUG_DIR', __DIR__);
define('DEBUG_FILE_PREFIX', 'visitante_acesso_');
define('DEBUG_FILE_EXTENSION', '.log');

class VisitanteAcessoDebug {
    private $arquivo_log;
    private $data_hora;
    
    public function __construct() {
        $data = date('Y-m-d');
        $this->arquivo_log = DEBUG_DIR . '/' . DEBUG_FILE_PREFIX . $data . DEBUG_FILE_EXTENSION;
        $this->data_hora = date('Y-m-d H:i:s');
    }
    
    public function registrar($tipo, $funcao, $mensagem, $contexto = []) {
        $log_entry = [
            'timestamp' => $this->data_hora,
            'tipo' => strtoupper($tipo),
            'funcao' => $funcao,
            'mensagem' => $mensagem,
            'ip_cliente' => $_SERVER['REMOTE_ADDR'] ?? 'DESCONHECIDO',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'DESCONHECIDO', 0, 100),
            'metodo_http' => $_SERVER['REQUEST_METHOD'] ?? 'DESCONHECIDO',
            'url' => $_SERVER['REQUEST_URI'] ?? 'DESCONHECIDO',
            'contexto' => $contexto
        ];
        
        $this->escrever_log($log_entry);
    }
    
    public function erro_validacao($campo, $mensagem, $valor = null) {
        $this->registrar('ERROR', 'validacao', "Erro ao validar campo: $campo", [
            'campo' => $campo,
            'mensagem' => $mensagem,
            'valor' => $valor
        ]);
    }
    
    public function erro_api($acao, $codigo_http, $mensagem, $dados = []) {
        $this->registrar('ERROR', 'api_' . $acao, "Erro HTTP $codigo_http: $mensagem", [
            'acao' => $acao,
            'codigo_http' => $codigo_http,
            'dados' => $dados
        ]);
    }
    
    public function erro_banco($query, $erro_mysql, $dados = []) {
        $this->registrar('ERROR', 'banco_dados', "Erro MySQL: $erro_mysql", [
            'query' => $query,
            'erro_mysql' => $erro_mysql,
            'dados' => $dados
        ]);
    }
    
    public function sucesso($acao, $mensagem, $dados = []) {
        $this->registrar('SUCCESS', $acao, $mensagem, $dados);
    }
    
    public function aviso($acao, $mensagem, $dados = []) {
        $this->registrar('WARNING', $acao, $mensagem, $dados);
    }
    
    public function info($acao, $mensagem, $dados = []) {
        $this->registrar('INFO', $acao, $mensagem, $dados);
    }
    
    private function escrever_log($entrada) {
        try {
            if (!is_dir(DEBUG_DIR)) {
                mkdir(DEBUG_DIR, 0755, true);
            }
            
            $json_entry = json_encode($entrada, JSON_UNESCAPED_UNICODE) . "\n";
            file_put_contents($this->arquivo_log, $json_entry, FILE_APPEND | LOCK_EX);
            chmod($this->arquivo_log, 0644);
            
        } catch (Exception $e) {
            error_log("Erro ao escrever log: " . $e->getMessage());
        }
    }
    
    public function obter_logs_dia($filtro_tipo = null) {
        if (!file_exists($this->arquivo_log)) {
            return [];
        }
        
        $linhas = file($this->arquivo_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs = [];
        
        foreach ($linhas as $linha) {
            $entrada = json_decode($linha, true);
            if ($entrada === null) continue;
            if ($filtro_tipo && $entrada['tipo'] !== strtoupper($filtro_tipo)) continue;
            $logs[] = $entrada;
        }
        
        return $logs;
    }
    
    public function obter_estatisticas() {
        $logs = $this->obter_logs_dia();
        $stats = [
            'total' => count($logs),
            'erros' => 0,
            'avisos' => 0,
            'sucessos' => 0,
            'info' => 0,
            'por_funcao' => [],
            'por_ip' => []
        ];
        
        foreach ($logs as $log) {
            $tipo = $log['tipo'];
            if ($tipo === 'ERROR') $stats['erros']++;
            elseif ($tipo === 'WARNING') $stats['avisos']++;
            elseif ($tipo === 'SUCCESS') $stats['sucessos']++;
            elseif ($tipo === 'INFO') $stats['info']++;
            
            $funcao = $log['funcao'] ?? 'desconhecida';
            $stats['por_funcao'][$funcao] = ($stats['por_funcao'][$funcao] ?? 0) + 1;
            
            $ip = $log['ip_cliente'] ?? 'desconhecido';
            $stats['por_ip'][$ip] = ($stats['por_ip'][$ip] ?? 0) + 1;
        }
        
        return $stats;
    }
}

$visitante_acesso_debug = new VisitanteAcessoDebug();

function registrar_erro_visitante_acesso($tipo, $funcao, $mensagem, $contexto = []) {
    global $visitante_acesso_debug;
    $visitante_acesso_debug->registrar($tipo, $funcao, $mensagem, $contexto);
}

function registrar_sucesso_visitante_acesso($acao, $mensagem, $dados = []) {
    global $visitante_acesso_debug;
    $visitante_acesso_debug->sucesso($acao, $mensagem, $dados);
}

?>
