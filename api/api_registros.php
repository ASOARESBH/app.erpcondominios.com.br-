<?php
// =====================================================
// API PARA REGISTROS DE ACESSO v3
// Novidades: tipo_acesso (Entrada/Saída), dependente_id
// Mantém compatibilidade com colunas opcionais via
// _tem_coluna() + ALTER TABLE automático
// =====================================================

session_start();
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/access_control_notification_helper.php';
require_once __DIR__ . '/helpers/alertas_acesso_helper.php';
require_once __DIR__ . '/helpers/visitantes_config_helper.php';

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// ===== LOG DE DEBUG =====
function log_registro($msg, $dados = null) {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($dados !== null) $linha .= ' | ' . json_encode($dados, JSON_UNESCAPED_UNICODE);
    @file_put_contents($dir . '/registro.txt', $linha . PHP_EOL, FILE_APPEND);
}

// ===== RETORNO JSON =====
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$metodo  = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();
$tenant_id = exigirTenantId();

// ===== VERIFICAR / CRIAR COLUNAS EXTRAS =====
function _tem_coluna($conexao, $tabela, $coluna) {
    $r = $conexao->query("SHOW COLUMNS FROM `$tabela` LIKE '$coluna'");
    return $r && $r->num_rows > 0;
}

function _garantir_coluna($conexao, $tabela, $coluna, $definicao) {
    if (!_tem_coluna($conexao, $tabela, $coluna)) {
        $conexao->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao");
        log_registro("ALTER TABLE: coluna $coluna adicionada a $tabela");
    }
}

// Garantir colunas novas (cria automaticamente se não existirem)
_garantir_coluna($conexao, 'registros_acesso', 'tipo_acesso',    "ENUM('Entrada','Saída') DEFAULT 'Entrada'");
_garantir_coluna($conexao, 'registros_acesso', 'dependente_id',  "INT NULL DEFAULT NULL");
_garantir_coluna($conexao, 'registros_acesso', 'visitante_id',   "INT NULL DEFAULT NULL");
_garantir_coluna($conexao, 'registros_acesso', 'documento_visitante', "VARCHAR(30) NULL DEFAULT NULL");
// Ocupantes de veículo: cada ocupante vira seu próprio registro_acesso, marcado
// como OCUPANTE e apontando para o registro TITULAR do mesmo veículo/horário.
// Isso permite que a mesma pessoa seja titular em um veículo e ocupante em outro
// sem que os dois papéis se confundam no histórico.
_garantir_coluna($conexao, 'registros_acesso', 'papel_veiculo',  "ENUM('TITULAR','OCUPANTE') NOT NULL DEFAULT 'TITULAR'");
_garantir_coluna($conexao, 'registros_acesso', 'registro_titular_id', "INT NULL DEFAULT NULL");

$tem_tipo_acesso     = true; // acabou de garantir
$tem_dependente_id   = true;
$tem_visitante_id    = true;
$tem_documento_visit = true;

