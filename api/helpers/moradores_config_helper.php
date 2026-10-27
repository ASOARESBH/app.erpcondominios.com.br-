<?php
/**
 * Configuração por tenant dos cadastros de moradores, dependentes e veículos.
 * O tenant é sempre resolvido pela sessão da API consumidora.
 */

if (!function_exists('moradores_catalogo_campos')) {
    function moradores_catalogo_campos() {
        return [
            'moradores' => [
                'rotulo' => 'Moradores',
                'campos' => [
                    'nome' => ['rotulo' => 'Nome completo', 'descricao' => 'Identificação nominal do morador.', 'tipo' => 'texto', 'padrao' => 1, 'fixo' => 1],
                    'cpf' => ['rotulo' => 'CPF', 'descricao' => 'Documento usado para identificar o morador.', 'tipo' => 'texto', 'padrao' => 1, 'fixo' => 1],
                    'unidade' => ['rotulo' => 'Unidade', 'descricao' => 'Gleba, lote ou unidade vinculada ao morador.', 'tipo' => 'selecao', 'padrao' => 1, 'fixo' => 1],
                    'email' => ['rotulo' => 'E-mail', 'descricao' => 'E-mail usado no cadastro e nas comunicações.', 'tipo' => 'email', 'padrao' => 1, 'fixo' => 0],
                    'senha' => ['rotulo' => 'Senha de acesso', 'descricao' => 'Senha inicial para acesso ao portal do morador.', 'tipo' => 'senha', 'padrao' => 1, 'fixo' => 0],
                    'telefone' => ['rotulo' => 'Telefone', 'descricao' => 'Telefone fixo ou principal do morador.', 'tipo' => 'telefone', 'padrao' => 0, 'fixo' => 0],
                    'celular' => ['rotulo' => 'Celular', 'descricao' => 'Telefone celular complementar.', 'tipo' => 'telefone', 'padrao' => 0, 'fixo' => 0],
                    'observacao' => ['rotulo' => 'Observação', 'descricao' => 'Informações adicionais do cadastro.', 'tipo' => 'texto', 'padrao' => 0, 'fixo' => 0],
                ],
            ],
            'dependentes' => [
                'rotulo' => 'Dependentes',
                'campos' => [
                    'morador_id' => ['rotulo' => 'Morador titular', 'descricao' => 'Vínculo obrigatório com o morador responsável.', 'tipo' => 'selecao', 'padrao' => 1, 'fixo' => 1],
                    'nome_completo' => ['rotulo' => 'Nome completo', 'descricao' => 'Identificação nominal do dependente.', 'tipo' => 'texto', 'padrao' => 1, 'fixo' => 1],
                    'parentesco' => ['rotulo' => 'Parentesco', 'descricao' => 'Relação do dependente com o morador titular.', 'tipo' => 'selecao', 'padrao' => 1, 'fixo' => 0],
                    'cpf' => ['rotulo' => 'CPF', 'descricao' => 'Documento do dependente para identificação e duplicidade.', 'tipo' => 'texto', 'padrao' => 1, 'fixo' => 0],
                    'email' => ['rotulo' => 'E-mail', 'descricao' => 'E-mail de contato do dependente.', 'tipo' => 'email', 'padrao' => 0, 'fixo' => 0],
                    'telefone' => ['rotulo' => 'Telefone', 'descricao' => 'Telefone principal do dependente.', 'tipo' => 'telefone', 'padrao' => 0, 'fixo' => 0],
                    'celular' => ['rotulo' => 'Celular', 'descricao' => 'Telefone celular do dependente.', 'tipo' => 'telefone', 'padrao' => 0, 'fixo' => 0],
                    'observacao' => ['rotulo' => 'Observação', 'descricao' => 'Informações adicionais do dependente.', 'tipo' => 'texto', 'padrao' => 0, 'fixo' => 0],
                ],
            ],
        ];
    }
}

if (!function_exists('moradores_catalogo_opcoes')) {
    function moradores_catalogo_opcoes() {
        return [
            'dependente_multiplos_veiculos' => [
                'rotulo' => 'Permitir mais de um veículo por dependente',
                'descricao' => 'Quando ativado, o mesmo dependente poderá ter vários veículos vinculados na tela de Veículos.',
                'tipo' => 'booleano',
                'padrao' => 0,
            ],
        ];
    }
}

