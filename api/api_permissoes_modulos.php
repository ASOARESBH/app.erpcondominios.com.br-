<?php
// =====================================================================
// API DE CONTROLE DE ACESSO POR MÓDULO
// Versão 1.0
// =====================================================================
// Ações disponíveis:
//   GET  ?acao=listar_modulos          — Lista todos os módulos do sistema
//   GET  ?acao=permissoes_usuario&id=X — Permissões de um usuário específico
//   GET  ?acao=meus_modulos            — Módulos do usuário logado (para sidebar)
//   POST acao=salvar_permissoes        — Salva permissões de um usuário (admin)
//   POST acao=resetar_para_perfil      — Reseta para padrão do perfil (admin)
// =====================================================================

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar autenticação básica
$usuario_logado = verificarAutenticacao(true);
$conexao = conectar_banco();

// Garantir que as tabelas existem
_garantir_tabelas($conexao);

$metodo = $_SERVER['REQUEST_METHOD'];
$acao   = $_GET['acao'] ?? $_POST['acao'] ?? (json_decode(file_get_contents('php://input'), true)['acao'] ?? '');

// ─────────────────────────────────────────────────────────────────────
// GET: Listar todos os módulos do sistema
// ─────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'listar_modulos') {
    $sql = "SELECT id, chave, nome, grupo, icone, descricao, permissao_minima, ativo, ordem
            FROM modulos_sistema ORDER BY ordem ASC";
    $res = $conexao->query($sql);
    $modulos = [];
    while ($row = $res->fetch_assoc()) {
        $modulos[] = $row;
    }
    // Agrupar por grupo
    $agrupados = [];
    foreach ($modulos as $m) {
        $agrupados[$m['grupo']][] = $m;
    }
    retornar_json(true, 'Módulos listados', ['modulos' => $modulos, 'grupos' => $agrupados]);
}

