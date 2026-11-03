<?php
session_start();
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/alertas_unidade_helper.php';
ob_end_clean();

date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
$origem = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (preg_match('/^https?:\/\/([a-z0-9-]+\.)?erpcondominios\.com\.br$/i', $origem) || preg_match('/^https?:\/\/localhost(:\d+)?$/i', $origem)) {
    header('Access-Control-Allow-Origin: ' . $origem);
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function portal_au_responder($sucesso, $mensagem, $dados = [], $status = 200, $codigo = null) {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code((int)$status);
    $resposta = ['sucesso'=>(bool)$sucesso,'mensagem'=>(string)$mensagem,'dados'=>$dados === null ? [] : $dados];
    if ($codigo !== null) $resposta['codigo'] = $codigo;
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function portal_au_erro($mensagem, $status = 422, $codigo = null, $dados = []) { portal_au_responder(false, $mensagem, $dados, $status, $codigo); }
set_exception_handler(function (Throwable $erro) {
    error_log('[PortalAlertasUnidade] erro interno não exposto ao cliente.');
    portal_au_responder(false, 'Não foi possível concluir a operação de alertas. Tente novamente.', [], 500, 'INTERNAL_ERROR');
});
function portal_au_texto($valor, $limite) {
    $valor = trim(strip_tags((string)$valor));
    return function_exists('mb_substr') ? mb_substr($valor, 0, $limite, 'UTF-8') : substr($valor, 0, $limite);
}
function portal_au_token() {
    $cabecalho = '';
    if (function_exists('getallheaders')) {
        $cabecalhos = getallheaders();
        $cabecalho = $cabecalhos['Authorization'] ?? $cabecalhos['authorization'] ?? '';
    }
    if ($cabecalho === '') $cabecalho = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([A-Za-z0-9._~+\/-]{20,256})$/i', trim($cabecalho), $m)) return null;
    return $m[1];
}
function portal_au_auth(mysqli $c) {
    $token = portal_au_token();
    if ($token === null) portal_au_erro('Token Bearer obrigatório.', 401, 'AUTH_REQUIRED');
    $stmtColuna = au_query($c, "SELECT COUNT(*) total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sessoes_portal' AND COLUMN_NAME='tenant_id'", '', []);
    $temTenantSessao = (int)($stmtColuna->get_result()->fetch_assoc()['total'] ?? 0) > 0; $stmtColuna->close();
    $sqlSessao = $temTenantSessao
        ? 'SELECT sp.morador_id,m.tenant_id,m.nome,m.unidade FROM sessoes_portal sp INNER JOIN moradores m ON m.id=sp.morador_id AND m.ativo=1 AND (sp.tenant_id IS NULL OR m.tenant_id=sp.tenant_id) WHERE sp.token=? AND sp.ativo=1 AND sp.data_expiracao>NOW() LIMIT 1'
        : 'SELECT sp.morador_id,m.tenant_id,m.nome,m.unidade FROM sessoes_portal sp INNER JOIN moradores m ON m.id=sp.morador_id AND m.ativo=1 WHERE sp.token=? AND sp.ativo=1 AND sp.data_expiracao>NOW() LIMIT 1';
    $stmt = au_query($c, $sqlSessao, 's', [$token]);
    $sessao = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$sessao || (int)$sessao['tenant_id'] <= 0 || (int)$sessao['morador_id'] <= 0) portal_au_erro('Sessão do portal inválida ou expirada.', 401, 'AUTH_INVALID');
    return ['tenant_id'=>(int)$sessao['tenant_id'],'morador_id'=>(int)$sessao['morador_id'],'morador_nome'=>(string)$sessao['nome'],'unidade'=>(string)$sessao['unidade']];
}
function portal_au_data($valor, $obrigatoria = false) {
    $valor = trim((string)$valor);
    if ($valor === '' && !$obrigatoria) return null;
    $data = au_now($valor);
    if (!$data) portal_au_erro('Informe uma data e hora válidas.', 422, 'DATA_INVALIDA');
    return $data->format('Y-m-d H:i:s');
}
function portal_au_hora($valor) {
    $valor = trim((string)$valor);
    if ($valor === '') return null;
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $valor)) portal_au_erro('A janela de horário é inválida.', 422, 'HORA_INVALIDA');
    return strlen($valor) === 5 ? $valor . ':00' : $valor;
}
function portal_au_dias($valor) {
    if ($valor === null || $valor === '') return null;
    $dias = au_dias_validos($valor);
    $partes = is_array($valor) ? $valor : preg_split('/\s*,\s*/', trim((string)$valor), -1, PREG_SPLIT_NO_EMPTY);
    if (!$dias || count($dias) !== count($partes)) portal_au_erro('Os dias da semana devem estar entre 1 e 7.', 422, 'DIAS_INVALIDOS');
    return implode(',', $dias);
}
function portal_au_dependente(mysqli $c, $tenantId, $moradorId, $id) {
    $stmt = au_query($c, 'SELECT id,morador_id,nome_completo FROM dependentes WHERE tenant_id=? AND id=? AND morador_id=? AND ativo=1 LIMIT 1', 'iii', [$tenantId,$id,$moradorId]);
    $dependente = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$dependente) portal_au_erro('O dependente não pertence ao morador autenticado ou está inativo.', 403, 'DEPENDENTE_FORA_DO_ESCOPO');
    return $dependente;
}
function portal_au_alvos(mysqli $c, array $auth, array $alvos, $publico, $abrangencia) {
    if ($abrangencia === 'TODOS_DO_TIPO') return [];
    if ($abrangencia !== 'ESPECIFICO' || !$alvos) portal_au_erro('A abrangência específica exige pelo menos um alvo.', 422, 'ALVOS_OBRIGATORIOS');
    if (count($alvos) > 20) portal_au_erro('Informe no máximo 20 alvos específicos.', 422, 'LIMITE_ALVOS');
    $resultado = [];
    foreach ($alvos as $alvo) {
        if (!is_array($alvo)) continue;
        $tipo = strtoupper(trim((string)($alvo['tipo_alvo'] ?? $alvo['tipo'] ?? '')));
        if (!in_array($tipo, ['VISITANTE','DOCUMENTO','PLACA'], true)) portal_au_erro('O alvo específico é inválido.', 422, 'ALVO_INVALIDO');
        $doc = null; $tipoDoc = null; $placa = null; $visitanteId = null; $nome = portal_au_texto($alvo['nome_referencia'] ?? $alvo['nome'] ?? '',150) ?: null;
        if ($tipo === 'VISITANTE') {
            if ($publico === 'VEICULO') portal_au_erro('Alvo de visitante incompatível com público de veículo.',422,'ALVO_INVALIDO');
            $visitanteId = (int)($alvo['visitante_id'] ?? 0);
            $visitante = portal_au_visitante_vinculado($c,$auth,$visitanteId);
            if (!$visitante) portal_au_erro('O visitante não pertence à sua unidade ou está inativo.',403,'VISITANTE_FORA_DO_ESCOPO');
            $doc = alertas_unidade_normalizar_documento($visitante['documento'] ?? '',$visitante['tipo_documento'] ?? 'OUTRO');
            $tipoDoc = strtoupper((string)($visitante['tipo_documento'] ?? 'OUTRO'));
            if (!in_array($tipoDoc,['CPF','RG','OUTRO'],true)) $tipoDoc='OUTRO';
            $nome = (string)$visitante['nome_completo'];
        } elseif ($tipo === 'DOCUMENTO') {
            $tipoDoc = strtoupper(trim((string)($alvo['tipo_documento'] ?? 'OUTRO')));
            if (!in_array($tipoDoc, ['CPF','RG','OUTRO'], true)) $tipoDoc = 'OUTRO';
            $doc = alertas_unidade_normalizar_documento($alvo['documento'] ?? '', $tipoDoc);
            if ($doc === '' || strlen($doc) > 20) portal_au_erro('Informe um documento válido para o alvo.', 422, 'ALVO_INVALIDO');
        } else {
            $placa = alertas_unidade_normalizar_placa($alvo['placa'] ?? '');
            if ($placa === '' || strlen($placa) > 10) portal_au_erro('Informe uma placa válida para o alvo.', 422, 'ALVO_INVALIDO');
        }
        $resultado[] = ['tipo_alvo'=>$tipo,'visitante_id'=>$visitanteId,'tipo_documento'=>$tipoDoc,'documento_normalizado'=>$doc,'placa_normalizada'=>$placa,'nome_referencia'=>$nome];
    }
    if (!$resultado) portal_au_erro('Adicione pelo menos um alvo específico.', 422, 'ALVOS_OBRIGATORIOS');
    return $resultado;
}
function portal_au_entrada(mysqli $c, array $auth, array $body) {
    $tenantId = $auth['tenant_id']; $moradorId = $auth['morador_id'];
    $titularTipo = strtoupper(trim((string)($body['titular_tipo'] ?? 'MORADOR')));
    if (!in_array($titularTipo, ['MORADOR','DEPENDENTE'], true)) portal_au_erro('O tipo de titular é inválido.', 422, 'TITULAR_INVALIDO');
    $dependenteId = null;
    if ($titularTipo === 'DEPENDENTE') {
        $dependenteId = (int)($body['dependente_id'] ?? 0);
        portal_au_dependente($c,$tenantId,$moradorId,$dependenteId);
    } elseif (!empty($body['dependente_id'])) {
        portal_au_erro('Não informe dependente quando o titular é o morador.', 422, 'TITULAR_INVALIDO');
    }
    $titulo = portal_au_texto($body['titulo'] ?? '',120); $mensagem = portal_au_texto($body['mensagem'] ?? '',1000);
    if ($titulo === '' || $mensagem === '') portal_au_erro('Informe título e mensagem do alerta.',422,'CAMPOS_OBRIGATORIOS');
    $nivel = strtoupper(trim((string)($body['nivel'] ?? ''))); $acao = strtoupper(trim((string)($body['acao'] ?? '')));
    $publico = strtoupper(trim((string)($body['publico'] ?? ''))); $abrangencia = strtoupper(trim((string)($body['abrangencia'] ?? 'TODOS_DO_TIPO')));
    if (!in_array($nivel,['INFORMATIVO','ATENCAO','CRITICO'],true)) portal_au_erro('O nível do alerta é inválido.',422,'ENUM_INVALIDO');
    if (!in_array($acao,['APENAS_AVISAR','EXIGIR_CIENTE','CONTATAR_MORADOR','BLOQUEAR'],true)) portal_au_erro('A ação não é permitida no Portal.',422,'PORTAL_RESTRICAO');
    if ($acao === 'APENAS_AVISAR' && $nivel !== 'INFORMATIVO') portal_au_erro('A ação Apenas avisar só pode ser usada com nível Informativo.',422,'COMBINACAO_INVALIDA');
    if ($acao === 'BLOQUEAR' && $nivel !== 'CRITICO') portal_au_erro('Bloquear entrada exige nível Crítico.',422,'COMBINACAO_INVALIDA');
    if (!in_array($publico,['TODOS','VISITANTE','PRESTADOR','ENTREGADOR_AUTORIZADO','FAMILIAR','VEICULO'],true) || !in_array($abrangencia,['TODOS_DO_TIPO','ESPECIFICO'],true)) portal_au_erro('Público ou abrangência inválida.',422,'ENUM_INVALIDO');
    if ($publico === 'VEICULO' && $abrangencia === 'TODOS_DO_TIPO') portal_au_erro('Veículo exige abrangência específica por placa.',422,'COMBINACAO_INVALIDA');
    $inicio = portal_au_data($body['vigencia_inicio'] ?? '',true); $fim = portal_au_data($body['vigencia_fim'] ?? '',true);
    if ($fim < $inicio || strtotime($fim) > strtotime($inicio . ' +365 days')) portal_au_erro('A vigência deve terminar depois do início e ter no máximo 365 dias.',422,'VIGENCIA_INVALIDA');
    $dias = portal_au_dias($body['dias_semana'] ?? null); $horaInicio = portal_au_hora($body['hora_inicio'] ?? null); $horaFim = portal_au_hora($body['hora_fim'] ?? null);
    if (($horaInicio === null) !== ($horaFim === null)) portal_au_erro('Informe início e fim da janela de horário.',422,'JANELA_INVALIDA');
    $alvos = portal_au_alvos($c,$auth,is_array($body['alvos'] ?? null) ? $body['alvos'] : [],$publico,$abrangencia);
    return ['unidade'=>$auth['unidade'],'titular_tipo'=>$titularTipo,'morador_id'=>$moradorId,'dependente_id'=>$dependenteId,'titulo'=>$titulo,'mensagem'=>$mensagem,'nivel'=>$nivel,'acao'=>$acao,'publico'=>$publico,'abrangencia'=>$abrangencia,'vigencia_inicio'=>$inicio,'vigencia_fim'=>$fim,'dias_semana'=>$dias,'hora_inicio'=>$horaInicio,'hora_fim'=>$horaFim,'instrucao_operador'=>portal_au_texto($body['instrucao_operador']??'',500)?:null,'telefone_contato'=>portal_au_texto($body['telefone_contato']??'',30)?:null,'idempotency_key'=>portal_au_texto($body['idempotency_key']??($_SERVER['HTTP_X_IDEMPOTENCY_KEY']??''),64)?:null,'alvos'=>$alvos];
}
function portal_au_snapshot(array $a) {
    $campos = ['id','unidade','titular_tipo','morador_id','dependente_id','titulo','mensagem','nivel','acao','publico','abrangencia','vigencia_inicio','vigencia_fim','dias_semana','hora_inicio','hora_fim','instrucao_operador','telefone_contato','status','origem','total_acionamentos','ultimo_acionamento_em'];
    $saida = [];
    foreach ($campos as $campo) if (array_key_exists($campo, $a)) $saida[$campo] = $a[$campo];
    return $saida;
}
function portal_au_saida_alerta(array $a, $moradorId) {
    return ['id'=>(int)$a['id'],'unidade'=>$a['unidade'],'titular_tipo'=>$a['titular_tipo'],'morador_id'=>(int)$a['morador_id'],'dependente_id'=>$a['dependente_id']===null?null:(int)$a['dependente_id'],'titulo'=>$a['titulo'],'mensagem'=>$a['mensagem'],'nivel'=>$a['nivel'],'acao'=>$a['acao'],'publico'=>$a['publico'],'abrangencia'=>$a['abrangencia'],'vigencia_inicio'=>$a['vigencia_inicio'],'vigencia_fim'=>$a['vigencia_fim'],'dias_semana'=>au_dias_validos($a['dias_semana']??''),'hora_inicio'=>$a['hora_inicio'],'hora_fim'=>$a['hora_fim'],'instrucao_operador'=>$a['instrucao_operador'],'telefone_contato'=>$a['telefone_contato'],'status'=>(($a['status']==='ATIVO'&&!empty($a['vigencia_fim'])&&$a['vigencia_fim']<date('Y-m-d H:i:s'))?'EXPIRADO':$a['status']),'origem'=>$a['origem'],'total_acionamentos'=>(int)$a['total_acionamentos'],'ultimo_acionamento_em'=>$a['ultimo_acionamento_em'],'pode_editar'=>((int)$a['morador_id']===$moradorId && in_array($a['origem'],['PORTAL','APP'],true) && (int)$a['criado_por_morador_id']===$moradorId)];
}

