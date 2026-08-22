<?php
/**
 * Configuração por tenant dos campos obrigatórios do cadastro de visitantes.
 * A fonte do tenant é sempre a sessão autenticada da API consumidora.
 */

if (!function_exists('visitantes_catalogo_campos')) {
    function visitantes_catalogo_campos() {
        return [
            'nome_completo' => ['rotulo' => 'Nome completo', 'descricao' => 'Identificação nominal do visitante.', 'tipo' => 'texto', 'padrao' => 1],
            'tipo_documento' => ['rotulo' => 'Tipo de documento', 'descricao' => 'CPF ou RG informado no cadastro.', 'tipo' => 'selecao', 'padrao' => 1],
            'documento' => ['rotulo' => 'Número do documento', 'descricao' => 'CPF ou RG para identificação e prevenção de duplicidade.', 'tipo' => 'texto', 'padrao' => 1],
            'telefone_contato' => ['rotulo' => 'Telefone de contato', 'descricao' => 'Telefone principal do visitante.', 'tipo' => 'telefone', 'padrao' => 1],
            'celular' => ['rotulo' => 'Celular', 'descricao' => 'Telefone celular complementar.', 'tipo' => 'telefone', 'padrao' => 0],
            'email' => ['rotulo' => 'E-mail', 'descricao' => 'E-mail de contato do visitante.', 'tipo' => 'email', 'padrao' => 0],
            'observacao' => ['rotulo' => 'Observação', 'descricao' => 'Informações adicionais do cadastro.', 'tipo' => 'texto', 'padrao' => 0],
            'foto' => ['rotulo' => 'Foto do visitante', 'descricao' => 'Foto capturada pela câmera ou enviada em arquivo.', 'tipo' => 'anexo', 'padrao' => 0],
            'documento_digitalizado' => ['rotulo' => 'Documento digitalizado', 'descricao' => 'Cópia digitalizada do documento em imagem ou PDF.', 'tipo' => 'anexo', 'padrao' => 0],
            'anexo_evidencia' => ['rotulo' => 'Foto ou documento digitalizado', 'descricao' => 'Exige ao menos um dos dois anexos como evidência de identificação.', 'tipo' => 'regra_anexo', 'padrao' => 1],
        ];
    }
}

if (!function_exists('visitantes_tabela_config_existe')) {
    function visitantes_tabela_config_existe($conexao) {
        static $existe = null;
        if ($existe !== null) return $existe;
        $res = $conexao->query("SHOW TABLES LIKE 'config_visitantes_campos'");
        $existe = ($res instanceof mysqli_result && $res->num_rows > 0);
        if ($res instanceof mysqli_result) $res->free();
        return $existe;
    }
}

if (!function_exists('visitantes_obter_config_campos')) {
    function visitantes_obter_config_campos($conexao, $tenant_id) {
        $catalogo = visitantes_catalogo_campos();
        $config = [];
        foreach ($catalogo as $campo => $meta) {
            $config[$campo] = [
                'campo' => $campo,
                'rotulo' => $meta['rotulo'],
                'descricao' => $meta['descricao'],
                'tipo' => $meta['tipo'],
                'obrigatorio' => (bool)$meta['padrao'],
            ];
        }

        if (!visitantes_tabela_config_existe($conexao)) return $config;

        $stmt = $conexao->prepare('SELECT campo, obrigatorio FROM config_visitantes_campos WHERE tenant_id = ?');
        if (!$stmt) return $config;
        $stmt->bind_param('i', $tenant_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($linha = $res->fetch_assoc()) {
            $campo = (string)$linha['campo'];
            if (isset($config[$campo])) $config[$campo]['obrigatorio'] = ((int)$linha['obrigatorio'] === 1);
        }
        $stmt->close();
        return $config;
    }
}

if (!function_exists('visitantes_validar_campos_configurados')) {
    function visitantes_validar_campos_configurados(array $config, array $dados, $tem_foto, $tem_documento_digitalizado) {
        $mapa = [
            'nome_completo' => 'nome_completo',
            'tipo_documento' => 'tipo_documento',
            'documento' => 'documento',
            'telefone_contato' => 'telefone_contato',
            'celular' => 'celular',
            'email' => 'email',
            'observacao' => 'observacao',
        ];

        foreach ($mapa as $campo => $chave) {
            if (!empty($config[$campo]['obrigatorio']) && trim((string)($dados[$chave] ?? '')) === '') {
                return 'O campo "' . $config[$campo]['rotulo'] . '" é obrigatório para este condomínio.';
            }
        }

        $email = trim((string)($dados['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Informe um e-mail válido.';
        }
        if (!empty($config['foto']['obrigatorio']) && !$tem_foto) {
            return 'A foto do visitante é obrigatória para este condomínio.';
        }
        if (!empty($config['documento_digitalizado']['obrigatorio']) && !$tem_documento_digitalizado) {
            return 'O documento digitalizado é obrigatório para este condomínio.';
        }
        if (!empty($config['anexo_evidencia']['obrigatorio']) && !$tem_foto && !$tem_documento_digitalizado) {
            return 'Anexe ao menos uma foto ou um documento digitalizado para concluir o cadastro.';
        }
        return null;
    }
}
?>
