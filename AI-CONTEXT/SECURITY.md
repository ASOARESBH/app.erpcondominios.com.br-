# Segurança (Security Guide)

## 1. Autenticação e Sessão
- As sessões são gerenciadas pelo PHP (`PHPSESSID`).
- O frontend usa `credentials: 'include'` no `fetch()` para enviar o cookie.
- A API DEVE ter os headers CORS corretos:
  `Access-Control-Allow-Origin: https://asl.erpcondominios.com.br`
  `Access-Control-Allow-Credentials: true`

## 2. Hierarquia de Permissões
Definida no `auth_helper.php`:
1. `visualizador` (Apenas leitura)
2. `operador` (Leitura e criação básica)
3. `gerente` (Edição avançada, relatórios)
4. `admin` (Configurações, exclusão, integrações)

## 3. Proteção de Diretórios
Pastas sensíveis (como `/api/`) devem ter arquivo `.htaccess` bloqueando listagem de diretórios (`Options -Indexes`).
Arquivos de upload de moradores/documentos ficam fora da raiz pública ou protegidos por script PHP que valida a sessão antes de entregar o arquivo.
