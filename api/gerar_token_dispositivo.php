<?php
/**
 * Função para gerar token de dispositivo (12 caracteres alfanuméricos)
 */

function gerarTokenDispositivo($tamanho = 12) {
    $caracteres = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $token = '';
    
    for ($i = 0; $i < $tamanho; $i++) {
        $token .= $caracteres[random_int(0, strlen($caracteres) - 1)];
    }
    
    return $token;
}

function verificarTokenUnico($conexao, $token) {
    $stmt = $conexao->prepare("SELECT COUNT(*) as total FROM dispositivos_console WHERE token_acesso = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $resultado = $stmt->get_result()->fetch_assoc();
    
    return $resultado['total'] == 0;
}

function gerarTokenUnico($conexao, $tamanho = 12) {
    $tentativas = 0;
    $maxTentativas = 10;
    
    do {
        $token = gerarTokenDispositivo($tamanho);
        $tentativas++;
        
        if (verificarTokenUnico($conexao, $token)) {
            return $token;
        }
    } while ($tentativas < $maxTentativas);
    
    // Se não conseguir gerar token único, adicionar timestamp
    return gerarTokenDispositivo($tamanho - 4) . substr(time(), -4);
}