function portal_au_rate_limit(mysqli $c, array $auth) {
    $bucket = date('Y-m-d H:i:00', floor(time() / 60) * 60);
    $usuarioId = -abs((int)$auth['morador_id']);
    $stmt = au_query($c, 'INSERT INTO alertas_unidade_rate_limits (tenant_id,usuario_id,unidade,bucket_inicio,contador) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE contador=contador+1,atualizado_em=NOW()', 'iiss', [$auth['tenant_id'],$usuarioId,$auth['unidade'],$bucket]);
    $stmt->close();
    $stmt = au_query($c, 'SELECT contador FROM alertas_unidade_rate_limits WHERE tenant_id=? AND usuario_id=? AND unidade=? AND bucket_inicio=? LIMIT 1', 'iiss', [$auth['tenant_id'],$usuarioId,$auth['unidade'],$bucket]);
    $contador = (int)($stmt->get_result()->fetch_assoc()['contador'] ?? 0); $stmt->close();
    if ($contador > 60) portal_au_erro('Limite temporário de verificações atingido. Aguarde um minuto.',429,'RATE_LIMIT');
}
function portal_au_coluna_existe(mysqli $c, string $tabela, string $coluna): bool {
    static $cache=[];
    $chave=strtolower($tabela . '.' . $coluna);
    if (array_key_exists($chave,$cache)) return $cache[$chave];
    $stmt = au_query($c, 'SELECT COUNT(*) total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', 'ss', [$tabela,$coluna]);
    $existe = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0; $stmt->close();
    return $cache[$chave]=$existe;
}
function portal_au_visitante_vinculado(mysqli $c, array $auth, int $visitanteId) {
    if ($visitanteId <= 0) return null;
    $vinculos=[];$parametros=[];
    if (portal_au_coluna_existe($c,'visitantes','morador_id')) {$vinculos[]='morador_id=?';$parametros[]=(int)$auth['morador_id'];}
    if (portal_au_coluna_existe($c,'visitantes','cadastrado_por_morador_id')) {$vinculos[]='cadastrado_por_morador_id=?';$parametros[]=(int)$auth['morador_id'];}
    if (!$vinculos) return null;
    $sql='SELECT id,nome_completo,documento,tipo_documento FROM visitantes WHERE tenant_id=? AND id=? AND ativo=1 AND (' . implode(' OR ',$vinculos) . ') LIMIT 1';
    $stmt=au_query($c,$sql,'ii' . str_repeat('i',count($parametros)),array_merge([(int)$auth['tenant_id'],$visitanteId],$parametros));
    $visitante=$stmt->get_result()->fetch_assoc();$stmt->close();
    return $visitante ?: null;
}

$metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($metodo,['GET','POST'],true)) portal_au_erro('Método não permitido.',405,'METHOD_NOT_ALLOWED');
$conexao = conectar_banco();
$conexao->set_charset('utf8mb4');
$auth = portal_au_auth($conexao);
if (!alertas_unidade_migration_instalada($conexao)) portal_au_erro('A estrutura de Alertas da Unidade ainda não foi instalada. Aplique a migration do módulo antes de usar esta função.',503,'MIGRATION_ALERTAS_PENDENTE');
$body = [];
if ($metodo === 'POST') { $body=json_decode(file_get_contents('php://input'),true); if(!is_array($body)) portal_au_erro('Envie um JSON válido.',400,'JSON_INVALIDO'); }
$acao = strtolower(trim((string)($_GET['acao'] ?? $body['acao'] ?? '')));
if ($acao === '') $acao = $metodo === 'GET' ? 'listar' : '';

if ($acao === 'buscar_visitante' && $metodo === 'GET') {
    portal_au_rate_limit($conexao,$auth);
    $termo = portal_au_texto($_GET['q'] ?? '', 120);
    $tamanhoTermo = function_exists('mb_strlen') ? mb_strlen($termo, 'UTF-8') : strlen($termo);
    if ($tamanhoTermo < 3) portal_au_erro('Informe pelo menos 3 caracteres para pesquisar.',422,'TERMO_CURTO');
    $vinculos = [];
    $parametrosVinculo = [];
    if (portal_au_coluna_existe($conexao,'visitantes','morador_id')) { $vinculos[]='v.morador_id=?'; $parametrosVinculo[]=(int)$auth['morador_id']; }
    if (portal_au_coluna_existe($conexao,'visitantes','cadastrado_por_morador_id')) { $vinculos[]='v.cadastrado_por_morador_id=?'; $parametrosVinculo[]=(int)$auth['morador_id']; }
    if (!$vinculos) portal_au_responder(true,'Visitantes encontrados.',['visitantes'=>[]]);
    $doc = preg_replace('/[^0-9A-Za-z]/','',$termo);
    $like = '%' . $termo . '%'; $docLike = '%' . $doc . '%';
    $sql = 'SELECT v.id,v.nome_completo,v.tipo_documento,v.documento FROM visitantes v WHERE v.tenant_id=? AND v.ativo=1 AND (' . implode(' OR ',$vinculos) . ') AND (v.nome_completo LIKE ? OR REPLACE(REPLACE(REPLACE(REPLACE(UPPER(v.documento),\'.\',\'\'),\'-\',\'\'),\'/\',\'\'),\' \',\'\') LIKE UPPER(?)) ORDER BY v.nome_completo LIMIT 10';
    $tipos = 'i' . str_repeat('i',count($parametrosVinculo)) . 'ss';
    $parametros = array_merge([(int)$auth['tenant_id']],$parametrosVinculo,[$like,$docLike]);
    $stmt = au_query($conexao,$sql,$tipos,$parametros);
    $visitantes = [];
    foreach (au_rows($stmt) as $visitante) $visitantes[] = ['id'=>(int)$visitante['id'],'nome_completo'=>$visitante['nome_completo'],'tipo_documento'=>$visitante['tipo_documento'],'documento_mascarado'=>alertas_unidade_mascarar_documento($visitante['documento'],$visitante['tipo_documento'])];
    $stmt->close();
    portal_au_responder(true,'Visitantes encontrados.',['visitantes'=>$visitantes]);
}

