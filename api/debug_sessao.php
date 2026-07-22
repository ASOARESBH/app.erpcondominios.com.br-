<?php
/**
 * Script de Debug - Verificar Sessões
 * Use este script para diagnosticar problemas de sessão
 */

require_once 'config.php';

echo "<h2>Debug de Sessões - Portal do Morador</h2>";
echo "<hr>";

// Conectar ao banco
$conexao = conectar_banco();

// ========== VERIFICAR FUSO HORÁRIO ==========
echo "<h3>1. Fuso Horário</h3>";

// PHP
$php_now = date('Y-m-d H:i:s');
$php_timezone = date_default_timezone_get();
echo "<p><strong>PHP NOW():</strong> $php_now</p>";
echo "<p><strong>PHP Timezone:</strong> $php_timezone</p>";

// MySQL
$resultado = $conexao->query("SELECT NOW() as mysql_now, @@session.time_zone as mysql_timezone");
$mysql = $resultado->fetch_assoc();
echo "<p><strong>MySQL NOW():</strong> {$mysql['mysql_now']}</p>";
echo "<p><strong>MySQL Timezone:</strong> {$mysql['mysql_timezone']}</p>";

// Comparação
$php_timestamp = strtotime($php_now);
$mysql_timestamp = strtotime($mysql['mysql_now']);
$diferenca = $php_timestamp - $mysql_timestamp;

if ($diferenca == 0) {
    echo "<p style='color:green;'><strong>✅ PHP e MySQL estão sincronizados!</strong></p>";
} else {
    $horas = round($diferenca / 3600, 1);
    echo "<p style='color:red;'><strong>❌ DIFERENÇA de $horas horas entre PHP e MySQL!</strong></p>";
    echo "<p>Isso pode causar problemas de expiração de sessão.</p>";
}

echo "<hr>";

// ========== VERIFICAR SESSÕES ==========
echo "<h3>2. Sessões Ativas</h3>";

