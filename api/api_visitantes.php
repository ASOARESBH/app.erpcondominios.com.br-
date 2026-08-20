<?php
// =====================================================
// API PARA CRUD DE VISITANTES v2
// Suporte: foto, documento_arquivo, placa_veiculo,
//          telefone_contato, busca por RG/CPF
// =====================================================

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/tenant_file_storage_helper.php';
require_once __DIR__ . '/helpers/cpf_helper.php';

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Nenhuma exceção deve encerrar a conexão com corpo vazio. O frontend sempre
// recebe JSON, inclusive em uma falha inesperada de banco ou arquivo.
set_exception_handler(function (Throwable $erro) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('[Visitantes][Fatal] ' . $erro->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Não foi possível concluir a operação de visitantes. Tente novamente ou contate o administrador.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$usuarioAutenticado = verificarAutenticacao(true, 'operador');
$tenant_id = (int)$usuarioAutenticado['tenant_id'];

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();

// Armazenamento físico removido: fotos e documentos são persistidos em tenant_arquivos.
// Os campos da tabela mantêm URLs legadas apenas como chave de compatibilidade.

/**
 * Valida e persiste um anexo opcional do cadastro inicial de visitante.
 * O cadastro exige ao menos um anexo, verificado após o processamento de foto e documento.
 */
function gravar_anexo_visitante_se_enviado($conexao, $tenant_id, $campo, $tipo_upload, $visitante_id) {
    $arquivo = $_FILES[$campo] ?? null;
    if (!$arquivo || (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int)($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no envio do arquivo de ' . $tipo_upload . '.');
    }

    $extensoesPermitidas = $tipo_upload === 'foto'
        ? ['jpg', 'jpeg', 'png', 'webp']
        : ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
    $extensao = strtolower(pathinfo((string)$arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($extensao, $extensoesPermitidas, true)) {
        throw new RuntimeException('Formato de ' . $tipo_upload . ' não permitido.');
    }
    if ((int)$arquivo['size'] <= 0 || (int)$arquivo['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('O arquivo de ' . $tipo_upload . ' deve ter até 5MB.');
    }

    $prefixo = $tipo_upload === 'foto' ? 'foto' : 'doc';
    $caminhoLegado = 'uploads/visitantes/' . ($tipo_upload === 'foto' ? 'fotos' : 'documentos')
        . '/' . $prefixo . '_' . (int)$visitante_id . '_' . time() . '.' . $extensao;
    $arquivoBanco = tenant_file_gravar_upload(
        $conexao,
        (int)$tenant_id,
        $arquivo,
        $tipo_upload === 'foto' ? 'visitante_foto' : 'visitante_documento',
        $caminhoLegado,
        false
    );

    return '../' . $arquivoBanco['caminho_legado'];
}

// ========== UPLOAD DE FOTO / DOCUMENTO ==========
if ($metodo === 'POST' && isset($_GET['acao']) && $_GET['acao'] === 'upload') {
    verificarPermissao('operador');

    $tipo_upload = $_GET['tipo'] ?? 'foto'; // 'foto' ou 'documento'
    $visitante_id = intval($_GET['visitante_id'] ?? 0);

    if ($visitante_id <= 0) retornar_json(false, "ID do visitante inválido");

    // Impede BLOBs órfãos e garante que o anexo pertença ao visitante deste tenant.
    $stmtVisitante = $conexao->prepare('SELECT id FROM visitantes WHERE tenant_id = ? AND id = ? LIMIT 1');
    if (!$stmtVisitante) retornar_json(false, 'Erro ao validar o visitante para receber o anexo.');
    $stmtVisitante->bind_param('ii', $tenant_id, $visitante_id);
    $stmtVisitante->execute();
    $visitanteExiste = $stmtVisitante->get_result()->fetch_assoc();
    $stmtVisitante->close();
    if (!$visitanteExiste) retornar_json(false, 'Visitante não encontrado no condomínio atual.');

    $campo_arquivo = ($tipo_upload === 'foto') ? $_FILES['foto'] ?? null : $_FILES['documento'] ?? null;
    if (!$campo_arquivo || $campo_arquivo['error'] !== UPLOAD_ERR_OK) {
        retornar_json(false, "Nenhum arquivo enviado ou erro no upload");
    }

    $ext_permitidas = ($tipo_upload === 'foto')
        ? ['jpg', 'jpeg', 'png', 'webp']
        : ['jpg', 'jpeg', 'png', 'pdf', 'webp'];

    $ext = strtolower(pathinfo($campo_arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ext_permitidas)) {
        retornar_json(false, "Formato de arquivo não permitido. Use: " . implode(', ', $ext_permitidas));
    }

    // Limite de 5MB
    if ($campo_arquivo['size'] > 5 * 1024 * 1024) {
        retornar_json(false, "Arquivo muito grande. Máximo: 5MB");
    }

    $nome_arquivo = ($tipo_upload === 'foto' ? 'foto' : 'doc') . '_' . $visitante_id . '_' . time() . '.' . $ext;
    $caminho_legado = ($tipo_upload === 'foto') ? 'uploads/visitantes/fotos/' . $nome_arquivo : 'uploads/visitantes/documentos/' . $nome_arquivo;
    try {
        $arquivoBanco = tenant_file_gravar_upload($conexao, (int)$tenant_id, $campo_arquivo, $tipo_upload === 'foto' ? 'visitante_foto' : 'visitante_documento', $caminho_legado, false);
        $url_relativa = '../' . $arquivoBanco['caminho_legado'];
    } catch (Throwable $e) {
        error_log('[Visitantes][Arquivo] tenant=' . $tenant_id . ' erro=' . $e->getMessage());
        retornar_json(false, 'Erro ao armazenar arquivo no banco');
    }

    // Atualizar o campo no banco
    $campo_db = ($tipo_upload === 'foto') ? 'foto' : 'documento_arquivo';
    $stmt = $conexao->prepare("UPDATE visitantes SET $campo_db = ? WHERE tenant_id = $tenant_id AND id = ?");
    $stmt->bind_param("si", $url_relativa, $visitante_id);
    if (!$stmt->execute()) {
        retornar_json(false, "Arquivo salvo mas erro ao atualizar banco: " . $stmt->error);
    }
    $stmt->close();

    registrar_log('INFO', "Upload $tipo_upload visitante ID $visitante_id: $nome_arquivo");
    retornar_json(true, "Arquivo enviado com sucesso", ['url' => $url_relativa, 'nome' => $nome_arquivo]);
}

// ========== BUSCAR POR DOCUMENTO (RG ou CPF) — para o módulo de Registro ==========
if ($metodo === 'GET' && isset($_GET['documento'])) {
    $doc = sanitizar($conexao, trim($_GET['documento']));
    $doc_limpo = preg_replace('/[^0-9A-Za-z]/', '', $doc);

    $stmt = $conexao->prepare(
        "SELECT id, nome_completo, documento, tipo_documento, telefone, celular, telefone_contato,
                placa_veiculo, foto, documento_arquivo, observacao, ativo,
                cadastrado_por_nome, cadastrado_por_tipo, cadastrado_por_usuario_id, cadastrado_por_morador_id
         FROM visitantes WHERE tenant_id = $tenant_id AND REPLACE(REPLACE(REPLACE(documento, '.', ''), '-', ''), '/', '') = ?
            OR documento = ?
         LIMIT 1"
    );
    $stmt->bind_param("ss", $doc_limpo, $doc);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        retornar_json(true, "Visitante encontrado", $row);
    } else {
        retornar_json(false, "Visitante não encontrado com este documento");
    }
}

// ========== RELATÓRIO ANALÍTICO DE ACESSOS ==========
// Fonte: registros_acesso. O relatório usa somente eventos do tenant autenticado,
// com Visitante e Prestador registrados pelo controle de acesso manual.
if ($metodo === 'GET' && ($_GET['acao'] ?? '') === 'analitico_acessos') {
    $dataInicio = trim((string)($_GET['data_inicio'] ?? date('Y-m-01')));
    $dataFim = trim((string)($_GET['data_fim'] ?? date('Y-m-d')));
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dataInicio)) $dataInicio = date('Y-m-01');
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dataFim)) $dataFim = date('Y-m-d');
    if ($dataInicio > $dataFim) retornar_json(false, 'A data inicial não pode ser posterior à data final.');

    $wherePeriodo = "tenant_id = ? AND data_hora >= ? AND data_hora < DATE_ADD(?, INTERVAL 1 DAY) AND tipo IN ('Visitante', 'Prestador')";
    $tiposPeriodo = 'iss';
    $paramsPeriodo = [$tenant_id, $dataInicio, $dataFim];

    // Consolidado estratégico do período selecionado.
    $sqlResumo = "SELECT
        COUNT(*) AS total_acessos,
        SUM(tipo = 'Visitante') AS visitantes,
        SUM(tipo = 'Prestador') AS prestadores,
        SUM(tipo_acesso = 'Entrada') AS entradas,
        SUM(tipo_acesso = 'Saída') AS saidas,
        SUM(liberado = 1) AS liberados,
        SUM(liberado = 0) AS pendentes
        FROM registros_acesso WHERE $wherePeriodo";
    $stmtResumo = $conexao->prepare($sqlResumo);
    if (!$stmtResumo) retornar_json(false, 'Não foi possível preparar o resumo analítico.');
    $stmtResumo->bind_param($tiposPeriodo, ...$paramsPeriodo);
    $stmtResumo->execute();
    $resumo = $stmtResumo->get_result()->fetch_assoc() ?: [];
    $stmtResumo->close();
    foreach (['total_acessos', 'visitantes', 'prestadores', 'entradas', 'saidas', 'liberados', 'pendentes'] as $campo) {
        $resumo[$campo] = (int)($resumo[$campo] ?? 0);
    }

    // Horário de pico das últimas 24 horas, independentemente do período selecionado.
    $sqlPico = "SELECT HOUR(data_hora) AS hora, COUNT(*) AS total
                FROM registros_acesso
                WHERE tenant_id = ?
                  AND data_hora >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  AND tipo IN ('Visitante', 'Prestador')
                GROUP BY HOUR(data_hora)
                ORDER BY total DESC, hora ASC
                LIMIT 1";
    $stmtPico = $conexao->prepare($sqlPico);
    if (!$stmtPico) retornar_json(false, 'Não foi possível preparar o horário de pico.');
    $stmtPico->bind_param('i', $tenant_id);
    $stmtPico->execute();
    $pico24h = $stmtPico->get_result()->fetch_assoc();
    $stmtPico->close();
    $horarioPico = $pico24h ? [
        'hora' => (int)$pico24h['hora'],
        'rotulo' => str_pad((string)$pico24h['hora'], 2, '0', STR_PAD_LEFT) . ':00',
        'total' => (int)$pico24h['total'],
    ] : ['hora' => null, 'rotulo' => 'Sem registros', 'total' => 0];

    $histograma24h = [];
    $sqlHistograma = "SELECT HOUR(data_hora) AS hora, COUNT(*) AS total
                      FROM registros_acesso
                      WHERE tenant_id = ?
                        AND data_hora >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        AND tipo IN ('Visitante', 'Prestador')
                      GROUP BY HOUR(data_hora)
                      ORDER BY hora ASC";
    $stmtHistograma = $conexao->prepare($sqlHistograma);
    if (!$stmtHistograma) retornar_json(false, 'Não foi possível preparar a distribuição horária.');
    $stmtHistograma->bind_param('i', $tenant_id);
    $stmtHistograma->execute();
    $resHistograma = $stmtHistograma->get_result();
    while ($linha = $resHistograma->fetch_assoc()) {
        $histograma24h[] = ['hora' => (int)$linha['hora'], 'total' => (int)$linha['total']];
    }
    $stmtHistograma->close();

    // Ranking das glebas/unidades mais acessadas no período.
    $rankingUnidades = [];
    $sqlUnidades = "SELECT COALESCE(NULLIF(TRIM(unidade_destino), ''), 'Não informado') AS unidade,
                           COUNT(*) AS total,
                           SUM(tipo = 'Visitante') AS visitantes,
                           SUM(tipo = 'Prestador') AS prestadores
                    FROM registros_acesso
                    WHERE $wherePeriodo
                    GROUP BY COALESCE(NULLIF(TRIM(unidade_destino), ''), 'Não informado')
                    ORDER BY total DESC, unidade ASC
                    LIMIT 10";
    $stmtUnidades = $conexao->prepare($sqlUnidades);
    if (!$stmtUnidades) retornar_json(false, 'Não foi possível preparar o ranking de unidades.');
    $stmtUnidades->bind_param($tiposPeriodo, ...$paramsPeriodo);
    $stmtUnidades->execute();
    $resUnidades = $stmtUnidades->get_result();
    while ($linha = $resUnidades->fetch_assoc()) {
        $rankingUnidades[] = [
            'unidade' => $linha['unidade'],
            'total' => (int)$linha['total'],
            'visitantes' => (int)$linha['visitantes'],
            'prestadores' => (int)$linha['prestadores'],
        ];
    }
    $stmtUnidades->close();

    // Pessoas mais registradas, útil para identificar recorrência de prestadores e visitantes.
    $rankingPessoas = [];
    $sqlPessoas = "SELECT COALESCE(NULLIF(TRIM(nome_visitante), ''), 'Não identificado') AS nome,
                           tipo, COUNT(*) AS total
                    FROM registros_acesso
                    WHERE $wherePeriodo
                    GROUP BY COALESCE(NULLIF(TRIM(nome_visitante), ''), 'Não identificado'), tipo
                    ORDER BY total DESC, nome ASC
                    LIMIT 10";
    $stmtPessoas = $conexao->prepare($sqlPessoas);
    if (!$stmtPessoas) retornar_json(false, 'Não foi possível preparar o ranking de acessos.');
    $stmtPessoas->bind_param($tiposPeriodo, ...$paramsPeriodo);
    $stmtPessoas->execute();
    $resPessoas = $stmtPessoas->get_result();
    while ($linha = $resPessoas->fetch_assoc()) {
        $rankingPessoas[] = ['nome' => $linha['nome'], 'tipo' => $linha['tipo'], 'total' => (int)$linha['total']];
    }
    $stmtPessoas->close();

    // Tendência diária estratégica, limitada a 31 dias mais recentes do período.
    $tendenciaDiaria = [];
    $sqlTendencia = "SELECT DATE(data_hora) AS data_ordem, DATE_FORMAT(data_hora, '%d/%m') AS data,
                            COUNT(*) AS total,
                            SUM(tipo = 'Visitante') AS visitantes,
                            SUM(tipo = 'Prestador') AS prestadores
                     FROM registros_acesso
                     WHERE $wherePeriodo
                     GROUP BY DATE(data_hora), DATE_FORMAT(data_hora, '%d/%m')
                     ORDER BY data_ordem DESC
                     LIMIT 31";
    $stmtTendencia = $conexao->prepare($sqlTendencia);
    if (!$stmtTendencia) retornar_json(false, 'Não foi possível preparar a tendência de acessos.');
    $stmtTendencia->bind_param($tiposPeriodo, ...$paramsPeriodo);
    $stmtTendencia->execute();
    $resTendencia = $stmtTendencia->get_result();
    while ($linha = $resTendencia->fetch_assoc()) {
        $tendenciaDiaria[] = [
            'data' => $linha['data'],
            'total' => (int)$linha['total'],
            'visitantes' => (int)$linha['visitantes'],
            'prestadores' => (int)$linha['prestadores'],
        ];
    }
    $stmtTendencia->close();

    retornar_json(true, 'Relatório analítico de acessos gerado.', [
        'periodo' => ['data_inicio' => $dataInicio, 'data_fim' => $dataFim],
        'resumo' => $resumo,
        'horario_pico_24h' => $horarioPico,
        'histograma_24h' => $histograma24h,
        'ranking_unidades' => $rankingUnidades,
        'ranking_pessoas' => $rankingPessoas,
        'tendencia_diaria' => $tendenciaDiaria,
    ]);
}

