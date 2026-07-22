<?php
// =====================================================
// SCRIPT DE TESTE DAS APIs
// =====================================================

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste das APIs - Sistema de Controle de Acesso</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        h1 { color: #333; }
        .test-section { background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .test-section h2 { color: #1d4ed8; margin-top: 0; }
        .success { color: #22c55e; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .info { color: #3b82f6; }
        pre { background: #f8fafc; padding: 10px; border-radius: 4px; overflow-x: auto; }
        button { background: #3b82f6; color: #fff; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <h1>🧪 Teste das APIs - Sistema de Controle de Acesso</h1>

    <?php
    require_once 'config.php';

    echo "<div class='test-section'>";
    echo "<h2>1. Teste de Conexão com o Banco de Dados</h2>";
    
    try {
        $conexao = conectar_banco();
        echo "<p class='success'>✅ Conexão estabelecida com sucesso!</p>";
        echo "<p class='info'>Banco: " . DB_NAME . "</p>";
        echo "<p class='info'>Usuário: " . DB_USER . "</p>";
        
        // Testar se as tabelas existem
        $tabelas = ['moradores', 'veiculos', 'registros_acesso', 'logs_sistema', 'configuracoes'];
        echo "<h3>Verificando tabelas:</h3>";
        
        foreach ($tabelas as $tabela) {
            $resultado = $conexao->query("SHOW TABLES LIKE '$tabela'");
            if ($resultado->num_rows > 0) {
                echo "<p class='success'>✅ Tabela '$tabela' existe</p>";
            } else {
                echo "<p class='error'>❌ Tabela '$tabela' NÃO existe</p>";
            }
        }
        
        fechar_conexao($conexao);
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro na conexão: " . $e->getMessage() . "</p>";
    }
    echo "</div>";

    // Teste 2: Inserir morador de teste
    echo "<div class='test-section'>";
    echo "<h2>2. Teste de Inserção de Morador</h2>";
    
    $conexao = conectar_banco();
    
    // Verificar se já existe
    $cpf_teste = '123.456.789-00';
    $stmt = $conexao->prepare("SELECT id FROM moradores WHERE cpf = ?");
    $stmt->bind_param("s", $cpf_teste);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        echo "<p class='info'>ℹ️ Morador de teste já existe no banco</p>";
    } else {
        $nome = "João da Silva (Teste)";
        $unidade = "101";
        $email = "teste@teste.com";
        $senha = password_hash("123456", PASSWORD_DEFAULT);
        $telefone = "(11) 1234-5678";
        $celular = "(11) 98765-4321";
        
        $stmt_insert = $conexao->prepare("INSERT INTO moradores (nome, cpf, unidade, email, senha, telefone, celular) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_insert->bind_param("sssssss", $nome, $cpf_teste, $unidade, $email, $senha, $telefone, $celular);
        
        if ($stmt_insert->execute()) {
            echo "<p class='success'>✅ Morador de teste inserido com sucesso! ID: " . $conexao->insert_id . "</p>";
        } else {
            echo "<p class='error'>❌ Erro ao inserir morador: " . $stmt_insert->error . "</p>";
        }
        $stmt_insert->close();
    }
    $stmt->close();
    
    fechar_conexao($conexao);
    echo "</div>";

    // Teste 3: Listar moradores
    echo "<div class='test-section'>";
    echo "<h2>3. Teste de Listagem de Moradores</h2>";
    
    $conexao = conectar_banco();
    $resultado = $conexao->query("SELECT id, nome, cpf, unidade FROM moradores LIMIT 5");
    
    if ($resultado && $resultado->num_rows > 0) {
        echo "<p class='success'>✅ Moradores encontrados: " . $resultado->num_rows . "</p>";
        echo "<pre>";
        while ($row = $resultado->fetch_assoc()) {
            echo "ID: {$row['id']} | Nome: {$row['nome']} | CPF: {$row['cpf']} | Unidade: {$row['unidade']}\n";
        }
        echo "</pre>";
    } else {
        echo "<p class='info'>ℹ️ Nenhum morador cadastrado ainda</p>";
    }
    
    fechar_conexao($conexao);
    echo "</div>";

    // Teste 4: Inserir veículo de teste
    echo "<div class='test-section'>";
    echo "<h2>4. Teste de Inserção de Veículo</h2>";
    
    $conexao = conectar_banco();
    
    // Buscar primeiro morador
    $resultado = $conexao->query("SELECT id FROM moradores LIMIT 1");
    if ($resultado && $resultado->num_rows > 0) {
        $morador = $resultado->fetch_assoc();
        $morador_id = $morador['id'];
        
        $placa_teste = "ABC1D23";
        
        // Verificar se já existe
        $stmt = $conexao->prepare("SELECT id FROM veiculos WHERE placa = ?");
        $stmt->bind_param("s", $placa_teste);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            echo "<p class='info'>ℹ️ Veículo de teste já existe no banco</p>";
        } else {
            $modelo = "Honda Civic (Teste)";
            $cor = "Prata";
            $tag = "TAG" . rand(100000, 999999);
            
            $stmt_insert = $conexao->prepare("INSERT INTO veiculos (placa, modelo, cor, tag, morador_id) VALUES (?, ?, ?, ?, ?)");
            $stmt_insert->bind_param("ssssi", $placa_teste, $modelo, $cor, $tag, $morador_id);
            
            if ($stmt_insert->execute()) {
                echo "<p class='success'>✅ Veículo de teste inserido com sucesso! ID: " . $conexao->insert_id . "</p>";
                echo "<p class='info'>TAG gerada: $tag</p>";
            } else {
                echo "<p class='error'>❌ Erro ao inserir veículo: " . $stmt_insert->error . "</p>";
            }
            $stmt_insert->close();
        }
        $stmt->close();
    } else {
        echo "<p class='error'>❌ Nenhum morador cadastrado para vincular o veículo</p>";
    }
    
    fechar_conexao($conexao);
    echo "</div>";

    // Teste 5: Verificar TAG
    echo "<div class='test-section'>";
    echo "<h2>5. Teste de Verificação de TAG</h2>";
    
    $conexao = conectar_banco();
    $resultado = $conexao->query("SELECT v.tag, v.placa, m.nome, m.unidade FROM veiculos v INNER JOIN moradores m ON v.morador_id = m.id WHERE v.ativo = 1 LIMIT 1");
    
    if ($resultado && $resultado->num_rows > 0) {
        $veiculo = $resultado->fetch_assoc();
        echo "<p class='success'>✅ TAG encontrada para teste</p>";
        echo "<pre>";
        echo "TAG: {$veiculo['tag']}\n";
        echo "Placa: {$veiculo['placa']}\n";
        echo "Morador: {$veiculo['nome']}\n";
        echo "Unidade: {$veiculo['unidade']}\n";
        echo "</pre>";
        echo "<p class='info'>💡 Use esta TAG para testar o sistema de acesso</p>";
    } else {
        echo "<p class='info'>ℹ️ Nenhum veículo com TAG cadastrado ainda</p>";
    }
    
    fechar_conexao($conexao);
    echo "</div>";

    // Teste 6: Logs do sistema
    echo "<div class='test-section'>";
    echo "<h2>6. Últimos Logs do Sistema</h2>";
    
    $conexao = conectar_banco();
    $resultado = $conexao->query("SELECT tipo, descricao, DATE_FORMAT(data_hora, '%d/%m/%Y %H:%i:%s') as data_hora FROM logs_sistema ORDER BY data_hora DESC LIMIT 5");
    
    if ($resultado && $resultado->num_rows > 0) {
        echo "<p class='success'>✅ Logs encontrados: " . $resultado->num_rows . "</p>";
        echo "<pre>";
        while ($row = $resultado->fetch_assoc()) {
            echo "[{$row['data_hora']}] {$row['tipo']}: {$row['descricao']}\n";
        }
        echo "</pre>";
    } else {
        echo "<p class='info'>ℹ️ Nenhum log registrado ainda</p>";
    }
    
    fechar_conexao($conexao);
    echo "</div>";

    // Resumo
    echo "<div class='test-section'>";
    echo "<h2>✅ Resumo dos Testes</h2>";
    echo "<p>Todos os testes foram executados. Verifique os resultados acima.</p>";
    echo "<p><strong>Próximos passos:</strong></p>";
    echo "<ul>";
    echo "<li>Acesse <a href='dashboard.html'>dashboard.html</a> para usar o sistema</li>";
    echo "<li>Cadastre moradores em <a href='moradores.html'>moradores.html</a></li>";
    echo "<li>Cadastre veículos em <a href='veiculos.html'>veiculos.html</a></li>";
    echo "<li>Teste o controle de acesso em <a href='acesso.html'>acesso.html</a></li>";
    echo "</ul>";
    echo "</div>";
    ?>

</body>
</html>
