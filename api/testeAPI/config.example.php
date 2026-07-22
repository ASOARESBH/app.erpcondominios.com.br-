<?php
// =====================================================
// CONFIGURAÇÃO DO BANCO DE DADOS - TEMPLATE
// =====================================================
// Copie este arquivo para config.php e preencha com suas credenciais

// Configurações do banco de dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'seu_banco_de_dados');
define('DB_USER', 'seu_usuario');
define('DB_PASS', 'sua_senha');
define('DB_CHARSET', 'utf8mb4');

// Configuração de timezone
date_default_timezone_set('America/Sao_Paulo');

// Configuração de exibição de erros (desativar em produção)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Função para conectar ao banco de dados
function conectar_banco() {
    try {
        $conexao = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conexao->connect_error) {
            throw new Exception("Erro na conexão: " . $conexao->connect_error);
        }
        
        $conexao->set_charset(DB_CHARSET);
        
        // Sincronizar timezone do MySQL com PHP
        $conexao->query("SET time_zone = '-03:00'");
        
        return $conexao;
        
    } catch (Exception $e) {
        error_log("Erro de conexão ao banco: " . $e->getMessage());
        die("Erro ao conectar ao banco de dados. Verifique as configurações.");
    }
}

// Função para fechar conexão
function fechar_conexao($conexao) {
    if ($conexao) {
        $conexao->close();
    }
}

// Função para sanitizar entrada
function sanitizar($conexao, $valor) {
    return $conexao->real_escape_string(trim($valor));
}

// Função para retornar JSON
function retornar_json($sucesso, $mensagem, $dados = null) {
    header('Content-Type: application/json; charset=utf-8');
    $resposta = array(
        'sucesso' => $sucesso,
        'mensagem' => $mensagem
    );
    if ($dados !== null) {
        $resposta['dados'] = $dados;
    }
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
    exit;
}

// Função para registrar log
function registrar_log($tipo, $descricao, $usuario = null) {
    $conexao = conectar_banco();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
    
    $stmt = $conexao->prepare("INSERT INTO logs_sistema (tipo, descricao, usuario, ip) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $tipo, $descricao, $usuario, $ip);
    $stmt->execute();
    $stmt->close();
    
    fechar_conexao($conexao);
}
