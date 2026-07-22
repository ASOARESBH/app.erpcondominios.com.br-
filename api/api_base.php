<?php
/**
 * =====================================================
 * API BASE - Arquivo Padrão Obrigatório para Todas as APIs
 * =====================================================
 * 
 * Este arquivo centraliza:
 * - Inicialização de sessão
 * - Validação de autenticação
 * - Headers padrão (JSON, CORS, Cache)
 * - Tratamento centralizado de erros
 * - Formato padrão de respostas
 * 
 * OBRIGATÓRIO: Incluir no início de TODAS as APIs:
 * require_once 'api_base.php';
 * 
 * VERSÃO: 2.0.0
 * DATA: 30 de Janeiro de 2026
 */

// =====================================================
// 1. CONFIGURAÇÕES DE SESSÃO
// =====================================================

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200); // 2 horas
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Iniciar sessão se não estiver iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================================================
// 2. HEADERS PADRÃO PARA API
// =====================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// CORS - Permitir requisições do frontend
$origem_permitida = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
header('Access-Control-Allow-Origin: ' . $origem_permitida);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Tratar requisições OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// =====================================================
// 3. INCLUIR DEPENDÊNCIAS
// =====================================================

require_once 'config.php';
require_once 'auth_helper.php';

// =====================================================
// 4. CONSTANTES GLOBAIS
// =====================================================

define('API_VERSION', '2.0.0');
define('API_TIMEOUT', 7200); // 2 horas
define('API_LOG_DIR', '/var/log/erp_api/');

// Criar diretório de logs se não existir
if (!is_dir(API_LOG_DIR)) {
    @mkdir(API_LOG_DIR, 0755, true);
}

// =====================================================
// 5. FUNÇÃO DE RESPOSTA PADRÃO (Compatibilidade)
// =====================================================

