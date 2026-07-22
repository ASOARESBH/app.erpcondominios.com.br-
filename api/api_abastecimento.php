<?php
/**
 * =====================================================
 * API DE ABASTECIMENTO
 * =====================================================
 * Gerencia veículos, abastecimentos e recargas
 */

// Função para retornar JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array('sucesso' => $sucesso, 'mensagem' => $mensagem);
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://asl.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
require_once 'config.php';
require_once 'auth_helper.php';

// ============================================================
// ANTI-DUPLICIDADE: verificação de chave de idempotência
// Armazena as chaves processadas na sessão por 60 segundos.
// Se a mesma chave chegar duas vezes (duplo clique, retry de rede),
// a segunda requisição retorna sucesso sem gravar no banco.
// ============================================================
function verificarIdempotencia($action) {
    $key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
    if (!$key) return null; // sem chave = fluxo normal

    // Namespace por usuário para evitar colissões entre sessões
    $ns = 'idempotency_' . ($_SESSION['usuario_id'] ?? 'anon');

    if (!isset($_SESSION[$ns])) {
        $_SESSION[$ns] = [];
    }

    // Limpar chaves expiradas (> 60 segundos)
    $agora = time();
    foreach ($_SESSION[$ns] as $k => $ts) {
        if ($agora - $ts > 60) unset($_SESSION[$ns][$k]);
    }

    if (isset($_SESSION[$ns][$key])) {
        // Chave já processada: retornar sucesso sem gravar
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso'    => true,
            'mensagem'   => 'Registro já processado (requisição duplicada ignorada)',
            '_duplicata' => true,
        ]);
        exit;
    }

    // Registrar a chave como processada
    $_SESSION[$ns][$key] = $agora;
    return $key;
}

// Verificar autenticação
verificarAutenticacao(true, 'operador');

// Configuração do banco de dados
$conn = conectar_banco();

// Para operações de escrita, verificar permissão
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    verificarPermissao('operador');
}

// Obter método da requisição
$metodo = $_SERVER['REQUEST_METHOD'];

// GET - Listar dados
if ($metodo === 'GET') {
    // Autenticação já verificada acima
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'listar_veiculos':
            listarVeiculos($conn);
            break;
            
        case 'listar_abastecimentos':
            listarAbastecimentos($conn);
            break;
            
        case 'listar_recargas':
            listarRecargas($conn);
            break;
            
        case 'listar_usuarios':
            listarUsuarios($conn);
            break;
            
        case 'obter_saldo':
            obterSaldo($conn);
            break;
            
        case 'relatorio':
            gerarRelatorio($conn);
            break;

        case 'recalcular_saldo':
            recalcularSaldo($conn);
            break;
            
        default:
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Ação não especificada'
            ]);
    }
}

// POST - Criar registros
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $action = $dados['action'] ?? '';
    
    switch ($action) {
        case 'cadastrar_veiculo':
            cadastrarVeiculo($conn, $dados);
            break;
            
        case 'lancar_abastecimento':
            lancarAbastecimento($conn, $dados);
            break;
            
        case 'registrar_recarga':
            registrarRecarga($conn, $dados);
            break;

        case 'recalcular_saldo':
            recalcularSaldo($conn);
            break;
            
        default:
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Ação não especificada'
            ]);
    }
}

// ============================================
// FUNÇÕES DE VEÍCULOS
// ============================================

function listarVeiculos($conn) {
    try {
        $sql = "SELECT * FROM abastecimento_veiculos ORDER BY data_cadastro DESC";
        $result = $conn->query($sql);
        
        $veiculos = [];
        while ($row = $result->fetch_assoc()) {
            $veiculos[] = $row;
        }
        
        echo json_encode([
            'sucesso' => true,
            'dados' => $veiculos
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao listar veículos: ' . $e->getMessage()
        ]);
    }
}

