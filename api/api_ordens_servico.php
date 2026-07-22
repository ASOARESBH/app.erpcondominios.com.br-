<?php
// =====================================================
// API - ORDENS DE SERVIÇO (OS)
// Versão: 1.0  |  Data: 2026-06-22
// =====================================================
// Endpoints (acao via GET ou JSON body):
//   migration           — cria/verifica tabelas
//   listar              — lista OS com filtros
//   criar               — cria nova OS
//   editar              — edita OS existente
//   excluir             — exclui OS
//   buscar              — busca OS por número/assunto
//   dashboard_kpis      — KPIs para o dashboard
//   listar_interacoes   — interações de uma OS
//   adicionar_interacao — adiciona interação (muda status p/ andamento)
//   finalizar           — finaliza OS (horas_totais opcional)
//   vincular_chamado    — vincula OS dependente
//   listar_assuntos     — lista assuntos de configuração
//   criar_assunto       — cria assunto
//   editar_assunto      — edita assunto
//   excluir_assunto     — exclui assunto
//   listar_config       — lista configurações homem-hora
//   salvar_config       — salva configuração homem-hora
//   listar_materiais    — lista materiais usados em uma OS
//   adicionar_material  — adiciona material a uma OS
//   remover_material    — remove material de uma OS
//   baixar_estoque_os   — abate estoque ao finalizar OS
// =====================================================

// ─── Handler de erro fatal ───────────────────────────
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $log_file = __DIR__ . '/../logs/debug_ordens_servico.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $entry = date('Y-m-d H:i:s') . ' | PHP_ERROR | ' . json_encode([
        'errno' => $errno, 'errstr' => $errstr,
        'errfile' => basename($errfile), 'errline' => $errline
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['sucesso'=>false,'mensagem'=>"Erro interno: $errstr (linha $errline)"], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return false;
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $log_file = __DIR__ . '/../logs/debug_ordens_servico.log';
        $entry = date('Y-m-d H:i:s') . ' | FATAL_ERROR | ' . json_encode($error, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso'=>false,'mensagem'=>'Erro fatal: '.$error['message']], JSON_UNESCAPED_UNICODE);
        }
    }
});

// ─── Configurações de sessão ─────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 7200);

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
ob_end_clean();

// ─── Headers ─────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$allowed_origins = [
    'https://asl.erpcondominios.com.br',
    'http://asl.erpcondominios.com.br',
    'https://erpcondominios.com.br',
    'http://localhost',
    'http://127.0.0.1'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed_origins) ? $origin : 'https://asl.erpcondominios.com.br'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── Função de log de debug ──────────────────────────
