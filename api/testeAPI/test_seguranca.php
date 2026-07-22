<?php
/**
 * =====================================================
 * TESTES DE SEGURANÇA - SUITE COMPLETA
 * =====================================================
 * 
 * Testa os 3 passos críticos de segurança:
 * 1. Rate Limiting
 * 2. Autenticação em endpoints
 * 3. JWT
 * 
 * Uso: php test_seguranca.php
 */

// Cores para output
define('GREEN', "\033[92m");
define('RED', "\033[91m");
define('YELLOW', "\033[93m");
define('BLUE', "\033[94m");
define('RESET', "\033[0m");

class TestadorSeguranca {
    private $testes_passados = 0;
    private $testes_falhados = 0;
    private $resultados = [];
    
    public function __construct() {
        // Incluir classes necessárias
        if (file_exists('rate_limiter.php')) {
            require_once 'rate_limiter.php';
        }
        if (file_exists('jwt_handler.php')) {
            require_once 'jwt_handler.php';
        }
    }
    
    /**
     * Executar todos os testes
     */
    public function executarTodosTestes() {
        echo "\n" . BLUE . "╔════════════════════════════════════════════════════════════╗" . RESET . "\n";
        echo BLUE . "║     SUITE DE TESTES - SEGURANÇA DO SISTEMA                   ║" . RESET . "\n";
        echo BLUE . "╚════════════════════════════════════════════════════════════╝" . RESET . "\n\n";
        
        $this->testarRateLimiter();
        $this->testarJWT();
        $this->testarArquivos();
        
        $this->exibirResumo();
    }
    
    /**
     * Testar Rate Limiter
     */
    private function testarRateLimiter() {
        echo YELLOW . "\n[1] TESTANDO RATE LIMITER" . RESET . "\n";
        echo str_repeat("─", 60) . "\n";
        
        if (!class_exists('RateLimiter')) {
            echo RED . "✗ Classe RateLimiter não encontrada" . RESET . "\n";
            $this->testes_falhados++;
            return;
        }
        
        $limiter = new RateLimiter();
        $identifier = "test:192.168.1.1";
        
        // Teste 1: Primeira requisição deve ser permitida
        $teste1 = $limiter->isAllowed($identifier, 3, 300);
        $this->registrarTeste("Primeira requisição permitida", $teste1);
        
        // Teste 2: Segunda requisição deve ser permitida
        $teste2 = $limiter->isAllowed($identifier, 3, 300);
        $this->registrarTeste("Segunda requisição permitida", $teste2);
        
        // Teste 3: Terceira requisição deve ser permitida
        $teste3 = $limiter->isAllowed($identifier, 3, 300);
        $this->registrarTeste("Terceira requisição permitida", $teste3);
        
        // Teste 4: Quarta requisição deve ser NEGADA (limite de 3)
        $teste4 = !$limiter->isAllowed($identifier, 3, 300);
        $this->registrarTeste("Quarta requisição NEGADA (limite excedido)", $teste4);
        
        // Teste 5: Verificar tentativas restantes
        $limiter->reset($identifier);
        $remaining = $limiter->getRemainingAttempts($identifier, 5);
        $teste5 = $remaining === 5;
        $this->registrarTeste("Tentativas restantes após reset: $remaining", $teste5);
        
        // Teste 6: Verificar estatísticas
        $stats = $limiter->getStats();
        $teste6 = isset($stats['backend']);
        $this->registrarTeste("Estatísticas disponíveis (backend: {$stats['backend']})", $teste6);
    }
    