function cadastrarVeiculo($conn, $dados) {
    try {
        // Validar placa
        $placa = strtoupper(trim($dados['placa']));
        
        // Verificar se placa já existe
        $stmt = $conn->prepare("SELECT id FROM abastecimento_veiculos WHERE placa = ?");
        $stmt->bind_param("s", $placa);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Placa já cadastrada no sistema'
            ]);
            return;
        }
        
        // Inserir veículo
        $stmt = $conn->prepare("
            INSERT INTO abastecimento_veiculos 
            (placa, modelo, ano, cor, km_inicial, data_cadastro) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->bind_param(
            "ssisi",
            $placa,
            $dados['modelo'],
            $dados['ano'],
            $dados['cor'],
            $dados['km_inicial']
        );
        
        if ($stmt->execute()) {
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Veículo cadastrado com sucesso',
                'id' => $conn->insert_id
            ]);
        } else {
            throw new Exception($stmt->error);
        }
    } catch (Exception $e) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao cadastrar veículo: ' . $e->getMessage()
        ]);
    }
}

// ============================================
// FUNÇÕES DE ABASTECIMENTO
// ============================================

function listarAbastecimentos($conn) {
    try {
        $sql = "
            SELECT 
                a.*,
                v.placa as veiculo_placa,
                v.modelo as veiculo_modelo,
                u.nome as operador_nome
            FROM abastecimento_lancamentos a
            INNER JOIN abastecimento_veiculos v ON a.veiculo_id = v.id
            INNER JOIN usuarios u ON a.operador_id = u.id
            ORDER BY a.data_abastecimento DESC
        ";
        
        $result = $conn->query($sql);
        
        $abastecimentos = [];
        while ($row = $result->fetch_assoc()) {
            $abastecimentos[] = $row;
        }
        
        echo json_encode([
            'sucesso' => true,
            'dados' => $abastecimentos
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao listar abastecimentos: ' . $e->getMessage()
        ]);
    }
}

function lancarAbastecimento($conn, $dados) {
    // ═══ PROTEÇÃO BACKEND: chave de idempotência ═══
    // Se a mesma requisição chegar duas vezes (duplo clique, retry),
    // a função encerra com sucesso sem gravar novamente no banco.
    verificarIdempotencia('lancar_abastecimento');

    try {
        // Obter saldo atual
        $saldo = obterSaldoAtual($conn);
        $valor = floatval($dados['valor']);
        
        // Calcular novo saldo
        $novoSaldo = $saldo - $valor;
        
        // Obter nome do usuário logado
        $usuarioLogado = $_SESSION['usuario_nome'] ?? 'Sistema';
        
        // Inserir abastecimento
        $stmt = $conn->prepare("
            INSERT INTO abastecimento_lancamentos 
            (veiculo_id, data_abastecimento, km_abastecimento, litros, valor, 
             tipo_combustivel, operador_id, usuario_logado, data_registro) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->bind_param(
            "isiddsss",
            $dados['veiculo_id'],
            $dados['data_abastecimento'],
            $dados['km_abastecimento'],
            $dados['litros'],
            $valor,
            $dados['tipo_combustivel'],
            $dados['operador_id'],
            $usuarioLogado
        );
        
        if ($stmt->execute()) {
            // Atualizar saldo
            atualizarSaldoAtual($conn, $novoSaldo);
            
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Abastecimento registrado com sucesso',
                'saldo_atual' => $novoSaldo
            ]);
        } else {
            throw new Exception($stmt->error);
        }
    } catch (Exception $e) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao registrar abastecimento: ' . $e->getMessage()
        ]);
    }
}

// ============================================
// FUNÇÕES DE RECARGA
// ============================================

function listarRecargas($conn) {
    try {
        $sql = "
            SELECT 
                r.*,
                u.nome as usuario_nome
            FROM abastecimento_recargas r
            INNER JOIN usuarios u ON r.usuario_id = u.id
            ORDER BY r.data_recarga DESC
        ";
        
        $result = $conn->query($sql);
        
        $recargas = [];
        while ($row = $result->fetch_assoc()) {
            $recargas[] = $row;
        }
        
        echo json_encode([
            'sucesso' => true,
            'dados' => $recargas
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao listar recargas: ' . $e->getMessage()
        ]);
    }
}

