<?php
// =====================================================
// MODEL - GERENCIAMENTO DE SESSÕES
// =====================================================
// Padrão MVC - Camada de Dados
// Responsável por interagir com banco de dados para sessões

class SessionModel {
    private $conexao;
    private $tabela = 'sessoes_usuarios';
    
    /**
     * Construtor
     * @param mysqli $conexao Conexão com banco de dados
     */
    public function __construct($conexao) {
        $this->conexao = $conexao;
    }
    
    /**
     * Criar tabela de sessões se não existir
     */
    public function criarTabelaSessoes() {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->tabela}` (
            `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `usuario_id` INT(11) NOT NULL,
            `session_id` VARCHAR(255) NOT NULL UNIQUE,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` TEXT,
            `data_login` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `data_expiracao` TIMESTAMP NOT NULL,
            `ultima_atividade` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `ativo` TINYINT(1) DEFAULT 1,
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
            INDEX `idx_usuario_id` (`usuario_id`),
            INDEX `idx_session_id` (`session_id`),
            INDEX `idx_ativo` (`ativo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        if (!$this->conexao->query($sql)) {
            throw new Exception('Erro ao criar tabela de sessões: ' . $this->conexao->error);
        }
        
        return true;
    }
    
    /**
     * Registrar nova sessão no banco
     * @param int $usuario_id ID do usuário
     * @param string $session_id ID da sessão PHP
     * @param int $duracao_segundos Duração da sessão em segundos
     * @return bool
     */
    public function registrarSessao($usuario_id, $session_id, $duracao_segundos = 7200) {
        try {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $data_expiracao = date('Y-m-d H:i:s', time() + $duracao_segundos);
            
            $stmt = $this->conexao->prepare(
                "INSERT INTO {$this->tabela} (usuario_id, session_id, ip_address, user_agent, data_expiracao) 
                 VALUES (?, ?, ?, ?, ?)"
            );
            
            if (!$stmt) {
                throw new Exception('Erro ao preparar statement: ' . $this->conexao->error);
            }
            
            $stmt->bind_param('issss', $usuario_id, $session_id, $ip_address, $user_agent, $data_expiracao);
            
            if (!$stmt->execute()) {
                throw new Exception('Erro ao executar statement: ' . $stmt->error);
            }
            
            $stmt->close();
            return true;
            
        } catch (Exception $e) {
            error_log('Erro ao registrar sessão: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obter informações da sessão ativa
     * @param string $session_id ID da sessão
     * @return array|null
     */
    public function obterSessaoAtiva($session_id) {
        try {
            $stmt = $this->conexao->prepare(
                "SELECT s.*, u.nome, u.email, u.funcao, u.departamento, u.permissao 
                 FROM {$this->tabela} s
                 INNER JOIN usuarios u ON s.usuario_id = u.id
                 WHERE s.session_id = ? AND s.ativo = 1 AND s.data_expiracao > NOW()"
            );
            
            if (!$stmt) {
                throw new Exception('Erro ao preparar statement: ' . $this->conexao->error);
            }
            
            $stmt->bind_param('s', $session_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Erro ao executar statement: ' . $stmt->error);
            }
            
            $resultado = $stmt->get_result();
            $sessao = $resultado->fetch_assoc();
            $stmt->close();
            
            return $sessao;
            
        } catch (Exception $e) {
            error_log('Erro ao obter sessão ativa: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Atualizar última atividade da sessão
     * @param string $session_id ID da sessão
     * @return bool
     */
    public function atualizarUltimaAtividade($session_id) {
        try {
            $stmt = $this->conexao->prepare(
                "UPDATE {$this->tabela} SET ultima_atividade = NOW() 
                 WHERE session_id = ? AND ativo = 1"
            );
            
            if (!$stmt) {
                throw new Exception('Erro ao preparar statement: ' . $this->conexao->error);
            }
            
            $stmt->bind_param('s', $session_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Erro ao executar statement: ' . $stmt->error);
            }
            
            $stmt->close();
            return true;
            
        } catch (Exception $e) {
            error_log('Erro ao atualizar última atividade: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Calcular tempo restante da sessão
     * @param string $session_id ID da sessão
     * @return array|null Retorna array com tempo em segundos e formatado
     */
    public function calcularTempoRestante($session_id) {
        try {
            $stmt = $this->conexao->prepare(
                "SELECT TIMESTAMPDIFF(SECOND, NOW(), data_expiracao) as segundos_restantes,
                        data_login, data_expiracao, ultima_atividade
                 FROM {$this->tabela}
                 WHERE session_id = ? AND ativo = 1"
            );
            
            if (!$stmt) {
                throw new Exception('Erro ao preparar statement: ' . $this->conexao->error);
            }
            
            $stmt->bind_param('s', $session_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Erro ao executar statement: ' . $stmt->error);
            }
            
            $resultado = $stmt->get_result();
            $dados = $resultado->fetch_assoc();
            $stmt->close();
            
            if (!$dados) {
                return null;
            }
            
            $segundos_restantes = max(0, (int)$dados['segundos_restantes']);
            
            return [
                'segundos_restantes' => $segundos_restantes,
                'tempo_formatado' => $this->formatarTempo($segundos_restantes),
                'data_login' => $dados['data_login'],
                'data_expiracao' => $dados['data_expiracao'],
                'ultima_atividade' => $dados['ultima_atividade'],
                'tempo_decorrido' => $this->calcularTempoDecorrido($dados['data_login'])
            ];
            
        } catch (Exception $e) {
            error_log('Erro ao calcular tempo restante: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Encerrar sessão
     * @param string $session_id ID da sessão
     * @return bool
     */
    public function encerrarSessao($session_id) {
        try {
            $stmt = $this->conexao->prepare(
                "UPDATE {$this->tabela} SET ativo = 0 WHERE session_id = ?"
            );
            
            if (!$stmt) {
                throw new Exception('Erro ao preparar statement: ' . $this->conexao->error);
            }
            
            $stmt->bind_param('s', $session_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Erro ao executar statement: ' . $stmt->error);
            }
            
            $stmt->close();
            return true;
            
        } catch (Exception $e) {
            error_log('Erro ao encerrar sessão: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Limpar sessões expiradas
     * @return int Número de sessões removidas
     */
    public function limparSessoesExpiradas() {
        try {
            $stmt = $this->conexao->prepare(
                "DELETE FROM {$this->tabela} WHERE data_expiracao < NOW() OR ativo = 0"
            );
            
            if (!$stmt) {
                throw new Exception('Erro ao preparar statement: ' . $this->conexao->error);
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Erro ao executar statement: ' . $stmt->error);
            }
            
            $linhas_afetadas = $stmt->affected_rows;
            $stmt->close();
            
            return $linhas_afetadas;
            
        } catch (Exception $e) {
            error_log('Erro ao limpar sessões expiradas: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Obter todas as sessões ativas de um usuário
     * @param int $usuario_id ID do usuário
     * @return array
     */
    public function obterSessoesAtivasUsuario($usuario_id) {
        try {
            $stmt = $this->conexao->prepare(
                "SELECT id, session_id, ip_address, data_login, data_expiracao, ultima_atividade
                 FROM {$this->tabela}
                 WHERE usuario_id = ? AND ativo = 1 AND data_expiracao > NOW()
                 ORDER BY data_login DESC"
            );
            
            if (!$stmt) {
                throw new Exception('Erro ao preparar statement: ' . $this->conexao->error);
            }
            
            $stmt->bind_param('i', $usuario_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Erro ao executar statement: ' . $stmt->error);
            }
            
            $resultado = $stmt->get_result();
            $sessoes = [];
            
            while ($row = $resultado->fetch_assoc()) {
                $sessoes[] = $row;
            }
            
            $stmt->close();
            return $sessoes;
            
        } catch (Exception $e) {
            error_log('Erro ao obter sessões ativas do usuário: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Formatar tempo em segundos para formato legível
     * @param int $segundos Tempo em segundos
     * @return string Tempo formatado (HH:MM:SS)
     */
    private function formatarTempo($segundos) {
        $horas = floor($segundos / 3600);
        $minutos = floor(($segundos % 3600) / 60);
        $segs = $segundos % 60;
        
        return sprintf('%02d:%02d:%02d', $horas, $minutos, $segs);
    }
    
    /**
     * Calcular tempo decorrido desde o login
     * @param string $data_login Data de login (formato MySQL)
     * @return array Tempo em segundos e formatado
     */
    private function calcularTempoDecorrido($data_login) {
        $data_login_timestamp = strtotime($data_login);
        $tempo_decorrido = time() - $data_login_timestamp;
        
        return [
            'segundos' => $tempo_decorrido,
            'formatado' => $this->formatarTempo($tempo_decorrido)
        ];
    }
}
?>