// ─────────────────────────────────────────────────────────────────────
// GET: Permissões de um usuário específico (admin/gerente)
// ─────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'permissoes_usuario') {
    verificarPermissao('gerente');
    $usuario_id = intval($_GET['id'] ?? 0);
    if ($usuario_id <= 0) {
        retornar_json(false, 'ID de usuário inválido');
    }

    // Buscar dados do usuário
    $stmt = $conexao->prepare("SELECT id, nome, email, permissao FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $usuario_id);
    $stmt->execute();
    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$usuario) {
        retornar_json(false, 'Usuário não encontrado');
    }

    // Admin tem acesso total — retornar tudo como habilitado
    if ($usuario['permissao'] === 'admin') {
        $sql = "SELECT chave, nome, grupo, icone, descricao, permissao_minima, ativo, ordem FROM modulos_sistema ORDER BY ordem ASC";
        $res = $conexao->query($sql);
        $permissoes = [];
        while ($row = $res->fetch_assoc()) {
            $permissoes[$row['chave']] = [
                'modulo_chave'  => $row['chave'],
                'nome'          => $row['nome'],
                'grupo'         => $row['grupo'],
                'icone'         => $row['icone'],
                'descricao'     => $row['descricao'],
                'permissao_minima' => $row['permissao_minima'],
                'ativo'         => $row['ativo'],
                'ordem'         => $row['ordem'],
                'pode_acessar'  => 1,
                'pode_criar'    => 1,
                'pode_editar'   => 1,
                'pode_excluir'  => 1,
                'pode_exportar' => 1,
                'origem'        => 'admin_total'
            ];
        }
        retornar_json(true, 'Permissões carregadas (admin total)', [
            'usuario'    => $usuario,
            'permissoes' => $permissoes
        ]);
    }

    // Buscar permissões individuais salvas
    $sql = "SELECT um.modulo_chave, um.pode_acessar, um.pode_criar, um.pode_editar,
                   um.pode_excluir, um.pode_exportar,
                   ms.nome, ms.grupo, ms.icone, ms.descricao, ms.permissao_minima, ms.ativo, ms.ordem
            FROM modulos_sistema ms
            LEFT JOIN usuario_modulos um ON ms.chave = um.modulo_chave AND um.usuario_id = ?
            ORDER BY ms.ordem ASC";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param('i', $usuario_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $hierarquia = ['visualizador' => 1, 'operador' => 2, 'gerente' => 3, 'admin' => 4];
    $nivel_usuario = $hierarquia[$usuario['permissao']] ?? 1;

    $permissoes = [];
    while ($row = $res->fetch_assoc()) {
        $nivel_modulo = $hierarquia[$row['permissao_minima']] ?? 1;
        $tem_permissao_perfil = ($nivel_usuario >= $nivel_modulo);

        // Se não há registro individual, usar padrão do perfil
        $tem_registro = ($row['pode_acessar'] !== null);
        $pode_acessar = $tem_registro ? (int)$row['pode_acessar'] : ($tem_permissao_perfil ? 1 : 0);

        $permissoes[$row['modulo_chave']] = [
            'modulo_chave'   => $row['modulo_chave'],
            'nome'           => $row['nome'],
            'grupo'          => $row['grupo'],
            'icone'          => $row['icone'],
            'descricao'      => $row['descricao'],
            'permissao_minima' => $row['permissao_minima'],
            'ativo'          => (int)$row['ativo'],
            'ordem'          => (int)$row['ordem'],
            'pode_acessar'   => $pode_acessar,
            'pode_criar'     => $tem_registro ? (int)$row['pode_criar']    : ($tem_permissao_perfil ? 1 : 0),
            'pode_editar'    => $tem_registro ? (int)$row['pode_editar']   : ($tem_permissao_perfil ? 1 : 0),
            'pode_excluir'   => $tem_registro ? (int)$row['pode_excluir']  : 0,
            'pode_exportar'  => $tem_registro ? (int)$row['pode_exportar'] : ($tem_permissao_perfil ? 1 : 0),
            'origem'         => $tem_registro ? 'individual' : 'perfil_padrao',
            'perfil_permite' => $tem_permissao_perfil
        ];
    }
    $stmt->close();

    retornar_json(true, 'Permissões carregadas', [
        'usuario'    => $usuario,
        'permissoes' => $permissoes
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// GET: Módulos do usuário logado (para sidebar e verificação de acesso)
// Alias: minhas_permissoes — retorna formato { is_admin, permissoes: { chave: {...} } }
// ─────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'minhas_permissoes') {
    $acao = 'meus_modulos'; // normalizar para o bloco abaixo
}
if ($metodo === 'GET' && $acao === 'meus_modulos') {
    $uid = $usuario_logado['id'];
    $permissao = $usuario_logado['permissao'] ?? 'operador';

    // Admin tem acesso total
    if ($permissao === 'admin') {
        $sql = "SELECT chave, nome, grupo, icone, ordem FROM modulos_sistema WHERE ativo = 1 ORDER BY ordem ASC";
        $res = $conexao->query($sql);
        $modulos = [];
        while ($row = $res->fetch_assoc()) {
            $modulos[$row['chave']] = [
                'pode_acessar'  => true,
                'pode_criar'    => true,
                'pode_editar'   => true,
                'pode_excluir'  => true,
                'pode_exportar' => true
            ];
        }
        retornar_json(true, 'Módulos carregados', ['modulos' => $modulos, 'is_admin' => true]);
    }

    $hierarquia = ['visualizador' => 1, 'operador' => 2, 'gerente' => 3, 'admin' => 4];
    $nivel_usuario = $hierarquia[$permissao] ?? 1;

    $sql = "SELECT ms.chave, ms.nome, ms.grupo, ms.icone, ms.ordem,
                   ms.permissao_minima,
                   um.pode_acessar, um.pode_criar, um.pode_editar, um.pode_excluir, um.pode_exportar
            FROM modulos_sistema ms
            LEFT JOIN usuario_modulos um ON ms.chave = um.modulo_chave AND um.usuario_id = ?
            WHERE ms.ativo = 1
            ORDER BY ms.ordem ASC";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();

    $modulos = [];
    while ($row = $res->fetch_assoc()) {
        $nivel_modulo = $hierarquia[$row['permissao_minima']] ?? 1;
        $tem_permissao_perfil = ($nivel_usuario >= $nivel_modulo);
        $tem_registro = ($row['pode_acessar'] !== null);
        $pode_acessar = $tem_registro ? (bool)$row['pode_acessar'] : $tem_permissao_perfil;

        if ($pode_acessar) {
            $modulos[$row['chave']] = [
                'pode_acessar'  => true,
                'pode_criar'    => $tem_registro ? (bool)$row['pode_criar']    : $tem_permissao_perfil,
                'pode_editar'   => $tem_registro ? (bool)$row['pode_editar']   : $tem_permissao_perfil,
                'pode_excluir'  => $tem_registro ? (bool)$row['pode_excluir']  : false,
                'pode_exportar' => $tem_registro ? (bool)$row['pode_exportar'] : $tem_permissao_perfil
            ];
        }
    }
    $stmt->close();

    retornar_json(true, 'Módulos carregados', ['modulos' => $modulos, 'is_admin' => false]);
}

// ─────────────────────────────────────────────────────────────────────
// POST: Salvar permissões de um usuário (somente admin)
// ─────────────────────────────────────────────────────────────────────
if ($metodo === 'POST') {
    verificarPermissao('admin');
    $dados = json_decode(file_get_contents('php://input'), true);
    $acao_post = $dados['acao'] ?? $acao;

    if ($acao_post === 'salvar_permissoes') {
        $usuario_id  = intval($dados['usuario_id'] ?? 0);
        $permissoes  = $dados['permissoes'] ?? [];

        if ($usuario_id <= 0 || empty($permissoes)) {
            retornar_json(false, 'Dados inválidos');
        }

        // Verificar se usuário existe
        $stmt = $conexao->prepare("SELECT id, permissao FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $usuario_id);
        $stmt->execute();
        $usuario = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$usuario) {
            retornar_json(false, 'Usuário não encontrado');
        }

        // Admin não precisa de permissões individuais — sempre tem acesso total
        if ($usuario['permissao'] === 'admin') {
            retornar_json(true, 'Administradores têm acesso total a todos os módulos automaticamente');
        }

        // Salvar permissões em lote (INSERT ... ON DUPLICATE KEY UPDATE)
        $salvos = 0;
        foreach ($permissoes as $chave => $perm) {
            $chave         = $conexao->real_escape_string($chave);
            $pode_acessar  = isset($perm['pode_acessar'])  ? (int)(bool)$perm['pode_acessar']  : 0;
            $pode_criar    = isset($perm['pode_criar'])    ? (int)(bool)$perm['pode_criar']    : 0;
            $pode_editar   = isset($perm['pode_editar'])   ? (int)(bool)$perm['pode_editar']   : 0;
            $pode_excluir  = isset($perm['pode_excluir'])  ? (int)(bool)$perm['pode_excluir']  : 0;
            $pode_exportar = isset($perm['pode_exportar']) ? (int)(bool)$perm['pode_exportar'] : 0;

            $sql = "INSERT INTO usuario_modulos
                        (usuario_id, modulo_chave, pode_acessar, pode_criar, pode_editar, pode_excluir, pode_exportar)
                    VALUES
                        ($usuario_id, '$chave', $pode_acessar, $pode_criar, $pode_editar, $pode_excluir, $pode_exportar)
                    ON DUPLICATE KEY UPDATE
                        pode_acessar  = VALUES(pode_acessar),
                        pode_criar    = VALUES(pode_criar),
                        pode_editar   = VALUES(pode_editar),
                        pode_excluir  = VALUES(pode_excluir),
                        pode_exportar = VALUES(pode_exportar),
                        atualizado_em = NOW()";
            if ($conexao->query($sql)) {
                $salvos++;
            }
        }

        registrar_log('PERMISSOES_MODULOS', "Permissões salvas para usuário ID $usuario_id — $salvos módulos", $_SESSION['usuario_nome'] ?? 'sistema');
        retornar_json(true, "Permissões salvas com sucesso ($salvos módulos)", ['salvos' => $salvos]);
    }

    if ($acao_post === 'resetar_para_perfil') {
        $usuario_id = intval($dados['usuario_id'] ?? 0);
        if ($usuario_id <= 0) {
            retornar_json(false, 'ID inválido');
        }
        $stmt = $conexao->prepare("DELETE FROM usuario_modulos WHERE usuario_id = ?");
        $stmt->bind_param('i', $usuario_id);
        $stmt->execute();
        $stmt->close();
        registrar_log('PERMISSOES_RESET', "Permissões resetadas para padrão do perfil — usuário ID $usuario_id", $_SESSION['usuario_nome'] ?? 'sistema');
        retornar_json(true, 'Permissões resetadas para o padrão do perfil');
    }

    retornar_json(false, 'Ação não reconhecida');
}

retornar_json(false, 'Método ou ação inválida');

// ─────────────────────────────────────────────────────────────────────
// HELPER: Garantir que as tabelas existem
// ─────────────────────────────────────────────────────────────────────
function _garantir_tabelas($conexao) {
    $conexao->query("CREATE TABLE IF NOT EXISTS `modulos_sistema` (
        `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `chave`            VARCHAR(60)  NOT NULL,
        `nome`             VARCHAR(100) NOT NULL,
        `grupo`            VARCHAR(60)  NOT NULL,
        `icone`            VARCHAR(60)  NOT NULL DEFAULT 'fas fa-circle',
        `descricao`        VARCHAR(255) DEFAULT NULL,
        `permissao_minima` ENUM('visualizador','operador','gerente','admin') NOT NULL DEFAULT 'operador',
        `ativo`            TINYINT(1)   NOT NULL DEFAULT 1,
        `ordem`            SMALLINT     NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_chave` (`chave`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conexao->query("CREATE TABLE IF NOT EXISTS `usuario_modulos` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `usuario_id`    INT UNSIGNED NOT NULL,
        `modulo_chave`  VARCHAR(60)  NOT NULL,
        `pode_acessar`  TINYINT(1)   NOT NULL DEFAULT 1,
        `pode_criar`    TINYINT(1)   NOT NULL DEFAULT 0,
        `pode_editar`   TINYINT(1)   NOT NULL DEFAULT 0,
        `pode_excluir`  TINYINT(1)   NOT NULL DEFAULT 0,
        `pode_exportar` TINYINT(1)   NOT NULL DEFAULT 0,
        `criado_em`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `atualizado_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_usuario_modulo` (`usuario_id`, `modulo_chave`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Inserir módulos padrão se a tabela estiver vazia
    $count = $conexao->query("SELECT COUNT(*) as c FROM modulos_sistema")->fetch_assoc()['c'];
    if ($count == 0) {
        $modulos_padrao = [
            ['dashboard','Dashboard','Core','fas fa-chart-line','Painel principal com KPIs','visualizador',10],
            ['moradores','Moradores','Condomínios','fas fa-users','Cadastro de moradores','operador',20],
            ['veiculos','Veículos','Condomínios','fas fa-car','Cadastro de veículos','operador',21],
            ['visitantes','Visitantes','Condomínios','fas fa-user-friends','Controle de visitantes','operador',22],
            ['registro','Registro Manual','Acesso','fas fa-clipboard-list','Registro de entrada/saída','operador',30],
            ['acesso','Controle de Acesso','Acesso','fas fa-door-open','Histórico de acessos','operador',31],
            ['relatorios','Relatórios de Acesso','Acesso','fas fa-file-alt','Relatórios de acessos','gerente',32],
            ['financeiro','Financeiro','Financeiro','fas fa-money-bill-wave','Painel financeiro','gerente',40],
            ['contas_pagar','Contas a Pagar','Financeiro','fas fa-arrow-up','Gestão de despesas','gerente',41],
            ['contas_receber','Contas a Receber','Financeiro','fas fa-arrow-down','Gestão de receitas','gerente',42],
            ['planos_contas','Planos de Contas','Financeiro','fas fa-list-ol','Classificação contábil','gerente',43],
            ['importacao_financeira','Importação Financeira','Financeiro','fas fa-file-import','Importação de extratos','gerente',44],
            ['logs_financeiro','Logs Financeiros','Financeiro','fas fa-bug','Diagnóstico financeiro','admin',45],
            ['manutencao','Manutenção Geral','Manutenção','fas fa-tools','Ordens de serviço','operador',50],
            ['hidrometro','Hidrômetros','Manutenção','fas fa-tint','Cadastro de hidrômetros','operador',51],
            ['leitura','Leituras Hidrômetro','Manutenção','fas fa-tachometer-alt','Leituras mensais','operador',52],
            ['relatorios_hidrometro','Relatórios Hidrômetro','Manutenção','fas fa-chart-bar','Relatórios de consumo','gerente',53],
            ['abastecimento','Abastecimento','Manutenção','fas fa-gas-pump','Controle de abastecimento','operador',54],
            ['estoque','Estoque','Manutenção','fas fa-boxes','Gestão de estoque','operador',55],
            ['inventario','Inventário','Manutenção','fas fa-clipboard-check','Inventário de patrimônio','operador',56],
            ['relatorios_inventario','Relatórios Inventário','Manutenção','fas fa-chart-pie','Relatórios de inventário','gerente',57],
            ['administrativa','Administrativo','Administrativo','fas fa-briefcase','Gestão administrativa','gerente',60],
            ['contratos','Contratos','Administrativo','fas fa-file-contract','Gestão de contratos','gerente',61],
            ['protocolos','Protocolos','Administrativo','fas fa-stamp','Registro de protocolos','operador',62],
            ['notificacoes','Notificações','Administrativo','fas fa-bell','Envio de notificações','operador',63],
            ['eventos','Eventos','Administrativo','fas fa-calendar-alt','Gestão de eventos','operador',64],
            ['recursos_humanos','Recursos Humanos','RH','fas fa-id-card','Gestão de funcionários','gerente',70],
            ['crm','CRM','CRM','fas fa-handshake','Gestão de leads','gerente',80],
            ['marketplace','Marketplace','Marketplace','fas fa-store','Portal de produtos','operador',90],
            ['marketplace_admin','Marketplace Admin','Marketplace','fas fa-store-alt','Admin do marketplace','admin',91],
            ['configuracao','Configurações','Sistema','fas fa-cog','Configurações gerais','admin',100],
            ['dispositivos','Dispositivos','Sistema','fas fa-microchip','Gestão de dispositivos','admin',101],
            ['seguranca','Segurança','Sistema','fas fa-shield-alt','Configurações de segurança','admin',102],
            ['sistema','Sistema','Sistema','fas fa-server','Informações do servidor','admin',103],
            ['usuarios','Usuários','Sistema','fas fa-user-cog','Gerenciamento de usuários','admin',104],
            ['empresa','Empresa','Sistema','fas fa-building','Dados da empresa','admin',105],
            ['meu_perfil','Meu Perfil','Sistema','fas fa-user-circle','Perfil do usuário','visualizador',106],
        ];
        $stmt = $conexao->prepare("INSERT IGNORE INTO modulos_sistema (chave,nome,grupo,icone,descricao,permissao_minima,ordem) VALUES (?,?,?,?,?,?,?)");
        foreach ($modulos_padrao as $m) {
            $stmt->bind_param('ssssssi', $m[0],$m[1],$m[2],$m[3],$m[4],$m[5],$m[6]);
            $stmt->execute();
        }
        $stmt->close();
    }
}

function retornar_json($sucesso, $mensagem, $dados = null) {
    $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
    if ($dados !== null) $r['dados'] = $dados;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

fechar_conexao($conexao);
