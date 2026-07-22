<?php
// =====================================================
// SCRIPT DE DIAGNÓSTICO - MARKETPLACE API
// REMOVER APÓS USO!
// =====================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

$erros = [];
$info  = [];

// 1. Verificar PHP version
$info['php_version'] = phpversion();

// 2. Verificar se config.php existe e carrega
if (!file_exists(__DIR__ . '/config.php')) {
    $erros[] = 'config.php não encontrado';
} else {
    try {
        require_once 'config.php';
        $info['config_ok'] = true;
    } catch (Throwable $e) {
        $erros[] = 'Erro ao carregar config.php: ' . $e->getMessage();
    }
}

// 3. Testar conexão com banco
if (function_exists('conectar_banco')) {
    try {
        $conn = conectar_banco();
        $info['conexao_banco'] = 'OK';

        // 4. Verificar tabelas do marketplace
        $tabelas = ['ramos_atividade','fornecedores','produtos_servicos','pedidos','avaliacoes','historico_status_pedido'];
        foreach ($tabelas as $t) {
            $r = $conn->query("SHOW TABLES LIKE '{$t}'");
            $info['tabela_' . $t] = ($r && $r->num_rows > 0) ? 'existe' : 'NAO EXISTE';
        }

        // 5. Verificar tabela sessoes_portal
        $r = $conn->query("SHOW TABLES LIKE 'sessoes_portal'");
        $info['tabela_sessoes_portal'] = ($r && $r->num_rows > 0) ? 'existe' : 'NAO EXISTE';

        // 6. Contar fornecedores aprovados
        $r = $conn->query("SELECT COUNT(*) as total FROM fornecedores WHERE aprovado=1 AND ativo=1");
        if ($r) {
            $row = $r->fetch_assoc();
            $info['fornecedores_aprovados'] = $row['total'];
        }

        // 7. Contar produtos ativos
        $r = $conn->query("SELECT COUNT(*) as total FROM produtos_servicos WHERE ativo=1");
        if ($r) {
            $row = $r->fetch_assoc();
            $info['produtos_ativos'] = $row['total'];
        }

        // 8. Testar query da vitrine (simplificada)
        $r = $conn->query("SELECT ps.id, ps.nome FROM produtos_servicos ps JOIN fornecedores f ON ps.fornecedor_id = f.id WHERE ps.ativo=1 AND f.ativo=1 AND f.aprovado=1 LIMIT 1");
        if ($r === false) {
            $erros[] = 'Erro na query vitrine: ' . $conn->error;
        } else {
            $info['query_vitrine'] = 'OK (' . $r->num_rows . ' resultado(s))';
        }

        $conn->close();
    } catch (Throwable $e) {
        $erros[] = 'Erro de banco: ' . $e->getMessage();
    }
} else {
    $erros[] = 'Função conectar_banco não encontrada';
}

// 9. Verificar se auth_helper.php existe
if (!file_exists(__DIR__ . '/auth_helper.php')) {
    $erros[] = 'auth_helper.php não encontrado';
} else {
    $info['auth_helper'] = 'existe';
}

// 10. Verificar se session_start causa problema
$info['session_status_antes'] = session_status(); // 0=disabled, 1=none, 2=active

echo json_encode([
    'diagnostico' => true,
    'erros'       => $erros,
    'info'        => $info,
    'timestamp'   => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
