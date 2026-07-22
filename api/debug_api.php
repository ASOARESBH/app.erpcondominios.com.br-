<?php
/**
 * SCRIPT DE DEBUG PARA TESTAR API
 * 
 * Acesse: https://seu_dominio.com/api/debug_api.php
 * 
 * Este script testa todas as funcionalidades da API
 */

// Limpar saída anterior
ob_start();
ob_end_clean();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug API - Sistema de Portaria</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Courier New', monospace; background: #1e1e1e; color: #d4d4d4; padding: 2rem; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #4ec9b0; margin-bottom: 2rem; }
        .test-section { background: #252526; padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 8px; border-left: 4px solid #007acc; }
        .test-section h2 { color: #569cd6; margin-bottom: 1rem; font-size: 1.1rem; }
        .test-item { margin-bottom: 1rem; padding: 1rem; background: #1e1e1e; border-radius: 4px; }
        .test-label { color: #ce9178; font-weight: bold; }
        .test-result { margin-top: 0.5rem; padding: 0.5rem; border-radius: 4px; }
        .success { background: #1e4620; color: #4ec9b0; }
        .error { background: #4d1f1f; color: #f48771; }
        .warning { background: #4d3d1f; color: #dcdcaa; }
        .info { background: #1f3d4d; color: #9cdcfe; }
        button { background: #007acc; color: #fff; padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; margin-right: 0.5rem; }
        button:hover { background: #005a9e; }
        pre { background: #1e1e1e; padding: 1rem; border-radius: 4px; overflow-x: auto; margin-top: 0.5rem; }
        .url-input { width: 100%; padding: 0.5rem; margin-bottom: 0.5rem; background: #3e3e42; color: #d4d4d4; border: 1px solid #555; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 DEBUG API - Sistema de Portaria</h1>
        
        <div class="test-section">
            <h2>1️⃣ Teste de Conexão com Banco de Dados</h2>
            <div class="test-item">
                <span class="test-label">Status:</span>
                <div class="test-result" id="db-status">Testando...</div>
            </div>
        </div>

        <div class="test-section">
            <h2>2️⃣ Teste de API de Moradores</h2>
            <div class="test-item">
                <label>Filtro por Unidade:</label>
                <input type="text" id="unidade-input" class="url-input" placeholder="Ex: Gleba 1" value="Gleba 1">
                <button onclick="testarMoradores()">Testar</button>
                <div class="test-result" id="moradores-result"></div>
            </div>
        </div>

        <div class="test-section">
            <h2>3️⃣ Teste de API de Protocolos</h2>
            <div class="test-item">
                <button onclick="testarProtocolos()">Listar Protocolos</button>
                <div class="test-result" id="protocolos-result"></div>
            </div>
        </div>

        <div class="test-section">
            <h2>4️⃣ Teste de API de Usuário Logado</h2>
            <div class="test-item">
                <button onclick="testarUsuarioLogado()">Verificar Usuário</button>
                <div class="test-result" id="usuario-result"></div>
            </div>
        </div>

        <div class="test-section">
            <h2>5️⃣ Teste de API Customizada</h2>
            <div class="test-item">
                <label>URL da API:</label>
                <input type="text" id="api-url" class="url-input" placeholder="Ex: api_moradores.php?unidade=Gleba%201">
                <button onclick="testarCustom()">Testar</button>
                <div class="test-result" id="custom-result"></div>
            </div>
        </div>

        <div class="test-section">
            <h2>6️⃣ Informações do Sistema</h2>
            <div class="test-item">
                <span class="test-label">PHP Version:</span>
                <div class="test-result info"><?php echo phpversion(); ?></div>
            </div>
            <div class="test-item">
                <span class="test-label">Servidor:</span>
                <div class="test-result info"><?php echo $_SERVER['SERVER_SOFTWARE']; ?></div>
            </div>
            <div class="test-item">
                <span class="test-label">URL Base:</span>
                <div class="test-result info"><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']; ?></div>
            </div>
        </div>
    </div>

    <script>
        const baseUrl = window.location.origin;

        async function testarMoradores() {
            const unidade = document.getElementById('unidade-input').value;
            const resultDiv = document.getElementById('moradores-result');
            resultDiv.innerHTML = '<div class="warning">Testando...</div>';
            
            try {
                const response = await fetch(`api_moradores.php?unidade=${encodeURIComponent(unidade)}`);
                const data = await response.json();
                
                if (data.sucesso) {
                    resultDiv.innerHTML = `<div class="success">✅ Sucesso! ${data.dados.length} moradores encontrados</div><pre>${JSON.stringify(data, null, 2)}</pre>`;
                } else {
                    resultDiv.innerHTML = `<div class="warning">⚠️ ${data.mensagem}</div><pre>${JSON.stringify(data, null, 2)}</pre>`;
                }
            } catch (err) {
                resultDiv.innerHTML = `<div class="error">❌ Erro: ${err.message}</div><pre>${err.stack}</pre>`;
            }
        }

        async function testarProtocolos() {
            const resultDiv = document.getElementById('protocolos-result');
            resultDiv.innerHTML = '<div class="warning">Testando...</div>';
            
            try {
                const response = await fetch('api_protocolos.php');
                const data = await response.json();
                
                if (data.sucesso) {
                    resultDiv.innerHTML = `<div class="success">✅ Sucesso! ${data.dados.length} protocolos encontrados</div><pre>${JSON.stringify(data.dados.slice(0, 3), null, 2)}</pre>`;
                } else {
                    resultDiv.innerHTML = `<div class="warning">⚠️ ${data.mensagem}</div><pre>${JSON.stringify(data, null, 2)}</pre>`;
                }
            } catch (err) {
                resultDiv.innerHTML = `<div class="error">❌ Erro: ${err.message}</div><pre>${err.stack}</pre>`;
            }
        }

        async function testarUsuarioLogado() {
            const resultDiv = document.getElementById('usuario-result');
            resultDiv.innerHTML = '<div class="warning">Testando...</div>';
            
            try {
                const response = await fetch('api_usuario_logado.php');
                const data = await response.json();
                
                if (data.sucesso) {
                    resultDiv.innerHTML = `<div class="success">✅ Usuário logado: ${data.dados.nome}</div><pre>${JSON.stringify(data, null, 2)}</pre>`;
                } else {
                    resultDiv.innerHTML = `<div class="warning">⚠️ ${data.mensagem}</div><pre>${JSON.stringify(data, null, 2)}</pre>`;
                }
            } catch (err) {
                resultDiv.innerHTML = `<div class="error">❌ Erro: ${err.message}</div><pre>${err.stack}</pre>`;
            }
        }

        async function testarCustom() {
            const url = document.getElementById('api-url').value;
            const resultDiv = document.getElementById('custom-result');
            
            if (!url) {
                resultDiv.innerHTML = '<div class="error">❌ Digite uma URL</div>';
                return;
            }
            
            resultDiv.innerHTML = '<div class="warning">Testando...</div>';
            
            try {
                const response = await fetch(url);
                const text = await response.text();
                
                try {
                    const data = JSON.parse(text);
                    if (data.sucesso) {
                        resultDiv.innerHTML = `<div class="success">✅ Sucesso!</div><pre>${JSON.stringify(data, null, 2)}</pre>`;
                    } else {
                        resultDiv.innerHTML = `<div class="warning">⚠️ ${data.mensagem}</div><pre>${JSON.stringify(data, null, 2)}</pre>`;
                    }
                } catch (e) {
                    resultDiv.innerHTML = `<div class="error">❌ Resposta não é JSON válido</div><pre>${text}</pre>`;
                }
            } catch (err) {
                resultDiv.innerHTML = `<div class="error">❌ Erro: ${err.message}</div><pre>${err.stack}</pre>`;
            }
        }

        // Testar banco de dados ao carregar
        window.addEventListener('load', async () => {
            const resultDiv = document.getElementById('db-status');
            try {
                const response = await fetch('api_moradores.php?unidade=TEST');
                const data = await response.json();
                resultDiv.innerHTML = '<div class="success">✅ Banco de dados conectado</div>';
            } catch (err) {
                resultDiv.innerHTML = `<div class="error">❌ Erro ao conectar: ${err.message}</div>`;
            }
        });
    </script>
</body>
</html>
