# Guia de PHP

## 1. Padrão Arquitetural
Migração em andamento de Scripts Procedurais para Orientação a Objetos (Classes estendendo `ApiBase`).

## 2. Estrutura de uma API Moderna
```php
require_once 'api_base.php';

class ModuloApi extends ApiBase {
    public function __construct() {
        parent::__construct();
        $this->verificarAcesso(true, 'operador');
    }
    
    public function executar() {
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'listar': $this->listar(); break;
            default: $this->retornarErro('Ação inválida', 400);
        }
    }
    
    private function listar() {
        // Logica
        $this->retornarSucesso('OK', $dados);
    }
}

$api = new ModuloApi();
$api->executar();
```

## 3. Boas Práticas
- **Nunca** use `echo` ou `print_r` para debug. Use `error_log()`.
- Valide todos os inputs (`$_POST`, `$_GET`).
- Use transações (`$conexao->begin_transaction()`) para operações em múltiplas tabelas.
