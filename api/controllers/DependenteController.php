<?php
/**
 * =====================================================
 * CONTROLLER: DependenteController
 * =====================================================
 * 
 * Responsável por gerenciar requisições relacionadas
 * a dependentes
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 25/01/2026
 * @version 1.0
 */

require_once __DIR__ . '/../models/DependenteModel.php';

class DependenteController {
    
    private $model;
    private $conexao;
    
    /**
     * Construtor
     * @param object $conexao Conexão com banco de dados
     */
    public function __construct($conexao) {
        $this->conexao = $conexao;
        $this->model = new DependenteModel($conexao);
    }
    
    /**
     * Listar dependentes de um morador
     * @param int $morador_id ID do morador
     * @param bool $apenas_ativos Se true, retorna apenas ativos
     * @return array Resposta com dependentes
     */
    public function listar($morador_id, $apenas_ativos = true) {
        try {
            $morador_id = (int)$morador_id;
            
            if ($morador_id <= 0) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'ID do morador inválido',
                    'dados' => null
                ];
            }
            
            $dependentes = $this->model->listarPorMorador($morador_id, $apenas_ativos);
            
            return [
                'sucesso' => true,
                'mensagem' => 'Dependentes listados com sucesso',
                'dados' => $dependentes,
                'total' => count($dependentes)
            ];
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao listar dependentes: ' . $e->getMessage(),
                'dados' => null
            ];
        }
    }
    
    /**
     * Obter dependente por ID
     * @param int $id ID do dependente
     * @return array Resposta com dados do dependente
     */
    public function obter($id) {
        try {
            $id = (int)$id;
            
            if ($id <= 0) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'ID do dependente inválido',
                    'dados' => null
                ];
            }
            
            $dependente = $this->model->obterPorId($id);
            
            if (!$dependente) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'Dependente não encontrado',
                    'dados' => null
                ];
            }
            
            return [
                'sucesso' => true,
                'mensagem' => 'Dependente obtido com sucesso',
                'dados' => $dependente
            ];
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao obter dependente: ' . $e->getMessage(),
                'dados' => null
            ];
        }
    }
    
    /**
     * Criar novo dependente
     * @param array $dados Dados do dependente
     * @return array Resposta da operação
     */
    public function criar($dados) {
        try {
            // ====================================================
            // MAPEAMENTO: camelCase → snake_case
            // ====================================================
            // Frontend envia em camelCase, Backend espera snake_case
            $mapeamento = [
                'moradorId' => 'morador_id',
                'nomeCompleto' => 'nome_completo',
                'dataNascimento' => 'data_nascimento'
            ];
            
            foreach ($mapeamento as $camel => $snake) {
                if (isset($dados[$camel]) && !isset($dados[$snake])) {
                    $dados[$snake] = $dados[$camel];
                    unset($dados[$camel]);
                }
            }
            
            // ====================================================
            // LIMPEZA DE DOCUMENTOS: Remover máscaras
            // ====================================================
            // CPF: 123.456.789-10 → 12345678910
            if (isset($dados['cpf'])) {
                $dados['cpf'] = preg_replace('/[^0-9]/', '', $dados['cpf']);
            }
            
            // Telefone: (31) 3333-4444 → 3133334444
            if (isset($dados['telefone'])) {
                $dados['telefone'] = preg_replace('/[^0-9]/', '', $dados['telefone']);
            }
            
            // Celular: (31) 99999-8888 → 31999998888
            if (isset($dados['celular'])) {
                $dados['celular'] = preg_replace('/[^0-9]/', '', $dados['celular']);
            }
            
            // Validações
            if (empty($dados['morador_id'])) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'ID do morador é obrigatório'
                ];
            }
            
            if (empty($dados['nome_completo'])) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'Nome completo é obrigatório'
                ];
            }
            
            if (empty($dados['cpf'])) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'CPF é obrigatório'
                ];
            }
            
            // Validar formato do CPF
            if (!$this->validarCPF($dados['cpf'])) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'CPF inválido'
                ];
            }
            
            // Validar CPF como visitante
            $validacao_visitante = $this->validarConflitoDependenteVisitante($dados['cpf']);
            if (!$validacao_visitante['sucesso']) {
                return $validacao_visitante;
            }
            
            // Criar dependente
            $resultado = $this->model->criar($dados);
            
            return $resultado;
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao criar dependente: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Atualizar dependente
     * @param int $id ID do dependente
     * @param array $dados Dados a atualizar
     * @return array Resposta da operação
     */
    public function atualizar($id, $dados) {
        try {
            $id = (int)$id;
            
            if ($id <= 0) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'ID do dependente inválido'
                ];
            }
            
            // ====================================================
            // MAPEAMENTO: camelCase → snake_case
            // ====================================================
            $mapeamento = [
                'moradorId' => 'morador_id',
                'nomeCompleto' => 'nome_completo',
                'dataNascimento' => 'data_nascimento'
            ];
            
            foreach ($mapeamento as $camel => $snake) {
                if (isset($dados[$camel]) && !isset($dados[$snake])) {
                    $dados[$snake] = $dados[$camel];
                    unset($dados[$camel]);
                }
            }
            
            // ====================================================
            // LIMPEZA DE DOCUMENTOS: Remover máscaras
            // ====================================================
            if (isset($dados['cpf'])) {
                $dados['cpf'] = preg_replace('/[^0-9]/', '', $dados['cpf']);
            }
            
            if (isset($dados['telefone'])) {
                $dados['telefone'] = preg_replace('/[^0-9]/', '', $dados['telefone']);
            }
            
            if (isset($dados['celular'])) {
                $dados['celular'] = preg_replace('/[^0-9]/', '', $dados['celular']);
            }
            
            // Validar CPF se foi alterado
            if (isset($dados['cpf']) && !empty($dados['cpf'])) {
                if (!$this->validarCPF($dados['cpf'])) {
                    return [
                        'sucesso' => false,
                        'mensagem' => 'CPF inválido'
                    ];
                }
            }
            
            // Atualizar dependente
            $resultado = $this->model->atualizar($id, $dados);
            
            return $resultado;
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao atualizar dependente: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Ativar dependente
     * @param int $id ID do dependente
     * @return array Resposta da operação
     */
    public function ativar($id) {
        try {
            $id = (int)$id;
            
            if ($id <= 0) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'ID do dependente inválido'
                ];
            }
            
            $resultado = $this->model->ativarInativar($id, true);
            
            // Se ativado com sucesso, remover de visitantes se houver
            if ($resultado['sucesso']) {
                $this->removerDeVisitantes($id);
            }
            
            return $resultado;
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao ativar dependente: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Inativar dependente
     * @param int $id ID do dependente
     * @return array Resposta da operação
     */
    public function inativar($id) {
        try {
            $id = (int)$id;
            
            if ($id <= 0) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'ID do dependente inválido'
                ];
            }
            
            $resultado = $this->model->ativarInativar($id, false);
            
            return $resultado;
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao inativar dependente: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Deletar dependente
     * @param int $id ID do dependente
     * @return array Resposta da operação
     */
    public function deletar($id) {
        try {
            $id = (int)$id;
            
            if ($id <= 0) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'ID do dependente inválido'
                ];
            }
            
            $resultado = $this->model->deletar($id);
            
            return $resultado;
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao deletar dependente: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Listar dependentes com veículos
     * @param int $morador_id ID do morador
     * @return array Resposta com dependentes e veículos
     */
    public function listarComVeiculos($morador_id) {
        try {
            $morador_id = (int)$morador_id;
            
            if ($morador_id <= 0) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'ID do morador inválido',
                    'dados' => null
                ];
            }
            
            $dependentes = $this->model->listarComVeiculos($morador_id);
            
            return [
                'sucesso' => true,
                'mensagem' => 'Dependentes com veículos listados com sucesso',
                'dados' => $dependentes,
                'total' => count($dependentes)
            ];
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'mensagem' => 'Erro ao listar dependentes com veículos: ' . $e->getMessage(),
                'dados' => null
            ];
        }
    }
    
    /**
     * Validar CPF
     * @param string $cpf CPF a validar
     * @return bool True se válido
     */
    private function validarCPF($cpf) {
        // Remove caracteres especiais
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        
        // Verifica se tem 11 dígitos
        if (strlen($cpf) != 11) {
            return false;
        }
        
        // Verifica se não é uma sequência de números iguais
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        
        // Calcula primeiro dígito verificador
        $soma = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma += $cpf[$i] * (10 - $i);
        }
        $resto = $soma % 11;
        $digito1 = ($resto < 2) ? 0 : 11 - $resto;
        
        if ($cpf[9] != $digito1) {
            return false;
        }
        
        // Calcula segundo dígito verificador
        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += $cpf[$i] * (11 - $i);
        }
        $resto = $soma % 11;
        $digito2 = ($resto < 2) ? 0 : 11 - $resto;
        
        if ($cpf[10] != $digito2) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validar conflito entre dependente e visitante
     * @param string $cpf CPF a validar
     * @return array Resposta da validação
     */
    private function validarConflitoDependenteVisitante($cpf) {
        $sql = "SELECT id, nome_completo FROM visitantes WHERE documento = ? AND ativo = 1";
        
        $stmt = $this->conexao->prepare($sql);
        $stmt->bind_param("s", $cpf);
        $stmt->execute();
        
        $resultado = $stmt->get_result();
        
        if ($resultado->num_rows > 0) {
            $visitante = $resultado->fetch_assoc();
            $stmt->close();
            
            return [
                'sucesso' => false,
                'mensagem' => 'Este CPF já está cadastrado como visitante (' . $visitante['nome_completo'] . '). Remova o cadastro de visitante antes de cadastrar como dependente.',
                'visitante_id' => $visitante['id']
            ];
        }
        
        $stmt->close();
        
        return [
            'sucesso' => true,
            'mensagem' => 'CPF validado com sucesso'
        ];
    }
    
    /**
     * Remover dependente de visitantes
     * @param int $dependente_id ID do dependente
     * @return bool True se removido
     */
    private function removerDeVisitantes($dependente_id) {
        $dependente = $this->model->obterPorId($dependente_id);
        
        if (!$dependente) {
            return false;
        }
        
        $sql = "UPDATE visitantes SET ativo = 0 WHERE documento = ? AND dependente_id IS NULL";
        
        $stmt = $this->conexao->prepare($sql);
        $stmt->bind_param("s", $dependente['cpf']);
        $resultado = $stmt->execute();
        
        $stmt->close();
        
        return $resultado;
    }
}
?>
