<?php
// =====================================================
// EXEMPLO DE API USANDO API_BASE
// =====================================================

// Incluir base
require_once 'api_base.php';

// Criar classe da API
class ExemploApi extends ApiBase {
    
    /**
     * Listar registros
     */
    public function listar() {
        try {
            // Buscar todos os registros
            $query = "SELECT * FROM tabela ORDER BY id DESC";
            $registros = $this->buscarTodos($query);
            
            // Retornar sucesso
            $this->retornarSucesso('Registros listados com sucesso', [
                'total' => count($registros),
                'registros' => $registros
            ]);
            
        } catch (Exception $e) {
            error_log('Erro ao listar: ' . $e->getMessage());
            $this->retornarErro('Erro ao listar registros');
        }
    }
    
    /**
     * Buscar por ID
     */
    public function buscar($id) {
        try {
            // Validar ID
            if (empty($id) || !is_numeric($id)) {
                $this->retornarErro('ID inválido', 400);
            }
            
            // Buscar registro
            $query = "SELECT * FROM tabela WHERE id = ? LIMIT 1";
            $registro = $this->buscarUm($query, [$id], 'i');
            
            if (!$registro) {
                $this->retornarErro('Registro não encontrado', 404);
            }
            
            // Retornar sucesso
            $this->retornarSucesso('Registro encontrado', $registro);
            
        } catch (Exception $e) {
            error_log('Erro ao buscar: ' . $e->getMessage());
            $this->retornarErro('Erro ao buscar registro');
        }
    }
    
    /**
     * Criar novo registro
     */
    public function criar($dados) {
        try {
            // Validar campos obrigatórios
            $this->validarCampos($dados, ['campo1', 'campo2']);
            
            // Verificar permissão (opcional)
            // $this->verificarPermissao('admin');
            
            // Inserir no banco
            $query = "INSERT INTO tabela (campo1, campo2, usuario_id, data_criacao) VALUES (?, ?, ?, NOW())";
            $insert_id = $this->inserir($query, [
                $dados['campo1'],
                $dados['campo2'],
                $this->getUsuarioId()
            ], 'ssi');
            
            // Registrar log
            $this->registrarLog('criar_registro', "Registro criado: ID {$insert_id}");
            
            // Retornar sucesso
            $this->retornarSucesso('Registro criado com sucesso', [
                'id' => $insert_id
            ], 201);
            
        } catch (Exception $e) {
            error_log('Erro ao criar: ' . $e->getMessage());
            $this->retornarErro('Erro ao criar registro');
        }
    }
    
    /**
     * Atualizar registro
     */
    public function atualizar($id, $dados) {
        try {
            // Validar ID
            if (empty($id) || !is_numeric($id)) {
                $this->retornarErro('ID inválido', 400);
            }
            
            // Validar campos obrigatórios
            $this->validarCampos($dados, ['campo1', 'campo2']);
            
            // Verificar se registro existe
            $registro_existe = $this->buscarUm("SELECT id FROM tabela WHERE id = ?", [$id], 'i');
            if (!$registro_existe) {
                $this->retornarErro('Registro não encontrado', 404);
            }
            
            // Atualizar no banco
            $query = "UPDATE tabela SET campo1 = ?, campo2 = ?, data_atualizacao = NOW() WHERE id = ?";
            $this->atualizar($query, [
                $dados['campo1'],
                $dados['campo2'],
                $id
            ], 'ssi');
            
            // Registrar log
            $this->registrarLog('atualizar_registro', "Registro atualizado: ID {$id}");
            
            // Retornar sucesso
            $this->retornarSucesso('Registro atualizado com sucesso');
            
        } catch (Exception $e) {
            error_log('Erro ao atualizar: ' . $e->getMessage());
            $this->retornarErro('Erro ao atualizar registro');
        }
    }
    
    /**
     * Deletar registro
     */
    public function deletar($id) {
        try {
            // Validar ID
            if (empty($id) || !is_numeric($id)) {
                $this->retornarErro('ID inválido', 400);
            }
            
            // Verificar permissão
            $this->verificarPermissao('admin');
            
            // Verificar se registro existe
            $registro_existe = $this->buscarUm("SELECT id FROM tabela WHERE id = ?", [$id], 'i');
            if (!$registro_existe) {
                $this->retornarErro('Registro não encontrado', 404);
            }
            
            // Deletar do banco
            $query = "DELETE FROM tabela WHERE id = ?";
            $this->deletar($query, [$id], 'i');
            
            // Registrar log
            $this->registrarLog('deletar_registro', "Registro deletado: ID {$id}");
            
            // Retornar sucesso
            $this->retornarSucesso('Registro deletado com sucesso');
            
        } catch (Exception $e) {
            error_log('Erro ao deletar: ' . $e->getMessage());
            $this->retornarErro('Erro ao deletar registro');
        }
    }
}

// Processar requisição
$api = new ExemploApi(); // true = requer autenticação (padrão)

$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

switch ($metodo) {
    case 'GET':
        if ($id) {
            $api->buscar($id);
        } else {
            $api->listar();
        }
        break;
        
    case 'POST':
        $dados = $_POST;
        $api->criar($dados);
        break;
        
    case 'PUT':
        parse_str(file_get_contents("php://input"), $dados);
        if ($id) {
            $api->atualizar($id, $dados);
        } else {
            $api->retornarErro('ID não fornecido', 400);
        }
        break;
        
    case 'DELETE':
        if ($id) {
            $api->deletar($id);
        } else {
            $api->retornarErro('ID não fornecido', 400);
        }
        break;
        
    default:
        $api->retornarErro('Método não suportado', 405);
}
?>
