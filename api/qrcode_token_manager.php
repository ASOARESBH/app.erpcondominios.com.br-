<?php
/**
 * Gerenciador de Tokens para QR Code
 * Sistema seguro de geração, validação e invalidação de tokens
 */

class QRCodeTokenManager {
    
    private $conexao;
    
    public function __construct($conexao) {
        $this->conexao = $conexao;
    }
    
    /**
     * Gera token único e seguro
     */
    public function gerarToken($acesso_id, $validade_horas = 24) {
        // Gerar token aleatório seguro (32 caracteres)
        $token = bin2hex(random_bytes(16));
        
        // Calcular data/hora de expiração
        $expira_em = date('Y-m-d H:i:s', strtotime("+{$validade_horas} hours"));
        
        // Salvar token no banco
        $stmt = $this->conexao->prepare("
            INSERT INTO qrcode_tokens 
            (acesso_id, token, expira_em, usado, criado_em)
            VALUES (?, ?, ?, 0, NOW())
        ");
        
        $stmt->bind_param("iss", $acesso_id, $token, $expira_em);
        
        if ($stmt->execute()) {
            $token_id = $stmt->insert_id;
            $stmt->close();
            
            return [
                'sucesso' => true,
                'token_id' => $token_id,
                'token' => $token,
                'expira_em' => $expira_em,
                'validade_horas' => $validade_horas
            ];
        } else {
            error_log("[TOKEN] Erro ao salvar token: " . $stmt->error);
            return ['sucesso' => false, 'mensagem' => 'Erro ao gerar token'];
        }
    }
    
    /**
     * Valida token e retorna dados do acesso
     */
    public function validarToken($token) {
        // Buscar token no banco
        $stmt = $this->conexao->prepare("
            SELECT 
                t.id as token_id,
                t.acesso_id,
                t.expira_em,
                t.usado,
                t.usado_em,
                a.visitante_id,
                a.tipo_acesso,
                a.data_inicial,
                a.data_final,
                a.unidade_destino,
                a.morador_responsavel_id,
                v.nome_completo,
                v.documento,
                v.foto
            FROM qrcode_tokens t
            INNER JOIN acessos_visitantes a ON t.acesso_id = a.id
            INNER JOIN visitantes v ON a.visitante_id = v.id
            WHERE t.token = ?
        ");
        
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $resultado = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$resultado) {
            return [
                'valido' => false,
                'motivo' => 'token_invalido',
                'mensagem' => 'Token não encontrado'
            ];
        }
        
        // Verificar se já foi usado
        if ($resultado['usado'] == 1) {
            return [
                'valido' => false,
                'motivo' => 'token_usado',
                'mensagem' => 'Token já foi utilizado',
                'usado_em' => $resultado['usado_em']
            ];
        }
        
        // Verificar se expirou
        $agora = date('Y-m-d H:i:s');
        if ($agora > $resultado['expira_em']) {
            return [
                'valido' => false,
                'motivo' => 'token_expirado',
                'mensagem' => 'Token expirado',
                'expirou_em' => $resultado['expira_em']
            ];
        }
        
        // Verificar se está dentro do período de validade do acesso
        $data_atual = date('Y-m-d');
        if ($data_atual < $resultado['data_inicial'] || $data_atual > $resultado['data_final']) {
            return [
                'valido' => false,
                'motivo' => 'fora_periodo',
                'mensagem' => 'Acesso fora do período de validade',
                'periodo' => $resultado['data_inicial'] . ' a ' . $resultado['data_final']
            ];
        }
        
        // Token válido!
        return [
            'valido' => true,
            'token_id' => $resultado['token_id'],
            'acesso_id' => $resultado['acesso_id'],
            'visitante' => [
                'id' => $resultado['visitante_id'],
                'nome' => $resultado['nome_completo'],
                'documento' => $resultado['documento'],
                'foto' => $resultado['foto']
            ],
            'acesso' => [
                'tipo' => $resultado['tipo_acesso'],
                'data_inicial' => $resultado['data_inicial'],
                'data_final' => $resultado['data_final'],
                'unidade_destino' => $resultado['unidade_destino'],
                'morador_responsavel_id' => $resultado['morador_responsavel_id']
            ],
            'expira_em' => $resultado['expira_em']
        ];
    }
    
    /**
     * Marca token como usado (uso único)
     */
    public function marcarComoUsado($token) {
        $stmt = $this->conexao->prepare("
            UPDATE qrcode_tokens 
            SET usado = 1, usado_em = NOW()
            WHERE token = ? AND usado = 0
        ");
        
        $stmt->bind_param("s", $token);
        $sucesso = $stmt->execute();
        $linhas_afetadas = $stmt->affected_rows;
        $stmt->close();
        
        if ($sucesso && $linhas_afetadas > 0) {
            // Registrar uso no log de acessos
            $this->registrarUsoNoLog($token);
            
            return ['sucesso' => true, 'mensagem' => 'Token marcado como usado'];
        } else {
            return ['sucesso' => false, 'mensagem' => 'Token já foi usado ou não existe'];
        }
    }
    
    /**
     * Registra uso do token no log de acessos
     */
    private function registrarUsoNoLog($token) {
        $stmt = $this->conexao->prepare("
            INSERT INTO logs_acesso_qrcode 
            (token, acesso_id, usado_em, ip_address, user_agent)
            SELECT 
                token, 
                acesso_id, 
                NOW(), 
                ?, 
                ?
            FROM qrcode_tokens 
            WHERE token = ?
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->bind_param("sss", $ip, $user_agent, $token);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Invalida token manualmente
     */
    public function invalidarToken($token) {
        $stmt = $this->conexao->prepare("
            UPDATE qrcode_tokens 
            SET usado = 1, usado_em = NOW(), invalidado_manualmente = 1
            WHERE token = ?
        ");
        
        $stmt->bind_param("s", $token);
        $sucesso = $stmt->execute();
        $stmt->close();
        
        return ['sucesso' => $sucesso];
    }
    
    /**
     * Limpa tokens expirados (manutenção)
     */
    public function limparTokensExpirados() {
        $stmt = $this->conexao->prepare("
            DELETE FROM qrcode_tokens 
            WHERE expira_em < NOW() 
            AND DATE(expira_em) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        
        $stmt->execute();
        $linhas_deletadas = $stmt->affected_rows;
        $stmt->close();
        
        return [
            'sucesso' => true,
            'tokens_removidos' => $linhas_deletadas,
            'mensagem' => "Removidos {$linhas_deletadas} tokens expirados há mais de 30 dias"
        ];
    }
    
    /**
     * Gera dados para QR Code (JSON)
     */
    public function gerarDadosQRCode($token, $acesso_id) {
        // Buscar dados do acesso
        $stmt = $this->conexao->prepare("
            SELECT 
                a.qr_code,
                a.tipo_acesso,
                a.data_inicial,
                a.data_final,
                v.nome_completo,
                v.documento
            FROM acessos_visitantes a
            INNER JOIN visitantes v ON a.visitante_id = v.id
            WHERE a.id = ?
        ");
        
        $stmt->bind_param("i", $acesso_id);
        $stmt->execute();
        $acesso = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$acesso) {
            return null;
        }
        
        // Montar dados do QR Code
        $dados = [
            'token' => $token,
            'codigo' => $acesso['qr_code'],
            'visitante' => $acesso['nome_completo'],
            'documento' => $acesso['documento'],
            'tipo_acesso' => $acesso['tipo_acesso'],
            'valido_de' => $acesso['data_inicial'],
            'valido_ate' => $acesso['data_final'],
            'timestamp' => time()
        ];
        
        return json_encode($dados);
    }
    
    /**
     * Estatísticas de tokens
     */
    public function obterEstatisticas() {
        $stats = [];
        
        // Total de tokens
        $result = $this->conexao->query("SELECT COUNT(*) as total FROM qrcode_tokens");
        $stats['total_tokens'] = $result->fetch_assoc()['total'];
        
        // Tokens ativos (não usados e não expirados)
        $result = $this->conexao->query("
            SELECT COUNT(*) as total 
            FROM qrcode_tokens 
            WHERE usado = 0 AND expira_em > NOW()
        ");
        $stats['tokens_ativos'] = $result->fetch_assoc()['total'];
        
        // Tokens usados
        $result = $this->conexao->query("SELECT COUNT(*) as total FROM qrcode_tokens WHERE usado = 1");
        $stats['tokens_usados'] = $result->fetch_assoc()['total'];
        
        // Tokens expirados
        $result = $this->conexao->query("
            SELECT COUNT(*) as total 
            FROM qrcode_tokens 
            WHERE usado = 0 AND expira_em < NOW()
        ");
        $stats['tokens_expirados'] = $result->fetch_assoc()['total'];
        
        // Tokens usados hoje
        $result = $this->conexao->query("
            SELECT COUNT(*) as total 
            FROM qrcode_tokens 
            WHERE usado = 1 AND DATE(usado_em) = CURDATE()
        ");
        $stats['tokens_usados_hoje'] = $result->fetch_assoc()['total'];
        
        return $stats;
    }
}
?>