if (!function_exists('moradores_config_tabela_existe')) {
    function moradores_config_tabela_existe($conexao, $tabela) {
        $permitidas = ['config_moradores_campos', 'config_moradores_opcoes'];
        if (!in_array($tabela, $permitidas, true)) return false;
        $resultado = $conexao->query("SHOW TABLES LIKE '" . $tabela . "'");
        $existe = $resultado instanceof mysqli_result && $resultado->num_rows > 0;
        if ($resultado instanceof mysqli_result) $resultado->free();
        return $existe;
    }
}

if (!function_exists('moradores_configuracao_disponivel')) {
    function moradores_configuracao_disponivel($conexao) {
        return moradores_config_tabela_existe($conexao, 'config_moradores_campos') && moradores_config_tabela_existe($conexao, 'config_moradores_opcoes');
    }
}

if (!function_exists('moradores_obter_configuracao')) {
    function moradores_obter_configuracao($conexao, $tenant_id) {
        $catalogoCampos = moradores_catalogo_campos();
        $catalogoOpcoes = moradores_catalogo_opcoes();
        $campos = [];

        foreach ($catalogoCampos as $grupo => $grupoMeta) {
            $campos[$grupo] = [];
            foreach ($grupoMeta['campos'] as $campo => $meta) {
                $campos[$grupo][$campo] = [
                    'grupo' => $grupo,
                    'campo' => $campo,
                    'rotulo' => $meta['rotulo'],
                    'descricao' => $meta['descricao'],
                    'tipo' => $meta['tipo'],
                    'obrigatorio' => (bool)$meta['padrao'],
                    'fixo' => (bool)$meta['fixo'],
                ];
            }
        }
        $opcoes = [];
        foreach ($catalogoOpcoes as $opcao => $meta) {
            $opcoes[$opcao] = [
                'opcao' => $opcao,
                'rotulo' => $meta['rotulo'],
                'descricao' => $meta['descricao'],
                'tipo' => $meta['tipo'],
                'habilitado' => (bool)$meta['padrao'],
            ];
        }

        if (moradores_config_tabela_existe($conexao, 'config_moradores_campos')) {
            $stmt = $conexao->prepare('SELECT grupo, campo, obrigatorio FROM config_moradores_campos WHERE tenant_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $tenant_id);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($linha = $res->fetch_assoc()) {
                    $grupo = (string)$linha['grupo'];
                    $campo = (string)$linha['campo'];
                    if (isset($campos[$grupo][$campo]) && !$campos[$grupo][$campo]['fixo']) {
                        $campos[$grupo][$campo]['obrigatorio'] = ((int)$linha['obrigatorio'] === 1);
                    }
                }
                $stmt->close();
            }
        }

        if (moradores_config_tabela_existe($conexao, 'config_moradores_opcoes')) {
            $stmt = $conexao->prepare('SELECT opcao, habilitado FROM config_moradores_opcoes WHERE tenant_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $tenant_id);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($linha = $res->fetch_assoc()) {
                    $opcao = (string)$linha['opcao'];
                    if (isset($opcoes[$opcao])) $opcoes[$opcao]['habilitado'] = ((int)$linha['habilitado'] === 1);
                }
                $stmt->close();
            }
        }

        return [
            'campos' => $campos,
            'opcoes' => $opcoes,
            'modo_compatibilidade' => !moradores_configuracao_disponivel($conexao),
        ];
    }
}

if (!function_exists('moradores_config_opcao_habilitada')) {
    function moradores_config_opcao_habilitada(array $configuracao, $opcao) {
        return !empty($configuracao['opcoes'][$opcao]['habilitado']);
    }
}

if (!function_exists('moradores_validar_campos_configurados')) {
    function moradores_validar_campos_configurados(array $configuracao, array $dados, $grupo, $ignorarSenha = false) {
        $campos = $configuracao['campos'][$grupo] ?? [];
        foreach ($campos as $campo => $meta) {
            if ($ignorarSenha && $campo === 'senha') continue;
            if (empty($meta['obrigatorio'])) continue;
            if (trim((string)($dados[$campo] ?? '')) === '') {
                return 'O campo "' . $meta['rotulo'] . '" é obrigatório para este condomínio.';
            }
        }
        return null;
    }
}
?>
