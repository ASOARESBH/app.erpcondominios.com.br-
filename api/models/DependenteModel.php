<?php
/**
 * =====================================================
 * MODEL: DependenteModel
 * =====================================================
 * 
 * Modelo para gerenciar operações de dependentes
 * no banco de dados
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 28/01/2026
 * @version 1.0
 */

class DependenteModel {
    
    private $conexao;
    private $tabela = 'dependentes';
    
    /**
     * Construtor
     * @param object $conexao Conexão com banco de dados
     */
    public function __construct($conexao) {
        $this->conexao = $conexao;
    }
    
    /**
     * Listar dependentes por morador
     * @param int $morador_id ID do morador
     * @param bool $apenas_ativos Se true, retorna apenas ativos
     * @return array Dependentes
     */
    public function listarPorMorador($morador_id, $apenas_ativos = true) {
        $morador_id = (int)$morador_id;
        
        $sql = "SELECT id, morador_id, nome, cpf, parentesco, data_nascimento, 
                email, telefone, celular, observacao, ativo,
                DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro
                FROM {$this->tabela} 
                WHERE morador_id = ?";
        
        if ($apenas_ativos) {
            $sql .= " AND ativo = 1";
        }
        
        $sql .= " ORDER BY nome ASC";
        
        $stmt = $this->conexao->prepare($sql);
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $this->conexao->error);
        }
        
        $stmt->bind_param("i", $morador_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Erro ao executar query: " . $stmt->error);
        }
        
        $resultado = $stmt->get_result();
        $dependentes = array();
        
        if ($resultado && $resultado->num_rows > 0) {
            while ($row = $resultado->fetch_assoc()) {
                $dependentes[] = $row;
            }
        }
        
        $stmt->close();
        return $dependentes;
    }
    
    /**
     * Listar todos os dependentes
     * @return array Todos os dependentes
     */
    public function listarTodos() {
        $sql = "SELECT id, morador_id, nome, cpf, parentesco, data_nascimento, 
                email, telefone, celular, observacao, ativo,
                DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro
                FROM {$this->tabela}
                ORDER BY nome ASC";
        
        $resultado = $this->conexao->query($sql);
        $dependentes = array();
        
        if ($resultado && $resultado->num_rows > 0) {
            while ($row = $resultado->fetch_assoc()) {
                $dependentes[] = $row;
            }
        }
        
        return $dependentes;
    }
    
    /**
     * Obter dependente por ID
     * @param int $id ID do dependente
     * @return array|null Dados do dependente ou null
     */
    public function obterPorId($id) {
        $id = (int)$id;
        
        $sql = "SELECT id, morador_id, nome, cpf, parentesco, data_nascimento, 
                email, telefone, celular, observacao, ativo,
                DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro
                FROM {$this->tabela}
                WHERE id = ?";
        
        $stmt = $this->conexao->prepare($sql);
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $this->conexao->error);
        }
        
        $stmt->bind_param("i", $id);
        
        if (!$stmt->execute()) {
            throw new Exception("Erro ao executar query: " . $stmt->error);
        }
        
        $resultado = $stmt->get_result();
        $dependente = null;
        
        if ($resultado && $resultado->num_rows > 0) {
            $dependente = $resultado->fetch_assoc();
        }
        
        $stmt->close();
        return $dependente;
    }
    
    /**
     * Criar novo dependente
     * @param array $dados Dados do dependente
     * @return int ID do dependente criado
     */
    public function criar($dados) {
        $morador_id = (int)($dados['morador_id'] ?? 0);
        $nome = $this->conexao->real_escape_string($dados['nome'] ?? '');
        $cpf = $this->conexao->real_escape_string($dados['cpf'] ?? '');
        $parentesco = $this->conexao->real_escape_string($dados['parentesco'] ?? '');
        $data_nascimento = $this->conexao->real_escape_string($dados['data_nascimento'] ?? '');
        $email = $this->conexao->real_escape_string($dados['email'] ?? '');
        $telefone = $this->conexao->real_escape_string($dados['telefone'] ?? '');
        $celular = $this->conexao->real_escape_string($dados['celular'] ?? '');
        $observacao = $this->conexao->real_escape_string($dados['observacao'] ?? '');
        
        // Validações
        if ($morador_id <= 0 || empty($nome) || empty($cpf)) {
            throw new Exception("Dados obrigatórios faltando");
        }
        
        // Verificar se CPF já existe
        $stmt = $this->conexao->prepare("SELECT id FROM {$this->tabela} WHERE cpf = ? AND morador_id != ?");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $this->conexao->error);
        }
        $stmt->bind_param("si", $cpf, $morador_id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $stmt->close();
            throw new Exception("CPF já cadastrado para outro dependente");
        }
        $stmt->close();
        
        // Inserir dependente
        $sql = "INSERT INTO {$this->tabela} (morador_id, nome, cpf, parentesco, data_nascimento, email, telefone, celular, observacao, ativo) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        
        $stmt = $this->conexao->prepare($sql);
        if (!$stmt) {
            throw new Exception("Erro ao preparar insert: " . $this->conexao->error);
        }
        
        $stmt->bind_param("issssssss", $morador_id, $nome, $cpf, $parentesco, $data_nascimento, $email, $telefone, $celular, $observacao);
        
        if (!$stmt->execute()) {
            throw new Exception("Erro ao inserir dependente: " . $stmt->error);
        }
        
        $id = $this->conexao->insert_id;
        $stmt->close();
        
        return $id;
    }
    
    /**
     * Atualizar dependente
     * @param int $id ID do dependente
     * @param array $dados Dados a atualizar
     * @return bool True se atualizado com sucesso
     */
    public function atualizar($id, $dados) {
        $id = (int)$id;
        $nome = $this->conexao->real_escape_string($dados['nome'] ?? '');
        $parentesco = $this->conexao->real_escape_string($dados['parentesco'] ?? '');
        $data_nascimento = $this->conexao->real_escape_string($dados['data_nascimento'] ?? '');
        $email = $this->conexao->real_escape_string($dados['email'] ?? '');
        $telefone = $this->conexao->real_escape_string($dados['telefone'] ?? '');
        $celular = $this->conexao->real_escape_string($dados['celular'] ?? '');
        $observacao = $this->conexao->real_escape_string($dados['observacao'] ?? '');
        
        if ($id <= 0 || empty($nome)) {
            throw new Exception("Dados inválidos");
        }
        
        $sql = "UPDATE {$this->tabela} 
                SET nome = ?, parentesco = ?, data_nascimento = ?, email = ?, telefone = ?, celular = ?, observacao = ?
                WHERE id = ?";
        
        $stmt = $this->conexao->prepare($sql);
        if (!$stmt) {
            throw new Exception("Erro ao preparar update: " . $this->conexao->error);
        }
        
        $stmt->bind_param("sssssssi", $nome, $parentesco, $data_nascimento, $email, $telefone, $celular, $observacao, $id);
        
        if (!$stmt->execute()) {
            throw new Exception("Erro ao atualizar dependente: " . $stmt->error);
        }
        
        $stmt->close();
        return true;
    }
    
    /**
     * Deletar dependente
     * @param int $id ID do dependente
     * @return bool True se deletado com sucesso
     */
    public function deletar($id) {
        $id = (int)$id;
        
        if ($id <= 0) {
            throw new Exception("ID inválido");
        }
        
        $sql = "DELETE FROM {$this->tabela} WHERE id = ?";
        
        $stmt = $this->conexao->prepare($sql);
        if (!$stmt) {
            throw new Exception("Erro ao preparar delete: " . $this->conexao->error);
        }
        
        $stmt->bind_param("i", $id);
        
        if (!$stmt->execute()) {
            throw new Exception("Erro ao deletar dependente: " . $stmt->error);
        }
        
        $stmt->close();
        return true;
    }
    
    /**
     * Ativar dependente
     * @param int $id ID do dependente
     * @return bool True se ativado com sucesso
     */
    public function ativar($id) {
        $id = (int)$id;
        
        if ($id <= 0) {
            throw new Exception("ID inválido");
        }
        
        $sql = "UPDATE {$this->tabela} SET ativo = 1 WHERE id = ?";
        
        $stmt = $this->conexao->prepare($sql);
        if (!$stmt) {
            throw new Exception("Erro ao preparar update: " . $this->conexao->error);
        }
        
        $stmt->bind_param("i", $id);
        
        if (!$stmt->execute()) {
            throw new Exception("Erro ao ativar dependente: " . $stmt->error);
        }
        
        $stmt->close();
        return true;
    }
    
    /**
     * Inativar dependente
     * @param int $id ID do dependente
     * @return bool True se inativado com sucesso
     */
    public function inativar($id) {
        $id = (int)$id;
        
        if ($id <= 0) {
            throw new Exception("ID inválido");
        }
        
        $sql = "UPDATE {$this->tabela} SET ativo = 0 WHERE id = ?";
        
        $stmt = $this->conexao->prepare($sql);
        if (!$stmt) {
            throw new Exception("Erro ao preparar update: " . $this->conexao->error);
        }
        
        $stmt->bind_param("i", $id);
        
        if (!$stmt->execute()) {
            throw new Exception("Erro ao inativar dependente: " . $stmt->error);
        }
        
        $stmt->close();
        return true;
    }
}
?>