if (!function_exists('retornar_json')) {
    /**
     * Retorna resposta JSON no formato padrão
     * COMPATIBILIDADE: Mantém formato antigo
     */
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $resposta = [
            'success' => $sucesso,
            'message' => $mensagem
        ];
        
        if ($dados !== null) {
            $resposta['data'] = $dados;
        }
        
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// =====================================================
// 6. CLASSE BASE PARA TODAS AS APIs
// =====================================================

class ApiBase {
    
    /**
     * Propriedades protegidas
     */
    protected $conexao = null;
    protected $requer_autenticacao = true;
    protected $tipo_usuario = null; // 'morador', 'fornecedor', 'admin', etc
    protected $usuario_id = null;
    protected $inicio_requisicao = null;
    
    /**
     * Construtor
     * 
     * @param bool $requer_autenticacao Se deve validar autenticação
     * @param string $tipo_usuario Tipo de usuário esperado (opcional)
     */
    public function __construct($requer_autenticacao = true, $tipo_usuario = null) {
        $this->requer_autenticacao = $requer_autenticacao;
        $this->tipo_usuario = $tipo_usuario;
        $this->inicio_requisicao = microtime(true);
        
        // Registrar início da requisição
        $this->registrarRequisicao('inicio');
        
        // Verificar autenticação se necessário
        if ($this->requer_autenticacao) {
            $this->verificarAutenticacao();
        }
    }
    
    /**
     * Destrutor - Garantir limpeza
     */
    public function __destruct() {
        $this->fecharConexao();
    }
    
    // =====================================================
    // MÉTODOS DE AUTENTICAÇÃO
    // =====================================================
    
    /**
     * Verificar se usuário está autenticado
     */
    protected function verificarAutenticacao() {
        // Verificar se há sessão válida
        if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
            $this->retornarErro('Sessão inválida ou expirada. Faça login novamente.', 401);
        }
        
        // Verificar se há ID do usuário
        if (!isset($_SESSION['usuario_id']) || empty($_SESSION['usuario_id'])) {
            $this->retornarErro('Usuário não identificado. Faça login novamente.', 401);
        }
        
        // Armazenar ID do usuário
        $this->usuario_id = $_SESSION['usuario_id'];
        
        // Verificar tipo de usuário se especificado
        if ($this->tipo_usuario !== null) {
            $tipo_sessao = $_SESSION['usuario_tipo'] ?? null;
            if ($tipo_sessao !== $this->tipo_usuario) {
                $this->retornarErro('Tipo de usuário inválido para esta operação.', 403);
            }
        }
        
        // Verificar timeout da sessão
        if (isset($_SESSION['login_timestamp'])) {
            $tempo_decorrido = time() - $_SESSION['login_timestamp'];
            
            if ($tempo_decorrido > API_TIMEOUT) {
                session_destroy();
                $this->retornarErro('Sessão expirada. Faça login novamente.', 401);
            }
            
            // Atualizar timestamp se passou mais de 5 minutos
            if ($tempo_decorrido > 300) {
                $_SESSION['login_timestamp'] = time();
            }
        }
    }
    
    /**
     * Verificar permissão do usuário
     */
    protected function verificarPermissao($permissao_necessaria) {
        $permissao_usuario = $_SESSION['usuario_permissao'] ?? 'usuario';
        
        $hierarquia = [
            'usuario' => 1,
            'moderador' => 2,
            'supervisor' => 3,
            'gerente' => 4,
            'admin' => 5
        ];
        
        $nivel_usuario = $hierarquia[$permissao_usuario] ?? 1;
        $nivel_necessario = $hierarquia[$permissao_necessaria] ?? 1;
        
        if ($nivel_usuario < $nivel_necessario) {
            $this->retornarErro('Você não tem permissão para realizar esta ação.', 403);
        }
    }
    
    // =====================================================
    // MÉTODOS DE BANCO DE DADOS
    // =====================================================
    
    /**
     * Obter conexão com banco
     */
    protected function getConexao() {
        if ($this->conexao === null) {
            $this->conexao = conectar_banco();
            
            if (!$this->conexao) {
                $this->retornarErro('Erro ao conectar ao banco de dados.', 500);
            }
        }
        
        return $this->conexao;
    }
    
    /**
     * Fechar conexão
     */
    protected function fecharConexao() {
        if ($this->conexao !== null) {
            fechar_conexao($this->conexao);
            $this->conexao = null;
        }
    }
    
    /**
     * Executar query preparada com tratamento de erro
     */
    protected function executarQuery($query, $params = [], $tipos = '') {
        try {
            $conexao = $this->getConexao();
            $stmt = $conexao->prepare($query);
            
            if (!$stmt) {
                throw new Exception('Erro ao preparar query: ' . $conexao->error);
            }
            
            // Bind params se houver
            if (!empty($params) && !empty($tipos)) {
                $stmt->bind_param($tipos, ...$params);
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Erro ao executar query: ' . $stmt->error);
            }
            
            return $stmt;
            
        } catch (Exception $e) {
            $this->registrarErro('executarQuery', $e->getMessage(), $query);
            $this->retornarErro('Erro ao processar requisição.', 500);
        }
    }
    
    /**
     * Buscar um registro
     */
    protected function buscarUm($query, $params = [], $tipos = '') {
        try {
            $stmt = $this->executarQuery($query, $params, $tipos);
            $resultado = $stmt->get_result();
            
            if ($resultado && $resultado->num_rows > 0) {
                $registro = $resultado->fetch_assoc();
                $stmt->close();
                return $registro;
            }
            
            $stmt->close();
            return null;
            
        } catch (Exception $e) {
            $this->registrarErro('buscarUm', $e->getMessage(), $query);
            return null;
        }
    }
    
    /**
     * Buscar múltiplos registros
     */
    protected function buscarTodos($query, $params = [], $tipos = '') {
        try {
            $stmt = $this->executarQuery($query, $params, $tipos);
            $resultado = $stmt->get_result();
            
            $registros = [];
            if ($resultado) {
                while ($row = $resultado->fetch_assoc()) {
                    $registros[] = $row;
                }
            }
            
            $stmt->close();
            return $registros;
            
        } catch (Exception $e) {
            $this->registrarErro('buscarTodos', $e->getMessage(), $query);
            return [];
        }
    }
    
    /**
     * Inserir registro
     */
    protected function inserir($query, $params = [], $tipos = '') {
        try {
            $stmt = $this->executarQuery($query, $params, $tipos);
            $insert_id = $stmt->insert_id;
            $stmt->close();
            
            return $insert_id;
            
        } catch (Exception $e) {
            $this->registrarErro('inserir', $e->getMessage(), $query);
            return false;
        }
    }
    
    /**
     * Atualizar registro
     */
    protected function atualizar($query, $params = [], $tipos = '') {
        try {
            $stmt = $this->executarQuery($query, $params, $tipos);
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            
            return $affected_rows;
            
        } catch (Exception $e) {
            $this->registrarErro('atualizar', $e->getMessage(), $query);
            return false;
        }
    }
    
    /**
     * Deletar registro
     */
    protected function deletar($query, $params = [], $tipos = '') {
        try {
            $stmt = $this->executarQuery($query, $params, $tipos);
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            
            return $affected_rows;
            
        } catch (Exception $e) {
            $this->registrarErro('deletar', $e->getMessage(), $query);
            return false;
        }
    }
    
    // =====================================================
    // MÉTODOS DE VALIDAÇÃO
    // =====================================================
    
    /**
     * Validar campos obrigatórios
     */
    protected function validarCampos($dados, $campos_obrigatorios) {
        $campos_faltando = [];
        
        foreach ($campos_obrigatorios as $campo) {
            if (!isset($dados[$campo]) || empty(trim((string)$dados[$campo]))) {
                $campos_faltando[] = $campo;
            }
        }
        
        if (!empty($campos_faltando)) {
            $this->retornarErro(
                'Campos obrigatórios não preenchidos: ' . implode(', ', $campos_faltando),
                400
            );
        }
    }
    
    /**
     * Sanitizar entrada
     */
    protected function sanitizar($valor) {
        $conexao = $this->getConexao();
        return $conexao->real_escape_string(trim((string)$valor));
    }
    
    /**
     * Validar email
     */
    protected function validarEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    // =====================================================
    // MÉTODOS DE RESPOSTA
    // =====================================================
    
    /**
     * Retornar sucesso
     * Formato padrão: { "success": true, "data": {}, "error": null }
     */
    protected function retornarSucesso($dados = null, $mensagem = 'Operação realizada com sucesso', $codigo = 200) {
        http_response_code($codigo);
        
        $resposta = [
            'success' => true,
            'data' => $dados,
            'error' => null
        ];
        
        // Adicionar tempo de execução em debug
        if (isset($_GET['debug']) && $_GET['debug'] === '1') {
            $tempo_execucao = microtime(true) - $this->inicio_requisicao;
            $resposta['_debug'] = [
                'tempo_execucao' => round($tempo_execucao, 4) . 's',
                'memoria_usada' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB'
            ];
        }
        
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $this->registrarRequisicao('sucesso', $codigo);
        $this->fecharConexao();
        exit;
    }
    
    /**
     * Retornar erro
     * Formato padrão: { "success": false, "data": null, "error": "mensagem" }
     */
    protected function retornarErro($mensagem, $codigo = 400, $dados = null) {
        http_response_code($codigo);
        
        $resposta = [
            'success' => false,
            'data' => $dados,
            'error' => $mensagem
        ];
        
        // Adicionar tempo de execução em debug
        if (isset($_GET['debug']) && $_GET['debug'] === '1') {
            $tempo_execucao = microtime(true) - $this->inicio_requisicao;
            $resposta['_debug'] = [
                'tempo_execucao' => round($tempo_execucao, 4) . 's',
                'memoria_usada' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB'
            ];
        }
        
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $this->registrarRequisicao('erro', $codigo);
        $this->registrarErro('retornarErro', $mensagem);
        $this->fecharConexao();
        exit;
    }
    
    // =====================================================
    // MÉTODOS DE LOGGING
    // =====================================================
    
    /**
     * Registrar requisição
     */
    protected function registrarRequisicao($status, $codigo = null) {
        $arquivo = API_LOG_DIR . 'requisicoes_' . date('Y-m-d') . '.log';
        
        $log = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => $status,
            'codigo_http' => $codigo,
            'metodo' => $_SERVER['REQUEST_METHOD'],
            'endpoint' => $_SERVER['REQUEST_URI'],
            'usuario_id' => $this->usuario_id,
            'ip' => $_SERVER['REMOTE_ADDR']
        ];
        
        @file_put_contents($arquivo, json_encode($log) . PHP_EOL, FILE_APPEND);
    }
    
    /**
     * Registrar erro
     */
    protected function registrarErro($funcao, $mensagem, $contexto = null) {
        $arquivo = API_LOG_DIR . 'erros_' . date('Y-m-d') . '.log';
        
        $log = [
            'timestamp' => date('Y-m-d H:i:s'),
            'funcao' => $funcao,
            'mensagem' => $mensagem,
            'contexto' => $contexto,
            'usuario_id' => $this->usuario_id,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'arquivo' => __FILE__,
            'linha' => __LINE__
        ];
        
        @file_put_contents($arquivo, json_encode($log) . PHP_EOL, FILE_APPEND);
        
        // Também registrar no error_log do PHP
        error_log('[API] ' . $funcao . ': ' . $mensagem);
    }
    
    // =====================================================
    // MÉTODOS AUXILIARES
    // =====================================================
    
    /**
     * Obter ID do usuário logado
     */
    protected function getUsuarioId() {
        return $this->usuario_id ?? $_SESSION['usuario_id'] ?? null;
    }
    
    /**
     * Obter dados do usuário logado
     */
    protected function getUsuario() {
        return [
            'id' => $_SESSION['usuario_id'] ?? null,
            'nome' => $_SESSION['usuario_nome'] ?? null,
            'email' => $_SESSION['usuario_email'] ?? null,
            'tipo' => $_SESSION['usuario_tipo'] ?? null,
            'permissao' => $_SESSION['usuario_permissao'] ?? null
        ];
    }
    
    /**
     * Obter dados da requisição (GET, POST, JSON)
     */
    protected function obterDados() {
        $dados = [];
        
        // GET
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $dados = $_GET;
        }
        // POST (form-data)
        elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST)) {
            $dados = $_POST;
        }
        // JSON
        else {
            $json = file_get_contents('php://input');
            if (!empty($json)) {
                $dados = json_decode($json, true) ?? [];
            }
        }
        
        return $dados;
    }
}

/**
 * Função auxiliar para criar instância de API
 */
function criarApi($requer_autenticacao = true, $tipo_usuario = null) {
    return new ApiBase($requer_autenticacao, $tipo_usuario);
}

/**
 * Registrar shutdown function para garantir limpeza
 */
register_shutdown_function(function() {
    // Fechar todas as conexões abertas
    if (function_exists('fechar_conexao')) {
        // Será chamado automaticamente
    }
});

?>
