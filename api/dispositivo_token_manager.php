<?php
/**
 * Gerenciador de Tokens de Dispositivos (Tablets)
 * Autenticação de tablets para validação de QR Code
 */

class DispositivoTokenManager {
    private $conexao;
    
    public function __construct($conexao) {
        $this->conexao = $conexao;
    }
    
    /**
     * Gera token único de 12 caracteres alfanuméricos
     * Formato: A9F3K7L2Q8M4 (fácil de digitar)
     * Exclui: I, O, 0, 1 (para evitar confusão)
     */
    public function gerarToken() {
        $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $tentativas = 0;
        $max_tentativas = 100;
        
        do {
            $token = '';
            for ($i = 0; $i < 12; $i++) {
                $token .= $caracteres[random_int(0, strlen($caracteres) - 1)];
            }
            
            // Verificar se token já existe
            $stmt = $this->conexao->prepare("SELECT COUNT(*) as total FROM dispositivos_tablets WHERE token = ?");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $resultado = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $tentativas++;
            
            if ($resultado['total'] == 0) {
                return $token;
            }
            
        } while ($tentativas < $max_tentativas);
        
        return false;
    }
    
    /**
     * Gera secret de 32 caracteres (contra-chave)
     */
    public function gerarSecret() {
        return bin2hex(random_bytes(16)); // 32 caracteres hex
    }
    
    /**
     * Cadastra novo dispositivo
     */
    public function cadastrarDispositivo($nome, $tipo_dispositivo, $localizacao, $status, $responsavel = null, $observacao = null) {
        $token = $this->gerarToken();
        $secret = $this->gerarSecret();
        
        if (!$token) {
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao gerar token único'
            ];
        }
        
        $stmt = $this->conexao->prepare("
            INSERT INTO dispositivos_tablets 
            (nome, tipo_dispositivo, token, secret, local, status, responsavel, observacao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("ssssssss", $nome, $tipo_dispositivo, $token, $secret, $localizacao, $status, $responsavel, $observacao);
        
        if ($stmt->execute()) {
            $dispositivo_id = $stmt->insert_id;
            $stmt->close();
            
            error_log("[DISPOSITIVO] Novo dispositivo cadastrado: $nome (Token: $token)");
            
            return [
                'sucesso' => true,
                'mensagem' => 'Dispositivo cadastrado com sucesso',
                'dispositivo_id' => $dispositivo_id,
                'token' => $token,
                'secret' => $secret
            ];
        } else {
            error_log("[DISPOSITIVO] Erro ao cadastrar: " . $stmt->error);
            $stmt->close();
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao cadastrar dispositivo'
            ];
        }
    }
    
    /**
     * Valida token do dispositivo
     */
    public function validarDispositivo($token) {
        $stmt = $this->conexao->prepare("
            SELECT 
                id,
                nome,
                token,
                secret,
                status,
                local,
                ultimo_acesso,
                total_validacoes
            FROM dispositivos_tablets
            WHERE token = ?
        ");
        
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $resultado = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$resultado) {
            error_log("[DISPOSITIVO] Token não encontrado: $token");
            return [
                'valido' => false,
                'motivo' => 'token_nao_encontrado',
                'mensagem' => 'Dispositivo não autorizado'
            ];
        }
        
        if ($resultado['status'] !== 'ativo') {
            error_log("[DISPOSITIVO] Dispositivo inativo: {$resultado['nome']}");
            return [
                'valido' => false,
                'motivo' => 'dispositivo_inativo',
                'mensagem' => 'Dispositivo desativado'
            ];
        }
        
        error_log("[DISPOSITIVO] Dispositivo validado: {$resultado['nome']}");
        
        return [
            'valido' => true,
            'dispositivo_id' => $resultado['id'],
            'nome' => $resultado['nome'],
            'local' => $resultado['local'],
            'secret' => $resultado['secret']
        ];
    }
    
