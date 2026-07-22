<?php
// =====================================================
// TESTE DE SESSÃO - DEBUG
// =====================================================

session_start();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Teste de Sessão</title>
    <style>
        body {
            font-family: monospace;
            padding: 20px;
            background: #1e293b;
            color: #e2e8f0;
        }
        h1 { color: #3b82f6; }
        .info { background: #334155; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        pre { background: #0f172a; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🔍 Teste de Sessão PHP</h1>
    
    <div class="info">
        <h2>Status da Sessão</h2>
        <?php if (isset($_SESSION['usuario_logado']) && $_SESSION['usuario_logado'] === true): ?>
            <p class="success">✅ Usuário está logado!</p>
        <?php else: ?>
            <p class="error">❌ Usuário NÃO está logado!</p>
        <?php endif; ?>
    </div>
    
    <div class="info">
        <h2>Session ID</h2>
        <pre><?php echo session_id(); ?></pre>
    </div>
    
    <div class="info">
        <h2>Dados da Sessão</h2>
        <pre><?php print_r($_SESSION); ?></pre>
    </div>
    
    <div class="info">
        <h2>Cookies</h2>
        <pre><?php print_r($_COOKIE); ?></pre>
    </div>
    
    <div class="info">
        <h2>Configurações de Sessão</h2>
        <pre><?php
        echo "session.save_path: " . session_save_path() . "\n";
        echo "session.name: " . session_name() . "\n";
        echo "session.cookie_lifetime: " . ini_get('session.cookie_lifetime') . "\n";
        echo "session.gc_maxlifetime: " . ini_get('session.gc_maxlifetime') . "\n";
        echo "session.cookie_httponly: " . ini_get('session.cookie_httponly') . "\n";
        echo "session.use_only_cookies: " . ini_get('session.use_only_cookies') . "\n";
        ?></pre>
    </div>
    
    <div class="info">
        <h2>Timestamp</h2>
        <?php if (isset($_SESSION['login_timestamp'])): ?>
            <p>Login: <?php echo date('Y-m-d H:i:s', $_SESSION['login_timestamp']); ?></p>
            <p>Agora: <?php echo date('Y-m-d H:i:s', time()); ?></p>
            <p>Tempo decorrido: <?php echo (time() - $_SESSION['login_timestamp']); ?> segundos</p>
        <?php else: ?>
            <p class="error">Timestamp não definido</p>
        <?php endif; ?>
    </div>
    
    <div class="info">
        <a href="login.html" style="color: #3b82f6;">← Voltar para Login</a> |
        <a href="dashboard.html" style="color: #3b82f6;">Ir para Dashboard →</a> |
        <a href="logout.php" style="color: #ef4444;">Sair</a>
    </div>
</body>
</html>