// ===== VALIDAR CADASTRO DE VISITANTE/PRESTADOR (titular ou ocupante) =====
// Confirma que o visitante pertence ao tenant. O documento digitalizado só é
// exigido quando ESTE condomínio marcou "Documento digitalizado" como
// obrigatório em Configurações > Sistema > Visitantes — a mesma regra usada
// no cadastro do visitante (visitantes_obter_config_campos). Antes, o Registro
// Manual exigia o anexo sempre, mesmo para tenants que configuraram o anexo
// como opcional no cadastro — bloqueando o acesso de gente que o próprio
// condomínio decidiu não exigir identificação digitalizada.
function _validar_visitante_para_registro($conexao, $tenant_id, $visitante_id, $anexoObrigatorio) {
    $visitante_id = (int)$visitante_id;
    if ($visitante_id <= 0) {
        return ['ok' => false, 'mensagem' => 'Localize um visitante/prestador cadastrado pelo documento.'];
    }

    $stmtVisitante = $conexao->prepare(
        'SELECT id, nome_completo, documento, tipo_documento, documento_arquivo FROM visitantes WHERE tenant_id = ? AND id = ? LIMIT 1'
    );
    if (!$stmtVisitante) {
        return ['ok' => false, 'mensagem' => 'Erro ao validar o cadastro do visitante.'];
    }
    $stmtVisitante->bind_param('ii', $tenant_id, $visitante_id);
    $stmtVisitante->execute();
    $visitante = $stmtVisitante->get_result()->fetch_assoc();
    $stmtVisitante->close();

    if (!$visitante) {
        return ['ok' => false, 'mensagem' => 'O visitante/prestador selecionado não pertence ao condomínio atual.'];
    }

    if ($anexoObrigatorio) {
        if (empty($visitante['documento_arquivo'])) {
            return ['ok' => false, 'mensagem' => 'Cadastro de ' . $visitante['nome_completo'] . ' encontrado, mas falta o documento digitalizado anexado (exigido pela configuração deste condomínio). Cadastre o documento antes de registrar o acesso.'];
        }

        $caminhoDocumento = ltrim((string)$visitante['documento_arquivo'], './');
        $stmtDocumento = $conexao->prepare(
            'SELECT id FROM tenant_arquivos WHERE tenant_id = ? AND caminho_legado = ? AND ativo = 1 LIMIT 1'
        );
        if (!$stmtDocumento) {
            return ['ok' => false, 'mensagem' => 'Erro ao validar o documento digitalizado do visitante.'];
        }
        $stmtDocumento->bind_param('is', $tenant_id, $caminhoDocumento);
        $stmtDocumento->execute();
        $documentoAtivo = $stmtDocumento->get_result()->fetch_assoc();
        $stmtDocumento->close();

        if (!$documentoAtivo) {
            return ['ok' => false, 'mensagem' => 'Cadastro de ' . $visitante['nome_completo'] . ' encontrado, mas o documento digitalizado não está disponível no sistema. Anexe-o novamente antes de registrar o acesso.'];
        }
    }

    return ['ok' => true, 'visitante' => $visitante];
}

// Normaliza um documento (CPF/RG) para comparação — remove pontuação/espaços.
function _normalizar_documento_comparacao($documento) {
    return preg_replace('/[^A-Za-z0-9]/', '', (string)$documento);
}

// ========== LISTAR REGISTROS ==========
if ($metodo === 'GET') {
    $limite = intval($_GET['limite'] ?? 100);

    $sql = "SELECT r.id,
            DATE_FORMAT(r.data_hora, '%d/%m/%Y %H:%i:%s') AS data_hora_formatada,
            r.data_hora, r.placa, r.modelo, r.cor, r.tag, r.tipo,
            r.nome_visitante, r.unidade_destino, r.dias_permanencia,
            r.status, r.liberado, r.observacao,
            r.tipo_acesso, r.dependente_id,
            r.papel_veiculo, r.registro_titular_id,
            m.nome AS morador_nome, m.unidade AS morador_unidade,
            d.nome_completo AS dependente_nome
            FROM registros_acesso r
            LEFT JOIN moradores m ON r.morador_id = m.id
            LEFT JOIN dependentes d ON r.dependente_id = d.id
            WHERE r.tenant_id = ?
            ORDER BY r.data_hora DESC
            LIMIT ?";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        log_registro('ERRO prepare GET', ['erro' => $conexao->error]);
        retornar_json(false, 'Erro ao preparar consulta: ' . $conexao->error);
    }
    $stmt->bind_param('ii', $tenant_id, $limite);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $registros = [];
    while ($row = $resultado->fetch_assoc()) {
        $registros[] = $row;
    }
    $stmt->close();
    retornar_json(true, 'Registros listados com sucesso', $registros);
}