// ========== LISTAR VISITANTES ==========
if ($metodo === 'GET') {
    $busca = isset($_GET['busca']) ? sanitizar($conexao, $_GET['busca']) : '';

    // O nome exibido prioriza a autoria gravada no momento do cadastro e, para
    // registros migrados, resolve o autor pelos IDs persistidos. Sem evidência
    // verificável, o registro continua corretamente identificado como legado.
    $sql = "SELECT v.id, v.nome_completo, v.documento, v.tipo_documento,
                   v.telefone, v.celular, v.telefone_contato, v.email,
                   v.placa_veiculo, v.foto, v.documento_arquivo,
                   v.cep, v.endereco, v.numero, v.complemento, v.bairro, v.cidade, v.estado,
                   v.observacao, v.ativo,
                   CASE
                       WHEN v.cadastrado_por_tipo = 'FUNCIONARIO' OR v.cadastrado_por_usuario_id IS NOT NULL
                           THEN 'FUNCIONARIO'
                       WHEN v.cadastrado_por_tipo = 'MORADOR' OR v.cadastrado_por_morador_id IS NOT NULL OR v.morador_id IS NOT NULL
                           THEN 'MORADOR'
                       ELSE 'LEGADO'
                   END AS cadastrado_por_tipo,
                   CASE
                       WHEN v.cadastrado_por_tipo = 'FUNCIONARIO' OR v.cadastrado_por_usuario_id IS NOT NULL
                           THEN COALESCE(NULLIF(NULLIF(v.cadastrado_por_nome, ''), 'Cadastro legado'), NULLIF(u.nome, ''), 'Usuário não identificado')
                       WHEN v.cadastrado_por_tipo = 'MORADOR' OR v.cadastrado_por_morador_id IS NOT NULL OR v.morador_id IS NOT NULL
                           THEN COALESCE(NULLIF(NULLIF(v.cadastrado_por_nome, ''), 'Cadastro legado'), NULLIF(m_autor.nome, ''), NULLIF(m_legado.nome, ''), 'Morador não identificado')
                       ELSE COALESCE(NULLIF(v.cadastrado_por_nome, ''), 'Cadastro legado')
                   END AS cadastrado_por_nome,
                   v.cadastrado_por_usuario_id,
                   COALESCE(v.cadastrado_por_morador_id, v.morador_id) AS cadastrado_por_morador_id,
                   DATE_FORMAT(v.data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro_formatada
            FROM visitantes v
            LEFT JOIN usuarios u
                   ON u.id = v.cadastrado_por_usuario_id
            LEFT JOIN moradores m_autor
                   ON m_autor.id = v.cadastrado_por_morador_id
                  AND m_autor.tenant_id = v.tenant_id
            LEFT JOIN moradores m_legado
                   ON m_legado.id = v.morador_id
                  AND m_legado.tenant_id = v.tenant_id
            WHERE v.tenant_id = ?";

    $tipos = 'i';
    $parametros = [$tenant_id];
    if ($busca !== '') {
        $buscaLike = '%' . $busca . '%';
        $sql .= " AND (v.nome_completo LIKE ? OR v.documento LIKE ? OR v.telefone_contato LIKE ? OR v.placa_veiculo LIKE ?)";
        $tipos .= 'ssss';
        $parametros[] = $buscaLike;
        $parametros[] = $buscaLike;
        $parametros[] = $buscaLike;
        $parametros[] = $buscaLike;
    }
    $sql .= " ORDER BY v.nome_completo ASC";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) retornar_json(false, 'Não foi possível preparar a listagem de visitantes.');
    $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $visitantes = [];
    while ($resultado && ($row = $resultado->fetch_assoc())) {
        $visitantes[] = $row;
    }
    $stmt->close();

    retornar_json(true, "Visitantes listados com sucesso", $visitantes);
}