if ($acao === 'verificar_convite' && $metodo === 'POST') {
    portal_au_rate_limit($conexao,$auth);
    $visitanteId = (int)($body['visitante_id'] ?? 0);
    $dataInicio = portal_au_data($body['data_inicial'] ?? '', true);
    $tipo = alertas_unidade_mapear_tipo($body['tipo_visitante'] ?? '') ?: 'VISITANTE';
    if (!in_array($tipo,['VISITANTE','PRESTADOR','ENTREGADOR_AUTORIZADO','FAMILIAR'],true)) $tipo = 'VISITANTE';
    $visitante = portal_au_visitante_vinculado($conexao,$auth,$visitanteId);
    if (!$visitante) portal_au_erro('O visitante não pertence a este condomínio ou está inativo.',403,'VISITANTE_FORA_DO_ESCOPO');
    $ctx = [
        'contexto'=>'CONVIDADOS','unidade'=>$auth['unidade'],'morador_id'=>$auth['morador_id'],
        'tipo_pessoa'=>$tipo,'visitante_id'=>(int)$visitante['id'],'documento'=>$visitante['documento'],
        'tipo_documento'=>$visitante['tipo_documento'],'placa'=>portal_au_texto($body['placa']??'',10),
        'tipo_acesso'=>'Entrada','data_hora'=>$dataInicio,
        'pessoa_nome'=>$visitante['nome_completo'],
    ];
    $alertas = alertas_unidade_avaliar($conexao,(int)$auth['tenant_id'],$ctx);
    $saida=[];
    foreach($alertas as $alerta)$saida[]=['id'=>(int)$alerta['id'],'titulo'=>$alerta['titulo'],'mensagem'=>$alerta['mensagem'],'nivel'=>$alerta['nivel'],'acao'=>$alerta['acao'],'unidade'=>$alerta['unidade'],'instrucao_operador'=>$alerta['instrucao_operador'],'telefone_contato'=>$alerta['telefone_contato']];
    portal_au_responder(true,'Conflitos de alertas verificados.',['alertas'=>$saida,'data_hora'=>$dataInicio]);
}

