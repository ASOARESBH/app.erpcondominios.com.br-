<?php
/**
 * ============================================================
 * API DE IMPORTAÇÃO FINANCEIRA — ERP Condomínio
 * ============================================================
 * Suporta:
 *   - Extrato Financeiro BRCondos (PDF com pdftotext -layout)
 *   - Contas a Pagar BRCondos (PDF)
 *   - CSV genérico (qualquer sistema)
 *
 * Ações disponíveis (POST/GET):
 *   importar            — Upload e processa o arquivo
 *   listar_lotes        — Lista os lotes de importação
 *   listar_itens        — Lista itens de um lote
 *   conciliar_item      — Conciliação manual de duplicata
 *   ignorar_item        — Ignora um item
 *   confirmar_importar  — Importa itens PENDENTES para contas_pagar/receber
 *   excluir_lote        — Remove lote e seus itens
 *   resumo_lote         — KPIs e agrupamentos de um lote para visualização
 *
 * @version 2.0.0
 */
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'log_financeiro_helper.php';

$conn         = conectar_banco();
$usuario      = verificarAutenticacao(true, 'operador');
$usuario_nome = $usuario['nome'] ?? 'Sistema';

date_default_timezone_set('America/Sao_Paulo');

// ── Criar tabelas se não existirem ────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `importacoes_financeiras` (
  `id`               int(11)      NOT NULL AUTO_INCREMENT,
  `nome_arquivo`     varchar(255) NOT NULL,
  `tipo_arquivo`     enum('CSV','PDF') NOT NULL DEFAULT 'CSV',
  `tipo_conta`       enum('PAGAR','RECEBER','EXTRATO') NOT NULL DEFAULT 'PAGAR',
  `formato_origem`   varchar(50)  DEFAULT NULL COMMENT 'brcondos_extrato|brcondos_pagar|generico',
  `data_inicio`      date         DEFAULT NULL,
  `data_fim`         date         DEFAULT NULL,
  `total_registros`  int(11)      DEFAULT 0,
  `total_importados` int(11)      DEFAULT 0,
  `total_duplicatas` int(11)      DEFAULT 0,
  `total_erros`      int(11)      DEFAULT 0,
  `total_entradas`   decimal(14,2) DEFAULT 0.00,
  `total_saidas`     decimal(14,2) DEFAULT 0.00,
  `saldo_final`      decimal(14,2) DEFAULT 0.00,
  `status`           enum('PROCESSANDO','CONCLUIDO','ERRO') DEFAULT 'PROCESSANDO',
  `usuario`          varchar(100) DEFAULT NULL,
  `data_importacao`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `importacoes_financeiras_itens` (
  `id`                    int(11)      NOT NULL AUTO_INCREMENT,
  `importacao_id`         int(11)      NOT NULL,
  `linha_original`        int(11)      DEFAULT NULL,
  `numero_documento`      varchar(100) DEFAULT NULL,
  `fornecedor_nome`       varchar(255) DEFAULT NULL,
  `classificacao_despesa` varchar(255) DEFAULT NULL,
  `centro_custo`          varchar(100) DEFAULT NULL,
  `historico_completo`    text         DEFAULT NULL,
  `observacao`            text         DEFAULT NULL,
  `tipo_lancamento`       enum('ENTRADA','SAIDA','TRANSFERENCIA','TARIFA','SALDO') DEFAULT 'SAIDA',
  `valor`                 decimal(12,2) DEFAULT NULL,
  `valor_entrada`         decimal(12,2) DEFAULT 0.00,
  `valor_saida`           decimal(12,2) DEFAULT 0.00,
  `saldo_apos`            decimal(12,2) DEFAULT NULL,
  `data_lancamento`       date         DEFAULT NULL,
  `data_vencimento`       date         DEFAULT NULL,
  `data_pagamento`        date         DEFAULT NULL,
  `status_original`       varchar(50)  DEFAULT NULL,
  `aprovada`              tinyint(1)   DEFAULT 0,
  `status_importacao`     enum('PENDENTE','IMPORTADO','DUPLICATA','ERRO','CONCILIADO','IGNORADO') DEFAULT 'PENDENTE',
  `conta_id`              int(11)      DEFAULT NULL,
  `duplicata_conta_id`    int(11)      DEFAULT NULL,
  `mensagem_erro`         text         DEFAULT NULL,
  `conciliado_por`        varchar(100) DEFAULT NULL,
  `data_conciliacao`      timestamp    NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_importacao_id` (`importacao_id`),
  KEY `idx_data_lancamento` (`data_lancamento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Roteamento ────────────────────────────────────────────────
$acao = $_REQUEST['acao'] ?? '';

switch ($acao) {
    case 'importar':            _importar();           break;
    case 'listar_lotes':        _listarLotes();        break;
    case 'listar_itens':        _listarItens();        break;
    case 'conciliar_item':      _conciliarItem();      break;
    case 'ignorar_item':        _ignorarItem();        break;
    case 'confirmar_importar':  _confirmarImportar();  break;
    case 'excluir_lote':        _excluirLote();        break;
    case 'resumo_lote':         _resumoLote();         break;
    default:
        retornar_json(false, 'Ação inválida: ' . htmlspecialchars($acao));
}

// ══════════════════════════════════════════════════════════════
// IMPORTAR
// ══════════════════════════════════════════════════════════════
function _importar() {
    global $conn, $usuario_nome;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        retornar_json(false, 'Método inválido'); return;
    }

    $tipo_conta  = strtoupper(trim($_POST['tipo_conta']  ?? 'PAGAR'));
    $data_inicio = trim($_POST['data_inicio'] ?? '');
    $data_fim    = trim($_POST['data_fim']    ?? '');

    if (!in_array($tipo_conta, ['PAGAR','RECEBER','EXTRATO'])) {
        log_fin('importacao', 'ERRO', 'importar', 'Tipo de conta inválido recebido: ' . $tipo_conta, json_encode(['post' => $_POST]));
        retornar_json(false, 'Tipo de conta inválido. Use PAGAR, RECEBER ou EXTRATO. Recebido: ' . htmlspecialchars($tipo_conta)); return;
    }
    if (empty($_FILES['arquivo']['tmp_name'])) {
        log_fin('importacao', 'ERRO', 'importar', 'Nenhum arquivo enviado na requisição', json_encode(['post' => $_POST]));
        retornar_json(false, 'Nenhum arquivo enviado.'); return;
    }

    $arquivo   = $_FILES['arquivo'];
    $nome_orig = $arquivo['name'];
    $tmp       = $arquivo['tmp_name'];
    $ext       = strtoupper(pathinfo($nome_orig, PATHINFO_EXTENSION));

    if (!in_array($ext, ['CSV','PDF'])) {
        log_fin('importacao', 'ERRO', 'importar', 'Formato de arquivo não suportado: ' . $ext, 'Arquivo: ' . $nome_orig);
        retornar_json(false, 'Formato não suportado. Envie CSV ou PDF.'); return;
    }

    // Detectar formato de origem
    $formato = 'generico';
    if ($ext === 'PDF') {
        $nome_lower = strtolower($nome_orig);
        if (strpos($nome_lower, 'extratofinanceiro') !== false || strpos($nome_lower, 'extrato') !== false) {
            $formato = 'brcondos_extrato';
            $tipo_conta = 'EXTRATO'; // extrato tem entradas e saídas
        } elseif (strpos($nome_lower, 'contasapagar') !== false || strpos($nome_lower, 'contas_a_pagar') !== false) {
            $formato = 'brcondos_pagar';
        }
    }

    // Criar lote
    $stmt = $conn->prepare("INSERT INTO importacoes_financeiras
        (nome_arquivo, tipo_arquivo, tipo_conta, formato_origem, data_inicio, data_fim, status, usuario)
        VALUES (?, ?, ?, ?, ?, ?, 'PROCESSANDO', ?)");
    $di = $data_inicio ?: null;
    $df = $data_fim    ?: null;
    $stmt->bind_param('sssssss', $nome_orig, $ext, $tipo_conta, $formato, $di, $df, $usuario_nome);
    $stmt->execute();
    $lote_id = $conn->insert_id;
    $stmt->close();

    // Parse
    if ($ext === 'CSV') {
        $itens = _parseCSV($tmp, $data_inicio, $data_fim);
    } elseif ($formato === 'brcondos_extrato') {
        $itens = _parseExtratoFinanceiroBRCondos($tmp, $data_inicio, $data_fim);
    } else {
        $itens = _parsePDFContasPagar($tmp, $data_inicio, $data_fim);
    }

    if (empty($itens)) {
        $conn->query("UPDATE importacoes_financeiras SET status='ERRO', total_registros=0 WHERE id={$lote_id}");
        log_fin('importacao', 'AVISO', 'importar', 'Nenhum registro encontrado no arquivo', 'Arquivo: ' . $nome_orig . ' | Formato: ' . $formato . ' | Período: ' . $data_inicio . ' a ' . $data_fim, $lote_id);
        retornar_json(false, 'Nenhum registro encontrado no arquivo. Verifique o formato ou o período informado.'); return;
    }

    // Inserir itens e detectar duplicatas
    $total = count($itens);
    $pendentes = 0; $duplicatas = 0; $erros = 0;
    $sum_entradas = 0.0; $sum_saidas = 0.0;

    foreach ($itens as $idx => $item) {
        $status_imp = 'PENDENTE';
        $dup_id     = null;

        // Acumular totais
        $sum_entradas += (float)($item['valor_entrada'] ?? 0);
        $sum_saidas   += (float)($item['valor_saida']   ?? 0);

        // Detectar duplicata (apenas para SAÍDA/PAGAR)
        $tipo_lanc = $item['tipo_lancamento'] ?? 'SAIDA';
        if (in_array($tipo_lanc, ['SAIDA','TARIFA']) && !empty($item['fornecedor_nome']) && !empty($item['valor']) && !empty($item['data_lancamento'])) {
            $fn  = $item['fornecedor_nome'];
            $val = (float)$item['valor'];
            $dv  = $item['data_lancamento'];
            $chk = $conn->prepare("SELECT id FROM contas_pagar WHERE fornecedor_nome = ? AND valor_original = ? AND data_vencimento = ? LIMIT 1");
            $chk->bind_param('sds', $fn, $val, $dv);
            $chk->execute();
            $res = $chk->get_result();
            if ($res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $status_imp = 'DUPLICATA';
                $dup_id     = $row['id'];
                $duplicatas++;
            }
            $chk->close();
        }

        // Inserir item
        $ins = $conn->prepare("INSERT INTO importacoes_financeiras_itens
            (importacao_id, linha_original, numero_documento, fornecedor_nome,
             classificacao_despesa, centro_custo, historico_completo, observacao,
             tipo_lancamento, valor, valor_entrada, valor_saida, saldo_apos,
             data_lancamento, data_vencimento, data_pagamento,
             status_original, aprovada, status_importacao, duplicata_conta_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $linha  = $idx + 1;
        $num    = $item['numero_documento']      ?? null;
        $forn   = $item['fornecedor_nome']       ?? null;
        $class  = $item['classificacao_despesa'] ?? null;
        $cc     = $item['centro_custo']          ?? null;
        $hist   = $item['historico_completo']    ?? null;
        $obs    = $item['observacao']            ?? null;
        $tlanc  = $item['tipo_lancamento']       ?? 'SAIDA';
        $val    = isset($item['valor'])          ? (float)$item['valor']          : null;
        $vent   = isset($item['valor_entrada'])  ? (float)$item['valor_entrada']  : 0.0;
        $vsai   = isset($item['valor_saida'])    ? (float)$item['valor_saida']    : 0.0;
        $saldo  = isset($item['saldo_apos'])     ? (float)$item['saldo_apos']     : null;
        $dl     = $item['data_lancamento']       ?? null;
        $dv     = $item['data_vencimento']       ?? $dl;
        $dp     = $item['data_pagamento']        ?? null;
        $stori  = $item['status_original']       ?? null;
        $aprov  = isset($item['aprovada'])       ? (int)$item['aprovada']         : 0;

        $ins->bind_param('iisssssssddddssssis',
            $lote_id, $linha, $num, $forn, $class, $cc, $hist, $obs,
            $tlanc, $val, $vent, $vsai, $saldo,
            $dl, $dv, $dp, $stori, $aprov, $status_imp, $dup_id
        );
        if ($ins->execute()) {
            if ($status_imp === 'PENDENTE') $pendentes++;
        } else {
            $erros++;
        }
        $ins->close();
    }

    // Calcular saldo final do extrato
    $saldo_final = $sum_entradas - $sum_saidas;

    // Atualizar lote
    $conn->query("UPDATE importacoes_financeiras SET
        status='CONCLUIDO',
        total_registros={$total},
        total_importados={$pendentes},
        total_duplicatas={$duplicatas},
        total_erros={$erros},
        total_entradas={$sum_entradas},
        total_saidas={$sum_saidas},
        saldo_final={$saldo_final}
        WHERE id={$lote_id}");

    retornar_json(true, 'Arquivo processado com sucesso', [
        'lote_id'    => $lote_id,
        'total'      => $total,
        'pendentes'  => $pendentes,
        'duplicatas' => $duplicatas,
        'erros'      => $erros,
        'entradas'   => $sum_entradas,
        'saidas'     => $sum_saidas,
        'formato'    => $formato
    ]);
}

// ══════════════════════════════════════════════════════════════
// PARSER — EXTRATO FINANCEIRO BRCONDOS (PDF com pdftotext -layout)
// Formato: colunas de posição fixa
//   Col 0-10   : Data (DD/MM/YYYY) — pode estar em branco (linha de continuação)
//   Col 11-90  : Histórico (texto livre, pode ter múltiplas linhas)
//   Col 90-105 : Entrada (valor positivo)
//   Col 105-120: Saída   (valor negativo)
//   Col 120+   : Saldo
// ══════════════════════════════════════════════════════════════
function _parseExtratoFinanceiroBRCondos($path, $data_inicio, $data_fim) {
    $pdftotext = trim(shell_exec('which pdftotext 2>/dev/null'));
    if (empty($pdftotext)) return [];

    $texto = shell_exec("pdftotext -layout " . escapeshellarg($path) . " - 2>/dev/null");
    if (empty($texto)) return [];

    $linhas = explode("\n", $texto);
    $itens  = [];
    $atual  = null; // lançamento em construção

    // Regex para linha com data (DD/MM/YYYY no início, com possíveis espaços antes)
    $reData = '/^\s{0,5}(\d{2}\/\d{2}\/\d{4})\s+(.+?)\s{2,}([\d\.,]+)?\s*([\d\.,]+)?\s*([\d\.,]+)?\s*$/';

    // Regex para linha com data e valores nas colunas fixas
    // Detectar posição dos números no final da linha
    $reValores = '/([\d\.]+,\d{2})\s*$/'; // último número = saldo
    $reNumeros = '/\b([\d\.]+,\d{2})\b/g';

    foreach ($linhas as $linha_raw) {
        // Ignorar linhas de cabeçalho/rodapé
        if (preg_match('/^(Extrato Financeiro|Totalizadores|Filtros|Período|Conta|Centro|Data\s+Histórico|Qtd\.|Saldo\s+R\$|^\s*$)/i', trim($linha_raw))) {
            continue;
        }

        // Verificar se a linha começa com uma data
        if (preg_match('/^\s{0,5}(\d{2}\/\d{2}\/\d{4})\s/', $linha_raw, $mData)) {
            // Salvar lançamento anterior
            if ($atual !== null) {
                $item = _finalizarItemExtrato($atual, $data_inicio, $data_fim);
                if ($item) $itens[] = $item;
            }

            // Extrair valores numéricos do final da linha
            $valores = _extrairValoresLinha($linha_raw);

            // Extrair histórico (texto entre a data e os números)
            $data_str = trim($mData[1]);
            $resto    = substr($linha_raw, strpos($linha_raw, $data_str) + strlen($data_str));
            $hist_raw = _removerValoresDoFinal($resto, $valores);

            $atual = [
                'data'       => _normalizarData($data_str),
                'historico'  => [trim($hist_raw)],
                'entrada'    => $valores['entrada'],
                'saida'      => $valores['saida'],
                'saldo'      => $valores['saldo']
            ];
        } else {
            // Linha de continuação do histórico
            if ($atual !== null) {
                $linha_limpa = trim($linha_raw);
                if (!empty($linha_limpa) && strlen($linha_limpa) > 2) {
                    // Verificar se não são apenas números (não é linha de dados)
                    if (!preg_match('/^\s*([\d\.]+,\d{2}\s*){1,3}\s*$/', $linha_raw)) {
                        $atual['historico'][] = $linha_limpa;
                    }
                }
            }
        }
    }

    // Último item
    if ($atual !== null) {
        $item = _finalizarItemExtrato($atual, $data_inicio, $data_fim);
        if ($item) $itens[] = $item;
    }

    return $itens;
}

function _extrairValoresLinha($linha) {
    // Encontrar todos os números no formato brasileiro no final da linha
    // Padrão: podem ser 1, 2 ou 3 números no final (entrada/saída/saldo ou apenas saldo)
    $resultado = ['entrada' => null, 'saida' => null, 'saldo' => null];

    // Extrair todos os números da linha
    preg_match_all('/\b(\d{1,3}(?:\.\d{3})*,\d{2})\b/', $linha, $matches);
    $nums = $matches[1] ?? [];

    if (count($nums) === 0) return $resultado;
    if (count($nums) === 1) {
        $resultado['saldo'] = _normalizarValor($nums[0]);
        return $resultado;
    }
    if (count($nums) === 2) {
        // Pode ser (entrada, saldo) ou (saída, saldo)
        // Verificar pela posição na linha — o saldo é sempre o último
        $resultado['saldo'] = _normalizarValor($nums[count($nums)-1]);
        $primeiro = _normalizarValor($nums[0]);
        // Verificar se há sinal negativo antes do número na linha
        $pos_num = strrpos($linha, $nums[0]);
        if ($pos_num > 0 && substr($linha, $pos_num-1, 1) === '-') {
            $resultado['saida'] = $primeiro;
        } else {
            $resultado['entrada'] = $primeiro;
        }
        return $resultado;
    }
    // 3 ou mais números: entrada, saída, saldo (mas geralmente só 2 dos 3 estão presentes)
    // Usar posição na linha para determinar
    $resultado['saldo'] = _normalizarValor($nums[count($nums)-1]);
    // Verificar posições dos outros números para determinar se são entrada ou saída
    // Estratégia: verificar se o número é precedido por espaços em branco longos (coluna de saída)
    // ou se está mais à esquerda (coluna de entrada)
    // Simplificação: o penúltimo é saída se for negativo no contexto, senão entrada
    if (count($nums) >= 2) {
        $penultimo = _normalizarValor($nums[count($nums)-2]);
        // Verificar contexto: se a linha tem "PAGAMENTO" ou "TARIFA" é saída
        if (preg_match('/PAGAMENTO|TARIFA|SAÍDA|DEBIT/i', $linha)) {
            $resultado['saida'] = $penultimo;
        } else {
            $resultado['entrada'] = $penultimo;
        }
    }
    return $resultado;
}

function _removerValoresDoFinal($texto, $valores) {
    // Remover os números encontrados do final do texto para obter apenas o histórico
    $texto = preg_replace('/\s+\d{1,3}(?:\.\d{3})*,\d{2}(?:\s+\d{1,3}(?:\.\d{3})*,\d{2})*\s*$/', '', $texto);
    return trim($texto);
}

function _finalizarItemExtrato($atual, $data_inicio, $data_fim) {
    if (empty($atual['data'])) return null;

    // Filtrar por período
    if ($data_inicio && $atual['data'] < $data_inicio) return null;
    if ($data_fim    && $atual['data'] > $data_fim)    return null;

    $historico_completo = implode(' ', array_filter($atual['historico']));
    $historico_completo = preg_replace('/\s+/', ' ', trim($historico_completo));

    // Ignorar linhas de saldo inicial
    if (preg_match('/^SALDO INICIAL/i', $historico_completo)) return null;

    // Determinar tipo de lançamento
    $tipo = 'SAIDA';
    $valor_entrada = 0.0;
    $valor_saida   = 0.0;
    $valor         = 0.0;

    if (!empty($atual['entrada'])) {
        $tipo          = 'ENTRADA';
        $valor_entrada = (float)$atual['entrada'];
        $valor         = $valor_entrada;
    } elseif (!empty($atual['saida'])) {
        $tipo        = 'SAIDA';
        $valor_saida = (float)$atual['saida'];
        $valor       = $valor_saida;
        if (preg_match('/TARIFA BANCARIA/i', $historico_completo)) $tipo = 'TARIFA';
        if (preg_match('/Transfer[eê]ncia/i', $historico_completo)) $tipo = 'TRANSFERENCIA';
    }

    // Extrair fornecedor/origem do histórico
    $fornecedor = _extrairFornecedor($historico_completo);

    // Extrair número de fatura
    $num_fat = null;
    if (preg_match('/Fat\.\s*:?\s*(\d+)/i', $historico_completo, $mf)) {
        $num_fat = $mf[1];
    }

    // Classificação automática
    $class = _classificarHistorico($historico_completo);

    return [
        'numero_documento'      => $num_fat,
        'fornecedor_nome'       => $fornecedor,
        'classificacao_despesa' => $class,
        'historico_completo'    => $historico_completo,
        'observacao'            => $historico_completo,
        'tipo_lancamento'       => $tipo,
        'valor'                 => $valor,
        'valor_entrada'         => $valor_entrada,
        'valor_saida'           => $valor_saida,
        'saldo_apos'            => $atual['saldo'] ? (float)$atual['saldo'] : null,
        'data_lancamento'       => $atual['data'],
        'data_vencimento'       => $atual['data'],
        'data_pagamento'        => $tipo === 'SAIDA' ? $atual['data'] : null,
        'status_original'       => $tipo === 'ENTRADA' ? 'Recebido' : 'Pago',
        'aprovada'              => 1
    ];
}

function _extrairFornecedor($historico) {
    // Padrão: "FORNECEDOR - DESCRIÇÃO" ou "GLEBA, N° X - RECEBIMENTO..."
    if (preg_match('/^(GLEBA,\s*N[°º]\s*\d+)/i', $historico, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/^([^-]+?)\s+-\s+/i', $historico, $m)) {
        return trim($m[1]);
    }
    // Pegar as primeiras palavras
    $palavras = explode(' ', $historico);
    return implode(' ', array_slice($palavras, 0, min(5, count($palavras))));
}

function _classificarHistorico($historico) {
    $h = strtoupper($historico);
    if (strpos($h, 'TARIFA BANCARIA') !== false || strpos($h, 'TARIFA AUTORIZ') !== false) return 'Tarifa Bancária';
    if (strpos($h, 'SALÁRIO') !== false || strpos($h, 'SALARIOS') !== false) return 'Folha de Pagamento';
    if (strpos($h, 'FGTS') !== false) return 'Encargos Trabalhistas';
    if (strpos($h, 'ENERGIA') !== false || strpos($h, 'CEMIG') !== false) return 'Energia Elétrica';
    if (strpos($h, 'ÁGUA') !== false || strpos($h, 'AGUA') !== false || strpos($h, 'POÇO') !== false) return 'Água / Saneamento';
    if (strpos($h, 'ADMINISTRAÇÃO') !== false || strpos($h, 'ADMINISTRADORA') !== false) return 'Administração';
    if (strpos($h, 'SÍNDICO') !== false || strpos($h, 'SINDICO') !== false) return 'Ajuda de Custo Síndico';
    if (strpos($h, 'SEGURANÇA') !== false || strpos($h, 'CFTV') !== false || strpos($h, 'PORTARIA') !== false) return 'Segurança / Portaria';
    if (strpos($h, 'MANUTENÇÃO') !== false || strpos($h, 'MANUTENCAO') !== false) return 'Manutenção';
    if (strpos($h, 'INTERNET') !== false || strpos($h, 'VIVO') !== false) return 'Telecomunicações';
    if (strpos($h, 'COMBUSTÍVEL') !== false || strpos($h, 'COMBUSTIVEL') !== false) return 'Combustível';
    if (strpos($h, 'DARF') !== false || strpos($h, 'TRIBUTO') !== false || strpos($h, 'IMPOSTO') !== false) return 'Impostos / Tributos';
    if (strpos($h, 'TRANSFERÊNCIA') !== false || strpos($h, 'TRANSFERENCIA') !== false) return 'Transferência';
    if (strpos($h, 'RECEBIMENTO') !== false || strpos($h, 'TAXA ASSOCIATIVA') !== false) return 'Taxa Associativa';
    if (strpos($h, 'ACORDO') !== false || strpos($h, 'NEGOCIAÇÃO') !== false) return 'Acordo / Negociação';
    return 'Outros';
}

// ══════════════════════════════════════════════════════════════
// PARSER — CONTAS A PAGAR BRCONDOS (PDF)
// ══════════════════════════════════════════════════════════════
function _parsePDFContasPagar($path, $data_inicio, $data_fim) {
    $pdftotext = trim(shell_exec('which pdftotext 2>/dev/null'));
    if (empty($pdftotext)) return [];
    $texto = shell_exec("pdftotext -layout " . escapeshellarg($path) . " - 2>/dev/null");
    if (empty($texto)) return [];

    $itens  = [];
    $linhas = explode("\n", $texto);

    foreach ($linhas as $linha_txt) {
        $linha_txt = trim($linha_txt);
        if (strlen($linha_txt) < 10) continue;

        // Padrão: ID Fornecedor DataVence DataPago Valor ... Aprovada Status
        if (preg_match('/^(\d{6,10})\s+(.+?)\s+(\d{2}\/\d{2}\/\d{4})\s+(\d{2}\/\d{2}\/\d{4})\s+([\d\.,]+)/', $linha_txt, $m)) {
            $dv = _normalizarData($m[3]);
            $dp = _normalizarData($m[4]);
            if ($data_inicio && $dv < $data_inicio) continue;
            if ($data_fim    && $dv > $data_fim)    continue;
            $val = _normalizarValor($m[5]);
            $itens[] = [
                'numero_documento'      => $m[1],
                'fornecedor_nome'       => trim($m[2]),
                'historico_completo'    => trim($linha_txt),
                'observacao'            => trim($linha_txt),
                'tipo_lancamento'       => 'SAIDA',
                'valor'                 => $val,
                'valor_entrada'         => 0,
                'valor_saida'           => $val,
                'data_lancamento'       => $dv,
                'data_vencimento'       => $dv,
                'data_pagamento'        => $dp,
                'status_original'       => 'Pago',
                'aprovada'              => 1,
                'classificacao_despesa' => _classificarHistorico($m[2])
            ];
        }
    }
    return $itens;
}

// ══════════════════════════════════════════════════════════════
// PARSER — CSV GENÉRICO
// ══════════════════════════════════════════════════════════════
function _parseCSV($path, $data_inicio, $data_fim) {
    $itens  = [];
    $handle = fopen($path, 'r');
    if (!$handle) return $itens;

    $primeira = fgets($handle);
    rewind($handle);
    $delim = (substr_count($primeira, ';') > substr_count($primeira, ',')) ? ';' : ',';

    $header = fgetcsv($handle, 0, $delim);
    if (!$header) { fclose($handle); return $itens; }
    $header = array_map(fn($h) => mb_strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $h))), $header);

    $map = [
        'numero_documento'      => ['_', 'id', 'numero', 'num', 'fatura', 'cod'],
        'fornecedor_nome'       => ['fornecedor', 'fornecedor_nome', 'nome', 'empresa', 'credor', 'historico'],
        'classificacao_despesa' => ['classificacao', 'classifica__o_da_despesa', 'categoria'],
        'observacao'            => ['observa__o', 'observacao', 'descricao', 'historico'],
        'valor'                 => ['valor', 'valor_r_', 'valor_original', 'total'],
        'valor_entrada'         => ['entrada', 'credito', 'receita'],
        'valor_saida'           => ['saida', 'debito', 'despesa'],
        'data_lancamento'       => ['data', 'data_lancamento', 'dt_lancamento'],
        'data_vencimento'       => ['vence_em', 'vencimento', 'data_vencimento'],
        'data_pagamento'        => ['pago_em', 'pagamento', 'data_pagamento'],
        'status_original'       => ['status', 'situacao'],
        'aprovada'              => ['aprovada', 'aprovado']
    ];

    $idx_map = [];
    foreach ($map as $campo => $aliases) {
        foreach ($aliases as $alias) {
            $pos = array_search($alias, $header);
            if ($pos !== false) { $idx_map[$campo] = $pos; break; }
        }
    }

    while (($row = fgetcsv($handle, 0, $delim)) !== false) {
        if (count(array_filter($row)) === 0) continue;
        $item = [];
        foreach ($idx_map as $campo => $pos) {
            $item[$campo] = isset($row[$pos]) ? trim($row[$pos]) : null;
        }

        foreach (['valor','valor_entrada','valor_saida'] as $vc) {
            if (!empty($item[$vc])) $item[$vc] = _normalizarValor($item[$vc]);
        }
        foreach (['data_lancamento','data_vencimento','data_pagamento'] as $dc) {
            if (!empty($item[$dc])) $item[$dc] = _normalizarData($item[$dc]);
        }

        $dv = $item['data_vencimento'] ?? $item['data_lancamento'] ?? null;
        if ($data_inicio && $dv && $dv < $data_inicio) continue;
        if ($data_fim    && $dv && $dv > $data_fim)    continue;

        // Determinar tipo
        $vent = (float)($item['valor_entrada'] ?? 0);
        $vsai = (float)($item['valor_saida']   ?? 0);
        $val  = (float)($item['valor']         ?? max($vent, $vsai));
        $tipo = $vent > 0 ? 'ENTRADA' : 'SAIDA';
        if ($val == 0 && $vent > 0) { $val = $vent; }
        if ($val == 0 && $vsai > 0) { $val = $vsai; }

        $item['tipo_lancamento'] = $tipo;
        $item['valor']           = $val;
        $item['valor_entrada']   = $vent;
        $item['valor_saida']     = $vsai;
        $item['historico_completo'] = $item['observacao'] ?? ($item['fornecedor_nome'] ?? '');
        $item['aprovada']        = (!empty($item['aprovada']) && strtolower($item['aprovada']) === 'sim') ? 1 : 0;
        $item['data_lancamento'] = $item['data_lancamento'] ?? $dv;
        $itens[] = $item;
    }
    fclose($handle);
    return $itens;
}

// ══════════════════════════════════════════════════════════════
// LISTAR LOTES
// ══════════════════════════════════════════════════════════════
function _listarLotes() {
    global $conn;
    $res   = $conn->query("SELECT * FROM importacoes_financeiras ORDER BY data_importacao DESC LIMIT 100");
    $lotes = [];
    while ($row = $res->fetch_assoc()) $lotes[] = $row;
    retornar_json(true, 'OK', $lotes);
}

// ══════════════════════════════════════════════════════════════
// LISTAR ITENS DE UM LOTE
// ══════════════════════════════════════════════════════════════
function _listarItens() {
    global $conn;
    $lote_id = (int)($_GET['lote_id'] ?? 0);
    $status  = trim($_GET['status']   ?? '');
    $tipo    = trim($_GET['tipo']     ?? '');
    $busca   = trim($_GET['busca']    ?? '');
    $pagina  = max(1, (int)($_GET['pagina'] ?? 1));
    $por_pag = min(500, max(10, (int)($_GET['por_pagina'] ?? 100)));
    $offset  = ($pagina - 1) * $por_pag;

    if (!$lote_id) { retornar_json(false, 'lote_id obrigatório'); return; }

    $where = ["importacao_id = {$lote_id}"];
    if ($status) $where[] = "status_importacao = '" . $conn->real_escape_string($status) . "'";
    if ($tipo)   $where[] = "tipo_lancamento = '"   . $conn->real_escape_string($tipo)   . "'";
    if ($busca)  $where[] = "(fornecedor_nome LIKE '%" . $conn->real_escape_string($busca) . "%' OR historico_completo LIKE '%" . $conn->real_escape_string($busca) . "%')";

    $where_sql = implode(' AND ', $where);

    $total_res = $conn->query("SELECT COUNT(*) as c FROM importacoes_financeiras_itens WHERE {$where_sql}");
    $total_rows = (int)$total_res->fetch_assoc()['c'];

    $res   = $conn->query("SELECT * FROM importacoes_financeiras_itens WHERE {$where_sql} ORDER BY data_lancamento ASC, linha_original ASC LIMIT {$por_pag} OFFSET {$offset}");
    $itens = [];
    while ($row = $res->fetch_assoc()) $itens[] = $row;

    retornar_json(true, 'OK', [
        'itens'       => $itens,
        'total'       => $total_rows,
        'pagina'      => $pagina,
        'por_pagina'  => $por_pag,
        'paginas'     => ceil($total_rows / $por_pag)
    ]);
}

// ══════════════════════════════════════════════════════════════
// RESUMO DO LOTE — KPIs e agrupamentos para visualização
// ══════════════════════════════════════════════════════════════
function _resumoLote() {
    global $conn;
    $lote_id = (int)($_GET['lote_id'] ?? 0);
    if (!$lote_id) { retornar_json(false, 'lote_id obrigatório'); return; }

    // Dados do lote
    $res_lote = $conn->query("SELECT * FROM importacoes_financeiras WHERE id={$lote_id}");
    if (!$res_lote || $res_lote->num_rows === 0) { retornar_json(false, 'Lote não encontrado'); return; }
    $lote = $res_lote->fetch_assoc();

    // Totais por tipo de lançamento
    $res_tipos = $conn->query("SELECT tipo_lancamento,
        COUNT(*) as qtd,
        SUM(valor_entrada) as total_entrada,
        SUM(valor_saida) as total_saida
        FROM importacoes_financeiras_itens
        WHERE importacao_id={$lote_id}
        GROUP BY tipo_lancamento ORDER BY total_saida DESC");
    $por_tipo = [];
    while ($row = $res_tipos->fetch_assoc()) $por_tipo[] = $row;

    // Top 10 fornecedores por valor de saída
    $res_forn = $conn->query("SELECT fornecedor_nome,
        COUNT(*) as qtd,
        SUM(valor_saida) as total_saida,
        SUM(valor_entrada) as total_entrada
        FROM importacoes_financeiras_itens
        WHERE importacao_id={$lote_id} AND fornecedor_nome IS NOT NULL
        GROUP BY fornecedor_nome ORDER BY total_saida DESC LIMIT 15");
    $top_fornecedores = [];
    while ($row = $res_forn->fetch_assoc()) $top_fornecedores[] = $row;

    // Por classificação
    $res_class = $conn->query("SELECT classificacao_despesa,
        COUNT(*) as qtd,
        SUM(valor_saida) as total_saida,
        SUM(valor_entrada) as total_entrada
        FROM importacoes_financeiras_itens
        WHERE importacao_id={$lote_id}
        GROUP BY classificacao_despesa ORDER BY total_saida DESC");
    $por_classificacao = [];
    while ($row = $res_class->fetch_assoc()) $por_classificacao[] = $row;

    // Por mês
    $res_mes = $conn->query("SELECT DATE_FORMAT(data_lancamento,'%Y-%m') as mes,
        COUNT(*) as qtd,
        SUM(valor_entrada) as total_entrada,
        SUM(valor_saida) as total_saida
        FROM importacoes_financeiras_itens
        WHERE importacao_id={$lote_id} AND data_lancamento IS NOT NULL
        GROUP BY mes ORDER BY mes ASC");
    $por_mes = [];
    while ($row = $res_mes->fetch_assoc()) $por_mes[] = $row;

    retornar_json(true, 'OK', [
        'lote'              => $lote,
        'por_tipo'          => $por_tipo,
        'top_fornecedores'  => $top_fornecedores,
        'por_classificacao' => $por_classificacao,
        'por_mes'           => $por_mes
    ]);
}

// ══════════════════════════════════════════════════════════════
// CONCILIAR ITEM
// ══════════════════════════════════════════════════════════════
function _conciliarItem() {
    global $conn, $usuario_nome;
    $data     = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $item_id  = (int)($data['item_id']  ?? 0);
    $conta_id = (int)($data['conta_id'] ?? 0);
    if (!$item_id) { retornar_json(false, 'item_id obrigatório'); return; }
    $stmt = $conn->prepare("UPDATE importacoes_financeiras_itens SET
        status_importacao='CONCILIADO', conta_id=?, conciliado_por=?, data_conciliacao=NOW()
        WHERE id=?");
    $cid = $conta_id ?: null;
    $stmt->bind_param('isi', $cid, $usuario_nome, $item_id);
    $stmt->execute(); $stmt->close();
    retornar_json(true, 'Item conciliado com sucesso');
}

// ══════════════════════════════════════════════════════════════
// IGNORAR ITEM
// ══════════════════════════════════════════════════════════════
function _ignorarItem() {
    global $conn;
    $data    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $item_id = (int)($data['item_id'] ?? 0);
    if (!$item_id) { retornar_json(false, 'item_id obrigatório'); return; }
    $conn->query("UPDATE importacoes_financeiras_itens SET status_importacao='IGNORADO' WHERE id={$item_id}");
    retornar_json(true, 'Item ignorado');
}

// ══════════════════════════════════════════════════════════════
// CONFIRMAR IMPORTAÇÃO
// ══════════════════════════════════════════════════════════════
function _confirmarImportar() {
    global $conn, $usuario_nome;
    $data    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $lote_id = (int)($data['lote_id'] ?? 0);
    $apenas_saidas = isset($data['apenas_saidas']) ? (bool)$data['apenas_saidas'] : true;
    if (!$lote_id) { retornar_json(false, 'lote_id obrigatório'); return; }

    $res_lote = $conn->query("SELECT * FROM importacoes_financeiras WHERE id={$lote_id}");
    if (!$res_lote || $res_lote->num_rows === 0) { retornar_json(false, 'Lote não encontrado'); return; }
    $lote = $res_lote->fetch_assoc();

    // Buscar plano padrão de despesa
    $res_plano = $conn->query("SELECT id FROM planos_contas WHERE tipo='DESPESA' AND ativo=1 LIMIT 1");
    $plano_id  = 1;
    if ($res_plano && $res_plano->num_rows > 0) $plano_id = (int)$res_plano->fetch_assoc()['id'];

    // Filtro: importar apenas saídas (despesas) para contas_pagar
    $filtro_tipo = $apenas_saidas ? "AND tipo_lancamento IN ('SAIDA','TARIFA')" : "";
    $res_itens = $conn->query("SELECT * FROM importacoes_financeiras_itens
        WHERE importacao_id={$lote_id} AND status_importacao='PENDENTE' {$filtro_tipo}");

    $importados = 0; $erros = 0;

    while ($item = $res_itens->fetch_assoc()) {
        $num_doc = $item['numero_documento'] ?: ('IMP-' . $lote_id . '-' . $item['id']);
        $forn    = $item['fornecedor_nome']       ?: 'Importado';
        $class   = $item['classificacao_despesa'] ?: '';
        $obs     = $item['historico_completo']    ?: ($item['observacao'] ?: '');
        $valor   = (float)($item['valor_saida']   ?: $item['valor'] ?: 0);
        $dv      = $item['data_vencimento']       ?: $item['data_lancamento'] ?: date('Y-m-d');
        $dp      = $item['data_pagamento']        ?: $item['data_lancamento'];
        $status  = 'PAGO'; // extrato = já pago
        $vp      = $valor;
        $saldo   = 0;

        $ins = $conn->prepare("INSERT INTO contas_pagar
            (numero_documento, fornecedor_nome, plano_conta_id, classificacao_despesa,
             descricao, valor_original, valor_pago, saldo_devedor,
             data_emissao, data_vencimento, data_pagamento,
             status, observacoes, usuario_criacao, ativo)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
        $de = date('Y-m-d');
        $ins->bind_param('ssissdddssssss',
            $num_doc, $forn, $plano_id, $class,
            $obs, $valor, $vp, $saldo,
            $de, $dv, $dp,
            $status, $obs, $usuario_nome
        );
        if ($ins->execute()) {
            $conta_id = $conn->insert_id;
            $conn->query("UPDATE importacoes_financeiras_itens SET status_importacao='IMPORTADO', conta_id={$conta_id} WHERE id={$item['id']}");
            $importados++;
        } else {
            $conn->query("UPDATE importacoes_financeiras_itens SET status_importacao='ERRO', mensagem_erro='" . $conn->real_escape_string($conn->error) . "' WHERE id={$item['id']}");
            $erros++;
        }
        $ins->close();
    }

    $conn->query("UPDATE importacoes_financeiras SET total_importados=total_importados+{$importados} WHERE id={$lote_id}");
    retornar_json(true, "Importação concluída: {$importados} registros importados para Contas a Pagar, {$erros} erros.", [
        'importados' => $importados, 'erros' => $erros
    ]);
}

// ══════════════════════════════════════════════════════════════
// EXCLUIR LOTE
// ══════════════════════════════════════════════════════════════
function _excluirLote() {
    global $conn;
    $data    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $lote_id = (int)($data['lote_id'] ?? $_GET['lote_id'] ?? 0);
    if (!$lote_id) { retornar_json(false, 'lote_id obrigatório'); return; }
    $conn->query("DELETE FROM importacoes_financeiras_itens WHERE importacao_id={$lote_id}");
    $conn->query("DELETE FROM importacoes_financeiras WHERE id={$lote_id}");
    retornar_json(true, 'Lote excluído com sucesso');
}

// ══════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════
function _normalizarValor($v) {
    if (is_null($v) || $v === '') return null;
    $v = trim(str_replace(['R$',' '], '', $v));
    if (preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$/', $v)) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } else {
        $v = str_replace(',', '', $v);
    }
    return is_numeric($v) ? (float)$v : null;
}

function _normalizarData($d) {
    if (empty($d)) return null;
    $d = trim($d);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $d, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d))              return $d;
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $d, $m))    return "{$m[3]}-{$m[2]}-{$m[1]}";
    return null;
}