$resultado = $conexao->query("
    SELECT 
        s.id,
        s.morador_id,
        m.nome as morador_nome,
        s.token,
        s.data_login,
        s.data_expiracao,
        s.ativo,
        CASE 
            WHEN s.data_expiracao > NOW() THEN 'Válida'
            ELSE 'Expirada'
        END as status_expiracao,
        TIMESTAMPDIFF(HOUR, NOW(), s.data_expiracao) as horas_restantes
    FROM sessoes_portal s
    LEFT JOIN moradores m ON s.morador_id = m.id
    ORDER BY s.data_login DESC
    LIMIT 10
");

if ($resultado->num_rows > 0) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
    echo "<tr>";
    echo "<th>ID</th>";
    echo "<th>Morador</th>";
    echo "<th>Token (10 chars)</th>";
    echo "<th>Login</th>";
    echo "<th>Expiração</th>";
    echo "<th>Ativo</th>";
    echo "<th>Status</th>";
    echo "<th>Horas Restantes</th>";
    echo "</tr>";
    
    while ($sessao = $resultado->fetch_assoc()) {
        $cor_status = ($sessao['status_expiracao'] == 'Válida' && $sessao['ativo'] == 1) ? 'green' : 'red';
        $token_curto = substr($sessao['token'], 0, 10) . '...';
        
        echo "<tr>";
        echo "<td>{$sessao['id']}</td>";
        echo "<td>{$sessao['morador_nome']}</td>";
        echo "<td><code>$token_curto</code></td>";
        echo "<td>{$sessao['data_login']}</td>";
        echo "<td>{$sessao['data_expiracao']}</td>";
        echo "<td>" . ($sessao['ativo'] == 1 ? '✅ Sim' : '❌ Não') . "</td>";
        echo "<td style='color:$cor_status; font-weight:bold;'>{$sessao['status_expiracao']}</td>";
        echo "<td>{$sessao['horas_restantes']}h</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    // Contar sessões válidas
    $validas = $conexao->query("SELECT COUNT(*) as total FROM sessoes_portal WHERE ativo = 1 AND data_expiracao > NOW()")->fetch_assoc();
    $expiradas = $conexao->query("SELECT COUNT(*) as total FROM sessoes_portal WHERE ativo = 1 AND data_expiracao <= NOW()")->fetch_assoc();
    $inativas = $conexao->query("SELECT COUNT(*) as total FROM sessoes_portal WHERE ativo = 0")->fetch_assoc();
    
    echo "<br>";
    echo "<p><strong>Sessões Válidas:</strong> <span style='color:green;'>{$validas['total']}</span></p>";
    echo "<p><strong>Sessões Expiradas:</strong> <span style='color:orange;'>{$expiradas['total']}</span></p>";
    echo "<p><strong>Sessões Inativas:</strong> <span style='color:red;'>{$inativas['total']}</span></p>";
    
} else {
    echo "<p style='color:orange;'>⚠️ Nenhuma sessão encontrada</p>";
}

echo "<hr>";

// ========== TESTAR TOKEN ESPECÍFICO ==========
echo "<h3>3. Testar Token Específico</h3>";

if (isset($_GET['token'])) {
    $token_teste = $_GET['token'];
    
    echo "<p><strong>Token testado:</strong> <code>$token_teste</code></p>";
    
    // Buscar sessão
    $stmt = $conexao->prepare("
        SELECT 
            s.*,
            m.nome as morador_nome,
            CASE 
                WHEN s.data_expiracao > NOW() THEN 1
                ELSE 0
            END as nao_expirada
        FROM sessoes_portal s
        LEFT JOIN moradores m ON s.morador_id = m.id
        WHERE s.token = ?
    ");
    $stmt->bind_param("s", $token_teste);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $sessao = $resultado->fetch_assoc();
        
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><td><strong>Morador</strong></td><td>{$sessao['morador_nome']}</td></tr>";
        echo "<tr><td><strong>Login</strong></td><td>{$sessao['data_login']}</td></tr>";
        echo "<tr><td><strong>Expiração</strong></td><td>{$sessao['data_expiracao']}</td></tr>";
        echo "<tr><td><strong>Ativo</strong></td><td>" . ($sessao['ativo'] == 1 ? '✅ Sim' : '❌ Não') . "</td></tr>";
        echo "<tr><td><strong>Não Expirada</strong></td><td>" . ($sessao['nao_expirada'] == 1 ? '✅ Sim' : '❌ Não') . "</td></tr>";
        echo "</table>";
        
        echo "<br>";
        
        // Verificar se é válida
        if ($sessao['ativo'] == 1 && $sessao['nao_expirada'] == 1) {
            echo "<p style='color:green; font-size:18px; font-weight:bold;'>✅ SESSÃO VÁLIDA!</p>";
            echo "<p>O login deve funcionar com este token.</p>";
        } else {
            echo "<p style='color:red; font-size:18px; font-weight:bold;'>❌ SESSÃO INVÁLIDA!</p>";
            
            if ($sessao['ativo'] == 0) {
                echo "<p>Motivo: Sessão foi desativada (logout ou nova sessão)</p>";
            }
            if ($sessao['nao_expirada'] == 0) {
                echo "<p>Motivo: Sessão expirou (mais de 7 dias)</p>";
            }
        }
        
    } else {
        echo "<p style='color:red;'><strong>❌ Token não encontrado no banco de dados</strong></p>";
    }
    
} else {
    echo "<p>Para testar um token específico, adicione <code>?token=SEU_TOKEN</code> na URL</p>";
    echo "<p>Exemplo: <code>debug_sessao.php?token=abc123...</code></p>";
}

echo "<hr>";

// ========== LIMPAR SESSÕES EXPIRADAS ==========
echo "<h3>4. Limpeza de Sessões</h3>";

if (isset($_POST['limpar'])) {
    $resultado = $conexao->query("UPDATE sessoes_portal SET ativo = 0 WHERE data_expiracao <= NOW()");
    $afetados = $conexao->affected_rows;
    
    echo "<p style='color:green;'><strong>✅ $afetados sessões expiradas foram desativadas</strong></p>";
}

echo "<form method='post'>";
echo "<button type='submit' name='limpar' style='background:orange; color:white; padding:10px 20px; border:none; cursor:pointer; border-radius:5px;'>Limpar Sessões Expiradas</button>";
echo "</form>";

echo "<hr>";

// ========== CRIAR SESSÃO DE TESTE ==========
echo "<h3>5. Criar Sessão de Teste</h3>";

if (isset($_POST['criar_teste'])) {
    $morador_id = 185; // ANDRE SOARES E SILVA
    $token_teste = 'TOKEN_TESTE_' . bin2hex(random_bytes(16));
    $data_expiracao = date('Y-m-d H:i:s', strtotime('+7 days'));
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = 'Debug Script';
    
    $stmt = $conexao->prepare("INSERT INTO sessoes_portal (morador_id, token, ip_address, user_agent, data_expiracao) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $morador_id, $token_teste, $ip_address, $user_agent, $data_expiracao);
    
    if ($stmt->execute()) {
        echo "<p style='color:green;'><strong>✅ Sessão de teste criada!</strong></p>";
        echo "<p><strong>Token:</strong> <code>$token_teste</code></p>";
        echo "<p><strong>Expiração:</strong> $data_expiracao</p>";
        echo "<p><a href='debug_sessao.php?token=$token_teste'>Testar este token</a></p>";
    } else {
        echo "<p style='color:red;'><strong>❌ Erro ao criar sessão:</strong> {$stmt->error}</p>";
    }
}

echo "<form method='post'>";
echo "<button type='submit' name='criar_teste' style='background:blue; color:white; padding:10px 20px; border:none; cursor:pointer; border-radius:5px;'>Criar Sessão de Teste</button>";
echo "</form>";

echo "<hr>";

// ========== RECOMENDAÇÕES ==========
echo "<h3>6. Recomendações</h3>";

if ($diferenca != 0) {
    echo "<div style='background:#fee2e2; padding:15px; border-left:4px solid #ef4444; margin-bottom:10px;'>";
    echo "<p><strong>❌ Problema de Fuso Horário Detectado</strong></p>";
    echo "<p>Adicione no início do arquivo <code>config.php</code>:</p>";
    echo "<pre style='background:#f0f0f0; padding:10px;'>";
    echo "date_default_timezone_set('America/Sao_Paulo');\n";
    echo "\$conexao->query(\"SET time_zone = '-03:00'\");";
    echo "</pre>";
    echo "</div>";
}

if ($expiradas['total'] > 0) {
    echo "<div style='background:#fef3c7; padding:15px; border-left:4px solid #f59e0b; margin-bottom:10px;'>";
    echo "<p><strong>⚠️ Sessões Expiradas Detectadas</strong></p>";
    echo "<p>Execute limpeza periódica ou crie um cron job:</p>";
    echo "<pre style='background:#f0f0f0; padding:10px;'>";
    echo "0 0 * * * php /caminho/para/limpar_sessoes.php";
    echo "</pre>";
    echo "</div>";
}

echo "<div style='background:#dcfce7; padding:15px; border-left:4px solid #22c55e;'>";
echo "<p><strong>✅ Dicas de Segurança</strong></p>";
echo "<ul>";
echo "<li>Limite 1 sessão ativa por morador</li>";
echo "<li>Implemente renovação automática de token</li>";
echo "<li>Registre IP e User-Agent para auditoria</li>";
echo "<li>Force logout após inatividade</li>";
echo "</ul>";
echo "</div>";
