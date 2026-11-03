<?php
// =====================================================
// API PARA INTEGRAÇÃO COM RFID CONTROL ID iDUHF - CORRIGIDA
// =====================================================

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once __DIR__ . '/rbac_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/helpers/alertas_acesso_helper.php';
require_once __DIR__ . '/helpers/alertas_unidade_helper.php';

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array('sucesso' => $sucesso, 'mensagem' => $mensagem);
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$metodo = $_SERVER['REQUEST_METHOD'];
$conexao = conectar_banco();
$tenant_id = exigirTenantId();

function _tem_coluna($conexao, $tabela, $coluna) {
    $resultado = $conexao->query("SHOW COLUMNS FROM `$tabela` LIKE '$coluna'");
    return $resultado && $resultado->num_rows > 0;
}

function _garantir_coluna($conexao, $tabela, $coluna, $definicao) {
    if (!_tem_coluna($conexao, $tabela, $coluna)) {
        if (!$conexao->query("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao")) {
            error_log("[api_rfid] Falha ao adicionar coluna $coluna em $tabela: " . $conexao->error);
        }
    }
}

_garantir_coluna($conexao, 'registros_acesso', 'usuario_liberou', "VARCHAR(150) NULL DEFAULT NULL");

// ========== VERIFICAR TAG RFID ==========
if ($metodo === 'POST' && isset($_GET['acao']) && $_GET['acao'] === 'verificar_tag') {
    if (function_exists('rbacTabelasDisponiveis') && rbacTabelasDisponiveis($conexao)) rbacExigir($conexao, 'acesso', 'executar');
    else verificarPermissao('operador');
    $dados = json_decode(file_get_contents('php://input'), true);
    $tag = sanitizar($conexao, $dados['tag'] ?? $dados['identificador'] ?? '');
    $identificador = strtoupper(trim((string)($dados['identificador'] ?? $tag)));
    $identificadorNormalizado = preg_replace('/[^A-Z0-9]/', '', $identificador);
    
    if (empty($tag)) {
        retornar_json(false, "TAG RFID não informada");
    }
    
    // Buscar por TAG RFID ou por placa, sempre no tenant da sessão.
    $stmt = $conexao->prepare("SELECT v.id, v.placa, v.modelo, v.cor, v.tag, v.ativo, v.dependente_id,
                              m.id as morador_id, m.nome as morador_nome, m.unidade as morador_unidade,
                              d.id as dependente_id_check, d.nome_completo as dependente_nome
                              FROM veiculos v
                              INNER JOIN moradores m ON v.morador_id = m.id
                              LEFT JOIN dependentes d ON v.dependente_id = d.id AND d.tenant_id = v.tenant_id
                              WHERE v.tenant_id = ? AND m.tenant_id = ?
                                AND (v.tag = ? OR REPLACE(REPLACE(UPPER(v.placa), '-', ''), ' ', '') = ?)
                                AND v.ativo = 1 AND m.ativo = 1");
    
    $stmt->bind_param("iiss", $tenant_id, $tenant_id, $tag, $identificadorNormalizado);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $veiculo = $resultado->fetch_assoc();
        
        $data_hora = trim((string)($dados['data_hora'] ?? ''));
        if ($data_hora === '') $data_hora = date('Y-m-d H:i:s');
        $dataObjeto = au_now($data_hora);
        $data_hora = $dataObjeto ? $dataObjeto->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
        $tipo_acesso = in_array(($dados['tipo_acesso'] ?? 'Entrada'), ['Entrada', 'Saída'], true) ? $dados['tipo_acesso'] : 'Entrada';
        $placa = $veiculo['placa'];
        $modelo = $veiculo['modelo'];
        $cor = $veiculo['cor'];
        $morador_id = $veiculo['morador_id'];
        $dependente_id = $veiculo['dependente_id'] ?? null;
        
        // Determinar tipo de acesso
        if (!empty($dependente_id) && !empty($veiculo['dependente_nome'])) {
            $tipo = 'Dependente';
            $status = "✅ Acesso liberado - " . $veiculo['dependente_nome'] . " (dependente de " . $veiculo['morador_nome'] . ")";
            $mensagem = "✅ SEJA BEM-VINDO, " . strtoupper($veiculo['dependente_nome']) . ", dependente de " . strtoupper($veiculo['morador_nome']) . "!";
        } else {
            $tipo = 'Morador';
            $status = "✅ Acesso liberado - " . $veiculo['morador_nome'];
            $mensagem = "✅ SEJA BEM-VINDO, " . strtoupper($veiculo['morador_nome']) . "!";
        }
        
        $unidade = $veiculo['morador_unidade'];
        $liberado = 1;
        $usuario_liberou = trim((string)($_SESSION['usuario_nome'] ?? ''));
        if ($usuario_liberou === '') $usuario_liberou = null;
        $nome_acesso = $veiculo['dependente_nome'] ?? $veiculo['morador_nome'];
        $contextoAlerta = null;
        $alertasUnidadeResposta = [];
        $notificacoesAlertas = [];
        $registroAcessoId = 0;
        $cienciaToken = trim((string)($dados['ciencia_token'] ?? ''));
        if ($tipo_acesso === 'Entrada' && (!alertas_unidade_migration_instalada($conexao) || !_tem_coluna($conexao, 'registros_acesso', 'tenant_id') || !function_exists('alertas_unidade_avaliar'))) {
            http_response_code(503);
            retornar_json(false, 'A integração de Alertas da Unidade ainda não foi instalada. Aplique a migration do módulo antes de registrar novas entradas.', ['codigo'=>'MIGRATION_ALERTAS_PENDENTE']);
        }
        if ($tipo_acesso === 'Entrada' && _tem_coluna($conexao, 'registros_acesso', 'tenant_id') && function_exists('alertas_unidade_avaliar')) {
            $tabelaAlertas = $conexao->query("SHOW TABLES LIKE 'alertas_unidade'");
            $featureAtivo = $tabelaAlertas && $tabelaAlertas->num_rows > 0;
            if ($tabelaAlertas) $tabelaAlertas->free();
            if ($featureAtivo) {
                $contextoAlerta = alertas_unidade_construir_contexto_registro($conexao, (int)$tenant_id, [
                    'contexto'=>'CONTROLE_ACESSO','data_hora'=>$data_hora,'tipo_pessoa'=>$tipo,
                    'morador_id'=>$morador_id,'dependente_id'=>$dependente_id,'unidade'=>$unidade,
                    'placa'=>$placa,'tipo_acesso'=>$tipo_acesso,'pessoa_nome'=>$nome_acesso,
                ]);
                $contextoAlerta['_usuario_nome'] = (string)($_SESSION['usuario_nome'] ?? 'Usuário autenticado');
                $conexao->begin_transaction();
                try {
                    $alertasAtuais = alertas_unidade_avaliar($conexao, (int)$tenant_id, $contextoAlerta, true);
                    foreach ($alertasAtuais as $alertaAtual) $alertasUnidadeResposta[] = ['id'=>(int)$alertaAtual['id'],'titulo'=>$alertaAtual['titulo'],'mensagem'=>$alertaAtual['mensagem'],'nivel'=>$alertaAtual['nivel'],'acao'=>$alertaAtual['acao'],'unidade'=>$alertaAtual['unidade'],'instrucao_operador'=>$alertaAtual['instrucao_operador'],'telefone_contato'=>$alertaAtual['telefone_contato'],'divergencia_unidade'=>!empty($alertaAtual['divergencia_unidade'])];
                    $exigeCiencia = false;
                    foreach ($alertasAtuais as $alertaAtual) if (($alertaAtual['acao'] ?? '') !== 'APENAS_AVISAR') $exigeCiencia = true;
                    if ($exigeCiencia && $cienciaToken === '') {
                        $conexao->rollback();
                        http_response_code(409);
                        $alertasResposta = [];
                        foreach ($alertasAtuais as $alertaAtual) $alertasResposta[] = ['id'=>(int)$alertaAtual['id'],'titulo'=>$alertaAtual['titulo'],'mensagem'=>$alertaAtual['mensagem'],'nivel'=>$alertaAtual['nivel'],'acao'=>$alertaAtual['acao'],'unidade'=>$alertaAtual['unidade'],'instrucao_operador'=>$alertaAtual['instrucao_operador'],'telefone_contato'=>$alertaAtual['telefone_contato'],'divergencia_unidade'=>!empty($alertaAtual['divergencia_unidade'])];
                        $podeOverride = alertas_unidade_usuario_pode_override($conexao,(int)($_SESSION['usuario_id']??0),(int)$tenant_id);
                        retornar_json(false,'Há alertas que exigem ciência antes de registrar o acesso.',['codigo'=>'ALERTA_PENDENTE','alertas'=>$alertasResposta,'pode_override'=>$podeOverride,'contexto_seguro'=>array_merge($contextoAlerta,['data_hora'=>$data_hora])]);
                    }
                    $stmt_registro = $conexao->prepare("INSERT INTO registros_acesso
                        (tenant_id,data_hora,placa,modelo,cor,tag,tipo,morador_id,unidade_destino,status,liberado,tipo_acesso,usuario_liberou,dependente_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    if (!$stmt_registro) throw new RuntimeException('Falha ao preparar o registro RFID.');
                    $stmt_registro->bind_param('issssssississi',$tenant_id,$data_hora,$placa,$modelo,$cor,$tag,$tipo,$morador_id,$unidade,$status,$liberado,$tipo_acesso,$usuario_liberou,$dependente_id);
                    if (!$stmt_registro->execute()) { $stmt_registro->close(); throw new RuntimeException('Falha ao gravar o registro RFID.'); }
                    $registroAcessoId = (int)$conexao->insert_id;
                    $stmt_registro->close();
                    if ($cienciaToken !== '') {
                        $resultadoCiencia = alertas_unidade_validar_consumir_token($conexao,(int)$tenant_id,(int)($_SESSION['usuario_id']??0),$contextoAlerta,$cienciaToken,$registroAcessoId);
                        if (empty($resultadoCiencia['sucesso'])) {
                            $conexao->rollback();
                            http_response_code(409);
                            $alertasResposta = [];
                            foreach (($resultadoCiencia['alertas'] ?? $alertasAtuais) as $alertaAtual) $alertasResposta[] = ['id'=>(int)$alertaAtual['id'],'titulo'=>$alertaAtual['titulo'],'mensagem'=>$alertaAtual['mensagem'],'nivel'=>$alertaAtual['nivel'],'acao'=>$alertaAtual['acao'],'unidade'=>$alertaAtual['unidade'],'instrucao_operador'=>$alertaAtual['instrucao_operador'],'telefone_contato'=>$alertaAtual['telefone_contato'],'divergencia_unidade'=>!empty($alertaAtual['divergencia_unidade'])];
                            $podeOverride = alertas_unidade_usuario_pode_override($conexao,(int)($_SESSION['usuario_id']??0),(int)$tenant_id);
                            retornar_json(false,$resultadoCiencia['mensagem']??'Os alertas mudaram. Revise a ciência antes de continuar.',['codigo'=>'ALERTA_PENDENTE','alertas'=>$alertasResposta,'pode_override'=>$podeOverride,'contexto_seguro'=>array_merge($contextoAlerta,['data_hora'=>$data_hora])]);
                        }
                        $notificacoesAlertas = $resultadoCiencia['notificacoes'] ?? [];
                    } else {
                        $notificacoesAlertas = alertas_unidade_registrar_avisos($conexao,(int)$tenant_id,$contextoAlerta,$registroAcessoId,$alertasAtuais,(int)($_SESSION['usuario_id']??0),(string)($contextoAlerta['_usuario_nome']??'Usuário autenticado'));
                    }
                    $conexao->commit();
                } catch (Throwable $erroAcesso) {
                    try { $conexao->rollback(); } catch (Throwable $erroRollback) {}
                    error_log('[api_rfid] Falha no registro autenticado de acesso.');
                    http_response_code(500);
                    retornar_json(false,'Não foi possível registrar o acesso RFID.');
                }
            }
        }
        if ($registroAcessoId === 0) {
            $stmt_registro = $conexao->prepare("INSERT INTO registros_acesso
                (tenant_id,data_hora,placa,modelo,cor,tag,tipo,morador_id,unidade_destino,status,liberado,tipo_acesso,usuario_liberou,dependente_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if (!$stmt_registro) { http_response_code(500); retornar_json(false,'Falha ao preparar o registro RFID.'); }
            $stmt_registro->bind_param('issssssississi',$tenant_id,$data_hora,$placa,$modelo,$cor,$tag,$tipo,$morador_id,$unidade,$status,$liberado,$tipo_acesso,$usuario_liberou,$dependente_id);
            if (!$stmt_registro->execute()) { $stmt_registro->close(); http_response_code(500); retornar_json(false,'Falha ao gravar o registro RFID.'); }
            $registroAcessoId = (int)$conexao->insert_id;
            $stmt_registro->close();
        }
        foreach ($notificacoesAlertas as $notificacaoAlerta) {
            if (!is_array($notificacaoAlerta) || empty($notificacaoAlerta['alerta']) || (int)($notificacaoAlerta['morador_id']??0)<=0) continue;
            alertas_unidade_notificar_morador($conexao,(int)$tenant_id,$notificacaoAlerta['alerta'],(int)$notificacaoAlerta['morador_id'],$registroAcessoId,$contextoAlerta??[],(string)($notificacaoAlerta['decisao']??'CIENTE_LIBERADO'));
        }
        registrar_log('ACESSO_RFID', "Acesso via RFID: $placa (TAG RFID: $tag) - " . $nome_acesso);
        $alertas_disparados = alertas_acesso_processar_evento($conexao, (int)$tenant_id, 'rfid', [
            'placa' => $placa, 'modelo' => $modelo, 'cor' => $cor,
            'morador_nome' => $veiculo['morador_nome'], 'unidade' => $unidade,
            'dependente_nome' => $veiculo['dependente_nome'] ?? '', 'tipo_acesso' => $tipo_acesso,
        ]);
        
        retornar_json(true, "Acesso liberado", array(
            'liberado' => true,
            'morador' => $veiculo['morador_nome'],
            'dependente' => $veiculo['dependente_nome'] ?? null,
            'unidade' => $veiculo['morador_unidade'],
            'tag_rfid' => $veiculo['tag'],
            'placa' => $veiculo['placa'],
            'modelo' => $veiculo['modelo'],
            'tipo' => $tipo,
            'tipo_acesso' => $tipo_acesso,
            'ultimo_acesso' => date('d/m/Y H:i:s', strtotime($data_hora)),
            'mensagem' => strtoupper($tipo_acesso) . ' — ' . $mensagem,
            'alertas' => $alertas_disparados,
            'alertas_unidade' => $alertasUnidadeResposta
        ));
        
    } else {
        registrar_log('ACESSO_NEGADO_RFID', "Acesso negado via RFID: TAG RFID $tag não cadastrada ou inativa");
        
        retornar_json(false, "Acesso negado", array(
            'liberado' => false,
            'mensagem' => "❌ ACESSO NEGADO - TAG RFID NÃO CADASTRADA"
        ));
    }
    
    $stmt->close();
}

// ========== WEBHOOK PARA RECEBER LEITURAS DO RFID ==========
if ($metodo === 'POST' && isset($_GET['acao']) && $_GET['acao'] === 'webhook') {
    $dados = json_decode(file_get_contents('php://input'), true);
    
    $tag = sanitizar($conexao, $dados['tag'] ?? $dados['card'] ?? $dados['rfid'] ?? '');
    $timestamp = date('Y-m-d H:i:s');
    $tipo_acesso = in_array(($dados['tipo_acesso'] ?? 'Entrada'), ['Entrada', 'Saída'], true) ? $dados['tipo_acesso'] : 'Entrada';
    
    if (empty($tag)) {
        registrar_log('WEBHOOK_RFID_ERRO', "Webhook RFID recebido sem TAG RFID válida");
        retornar_json(false, "TAG RFID não identificada no webhook");
    }
    
    $stmt = $conexao->prepare("SELECT v.id, v.placa, v.modelo, v.cor, v.tag, v.ativo, v.dependente_id,
                              m.id as morador_id, m.nome as morador_nome, m.unidade as morador_unidade,
                              d.id as dependente_id_check, d.nome_completo as dependente_nome
                              FROM veiculos v
                              INNER JOIN moradores m ON v.morador_id = m.id AND m.tenant_id=v.tenant_id
                              LEFT JOIN dependentes d ON v.dependente_id = d.id AND d.tenant_id=v.tenant_id
                              WHERE v.tenant_id = ? AND v.tag = ? AND v.ativo = 1 AND m.ativo = 1");
    
    $stmt->bind_param("is", $tenant_id, $tag);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows > 0) {
        $veiculo = $resultado->fetch_assoc();
        
        $placa = $veiculo['placa'];
        $modelo = $veiculo['modelo'];
        $cor = $veiculo['cor'];
        $morador_id = $veiculo['morador_id'];
        $dependente_id = $veiculo['dependente_id'] ?? null;
        $tipoAlerta = 'MORADOR';
        
        if (!empty($dependente_id) && !empty($veiculo['dependente_nome'])) {
            $tipo = 'Morador';
            $tipoAlerta = 'DEPENDENTE';
            $status = "✅ Acesso liberado via RFID - " . $veiculo['dependente_nome'] . " (dependente de " . $veiculo['morador_nome'] . ")";
        } else {
            $tipo = 'Morador';
            $status = "✅ Acesso liberado via RFID - " . $veiculo['morador_nome'];
        }
        
        $unidade = $veiculo['morador_unidade'];
        $liberado = 1;
        
        $nome_acesso = $veiculo['dependente_nome'] ?? $veiculo['morador_nome'];
        $alertasUnidade = [];
        $registroId = 0;
        if ($tipo_acesso === 'Entrada') {
            if (!alertas_unidade_migration_instalada($conexao)) {
                http_response_code(503);
                retornar_json(false, 'O controle de alertas não está disponível. Aplique a migration do módulo antes de liberar acessos.', ['codigo'=>'MIGRATION_ALERTAS_PENDENTE','action'=>'deny']);
            }
            $conexao->begin_transaction();
            try {
                $contextoAlerta = alertas_unidade_construir_contexto_registro($conexao,$tenant_id,[
                    'contexto'=>'RFID','data_hora'=>$timestamp,'tipo_pessoa'=>$tipoAlerta,
                    'morador_id'=>(int)$morador_id,'dependente_id'=>$dependente_id ? (int)$dependente_id : null,
                    'unidade'=>$unidade,'placa'=>$placa,'tipo_acesso'=>'Entrada','pessoa_nome'=>$nome_acesso,
                ]);
                $alertasUnidade = alertas_unidade_avaliar($conexao,(int)$tenant_id,$contextoAlerta,true);
                $bloqueado = false;
                foreach ($alertasUnidade as $alertaUnidade) if (($alertaUnidade['acao']??'') === 'BLOQUEAR') $bloqueado = true;
                if ($bloqueado) {
                    alertas_unidade_registrar_automaticos($conexao,(int)$tenant_id,$contextoAlerta,0,$alertasUnidade,0,'Leitor RFID',true);
                    $conexao->commit();
                    retornar_json(false,'Acesso bloqueado por alerta da unidade.',[
                        'liberado'=>false,'action'=>'deny','codigo'=>'ACESSO_BLOQUEADO_ALERTA',
                        'mensagem'=>'Acesso bloqueado por alerta da unidade.','alertas_unidade'=>$alertasUnidade
                    ]);
                }
                $stmt_registro = $conexao->prepare("INSERT INTO registros_acesso (tenant_id,data_hora,placa,modelo,cor,tag,tipo,morador_id,unidade_destino,status,liberado,tipo_acesso,dependente_id,usuario_liberou) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                if (!$stmt_registro) throw new RuntimeException('Não foi possível preparar o registro RFID.');
                $dependenteRegistro = $dependente_id ? (int)$dependente_id : null;
                $usuarioLiberou = 'Leitor RFID';
                $stmt_registro->bind_param('issssssissisis',$tenant_id,$timestamp,$placa,$modelo,$cor,$tag,$tipo,$morador_id,$unidade,$status,$liberado,$tipo_acesso,$dependenteRegistro,$usuarioLiberou);
                if (!$stmt_registro->execute()) { $stmt_registro->close(); throw new RuntimeException('Não foi possível gravar o registro RFID.'); }
                $registroId = (int)$conexao->insert_id;
                $stmt_registro->close();
                alertas_unidade_registrar_automaticos($conexao,(int)$tenant_id,$contextoAlerta,$registroId,$alertasUnidade,0,'Leitor RFID',false);
                $conexao->commit();
            } catch (Throwable $erroRfid) {
                try{$conexao->rollback();}catch(Throwable $erroRollback){}
                error_log('[WebhookRFID] Falha interna ao concluir leitura.');
                http_response_code(500);
                retornar_json(false,'Não foi possível concluir a leitura RFID.',['action'=>'deny']);
            }
        } else {
            $stmt_registro = $conexao->prepare("INSERT INTO registros_acesso (tenant_id,data_hora,placa,modelo,cor,tag,tipo,morador_id,unidade_destino,status,liberado,tipo_acesso,dependente_id,usuario_liberou) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $dependenteRegistro = $dependente_id ? (int)$dependente_id : null;
            $usuarioLiberou = 'Leitor RFID';
            $stmt_registro->bind_param('issssssissisis',$tenant_id,$timestamp,$placa,$modelo,$cor,$tag,$tipo,$morador_id,$unidade,$status,$liberado,$tipo_acesso,$dependenteRegistro,$usuarioLiberou);
            $stmt_registro->execute();
            $registroId = (int)$conexao->insert_id;
            $stmt_registro->close();
        }
        registrar_log('WEBHOOK_RFID_SUCESSO', "Webhook RFID: $placa (TAG RFID: $tag) - " . $nome_acesso);
        $alertas_disparados = alertas_acesso_processar_evento($conexao, (int)$tenant_id, 'rfid_webhook', [
            'placa' => $placa, 'modelo' => $modelo, 'cor' => $cor,
            'morador_nome' => $veiculo['morador_nome'], 'unidade' => $unidade,
            'dependente_nome' => $veiculo['dependente_nome'] ?? '', 'tipo_acesso' => $tipo_acesso,
        ], hash('sha256', 'rfid_webhook|' . $tag . '|' . $timestamp));
        
        retornar_json(true, "Acesso liberado", array(
            'action' => 'open_gate',
            'duration' => 5,
            'message' => "Bem-vindo, " . ($veiculo['dependente_nome'] ?? $veiculo['morador_nome']),
            'alertas' => $alertas_disparados,
            'alertas_unidade' => $alertasUnidade
        ));
        
    } else {
        registrar_log('WEBHOOK_RFID_NEGADO', "Webhook RFID negado: TAG RFID $tag");
        
        retornar_json(false, "Acesso negado", array(
            'action' => 'deny',
            'message' => "Acesso negado"
        ));
    }
    
    $stmt->close();
}

// ========== TESTAR CONEXÃO COM EQUIPAMENTO RFID ==========
if ($metodo === 'GET' && isset($_GET['acao']) && $_GET['acao'] === 'testar_conexao') {
    
    $stmt = $conexao->prepare("SELECT chave, valor FROM configuracoes WHERE tenant_id = $tenant_id AND chave LIKE 'rfid_%'");
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $config = array();
    while ($row = $resultado->fetch_assoc()) {
        $config[$row['chave']] = $row['valor'];
    }
    $stmt->close();
    
    $ip = $config['rfid_ip'] ?? '';
    $porta = $config['rfid_porta'] ?? '3000';
    
    if (empty($ip)) {
        retornar_json(false, "IP do equipamento RFID não configurado");
    }
    
    $url = "http://$ip:$porta/api/status";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    
    if ($http_code === 200 || $http_code === 401) {
        retornar_json(true, "Conexão com equipamento RFID estabelecida", array(
            'ip' => $ip,
            'porta' => $porta,
            'status' => 'online'
        ));
    } else {
        retornar_json(false, "Não foi possível conectar ao equipamento RFID", array(
            'ip' => $ip,
            'porta' => $porta,
            'erro' => $erro,
            'status' => 'offline'
        ));
    }
}

// ========== LISTAR ÚLTIMOS ACESSOS ==========
if ($metodo === 'GET' && isset($_GET['acao']) && $_GET['acao'] === 'ultimos_acessos') {
    $limite = intval($_GET['limite'] ?? 10);
    if ($limite <= 0) $limite = 10;
    if ($limite > 100) $limite = 100;

    $tem_origem = false;
    $res_coluna_origem = $conexao->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros_acesso' AND COLUMN_NAME = 'origem'");
    if ($res_coluna_origem) {
        $tem_origem = intval($res_coluna_origem->fetch_assoc()['c'] ?? 0) > 0;
    }

    $origem_expr = $tem_origem ? "r.origem" : "NULL";
    
    $sql = "SELECT 
            r.id,
            r.data_hora,
            DATE_FORMAT(r.data_hora, '%d/%m/%Y') as data,
            DATE_FORMAT(r.data_hora, '%H:%i:%s') as hora,
            DATE_FORMAT(r.data_hora, '%d/%m/%Y %H:%i:%s') as data_hora_formatada,
            COALESCE(v_tag.tag, v_placa.tag, r.tag) as tag_rfid,
            COALESCE(v_tag.tag, v_placa.tag, r.tag) as tag,
            COALESCE(v_tag.placa, v_placa.placa, r.placa) as placa,
            COALESCE(v_tag.modelo, v_placa.modelo, r.modelo) as modelo,
            COALESCE(v_tag.cor, v_placa.cor, r.cor) as cor,
            r.tipo,
            r.unidade_destino as unidade,
            COALESCE(m.nome, r.nome_visitante, r.tipo) as morador,
            d.nome_completo as dependente,
            $origem_expr as origem,
            CASE
                WHEN LOWER(COALESCE($origem_expr, '')) = 'controlid' THEN 'ControleID'
                WHEN LOWER(COALESCE($origem_expr, r.status, r.observacao, '')) LIKE '%manual%' THEN 'Registro Manual'
                WHEN LOWER(COALESCE($origem_expr, '')) = 'portal' THEN 'Visitante'
                WHEN LOWER(COALESCE(r.tipo, '')) LIKE '%visitante%' THEN 'Visitante'
                WHEN LOWER(COALESCE(r.status, r.observacao, r.tipo, '')) LIKE '%rfid%' THEN 'TAG RFID'
                ELSE NULL
            END as origem_liberacao,
            r.usuario_liberou,
            r.liberado, r.status, r.tipo_acesso
            FROM registros_acesso r
            LEFT JOIN moradores m ON r.morador_id = m.id
            LEFT JOIN veiculos v_tag ON r.tag IS NOT NULL AND r.tag <> '' AND v_tag.tag = r.tag
            LEFT JOIN veiculos v_placa ON (r.tag IS NULL OR r.tag = '') AND r.placa IS NOT NULL AND r.placa <> '' AND v_placa.placa = r.placa
            LEFT JOIN dependentes d ON d.id = COALESCE(v_tag.dependente_id, v_placa.dependente_id)
            WHERE r.liberado = 1
            ORDER BY r.data_hora DESC
            LIMIT ?";
    
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $limite);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $acessos = array();
    
    if ($resultado && $resultado->num_rows > 0) {
        while ($row = $resultado->fetch_assoc()) {
            $acessos[] = $row;
        }
    }
    
    $stmt->close();
    retornar_json(true, "Últimos acessos listados", $acessos);
}

fechar_conexao($conexao);
