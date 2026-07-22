<?php
/**
 * =====================================================
 * DEBUG: debug_dependentes.php
 * =====================================================
 * 
 * Arquivo de debug para testar criação de dependentes
 * e diagnosticar problemas de salvamento no BD
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 25/01/2026
 * @version 1.0
 */

// Configurar exibição de erros
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_dependentes.log');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../models/DependenteModel.php';

/**
 * Função para registrar debug
 */
function registrarDebug($mensagem, $dados = null) {
    $timestamp = date('Y-m-d H:i:s');
    $log = "[{$timestamp}] {$mensagem}";
    
    if ($dados) {
        $log .= "\n" . json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    $log .= "\n" . str_repeat('-', 80) . "\n";
    
    file_put_contents(__DIR__ . '/debug_dependentes.log', $log, FILE_APPEND);
}

/**
 * Função para resposta JSON
 */
function resposta($sucesso, $mensagem, $dados = null) {
    $response = [
        'sucesso' => $sucesso,
        'mensagem' => $mensagem,
        'dados' => $dados,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    registrarDebug('=== INÍCIO DO DEBUG DE DEPENDENTES ===');
    
    // Conectar ao banco de dados
    $conexao = conectar_banco();
    
    if (!$conexao) {
        registrarDebug('ERRO: Falha ao conectar ao banco de dados');
        resposta(false, 'Erro ao conectar ao banco de dados');
    }
    
    registrarDebug('Conexão com banco de dados estabelecida');
    
    // Instanciar model
    $model = new DependenteModel($conexao);
    registrarDebug('Model DependenteModel instanciado');
    
    // Obter ação
    $acao = isset($_GET['acao']) ? trim($_GET['acao']) : 'testar';
    registrarDebug('Ação solicitada: ' . $acao);
    
    switch ($acao) {
        case 'testar':
            // Teste de criação de dependente
            registrarDebug('=== TESTE DE CRIAÇÃO DE DEPENDENTE ===');
            
            $dados_teste = [
                'morador_id' => 1,
                'nome_completo' => 'TESTE DEBUG ' . date('His'),
                'cpf' => '123.456.789-' . rand(10, 99),
                'email' => 'teste@debug.com',
                'celular' => '(31) 99999-9999',
                'parentesco' => 'Filho(a)',
                'observacao' => 'Cadastro de teste para debug'
            ];
            
            registrarDebug('Dados de teste preparados', $dados_teste);
            
            // Tentar criar
            $resultado = $model->criar($dados_teste);
            
            registrarDebug('Resultado da criação', $resultado);
            
            resposta($resultado['sucesso'], $resultado['mensagem'], $resultado);
            break;
        
        case 'verificar_tabela':
            // Verificar se a tabela existe
            registrarDebug('=== VERIFICAÇÃO DA TABELA DEPENDENTES ===');
            
            $sql = "SHOW TABLES LIKE 'dependentes'";
            $resultado = $conexao->query($sql);
            
            if ($resultado && $resultado->num_rows > 0) {
                registrarDebug('Tabela dependentes existe');
                
                // Verificar estrutura da tabela
                $sql_estrutura = "DESCRIBE dependentes";
                $resultado_estrutura = $conexao->query($sql_estrutura);
                
                $campos = [];
                while ($row = $resultado_estrutura->fetch_assoc()) {
                    $campos[] = $row;
                }
                
                registrarDebug('Estrutura da tabela dependentes', $campos);
                
                resposta(true, 'Tabela dependentes existe e está acessível', [
                    'tabela_existe' => true,
                    'campos' => $campos
                ]);
            } else {
                registrarDebug('ERRO: Tabela dependentes não existe');
                resposta(false, 'Tabela dependentes não existe no banco de dados');
            }
            break;
        
        case 'listar_todos':
            // Listar todos os dependentes (debug)
            registrarDebug('=== LISTAGEM DE TODOS OS DEPENDENTES ===');
            
            $sql = "SELECT * FROM dependentes ORDER BY id DESC LIMIT 10";
            $resultado = $conexao->query($sql);
            
            $dependentes = [];
            if ($resultado) {
                while ($row = $resultado->fetch_assoc()) {
                    $dependentes[] = $row;
                }
            }
            
            registrarDebug('Total de dependentes encontrados: ' . count($dependentes));
            
            resposta(true, 'Dependentes listados com sucesso', [
                'total' => count($dependentes),
                'dependentes' => $dependentes
            ]);
            break;
        
        case 'verificar_conexao':
            // Verificar conexão com banco
            registrarDebug('=== VERIFICAÇÃO DE CONEXÃO ===');
            
            $info_conexao = [
                'host' => DB_HOST,
                'database' => DB_NAME,
                'user' => DB_USER,
                'charset' => DB_CHARSET,
                'conexao_ativa' => $conexao ? true : false,
                'server_info' => $conexao->server_info ?? 'N/A',
                'client_info' => $conexao->client_info ?? 'N/A'
            ];
            
            registrarDebug('Informações de conexão', $info_conexao);
            
            resposta(true, 'Conexão verificada com sucesso', $info_conexao);
            break;
        
        case 'testar_insert_direto':
            // Testar INSERT direto no banco
            registrarDebug('=== TESTE DE INSERT DIRETO ===');
            
            $nome = 'TESTE INSERT DIRETO ' . date('His');
            $cpf = '999.999.999-' . rand(10, 99);
            
            $sql = "INSERT INTO dependentes 
                    (morador_id, nome_completo, cpf, parentesco, ativo) 
                    VALUES (1, ?, ?, 'Outro', 1)";
            
            $stmt = $conexao->prepare($sql);
            
            if (!$stmt) {
                registrarDebug('ERRO ao preparar statement', [
                    'error' => $conexao->error,
                    'errno' => $conexao->errno
                ]);
                resposta(false, 'Erro ao preparar statement: ' . $conexao->error);
            }
            
            $stmt->bind_param("ss", $nome, $cpf);
            
            if ($stmt->execute()) {
                $id = $conexao->insert_id;
                $affected = $stmt->affected_rows;
                
                registrarDebug('INSERT executado com sucesso', [
                    'insert_id' => $id,
                    'affected_rows' => $affected
                ]);
                
                // Verificar se foi realmente inserido
                $sql_verificar = "SELECT * FROM dependentes WHERE id = ?";
                $stmt_verificar = $conexao->prepare($sql_verificar);
                $stmt_verificar->bind_param("i", $id);
                $stmt_verificar->execute();
                $resultado_verificar = $stmt_verificar->get_result();
                $registro = $resultado_verificar->fetch_assoc();
                
                registrarDebug('Verificação do registro inserido', $registro);
                
                resposta(true, 'INSERT direto executado com sucesso', [
                    'insert_id' => $id,
                    'affected_rows' => $affected,
                    'registro_verificado' => $registro
                ]);
            } else {
                registrarDebug('ERRO ao executar INSERT', [
                    'error' => $stmt->error,
                    'errno' => $stmt->errno
                ]);
                resposta(false, 'Erro ao executar INSERT: ' . $stmt->error);
            }
            break;
        
        case 'limpar_testes':
            // Limpar registros de teste
            registrarDebug('=== LIMPEZA DE REGISTROS DE TESTE ===');
            
            $sql = "DELETE FROM dependentes WHERE nome_completo LIKE 'TESTE%'";
            $resultado = $conexao->query($sql);
            
            $linhas_afetadas = $conexao->affected_rows;
            
            registrarDebug('Registros de teste removidos', [
                'linhas_afetadas' => $linhas_afetadas
            ]);
            
            resposta(true, 'Registros de teste removidos com sucesso', [
                'linhas_removidas' => $linhas_afetadas
            ]);
            break;
        
        default:
            resposta(false, 'Ação não reconhecida: ' . $acao, [
                'acoes_disponiveis' => [
                    'testar',
                    'verificar_tabela',
                    'listar_todos',
                    'verificar_conexao',
                    'testar_insert_direto',
                    'limpar_testes'
                ]
            ]);
    }
    
    $conexao->close();
    registrarDebug('=== FIM DO DEBUG ===');
    
} catch (Exception $e) {
    registrarDebug('EXCEÇÃO CAPTURADA', [
        'mensagem' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    resposta(false, 'Erro: ' . $e->getMessage(), [
        'exception' => [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}
?>