// ========== CRIAR VISITANTE ==========
if ($metodo === 'POST') {
    verificarPermissao('operador');
    $entradaDados = isset($_POST['dados']) ? $_POST['dados'] : file_get_contents('php://input');
    $dados = json_decode($entradaDados, true);
    if (!is_array($dados)) {
        retornar_json(false, 'Dados do visitante inválidos.');
    }

    $nome_completo    = sanitizar($conexao, trim($dados['nome_completo']    ?? ''));
    $documento        = sanitizar($conexao, trim($dados['documento']        ?? ''));
    $tipo_documento   = sanitizar($conexao, $dados['tipo_documento']        ?? 'CPF');
    $telefone_contato = sanitizar($conexao, trim($dados['telefone_contato'] ?? ''));
    $telefone         = sanitizar($conexao, trim($dados['telefone']         ?? ''));
    $celular          = sanitizar($conexao, trim($dados['celular']          ?? ''));
    $placa_veiculo    = strtoupper(sanitizar($conexao, preg_replace('/[^A-Za-z0-9]/', '', $dados['placa_veiculo'] ?? '')));
    $email            = sanitizar($conexao, trim($dados['email']            ?? ''));
    $cep              = sanitizar($conexao, trim($dados['cep']              ?? ''));
    $endereco         = sanitizar($conexao, trim($dados['endereco']         ?? ''));
    $numero           = sanitizar($conexao, trim($dados['numero']           ?? ''));
    $complemento      = sanitizar($conexao, trim($dados['complemento']      ?? ''));
    $bairro           = sanitizar($conexao, trim($dados['bairro']           ?? ''));
    $cidade           = sanitizar($conexao, trim($dados['cidade']           ?? ''));
    $estado           = sanitizar($conexao, trim($dados['estado']           ?? ''));
    $observacao       = sanitizar($conexao, trim($dados['observacao']       ?? ''));

    // Validações
    if (empty($nome_completo)) retornar_json(false, "Nome completo é obrigatório");
    if (empty($documento))     retornar_json(false, "Documento (RG ou CPF) é obrigatório");

    $tipo_documento = in_array(strtoupper($tipo_documento), ['RG', 'CPF']) ? strtoupper($tipo_documento) : 'CPF';

    // CPF deve ser matematicamente válido e é sempre persistido com máscara.
    if ($tipo_documento === 'CPF') {
        if (!cpf_valido($documento)) {
            retornar_json(false, 'CPF inválido. Informe um CPF válido.');
        }
        $documento = cpf_formatar($documento);
    }

    // Verificar duplicidade por documento (ignorando pontuação)
    $doc_limpo_busca = preg_replace('/[^0-9A-Za-z]/', '', $documento);
    $stmt = $conexao->prepare(
        "SELECT id, nome_completo FROM visitantes WHERE tenant_id = $tenant_id AND REPLACE(REPLACE(REPLACE(documento, '.', ''), '-', ''), '/', '') = ?"
    );
    $stmt->bind_param("s", $doc_limpo_busca);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id_existente, $nome_existente);
        $stmt->fetch();
        $stmt->close();
        retornar_json(false, "Documento já cadastrado para: $nome_existente (ID: $id_existente)", [
            'id' => $id_existente,
            'nome' => $nome_existente,
            'duplicado' => true
        ]);
    }
    $stmt->close();

    // A autoria é sempre derivada do usuário autenticado e confirmada no banco
    // dentro do tenant da sessão; nunca é aceita do payload do navegador.
    $autorTipo = 'FUNCIONARIO';
    $autorUsuarioId = (int)($usuarioAutenticado['id'] ?? 0);
    $autorMoradorId = null;
    if ($autorUsuarioId <= 0) {
        retornar_json(false, 'Não foi possível identificar o usuário responsável pelo cadastro. Faça login novamente.');
    }
    $stmtAutor = $conexao->prepare(
        'SELECT u.nome FROM usuarios u
         INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.tenant_id = ?
         WHERE u.id = ? LIMIT 1'
    );
    if (!$stmtAutor) retornar_json(false, 'Não foi possível validar o responsável pelo cadastro.');
    $stmtAutor->bind_param('ii', $tenant_id, $autorUsuarioId);
    $stmtAutor->execute();
    $autorRegistro = $stmtAutor->get_result()->fetch_assoc();
    $stmtAutor->close();
    $autorNome = sanitizar($conexao, trim((string)($autorRegistro['nome'] ?? '')));
    if ($autorNome === '') {
        retornar_json(false, 'Usuário responsável não está vinculado ao condomínio atual. Faça login novamente.');
    }

    // Inserir visitante
    $stmt = $conexao->prepare(
        "INSERT INTO visitantes
            (tenant_id, cadastrado_por_tipo, cadastrado_por_usuario_id, cadastrado_por_morador_id, cadastrado_por_nome,
             nome_completo, documento, tipo_documento, telefone_contato, telefone, celular,
             placa_veiculo, email, cep, endereco, numero, complemento, bairro, cidade, estado, observacao)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) retornar_json(false, 'Erro ao preparar o cadastro do visitante.');
    $stmt->bind_param(
        "isiisssssssssssssssss",
        $tenant_id, $autorTipo, $autorUsuarioId, $autorMoradorId, $autorNome,
        $nome_completo, $documento, $tipo_documento, $telefone_contato, $telefone, $celular,
        $placa_veiculo, $email, $cep, $endereco, $numero, $complemento, $bairro, $cidade, $estado, $observacao
    );

    $conexao->begin_transaction();
    try {
        if (!$stmt->execute()) {
            throw new RuntimeException('Não foi possível gravar os dados do visitante.');
        }
        $id = (int)$conexao->insert_id;
        $stmt->close();

        // Foto ou documento são aceitos como evidência obrigatória. O cadastro
        // só é confirmado quando pelo menos um BLOB for gravado para este tenant.
        $urlFoto = gravar_anexo_visitante_se_enviado($conexao, (int)$tenant_id, 'foto', 'foto', $id);
        $urlDocumento = gravar_anexo_visitante_se_enviado($conexao, (int)$tenant_id, 'documento', 'documento', $id);
        if (!$urlFoto && !$urlDocumento) {
            throw new RuntimeException('Anexe ao menos uma foto ou um documento digitalizado para concluir o cadastro.');
        }

        if ($urlFoto && $urlDocumento) {
            $stmtArquivos = $conexao->prepare(
                'UPDATE visitantes SET foto = ?, documento_arquivo = ? WHERE tenant_id = ? AND id = ?'
            );
            if (!$stmtArquivos) throw new RuntimeException('Não foi possível associar os anexos ao visitante.');
            $stmtArquivos->bind_param('ssii', $urlFoto, $urlDocumento, $tenant_id, $id);
        } elseif ($urlFoto) {
            $stmtArquivos = $conexao->prepare(
                'UPDATE visitantes SET foto = ? WHERE tenant_id = ? AND id = ?'
            );
            if (!$stmtArquivos) throw new RuntimeException('Não foi possível associar a foto ao visitante.');
            $stmtArquivos->bind_param('sii', $urlFoto, $tenant_id, $id);
        } else {
            $stmtArquivos = $conexao->prepare(
                'UPDATE visitantes SET documento_arquivo = ? WHERE tenant_id = ? AND id = ?'
            );
            if (!$stmtArquivos) throw new RuntimeException('Não foi possível associar o documento ao visitante.');
            $stmtArquivos->bind_param('sii', $urlDocumento, $tenant_id, $id);
        }
        if (!$stmtArquivos->execute()) {
            $stmtArquivos->close();
            throw new RuntimeException('Não foi possível associar o anexo ao visitante.');
        }
        $stmtArquivos->close();

        $conexao->commit();
        registrar_log('INFO', "Visitante cadastrado por $autorNome [$autorTipo] com anexo: $nome_completo ($tipo_documento: $documento) ID: $id", $autorNome);
        retornar_json(true, 'Visitante cadastrado com sucesso', ['id' => $id]);
    } catch (Throwable $erroCadastro) {
        $conexao->rollback();
        error_log('[Visitantes][Cadastro] tenant=' . $tenant_id . ' erro=' . $erroCadastro->getMessage());
        retornar_json(false, $erroCadastro->getMessage());
    }
}

