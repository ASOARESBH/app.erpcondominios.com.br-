<?php
// =====================================================
// API: RH — Abono de Ponto
// =====================================================
// GET  ?acao=listar&colaborador_id=N&mes=M&ano=A
// POST ?acao=salvar  {lancamento_id, abono_extras, abono_extras_min,
//                     abono_falta, abono_atraso, abono_atraso_min, abono_justificativa}

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
require_once 'rh_ponto_core.php';
ob_end_clean();

try { verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId(); }
catch (Exception $e) {
    http_response_code(401);
    retornar_json(false, 'Não autenticado.');
    exit;
}

$conn = conectar_banco();
if (!$conn) { retornar_json(false, 'Erro ao conectar ao banco.'); exit; }

try { _abono_garantir_colunas($conn); _ponto_garantir_colunas($conn); }
catch (Throwable $e) { error_log('[RH Abono] Schema pendente; tenant='.(int)$tenant_id.'; erro='.$e->getMessage()); fechar_conexao($conn); http_response_code(503); retornar_json(false,'Estrutura de Ponto/Abono pendente. Aplique as migrations RH antes de continuar.'); }

function abono_exigir_rbac(mysqli $conn,string $acao):void {
    if(function_exists('rbacTabelasDisponiveis')&&rbacTabelasDisponiveis($conn)){rbacExigir($conn,'recursos_humanos',$acao,['submodulo_chave'=>'abono']);return;}
    verificarPermissao($acao==='visualizar'?'visualizador':'gerente');
}

$metodo = $_SERVER['REQUEST_METHOD'];
$acao   = trim($_GET['acao'] ?? '');

// ── LISTAR ────────────────────────────────────────────────────────────────────
if ($metodo === 'GET' && $acao === 'listar') {
    abono_exigir_rbac($conn,'visualizar');
    $colab_id = intval($_GET['colaborador_id'] ?? 0);
    $mes      = intval($_GET['mes'] ?? 0);
    $ano      = intval($_GET['ano'] ?? 0);

    if ($colab_id <= 0 || $mes < 1 || $mes > 12 || $ano < 2000) {
        fechar_conexao($conn);
        retornar_json(false, 'Parâmetros inválidos.');
        exit;
    }

    $data_ini = sprintf('%04d-%02d-01', $ano, $mes);
    $data_fim = date('Y-m-t', strtotime($data_ini));

    $stColab = $conn->prepare('SELECT id FROM rh_colaboradores WHERE tenant_id=? AND id=? LIMIT 1');
    if (!$stColab) { error_log('[RH Abono] Falha ao preparar validação do colaborador.'); fechar_conexao($conn); retornar_json(false, 'Não foi possível validar o colaborador.'); }
    $stColab->bind_param('ii', $tenant_id, $colab_id);
    if (!$stColab->execute()) { error_log('[RH Abono] Falha ao validar colaborador; errno=' . (int)$stColab->errno); $stColab->close(); fechar_conexao($conn); retornar_json(false, 'Não foi possível validar o colaborador.'); }
    $colabExiste = $stColab->get_result()->num_rows > 0;
    $stColab->close();
    if (!$colabExiste) { fechar_conexao($conn); http_response_code(404); retornar_json(false, 'Colaborador não encontrado.'); }

    $st = $conn->prepare(
        "SELECT id,
                DATE_FORMAT(data,'%d/%m/%Y') AS d,
                DAYNAME(data) AS dn,
                tipo_dia,
                TIME_FORMAT(hora_entrada,'%H:%i') AS he,
                TIME_FORMAT(hora_saida,'%H:%i')   AS hs,
                horas_trabalhadas_min, horas_extras_min, atraso_min,
                observacoes,
                abono_extras, abono_extras_min, abono_falta,
                abono_atraso, abono_atraso_min,
                abono_justificativa, abono_usuario,
                DATE_FORMAT(abono_data,'%d/%m/%Y %H:%i') AS abono_data
         FROM rh_ponto_lancamento WHERE tenant_id = ? AND colaborador_id = ? AND data BETWEEN ? AND ?
         ORDER BY data"
    );
    if (!$st) { error_log('[RH Abono] Falha ao preparar consulta; tenant=' . (int)$tenant_id . '; errno=' . (int)$conn->errno); fechar_conexao($conn); retornar_json(false, 'Não foi possível consultar os abonos.'); }

    $st->bind_param('iiss', $tenant_id, $colab_id, $data_ini, $data_fim);
    if (!$st->execute()) { error_log('[RH Abono] Falha ao listar abonos; errno=' . (int)$st->errno); $st->close(); fechar_conexao($conn); retornar_json(false, 'Não foi possível consultar os abonos.'); }
    $res = $st->get_result();

    $dias_pt = ['Monday'=>'Segunda','Tuesday'=>'Terça','Wednesday'=>'Quarta',
                'Thursday'=>'Quinta','Friday'=>'Sexta','Saturday'=>'Sábado','Sunday'=>'Domingo'];
    $list = [];
    while ($r = $res->fetch_assoc()) {
        $r['dia_pt'] = $dias_pt[$r['dn']] ?? $r['dn'];
        $list[] = $r;
    }
    $st->close();
    fechar_conexao($conn);
    retornar_json(true, 'OK', $list);
    exit;
}

