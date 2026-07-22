<?php
// =====================================================
// CONFIGURAÇÃO DO BANCO DE DADOS
// =====================================================

// Configurações do banco de dados HostGator
define('DB_HOST', 'localhost');
define('DB_NAME', 'inlaud99_erpserra');
define('DB_USER', 'inlaud99_admin');
define('DB_PASS', 'Admin259087@');
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
            // Log do erro
            error_log("Erro de conexão MySQL: " . $conexao->connect_error);
            error_log("MySQL errno: " . $conexao->connect_errno);
            
            // Retornar JSON em vez de die()
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Erro ao conectar ao banco de dados. Tente novamente mais tarde.',
                'erro_detalhado' => $conexao->connect_error,
                'erro_numero' => $conexao->connect_errno
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $conexao->set_charset(DB_CHARSET);
        
        // ✅ CORREÇÃO: Sincronizar timezone do MySQL com PHP
        $conexao->query("SET time_zone = '-03:00'");
        
        return $conexao;
        
    } catch (Exception $e) {
        error_log("Erro de conexão ao banco: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        // Retornar JSON em vez de die()
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao conectar ao banco de dados. Tente novamente mais tarde.',
            'erro_detalhado' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Função para fechar conexão
function fechar_conexao($conexao) {
    if ($conexao) {
        $conexao->close();
    }
}

// Função para sanitizar entrada
if (!function_exists('sanitizar')) {
function sanitizar($conexao, $valor) {
    return $conexao->real_escape_string(trim($valor));
}
}

// Função para retornar JSON (definida em cada API para evitar conflitos)
if (!function_exists('retornar_json')) {
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
}

// Função para registrar log
function registrar_log($tipo, $descricao, $usuario = null) {
    try {
        // Criar nova conexão apenas para log
        $conexao = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conexao->connect_error) {
            // Se falhar, apenas registra no error_log e continua
            error_log("Erro ao registrar log no banco: " . $conexao->connect_error);
            return false;
        }
        
        $conexao->set_charset(DB_CHARSET);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
        
        $stmt = $conexao->prepare("INSERT INTO logs_sistema (tipo, descricao, usuario, ip) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssss", $tipo, $descricao, $usuario, $ip);
            $stmt->execute();
            $stmt->close();
        }
        
        $conexao->close();
        return true;
        
    } catch (Exception $e) {
        // Se falhar, apenas registra no error_log e continua
        error_log("Erro ao registrar log: " . $e->getMessage());
        return false;
    }
}

// Função para retornar JSON (adicionada para corrigir erro)
if (!function_exists('retornar_json')) {
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
}