function os_log($nivel, $mensagem, $dados = []) {
    $log_file = __DIR__ . '/../logs/debug_ordens_servico.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $session_id = (session_status() === PHP_SESSION_ACTIVE) ? session_id() : 'sem-sessao';
    $entry = date('Y-m-d H:i:s') . ' | ' . strtoupper($nivel) . ' | ' . json_encode([
        'session_id' => $session_id,
        'mensagem'   => $mensagem,
        'dados'      => $dados
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
}

// ─── Função retornar JSON ────────────────────────────
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─── Função de geração de notificações ────────────────
if (!function_exists('_os_gerar_notificacoes')) {
    function _os_gerar_notificacoes($conn, $evento, $os_dados) {
        // Garantir que as tabelas corretas existem
        $conn->query("CREATE TABLE IF NOT EXISTS notif_alertas (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            regra_id      INT          DEFAULT NULL,
            modulo        VARCHAR(50)  NOT NULL DEFAULT 'os',
            evento        VARCHAR(80)  NOT NULL,
            titulo        VARCHAR(255) NOT NULL,
            corpo         TEXT,
            icone         VARCHAR(50)  DEFAULT 'fa-bell',
            cor           VARCHAR(20)  DEFAULT 'blue',
            link_pagina   VARCHAR(100) DEFAULT NULL,
            link_id       INT          DEFAULT NULL,
            criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("CREATE TABLE IF NOT EXISTS notif_destinatarios (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            alerta_id   INT NOT NULL,
            usuario_id  INT NOT NULL,
            lido        TINYINT(1) NOT NULL DEFAULT 0,
            lido_em     DATETIME DEFAULT NULL,
            dispensado  TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uk_alerta_usuario (alerta_id, usuario_id),
            INDEX idx_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ev_esc = $conn->real_escape_string($evento);
        $result = $conn->query(
            "SELECT * FROM notif_regras WHERE modulo='os' AND evento='$ev_esc' AND ativo=1"
        );
        if (!$result) return;

        while ($regra = $result->fetch_assoc()) {
            // Filtro de prioridade
            if (!empty($regra['prioridade'])) {
                if (($os_dados['prioridade'] ?? '') !== $regra['prioridade']) continue;
            }

            // Substituir variáveis nos templates
            $vars = [
                '{numero}'       => $os_dados['numero']          ?? '',
                '{titulo}'       => $os_dados['titulo']           ?? '',
                '{prioridade}'   => $os_dados['prioridade']       ?? '',
                '{departamento}' => $os_dados['departamento']     ?? '',
                '{morador}'      => $os_dados['morador_nome']     ?? '',
                '{atendente}'    => $os_dados['atendente_nome']   ?? '',
                '{criado_por}'   => $os_dados['criado_por_nome']  ?? ($os_dados['morador_nome'] ?? ''),
                '{horas}'        => $os_dados['horas']            ?? '',
            ];
            $titulo = str_replace(array_keys($vars), array_values($vars),
                      $regra['titulo_tpl'] ?? 'Nova O.S: {numero}');
            $corpo  = str_replace(array_keys($vars), array_values($vars),
                      $regra['corpo_tpl']  ?? 'O.S {numero} - {titulo}');

            // Ícone e cor baseados no evento
            $icone = 'fa-bell'; $cor = 'blue';
            if (strpos($evento, 'urgente')    !== false) { $icone = 'fa-exclamation-triangle'; $cor = 'red';    }
            elseif (strpos($evento, 'alta')   !== false) { $icone = 'fa-exclamation-circle';   $cor = 'orange'; }
            elseif (strpos($evento, 'criada') !== false) { $icone = 'fa-plus-circle';           $cor = 'green';  }
            elseif (strpos($evento, 'horas')  !== false) { $icone = 'fa-clock';                 $cor = 'amber';  }
            elseif (strpos($evento, 'finaliz')!== false) { $icone = 'fa-check-circle';          $cor = 'green';  }
            elseif (strpos($evento, 'previsao')!== false){ $icone = 'fa-calendar-check';        $cor = 'blue';   }

            // Inserir em notif_alertas
            $titulo_esc = $conn->real_escape_string($titulo);
            $corpo_esc  = $conn->real_escape_string($corpo);
            $regra_id   = intval($regra['id']);
            $link_id    = intval($os_dados['id'] ?? 0);

            $conn->query(
                "INSERT INTO notif_alertas
                    (regra_id, modulo, evento, titulo, corpo, icone, cor, link_pagina, link_id)
                 VALUES
                    ($regra_id, 'os', '$ev_esc', '$titulo_esc', '$corpo_esc',
                     '$icone', '$cor', 'ordens_servico', $link_id)"
            );
            $alerta_id = $conn->insert_id;
            if (!$alerta_id) continue;

            // Destinatários: usuários configurados na regra OU todos admins/gerentes
            $dest_ids = [];
            if (!empty($regra['usuarios_ids'])) {
                $dest_ids = array_filter(array_map('intval',
                    explode(',', $regra['usuarios_ids'])));
            }
            if (empty($dest_ids)) {
                $r_adm = $conn->query(
                    "SELECT id FROM usuarios WHERE tenant_id = $tenant_id AND permissao IN ('admin','gerente') AND ativo=1"
                );
                if ($r_adm) while ($u = $r_adm->fetch_assoc()) $dest_ids[] = intval($u['id']);
            }

            // Sempre incluir atendente e morador/solicitante vinculados à OS
            if (!empty($os_dados['atendente_id']))  $dest_ids[] = intval($os_dados['atendente_id']);
            if (!empty($os_dados['morador_id']))     $dest_ids[] = intval($os_dados['morador_id']);
            if (!empty($os_dados['criado_por_id']))  $dest_ids[] = intval($os_dados['criado_por_id']);

            foreach (array_unique(array_filter($dest_ids)) as $uid) {
                $conn->query(
                    "INSERT IGNORE INTO notif_destinatarios (alerta_id, usuario_id)
                     VALUES ($alerta_id, $uid)"
                );
            }
        }
    }
}

// ─── Autenticação ────────────────────────────────────
try {
    verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();
} catch (Exception $e) {
    os_log('erro', 'Autenticação falhou', ['msg' => $e->getMessage()]);
    retornar_json(false, 'Não autenticado: ' . $e->getMessage());
}

// ─── Conexão ─────────────────────────────────────────
$conn = conectar_banco();
if (!$conn) {
    os_log('erro', 'Falha na conexão com banco');
    retornar_json(false, 'Erro ao conectar ao banco de dados');
}
$conn->set_charset('utf8mb4');

// ─── Leitura da ação ─────────────────────────────────
$metodo = $_SERVER['REQUEST_METHOD'];
$body   = [];
$raw    = file_get_contents('php://input');
if ($raw) {
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) $body = $decoded;
}
$acao = $_GET['acao'] ?? $_POST['acao'] ?? $body['acao'] ?? '';

os_log('info', 'Requisição recebida', ['metodo' => $metodo, 'acao' => $acao, 'get' => $_GET]);

// =====================================================
// MIGRATION — CRIAR TABELAS
// =====================================================
function _os_garantir_tabelas($conn) {
    $sqls = [];

    // Tabela principal de OS
    $sqls[] = "CREATE TABLE IF NOT EXISTS os_chamados (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        numero          VARCHAR(20) NOT NULL UNIQUE COMMENT 'Ex: OS-2024-0001',
        titulo          VARCHAR(255) NOT NULL,
        assunto_id      INT DEFAULT NULL,
        departamento    VARCHAR(100) DEFAULT NULL,
        prioridade      ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
        status          ENUM('aberto','andamento','finalizado','cancelado') NOT NULL DEFAULT 'aberto',
        morador_id      INT DEFAULT NULL,
        morador_nome    VARCHAR(255) DEFAULT NULL,
        morador_unidade VARCHAR(50) DEFAULT NULL,
        atendente_id    INT DEFAULT NULL,
        atendente_nome  VARCHAR(255) DEFAULT NULL,
        descricao       TEXT DEFAULT NULL,
        horas_estimadas DECIMAL(8,2) DEFAULT NULL,
        horas_totais    DECIMAL(8,2) DEFAULT NULL,
        os_pai_id       INT DEFAULT NULL COMMENT 'OS dependente de outra',
        data_abertura   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        data_inicio     DATETIME DEFAULT NULL,
        data_finalizacao DATETIME DEFAULT NULL,
        data_previsao   DATE DEFAULT NULL,
        criado_por_id   INT DEFAULT NULL,
        criado_por_nome VARCHAR(255) DEFAULT NULL,
        observacao_finalizacao TEXT DEFAULT NULL,
        origem_portal   VARCHAR(30) DEFAULT NULL COMMENT 'NULL=interno, portal_morador=aberto pelo portal',
        assumido_por_id   INT DEFAULT NULL COMMENT 'Atendente que assumiu OS do portal',
        assumido_por_nome VARCHAR(150) DEFAULT NULL,
        data_assumido   DATETIME DEFAULT NULL,
        INDEX idx_status (status),
        INDEX idx_prioridade (prioridade),
        INDEX idx_departamento (departamento),
        INDEX idx_data_abertura (data_abertura),
        INDEX idx_morador_id (morador_id),
        INDEX idx_atendente_id (atendente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Interações / histórico de atendimento
    $sqls[] = "CREATE TABLE IF NOT EXISTS os_interacoes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        os_id       INT NOT NULL,
        tipo        ENUM('comentario','andamento','solucao','nota_interna') NOT NULL DEFAULT 'comentario',
        mensagem    TEXT NOT NULL,
        anexos      MEDIUMTEXT NULL,
        usuario_id  INT DEFAULT NULL,
        usuario_nome VARCHAR(255) DEFAULT NULL,
        criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_os_id (os_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Materiais usados em cada OS
    $sqls[] = "CREATE TABLE IF NOT EXISTS os_materiais_usados (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        os_id           INT NOT NULL,
        produto_id      INT NOT NULL,
        produto_nome    VARCHAR(255) NOT NULL,
        quantidade      DECIMAL(10,3) NOT NULL DEFAULT 1,
        preco_unitario  DECIMAL(10,2) DEFAULT 0,
        estoque_baixado TINYINT(1) NOT NULL DEFAULT 0,
        adicionado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_os_id (os_id),
        INDEX idx_produto_id (produto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Assuntos / categorias de OS (configuráveis)
    $sqls[] = "CREATE TABLE IF NOT EXISTS os_assuntos (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        nome        VARCHAR(150) NOT NULL,
        descricao   VARCHAR(255) DEFAULT NULL,
        departamento VARCHAR(100) DEFAULT NULL,
        ativo       TINYINT(1) NOT NULL DEFAULT 1,
        criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Configuração de homem-hora por assunto/serviço
    $sqls[] = "CREATE TABLE IF NOT EXISTS os_config_homem_hora (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        assunto_id      INT DEFAULT NULL,
        descricao       VARCHAR(255) NOT NULL,
        horas_estimadas DECIMAL(8,2) NOT NULL DEFAULT 1,
        custo_hora      DECIMAL(10,2) DEFAULT 0,
        ativo           TINYINT(1) NOT NULL DEFAULT 1,
        criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Recursos humanos vinculados a cada OS
    $sqls[] = "CREATE TABLE IF NOT EXISTS os_recursos_humanos (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        os_id           INT NOT NULL,
        colaborador_id  INT NOT NULL,
        colaborador_nome VARCHAR(255) NOT NULL,
        cargo           VARCHAR(150) DEFAULT NULL,
        departamento    VARCHAR(100) DEFAULT NULL,
        vinculado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_os_id (os_id),
        INDEX idx_colaborador_id (colaborador_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $erros = [];
    foreach ($sqls as $sql) {
        if (!$conn->query($sql)) {
            $erros[] = $conn->error;
        }
    }
    return $erros;
}

// Executar migration automaticamente
$erros_migration = _os_garantir_tabelas($conn);
if (!empty($erros_migration)) {
    os_log('erro', 'Erros na migration', $erros_migration);
}

// Adicionar coluna anexos em tabelas existentes (idempotente)
$_r = @$conn->query("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='os_interacoes' AND COLUMN_NAME='anexos'");
if ($_r && (int)$_r->fetch_assoc()['c'] === 0) {
    @$conn->query("ALTER TABLE os_interacoes ADD COLUMN anexos MEDIUMTEXT NULL AFTER mensagem");
}

// =====================================================
// MÓDULO PROJETOS — esquema aditivo (flag sobre a própria O.S)
// =====================================================
// "Projeto" não é um cadastro novo: é uma classificação da O.S existente.
// Todas as colunas abaixo são aditivas (nullable ou com DEFAULT) e não
// alteram nenhuma coluna, índice ou fluxo já existente em os_chamados/
// os_interacoes. Reaproveita a tabela central `departamentos` já usada
// por `listar_departamentos` nesta mesma API.
function _os_add_column_if_missing($conn, $tabela, $coluna, $definicao) {
    $r = @$conn->query("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tabela' AND COLUMN_NAME='$coluna'");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        @$conn->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao");
    }
}

function _os_garantir_esquema_projetos($conn) {
    // Projeto Público — classificação e informações públicas da O.S
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_publico', "TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_nome', "VARCHAR(255) DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_descricao', "TEXT DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_objetivo', "TEXT DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_beneficios', "TEXT DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_localizacao', "VARCHAR(255) DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_responsavel', "VARCHAR(255) DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_departamento_id', "INT DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_data_inicio_prevista', "DATE DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_data_fim_prevista', "DATE DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_imagem_capa', "VARCHAR(255) DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_percentual', "TINYINT UNSIGNED NOT NULL DEFAULT 0");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_etapa_id', "INT DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_chamados', 'projeto_status', "ENUM('planejamento','execucao','paralisado','finalizado') NOT NULL DEFAULT 'planejamento'");

    // Interações: etapa/percentual (só usados quando tipo='andamento') + flag de publicação
    _os_add_column_if_missing($conn, 'os_interacoes', 'etapa_id', "INT DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_interacoes', 'percentual', "TINYINT UNSIGNED DEFAULT NULL");
    _os_add_column_if_missing($conn, 'os_interacoes', 'publica', "TINYINT(1) NOT NULL DEFAULT 0");

    // Etapas configuráveis (Configurações → Ordens de Serviço → Etapas) — lista fixa, sem digitação livre
    $conn->query("CREATE TABLE IF NOT EXISTS os_etapas (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nome      VARCHAR(100) NOT NULL,
        ordem     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        ativo     TINYINT(1) NOT NULL DEFAULT 1,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_nome (nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $cnt_etapas = $conn->query("SELECT COUNT(*) c FROM os_etapas");
    if ($cnt_etapas && (int)$cnt_etapas->fetch_assoc()['c'] === 0) {
        $seeds_etapas = [
            'Análise', 'Levantamento Técnico', 'Projeto Executivo', 'Orçamento', 'Licitação',
            'Compra de Materiais', 'Chegada de Materiais', 'Mobilização', 'Início da Obra',
            'Execução', 'Concretagem', 'Acabamento', 'Paisagismo', 'Vistoria', 'Correções',
            'Entrega', 'Finalizado',
        ];
        $stmt_seed = $conn->prepare("INSERT IGNORE INTO os_etapas (nome, ordem) VALUES (?, ?)");
        foreach ($seeds_etapas as $i => $nome_etapa) {
            $ordem_etapa = $i + 1;
            $stmt_seed->bind_param('si', $nome_etapa, $ordem_etapa);
            $stmt_seed->execute();
        }
    }

    // Cadastro central de departamentos (mesma tabela usada por `listar_departamentos`
    // nesta API e por api_departamentos.php/api_documentos.php) — garantida aqui também
    // porque projeto_departamento_id depende dela existir desde o primeiro uso do módulo.
    $conn->query("CREATE TABLE IF NOT EXISTS departamentos (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        nome          VARCHAR(100) NOT NULL,
        descricao     VARCHAR(255) DEFAULT NULL,
        ativo         TINYINT(1) NOT NULL DEFAULT 1,
        criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_nome (nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Fotos de obra por interação — arquivo real em disco (não base64), no padrão de uploads do GED
    $conn->query("CREATE TABLE IF NOT EXISTS os_interacao_fotos (
        id                    INT AUTO_INCREMENT PRIMARY KEY,
        interacao_id          INT NOT NULL,
        arquivo               VARCHAR(255) NOT NULL,
        arquivo_nome_original VARCHAR(255) DEFAULT NULL,
        arquivo_tamanho       INT UNSIGNED DEFAULT 0,
        criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_interacao (interacao_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Vínculo opcional de documentos do GED a um projeto — coluna aditiva na
    // própria tabela `documentos`; só é alterada se a tabela já existir
    // (o GED cria sua própria estrutura em api_documentos.php).
    $tab_doc = $conn->query("SHOW TABLES LIKE 'documentos'");
    if ($tab_doc && $tab_doc->num_rows > 0) {
        _os_add_column_if_missing($conn, 'documentos', 'os_id', "INT DEFAULT NULL");
    }

    // Diretórios de upload (capa do projeto + fotos de obra). Mesmo essas
    // imagens sendo eventualmente públicas, o acesso direto à pasta é
    // bloqueado — toda entrega passa por api_imagem_projeto.php, que valida
    // se o projeto está publicado antes de servir o arquivo (nunca expor
    // fotos de um projeto ainda não publicado por URL previsível).
    foreach (['projetos_capas', 'projetos_fotos'] as $subdir) {
        $dir = dirname(__DIR__) . '/uploads/' . $subdir;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
        }
    }
}
_os_garantir_esquema_projetos($conn);

// Salva os campos de configuração pública do projeto (aba "Projeto" da O.S).
// Chamada tanto pela ação dedicada `salvar_projeto` quanto reaproveitável
// caso outra ação precise persistir os mesmos campos.
// A aba Projeto não tem cadastro próprio de título/departamento/responsável/
// datas/status — tudo isso é lido direto da O.S (única fonte de verdade).
// Esta função trata apenas o que é exclusivo do acompanhamento da obra:
// Etapa Atual e Conclusão (%). Auditoria (Parte 9 de uma refatoração
// anterior) continua registrando usuário/data/hora e valores antes → depois.
function _os_salvar_campos_projeto($conn, $os_id, $dados) {
    $temEtapaOuPercentual = array_key_exists('projeto_etapa_id', $dados) || array_key_exists('projeto_percentual', $dados);
    if (!$temEtapaOuPercentual) return ['ok' => true, 'auditoria' => null];

    $projeto_etapa_id  = !empty($dados['projeto_etapa_id']) ? (int)$dados['projeto_etapa_id'] : null;
    $projeto_percentual = isset($dados['projeto_percentual']) && $dados['projeto_percentual'] !== ''
        ? max(0, min(100, (int)$dados['projeto_percentual'])) : null;

    $resAntes = $conn->query("SELECT o.projeto_etapa_id, o.projeto_percentual, e.nome AS etapa_nome
                               FROM os_chamados o LEFT JOIN os_etapas e ON e.id = o.projeto_etapa_id
                               WHERE o.id = $os_id LIMIT 1");
    $antes = $resAntes ? $resAntes->fetch_assoc() : null;

    $sets = [];
    if (array_key_exists('projeto_etapa_id', $dados))  $sets[] = "projeto_etapa_id=" . ($projeto_etapa_id ?: 'NULL');
    if (array_key_exists('projeto_percentual', $dados)) $sets[] = "projeto_percentual=" . ($projeto_percentual !== null ? $projeto_percentual : 0);
    $ok = true;
    if ($sets) $ok = (bool)$conn->query("UPDATE os_chamados SET " . implode(',', $sets) . " WHERE tenant_id = $tenant_id AND id=$os_id");

    $auditoria = null;
    if ($antes) {
        $etapaNovaNome = null;
        if ($projeto_etapa_id) {
            $resEtapa = $conn->query("SELECT nome FROM os_etapas WHERE tenant_id = $tenant_id AND id = $projeto_etapa_id");
            $etapaNovaNome = $resEtapa ? ($resEtapa->fetch_assoc()['nome'] ?? null) : null;
        }
        $auditoria = [
            'etapa_anterior'      => $antes['etapa_nome'],
            'etapa_nova'          => $projeto_etapa_id ? $etapaNovaNome : $antes['etapa_nome'],
            'percentual_anterior' => (int)$antes['projeto_percentual'],
            'percentual_novo'     => $projeto_percentual !== null ? $projeto_percentual : (int)$antes['projeto_percentual'],
        ];
    }

    return ['ok' => $ok, 'auditoria' => $auditoria];
}

// Upload de imagem (capa do projeto ou foto de obra) — mesmas validações de
// extensão/MIME/tamanho já usadas pelo GED em api_documentos.php.
function _os_processar_upload_imagem($file, $dir) {
    $tipos_permitidos = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'];
    $exts_permitidas  = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    $limite_bytes     = 15 * 1024 * 1024; // 15 MB

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'erro' => 'Erro no upload: código ' . ($file['error'] ?? 'desconhecido')];
    }
    if ($file['size'] > $limite_bytes) return ['ok' => false, 'erro' => 'Arquivo muito grande. Limite: 15 MB.'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts_permitidas)) return ['ok' => false, 'erro' => "Tipo de arquivo .$ext não permitido."];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $tipos_permitidos)) return ['ok' => false, 'erro' => "Tipo MIME não permitido: $mime."];

    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nome_unico = uniqid('img_', true) . '.' . $ext;
    $destino    = $dir . '/' . $nome_unico;
    if (!move_uploaded_file($file['tmp_name'], $destino)) return ['ok' => false, 'erro' => 'Falha ao salvar o arquivo no servidor.'];

    _os_gerar_thumbnail($destino, $dir, $nome_unico);

    return ['ok' => true, 'arquivo' => $nome_unico, 'original' => $file['name'], 'tamanho' => (int)$file['size']];
}

// Gera uma miniatura 400x250 em WebP ao lado do original, para os cards do
// Portal/Página Pública (evita transferir a imagem em resolução total só
// para exibir um card pequeno). Best-effort: se a extensão GD não estiver
// disponível no servidor, ou a geração falhar por qualquer motivo, a
// ausência da miniatura é silenciosa — api_imagem_projeto.php cai de volta
// para o arquivo original automaticamente.
function _os_gerar_thumbnail(string $origemPath, string $dir, string $nomeArquivo): void {
    if (!function_exists('gd_info')) return;

    try {
        $info = @getimagesize($origemPath);
        if (!$info) return;
        [$largOrig, $altOrig, $tipo] = $info;

        $origem = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($origemPath),
            IMAGETYPE_PNG  => @imagecreatefrompng($origemPath),
            IMAGETYPE_GIF  => @imagecreatefromgif($origemPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($origemPath) : false,
            default => false,
        };
        if (!$origem) return;

        $largThumb = 400;
        $altThumb  = 250;

        // "object-fit: cover" manual — recorta para preencher 400x250 sem distorcer
        $escala = max($largThumb / $largOrig, $altThumb / $altOrig);
        $largRecorte = (int)round($largThumb / $escala);
        $altRecorte  = (int)round($altThumb / $escala);
        $origX = (int)round(($largOrig - $largRecorte) / 2);
        $origY = (int)round(($altOrig - $altRecorte) / 2);

        $thumb = imagecreatetruecolor($largThumb, $altThumb);
        imagecopyresampled($thumb, $origem, 0, 0, $origX, $origY, $largThumb, $altThumb, $largRecorte, $altRecorte);

        $base = pathinfo($nomeArquivo, PATHINFO_FILENAME);
        $caminhoThumb = $dir . '/' . $base . '_thumb.webp';

        if (function_exists('imagewebp')) {
            imagewebp($thumb, $caminhoThumb, 80);
        }

        imagedestroy($thumb);
        imagedestroy($origem);
    } catch (\Throwable $e) {
        error_log('Aviso: falha ao gerar thumbnail de projeto: ' . $e->getMessage());
    }
}

// Promove uma foto de obra já enviada (galeria/timeline) a capa pública do
// projeto: copia o arquivo para uploads/projetos_capas (preservando o
// original na pasta de fotos, nunca o substitui) e atualiza
// os_chamados.projeto_imagem_capa. Falha silenciosamente se o arquivo de
// origem não existir — nunca interrompe o fluxo de adicionar_interacao.
function _os_promover_foto_a_capa($conn, int $osId, string $dirOrigem, string $dirCapas, string $arquivoFoto): void {
    $nomeOrigem = basename($arquivoFoto);
    $caminhoOrigem = $dirOrigem . '/' . $nomeOrigem;
    if (!is_file($caminhoOrigem)) return;

    if (!is_dir($dirCapas)) mkdir($dirCapas, 0755, true);
    $ext = pathinfo($nomeOrigem, PATHINFO_EXTENSION);
    $novoNome = uniqid('capa_', true) . '.' . $ext;
    $destino  = $dirCapas . '/' . $novoNome;
    if (!copy($caminhoOrigem, $destino)) return;

    _os_gerar_thumbnail($destino, $dirCapas, $novoNome);

    $novoNomeEsc = $conn->real_escape_string($novoNome);
    $conn->query("UPDATE os_chamados SET projeto_imagem_capa='$novoNomeEsc' WHERE tenant_id = $tenant_id AND id=$osId");
}

// ─── Roteamento por ação ─────────────────────────────
switch ($acao) {

    // ─────────────────────────────────────────────────
    case 'migration':
        if (empty($erros_migration)) {
            retornar_json(true, 'Tabelas verificadas/criadas com sucesso');
        } else {
            retornar_json(false, 'Erros na migration', $erros_migration);
        }
        break;

    // ─────────────────────────────────────────────────
    case 'dashboard_kpis':
        $kpis = [];

        // Totais por status
        $res = $conn->query("SELECT status, COUNT(*) as total FROM os_chamados WHERE tenant_id = $tenant_id GROUP BY status");
        $por_status = ['aberto'=>0,'andamento'=>0,'finalizado'=>0,'cancelado'=>0];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $por_status[$row['status']] = (int)$row['total'];
            }
        }
        $kpis['abertos']     = $por_status['aberto'];
        $kpis['andamento']   = $por_status['andamento'];
        $kpis['finalizados'] = $por_status['finalizado'];
        $kpis['cancelados']  = $por_status['cancelado'];
        $kpis['total']       = array_sum($por_status);

        // Tempo médio de finalização (em horas)
        $res = $conn->query("SELECT AVG(horas_totais) as media FROM os_chamados WHERE tenant_id = $tenant_id AND status='finalizado' AND horas_totais IS NOT NULL");
        $row = $res ? $res->fetch_assoc() : null;
        $kpis['tempo_medio_horas'] = $row ? round((float)$row['media'], 1) : 0;

        // OS abertas hoje
        $res = $conn->query("SELECT COUNT(*) as total FROM os_chamados WHERE tenant_id = $tenant_id AND DATE(data_abertura) = CURDATE()");
        $row = $res ? $res->fetch_assoc() : null;
        $kpis['abertas_hoje'] = $row ? (int)$row['total'] : 0;

        // OS urgentes em aberto
        $res = $conn->query("SELECT COUNT(*) as total FROM os_chamados WHERE tenant_id = $tenant_id AND prioridade='urgente' AND status IN ('aberto','andamento')");
        $row = $res ? $res->fetch_assoc() : null;
        $kpis['urgentes_abertas'] = $row ? (int)$row['total'] : 0;

        // OS com prazo vencido (data_previsao < hoje e não finalizado)
        $res = $conn->query("SELECT COUNT(*) as total FROM os_chamados WHERE tenant_id = $tenant_id AND data_previsao IS NOT NULL AND data_previsao < CURDATE() AND status NOT IN ('finalizado','cancelado')");
        $row = $res ? $res->fetch_assoc() : null;
        $kpis['prazo_vencido'] = $row ? (int)$row['total'] : 0;

        // Últimas 5 OS abertas
        $res = $conn->query("SELECT id, numero, titulo, status, prioridade, departamento, DATE_FORMAT(data_abertura,'%d/%m/%Y %H:%i') as data_abertura FROM os_chamados WHERE tenant_id = $tenant_id ORDER BY id DESC LIMIT 5");
        $ultimas = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) $ultimas[] = $row;
        }
        $kpis['ultimas_os'] = $ultimas;

        // Por prioridade
        $res = $conn->query("SELECT prioridade, COUNT(*) as total FROM os_chamados WHERE tenant_id = $tenant_id AND status NOT IN ('finalizado','cancelado') GROUP BY prioridade");
        $por_prioridade = ['baixa'=>0,'media'=>0,'alta'=>0,'urgente'=>0];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $por_prioridade[$row['prioridade']] = (int)$row['total'];
            }
        }
        $kpis['por_prioridade'] = $por_prioridade;

        // Por departamento — total de OS agrupado
        $res = $conn->query("
            SELECT UPPER(TRIM(departamento)) as dep,
                   COUNT(*) as total,
                   SUM(status IN ('aberto','andamento')) as abertas,
                   SUM(status = 'finalizado') as finalizadas
            FROM os_chamados WHERE tenant_id = $tenant_id AND departamento IS NOT NULL AND TRIM(departamento) != ''
            GROUP BY UPPER(TRIM(departamento))
            ORDER BY total DESC
            LIMIT 12
        ");
        $por_departamento = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if ($row['dep'] === '') continue;
                $por_departamento[] = [
                    'departamento' => $row['dep'],
                    'total'        => (int)$row['total'],
                    'abertas'      => (int)$row['abertas'],
                    'finalizadas'  => (int)$row['finalizadas'],
                ];
            }
        }
        $kpis['por_departamento'] = $por_departamento;

        // Top 5 unidades com mais chamados (todos os status)
        $res = $conn->query("
            SELECT TRIM(morador_unidade) as unidade,
                   COUNT(*) as total,
                   SUM(status IN ('aberto','andamento')) as abertas,
                   SUM(status = 'finalizado') as finalizadas,
                   SUM(status = 'cancelado') as canceladas
            FROM os_chamados WHERE tenant_id = $tenant_id AND morador_unidade IS NOT NULL AND TRIM(morador_unidade) != ''
            GROUP BY TRIM(morador_unidade)
            ORDER BY total DESC
            LIMIT 5
        ");
        $top_unidades = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if ($row['unidade'] === '') continue;
                $top_unidades[] = [
                    'unidade'    => $row['unidade'],
                    'total'      => (int)$row['total'],
                    'abertas'    => (int)$row['abertas'],
                    'finalizadas'=> (int)$row['finalizadas'],
                    'canceladas' => (int)$row['canceladas'],
                ];
            }
        }
        $kpis['top_unidades'] = $top_unidades;

        retornar_json(true, 'KPIs carregados', $kpis);
        break;

    // ─────────────────────────────────────────────────
    case 'relatorio':
        $tipo    = preg_replace('/[^a-z_]/', '', $body['tipo'] ?? 'listagem_geral');
        $d_ini   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['data_ini'] ?? '') ? $body['data_ini'] : null;
        $d_fim   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['data_fim'] ?? '') ? $body['data_fim'] : null;
        $dep_r   = !empty($body['departamento']) ? $conn->real_escape_string(trim($body['departamento'])) : '';
        $unid_r  = !empty($body['unidade'])     ? $conn->real_escape_string(trim($body['unidade']))      : '';

        // Base WHERE parts (date + department)
        $wb = [];
        if ($d_ini) $wb[] = "DATE(data_abertura) >= '$d_ini'";
        if ($d_fim) $wb[] = "DATE(data_abertura) <= '$d_fim'";
        if ($dep_r) $wb[] = "UPPER(TRIM(departamento)) = UPPER('$dep_r')";
        $wb_str = $wb ? implode(' AND ', $wb) : '1=1';

        $wu = $unid_r ? "AND TRIM(morador_unidade) LIKE '%$unid_r%'" : '';

        $dados_rel = [];
        $sql_rel   = '';

        switch ($tipo) {

            case 'listagem_geral':
                $sql_rel = "SELECT numero, titulo,
                    COALESCE(NULLIF(TRIM(departamento),''),'—') as departamento,
                    morador_nome, morador_unidade,
                    prioridade, status, atendente_nome,
                    DATE_FORMAT(data_abertura,'%d/%m/%Y %H:%i') as abertura,
                    DATE_FORMAT(data_finalizacao,'%d/%m/%Y') as finalizacao,
                    DATEDIFF(COALESCE(data_finalizacao,CURDATE()),data_abertura) as dias,
                    COALESCE(horas_totais,'—') as horas
                FROM os_chamados WHERE tenant_id = $tenant_id AND $wb_str $wu
                ORDER BY data_abertura DESC LIMIT 500";
                break;

            case 'unidades_abertas':
                $wb2 = [];
                if ($d_ini) $wb2[] = "DATE(data_abertura) >= '$d_ini'";
                if ($d_fim) $wb2[] = "DATE(data_abertura) <= '$d_fim'";
                if ($dep_r) $wb2[] = "UPPER(TRIM(departamento)) = UPPER('$dep_r')";
                $wb2_str = $wb2 ? implode(' AND ', $wb2) : '1=1';
                $sql_rel = "SELECT TRIM(morador_unidade) as unidade,
                    MAX(morador_nome) as morador,
                    COUNT(*) as total_aberto,
                    SUM(prioridade='urgente') as urgentes,
                    SUM(prioridade='alta') as altas,
                    SUM(prioridade='media') as medias,
                    SUM(prioridade='baixa') as baixas,
                    DATE_FORMAT(MIN(data_abertura),'%d/%m/%Y') as abertura_mais_antiga
                FROM os_chamados WHERE tenant_id = $tenant_id AND status IN ('aberto','andamento')
                  AND morador_unidade IS NOT NULL AND TRIM(morador_unidade) != ''
                  AND $wb2_str $wu
                GROUP BY TRIM(morador_unidade)
                ORDER BY urgentes DESC, altas DESC, total_aberto DESC";
                break;

            case 'os_finalizadas':
                $wb3 = [];
                if ($d_ini) $wb3[] = "DATE(data_abertura) >= '$d_ini'";
                if ($d_fim) $wb3[] = "DATE(data_abertura) <= '$d_fim'";
                if ($dep_r) $wb3[] = "UPPER(TRIM(departamento)) = UPPER('$dep_r')";
                $wb3_str = $wb3 ? implode(' AND ', $wb3) : '1=1';
                $sql_rel = "SELECT numero, titulo,
                    COALESCE(NULLIF(TRIM(departamento),''),'—') as departamento,
                    morador_nome, morador_unidade,
                    prioridade, atendente_nome,
                    DATE_FORMAT(data_abertura,'%d/%m/%Y') as abertura,
                    DATE_FORMAT(data_finalizacao,'%d/%m/%Y') as finalizacao,
                    DATEDIFF(data_finalizacao,data_abertura) as dias_resolucao,
                    COALESCE(horas_estimadas,'—') as horas_estimadas,
                    COALESCE(horas_totais,'—') as horas_reais,
                    COALESCE(observacao_finalizacao,'') as observacao
                FROM os_chamados WHERE tenant_id = $tenant_id AND status='finalizado' AND $wb3_str $wu
                ORDER BY data_finalizacao DESC LIMIT 500";
                break;

            case 'por_atendente':
                $sql_rel = "SELECT
                    COALESCE(atendente_nome,'Sem atendente') as atendente,
                    COUNT(*) as total,
                    SUM(status='finalizado') as finalizadas,
                    SUM(status IN ('aberto','andamento')) as em_aberto,
                    SUM(status='cancelado') as canceladas,
                    ROUND(AVG(CASE WHEN status='finalizado' AND data_finalizacao IS NOT NULL
                        THEN DATEDIFF(data_finalizacao,data_abertura) END),1) as media_dias,
                    ROUND(SUM(COALESCE(horas_totais,0)),1) as total_horas,
                    ROUND(SUM(prioridade='urgente')) as urgentes_atendidas
                FROM os_chamados WHERE tenant_id = $tenant_id AND $wb_str $wu
                GROUP BY atendente_nome
                ORDER BY finalizadas DESC, total DESC";
                break;

            case 'por_departamento':
                $sql_rel = "SELECT
                    COALESCE(NULLIF(UPPER(TRIM(departamento)),''),'Sem departamento') as departamento,
                    COUNT(*) as total,
                    SUM(status IN ('aberto','andamento')) as em_aberto,
                    SUM(status='finalizado') as finalizadas,
                    SUM(status='cancelado') as canceladas,
                    SUM(prioridade='urgente') as urgentes,
                    ROUND(AVG(CASE WHEN status='finalizado' AND data_finalizacao IS NOT NULL
                        THEN DATEDIFF(data_finalizacao,data_abertura) END),1) as media_dias_resolucao,
                    ROUND(SUM(COALESCE(horas_totais,0)),1) as total_horas
                FROM os_chamados WHERE tenant_id = $tenant_id AND $wb_str $wu
                GROUP BY UPPER(TRIM(departamento))
                ORDER BY total DESC";
                break;

            case 'prazo_vencido':
                $wb5 = [];
                if ($dep_r) $wb5[] = "UPPER(TRIM(departamento)) = UPPER('$dep_r')";
                $wb5_str = $wb5 ? implode(' AND ', $wb5) : '1=1';
                $sql_rel = "SELECT numero, titulo,
                    COALESCE(NULLIF(TRIM(departamento),''),'—') as departamento,
                    prioridade, status, atendente_nome,
                    morador_nome, morador_unidade,
                    DATE_FORMAT(data_abertura,'%d/%m/%Y') as abertura,
                    DATE_FORMAT(data_previsao,'%d/%m/%Y') as previsao,
                    DATEDIFF(CURDATE(),data_previsao) as dias_atraso
                FROM os_chamados WHERE tenant_id = $tenant_id AND data_previsao IS NOT NULL
                  AND data_previsao < CURDATE()
                  AND status NOT IN ('finalizado','cancelado')
                  AND $wb5_str $wu
                ORDER BY dias_atraso DESC, FIELD(prioridade,'urgente','alta','media','baixa')";
                break;

            case 'tempo_resolucao':
                $sql_rel = "SELECT
                    COALESCE(NULLIF(UPPER(TRIM(departamento)),''),'Sem departamento') as departamento,
                    COUNT(*) as total_finalizadas,
                    ROUND(AVG(DATEDIFF(data_finalizacao,data_abertura)),1) as media_dias,
                    MIN(DATEDIFF(data_finalizacao,data_abertura)) as min_dias,
                    MAX(DATEDIFF(data_finalizacao,data_abertura)) as max_dias,
                    ROUND(AVG(COALESCE(horas_totais,0)),1) as media_horas,
                    ROUND(SUM(COALESCE(horas_totais,0)),1) as total_horas
                FROM os_chamados WHERE tenant_id = $tenant_id AND status='finalizado' AND data_finalizacao IS NOT NULL AND $wb_str $wu
                GROUP BY UPPER(TRIM(departamento))
                ORDER BY media_dias DESC";
                break;

            case 'ranking_ocorrencias':
                $sql_rel = "SELECT
                    COALESCE(a.nome,'Sem assunto') as assunto,
                    COALESCE(NULLIF(UPPER(TRIM(o.departamento)),''),'—') as departamento,
                    COUNT(o.id) as total,
                    SUM(o.status IN ('aberto','andamento')) as em_aberto,
                    SUM(o.status='finalizado') as finalizadas,
                    SUM(o.prioridade IN ('urgente','alta')) as alta_prioridade,
                    ROUND(AVG(CASE WHEN o.status='finalizado' AND o.data_finalizacao IS NOT NULL
                        THEN DATEDIFF(o.data_finalizacao,o.data_abertura) END),1) as media_dias
                FROM os_chamados o
                LEFT JOIN os_assuntos a ON o.assunto_id = a.id
                WHERE $wb_str $wu
                GROUP BY o.assunto_id, a.nome, UPPER(TRIM(o.departamento))
                ORDER BY total DESC
                LIMIT 30";
                break;

            default:
                retornar_json(false, 'Tipo de relatório inválido');
                break 2;
        }

        if ($sql_rel) {
            $res_rel = $conn->query($sql_rel);
            if ($res_rel) {
                while ($row = $res_rel->fetch_assoc()) $dados_rel[] = $row;
            } else {
                retornar_json(false, 'Erro na consulta: ' . $conn->error);
                break;
            }
        }
        retornar_json(true, 'Relatório gerado', ['tipo' => $tipo, 'registros' => count($dados_rel), 'dados' => $dados_rel]);
        break;

    // ─────────────────────────────────────────────────
    case 'listar':
        $status       = trim($_GET['status']       ?? $body['status']       ?? '');
        $prioridade   = trim($_GET['prioridade']   ?? $body['prioridade']   ?? '');
        $departamento = trim($_GET['departamento'] ?? $body['departamento'] ?? '');
        $busca        = trim($_GET['busca']        ?? $body['busca']        ?? '');
        $data_ini     = trim($_GET['data_ini']     ?? $body['data_ini']     ?? '');
        $data_fim     = trim($_GET['data_fim']     ?? $body['data_fim']     ?? '');
        $pagina       = max(1, (int)($_GET['pagina']     ?? 1));
        $por_pagina   = max(1, min(100, (int)($_GET['por_pagina'] ?? 25)));
        $offset       = ($pagina - 1) * $por_pagina;

        $where  = ['1=1'];
        $params = [];
        $types  = '';

        if ($status !== '') {
            $where[] = 'o.status = ?';
            $params[] = $status;
            $types .= 's';
        }
        if ($prioridade !== '') {
            $where[] = 'o.prioridade = ?';
            $params[] = $prioridade;
            $types .= 's';
        }
        if ($departamento !== '') {
            $where[] = 'o.departamento = ?';
            $params[] = $departamento;
            $types .= 's';
        }
        if ($busca !== '') {
            // Busca ampla: número, título, morador (nome), unidade do morador,
            // atendente, descrição e assunto — qualquer campo bate
            $b = '%' . $busca . '%';
            $where[] = '(o.numero LIKE ? OR o.titulo LIKE ? OR o.morador_nome LIKE ?'
                     . ' OR o.morador_unidade LIKE ? OR o.atendente_nome LIKE ?'
                     . ' OR o.descricao LIKE ? OR a.nome LIKE ?)';
            for ($i = 0; $i < 7; $i++) { $params[] = $b; $types .= 's'; }
        }
        if ($data_ini !== '') {
            $where[] = 'DATE(o.data_abertura) >= ?';
            $params[] = $data_ini;
            $types .= 's';
        }
        if ($data_fim !== '') {
            $where[] = 'DATE(o.data_abertura) <= ?';
            $params[] = $data_fim;
            $types .= 's';
        }

        $where_sql = implode(' AND ', $where);

        // Contar total (LEFT JOIN necessário para busca em assunto)
        $sql_count = "SELECT COUNT(*) as total FROM os_chamados o LEFT JOIN os_assuntos a ON o.assunto_id = a.id WHERE $where_sql";
        if (!empty($params)) {
            $stmt = $conn->prepare($sql_count);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query($sql_count);
        }
        $total = $res ? (int)$res->fetch_assoc()['total'] : 0;

        // Ordenação: abertos primeiro, depois andamento, depois finalizados;
        // dentro de cada grupo, mais recentes primeiro
        $order = "FIELD(o.status,'aberto','andamento','finalizado','cancelado'), o.id DESC";

        $sql = "SELECT o.*, a.nome as assunto_nome
                FROM os_chamados o
                LEFT JOIN os_assuntos a ON o.assunto_id = a.id
                WHERE $where_sql
                ORDER BY $order
                LIMIT ? OFFSET ?";

        $params[] = $por_pagina;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $lista = [];
        while ($row = $res->fetch_assoc()) $lista[] = $row;

        retornar_json(true, 'OS listadas', [
            'lista'      => $lista,
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => $por_pagina,
            'paginas'    => ceil($total / $por_pagina)
        ]);
        break;

    // ─────────────────────────────────────────────────
    case 'buscar':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if (!$id) retornar_json(false, 'ID inválido');

        $stmt = $conn->prepare(
            "SELECT o.*, a.nome as assunto_nome
             FROM os_chamados o
             LEFT JOIN os_assuntos a ON o.assunto_id = a.id
             WHERE o.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $os = $stmt->get_result()->fetch_assoc();
        if (!$os) retornar_json(false, 'OS não encontrada');

        // Buscar recursos humanos vinculados
        $stmt2 = $conn->prepare("SELECT * FROM os_recursos_humanos WHERE tenant_id = $tenant_id AND os_id = ? ORDER BY vinculado_em ASC");
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $rh = [];
        $res2 = $stmt2->get_result();
        while ($row = $res2->fetch_assoc()) $rh[] = $row;
        $os['recursos_humanos'] = $rh;

        // Buscar materiais
        $stmt3 = $conn->prepare("SELECT * FROM os_materiais_usados WHERE tenant_id = $tenant_id AND os_id = ? ORDER BY adicionado_em ASC");
        $stmt3->bind_param('i', $id);
        $stmt3->execute();
        $mats = [];
        $res3 = $stmt3->get_result();
        while ($row = $res3->fetch_assoc()) $mats[] = $row;
        $os['materiais'] = $mats;

        retornar_json(true, 'OS encontrada', $os);
        break;

    // ─────────────────────────────────────────────────
    case 'criar':
        $dados = array_merge($body, $_POST);

        $titulo      = trim($dados['titulo']      ?? '');
        $descricao   = trim($dados['descricao']   ?? '');
        $prioridade  = trim($dados['prioridade']  ?? 'media');
        $departamento = trim($dados['departamento'] ?? '');
        $assunto_id  = !empty($dados['assunto_id']) ? (int)$dados['assunto_id'] : null;
        $morador_id  = !empty($dados['morador_id']) ? (int)$dados['morador_id'] : null;
        $morador_nome = trim($dados['morador_nome'] ?? '');
        $morador_unidade = trim($dados['morador_unidade'] ?? '');
        $atendente_id = !empty($dados['atendente_id']) ? (int)$dados['atendente_id'] : null;
        $atendente_nome = trim($dados['atendente_nome'] ?? '');
        $horas_estimadas = !empty($dados['horas_estimadas']) ? (float)$dados['horas_estimadas'] : null;
        $data_previsao = !empty($dados['data_previsao']) ? $dados['data_previsao'] : null;
        $os_pai_id   = !empty($dados['os_pai_id']) ? (int)$dados['os_pai_id'] : null;
        $recursos_humanos = $dados['recursos_humanos'] ?? [];

        if (empty($titulo)) retornar_json(false, 'Título é obrigatório');
        if (!in_array($prioridade, ['baixa','media','alta','urgente'])) $prioridade = 'media';

        // Gerar número sequencial único: OS-YYYY-NNNN
        $ano = date('Y');
        // Busca tanto o formato antigo (OS-) quanto o novo (O.S-) para não repetir sequencial
        $res = $conn->query("SELECT MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED)) as ultimo FROM os_chamados WHERE tenant_id = $tenant_id AND numero LIKE '%{$ano}-%'");
        $row = $res ? $res->fetch_assoc() : null;
        $seq = ($row && $row['ultimo']) ? (int)$row['ultimo'] + 1 : 1;
        $numero = 'O.S-' . $ano . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

        // Usuário logado
        $usuario = obterUsuarioAutenticado();
        $criado_por_id   = $usuario ? (int)$usuario['id'] : null;
        $criado_por_nome = $usuario ? $usuario['nome'] : null;

        // INSERT com 16 colunas (sem data_abertura que usa DEFAULT CURRENT_TIMESTAMP)
        // Tipos: s=numero, s=titulo, i=assunto_id, s=departamento, s=prioridade,
        //        i=morador_id, s=morador_nome, s=morador_unidade,
        //        i=atendente_id, s=atendente_nome,
        //        s=descricao, d=horas_estimadas, s=data_previsao, i=os_pai_id,
        //        i=criado_por_id, s=criado_por_nome
        $stmt = $conn->prepare(
            "INSERT INTO os_chamados
             (numero, titulo, assunto_id, departamento, prioridade, status,
              morador_id, morador_nome, morador_unidade,
              atendente_id, atendente_nome,
              descricao, horas_estimadas, data_previsao, os_pai_id,
              criado_por_id, criado_por_nome)
             VALUES (?,?,?,?,?,'aberto',?,?,?,?,?,?,?,?,?,?,?)"
        );
        // 16 parâmetros (s=numero, s=titulo, i=assunto_id, s=departamento, s=prioridade,
        //  i=morador_id, s=morador_nome, s=morador_unidade, i=atendente_id, s=atendente_nome,
        //  s=descricao, d=horas_estimadas, s=data_previsao, i=os_pai_id, i=criado_por_id, s=criado_por_nome)
        $stmt->bind_param(
            'ssissississdsiis',
            $numero, $titulo, $assunto_id, $departamento, $prioridade,
            $morador_id, $morador_nome, $morador_unidade,
            $atendente_id, $atendente_nome,
            $descricao, $horas_estimadas, $data_previsao, $os_pai_id,
            $criado_por_id, $criado_por_nome
        );

        if (!$stmt->execute()) {
            os_log('erro', 'Erro ao criar O.S', ['error' => $conn->error, 'sql_error' => $stmt->error]);
            retornar_json(false, 'Erro ao criar O.S: ' . $stmt->error);
        }
        $os_id = $conn->insert_id;

        // Projeto Público — flag aditiva; não faz parte do INSERT original para
        // não alterar a assinatura/tipos já validados do bind_param acima.
        // Etapa/Conclusão iniciais (só existem no formulário quando o checkbox
        // está marcado) são salvos aqui também — nada a auditar ainda, é o
        // primeiro valor da O.S recém-criada.
        if (isset($dados['projeto_publico'])) {
            $projeto_publico_flag = !empty($dados['projeto_publico']) ? 1 : 0;
            $proj_etapa_id   = !empty($dados['projeto_etapa_id']) ? (int)$dados['projeto_etapa_id'] : null;
            $proj_percentual = (isset($dados['projeto_percentual']) && $dados['projeto_percentual'] !== '')
                ? max(0, min(100, (int)$dados['projeto_percentual'])) : 0;
            $stmt_proj = $conn->prepare("UPDATE os_chamados SET projeto_publico=?, projeto_etapa_id=?, projeto_percentual=? WHERE tenant_id = $tenant_id AND id=?");
            $stmt_proj->bind_param('iiii', $projeto_publico_flag, $proj_etapa_id, $proj_percentual, $os_id);
            $stmt_proj->execute();
        }

        // Vincular recursos humanos
        if (!empty($recursos_humanos) && is_array($recursos_humanos)) {
            $stmt_rh = $conn->prepare(
                "INSERT INTO os_recursos_humanos (os_id, colaborador_id, colaborador_nome, cargo, departamento) VALUES (?,?,?,?,?)"
            );
            foreach ($recursos_humanos as $rh) {
                $col_id   = (int)($rh['id'] ?? 0);
                $col_nome = $rh['nome'] ?? '';
                $col_cargo = $rh['cargo'] ?? '';
                $col_dep  = $rh['departamento'] ?? '';
                if ($col_id && $col_nome) {
                    $stmt_rh->bind_param('iisss', $os_id, $col_id, $col_nome, $col_cargo, $col_dep);
                    $stmt_rh->execute();
                }
            }
        }

        // Interação inicial automática
        $msg_inicial = "O.S criada com status **Aberto**. Prioridade: {$prioridade}.";
        $stmt_int = $conn->prepare(
            "INSERT INTO os_interacoes (os_id, tipo, mensagem, usuario_id, usuario_nome) VALUES (?,'comentario',?,?,?)"
        );
        $stmt_int->bind_param('isis', $os_id, $msg_inicial, $criado_por_id, $criado_por_nome);
        $stmt_int->execute();

        os_log('info', 'O.S criada', ['os_id' => $os_id, 'numero' => $numero]);

        // ── Gerar notificações para esta O.S ──────────────────────────────
        try {
            $api_notif = __DIR__ . '/api_notificacoes_os.php';
            if (file_exists($api_notif)) {
                // Chamar a função de geração de alertas diretamente
                $os_dados_notif = [
                    'id'             => $os_id,
                    'numero'         => $numero,
                    'titulo'         => $titulo,
                    'prioridade'     => $prioridade,
                    'departamento'   => $departamento,
                    'atendente_id'   => $atendente_id,
                    'atendente_nome' => $atendente_nome,
                    'criado_por_id'  => $criado_por_id,
                    'criado_por_nome'=> $criado_por_nome,
                    'morador_id'     => $morador_id,
                    'morador_nome'   => $morador_nome,
                    'morador_unidade'=> $morador_unidade,
                ];
                $os_dados_notif['data_previsao'] = $data_previsao;
                _os_gerar_notificacoes($conn, 'os_criada', $os_dados_notif);
                // Prioridade urgente ou alta: notificar imediatamente
                if (in_array($prioridade, ['urgente', 'alta'])) {
                    _os_gerar_notificacoes($conn, 'os_prioridade_' . $prioridade, $os_dados_notif);
                }
                // Previsão definida: notificar atendente responsável
                if ($data_previsao && $atendente_id) {
                    _os_gerar_notificacoes($conn, 'os_previsao_definida', $os_dados_notif);
                }
            }
        } catch (Exception $e_notif) {
            os_log('aviso', 'Erro ao gerar notificações: ' . $e_notif->getMessage());
        }

        $notificou_previsao = !empty($data_previsao) && !empty($atendente_id);
        retornar_json(true, "O.S {$numero} criada com sucesso!", ['id' => $os_id, 'numero' => $numero, 'notificou_previsao' => $notificou_previsao]);
        break;

    // ─────────────────────────────────────────────────
    case 'editar':
        $dados = array_merge($body, $_POST);
        $id = (int)($dados['id'] ?? $_GET['id'] ?? 0);
        if (!$id) retornar_json(false, 'ID inválido');

        $titulo      = trim($dados['titulo']      ?? '');
        $descricao   = trim($dados['descricao']   ?? '');
        $prioridade  = trim($dados['prioridade']  ?? 'media');
        $departamento = trim($dados['departamento'] ?? '');
        $assunto_id  = !empty($dados['assunto_id']) ? (int)$dados['assunto_id'] : null;
        $morador_id  = !empty($dados['morador_id']) ? (int)$dados['morador_id'] : null;
        $morador_nome = trim($dados['morador_nome'] ?? '');
        $morador_unidade = trim($dados['morador_unidade'] ?? '');
        $atendente_id = !empty($dados['atendente_id']) ? (int)$dados['atendente_id'] : null;
        $atendente_nome = trim($dados['atendente_nome'] ?? '');
        $horas_estimadas = !empty($dados['horas_estimadas']) ? (float)$dados['horas_estimadas'] : null;
        $data_previsao = !empty($dados['data_previsao']) ? $dados['data_previsao'] : null;

        if (empty($titulo)) retornar_json(false, 'Título é obrigatório');

        $stmt = $conn->prepare(
            "UPDATE os_chamados SET
             titulo=?, assunto_id=?, departamento=?, prioridade=?,
             morador_id=?, morador_nome=?, morador_unidade=?,
             atendente_id=?, atendente_nome=?,
             descricao=?, horas_estimadas=?, data_previsao=? WHERE tenant_id = $tenant_id AND id=?"
        );
        $stmt->bind_param(
            'sissississdsi',
            $titulo, $assunto_id, $departamento, $prioridade,
            $morador_id, $morador_nome, $morador_unidade,
            $atendente_id, $atendente_nome,
            $descricao, $horas_estimadas, $data_previsao, $id
        );

        if (!$stmt->execute()) {
            retornar_json(false, 'Erro ao editar OS: ' . $conn->error);
        }

        // Projeto Público — mesma flag aditiva do `criar`, atualizada separadamente.
        // Etapa/Conclusão só são tocadas quando o formulário efetivamente as
        // enviou (checkbox marcado); ao desmarcar "Publicar como Projeto", a
        // O.S apenas some do Portal/Página Pública — etapa/percentual
        // permanecem no banco como histórico (Parte 5), nunca são zerados
        // aqui. Alterações reais são auditadas (Parte 9): usuário, data/hora
        // (criado_em da nota) e valores antes → depois.
        if (isset($dados['projeto_publico'])) {
            $projeto_publico_flag = !empty($dados['projeto_publico']) ? 1 : 0;
            $temEtapaOuPercentualEditar = array_key_exists('projeto_etapa_id', $dados) || array_key_exists('projeto_percentual', $dados);

            $resAntesProj = $conn->query("SELECT o.projeto_etapa_id, o.projeto_percentual, e.nome AS etapa_nome
                                           FROM os_chamados o LEFT JOIN os_etapas e ON e.id = o.projeto_etapa_id
                                           WHERE o.id = $id LIMIT 1");
            $antesProj = $resAntesProj ? $resAntesProj->fetch_assoc() : null;
            $etapaAnteriorId    = $antesProj ? (int)$antesProj['projeto_etapa_id'] : 0;
            $etapaAnteriorNome  = $antesProj ? $antesProj['etapa_nome'] : null;
            $percentualAnterior = $antesProj ? (int)$antesProj['projeto_percentual'] : 0;

            if ($temEtapaOuPercentualEditar) {
                // Formulário enviou etapa/percentual (checkbox estava marcado) — aplica os novos valores.
                $etapaFinalId   = !empty($dados['projeto_etapa_id']) ? (int)$dados['projeto_etapa_id'] : null;
                $percentualFinal = (isset($dados['projeto_percentual']) && $dados['projeto_percentual'] !== '')
                    ? max(0, min(100, (int)$dados['projeto_percentual'])) : $percentualAnterior;
                $stmt_proj = $conn->prepare("UPDATE os_chamados SET projeto_publico=?, projeto_etapa_id=?, projeto_percentual=? WHERE tenant_id = $tenant_id AND id=?");
                $stmt_proj->bind_param('iiii', $projeto_publico_flag, $etapaFinalId, $percentualFinal, $id);
                $stmt_proj->execute();
            } else {
                // Checkbox desmarcado (ou formulário não enviou esses campos) — etapa/percentual
                // permanecem intactos no banco como histórico; só a flag pública muda.
                $conn->query("UPDATE os_chamados SET projeto_publico = $projeto_publico_flag WHERE tenant_id = $tenant_id AND id = $id");
                $etapaFinalId    = $etapaAnteriorId ?: null;
                $percentualFinal = $percentualAnterior;
            }

            if ($antesProj && $temEtapaOuPercentualEditar) {
                $etapaNovaNome = $etapaAnteriorNome;
                if ($etapaFinalId && $etapaFinalId !== $etapaAnteriorId) {
                    $resEtapaNova = $conn->query("SELECT nome FROM os_etapas WHERE tenant_id = $tenant_id AND id = $etapaFinalId");
                    $etapaNovaNome = $resEtapaNova ? ($resEtapaNova->fetch_assoc()['nome'] ?? null) : null;
                }
                if ($percentualAnterior !== $percentualFinal || $etapaAnteriorNome !== $etapaNovaNome) {
                    $usuarioProj = obterUsuarioAutenticado();
                    $uidProj   = $usuarioProj ? (int)$usuarioProj['id'] : null;
                    $unomeProj = $usuarioProj ? $usuarioProj['nome'] : 'Sistema';
                    $msgProj = sprintf(
                        'Projeto atualizado por %s. Etapa: %s → %s. Conclusão: %d%% → %d%%.',
                        $unomeProj, $etapaAnteriorNome ?: '—', $etapaNovaNome ?: '—',
                        $percentualAnterior, $percentualFinal
                    );
                    $stmtAuditProj = $conn->prepare("INSERT INTO os_interacoes (os_id, tipo, mensagem, usuario_id, usuario_nome) VALUES (?,'nota_interna',?,?,?)");
                    $stmtAuditProj->bind_param('isis', $id, $msgProj, $uidProj, $unomeProj);
                    $stmtAuditProj->execute();
                }
            }
        }

        // Atualizar recursos humanos (remove e recria)
        $recursos_humanos = $dados['recursos_humanos'] ?? null;
        if ($recursos_humanos !== null && is_array($recursos_humanos)) {
            $conn->query("DELETE FROM os_recursos_humanos WHERE tenant_id = $tenant_id AND os_id = $id");
            $stmt_rh = $conn->prepare(
                "INSERT INTO os_recursos_humanos (os_id, colaborador_id, colaborador_nome, cargo, departamento) VALUES (?,?,?,?,?)"
            );
            foreach ($recursos_humanos as $rh) {
                $col_id   = (int)($rh['id'] ?? 0);
                $col_nome = $rh['nome'] ?? '';
                $col_cargo = $rh['cargo'] ?? '';
                $col_dep  = $rh['departamento'] ?? '';
                if ($col_id && $col_nome) {
                    $stmt_rh->bind_param('iisss', $id, $col_id, $col_nome, $col_cargo, $col_dep);
                    $stmt_rh->execute();
                }
            }
        }

        os_log('info', 'OS editada', ['os_id' => $id]);
        retornar_json(true, 'OS atualizada com sucesso');
        break;

    // ─────────────────────────────────────────────────
    case 'excluir':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if (!$id) retornar_json(false, 'ID inválido');

        // Verificar se existe
        $res = $conn->query("SELECT id, numero, status FROM os_chamados WHERE tenant_id = $tenant_id AND id = $id");
        $os = $res ? $res->fetch_assoc() : null;
        if (!$os) retornar_json(false, 'OS não encontrada');
        if ($os['status'] === 'finalizado') retornar_json(false, 'Não é possível excluir uma OS finalizada');

        // Excluir dependências
        $conn->query("DELETE FROM os_interacoes WHERE tenant_id = $tenant_id AND os_id = $id");
        $conn->query("DELETE FROM os_materiais_usados WHERE tenant_id = $tenant_id AND os_id = $id");
        $conn->query("DELETE FROM os_recursos_humanos WHERE tenant_id = $tenant_id AND os_id = $id");

        $stmt = $conn->prepare("DELETE FROM os_chamados WHERE tenant_id = $tenant_id AND id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao excluir OS');

        os_log('info', 'OS excluída', ['os_id' => $id, 'numero' => $os['numero']]);
        retornar_json(true, 'OS excluída com sucesso');
        break;

    // ─────────────────────────────────────────────────
    case 'listar_interacoes':
        $os_id = (int)($_GET['os_id'] ?? $body['os_id'] ?? 0);
        if (!$os_id) retornar_json(false, 'os_id inválido');

        $stmt = $conn->prepare(
            "SELECT i.*, e.nome AS etapa_nome FROM os_interacoes i
             LEFT JOIN os_etapas e ON e.id = i.etapa_id
             WHERE i.os_id = ? ORDER BY i.criado_em ASC"
        );
        $stmt->bind_param('i', $os_id);
        $stmt->execute();
        $lista = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $lista[] = $row;

        // Anexar fotos de obra em lote (evita N+1 — uma consulta para todas as interações)
        if ($lista) {
            $ids_sql = implode(',', array_map(fn($r) => (int)$r['id'], $lista));
            $res_fotos = $conn->query("SELECT * FROM os_interacao_fotos WHERE tenant_id = $tenant_id AND interacao_id IN ($ids_sql) ORDER BY criado_em ASC");
            $fotos_por_interacao = [];
            if ($res_fotos) while ($f = $res_fotos->fetch_assoc()) $fotos_por_interacao[(int)$f['interacao_id']][] = $f;
            foreach ($lista as &$row) $row['fotos'] = $fotos_por_interacao[(int)$row['id']] ?? [];
            unset($row);
        }

        retornar_json(true, 'Interações carregadas', $lista);
        break;

    // ─────────────────────────────────────────────────
    case 'adicionar_interacao':
        $dados = array_merge($body, $_POST);
        $os_id    = (int)($dados['os_id'] ?? 0);
        $tipo     = trim($dados['tipo'] ?? 'comentario');
        $mensagem = trim($dados['mensagem'] ?? '');
        $anexos   = $dados['anexos'] ?? null;
        $etapa_id   = !empty($dados['etapa_id']) ? (int)$dados['etapa_id'] : null;
        $percentual = (isset($dados['percentual']) && $dados['percentual'] !== '') ? max(0, min(100, (int)$dados['percentual'])) : null;
        $publica    = !empty($dados['publica']) ? 1 : 0;

        if (!$os_id) retornar_json(false, 'os_id inválido');
        if (empty($mensagem)) retornar_json(false, 'Mensagem é obrigatória');
        if (!in_array($tipo, ['comentario','andamento','solucao','nota_interna'])) $tipo = 'comentario';
        if ($tipo === 'nota_interna') $publica = 0; // notas internas nunca são públicas

        // Verificar se OS existe
        $res = $conn->query("SELECT id, status, projeto_publico, projeto_etapa_id, projeto_percentual FROM os_chamados WHERE tenant_id = $tenant_id AND id = $os_id");
        $os = $res ? $res->fetch_assoc() : null;
        if (!$os) retornar_json(false, 'OS não encontrada');
        if ($os['status'] === 'finalizado') retornar_json(false, 'OS já finalizada');

        $usuario = obterUsuarioAutenticado();
        $usuario_id   = $usuario ? (int)$usuario['id'] : null;
        $usuario_nome = $usuario ? $usuario['nome'] : 'Sistema';

        // Sanitizar e serializar anexos
        $anexos_json = null;
        if (!empty($anexos) && is_array($anexos)) {
            $anexos_safe = array_map(fn($a) => [
                'nome'    => basename(strval($a['nome'] ?? '')),
                'tipo'    => strval($a['tipo'] ?? 'application/octet-stream'),
                'tamanho' => (int)($a['tamanho'] ?? 0),
                'base64'  => strval($a['base64'] ?? ''),
            ], array_slice($anexos, 0, 10)); // máx 10 anexos
            $anexos_json = json_encode($anexos_safe, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $conn->prepare(
            "INSERT INTO os_interacoes (os_id, tipo, mensagem, anexos, usuario_id, usuario_nome, etapa_id, percentual, publica) VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('isssisiii', $os_id, $tipo, $mensagem, $anexos_json, $usuario_id, $usuario_nome, $etapa_id, $percentual, $publica);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao adicionar interação: ' . $conn->error);

        $int_id = $conn->insert_id;

        // Mudar status para "andamento" ao adicionar qualquer interação (se ainda aberto)
        if ($os['status'] === 'aberto') {
            $conn->query("UPDATE os_chamados SET status='andamento', data_inicio=NOW() WHERE tenant_id = $tenant_id AND id = $os_id");
        }

        // Projeto Público: uma interação de "andamento" sempre atualiza automaticamente
        // o progresso do projeto (etapa atual + percentual), sem exigir edição manual.
        // Auditoria (Parte 9): a própria interação já registra usuário/data/hora;
        // aqui complementamos com etapa/percentual anterior → novo na mensagem.
        if ((int)$os['projeto_publico'] === 1 && $tipo === 'andamento') {
            $sets = [];
            if ($percentual !== null) $sets[] = "projeto_percentual = $percentual";
            if ($etapa_id)             $sets[] = "projeto_etapa_id = $etapa_id";
            if ($percentual === 100) {
                $sets[] = "projeto_status = 'finalizado'";
            } elseif ($percentual !== null || $etapa_id) {
                $sets[] = "projeto_status = IF(projeto_status='planejamento','execucao',projeto_status)";
            }
            if ($sets) $conn->query("UPDATE os_chamados SET " . implode(', ', $sets) . " WHERE tenant_id = $tenant_id AND id = $os_id");

            $percentualAnteriorProj = (int)($os['projeto_percentual'] ?? 0);
            $etapaAnteriorIdProj    = (int)($os['projeto_etapa_id'] ?? 0);
            if (($percentual !== null && $percentual !== $percentualAnteriorProj) || ($etapa_id && $etapa_id !== $etapaAnteriorIdProj)) {
                $etapaAnteriorNomeProj = null;
                if ($etapaAnteriorIdProj) {
                    $rEtapaAnt = $conn->query("SELECT nome FROM os_etapas WHERE tenant_id = $tenant_id AND id = $etapaAnteriorIdProj");
                    $etapaAnteriorNomeProj = $rEtapaAnt ? ($rEtapaAnt->fetch_assoc()['nome'] ?? null) : null;
                }
                $etapaNovaNomeProj = $etapaAnteriorNomeProj;
                if ($etapa_id && $etapa_id !== $etapaAnteriorIdProj) {
                    $rEtapaNova = $conn->query("SELECT nome FROM os_etapas WHERE tenant_id = $tenant_id AND id = $etapa_id");
                    $etapaNovaNomeProj = $rEtapaNova ? ($rEtapaNova->fetch_assoc()['nome'] ?? null) : null;
                }
                $msgAuditInt = sprintf(
                    'Progresso do projeto atualizado via interação por %s. Etapa: %s → %s. Conclusão: %d%% → %d%%.',
                    $usuario_nome, $etapaAnteriorNomeProj ?: '—', $etapaNovaNomeProj ?: '—',
                    $percentualAnteriorProj, $percentual !== null ? $percentual : $percentualAnteriorProj
                );
                $stmtAuditInt = $conn->prepare("INSERT INTO os_interacoes (os_id, tipo, mensagem, usuario_id, usuario_nome) VALUES (?,'nota_interna',?,?,?)");
                $stmtAuditInt->bind_param('isis', $os_id, $msgAuditInt, $usuario_id, $usuario_nome);
                $stmtAuditInt->execute();
            }
        }

        // Upload de fotos de obra (opcional, mesma requisição multipart)
        $fotos_salvas = [];
        if (!empty($_FILES['fotos']['tmp_name']) && is_array($_FILES['fotos']['tmp_name'])) {
            $dir_fotos = dirname(__DIR__) . '/uploads/projetos_fotos';
            $total_fotos = count($_FILES['fotos']['tmp_name']);
            for ($i = 0; $i < min($total_fotos, 10); $i++) {
                if (($_FILES['fotos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $file_foto = [
                    'tmp_name' => $_FILES['fotos']['tmp_name'][$i],
                    'name'     => $_FILES['fotos']['name'][$i],
                    'size'     => $_FILES['fotos']['size'][$i],
                    'error'    => $_FILES['fotos']['error'][$i],
                ];
                $up_foto = _os_processar_upload_imagem($file_foto, $dir_fotos);
                if ($up_foto['ok']) {
                    $stmt_foto = $conn->prepare("INSERT INTO os_interacao_fotos (interacao_id, arquivo, arquivo_nome_original, arquivo_tamanho) VALUES (?,?,?,?)");
                    $stmt_foto->bind_param('issi', $int_id, $up_foto['arquivo'], $up_foto['original'], $up_foto['tamanho']);
                    $stmt_foto->execute();
                    $fotos_salvas[] = $up_foto['arquivo'];
                }
            }
        }

        // "Imagem principal": a primeira foto deste envio passa a ser a capa pública do projeto.
        if ($fotos_salvas && !empty($dados['definir_capa'])) {
            _os_promover_foto_a_capa($conn, $os_id, dirname(__DIR__) . '/uploads/projetos_fotos', dirname(__DIR__) . '/uploads/projetos_capas', $fotos_salvas[0]);
        }

        os_log('info', 'Interação adicionada', ['os_id' => $os_id, 'tipo' => $tipo]);
        retornar_json(true, 'Interação adicionada com sucesso', ['id' => $int_id, 'fotos' => $fotos_salvas]);
        break;

    // ─────────────────────────────────────────────────
    case 'finalizar':
        $dados           = array_merge($body, $_POST);
        $os_id           = (int)($dados['os_id'] ?? 0);
        $horas_totais    = ($dados['horas_totais'] !== null && $dados['horas_totais'] !== '') ? (float)$dados['horas_totais'] : null;
        $horas_estimadas = !empty($dados['horas_estimadas']) ? (float)$dados['horas_estimadas'] : null;
        $data_previsao   = !empty($dados['data_previsao']) ? trim($dados['data_previsao']) : null;
        $observacao      = trim($dados['observacao_finalizacao'] ?? '');

        if (!$os_id) retornar_json(false, 'os_id inválido');
        if ($horas_totais !== null && $horas_totais < 0) retornar_json(false, 'Horas totais não podem ser negativas');

        $res = $conn->query("SELECT id, status, numero FROM os_chamados WHERE tenant_id = $tenant_id AND id = $os_id");
        $os  = $res ? $res->fetch_assoc() : null;
        if (!$os) retornar_json(false, 'O.S não encontrada');
        if ($os['status'] === 'finalizado') retornar_json(false, 'O.S já está finalizada');

        // UPDATE dinâmico: inclui horas_estimadas e data_previsao se informados
        $set_extra    = '';
        $extra_types  = '';
        $extra_values = [];
        if ($horas_estimadas !== null) { $set_extra .= ', horas_estimadas=?'; $extra_types .= 'd'; $extra_values[] = $horas_estimadas; }
        if ($data_previsao   !== null) { $set_extra .= ', data_previsao=?';   $extra_types .= 's'; $extra_values[] = $data_previsao; }

        $sql_fin = "UPDATE os_chamados SET status='finalizado', horas_totais=?, data_finalizacao=NOW(), observacao_finalizacao=?{$set_extra} WHERE tenant_id = $tenant_id AND id=?";
        $stmt    = $conn->prepare($sql_fin);
        $bind_types  = 'ds' . $extra_types . 'i';
        $bind_values = array_merge([$horas_totais, $observacao], $extra_values, [$os_id]);
        $stmt->bind_param($bind_types, ...$bind_values);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao finalizar O.S: ' . $conn->error);

        // Adicionar interação de finalização
        $usuario      = obterUsuarioAutenticado();
        $usuario_id   = $usuario ? (int)$usuario['id'] : null;
        $usuario_nome = $usuario ? $usuario['nome'] : 'Sistema';
        $msg_fin      = "O.S finalizada. Horas totais: {$horas_totais}h." . ($observacao ? " Observação: {$observacao}" : '');
        $stmt_int     = $conn->prepare(
            "INSERT INTO os_interacoes (os_id, tipo, mensagem, usuario_id, usuario_nome) VALUES (?,'solucao',?,?,?)"
        );
        $stmt_int->bind_param('isis', $os_id, $msg_fin, $usuario_id, $usuario_nome);
        $stmt_int->execute();

        // Baixar estoque automaticamente (materiais não baixados ainda)
        $res_mats = $conn->query(
            "SELECT * FROM os_materiais_usados WHERE tenant_id = $tenant_id AND os_id = $os_id AND estoque_baixado = 0"
        );
        $erros_estoque = [];
        if ($res_mats) {
            while ($mat = $res_mats->fetch_assoc()) {
                $res_prod = $conn->query("SELECT quantidade_estoque FROM produtos_estoque WHERE tenant_id = $tenant_id AND id = " . (int)$mat['produto_id']);
                if ($res_prod) {
                    $prod = $res_prod->fetch_assoc();
                    if ($prod) {
                        $nova_qtd = max(0, (float)$prod['quantidade_estoque'] - (float)$mat['quantidade']);
                        $conn->query("UPDATE produtos_estoque SET quantidade_estoque = $nova_qtd WHERE tenant_id = $tenant_id AND id = " . (int)$mat['produto_id']);
                        $conn->query("UPDATE os_materiais_usados SET estoque_baixado = 1 WHERE tenant_id = $tenant_id AND id = " . (int)$mat['id']);
                    }
                }
            }
        }

        os_log('info', 'O.S finalizada', ['os_id' => $os_id, 'numero' => $os['numero'], 'horas' => $horas_totais]);
        retornar_json(true, 'O.S finalizada com sucesso', ['erros_estoque' => $erros_estoque]);
        break;

    // ─────────────────────────────────────────────────
    // ─────────────────────────────────────────────────
    case 'assumir_portal':
        // Atendente assume uma OS aberta pelo portal e classifica prioridade + OS pai
        $dados       = array_merge($body, $_POST);
        $id          = (int)($dados['id'] ?? $_GET['id'] ?? 0);
        $prioridade  = trim($dados['prioridade'] ?? 'media');
        $os_pai_id   = !empty($dados['os_pai_id']) ? (int)$dados['os_pai_id'] : null;
        if (!$id) retornar_json(false, 'ID inválido');
        if (!in_array($prioridade, ['baixa','media','alta','urgente'])) retornar_json(false, 'Prioridade inválida');
        // Verificar se OS existe e é do portal
        $res_os = $conn->query("SELECT id, status, origem_portal, assumido_por_id FROM os_chamados WHERE tenant_id = $tenant_id AND id = $id");
        $os_row = $res_os ? $res_os->fetch_assoc() : null;
        if (!$os_row) retornar_json(false, 'OS não encontrada');
        if ($os_row['origem_portal'] !== 'portal_morador') retornar_json(false, 'Esta OS não foi aberta pelo portal do morador');
        if ($os_row['assumido_por_id']) retornar_json(false, 'Esta OS já foi assumida por outro atendente');
        $usuario = obterUsuarioAutenticado();
        $assumido_id   = $usuario ? (int)$usuario['id'] : null;
        $assumido_nome = $usuario ? $usuario['nome'] : 'Atendente';
        if (!$assumido_id) retornar_json(false, 'Usuário não autenticado');
        $stmt = $conn->prepare(
            "UPDATE os_chamados SET
                assumido_por_id = ?, assumido_por_nome = ?, data_assumido = NOW(),
                prioridade = ?, os_pai_id = ?,
                status = IF(status='aberto','andamento',status),
                data_inicio = IF(data_inicio IS NULL, NOW(), data_inicio) WHERE tenant_id = $tenant_id AND id = ?"
        );
        $stmt->bind_param('issii', $assumido_id, $assumido_nome, $prioridade, $os_pai_id, $id);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao assumir OS: ' . $conn->error);
        // Registrar interação
        $msg_assumiu = "OS assumida por {$assumido_nome}. Prioridade classificada como: {$prioridade}.";
        $stmt_int = $conn->prepare("INSERT INTO os_interacoes (os_id, tipo, mensagem, usuario_id, usuario_nome) VALUES (?,'andamento',?,?,?)");
        $stmt_int->bind_param('isis', $id, $msg_assumiu, $assumido_id, $assumido_nome);
        $stmt_int->execute();
        os_log('info', 'OS do portal assumida', ['os_id' => $id, 'atendente' => $assumido_nome, 'prioridade' => $prioridade]);
        retornar_json(true, 'OS assumida com sucesso!', ['assumido_por' => $assumido_nome, 'prioridade' => $prioridade]);
        break;
    // ─────────────────────────────────────────────────
    case 'vincular_chamado':
        $dados = array_merge($body, $_POST);
        $os_id    = (int)($dados['os_id'] ?? 0);
        $os_pai_id = (int)($dados['os_pai_id'] ?? 0);

        if (!$os_id || !$os_pai_id) retornar_json(false, 'os_id e os_pai_id são obrigatórios');
        if ($os_id === $os_pai_id) retornar_json(false, 'Uma OS não pode depender de si mesma');

        // Verificar se ambas existem
        $res = $conn->query("SELECT id FROM os_chamados WHERE tenant_id = $tenant_id AND id IN ($os_id, $os_pai_id)");
        if (!$res || $res->num_rows < 2) retornar_json(false, 'Uma ou ambas as OS não foram encontradas');

        $stmt = $conn->prepare("UPDATE os_chamados SET os_pai_id = ? WHERE tenant_id = $tenant_id AND id = ?");
        $stmt->bind_param('ii', $os_pai_id, $os_id);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao vincular OS');

        retornar_json(true, 'OS vinculada com sucesso');
        break;

    // ─────────────────────────────────────────────────
    case 'listar_departamentos':
        // Criar tabela central se ainda não existir e fazer seed inicial
        $conn->query("CREATE TABLE IF NOT EXISTS departamentos (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            nome          VARCHAR(100) NOT NULL,
            descricao     VARCHAR(255) DEFAULT NULL,
            ativo         TINYINT(1) NOT NULL DEFAULT 1,
            criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $cnt_dept = $conn->query("SELECT COUNT(*) as c FROM departamentos WHERE tenant_id = $tenant_id AND ativo=1");
        if ($cnt_dept && (int)$cnt_dept->fetch_assoc()['c'] === 0) {
            $seeds_dept = ['ADMINISTRATIVO','FINANCEIRO','JARDINAGEM','LIMPEZA','MANUTENÇÃO','PORTARIA','RONDA','SEGURANÇA','ZELADORIA'];
            $st_seed = $conn->prepare("INSERT IGNORE INTO departamentos (nome) VALUES (?)");
            foreach ($seeds_dept as $sd) { $st_seed->bind_param('s', $sd); $st_seed->execute(); }
        }
        // Retornar apenas departamentos ativos da tabela central
        $r_dept = $conn->query("SELECT nome FROM departamentos WHERE tenant_id = $tenant_id AND ativo=1 ORDER BY nome ASC");
        $todos  = [];
        if ($r_dept) while ($row = $r_dept->fetch_assoc()) $todos[] = $row['nome'];
        retornar_json(true, 'Departamentos carregados', $todos);
        break;

    case 'listar_assuntos':
        $ativo = $_GET['ativo'] ?? '1';
        $sql = "SELECT * FROM os_assuntos";
        if ($ativo !== '') $sql .= " WHERE ativo = " . ($ativo === '0' ? 0 : 1);
        $sql .= " ORDER BY nome ASC";
        $res = $conn->query($sql);
        $lista = [];
        if ($res) while ($row = $res->fetch_assoc()) $lista[] = $row;
        retornar_json(true, 'Assuntos carregados', $lista);
        break;

    // ─────────────────────────────────────────────────
    case 'criar_assunto':
        $dados = array_merge($body, $_POST);
        $nome        = trim($dados['nome']        ?? '');
        $descricao   = trim($dados['descricao']   ?? '');
        $departamento = trim($dados['departamento'] ?? '');

        if (empty($nome)) retornar_json(false, 'Nome é obrigatório');

        $stmt = $conn->prepare("INSERT INTO os_assuntos (nome, descricao, departamento) VALUES (?,?,?)");
        $stmt->bind_param('sss', $nome, $descricao, $departamento);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao criar assunto: ' . $conn->error);

        retornar_json(true, 'Assunto criado com sucesso', ['id' => $conn->insert_id]);
        break;

    // ─────────────────────────────────────────────────
    case 'editar_assunto':
        $dados = array_merge($body, $_POST);
        $id          = (int)($dados['id'] ?? $_GET['id'] ?? 0);
        $nome        = trim($dados['nome']        ?? '');
        $descricao   = trim($dados['descricao']   ?? '');
        $departamento = trim($dados['departamento'] ?? '');
        $ativo       = isset($dados['ativo']) ? (int)$dados['ativo'] : 1;

        if (!$id) retornar_json(false, 'ID inválido');
        if (empty($nome)) retornar_json(false, 'Nome é obrigatório');

        $stmt = $conn->prepare("UPDATE os_assuntos SET nome=?, descricao=?, departamento=?, ativo=? WHERE tenant_id = $tenant_id AND id=?");
        $stmt->bind_param('sssii', $nome, $descricao, $departamento, $ativo, $id);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao editar assunto');

        retornar_json(true, 'Assunto atualizado com sucesso');
        break;

    // ─────────────────────────────────────────────────
    case 'excluir_assunto':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if (!$id) retornar_json(false, 'ID inválido');

        // Verificar se está em uso
        $res = $conn->query("SELECT COUNT(*) as total FROM os_chamados WHERE tenant_id = $tenant_id AND assunto_id = $id");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && (int)$row['total'] > 0) {
            retornar_json(false, 'Assunto em uso por OS existentes. Inative-o em vez de excluir.');
        }

        $stmt = $conn->prepare("DELETE FROM os_assuntos WHERE tenant_id = $tenant_id AND id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao excluir assunto');

        retornar_json(true, 'Assunto excluído com sucesso');
        break;

    // ─────────────────────────────────────────────────
    case 'listar_config':
        $res = $conn->query(
            "SELECT c.*, a.nome as assunto_nome
             FROM os_config_homem_hora c
             LEFT JOIN os_assuntos a ON c.assunto_id = a.id
             WHERE c.ativo = 1
             ORDER BY c.descricao ASC"
        );
        $lista = [];
        if ($res) while ($row = $res->fetch_assoc()) $lista[] = $row;
        retornar_json(true, 'Configurações carregadas', $lista);
        break;

    // ─────────────────────────────────────────────────
    case 'salvar_config':
        $dados = array_merge($body, $_POST);
        $id             = !empty($dados['id']) ? (int)$dados['id'] : 0;
        $assunto_id     = !empty($dados['assunto_id']) ? (int)$dados['assunto_id'] : null;
        $descricao      = trim($dados['descricao']      ?? '');
        $horas_estimadas = (float)($dados['horas_estimadas'] ?? 1);
        $custo_hora     = (float)($dados['custo_hora']     ?? 0);

        if (empty($descricao)) retornar_json(false, 'Descrição é obrigatória');

        if ($id) {
            $stmt = $conn->prepare(
                "UPDATE os_config_homem_hora SET assunto_id=?, descricao=?, horas_estimadas=?, custo_hora=? WHERE tenant_id = $tenant_id AND id=?"
            );
            $stmt->bind_param('isddi', $assunto_id, $descricao, $horas_estimadas, $custo_hora, $id);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO os_config_homem_hora (assunto_id, descricao, horas_estimadas, custo_hora) VALUES (?,?,?,?)"
            );
            $stmt->bind_param('isdd', $assunto_id, $descricao, $horas_estimadas, $custo_hora);
        }

        if (!$stmt->execute()) retornar_json(false, 'Erro ao salvar configuração: ' . $conn->error);

        retornar_json(true, 'Configuração salva com sucesso', ['id' => $id ?: $conn->insert_id]);
        break;

    // ─────────────────────────────────────────────────
    case 'excluir_config':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if (!$id) retornar_json(false, 'ID inválido');
        $stmt = $conn->prepare("DELETE FROM os_config_homem_hora WHERE tenant_id = $tenant_id AND id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao excluir configuração');
        retornar_json(true, 'Configuração excluída com sucesso');
        break;

    // ─────────────────────────────────────────────────
    case 'listar_materiais':
        $os_id = (int)($_GET['os_id'] ?? $body['os_id'] ?? 0);
        if (!$os_id) retornar_json(false, 'os_id inválido');

        $stmt = $conn->prepare("SELECT * FROM os_materiais_usados WHERE tenant_id = $tenant_id AND os_id = ? ORDER BY adicionado_em ASC");
        $stmt->bind_param('i', $os_id);
        $stmt->execute();
        $lista = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $lista[] = $row;

        retornar_json(true, 'Materiais carregados', $lista);
        break;

    // ─────────────────────────────────────────────────
    case 'adicionar_material':
        $dados = array_merge($body, $_POST);
        $os_id         = (int)($dados['os_id'] ?? 0);
        $produto_id    = (int)($dados['produto_id'] ?? 0);
        $produto_nome  = trim($dados['produto_nome'] ?? '');
        $quantidade    = (float)($dados['quantidade'] ?? 1);
        $preco_unitario = (float)($dados['preco_unitario'] ?? 0);

        if (!$os_id || !$produto_id) retornar_json(false, 'os_id e produto_id são obrigatórios');
        if ($quantidade <= 0) retornar_json(false, 'Quantidade deve ser maior que zero');

        // Verificar se OS não está finalizada
        $res = $conn->query("SELECT status FROM os_chamados WHERE tenant_id = $tenant_id AND id = $os_id");
        $os = $res ? $res->fetch_assoc() : null;
        if (!$os) retornar_json(false, 'OS não encontrada');
        if ($os['status'] === 'finalizado') retornar_json(false, 'OS já finalizada — não é possível adicionar materiais');

        // Verificar produto e estoque disponível
        $res_prod = $conn->query("SELECT nome, preco_unitario, quantidade_estoque FROM produtos_estoque WHERE tenant_id = $tenant_id AND id = $produto_id");
        $prod = $res_prod ? $res_prod->fetch_assoc() : null;
        if (!$prod) retornar_json(false, 'Produto não encontrado no estoque');
        if ((float)$prod['quantidade_estoque'] <= 0) {
            retornar_json(false, "Estoque do produto \"{$prod['nome']}\" está zerado");
        }
        if ($quantidade > (float)$prod['quantidade_estoque']) {
            retornar_json(false, "Estoque insuficiente para \"{$prod['nome']}\" — disponível: {$prod['quantidade_estoque']}");
        }
        if (empty($produto_nome)) $produto_nome = $prod['nome'];
        if (!$preco_unitario) $preco_unitario = (float)$prod['preco_unitario'];

        $stmt = $conn->prepare(
            "INSERT INTO os_materiais_usados (os_id, produto_id, produto_nome, quantidade, preco_unitario) VALUES (?,?,?,?,?)"
        );
        $stmt->bind_param('iisdd', $os_id, $produto_id, $produto_nome, $quantidade, $preco_unitario);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao adicionar material');

        retornar_json(true, 'Material adicionado com sucesso', ['id' => $conn->insert_id]);
        break;

    // ─────────────────────────────────────────────────
    case 'remover_material':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if (!$id) retornar_json(false, 'ID inválido');

        // Verificar se já foi baixado do estoque
        $res = $conn->query("SELECT estoque_baixado, os_id FROM os_materiais_usados WHERE tenant_id = $tenant_id AND id = $id");
        $mat = $res ? $res->fetch_assoc() : null;
        if (!$mat) retornar_json(false, 'Material não encontrado');
        if ($mat['estoque_baixado']) retornar_json(false, 'Material já baixado do estoque — não pode ser removido');

        $stmt = $conn->prepare("DELETE FROM os_materiais_usados WHERE tenant_id = $tenant_id AND id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao remover material');

        retornar_json(true, 'Material removido com sucesso');
        break;

    // ─────────────────────────────────────────────────
    case 'baixar_estoque_os':
        $os_id = (int)($_GET['os_id'] ?? $body['os_id'] ?? 0);
        if (!$os_id) retornar_json(false, 'os_id inválido');

        $res_mats = $conn->query(
            "SELECT * FROM os_materiais_usados WHERE tenant_id = $tenant_id AND os_id = $os_id AND estoque_baixado = 0"
        );
        $baixados = 0;
        $erros = [];
        if ($res_mats) {
            while ($mat = $res_mats->fetch_assoc()) {
                $res_prod = $conn->query("SELECT quantidade_estoque FROM produtos_estoque WHERE tenant_id = $tenant_id AND id = " . (int)$mat['produto_id']);
                if ($res_prod) {
                    $prod = $res_prod->fetch_assoc();
                    if ($prod) {
                        $nova_qtd = max(0, (float)$prod['quantidade_estoque'] - (float)$mat['quantidade']);
                        $conn->query("UPDATE produtos_estoque SET quantidade_estoque = $nova_qtd WHERE tenant_id = $tenant_id AND id = " . (int)$mat['produto_id']);
                        $conn->query("UPDATE os_materiais_usados SET estoque_baixado = 1 WHERE tenant_id = $tenant_id AND id = " . (int)$mat['id']);
                        $baixados++;
                    } else {
                        $erros[] = 'Produto ID ' . $mat['produto_id'] . ' não encontrado';
                    }
                }
            }
        }

        retornar_json(true, "$baixados material(is) baixado(s) do estoque", ['baixados' => $baixados, 'erros' => $erros]);
        break;

    // =====================================================
    // MÓDULO PROJETOS
    // =====================================================

    // ─────────────────────────────────────────────────
    case 'salvar_projeto':
        $dados = array_merge($body, $_POST);
        $os_id = (int)($dados['os_id'] ?? $dados['id'] ?? 0);
        if (!$os_id) retornar_json(false, 'os_id inválido');

        $res = $conn->query("SELECT id FROM os_chamados WHERE tenant_id = $tenant_id AND id = $os_id");
        if (!$res || $res->num_rows === 0) retornar_json(false, 'O.S não encontrada');

        $resultado = _os_salvar_campos_projeto($conn, $os_id, $dados);
        if (!$resultado['ok']) {
            retornar_json(false, 'Erro ao salvar informações do projeto: ' . $conn->error);
        }

        // Auditoria: só grava nota quando Etapa/Conclusão realmente mudaram —
        // reaproveita o próprio histórico de interações (nota interna, já
        // oculta do Portal/página pública), evitando ruído e tabela de log nova.
        $aud = $resultado['auditoria'];
        if ($aud && ($aud['percentual_anterior'] !== $aud['percentual_novo'] || $aud['etapa_anterior'] !== $aud['etapa_nova'])) {
            $usuario = obterUsuarioAutenticado();
            $uid   = $usuario ? (int)$usuario['id'] : null;
            $unome = $usuario ? $usuario['nome'] : 'Sistema';
            $msg_audit = sprintf(
                'Progresso do projeto atualizado por %s. Etapa: %s → %s. Conclusão: %d%% → %d%%.',
                $unome, $aud['etapa_anterior'] ?: '—', $aud['etapa_nova'] ?: '—',
                $aud['percentual_anterior'], $aud['percentual_novo']
            );
            $stmt_audit = $conn->prepare("INSERT INTO os_interacoes (os_id, tipo, mensagem, usuario_id, usuario_nome) VALUES (?,'nota_interna',?,?,?)");
            $stmt_audit->bind_param('isis', $os_id, $msg_audit, $uid, $unome);
            $stmt_audit->execute();
        }

        os_log('info', 'Projeto salvo', ['os_id' => $os_id, 'auditoria' => $aud]);
        retornar_json(true, 'Informações do projeto salvas com sucesso');
        break;

    // ─────────────────────────────────────────────────
    case 'upload_imagem_capa':
        $os_id = (int)($_POST['os_id'] ?? 0);
        if (!$os_id) retornar_json(false, 'os_id inválido');
        if (empty($_FILES['imagem']['tmp_name'])) retornar_json(false, 'Nenhuma imagem enviada');

        $res = $conn->query("SELECT id FROM os_chamados WHERE tenant_id = $tenant_id AND id = $os_id");
        if (!$res || $res->num_rows === 0) retornar_json(false, 'O.S não encontrada');

        $dir = dirname(__DIR__) . '/uploads/projetos_capas';
        $up  = _os_processar_upload_imagem($_FILES['imagem'], $dir);
        if (!$up['ok']) retornar_json(false, $up['erro']);

        $stmt = $conn->prepare("UPDATE os_chamados SET projeto_imagem_capa=? WHERE tenant_id = $tenant_id AND id=?");
        $stmt->bind_param('si', $up['arquivo'], $os_id);
        $stmt->execute();

        os_log('info', 'Imagem de capa atualizada', ['os_id' => $os_id]);
        retornar_json(true, 'Imagem de capa atualizada com sucesso', ['arquivo' => $up['arquivo']]);
        break;

    // ─────────────────────────────────────────────────
    case 'upload_foto_interacao':
        $interacao_id = (int)($_POST['interacao_id'] ?? 0);
        if (!$interacao_id) retornar_json(false, 'interacao_id inválido');

        $res = $conn->query("SELECT id, os_id FROM os_interacoes WHERE tenant_id = $tenant_id AND id = $interacao_id");
        $interacaoRow = $res ? $res->fetch_assoc() : null;
        if (!$interacaoRow) retornar_json(false, 'Interação não encontrada');

        $dir = dirname(__DIR__) . '/uploads/projetos_fotos';
        $salvas = [];
        $arquivos = $_FILES['fotos'] ?? null;
        if ($arquivos && is_array($arquivos['tmp_name'])) {
            $total = count($arquivos['tmp_name']);
            for ($i = 0; $i < min($total, 10); $i++) {
                if (($arquivos['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $file = [
                    'tmp_name' => $arquivos['tmp_name'][$i],
                    'name'     => $arquivos['name'][$i],
                    'size'     => $arquivos['size'][$i],
                    'error'    => $arquivos['error'][$i],
                ];
                $up = _os_processar_upload_imagem($file, $dir);
                if ($up['ok']) {
                    $stmt = $conn->prepare("INSERT INTO os_interacao_fotos (interacao_id, arquivo, arquivo_nome_original, arquivo_tamanho) VALUES (?,?,?,?)");
                    $stmt->bind_param('issi', $interacao_id, $up['arquivo'], $up['original'], $up['tamanho']);
                    $stmt->execute();
                    $salvas[] = $up['arquivo'];
                }
            }
        }
        if (!$salvas) retornar_json(false, 'Nenhuma foto válida enviada (verifique extensão/tamanho — máx. 15MB, png/jpg/gif/webp).');

        // "Imagem principal": a primeira foto deste envio passa a ser a capa
        // pública do projeto — cópia física para uploads/projetos_capas
        // (mantém a foto original intacta na galeria/timeline).
        if (!empty($_POST['definir_capa'])) {
            _os_promover_foto_a_capa($conn, (int)$interacaoRow['os_id'], $dir, dirname(__DIR__) . '/uploads/projetos_capas', $salvas[0]);
        }

        os_log('info', 'Fotos de obra enviadas', ['interacao_id' => $interacao_id, 'total' => count($salvas)]);
        retornar_json(true, count($salvas) . ' foto(s) enviada(s) com sucesso', ['arquivos' => $salvas]);
        break;

    // ─────────────────────────────────────────────────
    case 'listar_etapas':
        $ativo = $_GET['ativo'] ?? '1';
        $sql = "SELECT * FROM os_etapas";
        if ($ativo !== '') $sql .= " WHERE ativo = " . ($ativo === '0' ? 0 : 1);
        $sql .= " ORDER BY ordem ASC, nome ASC";
        $res = $conn->query($sql);
        $lista = [];
        if ($res) while ($row = $res->fetch_assoc()) $lista[] = $row;
        retornar_json(true, 'Etapas carregadas', $lista);
        break;

    // ─────────────────────────────────────────────────
    case 'criar_etapa':
        $dados = array_merge($body, $_POST);
        $nome  = trim($dados['nome']  ?? '');
        $ordem = (int)($dados['ordem'] ?? 0);
        if (empty($nome)) retornar_json(false, 'Nome é obrigatório');

        $stmt = $conn->prepare("INSERT INTO os_etapas (nome, ordem) VALUES (?,?)");
        $stmt->bind_param('si', $nome, $ordem);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao criar etapa: ' . $conn->error);

        retornar_json(true, 'Etapa criada com sucesso', ['id' => $conn->insert_id]);
        break;

    // ─────────────────────────────────────────────────
    case 'editar_etapa':
        $dados = array_merge($body, $_POST);
        $id    = (int)($dados['id'] ?? $_GET['id'] ?? 0);
        $nome  = trim($dados['nome']  ?? '');
        $ordem = (int)($dados['ordem'] ?? 0);
        $ativo = isset($dados['ativo']) ? (int)$dados['ativo'] : 1;

        if (!$id) retornar_json(false, 'ID inválido');
        if (empty($nome)) retornar_json(false, 'Nome é obrigatório');

        $stmt = $conn->prepare("UPDATE os_etapas SET nome=?, ordem=?, ativo=? WHERE tenant_id = $tenant_id AND id=?");
        $stmt->bind_param('siii', $nome, $ordem, $ativo, $id);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao editar etapa');

        retornar_json(true, 'Etapa atualizada com sucesso');
        break;

    // ─────────────────────────────────────────────────
    case 'excluir_etapa':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if (!$id) retornar_json(false, 'ID inválido');

        $res = $conn->query("SELECT COUNT(*) as total FROM os_interacoes WHERE tenant_id = $tenant_id AND etapa_id = $id");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && (int)$row['total'] > 0) {
            retornar_json(false, 'Etapa em uso por interações existentes. Inative-a em vez de excluir.');
        }

        $stmt = $conn->prepare("DELETE FROM os_etapas WHERE tenant_id = $tenant_id AND id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) retornar_json(false, 'Erro ao excluir etapa');

        retornar_json(true, 'Etapa excluída com sucesso');
        break;

    // ─────────────────────────────────────────────────
    case 'listar_documentos_projeto':
        $os_id = (int)($_GET['os_id'] ?? 0);
        if (!$os_id) retornar_json(false, 'os_id inválido');

        $tab_doc = $conn->query("SHOW TABLES LIKE 'documentos'");
        if (!$tab_doc || $tab_doc->num_rows === 0) retornar_json(true, 'OK', []);

        $stmt = $conn->prepare("SELECT id, nome, descricao, status FROM documentos WHERE tenant_id = $tenant_id AND os_id = ? ORDER BY nome ASC");
        $stmt->bind_param('i', $os_id);
        $stmt->execute();
        $lista = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $lista[] = $row;

        retornar_json(true, 'Documentos vinculados carregados', $lista);
        break;

    // ─────────────────────────────────────────────────
    case 'vincular_documentos_projeto':
        $dados   = array_merge($body, $_POST);
        $os_id   = (int)($dados['os_id'] ?? 0);
        $doc_ids = $dados['documento_ids'] ?? [];
        if (!$os_id) retornar_json(false, 'os_id inválido');
        if (!is_array($doc_ids)) $doc_ids = [];
        $doc_ids = array_values(array_unique(array_filter(array_map('intval', $doc_ids))));

        $tab_doc = $conn->query("SHOW TABLES LIKE 'documentos'");
        if (!$tab_doc || $tab_doc->num_rows === 0) retornar_json(false, 'Módulo de documentos (GED) não disponível.');

        // Substituição total: desvincula tudo que estava e revincula apenas os selecionados
        $conn->query("UPDATE documentos SET os_id = NULL WHERE tenant_id = $tenant_id AND os_id = $os_id");
        if (!empty($doc_ids)) {
            $ids_sql = implode(',', $doc_ids);
            $conn->query("UPDATE documentos SET os_id = $os_id WHERE tenant_id = $tenant_id AND id IN ($ids_sql)");
        }

        retornar_json(true, 'Documentos vinculados ao projeto com sucesso');
        break;

    // ─────────────────────────────────────────────────
    default:
        os_log('aviso', 'Ação inválida', ['acao' => $acao]);
        retornar_json(false, "Ação inválida: '$acao'");
        break;
}
