<?php
/**
 * API DE INADIMPLÊNCIA — ERP Condomínios
 *
 * Importa o Relatório de Inadimplência Detalhado BRCondos por tenant, preserva
 * snapshots históricos e oferece comparação, ranking e uma heurística explícita
 * de tendência. Não cria, baixa ou altera títulos de contas a receber.
 */
ob_start();
ini_set('max_execution_time', '120');
ini_set('memory_limit', '256M');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/log_financeiro_helper.php';
require_once __DIR__ . '/helpers/tenant_file_storage_helper.php';
require_once __DIR__ . '/helpers/pdf_text_extractor_helper.php';

// Descarta qualquer saída acidental dos arquivos incluídos para preservar JSON puro.
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$conn = conectar_banco();
$usuario = verificarAutenticacao(true, 'gerente');
$tenant_id = exigirTenantId();
$usuario_nome = (string)($usuario['nome'] ?? 'Sistema');
$usuario_id = (int)($usuario['id'] ?? $_SESSION['usuario_id'] ?? 0);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

_garantirTabelas();
// Aceita a ação explicitamente em GET ou POST; evita ambiguidade de $_REQUEST em uploads multipart.
$acao = strtolower(trim((string)($_GET['acao'] ?? $_POST['acao'] ?? '')));

try {
    switch ($acao) {
        case 'importar':              _importar(); break;
        case 'dashboard':             _dashboard(); break;
        case 'listar_importacoes':    _listarImportacoes(); break;
        case 'ranking':               _ranking(); break;
        case 'comparar_importacoes':  _compararImportacoes(); break;
        case 'detalhe_importacao':    _detalheImportacao(); break;
        case 'exportar_csv':          _exportarCsv(); break;
        default: retornar_json(false, 'Ação inválida.');
    }
} catch (Throwable $e) {
    error_log('[Inadimplencia] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    log_fin('inadimplencia', 'ERRO', $acao ?: 'desconhecida', 'Falha não tratada na API', $e->getMessage());
    retornar_json(false, 'Não foi possível concluir a operação de inadimplência.');
}

function _garantirTabelas() {
    global $conn;
    $sqlImportacoes = "CREATE TABLE IF NOT EXISTS `inadimplencia_importacoes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `tenant_id` int(11) NOT NULL,
        `arquivo_id` int(11) DEFAULT NULL,
        `nome_arquivo` varchar(255) NOT NULL,
        `associacao_nome` varchar(255) DEFAULT NULL,
        `data_base` date DEFAULT NULL,
        `data_geracao_relatorio` datetime DEFAULT NULL,
        `indicador_correcao` varchar(100) DEFAULT NULL,
        `indicador_juros_pct` decimal(7,2) DEFAULT NULL,
        `indicador_multa_pct` decimal(7,2) DEFAULT NULL,
        `quantidade_unidades` int(11) NOT NULL DEFAULT 0,
        `total_lancado` decimal(14,2) NOT NULL DEFAULT 0.00,
        `total_projetado` decimal(14,2) NOT NULL DEFAULT 0.00,
        `total_lancado_relatorio` decimal(14,2) DEFAULT NULL,
        `total_projetado_relatorio` decimal(14,2) DEFAULT NULL,
        `totais_reconciliam` tinyint(1) NOT NULL DEFAULT 1,
        `alerta_reconciliacao` varchar(500) DEFAULT NULL,
        `total_lancamentos` int(11) NOT NULL DEFAULT 0,
        `total_sem_vinculo` int(11) NOT NULL DEFAULT 0,
        `status` enum('PROCESSANDO','CONCLUIDO','ERRO') NOT NULL DEFAULT 'PROCESSANDO',
        `mensagem_erro` text DEFAULT NULL,
        `usuario` varchar(100) DEFAULT NULL,
        `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_inad_tenant_data` (`tenant_id`,`data_base`),
        KEY `idx_inad_tenant_status` (`tenant_id`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqlLancamentos = "CREATE TABLE IF NOT EXISTS `inadimplencia_lancamentos` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `tenant_id` int(11) NOT NULL,
        `importacao_id` int(11) NOT NULL,
        `gleba_numero` varchar(20) NOT NULL,
        `carteira_status` varchar(160) DEFAULT NULL,
        `permite_receber` tinyint(1) NOT NULL DEFAULT 1,
        `participa_cobranca` tinyint(1) NOT NULL DEFAULT 1,
        `proprietario_nome` varchar(255) DEFAULT NULL,
        `proprietario_cpf` varchar(20) DEFAULT NULL,
        `proprietario_cpf_digitos` varchar(14) DEFAULT NULL,
        `identificador_lancamento` varchar(50) DEFAULT NULL,
        `chave_alternativa` varchar(255) DEFAULT NULL,
        `chave_comparacao` varchar(320) NOT NULL,
        `tipo_cobranca` varchar(80) DEFAULT NULL,
        `descricao_original` varchar(500) DEFAULT NULL,
        `mes_referencia` date DEFAULT NULL,
        `vencimento` date DEFAULT NULL,
        `valor` decimal(12,2) NOT NULL DEFAULT 0.00,
        `juros` decimal(12,2) NOT NULL DEFAULT 0.00,
        `multa` decimal(12,2) NOT NULL DEFAULT 0.00,
        `correcao` decimal(12,2) NOT NULL DEFAULT 0.00,
        `projecao_recebimento` decimal(12,2) NOT NULL DEFAULT 0.00,
        `morador_id` int(11) DEFAULT NULL,
        `unidade_id` int(11) DEFAULT NULL,
        `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_inad_importacao` (`importacao_id`),
        KEY `idx_inad_tenant_gleba` (`tenant_id`,`gleba_numero`),
        KEY `idx_inad_tenant_chave` (`tenant_id`,`chave_comparacao`),
        KEY `idx_inad_morador` (`tenant_id`,`morador_id`),
        KEY `idx_inad_unidade` (`tenant_id`,`unidade_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqlComparacoes = "CREATE TABLE IF NOT EXISTS `inadimplencia_comparacoes` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `tenant_id` int(11) NOT NULL,
        `importacao_atual_id` int(11) NOT NULL,
        `importacao_anterior_id` int(11) DEFAULT NULL,
        `status_comparacao` enum('PRIMEIRO_SNAPSHOT','SEM_MUDANCA','ATUALIZADO') NOT NULL DEFAULT 'PRIMEIRO_SNAPSHOT',
        `delta_total_projetado` decimal(14,2) NOT NULL DEFAULT 0.00,
        `variacao_pct` decimal(9,4) DEFAULT NULL,
        `total_novas_glebas` int(11) NOT NULL DEFAULT 0,
        `total_evoluindo` int(11) NOT NULL DEFAULT 0,
        `total_corrigidas` int(11) NOT NULL DEFAULT 0,
        `total_quitadas` int(11) NOT NULL DEFAULT 0,
        `total_risco_alto` int(11) NOT NULL DEFAULT 0,
        `resumo_json` longtext DEFAULT NULL,
        `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inad_comparacao_atual` (`tenant_id`,`importacao_atual_id`),
        KEY `idx_inad_comparacao_anterior` (`tenant_id`,`importacao_anterior_id`),
        KEY `idx_inad_comparacao_status` (`tenant_id`,`status_comparacao`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sqlImportacoes) || !$conn->query($sqlLancamentos) || !$conn->query($sqlComparacoes)) {
        throw new RuntimeException('Falha ao preparar tabelas de inadimplência: ' . $conn->error);
    }
}

function _importar() {
    global $conn, $tenant_id, $usuario_nome, $usuario_id;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') retornar_json(false, 'Método inválido. Use POST.');
    if (empty($_FILES['arquivo']['tmp_name'])) retornar_json(false, 'Selecione o relatório PDF de inadimplência.');

    $arquivo = $_FILES['arquivo'];
    $nome = (string)($arquivo['name'] ?? 'relatorio.pdf');
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    $tamanho = (int)($arquivo['size'] ?? 0);
    if ($ext !== 'pdf' || $tamanho < 1 || $tamanho > 20 * 1024 * 1024) {
        retornar_json(false, 'Envie um PDF válido de até 20 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($arquivo['tmp_name']) ?: '';
    if (!in_array($mime, ['application/pdf', 'application/x-pdf', 'application/octet-stream'], true)) {
        retornar_json(false, 'O arquivo enviado não foi reconhecido como PDF.');
    }
    $inicio_importacao = log_fin_inicio('inadimplencia', 'importar', 'Iniciando importação do relatório ' . $nome);
    $stmt = $conn->prepare('INSERT INTO inadimplencia_importacoes (tenant_id,nome_arquivo,status,usuario) VALUES (?,?,\'PROCESSANDO\',?)');
    $stmt->bind_param('iss', $tenant_id, $nome, $usuario_nome);
    $stmt->execute();
    $importacao_id = (int)$conn->insert_id;
    $stmt->close();

    try {
        $caminho = 'uploads/inadimplencia/tenant_' . $tenant_id . '/importacao_' . $importacao_id . '.pdf';
        $blob = tenant_file_gravar_upload($conn, $tenant_id, $arquivo, 'inadimplencia_relatorio', $caminho, false, $usuario_id);
        $arquivo_id = (int)$blob['id'];
        $stmt = $conn->prepare('UPDATE inadimplencia_importacoes SET arquivo_id=? WHERE id=? AND tenant_id=?');
        $stmt->bind_param('iii', $arquivo_id, $importacao_id, $tenant_id);
        $stmt->execute(); $stmt->close();
        $ref = $conn->prepare("INSERT INTO tenant_arquivo_referencias (arquivo_id, modulo, registro_id, campo_origem) VALUES (?, 'inadimplencia', ?, 'relatorio_pdf')");
        if ($ref) { $ref->bind_param('ii', $arquivo_id, $importacao_id); $ref->execute(); $ref->close(); }
    } catch (Throwable $e) {
        log_fin('inadimplencia', 'AVISO', 'arquivo_pdf', 'PDF não foi armazenado no BLOB; importação seguirá com o temporário.', $e->getMessage(), $importacao_id);
    }

    $parse = _parsePDFInadimplencia($arquivo['tmp_name']);
    if (empty($parse['lancamentos'])) {
        _marcarImportacaoErro($importacao_id, 'Nenhum lançamento foi identificado no relatório.');
        log_fin('inadimplencia', 'ERRO', 'importar', 'Parser não encontrou lançamentos', json_encode($parse['avisos'] ?? []), $importacao_id);
        retornar_json(false, 'Nenhum lançamento foi identificado. Confirme se o PDF é o Relatório de Inadimplência Detalhado BRCondos.');
    }

    $comparacaoPersistida = null;
    $conn->begin_transaction();
    try {
        $sem_vinculo = 0;
        $insert = $conn->prepare('INSERT INTO inadimplencia_lancamentos (tenant_id,importacao_id,gleba_numero,carteira_status,permite_receber,participa_cobranca,proprietario_nome,proprietario_cpf,proprietario_cpf_digitos,identificador_lancamento,chave_alternativa,chave_comparacao,tipo_cobranca,descricao_original,mes_referencia,vencimento,valor,juros,multa,correcao,projecao_recebimento,morador_id,unidade_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        if (!$insert) throw new RuntimeException($conn->error);
        foreach ($parse['lancamentos'] as $l) {
            $vinculo = _resolverVinculo($l['cpf_digitos'], $l['gleba_numero']);
            if (!$vinculo['morador_id'] && !$vinculo['unidade_id']) $sem_vinculo++;
            $insert->bind_param('iissiissssssssssdddddii',
                $tenant_id, $importacao_id, $l['gleba_numero'], $l['carteira_status'], $l['permite_receber'], $l['participa_cobranca'],
                $l['proprietario_nome'], $l['proprietario_cpf'], $l['cpf_digitos'], $l['identificador'], $l['chave_alternativa'],
                $l['chave_comparacao'], $l['tipo_cobranca'], $l['descricao_original'], $l['mes_referencia'], $l['vencimento'],
                $l['valor'], $l['juros'], $l['multa'], $l['correcao'], $l['projecao'], $vinculo['morador_id'], $vinculo['unidade_id']
            );
            if (!$insert->execute()) throw new RuntimeException('Falha ao gravar lançamento: ' . $insert->error);
        }
        $insert->close();

        $tot_lancado = (float)$parse['totais_calculados']['valor'];
        $tot_projetado = (float)$parse['totais_calculados']['projecao'];
        $rel_lancado = $parse['resumo']['total_lancado'] ?? null;
        $rel_projetado = $parse['resumo']['total_projetado'] ?? null;
        $reconciliam = ($rel_lancado === null || $rel_projetado === null)
            ? 0
            : (abs($tot_lancado - $rel_lancado) <= 1.00 && abs($tot_projetado - $rel_projetado) <= 1.00 ? 1 : 0);
        $alerta = $reconciliam ? null : 'Importação com divergência de totais — revise antes de confiar totalmente nestes números.';
        if (!$reconciliam) log_fin('inadimplencia', 'AVISO', 'reconciliacao_total', 'Totais calculados divergem do Resumo do PDF', json_encode(['calculado' => $parse['totais_calculados'], 'resumo' => $parse['resumo']]), $importacao_id);
        foreach ($parse['avisos'] as $aviso) log_fin('inadimplencia', 'AVISO', 'parser', $aviso, null, $importacao_id);

        $meta = $parse['meta'];
        $stmt = $conn->prepare('UPDATE inadimplencia_importacoes SET associacao_nome=?,data_base=?,data_geracao_relatorio=?,indicador_correcao=?,indicador_juros_pct=?,indicador_multa_pct=?,quantidade_unidades=?,total_lancado=?,total_projetado=?,total_lancado_relatorio=?,total_projetado_relatorio=?,totais_reconciliam=?,alerta_reconciliacao=?,total_lancamentos=?,total_sem_vinculo=?,status=\'CONCLUIDO\' WHERE id=? AND tenant_id=?');
        $stmt->bind_param('ssssddiddddisiiii', $meta['associacao_nome'], $meta['data_base'], $meta['data_geracao_relatorio'], $meta['correcao'], $meta['juros_pct'], $meta['multa_pct'], $meta['quantidade_unidades'], $tot_lancado, $tot_projetado, $rel_lancado, $rel_projetado, $reconciliam, $alerta, $parse['totais_calculados']['linhas'], $sem_vinculo, $importacao_id, $tenant_id);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $stmt->close();

        // Persiste o resultado da comparação com o snapshot imediatamente anterior do mesmo tenant.
        $comparacaoPersistida = _registrarComparacaoSnapshot($importacao_id);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        _marcarImportacaoErro($importacao_id, $e->getMessage());
        throw $e;
    }

    log_fin_fim('inadimplencia', 'importar', 'Importação concluída com ' . count($parse['lancamentos']) . ' lançamento(s).', $inicio_importacao, $importacao_id);
    retornar_json(true, 'Relatório importado com sucesso.', [
        'importacao_id' => $importacao_id,
        'data_base' => $parse['meta']['data_base'],
        'quantidade_unidades' => $parse['meta']['quantidade_unidades'],
        'total_lancado' => $parse['totais_calculados']['valor'],
        'total_projetado' => $parse['totais_calculados']['projecao'],
        'total_lancamentos' => $parse['totais_calculados']['linhas'],
        'total_sem_vinculo' => $sem_vinculo,
        'totais_reconciliam' => (bool)$reconciliam,
        'alerta_reconciliacao' => $alerta,
        'comparacao' => $comparacaoPersistida,
        'avisos' => $parse['avisos']
    ]);
}

function _parsePDFInadimplencia($path) {
    $texto = pdf_extrair_texto($path);
    $linhas = preg_split('/\R/u', $texto);
    $meta = ['associacao_nome' => null, 'data_base' => null, 'data_geracao_relatorio' => null, 'correcao' => null, 'juros_pct' => null, 'multa_pct' => null, 'quantidade_unidades' => 0];
    $resumo = ['total_lancado' => null, 'total_projetado' => null];
    $avisos = [];
    $lancamentos = [];
    $gleba = null; $proprietario = ['nome' => null, 'cpf' => null, 'cpf_digitos' => null];
    $owner_soma = ['valor' => 0.0, 'juros' => 0.0, 'multa' => 0.0, 'correcao' => 0.0, 'projecao' => 0.0, 'qtd' => 0];
    $em_resumo = false;

    foreach ($linhas as $numero_linha => $linha_bruta) {
        $linha = trim(str_replace("\f", '', $linha_bruta));
        if ($linha === '') continue;
        if (preg_match('/Relat[oó]rio de Inadimpl[êe]ncia Detalhado\s*-\s*(.+)$/ui', $linha, $m)) $meta['associacao_nome'] = trim($m[1]);
        if (preg_match('/^Corre[cç][aã]o\s+(.+)$/ui', $linha, $m)) $meta['correcao'] = trim($m[1]);
        if (preg_match('/^Juros\s+([\d\.,]+)%/ui', $linha, $m)) $meta['juros_pct'] = _normalizarValor($m[1]);
        if (preg_match('/^Multa\s+([\d\.,]+)%/ui', $linha, $m)) $meta['multa_pct'] = _normalizarValor($m[1]);
        if (preg_match('/^Data Base\s+(\d{2}\/\d{2}\/\d{4})/ui', $linha, $m)) $meta['data_base'] = _normalizarData($m[1]);
        if (preg_match('/^Quantidade de Unidades\s+(\d+)/ui', $linha, $m)) $meta['quantidade_unidades'] = (int)$m[1];
        if (preg_match('/BRCondos\s*-\s*(\d{2}\/\d{2}\/\d{4})\s*\|\s*(\d{2}:\d{2}:\d{2})/ui', $linha, $m)) $meta['data_geracao_relatorio'] = _normalizarData($m[1]) . ' ' . $m[2];

        if (preg_match('/^Resumo$/ui', $linha)) { $em_resumo = true; continue; }
        if ($em_resumo) {
            if (preg_match('/^TOTAL:\s+([\d\.]+,\d{2})\s+([\d\.]+,\d{2})$/ui', $linha, $m)) {
                $resumo['total_lancado'] = _normalizarValor($m[1]);
                $resumo['total_projetado'] = _normalizarValor($m[2]);
            }
            continue;
        }

        if (preg_match('/^GLEBA,\s*N[°ºo]\s*(\d+)(.*)$/ui', $linha, $m)) {
            $resto = trim($m[2]);
            $participa = stripos($resto, 'NÃO PARTICIPA DE COBRANÇA') === false ? 1 : 0;
            $resto = preg_replace('/\s*\(N[aã]o participa de cobran[cç]a\)\s*$/ui', '', $resto);
            $status = 'RECEBER';
            if (preg_match('/\(Carteira:\s*(.*)\)\s*$/ui', $resto, $carteira)) $status = trim($carteira[1]);
            $permite = stripos($status, 'NÃO PERMITE RECEBER') === false ? 1 : 0;
            $status = trim(preg_replace('/\s*\(N[aã]o permite receber\)\s*$/ui', '', $status));
            $gleba = ['numero' => (string)(int)$m[1], 'carteira_status' => $status ?: 'RECEBER', 'permite_receber' => $permite, 'participa_cobranca' => $participa];
            $proprietario = ['nome' => null, 'cpf' => null, 'cpf_digitos' => null];
            $owner_soma = ['valor' => 0.0, 'juros' => 0.0, 'multa' => 0.0, 'correcao' => 0.0, 'projecao' => 0.0, 'qtd' => 0];
            continue;
        }
        if (!$gleba || preg_match('/^(Unidade|Indicadores:|Descri[cç][aã]o|TOTALIZADORES)/ui', $linha)) continue;
        if (preg_match('/^(.+?)\s*\((\d{3}\.\d{3}\.\d{3}-\d{2})\)\s*$/u', $linha, $m)) {
            $proprietario = ['nome' => trim($m[1]), 'cpf' => $m[2], 'cpf_digitos' => preg_replace('/\D/', '', $m[2])];
            $owner_soma = ['valor' => 0.0, 'juros' => 0.0, 'multa' => 0.0, 'correcao' => 0.0, 'projecao' => 0.0, 'qtd' => 0];
            continue;
        }
        if (preg_match('/^TOTAL:\s+([\d\.]+,\d{2})\s+([\d\.]+,\d{2})\s+([\d\.]+,\d{2})\s+([\d\.]+,\d{2})\s+([\d\.]+,\d{2})$/ui', $linha, $m)) {
            if ($owner_soma['qtd']) {
                $esperado = _normalizarValor($m[5]);
                if (abs($owner_soma['projecao'] - $esperado) > 0.02) $avisos[] = 'Divergência no total do proprietário da Gleba ' . $gleba['numero'] . ': calculado ' . number_format($owner_soma['projecao'], 2, ',', '.') . ' / relatório ' . $m[5] . '.';
            }
            continue;
        }
        $reLinha = '/^(.+?)\s+([A-Z]{3}\/\d{4})\s+(\d{2}\/\d{2}\/\d{4})\s+([\d\.]+,\d{2})\s+([\d\.]+,\d{2})\s+([\d\.]+,\d{2})\s+([\d\.]+,\d{2})\s+([\d\.]+,\d{2})$/u';
        if (preg_match($reLinha, $linha, $m)) {
            $descricao = trim($m[1]);
            $identificador = null;
            if (preg_match('/#(\d+)/', $descricao, $id)) $identificador = $id[1];
            $valor = _normalizarValor($m[4]); $juros = _normalizarValor($m[5]); $multa = _normalizarValor($m[6]); $correcao = _normalizarValor($m[7]); $projecao = _normalizarValor($m[8]);
            $tipo = _categoriaCobranca($descricao);
            $mes = _normalizarMesReferencia($m[2]);
            $vencimento = _normalizarData($m[3]);
            $alternativa = implode('|', [$gleba['numero'], _chaveTexto($descricao), $mes ?: '', $vencimento ?: '', number_format((float)$valor, 2, '.', '')]);
            // O BRCondos pode reutilizar o mesmo # em itens de tipos diferentes; a chave de comparação inclui tipo e vencimento.
            $chave = $identificador ? implode('|', ['BRCONDOS', $identificador, _chaveTexto($tipo), $vencimento ?: '']) : 'ALT|' . $alternativa;
            $lancamentos[] = [
                'gleba_numero' => $gleba['numero'], 'carteira_status' => $gleba['carteira_status'], 'permite_receber' => $gleba['permite_receber'], 'participa_cobranca' => $gleba['participa_cobranca'],
                'proprietario_nome' => $proprietario['nome'], 'proprietario_cpf' => $proprietario['cpf'], 'cpf_digitos' => $proprietario['cpf_digitos'],
                'identificador' => $identificador, 'chave_alternativa' => $identificador ? null : $alternativa, 'chave_comparacao' => $chave,
                'tipo_cobranca' => $tipo, 'descricao_original' => $descricao, 'mes_referencia' => $mes, 'vencimento' => $vencimento,
                'valor' => $valor ?: 0, 'juros' => $juros ?: 0, 'multa' => $multa ?: 0, 'correcao' => $correcao ?: 0, 'projecao' => $projecao ?: 0
            ];
            foreach (['valor','juros','multa','correcao','projecao'] as $campo) $owner_soma[$campo] += (float)end($lancamentos)[$campo];
            $owner_soma['qtd']++;
        }
    }
    if (!$meta['data_base'] && $meta['data_geracao_relatorio']) $meta['data_base'] = substr($meta['data_geracao_relatorio'], 0, 10);
    if (!$meta['associacao_nome']) $meta['associacao_nome'] = null;
    $calculados = ['valor' => 0.0, 'projecao' => 0.0, 'linhas' => count($lancamentos)];
    foreach ($lancamentos as $l) { $calculados['valor'] += $l['valor']; $calculados['projecao'] += $l['projecao']; }
    return compact('meta', 'resumo', 'avisos', 'lancamentos') + ['totais_calculados' => $calculados];
}

function _dashboard() {
    global $conn, $tenant_id;
    $importacao_id = (int)($_GET['importacao_id'] ?? 0);
    $atual = _obterImportacao($importacao_id);
    if (!$atual) retornar_json(true, 'Nenhuma importação concluída para este condomínio.', ['tem_dados' => false]);
    $anterior = _obterImportacaoAnterior((int)$atual['id']);
    $comparacao = _compararDados((int)$atual['id'], $anterior ? (int)$anterior['id'] : 0);
    $ranking = _obterRanking((int)$atual['id'], ['limite' => 50]);
    $historico = [];
    $stmt = $conn->prepare("SELECT id,nome_arquivo,data_base,total_projetado,total_lancado,quantidade_unidades,totais_reconciliam,status,criado_em FROM inadimplencia_importacoes WHERE tenant_id=? AND status='CONCLUIDO' ORDER BY data_base ASC,id ASC");
    $stmt->bind_param('i', $tenant_id); $stmt->execute(); $res = $stmt->get_result(); while ($r = $res->fetch_assoc()) $historico[] = $r; $stmt->close();
    $carteiras = _distribuicaoCarteira((int)$atual['id']);
    $judicial = 0; foreach ($ranking as $r) if ((int)$r['permite_receber'] === 0 || stripos((string)$r['carteira_status'], 'JUDICIAL') !== false) $judicial++;
    $variacao = $anterior ? ((float)$atual['total_projetado'] - (float)$anterior['total_projetado']) : 0.0;
    $variacao_pct = $anterior && (float)$anterior['total_projetado'] > 0 ? ($variacao / (float)$anterior['total_projetado']) * 100 : null;
    $heuristica = _calcularHeuristica((int)$atual['id'], $anterior ? (int)$anterior['id'] : 0);
    // Backfill idempotente: snapshots históricos passam a ter comparação persistida na primeira consulta.
    $visaoGerencial = _obterComparacaoPersistida((int)$atual['id']) ?: _registrarComparacaoSnapshot((int)$atual['id']);
    retornar_json(true, 'Dashboard carregado.', [
        'tem_dados' => true, 'importacao_atual' => $atual, 'importacao_anterior' => $anterior,
        'kpis' => ['total_projetado' => (float)$atual['total_projetado'], 'unidades' => count($ranking), 'judiciais' => $judicial, 'variacao' => $variacao, 'variacao_pct' => $variacao_pct, 'sem_vinculo' => (int)$atual['total_sem_vinculo']],
        'carteiras' => $carteiras, 'historico' => $historico, 'mudancas' => $comparacao['agregado'], 'ranking' => $ranking, 'heuristica' => $heuristica, 'visao_gerencial' => $visaoGerencial
    ]);
}

function _listarImportacoes() {
    global $conn, $tenant_id;
    $pagina = max(1, (int)($_GET['pagina'] ?? 1)); $por = min(50, max(5, (int)($_GET['por_pagina'] ?? 10))); $offset = ($pagina - 1) * $por;
    $stmt = $conn->prepare("SELECT COUNT(*) total FROM inadimplencia_importacoes WHERE tenant_id=?"); $stmt->bind_param('i', $tenant_id); $stmt->execute(); $total = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    $stmt = $conn->prepare('SELECT * FROM inadimplencia_importacoes WHERE tenant_id=? ORDER BY data_base DESC,id DESC LIMIT ? OFFSET ?'); $stmt->bind_param('iii', $tenant_id, $por, $offset); $stmt->execute(); $res = $stmt->get_result(); $itens=[]; while($r=$res->fetch_assoc()) $itens[]=$r; $stmt->close();
    retornar_json(true, 'Histórico carregado.', ['itens'=>$itens,'total'=>$total,'pagina'=>$pagina,'total_paginas'=>max(1,(int)ceil($total/$por))]);
}

function _ranking() {
    $atual = _obterImportacao((int)($_GET['importacao_id'] ?? 0));
    if (!$atual) retornar_json(true, 'Nenhuma importação disponível.', ['itens'=>[]]);
    $filtros = ['busca'=>(string)($_GET['busca'] ?? ''), 'carteira'=>(string)($_GET['carteira'] ?? ''), 'ordem'=>(string)($_GET['ordem'] ?? 'divida_desc'), 'pagina'=>max(1,(int)($_GET['pagina'] ?? 1)), 'por_pagina'=>min(100,max(10,(int)($_GET['por_pagina'] ?? 25)))];
    $dados = _obterRanking((int)$atual['id'], $filtros);
    retornar_json(true, 'Ranking carregado.', $dados);
}

function _compararImportacoes() {
    $atual = _obterImportacao((int)($_GET['atual_id'] ?? 0));
    if (!$atual) retornar_json(false, 'Importação atual não encontrada.');
    $anterior = _obterImportacao((int)($_GET['anterior_id'] ?? 0), false) ?: _obterImportacaoAnterior((int)$atual['id']);
    $dados = _compararDados((int)$atual['id'], $anterior ? (int)$anterior['id'] : 0);
    retornar_json(true, 'Comparação concluída.', ['atual'=>$atual,'anterior'=>$anterior,'dados'=>$dados]);
}

function _detalheImportacao() {
    $importacao = _obterImportacao((int)($_GET['importacao_id'] ?? 0));
    if (!$importacao) retornar_json(false, 'Importação não encontrada.');
    retornar_json(true, 'Importação carregada.', ['importacao'=>$importacao, 'comparacao'=>_compararDados((int)$importacao['id'], (_obterImportacaoAnterior((int)$importacao['id'])['id'] ?? 0))]);
}

function _exportarCsv() {
    global $conn, $tenant_id;
    $atual = _obterImportacao((int)($_GET['importacao_id'] ?? 0));
    if (!$atual) retornar_json(false, 'Importação não encontrada.');
    while (ob_get_level()) ob_end_clean();
    $nome = 'inadimplencia_' . ($atual['data_base'] ?: date('Y-m-d')) . '.csv';
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $nome . '"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Gleba','Proprietário','CPF','Carteira','Meses em aberto','Valor projetado','Morador vinculado','Unidade vinculada'], ';');
    foreach (_obterRanking((int)$atual['id'], ['limite'=>5000]) as $r) fputcsv($out, [$r['gleba_numero'],$r['proprietario_nome'],$r['proprietario_cpf'],$r['carteira_status'],$r['meses_aberto'],number_format((float)$r['total_projetado'],2,',','.'),$r['morador_id']?'Sim':'Não',$r['unidade_id']?'Sim':'Não'], ';');
    fclose($out); exit;
}

function _obterImportacao($id = 0, $somenteConcluida = true) {
    global $conn, $tenant_id;
    if ($id <= 0) {
        $sql = "SELECT * FROM inadimplencia_importacoes WHERE tenant_id=?" . ($somenteConcluida ? " AND status='CONCLUIDO'" : '') . ' ORDER BY data_base DESC,id DESC LIMIT 1';
        $stmt=$conn->prepare($sql); $stmt->bind_param('i',$tenant_id);
    } else {
        $sql = 'SELECT * FROM inadimplencia_importacoes WHERE id=? AND tenant_id=?' . ($somenteConcluida ? " AND status='CONCLUIDO'" : '') . ' LIMIT 1';
        $stmt=$conn->prepare($sql); $stmt->bind_param('ii',$id,$tenant_id);
    }
    $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return $row ?: null;
}
function _obterImportacaoAnterior($id) {
    global $conn, $tenant_id;
    $stmt=$conn->prepare("SELECT p.* FROM inadimplencia_importacoes a JOIN inadimplencia_importacoes p ON p.tenant_id=a.tenant_id AND p.status='CONCLUIDO' AND (p.data_base<a.data_base OR (p.data_base=a.data_base AND p.id<a.id)) WHERE a.id=? AND a.tenant_id=? LIMIT 1");
    // a consulta precisa da última anterior, por isso não usa LIMIT sem ordenação
    $stmt->close();
    $stmt=$conn->prepare("SELECT p.* FROM inadimplencia_importacoes a JOIN inadimplencia_importacoes p ON p.tenant_id=a.tenant_id AND p.status='CONCLUIDO' AND (p.data_base<a.data_base OR (p.data_base=a.data_base AND p.id<a.id)) WHERE a.id=? AND a.tenant_id=? ORDER BY p.data_base DESC,p.id DESC LIMIT 1");
    $stmt->bind_param('ii',$id,$tenant_id); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $stmt->close(); return $r ?: null;
}
function _registrarComparacaoSnapshot($importacao_id) {
    global $conn, $tenant_id;
    $atual = _obterImportacao((int)$importacao_id);
    if (!$atual) throw new RuntimeException('Snapshot concluído não encontrado para comparação.');

    $anterior = _obterImportacaoAnterior((int)$atual['id']);
    $dados = _compararDados((int)$atual['id'], $anterior ? (int)$anterior['id'] : 0);
    $heuristica = _calcularHeuristica((int)$atual['id'], $anterior ? (int)$anterior['id'] : 0);
    $visao = _montarVisaoGerencial($atual, $anterior, $dados, $heuristica);

    $resumo = json_encode($visao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($resumo === false) throw new RuntimeException('Não foi possível serializar a comparação de inadimplência.');

    $anteriorId = $anterior ? (int)$anterior['id'] : null;
    $stmt = $conn->prepare("INSERT INTO inadimplencia_comparacoes (tenant_id,importacao_atual_id,importacao_anterior_id,status_comparacao,delta_total_projetado,variacao_pct,total_novas_glebas,total_evoluindo,total_corrigidas,total_quitadas,total_risco_alto,resumo_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE importacao_anterior_id=VALUES(importacao_anterior_id),status_comparacao=VALUES(status_comparacao),delta_total_projetado=VALUES(delta_total_projetado),variacao_pct=VALUES(variacao_pct),total_novas_glebas=VALUES(total_novas_glebas),total_evoluindo=VALUES(total_evoluindo),total_corrigidas=VALUES(total_corrigidas),total_quitadas=VALUES(total_quitadas),total_risco_alto=VALUES(total_risco_alto),resumo_json=VALUES(resumo_json)");
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('iiisddiiiiis', $tenant_id, $importacao_id, $anteriorId, $visao['status'], $visao['delta_total_projetado'], $visao['variacao_pct'], $visao['contagens']['novas'], $visao['contagens']['evoluindo'], $visao['contagens']['corrigidas'], $visao['contagens']['quitadas'], $visao['contagens']['risco_alto'], $resumo);
    if (!$stmt->execute()) throw new RuntimeException('Falha ao gravar comparação: ' . $stmt->error);
    $stmt->close();
    log_fin('inadimplencia', 'INFO', 'comparacao_persistida', 'Comparação de snapshots persistida.', json_encode(['status' => $visao['status'], 'anterior_id' => $anteriorId, 'contagens' => $visao['contagens']]), $importacao_id);

    return $visao;
}

function _obterComparacaoPersistida($importacao_id) {
    global $conn, $tenant_id;
    $stmt = $conn->prepare('SELECT * FROM inadimplencia_comparacoes WHERE tenant_id=? AND importacao_atual_id=? LIMIT 1');
    $stmt->bind_param('ii', $tenant_id, $importacao_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $resumo = json_decode((string)$row['resumo_json'], true);
    return is_array($resumo) ? $resumo : null;
}

function _montarVisaoGerencial($atual, $anterior, $comparacao, $heuristica) {
    $grupos = $comparacao['agregado'] ?? [];
    $novas = $grupos['NOVO'] ?? [];
    $evoluindo = $grupos['EVOLUINDO'] ?? [];
    $corrigidas = $grupos['CORRIGIDO'] ?? [];
    $quitadas = $grupos['QUITADO'] ?? [];
    $delta = $anterior ? (float)$atual['total_projetado'] - (float)$anterior['total_projetado'] : 0.0;
    $variacaoPct = $anterior && (float)$anterior['total_projetado'] > 0 ? ($delta / (float)$anterior['total_projetado']) * 100 : null;
    $semMudanca = $anterior && abs($delta) <= 1 && !count($novas) && !count($evoluindo) && !count($corrigidas) && !count($quitadas);
    $status = !$anterior ? 'PRIMEIRO_SNAPSHOT' : ($semMudanca ? 'SEM_MUDANCA' : 'ATUALIZADO');

    $prioridades = [];
    foreach (($heuristica['risco_alto'] ?? []) as $item) {
        $prioridades['risco_' . $item['gleba_numero']] = ['gleba_numero' => $item['gleba_numero'], 'tipo' => 'RISCO_ALTO', 'titulo' => 'Aumento em duas importações consecutivas', 'delta' => (float)$item['delta'], 'total_atual' => (float)$item['atual']];
    }
    foreach ($evoluindo as $item) {
        $chave = 'evol_' . $item['gleba_numero'];
        if (!isset($prioridades[$chave]) && !isset($prioridades['risco_' . $item['gleba_numero']])) $prioridades[$chave] = ['gleba_numero' => $item['gleba_numero'], 'tipo' => 'EVOLUINDO', 'titulo' => 'Dívida aumentou no período', 'delta' => (float)$item['delta'], 'total_atual' => (float)$item['atual']];
    }
    foreach ($novas as $item) {
        $chave = 'novo_' . $item['gleba_numero'];
        if (!isset($prioridades[$chave]) && !isset($prioridades['risco_' . $item['gleba_numero']])) $prioridades[$chave] = ['gleba_numero' => $item['gleba_numero'], 'tipo' => 'NOVO', 'titulo' => 'Nova inadimplência', 'delta' => (float)$item['delta'], 'total_atual' => (float)$item['atual']];
    }
    $prioridades = array_values($prioridades);
    usort($prioridades, function ($a, $b) { return abs((float)$b['delta']) <=> abs((float)$a['delta']); });

    return [
        'status' => $status,
        'importacao_atual_id' => (int)$atual['id'],
        'importacao_anterior_id' => $anterior ? (int)$anterior['id'] : null,
        'delta_total_projetado' => $delta,
        'variacao_pct' => $variacaoPct,
        'contagens' => ['novas' => count($novas), 'evoluindo' => count($evoluindo), 'corrigidas' => count($corrigidas), 'quitadas' => count($quitadas), 'risco_alto' => count($heuristica['risco_alto'] ?? [])],
        'prioridades' => array_slice($prioridades, 0, 8),
        'mensagem' => !$anterior ? 'Este é o primeiro snapshot. Importe o próximo relatório para obter a comparação automática.' : ($semMudanca ? 'Não foram identificadas alterações financeiras relevantes em relação ao snapshot anterior.' : 'Comparação concluída com o snapshot anterior deste condomínio.')
    ];
}

function _compararDados($atual_id, $anterior_id) {
    global $conn, $tenant_id;
    $mapaAtual = _mapaLancamentos((int)$atual_id); $mapaAnterior = $anterior_id ? _mapaLancamentos((int)$anterior_id) : [];
    $linhas=[]; $agregado=[];
    foreach ($mapaAtual as $chave=>$cur) {
        $prev=$mapaAnterior[$chave] ?? null; $delta=$prev ? $cur['projecao']-$prev['projecao'] : $cur['projecao'];
        $status=!$prev?'NOVO':($delta>1?'EVOLUINDO':($delta<-1?'CORRIGIDO':'ESTAVEL'));
        $linhas[]=['chave'=>$chave,'status'=>$status,'atual'=>$cur,'anterior'=>$prev,'delta'=>$delta];
    }
    foreach ($mapaAnterior as $chave=>$prev) if (!isset($mapaAtual[$chave])) $linhas[]=['chave'=>$chave,'status'=>'QUITADO','atual'=>null,'anterior'=>$prev,'delta'=>-$prev['projecao']];
    foreach ($linhas as $l) {
        $base=$l['atual'] ?: $l['anterior']; $g=(string)$base['gleba_numero'];
        if (!isset($agregado[$g])) $agregado[$g]=['gleba_numero'=>$g,'proprietario_nome'=>$base['proprietario_nome'],'morador_id'=>$base['morador_id'],'atual'=>0.0,'anterior'=>0.0,'delta'=>0.0,'status'=>'ESTAVEL'];
        $agregado[$g]['atual'] += $l['atual']['projecao'] ?? 0; $agregado[$g]['anterior'] += $l['anterior']['projecao'] ?? 0;
    }
    foreach($agregado as &$g){ $g['delta']=$g['atual']-$g['anterior']; $g['status']=$g['anterior']==0&&$g['atual']>0?'NOVO':($g['atual']==0&&$g['anterior']>0?'QUITADO':($g['delta']>1?'EVOLUINDO':($g['delta']<-1?'CORRIGIDO':'ESTAVEL'))); } unset($g);
    $grupos=['NOVO'=>[],'QUITADO'=>[],'EVOLUINDO'=>[],'CORRIGIDO'=>[],'ESTAVEL'=>[]]; foreach($agregado as $g)$grupos[$g['status']][]=$g; foreach($grupos as &$grupo)usort($grupo,fn($a,$b)=>abs($b['delta'])<=>abs($a['delta']));
    return ['lancamentos'=>$linhas,'agregado'=>$grupos];
}
function _mapaLancamentos($importacao_id) {
    global $conn, $tenant_id; if(!$importacao_id)return[];
    $stmt=$conn->prepare('SELECT chave_comparacao,gleba_numero,proprietario_nome,morador_id,projecao_recebimento FROM inadimplencia_lancamentos WHERE tenant_id=? AND importacao_id=?');$stmt->bind_param('ii',$tenant_id,$importacao_id);$stmt->execute();$res=$stmt->get_result();$map=[];
    while($r=$res->fetch_assoc()){ $k=$r['chave_comparacao']; if(!isset($map[$k]))$map[$k]=['gleba_numero'=>$r['gleba_numero'],'proprietario_nome'=>$r['proprietario_nome'],'morador_id'=>$r['morador_id'],'projecao'=>0.0];$map[$k]['projecao']+=(float)$r['projecao_recebimento']; }$stmt->close();return $map;
}
function _obterRanking($importacao_id, $filtros=[]) {
    global $conn,$tenant_id;
    $limite=(int)($filtros['limite']??0);$pagina=max(1,(int)($filtros['pagina']??1));$por=min(100,max(10,(int)($filtros['por_pagina']??25)));$busca=trim((string)($filtros['busca']??''));$carteira=trim((string)($filtros['carteira']??''));
    $where=' WHERE l.tenant_id=? AND l.importacao_id=? ';$params=[$tenant_id,$importacao_id];$tipos='ii';
    if($busca!==''){ $where.=' AND (l.gleba_numero LIKE ? OR l.proprietario_nome LIKE ? OR l.proprietario_cpf_digitos LIKE ?) ';$b='%'.preg_replace('/\D/','',$busca).'%';$nome='%'.$busca.'%';$params[]=$nome;$params[]=$nome;$params[]=$b;$tipos.='sss'; }
    if($carteira!==''){ $where.=' AND l.carteira_status=? ';$params[]=$carteira;$tipos.='s'; }
    $base=" FROM inadimplencia_lancamentos l LEFT JOIN moradores m ON m.id=l.morador_id AND m.tenant_id=l.tenant_id LEFT JOIN unidades u ON u.id=l.unidade_id AND u.tenant_id=l.tenant_id $where ";
    $group=' GROUP BY l.gleba_numero,l.carteira_status,l.permite_receber,l.participa_cobranca,l.proprietario_nome,l.proprietario_cpf,l.morador_id,l.unidade_id,m.nome,u.nome ';
    $orderMap=['divida_desc'=>'total_projetado DESC','divida_asc'=>'total_projetado ASC','gleba_asc'=>'CAST(l.gleba_numero AS UNSIGNED) ASC','meses_desc'=>'meses_aberto DESC'];$order=$orderMap[$filtros['ordem']??'divida_desc']??$orderMap['divida_desc'];
    $select='SELECT l.gleba_numero,l.carteira_status,l.permite_receber,l.participa_cobranca,MAX(l.proprietario_nome) proprietario_nome,MAX(l.proprietario_cpf) proprietario_cpf,MAX(l.morador_id) morador_id,MAX(l.unidade_id) unidade_id,MAX(m.nome) morador_nome,MAX(u.nome) unidade_nome,COUNT(DISTINCT l.mes_referencia) meses_aberto,SUM(l.valor) total_lancado,SUM(l.projecao_recebimento) total_projetado';
    $sql=$select.$base.$group.' ORDER BY '.$order;
    if($limite>0){$sql.=' LIMIT '.$limite;}else{$sql.=' LIMIT ? OFFSET ?';$params[]=$por;$params[]=($pagina-1)*$por;$tipos.='ii';}
    $stmt=$conn->prepare($sql);$stmt->bind_param($tipos,...$params);$stmt->execute();$res=$stmt->get_result();$itens=[];while($r=$res->fetch_assoc())$itens[]=$r;$stmt->close();
    if($limite>0)return $itens;
    $countSql='SELECT COUNT(*) total FROM (SELECT l.gleba_numero,l.proprietario_nome '.$base.$group.') x';$stmt=$conn->prepare($countSql);$stmt->bind_param(substr($tipos,0,strlen($tipos)-2),...array_slice($params,0,-2));$stmt->execute();$total=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();return ['itens'=>$itens,'total'=>$total,'pagina'=>$pagina,'total_paginas'=>max(1,(int)ceil($total/$por))];
}
function _distribuicaoCarteira($importacao_id){ global $conn,$tenant_id;$stmt=$conn->prepare("SELECT CASE WHEN participa_cobranca=0 THEN 'NÃO PARTICIPA DE COBRANÇA' WHEN carteira_status LIKE 'EM COBRANÇA EXTRA%' THEN 'EM COBRANÇA EXTRA JUDICIAL' WHEN carteira_status LIKE 'EM COBRANÇA JUDICIAL%' THEN 'EM COBRANÇA JUDICIAL' ELSE 'RECEBER' END carteira, SUM(projecao_recebimento) total, COUNT(DISTINCT gleba_numero) unidades FROM inadimplencia_lancamentos WHERE tenant_id=? AND importacao_id=? GROUP BY carteira ORDER BY total DESC");$stmt->bind_param('ii',$tenant_id,$importacao_id);$stmt->execute();$r=$stmt->get_result();$out=[];while($x=$r->fetch_assoc())$out[]=$x;$stmt->close();return$out;}
function _calcularHeuristica($atual_id,$anterior_id){ if(!$anterior_id)return['titulo'=>'Tendência baseada em histórico','mensagem'=>'São necessárias pelo menos duas importações para avaliar tendência.','risco_alto'=>[]];$atual=_obterImportacao($atual_id);$penultima=_obterImportacaoAnterior($anterior_id);$agora=_compararDados($atual_id,$anterior_id)['agregado'];$antes=$penultima?_compararDados($anterior_id,(int)$penultima['id'])['agregado']:[];$alto=[];foreach($agora['EVOLUINDO'] as $item){$g=$item['gleba_numero'];$evoluiaAntes=false;foreach($antes['EVOLUINDO'] as $a)if($a['gleba_numero']===$g){$evoluiaAntes=true;break;}if($evoluiaAntes)$alto[]=$item;}return['titulo'=>'Tendência baseada em histórico','mensagem'=>'Heurística explicável: risco alto indica aumento da dívida em duas importações consecutivas, sem quitação entre elas.','risco_alto'=>$alto];}
function _resolverVinculo($cpf,$gleba){global$conn,$tenant_id;$out=['morador_id'=>null,'unidade_id'=>null];$unidade='Gleba '.(int)$gleba;if($cpf){$stmt=$conn->prepare("SELECT id,unidade FROM moradores WHERE tenant_id=? AND REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','')=? LIMIT 1");$stmt->bind_param('is',$tenant_id,$cpf);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if($r)$out['morador_id']=(int)$r['id'];}$stmt=$conn->prepare('SELECT id FROM unidades WHERE tenant_id=? AND nome=? LIMIT 1');$stmt->bind_param('is',$tenant_id,$unidade);$stmt->execute();$u=$stmt->get_result()->fetch_assoc();$stmt->close();if($u)$out['unidade_id']=(int)$u['id'];if(!$out['morador_id']){$stmt=$conn->prepare('SELECT id FROM moradores WHERE tenant_id=? AND unidade=? AND ativo=1 ORDER BY id ASC LIMIT 1');$stmt->bind_param('is',$tenant_id,$unidade);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if($r)$out['morador_id']=(int)$r['id'];}return$out;}
function _marcarImportacaoErro($id,$erro){global$conn,$tenant_id;$stmt=$conn->prepare("UPDATE inadimplencia_importacoes SET status='ERRO',mensagem_erro=? WHERE id=? AND tenant_id=?");$stmt->bind_param('sii',$erro,$id,$tenant_id);$stmt->execute();$stmt->close();}
function _categoriaCobranca($descricao){$d=function_exists('mb_strtoupper')?mb_strtoupper($descricao,'UTF-8'):strtoupper($descricao);if(strpos($d,'TAXA ASSOCIATIVA')===0)return'Taxa Associativa';if(strpos($d,'LEITURA')===0&&strpos($d,'ÁGUA')!==false)return'Água';if(strpos($d,'ACORDO/NEGOCIAÇÃO')===0)return'Acordo/Negociação';if(strpos($d,'EXTRA')===0)return'Extras';return'Outros';}
function _normalizarMesReferencia($valor){$m=['JAN'=>'01','FEV'=>'02','MAR'=>'03','ABR'=>'04','MAI'=>'05','JUN'=>'06','JUL'=>'07','AGO'=>'08','SET'=>'09','OUT'=>'10','NOV'=>'11','DEZ'=>'12'];$valor=function_exists('mb_strtoupper')?mb_strtoupper(trim($valor),'UTF-8'):strtoupper(trim($valor));if(preg_match('/^([A-Z]{3})\/(\d{4})$/',$valor,$x)&&isset($m[$x[1]]))return$x[2].'-'.$m[$x[1]].'-01';return null;}
function _normalizarValor($v){if($v===null||$v==='')return null;$v=trim(str_replace(['R$',' '],'',$v));if(preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$/',$v))$v=str_replace(',','.',str_replace('.','',$v));else $v=str_replace(',','',$v);return is_numeric($v)?(float)$v:null;}
function _normalizarData($d){$d=trim((string)$d);return preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/',$d,$m)?$m[3].'-'.$m[2].'-'.$m[1]:null;}
function _chaveTexto($v){$v=function_exists('mb_strtolower')?mb_strtolower((string)$v,'UTF-8'):strtolower((string)$v);if(function_exists('iconv'))$v=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);return preg_replace('/[^a-z0-9]+/','-',trim((string)$v));}
?>