// ========== ATUALIZAR VISITANTE ==========
if ($metodo === 'PUT') {
    verificarPermissao('operador');
    $dados = json_decode(file_get_contents('php://input'), true);

    $id               = intval($dados['id'] ?? 0);
    $nome_completo    = sanitizar($conexao, trim($dados['nome_completo']    ?? ''));
    $documento        = sanitizar($conexao, trim($dados['documento']        ?? ''));
    $tipo_documento   = sanitizar($conexao, $dados['tipo_documento']        ?? 'CPF');
    $telefone_contato = sanitizar($conexao, trim($dados['telefone_contato'] ?? ''));
    $telefone         = sanitizar($conexao, trim($dados['telefone']         ?? ''));
    $celular          = sanitizar($conexao, trim($dados['celular']          ?? ''));
    $placa_veiculo    = strtoupper(sanitizar($conexao, preg_replace('/[^A-Za-z0-9]/', '', $dados['placa_veiculo'] ?? '')));
    $email            = sanitizar($conexao, trim($dados['email']            ?? ''));
    $cep              = sanitizar($conexao, trim($dados['cep']              ?? ''));
    $endereco         = sanitizar($conexao, trim($dados['endereco']         ?? ''));
    $numero           = sanitizar($conexao, trim($dados['numero']           ?? ''));
    $complemento      = sanitizar($conexao, trim($dados['complemento']      ?? ''));
    $bairro           = sanitizar($conexao, trim($dados['bairro']           ?? ''));
    $cidade           = sanitizar($conexao, trim($dados['cidade']           ?? ''));
    $estado           = sanitizar($conexao, trim($dados['estado']           ?? ''));
    $observacao       = sanitizar($conexao, trim($dados['observacao']       ?? ''));

    if ($id <= 0)          retornar_json(false, "ID inválido");
    if (empty($nome_completo)) retornar_json(false, "Nome completo é obrigatório");
    if (empty($documento))     retornar_json(false, "Documento é obrigatório");

    $tipo_documento = in_array(strtoupper($tipo_documento), ['RG', 'CPF']) ? strtoupper($tipo_documento) : 'CPF';

    // Mantém a mesma regra do cadastro também durante uma edição.
    if ($tipo_documento === 'CPF') {
        if (!cpf_valido($documento)) {
            retornar_json(false, 'CPF inválido. Informe um CPF válido.');
        }
        $documento = cpf_formatar($documento);
    }

    // Verificar duplicidade em outro visitante
    $doc_limpo_busca = preg_replace('/[^0-9A-Za-z]/', '', $documento);
    $stmt = $conexao->prepare(
        "SELECT id, nome_completo FROM visitantes WHERE tenant_id = $tenant_id AND REPLACE(REPLACE(REPLACE(documento, '.', ''), '-', ''), '/', '') = ?
           AND id != ?"
    );
    if (!$stmt) retornar_json(false, 'Erro ao validar documento do visitante.');
    $stmt->bind_param("si", $doc_limpo_busca, $id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id_dup, $nome_dup);
        $stmt->fetch();
        $stmt->close();
        retornar_json(false, "Documento já cadastrado para outro visitante: $nome_dup (ID: $id_dup)");
    }
    $stmt->close();

    $stmt = $conexao->prepare(
        "UPDATE visitantes SET
            nome_completo = ?, documento = ?, tipo_documento = ?,
            telefone_contato = ?, telefone = ?, celular = ?,
            placa_veiculo = ?, email = ?,
            cep = ?, endereco = ?, numero = ?, complemento = ?,
            bairro = ?, cidade = ?, estado = ?, observacao = ? WHERE tenant_id = $tenant_id AND id = ?"
    );
    if (!$stmt) retornar_json(false, 'Erro ao preparar a atualização do visitante.');
    $stmt->bind_param(
        "ssssssssssssssssi",
        $nome_completo, $documento, $tipo_documento,
        $telefone_contato, $telefone, $celular,
        $placa_veiculo, $email,
        $cep, $endereco, $numero, $complemento,
        $bairro, $cidade, $estado, $observacao, $id
    );

    if ($stmt->execute()) {
        registrar_log('INFO', "Visitante atualizado: $nome_completo (ID: $id)");
        retornar_json(true, "Visitante atualizado com sucesso");
    } else {
        retornar_json(false, "Erro ao atualizar visitante: " . $stmt->error);
    }
    $stmt->close();
}

