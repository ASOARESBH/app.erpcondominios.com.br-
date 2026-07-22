<?php
/**
 * SCRIPT DE DEBUG EXAUSTIVO
 * Objetivo: Identificar onde a API de dependentes está falhando silenciosamente.
 */

// 1. Configurações de exibição de erros (FORÇAR EXIBIÇÃO)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Relatório de Debug - Sistema de Dependentes</h1>";

try {
    // 2. Teste de inclusão de arquivos
    echo "<h3>1. Verificando arquivos:</h3>";
    $arquivos = [
        '../config/conexao.php', // Ajuste o caminho se necessário
        '../models/DependenteModel.php',
        '../controllers/DependenteController.php'
    ];

    foreach ($arquivos as $arq) {
        if (file_exists(__DIR__ . '/' . $arq)) {
            echo "✅ Arquivo encontrado: $arq <br>";
            require_once __DIR__ . '/' . $arq;
        } else {
            echo "❌ <strong style='color:red'>ARQUIVO NÃO ENCONTRADO: $arq </strong><br>";
        }
    }

    // 3. Teste de Conexão
    echo "<h3>2. Testando Conexão com Banco:</h3>";
    if (!isset($conexao)) {
        echo "❌ Variável de conexão \$conexao não definida após incluir arquivos de config.<br>";
    } else {
        echo "✅ Conexão estabelecida com sucesso.<br>";
    }

    // 4. Teste de Simulação de Cadastro (O "Culpado")
    echo "<h3>3. Simulando Cadastro de Dependente:</h3>";
    
    $controller = new DependenteController($conexao);
    
    // Dados de teste (Simulando o que vem do JavaScript)
    $dadosTeste = [
        'moradorId' => 1, // Certifique-se que este ID existe no seu banco
        'nomeCompleto' => 'Teste Debug Silva',
        'cpf' => '123.456.789-00', // Com máscara para testar a limpeza
        'dataNascimento' => '2000-01-01',
        'tipoParentesco' => 'Filho(a)'
    ];

    echo "Enviando dados para o Controller...<br>";
    $resultado = $controller->criar($dadosTeste);

    echo "<pre>Resultado do Controller:";
    print_r($resultado);
    echo "</pre>";

    if ($resultado['sucesso']) {
        echo "✅ <strong style='color:green'>SUCESSO: O Controller conseguiu salvar!</strong><br>";
    } else {
        echo "❌ <strong style='color:red'>FALHA: O Controller retornou erro: " . $resultado['mensagem'] . "</strong><br>";
    }

} catch (Throwable $e) {
    echo "<h3>💥 ERRO FATAL DO PHP:</h3>";
    echo "<p style='color:red'>" . $e->getMessage() . "</p>";
    echo "<strong>Linha:</strong> " . $e->getLine() . "<br>";
    echo "<strong>Arquivo:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Stack Trace:</strong><pre>" . $e->getTraceAsString() . "</pre>";
}