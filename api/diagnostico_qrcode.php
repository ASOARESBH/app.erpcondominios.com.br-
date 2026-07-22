<?php
/**
 * Página de Diagnóstico de QR Code
 * Testa todas as configurações e métodos de geração
 */

header('Content-Type: text/html; charset=utf-8');

require_once 'qrcode_generator.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico QR Code - ERP Condomínio</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; padding: 2rem; }
        .container { max-width: 900px; margin: 0 auto; }
        .header { background: #fff; padding: 2rem; border-radius: 12px; margin-bottom: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { color: #1e293b; margin-bottom: 0.5rem; }
        .subtitle { color: #64748b; }
        .section { background: #fff; padding: 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .section h2 { color: #1e293b; margin-bottom: 1rem; font-size: 1.2rem; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .info-item { padding: 1rem; background: #f8fafc; border-radius: 8px; }
        .info-label { font-weight: 600; color: #475569; margin-bottom: 0.25rem; font-size: 0.9rem; }
        .info-value { color: #1e293b; font-size: 1.1rem; }
        .status-ok { color: #10b981; font-weight: 600; }
        .status-error { color: #ef4444; font-weight: 600; }
        .status-warning { color: #f59e0b; font-weight: 600; }
        .test-result { padding: 1rem; background: #f8fafc; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #cbd5e1; }
        .test-result.success { border-left-color: #10b981; background: #dcfce7; }
        .test-result.error { border-left-color: #ef4444; background: #fee2e2; }
        .test-result h3 { margin-bottom: 0.5rem; font-size: 1rem; }
        .qr-preview { text-align: center; padding: 1rem; background: #f8fafc; border-radius: 8px; }
        .qr-preview img { max-width: 300px; border: 2px solid #e2e8f0; border-radius: 8px; }
        .code-block { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 8px; overflow-x: auto; font-family: 'Courier New', monospace; font-size: 0.9rem; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #3b82f6; color: #fff; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Diagnóstico de QR Code</h1>
            <p class="subtitle">Verificação completa das configurações e métodos de geração</p>
        </div>

        <!-- Configurações do Servidor -->
        <div class="section">
            <h2>⚙️ Configurações do Servidor PHP</h2>
            <div class="info-grid">
                <?php
                $diagnostico = QRCodeGenerator::diagnosticar();
                
                foreach ($diagnostico as $chave => $valor) {
                    $status_class = '';
                    if (in_array($valor, ['Habilitado', 'Sim'])) {
                        $status_class = 'status-ok';
                    } elseif (in_array($valor, ['Desabilitado', 'Não'])) {
                        $status_class = 'status-error';
                    }
                    
                    $label = str_replace('_', ' ', ucfirst($chave));
                    echo "<div class='info-item'>";
                    echo "<div class='info-label'>$label</div>";
                    echo "<div class='info-value $status_class'>$valor</div>";
                    echo "</div>";
                }
                ?>
            </div>
        </div>

        <!-- Teste de Geração -->
        <div class="section">
            <h2>🧪 Testes de Geração de QR Code</h2>
            
            <?php
            $dados_teste = json_encode([
                'codigo' => 'TESTE-' . time(),
                'visitante' => 'Teste Diagnóstico',
                'documento' => '000.000.000-00',
                'tipo_acesso' => 'portaria',
                'data_inicial' => date('Y-m-d'),
                'data_final' => date('Y-m-d', strtotime('+7 days'))
            ]);
            
            // Teste 1: Google Charts
            echo "<div class='test-result'>";
            echo "<h3>Teste 1: Google Charts API</h3>";
            $img1 = QRCodeGenerator::tentarGoogleCharts($dados_teste, 200);
            if ($img1 !== false) {
                echo "<p class='status-ok'>✅ Sucesso! Tamanho: " . strlen($img1) . " bytes</p>";
                echo "<div class='qr-preview'><img src='data:image/png;base64," . base64_encode($img1) . "' alt='QR Code Google'></div>";
            } else {
                echo "<p class='status-error'>❌ Falhou - Servidor pode estar bloqueando chart.googleapis.com</p>";
            }
            echo "</div>";
            
            // Teste 2: QR Server
            echo "<div class='test-result'>";
            echo "<h3>Teste 2: QR Server API (Alternativa)</h3>";
            $img2 = QRCodeGenerator::tentarQRServer($dados_teste, 200);
            if ($img2 !== false) {
                echo "<p class='status-ok'>✅ Sucesso! Tamanho: " . strlen($img2) . " bytes</p>";
                echo "<div class='qr-preview'><img src='data:image/png;base64," . base64_encode($img2) . "' alt='QR Code QR Server'></div>";
            } else {
                echo "<p class='status-error'>❌ Falhou - Servidor pode estar bloqueando api.qrserver.com</p>";
            }
            echo "</div>";
            
            // Teste 3: CURL
            if (function_exists('curl_init')) {
                echo "<div class='test-result'>";
                echo "<h3>Teste 3: QR Server com CURL</h3>";
                $img3 = QRCodeGenerator::gerarComCURL($dados_teste, 200);
                if ($img3 !== false) {
                    echo "<p class='status-ok'>✅ Sucesso! Tamanho: " . strlen($img3) . " bytes</p>";
                    echo "<div class='qr-preview'><img src='data:image/png;base64," . base64_encode($img3) . "' alt='QR Code CURL'></div>";
                } else {
                    echo "<p class='status-error'>❌ Falhou - Problema de conectividade ou firewall</p>";
                }
                echo "</div>";
            }
            
            // Teste 4: Método Inteligente (com fallback)
            echo "<div class='test-result'>";
            echo "<h3>Teste 4: Método Inteligente (com fallback automático)</h3>";
            $img4 = QRCodeGenerator::gerarInteligente($dados_teste, 200);
            if ($img4 !== false) {
                echo "<p class='status-ok'>✅ Sucesso! Este é o método usado pelo sistema.</p>";
                echo "<div class='qr-preview'><img src='data:image/png;base64," . base64_encode($img4) . "' alt='QR Code Inteligente'></div>";
            } else {
                echo "<p class='status-error'>❌ Falhou - Todos os métodos falharam</p>";
            }
            echo "</div>";
            ?>
        </div>

        <!-- Recomendações -->
        <div class="section">
            <h2>💡 Recomendações</h2>
            
            <?php
            $tem_problema = false;
            
            if ($diagnostico['allow_url_fopen'] === 'Desabilitado' && $diagnostico['curl_disponivel'] === 'Não') {
                $tem_problema = true;
                echo "<div class='test-result error'>";
                echo "<h3>⚠️ Problema Crítico</h3>";
                echo "<p><strong>allow_url_fopen está desabilitado E CURL não está disponível.</strong></p>";
                echo "<p>Solução: Entre em contato com o suporte da hospedagem para:</p>";
                echo "<ul style='margin-left: 1.5rem; margin-top: 0.5rem;'>";
                echo "<li>Habilitar allow_url_fopen no php.ini, OU</li>";
                echo "<li>Instalar/habilitar extensão CURL</li>";
                echo "</ul>";
                echo "</div>";
            } elseif ($diagnostico['allow_url_fopen'] === 'Desabilitado') {
                echo "<div class='test-result error'>";
                echo "<h3>⚠️ allow_url_fopen Desabilitado</h3>";
                echo "<p>O sistema está usando CURL como alternativa. Funciona, mas é recomendado habilitar allow_url_fopen.</p>";
                echo "</div>";
            }
            
            if (!$tem_problema && isset($img4) && $img4 === false) {
                echo "<div class='test-result error'>";
                echo "<h3>🔥 Problema de Conectividade</h3>";
                echo "<p>As configurações estão corretas, mas o servidor não consegue acessar APIs externas.</p>";
                echo "<p><strong>Possíveis causas:</strong></p>";
                echo "<ul style='margin-left: 1.5rem; margin-top: 0.5rem;'>";
                echo "<li>Firewall bloqueando saída HTTPS</li>";
                echo "<li>Servidor sem acesso à internet</li>";
                echo "<li>Proxy/VPN bloqueando requisições</li>";
                echo "</ul>";
                echo "<p style='margin-top: 1rem;'><strong>Solução:</strong> Entre em contato com o suporte da hospedagem.</p>";
                echo "</div>";
            }
            
            if (!$tem_problema && isset($img4) && $img4 !== false) {
                echo "<div class='test-result success'>";
                echo "<h3>✅ Tudo Funcionando!</h3>";
                echo "<p>O sistema de QR Code está funcionando corretamente. Se ainda houver erro em visitantes.html, pode ser:</p>";
                echo "<ul style='margin-left: 1.5rem; margin-top: 0.5rem;'>";
                echo "<li>Cache do navegador (pressione Ctrl+Shift+R para limpar)</li>";
                echo "<li>Arquivo api_acessos_visitantes.php não atualizado no servidor</li>";
                echo "<li>Arquivo qrcode_generator.php não foi enviado para o servidor</li>";
                echo "</ul>";
                echo "</div>";
            }
            ?>
        </div>

        <!-- Informações Técnicas -->
        <div class="section">
            <h2>📋 Informações Técnicas</h2>
            <div class="code-block">
                <strong>Versão PHP:</strong> <?php echo PHP_VERSION; ?><br>
                <strong>Sistema Operacional:</strong> <?php echo PHP_OS; ?><br>
                <strong>Servidor:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Desconhecido'; ?><br>
                <strong>Extensões Carregadas:</strong> <?php echo implode(', ', get_loaded_extensions()); ?>
            </div>
        </div>

        <a href="visitantes.html" class="btn">← Voltar para Visitantes</a>
        <a href="logs_sistema_v2.html" class="btn">Ver Logs de Erro</a>
    </div>
</body>
</html>