if ($acao === 'preferencia_push' && $metodo === 'GET') {
    portal_au_responder(true,'Preferência carregada.',['push_ativo'=>alertas_unidade_morador_push_ativo($conexao,(int)$auth['tenant_id'],(int)$auth['morador_id'])]);
}
if ($acao === 'preferencia_push' && $metodo === 'POST') {
    $pushAtivo = !empty($body['push_ativo']) ? 1 : 0;
    $stmt=au_query($conexao,'INSERT INTO alertas_unidade_preferencias (tenant_id,morador_id,push_ativo) VALUES (?,?,?) ON DUPLICATE KEY UPDATE push_ativo=VALUES(push_ativo),atualizado_em=NOW()','iii',[(int)$auth['tenant_id'],(int)$auth['morador_id'],$pushAtivo]);
    $stmt->close();
    portal_au_responder(true,'Preferência de notificações atualizada.',['push_ativo'=>$pushAtivo===1]);
}
if ($acao === 'opcoes' && $metodo === 'GET') {
    $stmt=au_query($conexao,'SELECT id,nome_completo FROM dependentes WHERE tenant_id=? AND morador_id=? AND ativo=1 ORDER BY nome_completo','ii',[$auth['tenant_id'],$auth['morador_id']]);
    portal_au_responder(true,'Opções carregadas.',['dependentes'=>au_rows($stmt)]);
}
if ($acao === 'listar' && $metodo === 'GET') {
    $where=['a.tenant_id=?','a.unidade=?'];$types='is';$params=[$auth['tenant_id'],$auth['unidade']];
    $status=strtoupper(portal_au_texto($_GET['status']??'',30));if($status!==''){if($status==='EXPIRADO')$where[]="a.status='ATIVO' AND a.vigencia_fim IS NOT NULL AND a.vigencia_fim<NOW()";else{$where[]='a.status=?';$types.='s';$params[]=$status;}}
    $pagina=max(1,(int)($_GET['pagina']??1));$limite=min(50,max(1,(int)($_GET['por_pagina']??20)));$offset=($pagina-1)*$limite;$base=implode(' AND ',$where);
    $stmt=au_query($conexao,"SELECT COUNT(*) total FROM alertas_unidade a WHERE $base",$types,$params);$total=(int)($stmt->get_result()->fetch_assoc()['total']??0);$stmt->close();
    $stmt=au_query($conexao,"SELECT a.* FROM alertas_unidade a WHERE $base ORDER BY a.vigencia_inicio DESC,a.id DESC LIMIT ? OFFSET ?",$types.'ii',array_merge($params,[$limite,$offset]));$alertas=[];foreach(au_rows($stmt) as $a)$alertas[]=portal_au_saida_alerta($a,$auth['morador_id']);$stmt->close();
    portal_au_responder(true,'Alertas listados com sucesso.',['alertas'=>$alertas,'total'=>$total,'pagina'=>$pagina,'por_pagina'=>$limite]);
}
if ($acao === 'detalhe' && $metodo === 'GET') {
    $id=(int)($_GET['id']??0);
    if($id<=0)portal_au_erro('Alerta inválido.',422,'ID_INVALIDO');
    $stmt=au_query($conexao,'SELECT a.* FROM alertas_unidade a WHERE a.tenant_id=? AND a.unidade=? AND a.id=? LIMIT 1','isi',[$auth['tenant_id'],$auth['unidade'],$id]);
    $alerta=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$alerta)portal_au_erro('Alerta não encontrado neste condomínio.',404,'NOT_FOUND');
    $podeEditar=portal_au_saida_alerta($alerta,$auth['morador_id'])['pode_editar'];
    $stmt=au_query($conexao,'SELECT id,alerta_id,tipo_alvo,visitante_id,tipo_documento,documento_normalizado,placa_normalizada,nome_referencia FROM alertas_unidade_alvos WHERE tenant_id=? AND alerta_id=? ORDER BY id','ii',[$auth['tenant_id'],$id]);
    $alvos=[];foreach(au_rows($stmt) as $a)$alvos[]=['id'=>(int)$a['id'],'tipo_alvo'=>$a['tipo_alvo'],'visitante_id'=>empty($a['visitante_id'])?null:(int)$a['visitante_id'],'tipo_documento'=>$a['tipo_documento'],'documento_mascarado'=>!empty($a['documento_normalizado'])?alertas_unidade_mascarar_documento($a['documento_normalizado'],$a['tipo_documento']):null,'documento_edicao'=>$podeEditar&&empty($a['visitante_id'])?$a['documento_normalizado']:null,'placa'=>$a['placa_normalizada'],'nome_referencia'=>$a['nome_referencia']];$stmt->close();
    $stmt=au_query($conexao,'SELECT id,alerta_id,contexto,decisao,pessoa_nome,pessoa_documento_mascarado,tipo_pessoa,placa,criado_em FROM alertas_unidade_ciencias WHERE tenant_id=? AND alerta_id=? ORDER BY criado_em DESC,id DESC LIMIT 100','ii',[$auth['tenant_id'],$id]);
    $ciencias=au_rows($stmt);$stmt->close();
    $stmt=au_query($conexao,'SELECT id,alerta_id,contexto,decisao,usuario_nome,pessoa_nome,pessoa_documento_mascarado,tipo_pessoa,placa,criado_em FROM alertas_unidade_acionamentos WHERE tenant_id=? AND alerta_id=? ORDER BY criado_em DESC,id DESC LIMIT 100','ii',[$auth['tenant_id'],$id]);
    $acionamentos=au_rows($stmt);$stmt->close();
    portal_au_responder(true,'Detalhe carregado.',['alerta'=>portal_au_saida_alerta($alerta,$auth['morador_id']),'alvos'=>$alvos,'ciencias'=>$ciencias,'acionamentos'=>$acionamentos,'pode_editar'=>$podeEditar]);
}
if ($acao === 'salvar' && $metodo === 'POST') {
    $id=(int)($body['id']??0);$entrada=portal_au_entrada($conexao,$auth,$body);$hash=hash('sha256',json_encode($entrada,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$conexao->begin_transaction();try{
        if($entrada['idempotency_key']!==null){$stmt=au_query($conexao,'SELECT id,idempotency_payload_hash FROM alertas_unidade WHERE tenant_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE','is',[$auth['tenant_id'],$entrada['idempotency_key']]);$old=$stmt->get_result()->fetch_assoc();$stmt->close();if($old){if((int)$old['id']!==$id&&$id>0||!hash_equals((string)$old['idempotency_payload_hash'],$hash)){ $conexao->rollback();portal_au_erro('A chave de idempotência já foi usada para outro alerta.',409,'IDEMPOTENCY_CONFLICT');}$conexao->commit();portal_au_responder(true,'Alerta já havia sido salvo.',['id'=>(int)$old['id'],'idempotente'=>true]);}}
        if($id<=0&&alertas_unidade_contar_ativos_unidade($conexao,(int)$auth['tenant_id'],(string)$auth['unidade'])>=30){$conexao->rollback();portal_au_erro('Esta unidade já possui o limite de 30 alertas vigentes.',422,'LIMITE_ALERTAS_UNIDADE');}
        $antes=null;$operacao='CRIAR';
        if($id>0){
            $stmt=au_query($conexao,'SELECT * FROM alertas_unidade WHERE tenant_id=? AND unidade=? AND id=? AND criado_por_morador_id=? AND origem IN (\'PORTAL\',\'APP\') LIMIT 1 FOR UPDATE','isii',[$auth['tenant_id'],$auth['unidade'],$id,$auth['morador_id']]);$atual=$stmt->get_result()->fetch_assoc();$stmt->close();
            if(!$atual||!in_array($atual['status'],['ATIVO','PAUSADO'],true)||portal_au_saida_alerta($atual,$auth['morador_id'])['pode_editar']!==true){$conexao->rollback();portal_au_erro('Este alerta não pode ser editado por esta sessão.',403,'ALERTA_SOMENTE_LEITURA');}
            $antes=portal_au_snapshot($atual);$sql='UPDATE alertas_unidade SET titular_tipo=?,morador_id=?,dependente_id=?,titulo=?,mensagem=?,nivel=?,acao=?,publico=?,abrangencia=?,vigencia_inicio=?,vigencia_fim=?,dias_semana=?,hora_inicio=?,hora_fim=?,instrucao_operador=?,telefone_contato=?,idempotency_key=?,idempotency_payload_hash=? WHERE tenant_id=? AND unidade=? AND id=? AND criado_por_morador_id=?';$params=[$entrada['titular_tipo'],$entrada['morador_id'],$entrada['dependente_id'],$entrada['titulo'],$entrada['mensagem'],$entrada['nivel'],$entrada['acao'],$entrada['publico'],$entrada['abrangencia'],$entrada['vigencia_inicio'],$entrada['vigencia_fim'],$entrada['dias_semana'],$entrada['hora_inicio'],$entrada['hora_fim'],$entrada['instrucao_operador'],$entrada['telefone_contato'],$entrada['idempotency_key'],$hash,$auth['tenant_id'],$auth['unidade'],$id,$auth['morador_id']];$stmt=au_query($conexao,$sql,'siisssssssssssssssisii',$params);$stmt->close();$operacao='EDITAR';
        }else{
            $sql='INSERT INTO alertas_unidade (tenant_id,unidade,titular_tipo,morador_id,dependente_id,titulo,mensagem,nivel,acao,publico,abrangencia,vigencia_inicio,vigencia_fim,dias_semana,hora_inicio,hora_fim,instrucao_operador,telefone_contato,status,origem,criado_por_tipo,criado_por_morador_id,idempotency_key,idempotency_payload_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $params=[$auth['tenant_id'],$entrada['unidade'],$entrada['titular_tipo'],$entrada['morador_id'],$entrada['dependente_id'],$entrada['titulo'],$entrada['mensagem'],$entrada['nivel'],$entrada['acao'],$entrada['publico'],$entrada['abrangencia'],$entrada['vigencia_inicio'],$entrada['vigencia_fim'],$entrada['dias_semana'],$entrada['hora_inicio'],$entrada['hora_fim'],$entrada['instrucao_operador'],$entrada['telefone_contato'],'ATIVO','PORTAL','MORADOR',$auth['morador_id'],$entrada['idempotency_key'],$hash];$stmt=au_query($conexao,$sql,'issiissssssssssssssssiss',$params);$id=(int)$conexao->insert_id;$stmt->close();
        }
        $stmt=au_query($conexao,'DELETE FROM alertas_unidade_alvos WHERE tenant_id=? AND alerta_id=?','ii',[$auth['tenant_id'],$id]);$stmt->close();
        foreach($entrada['alvos'] as $alvo){$stmt=au_query($conexao,'INSERT INTO alertas_unidade_alvos (tenant_id,alerta_id,tipo_alvo,visitante_id,tipo_documento,documento_normalizado,placa_normalizada,nome_referencia) VALUES (?,?,?,?,?,?,?,?)','iisissss',[$auth['tenant_id'],$id,$alvo['tipo_alvo'],$alvo['visitante_id'],$alvo['tipo_documento'],$alvo['documento_normalizado'],$alvo['placa_normalizada'],$alvo['nome_referencia']]);$stmt->close();}
        $stmt=au_query($conexao,'SELECT * FROM alertas_unidade WHERE tenant_id=? AND id=?','ii',[$auth['tenant_id'],$id]);$depois=portal_au_snapshot($stmt->get_result()->fetch_assoc()?:[]);$stmt->close();
        $stmt=au_query($conexao,'INSERT INTO alertas_unidade_historico (tenant_id,alerta_id,operacao,usuario_id,usuario_nome,origem,dados_antes_json,dados_depois_json) VALUES (?,?,?,?,?,?,?,?)','iisissss',[$auth['tenant_id'],$id,$operacao,null,$auth['morador_nome'],'PORTAL',$antes?json_encode($antes,JSON_UNESCAPED_UNICODE):null,json_encode($depois,JSON_UNESCAPED_UNICODE)]);$stmt->close();alertas_unidade_invalidar_cache($conexao,(int)$auth['tenant_id']);$conexao->commit();portal_au_responder(true,$operacao==='CRIAR'?'Alerta criado com sucesso.':'Alerta atualizado com sucesso.',['id'=>$id],$operacao==='CRIAR'?201:200);
    }catch(Throwable $erro){$conexao->rollback();if((int)$erro->getCode()===1062)portal_au_erro('Este alerta já foi salvo. Verifique a lista antes de tentar novamente.',409,'DUPLICIDADE');throw $erro;}
}
if ($acao === 'alterar_status' && $metodo === 'POST') {
    $id=(int)($body['id']??0);$operacao=strtoupper(trim((string)($body['operacao']??'')));if($id<=0||!in_array($operacao,['PAUSAR','RETOMAR','CANCELAR'],true))portal_au_erro('Alerta ou operação inválida.',422,'OPERACAO_INVALIDA');$motivo=portal_au_texto($body['motivo']??'',500);if($operacao==='CANCELAR'&&$motivo==='')portal_au_erro('Informe o motivo do cancelamento.',422,'MOTIVO_OBRIGATORIO');$stmt=au_query($conexao,'SELECT * FROM alertas_unidade WHERE tenant_id=? AND unidade=? AND id=? LIMIT 1 FOR UPDATE','isi',[$auth['tenant_id'],$auth['unidade'],$id]);$alerta=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$alerta||!portal_au_saida_alerta($alerta,$auth['morador_id'])['pode_editar'])portal_au_erro('Este alerta é somente leitura no Portal.',403,'ALERTA_SOMENTE_LEITURA');if(in_array($alerta['status'],['CANCELADO','EXPIRADO'],true)&&$operacao!=='CANCELAR')portal_au_erro('O status atual não permite esta operação.',409,'STATUS_INVALIDO');$novo=$operacao==='PAUSAR'?'PAUSADO':($operacao==='RETOMAR'?'ATIVO':'CANCELADO');if($operacao==='RETOMAR'&&alertas_unidade_contar_ativos_unidade($conexao,(int)$auth['tenant_id'],(string)$auth['unidade'],$id)>=30)portal_au_erro('Esta unidade já possui o limite de 30 alertas vigentes.',422,'LIMITE_ALERTAS_UNIDADE');$stmt=au_query($conexao,"UPDATE alertas_unidade SET status=?,cancelado_por_tipo=CASE WHEN ?='CANCELADO' THEN 'MORADOR' ELSE cancelado_por_tipo END,cancelado_por_id=CASE WHEN ?='CANCELADO' THEN ? ELSE cancelado_por_id END,cancelado_em=CASE WHEN ?='CANCELADO' THEN NOW() ELSE cancelado_em END,motivo_cancelamento=CASE WHEN ?='CANCELADO' THEN ? ELSE motivo_cancelamento END WHERE tenant_id=? AND unidade=? AND id=?",'sssisssisi',[$novo,$novo,$novo,$auth['morador_id'],$novo,$novo,$motivo,$auth['tenant_id'],$auth['unidade'],$id]);$stmt->close();$stmt=au_query($conexao,'INSERT INTO alertas_unidade_historico (tenant_id,alerta_id,operacao,usuario_id,usuario_nome,origem,dados_antes_json,dados_depois_json,motivo) VALUES (?,?,?,?,?,?,?,?,?)','iisisssss',[$auth['tenant_id'],$id,$operacao,null,$auth['morador_nome'],'PORTAL',json_encode(['status'=>$alerta['status']],JSON_UNESCAPED_UNICODE),json_encode(['status'=>$novo],JSON_UNESCAPED_UNICODE),$motivo?:null]);$stmt->close();alertas_unidade_invalidar_cache($conexao,(int)$auth['tenant_id']);portal_au_responder(true,'Status do alerta atualizado.',['id'=>$id,'status'=>$novo]);
}
portal_au_erro('Ação não reconhecida.',400,'ACTION_INVALID');