    /**
     * Registra validação realizada pelo dispositivo
     */
    public function registrarValidacao($dispositivo_id, $token_qrcode, $acesso_id, $resultado, $motivo_falha = null) {
        $stmt = $this->conexao->prepare("
            INSERT INTO logs_validacoes_dispositivo
            (dispositivo_id, token_qrcode, acesso_id, resultado, motivo_falha, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->bind_param("isissss", $dispositivo_id, $token_qrcode, $acesso_id, $resultado, $motivo_falha, $ip, $user_agent);
        
        if ($stmt->execute()) {
            $stmt->close();
            return ['sucesso' => true];
        } else {
            error_log("[DISPOSITIVO] Erro ao registrar validação: " . $stmt->error);
            $stmt->close();
            return ['sucesso' => false];
        }
    }
    
    /**
     * Atualiza status do dispositivo
     */
    public function atualizarStatus($dispositivo_id, $status) {
        $stmt = $this->conexao->prepare("
            UPDATE dispositivos_tablets 
            SET status = ? 
            WHERE id = ?
        ");
        
        $stmt->bind_param("si", $status, $dispositivo_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            error_log("[DISPOSITIVO] Status atualizado: ID $dispositivo_id -> $status");
            return [
                'sucesso' => true,
                'mensagem' => 'Status atualizado com sucesso'
            ];
        } else {
            error_log("[DISPOSITIVO] Erro ao atualizar status: " . $stmt->error);
            $stmt->close();
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao atualizar status'
            ];
        }
    }
    
    /**
     * Lista todos os dispositivos
     */
    public function listarDispositivos($status = null) {
        if ($status) {
            $stmt = $this->conexao->prepare("
                SELECT 
                    id, nome, tipo_dispositivo, token, status, local, responsavel, observacao,
                    ultimo_acesso, total_validacoes, criado_em
                FROM dispositivos_tablets
                WHERE status = ?
                ORDER BY criado_em DESC
            ");
            $stmt->bind_param("s", $status);
        } else {
            $stmt = $this->conexao->prepare("
                SELECT 
                    id, nome, tipo_dispositivo, token, status, local, responsavel, observacao,
                    ultimo_acesso, total_validacoes, criado_em
                FROM dispositivos_tablets
                ORDER BY criado_em DESC
            ");
        }
        
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        $dispositivos = [];
        while ($row = $resultado->fetch_assoc()) {
            $dispositivos[] = $row;
        }
        
        $stmt->close();
        return $dispositivos;
    }
    
    /**
     * Busca dispositivo por ID
     */
    public function buscarPorId($dispositivo_id) {
        $stmt = $this->conexao->prepare("
            SELECT 
                id, nome, tipo_dispositivo, token, secret, status, local, responsavel, observacao,
                ultimo_acesso, total_validacoes, criado_em
            FROM dispositivos_tablets
            WHERE id = ?
        ");
        
        $stmt->bind_param("i", $dispositivo_id);
        $stmt->execute();
        $resultado = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $resultado;
    }
    
    /**
     * Atualiza dispositivo
     */
    public function atualizarDispositivo($dispositivo_id, $nome, $tipo_dispositivo, $localizacao, $status, $responsavel, $observacao) {
        $stmt = $this->conexao->prepare("
            UPDATE dispositivos_tablets
            SET nome = ?, tipo_dispositivo = ?, local = ?, status = ?, responsavel = ?, observacao = ?
            WHERE id = ?
        ");
        
        $stmt->bind_param("ssssssi", $nome, $tipo_dispositivo, $localizacao, $status, $responsavel, $observacao, $dispositivo_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            error_log("[DISPOSITIVO] Dispositivo atualizado: ID $dispositivo_id");
            return [
                'sucesso' => true,
                'mensagem' => 'Dispositivo atualizado com sucesso'
            ];
        } else {
            error_log("[DISPOSITIVO] Erro ao atualizar: " . $stmt->error);
            $stmt->close();
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao atualizar dispositivo'
            ];
        }
    }
    
    /**
     * Deleta dispositivo
     */
    public function deletarDispositivo($dispositivo_id) {
        $stmt = $this->conexao->prepare("DELETE FROM dispositivos_tablets WHERE id = ?");
        $stmt->bind_param("i", $dispositivo_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            error_log("[DISPOSITIVO] Dispositivo deletado: ID $dispositivo_id");
            return [
                'sucesso' => true,
                'mensagem' => 'Dispositivo deletado com sucesso'
            ];
        } else {
            error_log("[DISPOSITIVO] Erro ao deletar: " . $stmt->error);
            $stmt->close();
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao deletar dispositivo'
            ];
        }
    }
    
    /**
     * Obtém estatísticas de dispositivos
     */
    public function obterEstatisticas() {
        $stmt = $this->conexao->query("SELECT * FROM view_estatisticas_dispositivos");
        $stats = $stmt->fetch_assoc();
        $stmt->close();
        
        return $stats;
    }
    
    /**
     * Obtém histórico de validações de um dispositivo
     */
    public function obterHistorico($dispositivo_id, $limite = 100) {
        $stmt = $this->conexao->prepare("
            SELECT 
                l.*,
                a.qr_code,
                v.nome_completo as visitante_nome
            FROM logs_validacoes_dispositivo l
            LEFT JOIN acessos_visitantes a ON l.acesso_id = a.id
            LEFT JOIN visitantes v ON a.visitante_id = v.id
            WHERE l.dispositivo_id = ?
            ORDER BY l.validado_em DESC
            LIMIT ?
        ");
        
        $stmt->bind_param("ii", $dispositivo_id, $limite);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        $historico = [];
        while ($row = $resultado->fetch_assoc()) {
            $historico[] = $row;
        }
        
        $stmt->close();
        return $historico;
    }
}
?>