// ========== EXCLUIR VISITANTE ==========
if ($metodo === 'DELETE') {
    verificarPermissao('admin');
    $dados = json_decode(file_get_contents('php://input'), true);
    $id = intval($dados['id'] ?? 0);

    if ($id <= 0) retornar_json(false, "ID inválido");

    $stmt = $conexao->prepare("SELECT nome_completo, foto, documento_arquivo FROM visitantes WHERE tenant_id = $tenant_id AND id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) retornar_json(false, "Visitante não encontrado");

    $stmt = $conexao->prepare("DELETE FROM visitantes WHERE tenant_id = $tenant_id AND id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        // Desativa os BLOBs do tenant; nunca apaga arquivos físicos do domínio.
        foreach ([$row['foto'], $row['documento_arquivo']] as $caminho) {
            if (!$caminho) continue;
            try { tenant_file_desativar_caminho($conexao, (int)$tenant_id, ltrim($caminho, './')); } catch (Throwable $e) { error_log('[Visitantes][Arquivo] ' . $e->getMessage()); }
        }

        registrar_log('INFO', "Visitante excluído: " . $row['nome_completo'] . " (ID: $id)");
        retornar_json(true, "Visitante excluído com sucesso");
    } else {
        retornar_json(false, "Erro ao excluir visitante: " . $stmt->error);
    }
    $stmt->close();
}

fechar_conexao($conexao);