function registrarRecarga($conn, $dados) {
    // ═══ PROTEÇÃO BACKEND: chave de idempotência ═══
    verificarIdempotencia('registrar_recarga');

    try {
        // Obter saldo atual
        $saldoAtual = obterSaldoAtual($conn);
        $valorRecarga = floatval($dados['valor_recarga']);
        $valorMinimo = floatval($dados['valor_minimo']);
        
        // Calcular novo saldo
        $novoSaldo = $saldoAtual + $valorRecarga;
        
        // Inserir recarga
        $stmt = $conn->prepare("
            INSERT INTO abastecimento_recargas 
            (data_recarga, valor_recarga, valor_minimo, nf, saldo_apos, usuario_id, data_registro) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $nf = !empty($dados['nf']) ? $dados['nf'] : null;
        $usuarioId = $_SESSION['usuario_id'];
        
        $stmt->bind_param(
            "sddsdi",
            $dados['data_recarga'],
            $valorRecarga,
            $valorMinimo,
            $nf,
            $novoSaldo,
            $usuarioId
        );
        
        if ($stmt->execute()) {
            // Atualizar saldo e valor mínimo
            atualizarSaldoAtual($conn, $novoSaldo);
            atualizarValorMinimo($conn, $valorMinimo);
            
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Recarga registrada com sucesso',
                'saldo_atual' => $novoSaldo
            ]);
        } else {
            throw new Exception($stmt->error);
        }
    } catch (Exception $e) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao registrar recarga: ' . $e->getMessage()
        ]);
    }
}

// ============================================
// FUNÇÕES DE SALDO
// ============================================

function obterSaldo($conn) {
    try {
        $saldo = obterSaldoAtual($conn);
        $valorMinimo = obterValorMinimoAtual($conn);
        
        echo json_encode([
            'sucesso' => true,
            'saldo' => $saldo,
            'valor_minimo' => $valorMinimo
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao obter saldo: ' . $e->getMessage()
        ]);
    }
}

function obterSaldoAtual($conn) {
    $result = $conn->query("SELECT valor FROM abastecimento_saldo WHERE id = 1");
    if ($result && $row = $result->fetch_assoc()) {
        return floatval($row['valor']);
    }
    return 0;
}

function obterValorMinimoAtual($conn) {
    $result = $conn->query("SELECT valor_minimo FROM abastecimento_saldo WHERE id = 1");
    if ($result && $row = $result->fetch_assoc()) {
        return floatval($row['valor_minimo']);
    }
    return 0;
}

