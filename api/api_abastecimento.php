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
$_mt_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?erpcondominios\.com\.br$/', $_mt_origin) ||
    preg_match('/^https?:\/\/localhost(:\d+)?$/', $_mt_origin)) {
    header('Access-Control-Allow-Origin: ' . $_mt_origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
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
require_once 'tenant_helper.php';;

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
$tenant_id = exigirTenantId();

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
            listarVeiculos($conn, $tenant_id);
            break;
            
        case 'listar_abastecimentos':
            listarAbastecimentos($conn, $tenant_id);
            break;
            
        case 'listar_recargas':
            listarRecargas($conn, $tenant_id);
            break;
            
        case 'listar_usuarios':
            listarUsuarios($conn, $tenant_id);
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
            cadastrarVeiculo($conn, $dados, $tenant_id);
            break;
            
        case 'lancar_abastecimento':
            lancarAbastecimento($conn, $dados, $tenant_id);
            break;
            
        case 'registrar_recarga':
            registrarRecarga($conn, $dados, $tenant_id);
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

function listarVeiculos($conn, $tenant_id) {
    try {
        $stmt = $conn->prepare('SELECT id, placa, modelo, ano, cor, km_inicial, data_cadastro FROM abastecimento_veiculos WHERE tenant_id = ? ORDER BY data_cadastro DESC, id DESC');
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $veiculos = [];
        while ($row = $result->fetch_assoc()) $veiculos[] = $row;
        $stmt->close();
        error_log('[ABASTECIMENTO] veiculos_carregados tenant_id=' . $tenant_id . ' total=' . count($veiculos));
        echo json_encode(['sucesso' => true, 'dados' => $veiculos], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[ABASTECIMENTO] erro_listar_veiculos tenant_id=' . (int)$tenant_id . ' erro=' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao listar veículos do condomínio.'], JSON_UNESCAPED_UNICODE);
    }
}

function cadastrarVeiculo($conn, $dados, $tenant_id) {
    try {
        $placa = strtoupper(trim((string)($dados['placa'] ?? '')));
        if ($placa === '') throw new RuntimeException('Placa obrigatória.');
        $stmt = $conn->prepare('SELECT id FROM abastecimento_veiculos WHERE tenant_id = ? AND placa = ? LIMIT 1');
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('is', $tenant_id, $placa);
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
            (tenant_id, placa, modelo, ano, cor, km_inicial, data_cadastro)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$stmt) throw new RuntimeException($conn->error);
        $modelo = trim((string)($dados['modelo'] ?? ''));
        $ano = (int)($dados['ano'] ?? 0);
        $cor = trim((string)($dados['cor'] ?? ''));
        $kmInicial = (int)($dados['km_inicial'] ?? 0);
        $stmt->bind_param('issisi', $tenant_id, $placa, $modelo, $ano, $cor, $kmInicial);
        
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

function listarAbastecimentos($conn, $tenant_id) {
    try {
        $sql = 'SELECT a.*, v.placa AS veiculo_placa, v.modelo AS veiculo_modelo, u.nome AS operador_nome FROM abastecimento_lancamentos a INNER JOIN abastecimento_veiculos v ON a.veiculo_id = v.id AND v.tenant_id = a.tenant_id INNER JOIN usuarios u ON a.operador_id = u.id AND u.tenant_id = a.tenant_id WHERE a.tenant_id = ? ORDER BY a.data_abastecimento DESC, a.id DESC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $abastecimentos = [];
        while ($row = $result->fetch_assoc()) {
            $abastecimentos[] = $row;
        }
        
        $stmt->close();
        echo json_encode([
            'sucesso' => true,
            'dados' => $abastecimentos
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao listar abastecimentos: ' . $e->getMessage()
        ]);
    }
}

function lancarAbastecimento($conn, $dados, $tenant_id) {
    // ═══ PROTEÇÃO BACKEND: chave de idempotência ═══
    // Se a mesma requisição chegar duas vezes (duplo clique, retry),
    // a função encerra com sucesso sem gravar novamente no banco.
    verificarIdempotencia('lancar_abastecimento');

    try {
        // Validar que veículo e operador pertencem ao tenant autenticado.
        $veiculoId = (int)($dados['veiculo_id'] ?? 0);
        $operadorId = (int)($dados['operador_id'] ?? 0);
        $validarVeiculo = $conn->prepare('SELECT id FROM abastecimento_veiculos WHERE id=? AND tenant_id=? LIMIT 1');
        if (!$validarVeiculo) throw new RuntimeException($conn->error);
        $validarVeiculo->bind_param('ii', $veiculoId, $tenant_id);
        $validarVeiculo->execute();
        $veiculoValido = $validarVeiculo->get_result()->num_rows === 1;
        $validarVeiculo->close();
        $validarOperador = $conn->prepare('SELECT id FROM usuarios WHERE id=? AND tenant_id=? AND ativo=1 LIMIT 1');
        if (!$validarOperador) throw new RuntimeException($conn->error);
        $validarOperador->bind_param('ii', $operadorId, $tenant_id);
        $validarOperador->execute();
        $operadorValido = $validarOperador->get_result()->num_rows === 1;
        $validarOperador->close();
        if (!$veiculoValido || !$operadorValido) throw new RuntimeException('Veículo ou operador não pertence ao condomínio ativo.');

        // Obter saldo atual
        $saldo = obterSaldoAtual($conn);
        $valor = floatval($dados['valor'] ?? 0);
        
        // Calcular novo saldo
        $novoSaldo = $saldo - $valor;
        
        // Obter nome do usuário logado
        $usuarioLogado = $_SESSION['usuario_nome'] ?? 'Sistema';
        
        // Inserir abastecimento
        $stmt = $conn->prepare("
            INSERT INTO abastecimento_lancamentos
            (tenant_id, veiculo_id, data_abastecimento, km_abastecimento, litros, valor,
             tipo_combustivel, operador_id, usuario_logado, data_registro)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$stmt) throw new RuntimeException($conn->error);
        $dataAbastecimento = (string)($dados['data_abastecimento'] ?? '');
        $kmAbastecimento = (int)($dados['km_abastecimento'] ?? 0);
        $litros = (float)($dados['litros'] ?? 0);
        $tipoCombustivel = (string)($dados['tipo_combustivel'] ?? '');
        $stmt->bind_param('iisiddsis', $tenant_id, $veiculoId, $dataAbastecimento, $kmAbastecimento, $litros, $valor, $tipoCombustivel, $operadorId, $usuarioLogado);
        
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

function listarUsuarios($conn, $tenant_id) {
    try {
        $stmt = $conn->prepare('SELECT id, nome, email FROM usuarios WHERE tenant_id = ? AND ativo = 1 ORDER BY nome, id');
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $usuarios = [];
        while ($row = $result->fetch_assoc()) $usuarios[] = $row;
        $stmt->close();
        error_log('[ABASTECIMENTO] operadores_carregados tenant_id=' . $tenant_id . ' total=' . count($usuarios));
        echo json_encode(['sucesso' => true, 'dados' => $usuarios], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[ABASTECIMENTO] erro_listar_usuarios tenant_id=' . (int)$tenant_id . ' erro=' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao listar operadores do condomínio.'], JSON_UNESCAPED_UNICODE);
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