// ── SALVAR ────────────────────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'salvar') {
    abono_exigir_rbac($conn,'editar');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $lancamento_id  = intval($body['lancamento_id'] ?? 0);
    $abono_extras   = in_array($body['abono_extras'] ?? '', ['nenhum','total','parcial'])
                        ? $body['abono_extras'] : 'nenhum';
    $abono_extras_m = max(0, intval($body['abono_extras_min'] ?? 0));
    $abono_falta    = empty($body['abono_falta']) ? 0 : 1;
    $abono_atraso   = in_array($body['abono_atraso'] ?? '', ['nenhum','total','parcial'])
                        ? $body['abono_atraso'] : 'nenhum';
    $abono_atraso_m = max(0, intval($body['abono_atraso_min'] ?? 0));
    $justificativa  = trim($body['abono_justificativa'] ?? '');
    $usuario        = $_SESSION['nome'] ?? $_SESSION['usuario_nome'] ?? 'Sistema';

    if ($lancamento_id <= 0) {
        fechar_conexao($conn);
        retornar_json(false, 'ID de lançamento inválido.');
        exit;
    }

    $tem_abono = $abono_extras !== 'nenhum' || $abono_falta || $abono_atraso !== 'nenhum';

    if ($tem_abono && empty($justificativa)) {
        fechar_conexao($conn);
        retornar_json(false, 'Justificativa é obrigatória ao conceder abono.');
        exit;
    }

    if (!$tem_abono) {
        // Limpar abono existente
        $abono_extras   = 'nenhum';
        $abono_extras_m = 0;
        $abono_falta    = 0;
        $abono_atraso   = 'nenhum';
        $abono_atraso_m = 0;
    }

    $justif_val     = $tem_abono ? $justificativa : null;
    $abono_usr_val  = $tem_abono ? $usuario : null;
    $abono_data_val = $tem_abono ? date('Y-m-d H:i:s') : null;

    $ctx=$conn->prepare('SELECT l.periodo_id,l.colaborador_id,l.data,p.status FROM rh_ponto_lancamento l INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id WHERE l.tenant_id=? AND l.id=? LIMIT 1');
    if(!$ctx){fechar_conexao($conn);http_response_code(500);retornar_json(false,'Não foi possível validar o lançamento.');}
    $ctx->bind_param('ii',$tenant_id,$lancamento_id);if(!$ctx->execute()){$ctx->close();fechar_conexao($conn);http_response_code(500);retornar_json(false,'Não foi possível validar o lançamento.');}$ctxRow=$ctx->get_result()->fetch_assoc();$ctx->close();
    if(!$ctxRow){fechar_conexao($conn);http_response_code(404);retornar_json(false,'Lançamento não encontrado.');}
    if(($ctxRow['status']??'')!=='aberto'){fechar_conexao($conn);http_response_code(409);retornar_json(false,'Período fechado não permite alterar Abono.');}
    $colabIdAbono=(int)$ctxRow['colaborador_id'];
    if(!bh_obter_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono)){fechar_conexao($conn);http_response_code(503);retornar_json(false,'Colaborador ocupado por outra atualização. Tente novamente.');}
    if(!$conn->begin_transaction()){bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono);fechar_conexao($conn);http_response_code(500);retornar_json(false,'Não foi possível iniciar a alteração do Abono.');}
    $lockRow=$conn->prepare('SELECT p.status FROM rh_ponto_lancamento l INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id WHERE l.tenant_id=? AND l.id=? AND l.colaborador_id=? LIMIT 1 FOR UPDATE');
    if(!$lockRow){$conn->rollback();bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono);fechar_conexao($conn);http_response_code(500);retornar_json(false,'Não foi possível bloquear o lançamento.');}
    $lockRow->bind_param('iii',$tenant_id,$lancamento_id,$colabIdAbono);if(!$lockRow->execute()){$lockRow->close();$conn->rollback();bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono);fechar_conexao($conn);http_response_code(500);retornar_json(false,'Não foi possível bloquear o lançamento.');}$locked=$lockRow->get_result()->fetch_assoc();$lockRow->close();
    if(!$locked||($locked['status']??'')!=='aberto'){$conn->rollback();bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono);fechar_conexao($conn);http_response_code($locked?409:404);retornar_json(false,$locked?'Período fechado não permite alterar Abono.':'Lançamento não encontrado.');}

    try {
    $st = $conn->prepare(
        "UPDATE rh_ponto_lancamento
         SET abono_extras=?, abono_extras_min=?, abono_falta=?,
             abono_atraso=?, abono_atraso_min=?,
             abono_justificativa=?, abono_usuario=?, abono_data=? WHERE tenant_id = ? AND id=?"
    );
    if (!$st) { error_log('[RH Abono] Falha ao preparar atualização; tenant=' . (int)$tenant_id . '; errno=' . (int)$conn->errno); $conn->rollback(); bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível salvar o abono.'); }

    // s(extras) i(extras_min) i(falta) s(atraso) i(atraso_min) s(justif) s(usr) s(data) i(tenant) i(id)
    $st->bind_param('siisisssii',
        $abono_extras, $abono_extras_m, $abono_falta,
        $abono_atraso, $abono_atraso_m,
        $justif_val, $abono_usr_val, $abono_data_val,
        $tenant_id, $lancamento_id
    );

    if (!$st->execute()) {
        error_log('[RH Abono] Falha ao salvar; errno=' . (int)$st->errno); $st->close(); $conn->rollback(); bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono); fechar_conexao($conn); http_response_code(500);
        retornar_json(false, 'Não foi possível salvar o abono.');
    }
    $st->close();

    // O abono não recalcula o fato bruto do dia (_calcular_minutos não é chamado
    // de novo) — apenas reflete na consequência agregada: banco de horas e
    // totais do período, reaproveitando as mesmas funções do motor de ponto.
    $stLancamento = $conn->prepare(
        'SELECT periodo_id, colaborador_id, data, horas_trabalhadas_min,
                horas_extras_min, atraso_min, saida_antecipada_min
         FROM rh_ponto_lancamento WHERE tenant_id = ? AND id = ? LIMIT 1'
    );
    if (!$stLancamento) { error_log('[RH Abono] Falha ao preparar recálculo; tenant=' . (int)$tenant_id); $conn->rollback(); bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível salvar o Abono e atualizar o Banco de Horas.'); }
    $stLancamento->bind_param('ii', $tenant_id, $lancamento_id);
    if (!$stLancamento->execute()) { error_log('[RH Abono] Falha ao carregar lançamento atualizado; errno=' . (int)$stLancamento->errno); $stLancamento->close(); $conn->rollback(); bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono); fechar_conexao($conn); http_response_code(500); retornar_json(false, 'Não foi possível salvar o Abono e atualizar o Banco de Horas.'); }
    $lRow = $stLancamento->get_result()->fetch_assoc();
    $stLancamento->close();
    if (!$lRow) throw new RuntimeException('Lançamento atualizado não encontrado durante a sincronização.');
    if ($lRow) {
        $escalaAbono  = _buscar_escala($conn, $colabIdAbono, $tenant_id);
        $calcAbono    = [
            'trabalhadas'      => (int)$lRow['horas_trabalhadas_min'],
            'extras'           => (int)$lRow['horas_extras_min'],
            'atraso'           => (int)$lRow['atraso_min'],
            'saida_antecipada' => (int)$lRow['saida_antecipada_min'],
        ];
        _bh_sincronizar($conn, $lancamento_id, $colabIdAbono, $lRow['data'], $calcAbono, $escalaAbono, $tenant_id, ['lock_ja_adquirido'=>true,'transacao_ja_ativa'=>true]);
        _bh_dsr_sincronizar($conn, $colabIdAbono, $lRow['data'], $escalaAbono, $tenant_id, ['lock_ja_adquirido'=>true,'transacao_ja_ativa'=>true]);
        _recalcular_totais($conn, intval($lRow['periodo_id']), $tenant_id);
    }

    if(!$conn->commit())throw new RuntimeException('Falha ao confirmar Abono.');
    bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono);
    fechar_conexao($conn);
    retornar_json(true, $tem_abono ? 'Abono salvo com sucesso.' : 'Abono removido.');
    } catch(Throwable $e) {
        error_log('[RH Abono] Falha ao salvar/sincronizar; tenant='.(int)$tenant_id.'; lancamento='.(int)$lancamento_id.'; erro='.$e->getMessage());
        $conn->rollback();bh_liberar_lock_colaborador($conn,(int)$tenant_id,$colabIdAbono);fechar_conexao($conn);http_response_code(500);retornar_json(false,'Não foi possível salvar o Abono e atualizar o Banco de Horas.');
    }
    exit;
}

fechar_conexao($conn);
retornar_json(false, 'Ação não reconhecida.');
