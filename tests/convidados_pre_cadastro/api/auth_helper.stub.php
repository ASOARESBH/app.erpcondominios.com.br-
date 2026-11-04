<?php
// Stub de teste: operador autenticado via cabeçalho X-Test-Operador: "<usuario_id>:<tenant_id>:<nome>"
function verificarAutenticacao($obrigatorio = true, $nivel = 'operador') {
    $h = $_SERVER['HTTP_X_TEST_OPERADOR'] ?? '';
    if ($h === '') { http_response_code(401); echo json_encode(['sucesso'=>false,'mensagem'=>'nao autenticado']); exit; }
    [$id,$tenant,$nome] = explode(':', $h, 3);
    $_SESSION['tenant_id'] = (int)$tenant;
    return ['id'=>(int)$id,'tenant_id'=>(int)$tenant,'nome'=>$nome];
}
function verificarPermissao($nivel) { return true; }
function rbacTabelasDisponiveis($c) { return false; }
function rbacExigir() {}
function rbacAuditar() {}
