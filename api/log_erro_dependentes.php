<?php
/**
 * =====================================================
 * LOG DE ERROS: log_erro_dependentes.php
 * =====================================================
 * 
 * Função robusta para registrar erros do módulo de dependentes
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 25/01/2026
 * @version 1.0
 */

/**
 * Registrar erro de dependentes
 * 
 * @param string $tipo Tipo do erro (criar, atualizar, deletar, etc.)
 * @param string $mensagem Mensagem do erro
 * @param array $contexto Contexto adicional (dados, stack trace, etc.)
 * @param string $nivel Nível do erro (ERROR, WARNING, INFO, DEBUG)
 * @return bool True se registrado com sucesso
 */
function registrarErroDependente($tipo, $mensagem, $contexto = [], $nivel = 'ERROR') {
    try {
        // Definir diretório de logs
        $dir_logs = __DIR__ . '/logs';
        
        // Criar diretório se não existir
        if (!is_dir($dir_logs)) {
            if (!mkdir($dir_logs, 0755, true)) {
                error_log("Falha ao criar diretório de logs: {$dir_logs}");
                return false;
            }
        }
        
        // Nome do arquivo de log
        $arquivo_log = $dir_logs . '/dependentes_' . date('Y-m-d') . '.log';
        
        // Preparar entrada do log
        $timestamp = date('Y-m-d H:i:s');
        $usuario_id = isset($_SESSION['usuario_id']) ? $_SESSION['usuario_id'] : 'N/A';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
        
        // Montar mensagem de log
        $log_entry = [
            'timestamp' => $timestamp,
            'nivel' => $nivel,
            'tipo' => $tipo,
            'mensagem' => $mensagem,
            'usuario_id' => $usuario_id,
            'ip' => $ip,
            'user_agent' => $user_agent,
            'contexto' => $contexto,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A'
        ];
        
        // Formatar log
        $log_formatado = "[{$timestamp}] [{$nivel}] [{$tipo}]\n";
        $log_formatado .= "Mensagem: {$mensagem}\n";
        $log_formatado .= "Usuário ID: {$usuario_id}\n";
        $log_formatado .= "IP: {$ip}\n";
        $log_formatado .= "Request: {$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']}\n";
        
        if (!empty($contexto)) {
            $log_formatado .= "Contexto:\n";
            $log_formatado .= json_encode($contexto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        
        $log_formatado .= str_repeat('-', 80) . "\n\n";
        
        // Escrever no arquivo
        $resultado = file_put_contents($arquivo_log, $log_formatado, FILE_APPEND | LOCK_EX);
        
        if ($resultado === false) {
            error_log("Falha ao escrever no arquivo de log: {$arquivo_log}");
            return false;
        }
        
        // Rotacionar logs se arquivo muito grande (> 10 MB)
        if (file_exists($arquivo_log) && filesize($arquivo_log) > 10 * 1024 * 1024) {
            $arquivo_rotacionado = $arquivo_log . '.' . date('YmdHis') . '.old';
            rename($arquivo_log, $arquivo_rotacionado);
            
            // Manter apenas últimos 5 arquivos rotacionados
            $arquivos_antigos = glob($dir_logs . '/dependentes_*.log.*.old');
            if (count($arquivos_antigos) > 5) {
                usort($arquivos_antigos, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                
                // Deletar os mais antigos
                for ($i = 0; $i < count($arquivos_antigos) - 5; $i++) {
                    unlink($arquivos_antigos[$i]);
                }
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Exceção ao registrar erro de dependente: " . $e->getMessage());
        return false;
    }
}

/**
 * Registrar sucesso de operação
 * 
 * @param string $tipo Tipo da operação (criar, atualizar, deletar, etc.)
 * @param string $mensagem Mensagem de sucesso
 * @param array $dados Dados da operação
 * @return bool True se registrado com sucesso
 */
function registrarSucessoDependente($tipo, $mensagem, $dados = []) {
    return registrarErroDependente($tipo, $mensagem, $dados, 'INFO');
}

/**
 * Registrar aviso
 * 
 * @param string $tipo Tipo do aviso
 * @param string $mensagem Mensagem de aviso
 * @param array $contexto Contexto adicional
 * @return bool True se registrado com sucesso
 */
function registrarAvisoDependente($tipo, $mensagem, $contexto = []) {
    return registrarErroDependente($tipo, $mensagem, $contexto, 'WARNING');
}

/**
 * Registrar debug
 * 
 * @param string $tipo Tipo do debug
 * @param string $mensagem Mensagem de debug
 * @param array $contexto Contexto adicional
 * @return bool True se registrado com sucesso
 */
function registrarDebugDependente($tipo, $mensagem, $contexto = []) {
    return registrarErroDependente($tipo, $mensagem, $contexto, 'DEBUG');
}

/**
 * Obter últimas entradas do log
 * 
 * @param int $quantidade Quantidade de entradas a retornar
 * @param string $nivel Filtrar por nível (opcional)
 * @return array Array com entradas do log
 */
function obterUltimosLogsDependentes($quantidade = 50, $nivel = null) {
    try {
        $arquivo_log = __DIR__ . '/logs/dependentes_' . date('Y-m-d') . '.log';
        
        if (!file_exists($arquivo_log)) {
            return [];
        }
        
        $conteudo = file_get_contents($arquivo_log);
        $entradas = explode(str_repeat('-', 80), $conteudo);
        
        // Filtrar por nível se especificado
        if ($nivel) {
            $entradas = array_filter($entradas, function($entrada) use ($nivel) {
                return strpos($entrada, "[{$nivel}]") !== false;
            });
        }
        
        // Pegar últimas N entradas
        $entradas = array_slice(array_reverse($entradas), 0, $quantidade);
        
        return $entradas;
        
    } catch (Exception $e) {
        error_log("Erro ao obter logs de dependentes: " . $e->getMessage());
        return [];
    }
}

/**
 * Limpar logs antigos
 * 
 * @param int $dias Manter logs dos últimos N dias
 * @return int Quantidade de arquivos removidos
 */
function limparLogsAntigosDependentes($dias = 30) {
    try {
        $dir_logs = __DIR__ . '/logs';
        
        if (!is_dir($dir_logs)) {
            return 0;
        }
        
        $arquivos = glob($dir_logs . '/dependentes_*.log');
        $removidos = 0;
        $data_limite = strtotime("-{$dias} days");
        
        foreach ($arquivos as $arquivo) {
            if (filemtime($arquivo) < $data_limite) {
                if (unlink($arquivo)) {
                    $removidos++;
                }
            }
        }
        
        return $removidos;
        
    } catch (Exception $e) {
        error_log("Erro ao limpar logs antigos de dependentes: " . $e->getMessage());
        return 0;
    }
}
?>
