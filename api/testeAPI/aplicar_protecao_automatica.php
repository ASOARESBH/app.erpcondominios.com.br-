<?php
/**
 * =====================================================
 * SCRIPT DE PROTEÇÃO AUTOMÁTICA DE ENDPOINTS
 * =====================================================
 * 
 * INSTRUÇÕES:
 * 1. Copie este arquivo para a pasta /api/
 * 2. Acesse via navegador: http://seu-dominio.com/api/aplicar_protecao_automatica.php
 * 3. O script irá proteger todos os endpoints automaticamente
 * 4. Um arquivo log_protecao_YYYY-MM-DD_HH-MM-SS.txt será gerado
 * 5. Após conclusão, DELETE este arquivo do servidor por segurança
 * 
 * AVISO: Este script deve ser executado APENAS UMA VEZ
 */

// Configurações de segurança
$ip_permitido = ['127.0.0.1', 'localhost', '::1'];
$apenas_localhost = true;

// Validar acesso (apenas localhost)
if ($apenas_localhost && !in_array($_SERVER['REMOTE_ADDR'], $ip_permitido)) {
    die("❌ ACESSO NEGADO. Este script só pode ser executado localmente (localhost).\n");
}

// Configurações
$api_dir = __DIR__;
$root_dir = dirname($api_dir);
$timestamp = date('Y-m-d_H-i-s');
$log_file = $api_dir . '/log_protecao_' . $timestamp . '.txt';
$backup_dir = $api_dir . '/backups_protecao_' . $timestamp;

// Criar diretório de backup
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Inicializar log
$log_content = "================================================================================\n";
$log_content .= "RELATÓRIO DE PROTEÇÃO AUTOMÁTICA DE ENDPOINTS\n";
$log_content .= "================================================================================\n\n";
$log_content .= "Data/Hora: " . date('d/m/Y H:i:s') . "\n";
$log_content .= "Diretório: " . $api_dir . "\n";
$log_content .= "Backup em: " . $backup_dir . "\n";
$log_content .= "IP de Execução: " . $_SERVER['REMOTE_ADDR'] . "\n";
$log_content .= "\n";

// Endpoints já protegidos (não modificar)
$endpoints_protegidos = [
    'api_acessos_visitantes.php',
    'api_visitantes.php',
    'api_moradores.php',
    'api_usuarios.php',
    'api_notificacoes.php',
    'api_checklist.php',
    'api_estoque.php',
    'api_pedidos.php',
    'api_contas_pagar.php',
    'api_contas_receber.php',
    'api_face_id.php',
    'api_abastecimento.php',
    'api_admin_fornecedores.php',
    'api_ramos_atividade.php',
    'api_config_periodo_leitura.php',
];

// Endpoints a proteger com suas permissões
$endpoints_para_proteger = [
    // Administrativos (admin)
    'api_logs_sistema.php' => 'admin',
    'api_logs_erro.php' => 'admin',
    'api_email_log.php' => 'admin',
    'api_email_templates.php' => 'admin',
    'api_smtp.php' => 'admin',
    
    // Inventário e Leitura (operador)
    'api_inventario.php' => 'operador',
    'api_leituras.php' => 'operador',
    'api_hidrometros.php' => 'operador',
    'api_avaliacoes.php' => 'operador',
    
    // Dispositivos (operador)
    'api_dispositivos.php' => 'operador',
    'api_dispositivos_console.php' => 'operador',
    'api_dispositivos_seguranca.php' => 'operador',
    
    // Portais (operador)
    'api_portal.php' => 'operador',
    'api_portal_morador.php' => 'operador',
    
    // Dados de Moradores (operador)
    'api_morador_dados.php' => 'operador',
    'api_morador_notificacoes.php' => 'operador',
    'api_morador_veiculos.php' => 'operador',
    'api_morador_protocolos.php' => 'operador',
    'api_morador_hidrometro.php' => 'operador',
    
    // Financeiro (gerente)
    'api_planos_contas.php' => 'gerente',
    'api_marketplace.php' => 'operador',
    
    // Gerenciamento (operador)
    'api_fornecedores.php' => 'operador',
    'api_produtos_servicos.php' => 'operador',
    'api_console_acesso.php' => 'operador',
    'api_dashboard_acessos.php' => 'operador',
    'api_dashboard_agua.php' => 'operador',
    'api_rfid.php' => 'operador',
    'api_unidades.php' => 'operador',
    'api_veiculos.php' => 'operador',
    'api_protocolos.php' => 'operador',
    'api_checklist_alertas.php' => 'operador',
    'api_checklist_itens.php' => 'operador',
    'api_registros.php' => 'operador',
    
    // Outros
    'api_recuperacao_senha.php' => 'operador',
    'api_sessao_fornecedor.php' => 'operador',
    'api_usuario_logado.php' => 'operador',
    'api_validar_token.php' => 'operador',
    'api_verificar_sessao.php' => 'operador',
    'api_login_fornecedor.php' => 'operador',
];

