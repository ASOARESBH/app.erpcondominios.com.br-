<?php
require_once __DIR__ . '/db/DatabaseDialect.php';

/**
 * =====================================================
 * TENANT HELPER — MOTOR DE RESOLUÇÃO DE TENANT
 * =====================================================
 * 
 * Responsável por:
 *   1. Identificar qual tenant (condomínio) está sendo acessado
 *   2. Carregar os dados do tenant na sessão PHP
 *   3. Fornecer funções utilitárias de tenant para todas as APIs
 * 
 * COMO USAR:
 *   require_once 'tenant_helper.php';
 *   $tenant_id = obterTenantId();          // Retorna tenant_id da sessão
 *   $tenant    = obterDadosTenant();       // Retorna array com dados do tenant
 * 
 * ESTRATÉGIA DE RESOLUÇÃO (em ordem de prioridade):
 *   1. Sessão PHP ($_SESSION['tenant_id']) — já autenticado
 *   2. Subdomínio da requisição (ex: serra.erpcondominios.com.br → slug=serra)
 *   3. Parâmetro GET ?tenant=slug (debug/dev do fluxo legado; login PostgreSQL o desabilita)
 * 
 * @version 1.0.0
 * @date 2026-07-22
 */

if (!defined('TENANT_HELPER_LOADED')) {
    define('TENANT_HELPER_LOADED', true);

    /**
     * Valida o host permitido no perfil PostgreSQL e extrai seu slug, quando houver.
     * Hosts-base e subdomínios reservados são confiáveis, mas não selecionam tenant.
     *
     * @return array{trusted: bool, slug: ?string}
     */
    function resolverContextoHostTenantPostgres(): array {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if (!is_string($host) || preg_match('/\A([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?\z/D', $host, $matches) !== 1) {
            return ['trusted' => false, 'slug' => null];
        }
        if (isset($matches[2]) && ((int)$matches[2] < 1 || (int)$matches[2] > 65535)) {
            return ['trusted' => false, 'slug' => null];
        }

        $hostname = strtolower($matches[1]);
        $dnsLabel = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (preg_match('/\A' . $dnsLabel . '(?:\.' . $dnsLabel . ')*\z/D', $hostname) !== 1) {
            return ['trusted' => false, 'slug' => null];
        }

        $trustedDomains = defined('ERP_TRUSTED_TENANT_DOMAINS') ? ERP_TRUSTED_TENANT_DOMAINS : null;
        if (!is_array($trustedDomains) || !array_is_list($trustedDomains)) {
            return ['trusted' => false, 'slug' => null];
        }

        foreach ($trustedDomains as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $domain = strtolower($candidate);
            if ($hostname === $domain) {
                return ['trusted' => true, 'slug' => null];
            }
            $suffix = '.' . $domain;
            if (!str_ends_with($hostname, $suffix)) {
                continue;
            }

            $subdomain = substr($hostname, 0, -strlen($suffix));
            if ($subdomain === '' || str_contains($subdomain, '.')) {
                continue;
            }
            $ignorar = ['www', 'app', 'api'];
            return [
                'trusted' => true,
                'slug' => in_array($subdomain, $ignorar, true) ? null : $subdomain,
            ];
        }

        return ['trusted' => false, 'slug' => null];
    }

    /**
     * Resolve o slug do tenant a partir da URL da requisição.
     * Suporta subdomínio e domínio único, mantendo querystring apenas no fluxo legado.
     *
     * @return string|null Slug do tenant ou null se não identificado
     */
    function resolverTenantSlugDaUrl(bool $allowDebugQuery = true): ?string {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if (!$allowDebugQuery) {
            $contexto = resolverContextoHostTenantPostgres();
            return $contexto['trusted'] ? $contexto['slug'] : null;
        }

        $host = preg_replace('/:\d+$/', '', (string)$host);

        // Modo subdomínio: serra.erpcondominios.com.br → 'serra'
        // Ignora www, app, api, localhost, 127.0.0.1
        $ignorar = ['www', 'app', 'api', 'localhost', '127'];
        $partes = explode('.', $host);
        if (count($partes) >= 3) {
            $sub = strtolower($partes[0]);
            if (!in_array($sub, $ignorar) && preg_match('/^[a-z0-9\-]+$/', $sub)) {
                return $sub;
            }
        }

        // Modo domínio único: parâmetro GET ?tenant=slug (apenas dev/debug)
        if ($allowDebugQuery && !empty($_GET['tenant']) && preg_match('/^[a-z0-9\-]+$/', $_GET['tenant'])) {
            return strtolower(trim($_GET['tenant']));
        }

        return null;
    }

    /**
     * Carrega os dados do tenant pelo slug e retorna o array completo.
     * Usado durante o login para popular a sessão.
     *
     * @param mysqli $conexao Conexão com o banco de dados
     * @param string $slug    Slug do tenant
     * @return array|null     Dados do tenant ou null se não encontrado/inativo
     */
    function carregarTenantPorSlug($conexao, string $slug): ?array {
        if (!\ERP\Database\DatabaseDialect::supports($conexao)) return null;
        $stmt = $conexao->prepare(
            "SELECT id, slug, razao_social, nome_fantasia, cnpj, plano, status,
                    logo_url, email_principal, modulos_habilitados
             FROM tenants
             WHERE slug = ? AND status = 'ativo'
             LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        $tenant = $res->num_rows > 0 ? $res->fetch_assoc() : null;
        $stmt->close();
        return $tenant;
    }

    /**
     * Carrega os dados do tenant pelo ID.
     * Usado para revalidar a sessão em cada requisição.
     *
     * @param mysqli $conexao  Conexão com o banco de dados
     * @param int    $tenant_id ID do tenant
     * @return array|null       Dados do tenant ou null se não encontrado/inativo
     */
    function carregarTenantPorId($conexao, int $tenant_id): ?array {
        if (!\ERP\Database\DatabaseDialect::supports($conexao)) return null;
        $stmt = $conexao->prepare(
            "SELECT id, slug, razao_social, nome_fantasia, cnpj, plano, status,
                    logo_url, email_principal, modulos_habilitados
             FROM tenants
             WHERE id = ? AND status = 'ativo'
             LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $tenant = $res->num_rows > 0 ? $res->fetch_assoc() : null;
        $stmt->close();
        return $tenant;
    }

    function usuarioTemAcessoTenantAtivo($conexao, int $usuario_id, int $tenant_id): bool {
        if (!\ERP\Database\DatabaseDialect::supports($conexao) || $usuario_id < 0 || $tenant_id <= 0) {
            return false;
        }
        $stmt = $conexao->prepare(
            "SELECT 1 AS autorizado
             FROM usuarios u
             INNER JOIN tenants t ON t.id = ? AND t.status = 'ativo'
             LEFT JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = t.id AND ut.ativo = 1
             WHERE u.id = ? AND u.ativo = 1
               AND (LOWER(COALESCE(u.permissao, '')) = 'super_admin' OR ut.usuario_id IS NOT NULL)
             LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('Não foi possível validar o acesso ao tenant.');
        $stmt->bind_param('ii', $tenant_id, $usuario_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Não foi possível validar o acesso ao tenant.');
        }
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            throw new RuntimeException('Não foi possível validar o acesso ao tenant.');
        }
        $authorized = $result->fetch_assoc() !== null;
        if (method_exists($result, 'free')) $result->free();
        $stmt->close();
        return $authorized;
    }

    function erpPermissaoLoginEfetiva(string $permissaoGlobal, ?string $permissaoLocal): string {
        $global = strtolower(trim($permissaoGlobal));
        if ($global === 'super_admin') return 'super_admin';
        $hierarquia = ['visualizador' => 1, 'operador' => 2, 'gerente' => 3, 'admin' => 4];
        $nivelGlobal = $hierarquia[$global] ?? 0;
        $local = strtolower(trim((string)$permissaoLocal));
        $nivelLocal = $hierarquia[$local] ?? 0;
        return $nivelLocal > $nivelGlobal ? $local : $global;
    }

    /**
     * Retorna o tenant_id da sessão atual.
     * Se não houver tenant na sessão, retorna null.
     *
     * @return int|null
     */
    function obterTenantId(): ?int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
    }

    /**
     * Retorna o tenant_id da sessão atual.
     * Se não houver tenant, encerra a requisição com erro 403.
     * Usar em APIs que EXIGEM contexto de tenant.
     *
     * @return int
     */
    function exigirTenantId(): int {
        $tenant_id = obterTenantId();
        if (!$tenant_id) {
            http_response_code(403);
            echo json_encode([
                'sucesso'  => false,
                'mensagem' => 'Contexto de condomínio não identificado. Faça login novamente.',
                'codigo'   => 'TENANT_REQUIRED'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $tenant_id;
    }

    /**
     * Retorna os dados completos do tenant da sessão atual.
     *
     * @return array|null
     */
    function obterDadosTenant(): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['tenant_id'])) return null;
        return [
            'id'           => $_SESSION['tenant_id'],
            'slug'         => $_SESSION['tenant_slug']         ?? '',
            'nome_fantasia'=> $_SESSION['tenant_nome']         ?? '',
            'razao_social' => $_SESSION['tenant_razao_social'] ?? '',
            'plano'        => $_SESSION['tenant_plano']        ?? 'basico',
            'logo_url'     => $_SESSION['tenant_logo_url']     ?? null,
        ];
    }

    /**
     * Injeta os dados do tenant na sessão PHP.
     * Chamado logo após a autenticação bem-sucedida.
     *
     * @param array $tenant Array retornado por carregarTenantPorSlug() ou carregarTenantPorId()
     */
    function injetarTenantNaSessao(array $tenant): void {
        $_SESSION['tenant_id']           = (int)$tenant['id'];
        $_SESSION['tenant_slug']         = $tenant['slug'];
        $_SESSION['tenant_nome']         = $tenant['nome_fantasia'] ?? $tenant['razao_social'];
        $_SESSION['tenant_razao_social'] = $tenant['razao_social'];
        $_SESSION['tenant_plano']        = $tenant['plano'] ?? 'basico';
        $_SESSION['tenant_logo_url']     = $tenant['logo_url'] ?? null;
    }

    /**
     * Verifica se o módulo solicitado está habilitado para o tenant atual.
     *
     * @param string $modulo_chave Chave do módulo (ex: 'financeiro', 'rh')
     * @return bool
     */
    function moduloHabilitado(string $modulo_chave): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();

        // Se não há lista de módulos, todos são permitidos (compatibilidade)
        $modulos_json = $_SESSION['tenant_modulos'] ?? null;
        if (empty($modulos_json)) return true;

        $modulos = is_array($modulos_json) ? $modulos_json : json_decode($modulos_json, true);
        if (!is_array($modulos)) return true;

        return in_array($modulo_chave, $modulos);
    }
}
?>
