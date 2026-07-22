<?php
// =====================================================
// VISUALIZADOR DE LOG DE LOGIN MORADOR
// =====================================================

// Caminho do arquivo de log
$log_file = 'login_morador_debug.log';

// Verificar se o arquivo existe
if (!file_exists($log_file)) {
    echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Log de Login Morador</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #4ec9b0; }
        .info { background: #264f78; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>📋 Log de Login Morador</h1>
        <div class='info'>
            ℹ️ Nenhum log encontrado ainda. Faça uma tentativa de login para gerar o log.
        </div>
    </div>
</body>
</html>";
    exit;
}

// Ler o conteúdo do log
$log_content = file_get_contents($log_file);

// Obter tamanho do arquivo
$file_size = filesize($log_file);
$file_size_kb = number_format($file_size / 1024, 2);

// Obter última modificação
$last_modified = date('d/m/Y H:i:s', filemtime($log_file));

// Contar linhas
$num_lines = substr_count($log_content, "\n");

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log de Login Morador - Debug</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        h1 {
            color: #4ec9b0;
            margin-bottom: 20px;
            font-size: 2rem;
        }
        
        .info-bar {
            background: #264f78;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-label {
            color: #9cdcfe;
            font-weight: bold;
        }
        
        .actions {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-refresh {
            background: #0e639c;
            color: #fff;
        }
        
        .btn-refresh:hover {
            background: #1177bb;
        }
        
        .btn-clear {
            background: #c72e0f;
            color: #fff;
        }
        
        .btn-clear:hover {
            background: #e03e1f;
        }
        
        .btn-download {
            background: #0e7c0e;
            color: #fff;
        }
        
        .btn-download:hover {
            background: #1e9c1e;
        }
        
        .log-container {
            background: #252526;
            border: 1px solid #3e3e42;
            border-radius: 8px;
            padding: 20px;
            overflow-x: auto;
        }
        
        .log-line {
            margin-bottom: 8px;
            padding: 5px;
            border-radius: 3px;
        }
        
        .log-line:hover {
            background: #2d2d30;
        }
        
        .timestamp {
            color: #858585;
        }
        
        .log-inicio {
            color: #4ec9b0;
            font-weight: bold;
        }
        
        .log-erro {
            color: #f48771;
            font-weight: bold;
        }
        
        .log-sucesso {
            color: #4ec9b0;
            font-weight: bold;
        }
        
        .log-info {
            color: #9cdcfe;
        }
        
        .log-dados {
            color: #ce9178;
            margin-left: 20px;
        }
        
        .empty-log {
            text-align: center;
            padding: 40px;
            color: #858585;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            h1 {
                font-size: 1.5rem;
            }
            
            .info-bar {
                flex-direction: column;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Log de Login Morador - Debug</h1>
        
        <div class="info-bar">
            <div class="info-item">
                <span class="info-label">📄 Arquivo:</span>
                <span><?php echo $log_file; ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">📏 Tamanho:</span>
                <span><?php echo $file_size_kb; ?> KB</span>
            </div>
            <div class="info-item">
                <span class="info-label">📝 Linhas:</span>
                <span><?php echo $num_lines; ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">🕐 Última modificação:</span>
                <span><?php echo $last_modified; ?></span>
            </div>
        </div>
        
        <div class="actions">
            <button class="btn btn-refresh" onclick="location.reload()">
                🔄 Atualizar
            </button>
            <a href="?limpar=1" class="btn btn-clear" onclick="return confirm('Deseja realmente limpar o log?')">
                🗑️ Limpar Log
            </a>
            <a href="?download=1" class="btn btn-download">
                💾 Baixar Log
            </a>
        </div>
        
        <div class="log-container">
            <?php
            if (empty(trim($log_content))) {
                echo '<div class="empty-log">Nenhum log registrado ainda.</div>';
            } else {
                $lines = explode("\n", $log_content);
                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;
                    
                    // Identificar tipo de log
                    $class = 'log-info';
                    if (strpos($line, 'INÍCIO') !== false) {
                        $class = 'log-inicio';
                    } elseif (strpos($line, 'ERRO') !== false) {
                        $class = 'log-erro';
                    } elseif (strpos($line, 'SUCESSO') !== false || strpos($line, 'BEM-SUCEDIDO') !== false) {
                        $class = 'log-sucesso';
                    }
                    
                    // Destacar timestamp
                    $line = preg_replace('/\[([\d\-: ]+)\]/', '<span class="timestamp">[$1]</span>', $line);
                    
                    // Destacar dados JSON
                    if (strpos($line, 'Dados:') !== false) {
                        $parts = explode('Dados:', $line);
                        echo '<div class="log-line ' . $class . '">' . $parts[0] . '</div>';
                        echo '<div class="log-dados">Dados: ' . $parts[1] . '</div>';
                    } else {
                        echo '<div class="log-line ' . $class . '">' . $line . '</div>';
                    }
                }
            }
            ?>
        </div>
    </div>
    
    <script>
        // Auto-refresh a cada 5 segundos
        setTimeout(function() {
            location.reload();
        }, 5000);
    </script>
</body>
</html>

<?php
// Ação de limpar log
if (isset($_GET['limpar'])) {
    file_put_contents($log_file, '');
    header('Location: ver_log_morador.php');
    exit;
}

// Ação de download
if (isset($_GET['download'])) {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="login_morador_debug_' . date('Y-m-d_H-i-s') . '.log"');
    echo $log_content;
    exit;
}
?>

