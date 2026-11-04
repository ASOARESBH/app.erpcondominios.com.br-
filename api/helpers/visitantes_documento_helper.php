<?php
/**
 * ERP CONDOMÍNIOS — Regras de documento de Visitantes compartilhadas.
 *
 * Usado por:
 *   - api/api_visitantes.php           (painel: cadastro/edição/busca por documento)
 *   - api/api_portal_morador.php       (Portal/App: aba Visitantes)
 *   - api/api_portal_convidados.php    (pré-cadastro de convidado que cadastra visitante)
 *
 * Regra: um CPF de MORADOR ATIVO do condomínio não pode ser cadastrado como visitante
 * (morador entra pelo próprio cadastro/tag, não como visitante). A comparação ignora
 * pontuação e é sempre limitada ao tenant da sessão.
 */

require_once __DIR__ . '/cpf_helper.php';

if (!function_exists('visitante_documento_normalizar')) {
    function visitante_documento_normalizar($tipo, $documento) {
        $tipo = strtoupper(trim((string)$tipo));
        $documento = strtoupper(trim((string)$documento));
        if ($tipo === 'CPF') return preg_replace('/\D/', '', $documento);
        return preg_replace('/[^A-Z0-9]/', '', $documento);
    }
}

if (!function_exists('visitante_cpf_de_morador')) {
    /**
     * Retorna ['id','nome','unidade'] do morador ativo do tenant com este CPF, ou null.
     */
    function visitante_cpf_de_morador(mysqli $conexao, $tenantId, $cpf) {
        $cpf = preg_replace('/\D/', '', (string)$cpf);
        if ((int)$tenantId <= 0 || strlen($cpf) !== 11) return null;
        $stmt = $conexao->prepare(
            "SELECT id, nome, unidade FROM moradores
             WHERE tenant_id = ? AND ativo = 1
               AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cpf, ''), '.', ''), '-', ''), '/', ''), ' ', '') = ?
             LIMIT 1"
        );
        if (!$stmt) return null;
        $tenantId = (int)$tenantId;
        $stmt->bind_param('is', $tenantId, $cpf);
        $stmt->execute();
        $morador = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $morador ?: null;
    }
}

if (!function_exists('visitante_mensagem_cpf_morador')) {
    /**
     * Mensagem padrão. $detalhar=true (painel/portaria) inclui nome e unidade do morador;
     * no Portal/App o dado do outro morador não é exposto.
     */
    function visitante_mensagem_cpf_morador(array $morador, $detalhar) {
        if (!$detalhar) {
            return 'Este CPF pertence a um morador do condomínio e não pode ser cadastrado como visitante.';
        }
        $unidade = trim((string)($morador['unidade'] ?? ''));
        return 'Este CPF pertence ao morador ' . trim((string)$morador['nome'])
            . ($unidade !== '' ? ' (unidade ' . $unidade . ')' : '')
            . ' e não pode ser cadastrado como visitante.';
    }
}