// Template de proteção
$template_protecao = <<<'PROTECAO'
header('Access-Control-Allow-Origin: https://asl.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'auth_helper.php';

// VERIFICAÇÃO CRÍTICA DE AUTENTICAÇÃO
verificarAutenticacao(true, '%PERMISSAO%');

// Para operações de escrita, verificar permissão apropriada
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    verificarPermissao('%PERMISSAO%');
}
PROTECAO;

/**
 * Função para adicionar proteção a um endpoint
 */
function adicionar_protecao($caminho, $permissao, $template, &$log) {
    global $backup_dir;
    
    if (!file_exists($caminho)) {
        $log .= "  ❌ Arquivo não encontrado: $caminho\n";
        return false;
    }
    
    $conteudo_original = file_get_contents($caminho);
    
    // Verificar se já foi protegido
    if (strpos($conteudo_original, 'verificarAutenticacao') !== false) {
        $log .= "  ⏭️  Já protegido (pulado)\n";
        return false;
    }
    
    // Preparar proteção com permissão
    $protecao = str_replace('%PERMISSAO%', $permissao, $template);
    
    // Fazer backup
    $nome_arquivo = basename($caminho);
    copy($caminho, $backup_dir . '/' . $nome_arquivo);
    $log .= "  📦 Backup criado: backups_protecao_*//$nome_arquivo\n";
    
    // Encontrar posição para inserir
    $conteudo_novo = $conteudo_original;
    $pos_config = strpos($conteudo_original, "require_once 'config.php'");
    $pos_header = strpos($conteudo_original, "header('Content-Type:");
    $pos_php = strpos($conteudo_original, "<?php");
    
    if ($pos_config !== false) {
        // Inserir após require_once 'config.php'
        $pos = $pos_config + strlen("require_once 'config.php'");
        
        // Verificar se auth_helper já está incluído
        if (strpos($conteudo_original, "require_once 'auth_helper.php'", $pos) === false) {
            $conteudo_novo = substr_replace($conteudo_original, "\nrequire_once 'auth_helper.php';\n\n" . $protecao, $pos, 0);
            $log .= "  ✅ Proteção inserida após require_once 'config.php'\n";
        } else {
            $log .= "  ⚠️  auth_helper.php já incluído\n";
            return false;
        }
    } elseif ($pos_header !== false) {
        // Inserir após header('Content-Type:
        $pos = strpos($conteudo_original, "\n", $pos_header) + 1;
        $conteudo_novo = substr_replace($conteudo_original, "\nrequire_once 'config.php';\nrequire_once 'auth_helper.php';\n\n" . $protecao . "\n", $pos, 0);
        $log .= "  ✅ Proteção inserida após header('Content-Type')\n";
    } elseif ($pos_php !== false) {
        // Inserir após <?php
        $pos = $pos_php + 5;
        $conteudo_novo = substr_replace($conteudo_original, "\nrequire_once 'config.php';\nrequire_once 'auth_helper.php';\n\n" . $protecao . "\n", $pos, 0);
        $log .= "  ✅ Proteção inserida após <?php\n";
    } else {
        $log .= "  ❌ Não foi possível encontrar ponto de inserção\n";
        return false;
    }
    
    // Salvar arquivo
    if (file_put_contents($caminho, $conteudo_novo)) {
        $log .= "  💾 Arquivo salvo com sucesso\n";
        
        // Calcular diferença
        $linhas_adicionadas = substr_count($protecao, "\n") + 2;
        $log .= "  📊 Linhas adicionadas: $linhas_adicionadas\n";
        
        return true;
    } else {
        $log .= "  ❌ Erro ao salvar arquivo\n";
        return false;
    }
}

// Iniciar processamento
$log_content .= "================================================================================\n";
$log_content .= "PROCESSAMENTO DE ENDPOINTS\n";
$log_content .= "================================================================================\n\n";

$total = 0;
$sucesso = 0;
$falha = 0;
$pulado = 0;

// Processar cada endpoint
foreach ($endpoints_para_proteger as $endpoint => $permissao) {
    $caminho = $api_dir . '/' . $endpoint;
    
    $log_content .= "\n[" . ($total + 1) . "] Processando: $endpoint\n";
    $log_content .= "    Permissão: $permissao\n";
    $log_content .= "    Caminho: $caminho\n";
    
    // Pular se já foi protegido
    if (in_array($endpoint, $endpoints_protegidos)) {
        $log_content .= "    Status: ⏭️  JÁ PROTEGIDO (pulado)\n";
        $pulado++;
        $total++;
        continue;
    }
    
    // Adicionar proteção
    if (adicionar_protecao($caminho, $permissao, $template_protecao, $log_content)) {
        $log_content .= "    Status: ✅ PROTEGIDO COM SUCESSO\n";
        $sucesso++;
    } else {
        $log_content .= "    Status: ❌ FALHA NA PROTEÇÃO\n";
        $falha++;
    }
    
    $total++;
}