// ========== CRIAR REGISTRO MANUAL ==========
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true);

    log_registro('POST recebido', $dados);

    $data_hora        = $dados['data_hora']        ?? date('Y-m-d H:i:s');
    $placa            = strtoupper(trim($dados['placa']    ?? ''));
    $modelo           = trim($dados['modelo']           ?? '');
    $cor              = trim($dados['cor']              ?? '');
    $tipo             = trim($dados['tipo']             ?? '');
    $unidade_destino  = trim($dados['unidade_destino']  ?? '');
    $dias_permanencia = intval($dados['dias_permanencia'] ?? 0);
    $nome_visitante   = trim($dados['nome_visitante']   ?? '');
    $observacao       = trim($dados['observacao']       ?? '');
    $tipo_acesso      = trim($dados['tipo_acesso']      ?? 'Entrada');

    // Validar tipo_acesso
    if (!in_array($tipo_acesso, ['Entrada', 'Saída'])) $tipo_acesso = 'Entrada';

    // Validações
    if (empty($placa) || empty($tipo)) {
        log_registro('ERRO validacao', ['placa' => $placa, 'tipo' => $tipo]);
        retornar_json(false, 'Placa e tipo são obrigatórios');
    }

    if (!in_array($tipo, ['Morador', 'Visitante', 'Prestador'])) {
        log_registro('ERRO tipo invalido', ['tipo' => $tipo]);
        retornar_json(false, 'Tipo inválido: ' . $tipo);
    }

    $morador_id   = isset($dados['morador_id'])   && $dados['morador_id']   ? intval($dados['morador_id'])   : null;
    $visitante_id = isset($dados['visitante_id']) && $dados['visitante_id'] ? intval($dados['visitante_id']) : null;
    $dependente_id = isset($dados['dependente_id']) && $dados['dependente_id'] ? intval($dados['dependente_id']) : null;
    $documento    = trim($dados['documento'] ?? '');

    // A placa cadastrada é a fonte autoritativa: não confiar em modelo, cor,
    // morador ou unidade enviados pelo navegador, pois campos desabilitados
    // ainda podem ser alterados por requisições manuais.
    $stmtVeiculo = $conexao->prepare("SELECT v.modelo, v.cor, v.morador_id, m.nome, m.unidade
        FROM veiculos v INNER JOIN moradores m ON m.id = v.morador_id AND m.tenant_id = ?
        WHERE v.tenant_id = ? AND REPLACE(REPLACE(UPPER(v.placa), '-', ''), ' ', '') = REPLACE(REPLACE(UPPER(?), '-', ''), ' ', '') AND v.ativo = 1 LIMIT 1");
    if ($stmtVeiculo) {
        $stmtVeiculo->bind_param('iis', $tenant_id, $tenant_id, $placa);
        $stmtVeiculo->execute();
        $veiculoCadastrado = $stmtVeiculo->get_result()->fetch_assoc();
        $stmtVeiculo->close();
        if ($veiculoCadastrado) {
            $modelo = trim((string)($veiculoCadastrado['modelo'] ?? ''));
            $cor = trim((string)($veiculoCadastrado['cor'] ?? ''));
            $morador_id = (int)$veiculoCadastrado['morador_id'];
            $unidade_destino = trim((string)($veiculoCadastrado['unidade'] ?? ''));
            $tipo = 'Morador';
        }
    }
    $tag          = null;
    $liberado     = 0;
    $status       = '';

    // Se for morador, buscar no banco pela placa
    if ($tipo === 'Morador') {
        // Se morador_id já foi enviado pelo frontend (seleção manual), usar diretamente
        if ($morador_id) {
            $stmt2 = $conexao->prepare("SELECT nome, unidade FROM moradores WHERE tenant_id = $tenant_id AND id = ?");
            if ($stmt2) {
                $stmt2->bind_param('i', $morador_id);
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                if ($res2->num_rows > 0) {
                    $mor = $res2->fetch_assoc();
                    $liberado = 1;
                    $status   = '✅ Acesso liberado - ' . $mor['nome'];
                    if (empty($unidade_destino)) $unidade_destino = $mor['unidade'];
                } else {
                    $status   = '🟨 Registro manual - Morador';
                    $liberado = 1;
                }
                $stmt2->close();
            }
        } else {
            // Tentar detectar pela placa
            $stmt2 = $conexao->prepare(
                "SELECT v.tag, v.morador_id, m.nome, m.unidade
                 FROM veiculos v
                 INNER JOIN moradores m ON v.morador_id = m.id
                 WHERE v.placa = ? AND v.ativo = 1"
            );
            if (!$stmt2) {
                log_registro('ERRO prepare busca veiculo', ['erro' => $conexao->error]);
                retornar_json(false, 'Erro interno ao buscar veículo: ' . $conexao->error);
            }
            $stmt2->bind_param('s', $placa);
            $stmt2->execute();
            $resultado2 = $stmt2->get_result();

            if ($resultado2->num_rows > 0) {
                $veiculo         = $resultado2->fetch_assoc();
                $morador_id      = intval($veiculo['morador_id']);
                $tag             = $veiculo['tag'];
                $liberado        = 1;
                $status          = '✅ Acesso liberado - ' . $veiculo['nome'];
                $unidade_destino = $veiculo['unidade'];
            } else {
                $status   = '❌ Acesso negado - Placa não cadastrada';
                $liberado = 0;
            }
            $stmt2->close();
        }

        // Se for dependente, buscar nome do dependente para o status
        if ($dependente_id) {
            $stmtDep = $conexao->prepare("SELECT nome_completo FROM dependentes WHERE tenant_id = $tenant_id AND id = ?");
            if ($stmtDep) {
                $stmtDep->bind_param('i', $dependente_id);
                $stmtDep->execute();
                $resDep = $stmtDep->get_result();
                if ($resDep->num_rows > 0) {
                    $dep = $resDep->fetch_assoc();
                    $status .= ' (Dependente: ' . $dep['nome_completo'] . ')';
                }
                $stmtDep->close();
            }
        }

    } else {
        // Visitante e Prestador compartilham o cadastro-base. O documento
        // digitalizado só é exigido se este condomínio configurou isso como
        // obrigatório (Configurações > Sistema > Visitantes) — a mesma regra
        // aplicada no cadastro do visitante, para não haver exigência
        // contraditória entre "cadastrar" e "liberar o acesso".
        $configVisitantesCampos = visitantes_obter_config_campos($conexao, $tenant_id);
        $anexoObrigatorio = !empty($configVisitantesCampos['documento_digitalizado']['obrigatorio']);

        $validacaoTitular = _validar_visitante_para_registro($conexao, $tenant_id, $visitante_id, $anexoObrigatorio);
        if (!$validacaoTitular['ok']) {
            retornar_json(false, $validacaoTitular['mensagem']);
        }
        $visitante = $validacaoTitular['visitante'];

        // Usa somente os dados confirmados no cadastro do tenant.
        $nome_visitante = $visitante['nome_completo'];
        $documento = $visitante['documento'];
        $status   = '🟨 Registro manual - ' . $tipo;
        $liberado = 1;
    }

    // ── Ocupantes do veículo (apenas Visitante/Prestador) ─────────────────────
    // Cada ocupante é validado com a MESMA regra do titular (cadastro no tenant
    // + configuração de anexo) antes de qualquer gravação, para que a falha de
    // um ocupante não deixe o titular salvo sem os ocupantes informados. Também
    // impede duas entradas com o mesmo documento no mesmo lançamento — nem por
    // ID de cadastro nem pelo número do documento — para que um operador não
    // consiga "forçar" a liberação repetindo a mesma pessoa na lista.
    $ocupantesValidados = [];
    if (($tipo === 'Visitante' || $tipo === 'Prestador') && !empty($dados['ocupantes']) && is_array($dados['ocupantes'])) {
        $idsJaUsados = [(int)$visitante_id];
        $documentosJaUsados = [_normalizar_documento_comparacao($documento)];
        foreach ($dados['ocupantes'] as $ocupanteRaw) {
            $ocupanteVisitanteId = (int)($ocupanteRaw['visitante_id'] ?? 0);
            if ($ocupanteVisitanteId > 0 && in_array($ocupanteVisitanteId, $idsJaUsados, true)) {
                retornar_json(false, 'Um dos ocupantes informados já é o titular deste acesso ou está repetido na lista de ocupantes.');
            }
            $validacaoOcupante = _validar_visitante_para_registro($conexao, $tenant_id, $ocupanteVisitanteId, $anexoObrigatorio);
            if (!$validacaoOcupante['ok']) {
                retornar_json(false, $validacaoOcupante['mensagem']);
            }
            $documentoOcupanteNormalizado = _normalizar_documento_comparacao($validacaoOcupante['visitante']['documento']);
            if ($documentoOcupanteNormalizado !== '' && in_array($documentoOcupanteNormalizado, $documentosJaUsados, true)) {
                retornar_json(false, 'O documento de ' . $validacaoOcupante['visitante']['nome_completo'] . ' já está registrado neste lançamento (como titular ou outro ocupante). Não é permitido lançar o mesmo documento duas vezes.');
            }
            $idsJaUsados[] = $ocupanteVisitanteId;
            $documentosJaUsados[] = $documentoOcupanteNormalizado;
            $ocupantesValidados[] = $validacaoOcupante['visitante'];
        }
    }

    // ── Montar INSERT dinamicamente (registro titular + ocupantes) ────────────
    // papel_veiculo/registro_titular_id distinguem o condutor/visitante principal
    // dos ocupantes do mesmo veículo, mesmo que um ocupante já seja titular em
    // outro registro (outro veículo) — cada linha é um evento de acesso próprio.
    $cols  = 'tenant_id, data_hora, placa, modelo, cor, tag, tipo, morador_id, nome_visitante, unidade_destino, dias_permanencia, status, liberado, observacao, tipo_acesso, dependente_id, visitante_id, documento_visitante, papel_veiculo, registro_titular_id';
    $marks = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
    // i=tenant_id(1) s=data_hora(2) s=placa(3) s=modelo(4) s=cor(5) s=tag(6) s=tipo(7)
    // i=morador_id(8) s=nome_visitante(9) s=unidade_destino(10)
    // i=dias_permanencia(11) s=status(12) i=liberado(13) s=observacao(14)
    // s=tipo_acesso(15) i=dependente_id(16) i=visitante_id(17) s=documento_visitante(18)
    // s=papel_veiculo(19) i=registro_titular_id(20)
    $types = 'issssssissisissiis' . 'si';
    $sql   = "INSERT INTO registros_acesso ($cols) VALUES ($marks)";

    $conexao->begin_transaction();
    $id_inserido = 0;
    $ocupantesRegistrados = [];
    try {
        // Registro titular
        $papel_veiculo_titular = 'TITULAR';
        $registro_titular_id_nulo = null;
        $params = [
            &$tenant_id, &$data_hora, &$placa, &$modelo, &$cor, &$tag, &$tipo,
            &$morador_id, &$nome_visitante, &$unidade_destino,
            &$dias_permanencia, &$status, &$liberado, &$observacao,
            &$tipo_acesso, &$dependente_id, &$visitante_id, &$documento,
            &$papel_veiculo_titular, &$registro_titular_id_nulo
        ];

        $stmt = $conexao->prepare($sql);
        if (!$stmt) throw new RuntimeException('Erro ao preparar inserção: ' . $conexao->error);
        call_user_func_array([$stmt, 'bind_param'], array_merge([&$types], $params));

        log_registro('INSERT executando (titular)', [
            'placa' => $placa, 'tipo' => $tipo, 'tipo_acesso' => $tipo_acesso,
            'morador_id' => $morador_id, 'dependente_id' => $dependente_id, 'visitante_id' => $visitante_id,
        ]);

        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Erro ao criar registro: ' . $erro);
        }
        $id_inserido = $conexao->insert_id;
        $stmt->close();

        // Registros dos ocupantes — mesmo veículo/horário, cada um com seu próprio visitante_id.
        foreach ($ocupantesValidados as $ocupante) {
            $ocNome        = $ocupante['nome_completo'];
            $ocDocumento   = $ocupante['documento'];
            $ocVisitanteId = (int)$ocupante['id'];
            $ocStatus      = '🟨 Ocupante do veículo (' . $tipo . ') — titular: ' . $nome_visitante;
            $ocLiberado    = 1;
            $ocDependente  = null;
            $ocPapel       = 'OCUPANTE';

            $paramsOc = [
                &$tenant_id, &$data_hora, &$placa, &$modelo, &$cor, &$tag, &$tipo,
                &$morador_id, &$ocNome, &$unidade_destino,
                &$dias_permanencia, &$ocStatus, &$ocLiberado, &$observacao,
                &$tipo_acesso, &$ocDependente, &$ocVisitanteId, &$ocDocumento,
                &$ocPapel, &$id_inserido
            ];

            $stmtOc = $conexao->prepare($sql);
            if (!$stmtOc) throw new RuntimeException('Erro ao preparar inserção de ocupante: ' . $conexao->error);
            call_user_func_array([$stmtOc, 'bind_param'], array_merge([&$types], $paramsOc));

            if (!$stmtOc->execute()) {
                $erroOc = $stmtOc->error;
                $stmtOc->close();
                throw new RuntimeException('Erro ao registrar o ocupante ' . $ocNome . ': ' . $erroOc);
            }
            $ocupantesRegistrados[] = ['id' => $conexao->insert_id, 'nome' => $ocNome, 'visitante_id' => $ocVisitanteId];
            $stmtOc->close();
        }

        $conexao->commit();
    } catch (Throwable $erroTransacao) {
        $conexao->rollback();
        log_registro('ERRO transacao registro+ocupantes', ['erro' => $erroTransacao->getMessage()]);
        retornar_json(false, $erroTransacao->getMessage());
    }

    log_registro('INSERT OK', ['id' => $id_inserido, 'status' => $status, 'tipo_acesso' => $tipo_acesso, 'ocupantes' => count($ocupantesRegistrados)]);
    registrar_log('REGISTRO_CRIADO', "Registro manual criado: $placa ($tipo) - $tipo_acesso" . (count($ocupantesRegistrados) ? ' + ' . count($ocupantesRegistrados) . ' ocupante(s)' : ''));

    // O acesso é a operação prioritária. A notificação é complementar e
    // jamais pode desfazer uma entrada/saída registrada com sucesso.
    $notificacao = ['sucesso' => false, 'motivo' => 'nao_processada'];
    try {
        $notificacao = controle_acesso_criar_notificacao_registro(
            $conexao,
            (int)$tenant_id,
            (int)$id_inserido,
            $morador_id ? (int)$morador_id : null,
            $unidade_destino,
            $tipo_acesso,
            $tipo,
            $placa,
            $modelo,
            $data_hora,
            $nome_visitante,
            $documento,
            (string) ($_SESSION['usuario_nome'] ?? '')
        );
    } catch (Throwable $erro_notificacao) {
        log_registro('NOTIFICACAO ACESSO FALHOU (não bloqueante)', [
            'registro_id' => $id_inserido,
            'erro' => $erro_notificacao->getMessage(),
        ]);
        $notificacao = ['sucesso' => false, 'motivo' => 'excecao_nao_bloqueante'];
    }
        log_registro('NOTIFICACAO ACESSO PROCESSADA', [
        'registro_id' => $id_inserido,
        'resultado' => $notificacao,
    ]);
    $alertas_disparados = [];
    try {
        $alertas_disparados = alertas_acesso_processar_evento($conexao, (int)$tenant_id, 'registro_manual', [
            'placa' => $placa, 'modelo' => $modelo, 'cor' => $cor,
            'pessoa_nome' => $nome_visitante, 'pessoa_cpf' => $documento,
            'unidade' => $unidade_destino, 'observacao' => $observacao,
            'tipo_acesso' => $tipo_acesso,
        ], 'registro_manual_' . (int)$id_inserido);
    } catch (Throwable $e) {
        log_registro('ALERTA ACESSO FALHOU (não bloqueante)', ['registro_id' => $id_inserido, 'erro' => $e->getMessage()]);
    }
    retornar_json(true, $status, [
        'id' => $id_inserido,
        'liberado' => $liberado,
        'status' => $status,
        'tipo_acesso' => $tipo_acesso,
        'notificacao_controle_acesso' => $notificacao,
        'alertas_acesso' => $alertas_disparados,
        'ocupantes_registrados' => $ocupantesRegistrados,
    ]);
}