function atualizarSaldoAtual($conn, $novoSaldo) {
    $stmt = $conn->prepare("
        INSERT INTO abastecimento_saldo (id, valor, data_atualizacao) 
        VALUES (1, ?, NOW())
        ON DUPLICATE KEY UPDATE valor = ?, data_atualizacao = NOW()
    ");
    $stmt->bind_param("dd", $novoSaldo, $novoSaldo);
    $stmt->execute();
}

function atualizarValorMinimo($conn, $valorMinimo) {
    $stmt = $conn->prepare("
        UPDATE abastecimento_saldo 
        SET valor_minimo = ?, data_atualizacao = NOW() 
        WHERE id = 1
    ");
    $stmt->bind_param("d", $valorMinimo);
    $stmt->execute();
}

// ============================================
// RECALCULAR SALDO (sincronização com o banco)
// ============================================

/**
 * Recalcula o saldo do zero com base nos registros reais:
 *   saldo = SUM(recargas.valor_recarga) - SUM(lancamentos.valor)
 *
 * Garante consistência mesmo após exclusões manuais no banco.
 */
function recalcularSaldo($conn) {
    try {
        // Total de recargas
        $resRecargas = $conn->query("
            SELECT COALESCE(SUM(valor_recarga), 0) AS total
            FROM abastecimento_recargas
        ");
        $totalRecargas = floatval($resRecargas->fetch_assoc()['total']);

        // Total de abastecimentos
        $resLanc = $conn->query("
            SELECT COALESCE(SUM(valor), 0) AS total
            FROM abastecimento_lancamentos
        ");
        $totalLancamentos = floatval($resLanc->fetch_assoc()['total']);

        // Saldo correto
        $saldoCorreto = $totalRecargas - $totalLancamentos;

        // Obter saldo atual antes de corrigir (para log)
        $saldoAnterior = obterSaldoAtual($conn);

        // Atualizar saldo na tabela
        $stmt = $conn->prepare("
            INSERT INTO abastecimento_saldo (id, valor, data_atualizacao)
            VALUES (1, ?, NOW())
            ON DUPLICATE KEY UPDATE valor = ?, data_atualizacao = NOW()
        ");
        $stmt->bind_param('dd', $saldoCorreto, $saldoCorreto);
        $stmt->execute();

        // Registrar no log de auditoria
        $usuario = $_SESSION['usuario_nome'] ?? 'Sistema';
        $descricao = "Saldo recalculado manualmente. Anterior: R\$ "
            . number_format($saldoAnterior, 2, ',', '.')
            . " → Correto: R\$ "
            . number_format($saldoCorreto, 2, ',', '.')
            . " | Recargas: R\$ " . number_format($totalRecargas, 2, ',', '.')
            . " | Abastecimentos: R\$ " . number_format($totalLancamentos, 2, ',', '.');

        $logStmt = $conn->prepare("
            INSERT INTO logs_sistema (tipo, descricao, usuario, ip, data_hora)
            VALUES ('SALDO_RECALCULADO', ?, ?, ?, NOW())
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $logStmt->bind_param('sss', $descricao, $usuario, $ip);
        $logStmt->execute();

        echo json_encode([
            'sucesso'           => true,
            'mensagem'          => 'Saldo recalculado com sucesso',
            'saldo_anterior'    => $saldoAnterior,
            'saldo_correto'     => $saldoCorreto,
            'total_recargas'    => $totalRecargas,
            'total_lancamentos' => $totalLancamentos,
            'diferenca'         => round($saldoCorreto - $saldoAnterior, 2),
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'sucesso'  => false,
            'mensagem' => 'Erro ao recalcular saldo: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function listarUsuarios($conn) {
    try {
        $sql = "SELECT id, nome, email FROM usuarios WHERE ativo = 1 ORDER BY nome";
        $result = $conn->query($sql);
        
        $usuarios = [];
        while ($row = $result->fetch_assoc()) {
            $usuarios[] = $row;
        }
        
        echo json_encode([
            'sucesso' => true,
            'dados' => $usuarios
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao listar usuários: ' . $e->getMessage()
        ]);
    }
}

// ============================================
// RELATÓRIOS
// ============================================

function gerarRelatorio($conn) {
    try {
        $where = [];
        $params = [];
        $types = '';
        
        // Filtro por veículo
        if (!empty($_GET['veiculo_id'])) {
            $where[] = "a.veiculo_id = ?";
            $params[] = $_GET['veiculo_id'];
            $types .= 'i';
        }
        
        // Filtro por data início
        if (!empty($_GET['data_inicio'])) {
            $where[] = "DATE(a.data_abastecimento) >= ?";
            $params[] = $_GET['data_inicio'];
            $types .= 's';
        }
        
        // Filtro por data fim
        if (!empty($_GET['data_fim'])) {
            $where[] = "DATE(a.data_abastecimento) <= ?";
            $params[] = $_GET['data_fim'];
            $types .= 's';
        }
        
        // Filtro por combustível
        if (!empty($_GET['combustivel'])) {
            $where[] = "a.tipo_combustivel = ?";
            $params[] = $_GET['combustivel'];
            $types .= 's';
        }
        
        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "
            SELECT 
                a.*,
                v.placa as veiculo_placa,
                v.modelo as veiculo_modelo,
                u.nome as operador_nome
            FROM abastecimento_lancamentos a
            INNER JOIN abastecimento_veiculos v ON a.veiculo_id = v.id
            INNER JOIN usuarios u ON a.operador_id = u.id
            $whereClause
            ORDER BY a.veiculo_id, a.data_abastecimento ASC
        ";
        
        if (count($params) > 0) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $conn->query($sql);
        }
        
        $dados = [];
        while ($row = $result->fetch_assoc()) {
            $dados[] = $row;
        }
        
        echo json_encode([
            'sucesso' => true,
            'dados' => $dados
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao gerar relatório: ' . $e->getMessage()
        ]);
    }
}

fechar_conexao($conn);
?>