// Resumo final
$log_content .= "\n\n";
$log_content .= "================================================================================\n";
$log_content .= "RESUMO FINAL\n";
$log_content .= "================================================================================\n\n";
$log_content .= "Total de Endpoints Processados: $total\n";
$log_content .= "✅ Protegidos com Sucesso: $sucesso\n";
$log_content .= "⏭️  Já Protegidos (Pulados): $pulado\n";
$log_content .= "❌ Falhados: $falha\n";
$log_content .= "📊 Taxa de Sucesso: " . ($total > 0 ? round(($sucesso / $total) * 100, 2) : 0) . "%\n\n";

$log_content .= "Arquivo de Log: $log_file\n";
$log_content .= "Diretório de Backup: $backup_dir\n\n";

$log_content .= "⚠️  IMPORTANTE:\n";
$log_content .= "1. Todos os arquivos foram feitos backup em: $backup_dir\n";
$log_content .= "2. Teste todos os endpoints antes de usar em produção\n";
$log_content .= "3. DELETE este arquivo (aplicar_protecao_automatica.php) após conclusão\n";
$log_content .= "4. Monitore os logs de erro para qualquer problema\n";
$log_content .= "5. Verifique o arquivo de log para detalhes completos\n\n";

$log_content .= "================================================================================\n";
$log_content .= "FIM DO RELATÓRIO\n";
$log_content .= "================================================================================\n";

// Salvar log
file_put_contents($log_file, $log_content);

// Exibir resultado
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proteção Automática de Endpoints</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 900px;
            width: 100%;
            padding: 40px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .status-card {
            background: #f8f9fa;
            border-left: 4px solid #007bff;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .status-card.success {
            border-left-color: #28a745;
        }
        .status-card.warning {
            border-left-color: #ffc107;
        }
        .status-card.danger {
            border-left-color: #dc3545;
        }
        .status-number {
            font-size: 32px;
            font-weight: bold;
            color: #007bff;
        }
        .status-card.success .status-number {
            color: #28a745;
        }
        .status-card.warning .status-number {
            color: #ffc107;
        }
        .status-card.danger .status-number {
            color: #dc3545;
        }
        .status-label {
            color: #666;
            font-size: 14px;
            margin-top: 10px;
        }
        .progress-bar {
            width: 100%;
            height: 30px;
            background: #e9ecef;
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 30px;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            transition: width 0.3s ease;
        }
        .alert {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert strong {
            display: block;
            margin-bottom: 5px;
        }
        .log-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-top: 30px;
        }
        .log-title {
            color: #333;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .log-content {
            background: white;
            border: 1px solid #dee2e6;
            padding: 15px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #333;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .download-btn {
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border-radius: 4px;
            text-decoration: none;
            margin-top: 10px;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        .download-btn:hover {
            background: #0056b3;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔒 Proteção Automática de Endpoints</h1>
        <div class="subtitle">Relatório de Execução - <?php echo date('d/m/Y H:i:s'); ?></div>
        
        <div class="status-grid">
            <div class="status-card success">
                <div class="status-number"><?php echo $sucesso; ?></div>
                <div class="status-label">Protegidos com Sucesso</div>
            </div>
            <div class="status-card warning">
                <div class="status-number"><?php echo $pulado; ?></div>
                <div class="status-label">Já Protegidos (Pulados)</div>
            </div>
            <div class="status-card danger">
                <div class="status-number"><?php echo $falha; ?></div>
                <div class="status-label">Falhados</div>
            </div>
            <div class="status-card">
                <div class="status-number"><?php echo $total; ?></div>
                <div class="status-label">Total Processados</div>
            </div>
        </div>
        
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?php echo ($total > 0 ? ($sucesso / $total) * 100 : 0); ?>%">
                <?php echo ($total > 0 ? round(($sucesso / $total) * 100, 1) : 0); ?>%
            </div>
        </div>
        
        <div class="alert">
            <strong>⚠️ IMPORTANTE:</strong>
            1. Todos os arquivos foram feitos backup em: <code><?php echo basename($backup_dir); ?></code><br>
            2. Teste todos os endpoints antes de usar em produção<br>
            3. <strong>DELETE este arquivo (aplicar_protecao_automatica.php) após conclusão</strong><br>
            4. Monitore os logs de erro para qualquer problema
        </div>
        
        <div class="log-section">
            <div class="log-title">📋 Arquivo de Log Completo</div>
            <div class="log-content"><?php echo htmlspecialchars($log_content); ?></div>
            <a href="log_protecao_<?php echo $timestamp; ?>.txt" class="download-btn" download>📥 Baixar Log Completo</a>
        </div>
        
        <div class="footer">
            <p>✅ Proteção concluída em <?php echo date('d/m/Y H:i:s'); ?></p>
            <p>📁 Log salvo em: <code>log_protecao_<?php echo $timestamp; ?>.txt</code></p>
            <p>📦 Backup salvo em: <code><?php echo basename($backup_dir); ?></code></p>
        </div>
    </div>
</body>
</html>