// ========== ATUALIZAR REGISTRO ==========
if ($metodo === 'PUT') {
    $dados = json_decode(file_get_contents('php://input'), true);

    $id         = intval($dados['id'] ?? 0);
    $observacao = trim($dados['observacao'] ?? '');
    $status     = trim($dados['status']     ?? '');

    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }

    $stmt = $conexao->prepare('UPDATE registros_acesso SET observacao=?, status=? WHERE tenant_id = $tenant_id AND id=?');
    if (!$stmt) {
        retornar_json(false, 'Erro ao preparar atualização: ' . $conexao->error);
    }
    $stmt->bind_param('ssi', $observacao, $status, $id);

    if ($stmt->execute()) {
        registrar_log('REGISTRO_ATUALIZADO', "Registro atualizado: ID $id");
        retornar_json(true, 'Registro atualizado com sucesso');
    } else {
        retornar_json(false, 'Erro ao atualizar registro: ' . $stmt->error);
    }
    $stmt->close();
}

// ========== EXCLUIR REGISTRO ==========
if ($metodo === 'DELETE') {
    $dados = json_decode(file_get_contents('php://input'), true);
    $id    = intval($dados['id'] ?? 0);

    if ($id <= 0) {
        retornar_json(false, 'ID inválido');
    }

    $stmt = $conexao->prepare('DELETE FROM registros_acesso WHERE tenant_id = $tenant_id AND id = ?');
    if (!$stmt) {
        retornar_json(false, 'Erro ao preparar exclusão: ' . $conexao->error);
    }
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        registrar_log('REGISTRO_EXCLUIDO', "Registro excluído: ID $id");
        retornar_json(true, 'Registro excluído com sucesso');
    } else {
        retornar_json(false, 'Erro ao excluir registro: ' . $stmt->error);
    }
    $stmt->close();
}

fechar_conexao($conexao);