    /**
     * Testar JWT
     */
    private function testarJWT() {
        echo YELLOW . "\n[2] TESTANDO JWT (JSON Web Tokens)" . RESET . "\n";
        echo str_repeat("─", 60) . "\n";
        
        if (!class_exists('JWTHandler')) {
            echo RED . "✗ Classe JWTHandler não encontrada" . RESET . "\n";
            $this->testes_falhados++;
            return;
        }
        
        $jwt = new JWTHandler('test_secret_key_12345');
        
        // Teste 1: Gerar token
        $token = $jwt->generateToken(1, 'João Silva', 'admin');
        $teste1 = !empty($token) && is_string($token);
        $this->registrarTeste("Token gerado com sucesso", $teste1);
        
        // Teste 2: Token tem 3 partes (header.payload.signature)
        $partes = explode('.', $token);
        $teste2 = count($partes) === 3;
        $this->registrarTeste("Token tem formato correto (3 partes)", $teste2);
        
        // Teste 3: Validar token válido
        $payload = $jwt->validateToken($token);
        $teste3 = $payload !== false && isset($payload['usuario_id']);
        $this->registrarTeste("Token válido é aceito", $teste3);
        
        // Teste 4: Verificar dados do payload
        if ($payload) {
            $teste4 = $payload['usuario_id'] === 1 && $payload['usuario_nome'] === 'João Silva';
            $this->registrarTeste("Dados do payload estão corretos", $teste4);
        } else {
            $this->registrarTeste("Dados do payload estão corretos", false);
        }
        
        // Teste 5: Token inválido é rejeitado
        $token_invalido = $token . "xyz";
        $payload_invalido = $jwt->validateToken($token_invalido);
        $teste5 = $payload_invalido === false;
        $this->registrarTeste("Token inválido é rejeitado", $teste5);
        
        // Teste 6: Token com assinatura alterada é rejeitado
        $partes = explode('.', $token);
        $partes[2] = 'assinatura_falsa';
        $token_alterado = implode('.', $partes);
        $payload_alterado = $jwt->validateToken($token_alterado);
        $teste6 = $payload_alterado === false;
        $this->registrarTeste("Token com assinatura alterada é rejeitado", $teste6);
        
        // Teste 7: Gerar refresh token
        $refresh_token = $jwt->generateRefreshToken(1, 'João Silva', 'admin');
        $teste7 = !empty($refresh_token) && $refresh_token !== $token;
        $this->registrarTeste("Refresh token gerado com sucesso", $teste7);
        
        // Teste 8: Renovar token usando refresh token
        $novo_token = $jwt->refreshToken($refresh_token);
        $teste8 = !empty($novo_token) && $novo_token !== $token;
        $this->registrarTeste("Token renovado com sucesso", $teste8);
        
        // Teste 9: Revogar token
        $revogado = $jwt->revokeToken($token);
        $teste9 = $revogado === true;
        $this->registrarTeste("Token revogado com sucesso", $teste9);
        
        // Teste 10: Token revogado é rejeitado
        $payload_revogado = $jwt->validateToken($token);
        $teste10 = $payload_revogado === false;
        $this->registrarTeste("Token revogado é rejeitado", $teste10);
    }
    
    /**
     * Testar Arquivos
     */
    private function testarArquivos() {
        echo YELLOW . "\n[3] VERIFICANDO ARQUIVOS DE SEGURANÇA" . RESET . "\n";
        echo str_repeat("─", 60) . "\n";
        
        $arquivos = [
            'rate_limiter.php' => 'Rate Limiter',
            'jwt_handler.php' => 'JWT Handler',
            'validar_login_com_rate_limit.php' => 'Login com Rate Limit',
            'auth_helper.php' => 'Auth Helper',
            'ENDPOINTS_PROTEGIDOS.md' => 'Documentação de Endpoints'
        ];
        
        foreach ($arquivos as $arquivo => $descricao) {
            $existe = file_exists($arquivo);
            $this->registrarTeste("Arquivo $descricao ($arquivo) existe", $existe);
        }
    }
    
    /**
     * Registrar resultado de teste
     */
    private function registrarTeste($descricao, $resultado) {
        if ($resultado) {
            echo GREEN . "✓" . RESET . " $descricao\n";
            $this->testes_passados++;
        } else {
            echo RED . "✗" . RESET . " $descricao\n";
            $this->testes_falhados++;
        }
        
        $this->resultados[] = [
            'descricao' => $descricao,
            'resultado' => $resultado
        ];
    }
    
    /**
     * Exibir resumo dos testes
     */
    private function exibirResumo() {
        $total = $this->testes_passados + $this->testes_falhados;
        $percentual = $total > 0 ? ($this->testes_passados / $total) * 100 : 0;
        
        echo "\n" . BLUE . "╔════════════════════════════════════════════════════════════╗" . RESET . "\n";
        echo BLUE . "║                    RESUMO DOS TESTES                         ║" . RESET . "\n";
        echo BLUE . "╚════════════════════════════════════════════════════════════╝" . RESET . "\n\n";
        
        echo "Total de testes: $total\n";
        echo GREEN . "Testes passados: {$this->testes_passados}" . RESET . "\n";
        
        if ($this->testes_falhados > 0) {
            echo RED . "Testes falhados: {$this->testes_falhados}" . RESET . "\n";
        }
        
        echo "Taxa de sucesso: " . number_format($percentual, 2) . "%\n\n";
        
        if ($this->testes_falhados === 0) {
            echo GREEN . "✓ TODOS OS TESTES PASSARAM!" . RESET . "\n";
        } else {
            echo RED . "✗ ALGUNS TESTES FALHARAM - Verifique os arquivos acima" . RESET . "\n";
        }
        
        echo "\n";
    }
}

// Executar testes
$testador = new TestadorSeguranca();
$testador->executarTodosTestes();
?>
