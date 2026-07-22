<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico API Dispositivos</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1000px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #667eea;
            margin-bottom: 30px;
        }
        .test {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
            border-left: 4px solid #667eea;
        }
        .test.success {
            border-left-color: #28a745;
            background: #d4edda;
        }
        .test.error {
            border-left-color: #dc3545;
            background: #f8d7da;
        }
        h3 {
            margin-bottom: 10px;
        }
        pre {
            background: #263238;
            color: #aed581;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
        }
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
            margin-right: 10px;
        }
        .badge-success {
            background: #28a745;
            color: white;
        }
        .badge-error {
            background: #dc3545;
            color: white;
        }
        .sql-script {
            background: #fff;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Diagnóstico API Dispositivos</h1>
        
        <?php
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
        // Teste 1: Verificar se config.php existe
        echo '<div class="test ' . (file_exists('config.php') ? 'success' : 'error') . '">';
        echo '<h3>1. Arquivo config.php</h3>';
        if (file_exists('config.php')) {
            echo '<span class="badge badge-success">✅ EXISTE</span>';
            echo '<p>Arquivo de configuração encontrado.</p>';
        } else {
            echo '<span class="badge badge-error">❌ NÃO EXISTE</span>';
            echo '<p>Arquivo config.php não encontrado!</p>';
        }
        echo '</div>';
        
        // Teste 2: Testar conexão com banco
        if (file_exists('config.php')) {
            require_once 'config.php';
            
            echo '<div class="test">';
            echo '<h3>2. Conexão com Banco de Dados</h3>';
            
            try {
                $conexao = conectar_banco();
                echo '<span class="badge badge-success">✅ CONECTADO</span>';
                echo '<p>Conexão estabelecida com sucesso!</p>';
                echo '<pre>';
                echo "Host: " . DB_HOST . "\n";
                echo "Database: " . DB_NAME . "\n";
                echo "User: " . DB_USER . "\n";
                echo "Charset: " . DB_CHARSET;
                echo '</pre>';
                
                // Teste 3: Verificar se tabela existe
                echo '</div><div class="test">';
                echo '<h3>3. Tabela dispositivos_console</h3>';
                
                $resultado = $conexao->query("SHOW TABLES LIKE 'dispositivos_console'");
                
                if ($resultado->num_rows > 0) {
                    echo '<span class="badge badge-success">✅ EXISTE</span>';
                    echo '<p>Tabela encontrada no banco de dados.</p>';
                    
                    // Verificar estrutura
                    $estrutura = $conexao->query("DESCRIBE dispositivos_console");
                    echo '<h4>Estrutura da Tabela:</h4>';
                    echo '<pre>';
                    while ($campo = $estrutura->fetch_assoc()) {
                        echo $campo['Field'] . ' - ' . $campo['Type'] . "\n";
                    }
                    echo '</pre>';
                    
                    // Contar registros
                    $count = $conexao->query("SELECT COUNT(*) as total FROM dispositivos_console")->fetch_assoc();
                    echo '<p><strong>Total de registros:</strong> ' . $count['total'] . '</p>';
                    
                } else {
                    echo '<span class="badge badge-error">❌ NÃO EXISTE</span>';
                    echo '<p>Tabela dispositivos_console não foi encontrada!</p>';
                    echo '<h4>📝 Script SQL para Criar a Tabela:</h4>';
                    echo '<div class="sql-script">';
                    echo '<pre>';
                    echo "CREATE TABLE IF NOT EXISTS dispositivos_console (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    nome_dispositivo VARCHAR(100) NOT NULL,
    token_acesso VARCHAR(20) NOT NULL UNIQUE,
    tipo_dispositivo ENUM('tablet', 'smartphone', 'computador') DEFAULT 'tablet',
    localizacao VARCHAR(100),
    responsavel VARCHAR(100),
    user_agent TEXT,
    ip_cadastro VARCHAR(45),
    ip_ultimo_acesso VARCHAR(45),
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_ultimo_acesso TIMESTAMP NULL,
    total_acessos INT(11) DEFAULT 0,
    ativo TINYINT(1) DEFAULT 1,
    observacao TEXT,
    INDEX idx_token (token_acesso),
    INDEX idx_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
                    echo '</pre>';
                    echo '</div>';
                    
                    echo '<h4>🔧 Como Criar:</h4>';
                    echo '<ol>';
                    echo '<li>Copie o script SQL acima</li>';
                    echo '<li>Acesse phpMyAdmin no cPanel</li>';
                    echo '<li>Selecione o banco: ' . DB_NAME . '</li>';
                    echo '<li>Vá na aba "SQL"</li>';
                    echo '<li>Cole o script e clique em "Executar"</li>';
                    echo '</ol>';
                }
                
                // Teste 4: Testar API diretamente
                echo '</div><div class="test">';
                echo '<h3>4. Teste da API</h3>';
                
                // Simular requisição GET
                $_SERVER['REQUEST_METHOD'] = 'GET';
                
                ob_start();
                try {
                    include 'api_dispositivos_console.php';
                    $output = ob_get_clean();
                    
                    $json = json_decode($output, true);
                    
                    if ($json !== null) {
                        echo '<span class="badge badge-success">✅ JSON VÁLIDO</span>';
                        echo '<p>API retornou JSON válido.</p>';
                        echo '<pre>' . json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                    } else {
                        echo '<span class="badge badge-error">❌ JSON INVÁLIDO</span>';
                        echo '<p>API não retornou JSON válido.</p>';
                        echo '<h4>Resposta da API:</h4>';
                        echo '<pre>' . htmlspecialchars($output) . '</pre>';
                    }
                } catch (Exception $e) {
                    ob_end_clean();
                    echo '<span class="badge badge-error">❌ ERRO</span>';
                    echo '<p>Erro ao executar API: ' . $e->getMessage() . '</p>';
                }
                
                fechar_conexao($conexao);
                
            } catch (Exception $e) {
                echo '<span class="badge badge-error">❌ ERRO DE CONEXÃO</span>';
                echo '<p>Não foi possível conectar ao banco de dados.</p>';
                echo '<pre>' . $e->getMessage() . '</pre>';
            }
            echo '</div>';
        }
        
        // Teste 5: Verificar se api_dispositivos_console.php existe
        echo '<div class="test ' . (file_exists('api_dispositivos_console.php') ? 'success' : 'error') . '">';
        echo '<h3>5. Arquivo api_dispositivos_console.php</h3>';
        if (file_exists('api_dispositivos_console.php')) {
            echo '<span class="badge badge-success">✅ EXISTE</span>';
            echo '<p>Arquivo da API encontrado.</p>';
            echo '<p><strong>Tamanho:</strong> ' . number_format(filesize('api_dispositivos_console.php') / 1024, 2) . ' KB</p>';
            echo '<p><strong>Última modificação:</strong> ' . date('d/m/Y H:i:s', filemtime('api_dispositivos_console.php')) . '</p>';
        } else {
            echo '<span class="badge badge-error">❌ NÃO EXISTE</span>';
            echo '<p>Arquivo api_dispositivos_console.php não encontrado!</p>';
        }
        echo '</div>';
        
        // Resumo
        echo '<div class="test">';
        echo '<h3>📊 Resumo do Diagnóstico</h3>';
        echo '<ul>';
        echo '<li>' . (file_exists('config.php') ? '✅' : '❌') . ' config.php</li>';
        echo '<li>' . (isset($conexao) ? '✅' : '❌') . ' Conexão com banco</li>';
        echo '<li>' . (isset($resultado) && $resultado->num_rows > 0 ? '✅' : '❌') . ' Tabela dispositivos_console</li>';
        echo '<li>' . (file_exists('api_dispositivos_console.php') ? '✅' : '❌') . ' api_dispositivos_console.php</li>';
        echo '</ul>';
        
        if (!isset($resultado) || $resultado->num_rows == 0) {
            echo '<div style="background: #fff3cd; padding: 15px; border-radius: 5px; margin-top: 20px;">';
            echo '<h4>⚠️ AÇÃO NECESSÁRIA</h4>';
            echo '<p>A tabela <strong>dispositivos_console</strong> não existe no banco de dados.</p>';
            echo '<p>Execute o script SQL fornecido acima para criar a tabela.</p>';
            echo '</div>';
        }
        echo '</div>';
        ?>
        
        <div style="margin-top: 30px; padding: 20px; background: #e9ecef; border-radius: 5px; text-align: center;">
            <p style="margin: 0; color: #666;">
                Diagnóstico realizado em <?php echo date('d/m/Y H:i:s'); ?>
            </p>
        </div>
    </div>
</body>
</html>
