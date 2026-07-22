<?php
// =====================================================
// CONTROLLER - GERENCIAMENTO DE SESSÕES
// =====================================================
// Padrão MVC - Camada de Lógica
// Responsável por processar requisições de sessão

require_once __DIR__ . '/../models/SessionModel.php';

class SessionController {
    private $model;
    private $conexao;
    
    /**
     * Construtor
     * @param mysqli $conexao Conexão com banco de dados
     */
    public function __construct($conexao) {
        $this->conexao = $conexao;
        $this->model = new SessionModel($conexao);
    }
    
    /**
     * Obter informações do usuário logado e tempo de sessão
     * @return array
     */
    public function obterDadosUsuarioLogado() {
        try {
            // Verificar se usuário está logado na sessão PHP
            if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'Usuário não autenticado',
                    'logado' => false
                ];
            }
            
            // Verificar se ID do usuário existe
            if (!isset($_SESSION['usuario_id'])) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'Sessão inválida',
                    'logado' => false
                ];
            }
            
            // Obter ID da sessão
            $session_id = session_id();
            
            // Buscar informações da sessão no banco
            $sessao_db = $this->model->obterSessaoAtiva($session_id);
            
            // Se não encontrar no banco, usar dados da sessão PHP
            $dados_usuario = [
                'id' => $_SESSION['usuario_id'],
                'nome' => $_SESSION['usuario_nome'] ?? 'Usuário',
                'email' => $_SESSION['usuario_email'] ?? '',
                'funcao' => $_SESSION['usuario_funcao'] ?? '',
                'departamento' => $_SESSION['usuario_departamento'] ?? '',
                'permissao' => $_SESSION['usuario_permissao'] ?? 'operador'
            ];
            
            // Calcular tempo de sessão
            $tempo_sessao = $this->calcularTempoSessao();
            
            // Atualizar última atividade no banco
            $this->model->atualizarUltimaAtividade($session_id);
            
            return [
                'sucesso' => true,
                'logado' => true,
                'usuario' => $dados_usuario,
                'sessao' => [
                    'id' => $session_id,
                    'tempo_decorrido' => $tempo_sessao['tempo_decorrido'],
                    'tempo_restante' => $tempo_sessao['tempo_restante'],
                    'tempo_decorrido_formatado' => $tempo_sessao['tempo_decorrido_formatado'],
                    'tempo_restante_formatado' => $tempo_sessao['tempo_restante_formatado'],
                    'data_login' => $sessao_db['data_login'] ?? date('Y-m-d H:i:s', $_SESSION['login_timestamp'] ?? time()),
                    'data_expiracao' => $sessao_db['data_expiracao'] ?? date('Y-m-d H:i:s', time() + 7200),
                    'ip_address' => $sessao_db['ip_address'] ?? $_SERVER['REMOTE_ADDR'],
                    'ativo' => true
                ]
            ];
            
        } catch (Exception $e) {
            error_log('Erro em obterDadosUsuarioLogado: ' . $e->getMessage());
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao obter dados da sessão',
                'logado' => false
            ];
        }
    }
    
    /**
     * Registrar nova sessão no banco
     * @param int $usuario_id ID do usuário
     * @return bool
     */
    public function registrarNovaSessionao($usuario_id) {
        try {
            $session_id = session_id();
            $duracao = 7200; // 2 horas
            
            return $this->model->registrarSessao($usuario_id, $session_id, $duracao);
            
        } catch (Exception $e) {
            error_log('Erro em registrarNovaSessionao: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Renovar sessão (estender tempo)
     * @return array
     */
    public function renovarSessao() {
        try {
            if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'Usuário não autenticado'
                ];
            }
            
            // Renovar timestamp
            $_SESSION['login_timestamp'] = time();
            
            // Atualizar no banco
            $session_id = session_id();
            $this->model->atualizarUltimaAtividade($session_id);
            
            return [
                'sucesso' => true,
                'mensagem' => 'Sessão renovada com sucesso',
                'novo_timestamp' => $_SESSION['login_timestamp']
            ];
            
        } catch (Exception $e) {
            error_log('Erro em renovarSessao: ' . $e->getMessage());
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao renovar sessão'
            ];
        }
    }
    
    /**
     * Encerrar sessão (logout)
     * @return array
     */
    public function encerrarSessao() {
        try {
            $session_id = session_id();
            
            // Registrar logout no banco
            if (isset($_SESSION['usuario_nome'])) {
                $this->registrarLogout($_SESSION['usuario_email'] ?? 'desconhecido', $_SESSION['usuario_nome']);
            }
            
            // Encerrar no banco
            $this->model->encerrarSessao($session_id);
            
            // Destruir sessão PHP
            $_SESSION = [];
            
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            session_destroy();
            
            return [
                'sucesso' => true,
                'mensagem' => 'Logout realizado com sucesso'
            ];
            
        } catch (Exception $e) {
            error_log('Erro em encerrarSessao: ' . $e->getMessage());
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao fazer logout'
            ];
        }
    }
    
    /**
     * Obter todas as sessões ativas do usuário
     * @return array
     */
    public function obterSessoesAtivasUsuario() {
        try {
            if (!isset($_SESSION['usuario_id'])) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'Usuário não autenticado'
                ];
            }
            
            $usuario_id = $_SESSION['usuario_id'];
            $sessoes = $this->model->obterSessoesAtivasUsuario($usuario_id);
            
            return [
                'sucesso' => true,
                'sessoes' => $sessoes,
                'total' => count($sessoes)
            ];
            
        } catch (Exception $e) {
            error_log('Erro em obterSessoesAtivasUsuario: ' . $e->getMessage());
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao obter sessões'
            ];
        }
    }
    
    /**
     * Limpar sessões expiradas
     * @return array
     */
    public function limparSessoesExpiradas() {
        try {
            $total_removidas = $this->model->limparSessoesExpiradas();
            
            return [
                'sucesso' => true,
                'mensagem' => 'Limpeza realizada com sucesso',
                'total_removidas' => $total_removidas
            ];
            
        } catch (Exception $e) {
            error_log('Erro em limparSessoesExpiradas: ' . $e->getMessage());
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao limpar sessões'
            ];
        }
    }
    
    /**
     * Calcular tempo de sessão
     * @return array
     */
    private function calcularTempoSessao() {
        $timeout = 7200; // 2 horas em segundos
        $login_timestamp = $_SESSION['login_timestamp'] ?? time();
        $tempo_decorrido = time() - $login_timestamp;
        $tempo_restante = max(0, $timeout - $tempo_decorrido);
        
        return [
            'tempo_decorrido' => $tempo_decorrido,
            'tempo_restante' => $tempo_restante,
            'tempo_decorrido_formatado' => $this->formatarTempo($tempo_decorrido),
            'tempo_restante_formatado' => $this->formatarTempo($tempo_restante),
            'percentual_usado' => round(($tempo_decorrido / $timeout) * 100, 2),
            'percentual_restante' => round(($tempo_restante / $timeout) * 100, 2)
        ];
    }
    
    /**
     * Formatar tempo em segundos para HH:MM:SS
     * @param int $segundos
     * @return string
     */
    private function formatarTempo($segundos) {
        $horas = floor($segundos / 3600);
        $minutos = floor(($segundos % 3600) / 60);
        $segs = $segundos % 60;
        
        return sprintf('%02d:%02d:%02d', $horas, $minutos, $segs);
    }
    
    /**
     * Registrar logout no log do sistema
     * @param string $email Email do usuário
     * @param string $nome Nome do usuário
     */
    private function registrarLogout($email, $nome) {
        try {
            require_once __DIR__ . '/../config.php';
            registrar_log('logout', "Logout realizado: {$email}", $nome);
        } catch (Exception $e) {
            error_log('Erro ao registrar logout: ' . $e->getMessage());
        }
    }
}
?>
