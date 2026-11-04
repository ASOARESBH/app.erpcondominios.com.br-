<?php
/** Helpers para mapeamento/reprocessamento do ledger RH. */
function bh_reprocess_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [$types];
    foreach ($params as &$param) $refs[] = &$param;
    if (!call_user_func_array([$stmt, 'bind_param'], $refs)) throw new RuntimeException('Falha ao preparar parâmetros do reprocessamento.');
}
function bh_reprocess_validar_periodo(array $input): array {
    foreach (['data_inicio','data_fim','colaborador_id','departamento','offset','incluir_fechados'] as $key) {
        if (array_key_exists($key,$input) && !is_scalar($input[$key]) && $input[$key] !== null) throw new InvalidArgumentException('Filtro inválido.');
    }
    $dataInicio=trim((string)($input['data_inicio']??'')); $dataFim=trim((string)($input['data_fim']??''));
    $validarData=static function(string $data):bool{if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$data))return false;$dt=DateTime::createFromFormat('!Y-m-d',$data);return $dt instanceof DateTime&&$dt->format('Y-m-d')===$data;};
    if (!$validarData($dataInicio)||!$validarData($dataFim)||$dataInicio>$dataFim) throw new InvalidArgumentException('Período inválido; informe datas válidas em ordem crescente.');
    $dias=(int)((strtotime($dataFim)-strtotime($dataInicio))/86400)+1;
    if ($dias>366) throw new InvalidArgumentException('O período máximo é de 366 dias. Divida o intervalo.');
    $rawColab=$input['colaborador_id']??null; $colab=($rawColab===null||trim((string)$rawColab)==='')?null:filter_var($rawColab,FILTER_VALIDATE_INT);
    if ($rawColab!==null&&trim((string)$rawColab)!==''&&($colab===false||(int)$colab<=0)) throw new InvalidArgumentException('colaborador_id inválido.');
    $dept=trim((string)($input['departamento']??''));
    if(bh_tamanho_texto($dept)>100)throw new InvalidArgumentException('Departamento inválido.');
    if ($colab===null&&$dept==='') throw new InvalidArgumentException('Selecione um colaborador ou departamento.');
    $offset=filter_var($input['offset']??0,FILTER_VALIDATE_INT);
    if ($offset===false||$offset<0||$offset>1000000) throw new InvalidArgumentException('Cursor de lote inválido.');
    return ['data_inicio'=>$dataInicio,'data_fim'=>$dataFim,'colaborador_id'=>$colab===null?null:(int)$colab,'departamento'=>$dept,
        'offset'=>(int)$offset,'incluir_fechados'=>!empty($input['incluir_fechados'])];
}
function bh_reprocess_normalizar_periodo_input(array $input): array {
    if (empty($input['data_inicio'])||empty($input['data_fim'])) {
        if ((isset($input['mes'])&&!is_scalar($input['mes']))||(isset($input['ano'])&&!is_scalar($input['ano']))) throw new InvalidArgumentException('Mês ou ano inválido.');
        $mes=filter_var($input['mes']??null,FILTER_VALIDATE_INT); $ano=filter_var($input['ano']??null,FILTER_VALIDATE_INT);
        if (!$mes||$mes<1||$mes>12||!$ano||$ano<2000||$ano>2099) throw new InvalidArgumentException('Informe mês/ano ou datas inicial/final válidas.');
        $input['data_inicio']=sprintf('%04d-%02d-01',$ano,$mes); $input['data_fim']=date('Y-m-t',strtotime($input['data_inicio']));
    }
    return $input;
}
function bh_reprocess_colaboradores(mysqli $conn,int $tenantId,array $filtro):array {
    $where='c.tenant_id=?'; $types='i'; $params=[$tenantId];
    if ($filtro['colaborador_id']!==null) { $where.=' AND c.id=?'; $types.='i'; $params[]=$filtro['colaborador_id']; }
    else {
        $where.=" AND c.ativo=1 AND c.departamento=? AND EXISTS (SELECT 1 FROM rh_escala e WHERE e.tenant_id=c.tenant_id AND e.colaborador_id=c.id AND e.ativo=1 AND e.banco_horas_ativo=1)";
        $types.='s'; $params[]=$filtro['departamento'];
    }
    $count=$conn->prepare("SELECT COUNT(*) total FROM rh_colaboradores c WHERE {$where}");
    if (!$count) throw new RuntimeException('Falha ao preparar contagem de colaboradores.');
    $countParams=$params; bh_reprocess_bind($count,$types,$countParams);
    if (!$count->execute()) { $count->close(); throw new RuntimeException('Falha ao contar colaboradores.'); }
    $total=(int)($count->get_result()->fetch_assoc()['total']??0); $count->close();
    $limitPlus=21; $offset=(int)$filtro['offset']; $selectParams=$params; $selectTypes=$types.'ii'; $selectParams[]=$limitPlus; $selectParams[]=$offset;
    $st=$conn->prepare("SELECT c.id,c.nome,c.departamento,c.ativo FROM rh_colaboradores c WHERE {$where} ORDER BY c.id ASC LIMIT ? OFFSET ?");
    if (!$st) throw new RuntimeException('Falha ao preparar seleção de colaboradores.');
    bh_reprocess_bind($st,$selectTypes,$selectParams);
    if (!$st->execute()) { $st->close(); throw new RuntimeException('Falha ao consultar colaboradores.'); }
    $items=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    $hasMore=count($items)>20; if ($hasMore) array_pop($items);
    return ['itens'=>$items,'total'=>$total,'offset'=>$offset,'limite'=>20,'offset_proximo'=>$hasMore?$offset+20:null];
}
function bh_reprocess_escala(mysqli $conn,int $tenantId,int $colaboradorId,bool $forUpdate=false):?array {
    if ($forUpdate) {
        $st=$conn->prepare('SELECT id FROM rh_escala WHERE tenant_id=? AND colaborador_id=? AND ativo=1 ORDER BY id ASC FOR UPDATE');
        if (!$st) throw new RuntimeException('Falha ao bloquear escala.');
        $st->bind_param('ii',$tenantId,$colaboradorId); if (!$st->execute()) { $st->close(); throw new RuntimeException('Falha ao bloquear escala.'); } $st->close();
    }
    return _buscar_escala($conn,$colaboradorId,$tenantId);
}
function bh_reprocess_periodos(mysqli $conn,int $tenantId,int $colaboradorId,string $inicio,string $fim,bool $forUpdate=false):array {
    $expr="STR_TO_DATE(CONCAT(p.ano,'-',LPAD(p.mes,2,'0'),'-01'),'%Y-%m-%d')";
    $sql="SELECT p.id,p.colaborador_id,p.mes,p.ano,p.status,{$expr} data_inicio,LAST_DAY({$expr}) data_fim FROM rh_ponto_periodo p WHERE p.tenant_id=? AND p.colaborador_id=? AND {$expr}<=? AND LAST_DAY({$expr})>=? ORDER BY p.ano,p.mes,p.id";
    if ($forUpdate) $sql.=' FOR UPDATE';
    $st=$conn->prepare($sql); if (!$st) throw new RuntimeException('Falha ao preparar consulta dos períodos.');
    $st->bind_param('iiss',$tenantId,$colaboradorId,$fim,$inicio);
    if (!$st->execute()) { $st->close(); throw new RuntimeException('Falha ao consultar períodos.'); }
    $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close(); return $rows;
}
function bh_reprocess_monday(string $date):string { $ts=strtotime($date); $dow=(int)date('w',$ts); return date('Y-m-d',$ts+(($dow===0?-6:1-$dow)*86400)); }
function bh_reprocess_dsr_semana(mysqli $conn,int $tenantId,int $colaboradorId,string $date,array $escala,array &$cache):array {
    $monday=bh_reprocess_monday($date); $key=$colaboradorId.':'.$monday; if(isset($cache[$key])) return $cache[$key];
    $sunday=date('Y-m-d',strtotime($monday.' +6 days'));
    $st=$conn->prepare('SELECT l.id,l.data,l.tipo_dia,l.abono_falta,p.status periodo_status,DAYOFWEEK(l.data) dow FROM rh_ponto_lancamento l INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id WHERE l.tenant_id=? AND l.colaborador_id=? AND l.data BETWEEN ? AND ? ORDER BY l.data');
    if(!$st) throw new RuntimeException('Falha ao preparar cálculo DSR.');
    $st->bind_param('iiss',$tenantId,$colaboradorId,$monday,$sunday); if(!$st->execute()){ $st->close();throw new RuntimeException('Falha ao consultar semana DSR.'); }
    $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $type=(string)($escala['tipo']??'');$work=['jornada_44h'=>['seg','ter','qua','qui','sex','sab'],'jornada_40h'=>['seg','ter','qua','qui','sex'],'jornada_36h'=>['seg','ter','qua','qui','sex','sab']][$type]??[];
    $map=[1=>'dom',2=>'seg',3=>'ter',4=>'qua',5=>'qui',6=>'sex',7=>'sab'];$sun=null;$falta=false;
    foreach($rows as $r){$day=$map[(int)$r['dow']]??'';if($day==='dom')$sun=$r;elseif(in_array($day,$work,true)&&$r['tipo_dia']==='falta'&&empty($r['abono_falta']))$falta=true;}
    $defaults=['jornada_44h'=>13200,'jornada_40h'=>12000,'jornada_36h'=>10800];$monthly=(int)($escala['carga_horaria_mensal_min']??0);
    return $cache[$key]=['segunda'=>$monday,'domingo'=>$sun,'falta_semana'=>$falta,'dsr_perdido_min'=>$falta?($monthly>0?(int)round($monthly/30):(int)round(($defaults[$type]??0)/30)):0];
}
function bh_reprocess_lancamento_data(mysqli $conn,int $tenantId,int $colaboradorId,string $data):?array {
    $st=$conn->prepare('SELECT l.*,p.status periodo_status FROM rh_ponto_lancamento l INNER JOIN rh_ponto_periodo p ON p.id=l.periodo_id AND p.tenant_id=l.tenant_id WHERE l.tenant_id=? AND l.colaborador_id=? AND l.data=? LIMIT 1');
    if(!$st)throw new RuntimeException('Falha ao preparar lookup diário.');$st->bind_param('iis',$tenantId,$colaboradorId,$data);
    if(!$st->execute()){$st->close();throw new RuntimeException('Falha ao consultar dia de ponto.');}$row=$st->get_result()->fetch_assoc()?:null;$st->close();return $row;
}
function bh_reprocess_mapear(mysqli $conn,int $tenantId,array $filtro,bool $forUpdate=false):array {
    if($filtro['colaborador_id']!==null&&!bh_colaborador_pertence_tenant($conn,$tenantId,(int)$filtro['colaborador_id'])){http_response_code(404);bh_responder(false,'Colaborador não encontrado.');}
    $selected=bh_reprocess_colaboradores($conn,$tenantId,$filtro);$daysOut=[];$employeesOut=[];$snapshot=[];$ignored=[];$dayFilter=[];
    for($ts=strtotime($filtro['data_inicio']);$ts<=strtotime($filtro['data_fim']);$ts+=86400)$dayFilter[date('Y-m-d',$ts)]=true;
    $sum=['credit_current'=>0,'debit_current'=>0,'excess_current'=>0,'credit_expected'=>0,'debit_expected'=>0,'excess_expected'=>0,'extra_gross'=>0,'extra_abono'=>0,'worked'=>0,'delay'=>0,'planned'=>0,'balance_current'=>0,'balance_after'=>0,'divergent'=>0,'processable'=>0];
    foreach($selected['itens'] as $c){
        $cid=(int)$c['id'];$scale=bh_reprocess_escala($conn,$tenantId,$cid,$forUpdate);$periods=bh_reprocess_periodos($conn,$tenantId,$cid,$filtro['data_inicio'],$filtro['data_fim'],$forUpdate);
        if(!$periods)$ignored[]=['colaborador_id'=>$cid,'status'=>'SEM_PERIODO'];
        $days=[];$periodRows=[];
        foreach($periods as $period){$calc=bh_calcular_dias_periodo($conn,$tenantId,(int)$period['id'],$scale,$forUpdate);$periodRows[]=['id'=>(int)$period['id'],'status'=>$period['status'],'mes'=>(int)$period['mes'],'ano'=>(int)$period['ano']];foreach($calc['itens'] as $item){$date=(string)$item['row']['data'];if(!isset($days[$date]))$days[$date]=['row'=>$item['row'],'calc'=>$item['calc'],'marcador'=>!empty($item['marcador']),'pareado_data'=>$item['pareado_data']??null,'pareado_de'=>$item['pareado_de']??null];}}
        $weeks=[];foreach(array_keys($dayFilter) as $date)$weeks[date('o-W',strtotime($date))]=bh_reprocess_monday($date);$dsr=[];$datesAffected=$dayFilter;
        if($scale&&!empty($scale['banco_horas_ativo'])&&in_array(($scale['tipo']??''),['jornada_44h','jornada_40h','jornada_36h'],true))foreach($weeks as $monday){$week=bh_reprocess_dsr_semana($conn,$tenantId,$cid,$monday,$scale,$dsr);if($week['domingo']){$sd=(string)$week['domingo']['data'];$dsr[$sd]=$week;if(!isset($days[$sd])){$sr=bh_reprocess_lancamento_data($conn,$tenantId,$cid,$sd);if($sr)$days[$sd]=['row'=>$sr,'calc'=>['trabalhadas'=>0,'extras'=>0,'atraso'=>0,'saida_antecipada'=>0],'marcador'=>false];}$datesAffected[$sd]=true;}}
        $lookup=array_keys($datesAffected);$from=min(array_merge([$filtro['data_inicio']],$lookup));$to=max(array_merge([$filtro['data_fim']],$lookup));$ledger=[];
        $stL=$conn->prepare("SELECT lancamento_id,tipo,SUM(minutos) minutos FROM rh_banco_horas WHERE tenant_id=? AND colaborador_id=? AND lancamento_id IS NOT NULL AND data BETWEEN ? AND ? AND tipo IN ('credito','debito','excedente') GROUP BY lancamento_id,tipo");
        if(!$stL)throw new RuntimeException('Falha ao preparar leitura do ledger.');$stL->bind_param('iiss',$tenantId,$cid,$from,$to);if(!$stL->execute()){$stL->close();throw new RuntimeException('Falha ao consultar ledger.');}foreach($stL->get_result()->fetch_all(MYSQLI_ASSOC) as $lr)$ledger[(int)$lr['lancamento_id']][$lr['tipo']]=(int)$lr['minutos'];$stL->close();
        $balance=bh_obter_saldo($conn,$tenantId,$cid);$sum['balance_current']+=$balance['saldo'];$employeeAfter=$balance['saldo'];$employeeSnapshot=[];
        ksort($days);
        foreach($days as $date=>$entry){$in=isset($dayFilter[$date]);$boundary=(!empty($entry['pareado_data'])&&isset($dayFilter[$entry['pareado_data']]))||(!empty($entry['pareado_de'])&&isset($dayFilter[$entry['pareado_de']]));$week=$dsr[$date]??null;if(!$in&&!$boundary&&!$week)continue;
            $r=$entry['row'];$calc=$entry['calc'];$lid=(int)$r['id'];$actual=$ledger[$lid]??[];$credNow=(int)($actual['credito']??0);$debitNow=(int)($actual['debito']??0);$excessNow=(int)($actual['excedente']??0);
            $abono=['tipo_dia'=>(string)($r['tipo_dia']??''),'abono_extras'=>(string)($r['abono_extras']??'nenhum'),'abono_extras_min'=>(int)($r['abono_extras_min']??0),'abono_atraso'=>(string)($r['abono_atraso']??'nenhum'),'abono_atraso_min'=>(int)($r['abono_atraso_min']??0),'abono_falta'=>(int)($r['abono_falta']??0)];
            if($week&&$date===(string)$week['domingo']['data']){$abono['tipo_dia']=$week['falta_semana']?'falta':'folga';$abono['abono_falta']=1;$abono['dsr_perdido_min']=(int)$week['dsr_perdido_min'];$calc=['trabalhadas'=>0,'extras'=>0,'atraso'=>0,'saida_antecipada'=>0];}
            $ap=bh_apurar_dia($calc,$abono,$scale??[],$date);$cred=(int)$ap['credito'];$debit=(int)$ap['debito_total'];$excess=(int)$ap['excedente'];$periodStatus=(string)($r['periodo_status']??'');$hasScale=$scale&&!empty($scale['banco_horas_ativo']);$applyDay=$hasScale&&($periodStatus==='aberto'||$filtro['incluir_fechados']);
            if(!$hasScale)$status='SEM_ESCALA_BH';elseif($periodStatus!=='aberto'&&!$filtro['incluir_fechados'])$status='PERIODO_FECHADO';elseif($cred===$credNow&&$debit===$debitNow&&$excess===$excessNow)$status='OK';elseif($credNow===0&&$debitNow===0&&$excessNow===0&&($cred+$debit+$excess)>0)$status='SEM_BANCO';else$status='DIVERGENTE';
            $diff=($cred-$debit)-($credNow-$debitNow);$params=function_exists('_obter_parametros_jornada_dia')?_obter_parametros_jornada_dia($scale,$date):['carga_min'=>0];
            $day=['colaborador_id'=>$cid,'colaborador_nome'=>(string)$c['nome'],'departamento'=>(string)($c['departamento']??''),'lancamento_id'=>$lid,'periodo_id'=>(int)($r['periodo_id']??0),'periodo_status'=>$periodStatus,'data'=>$date,'dia_semana'=>(int)date('N',strtotime($date)),'tipo_dia'=>(string)($r['tipo_dia']??''),'batidas'=>['entrada'=>$r['hora_entrada']??null,'almoco_saida'=>$r['hora_almoco_saida']??null,'almoco_retorno'=>$r['hora_almoco_retorno']??null,'saida'=>$r['hora_saida']??null],'carga_prevista'=>(int)($params['carga_min']??0),'trabalhado'=>(int)($calc['trabalhadas']??0),'extra_espelho'=>(int)($calc['extras']??0),'atraso_espelho'=>(int)($calc['atraso']??0),'abonos'=>['extras'=>$abono['abono_extras'],'extras_min'=>$abono['abono_extras_min'],'atraso'=>$abono['abono_atraso'],'atraso_min'=>$abono['abono_atraso_min'],'falta'=>(bool)$abono['abono_falta']],'credito_esperado'=>$cred,'debito_esperado'=>$debit,'excedente'=>$excess,'credito_atual'=>$credNow,'debito_atual'=>$debitNow,'excedente_atual'=>$excessNow,'diferenca'=>$diff,'diferenca_fmt'=>bh_fmt($diff),'status'=>$status,'regra_aplicada'=>$ap['regra_aplicada'],'limite_dia'=>$ap['limite_dia'],'alertas'=>$ap['alertas'],'impacto_dsr_fora_periodo'=>(!$in&&$week!==null)];
            $daysOut[]=$day;$sum['credit_current']+=$credNow;$sum['debit_current']+=$debitNow;$sum['excess_current']+=$excessNow;$sum['credit_expected']+=$applyDay?$cred:$credNow;$sum['debit_expected']+=$applyDay?$debit:$debitNow;$sum['excess_expected']+=$applyDay?$excess:$excessNow;$sum['extra_gross']+=(int)$ap['extra_bruto'];$sum['extra_abono']+=(int)$ap['extra_abonado'];if($in){$sum['worked']+=(int)($calc['trabalhadas']??0);$sum['delay']+=(int)($calc['atraso']??0);$sum['planned']+=(int)($params['carga_min']??0);}if(in_array($status,['DIVERGENTE','SEM_BANCO'],true))$sum['divergent']++;if(in_array($status,['PERIODO_FECHADO','SEM_ESCALA_BH'],true))$ignored[]=['colaborador_id'=>$cid,'data'=>$date,'status'=>$status];else$sum['processable']++;
            if($applyDay)$employeeAfter+=($cred-$debit)-($credNow-$debitNow);
            $employeeSnapshot[]=['id'=>$lid,'data'=>$date,'tipo_dia'=>$r['tipo_dia']??null,'he'=>$r['hora_entrada']??null,'has'=>$r['hora_almoco_saida']??null,'har'=>$r['hora_almoco_retorno']??null,'hs'=>$r['hora_saida']??null,'trabalhadas'=>$r['horas_trabalhadas_min']??null,'extras'=>$r['horas_extras_min']??null,'atraso'=>$r['atraso_min']??null,'saida'=>$r['saida_antecipada_min']??null,'abono_extras'=>$r['abono_extras']??null,'abono_extras_min'=>$r['abono_extras_min']??null,'abono_atraso'=>$r['abono_atraso']??null,'abono_atraso_min'=>$r['abono_atraso_min']??null,'abono_falta'=>$r['abono_falta']??null,'periodo_status'=>$periodStatus,'ledger'=>$actual];
        }
        $sum['balance_after']+=$employeeAfter;$snapshot[]=['colaborador_id'=>$cid,'nome'=>$c['nome'],'departamento'=>$c['departamento']??'','escala'=>$scale,'periodos'=>$periodRows,'saldo_global_atual'=>$balance['saldo'],'saldo_global_apos_reprocessar'=>$employeeAfter,'dias'=>$employeeSnapshot];$employeesOut[]=['colaborador_id'=>$cid,'nome'=>$c['nome'],'departamento'=>$c['departamento']??'','saldo_atual'=>$balance['saldo'],'saldo_apos_reprocessar'=>$employeeAfter];
    }
    usort($daysOut,static function($a,$b){$c=strcmp($a['data'],$b['data']);return $c?:($a['colaborador_id']<=>$b['colaborador_id']);});
    $hashInput=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));$hashPreview=hash('sha256',json_encode(['tenant_id'=>$tenantId,'filtro'=>$filtro,'snapshot_hash'=>$hashInput,'days'=>array_map(static fn($d)=>[$d['colaborador_id'],$d['data'],$d['credito_atual'],$d['debito_atual'],$d['credito_esperado'],$d['debito_esperado'],$d['status']],$daysOut)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
    $totals=['carga_prevista'=>$sum['planned'],'trabalhado_espelho'=>$sum['worked'],'extra_espelho'=>$sum['extra_gross'],'extra_abonado'=>$sum['extra_abono'],'atraso_espelho'=>$sum['delay'],'credito_atual'=>$sum['credit_current'],'debito_atual'=>$sum['debit_current'],'excedente_atual'=>$sum['excess_current'],'credito_esperado'=>$sum['credit_expected'],'debito_esperado'=>$sum['debit_expected'],'excedente_esperado'=>$sum['excess_expected'],'saldo_atual'=>$sum['balance_current'],'saldo_apos_reprocessar'=>$sum['balance_after'],'dias_divergentes'=>$sum['divergent'],'dias_processaveis'=>$sum['processable']];
    return ['filtro'=>$filtro,'colaboradores'=>$employeesOut,'lancamentos'=>$daysOut,'totais'=>$totals,'ignorados'=>$ignored,'hash_entrada'=>$hashInput,'hash_previa'=>$hashPreview,'lote'=>['offset'=>$selected['offset'],'limite'=>$selected['limite'],'total_colaboradores'=>$selected['total'],'offset_proximo'=>$selected['offset_proximo']]];
}
function bh_reprocess_historia_ensure(mysqli $conn):void{$r=$conn->query("SHOW TABLES LIKE 'rh_banco_horas_reprocessamentos'");if(!$r||$r->num_rows===0){if($r)$r->free();throw new RuntimeException('Migration de mapeamento/reprocessamento pendente.');}$r->free();}
function bh_reprocess_user_id():int{return (int)($_SESSION['usuario_id']??0);}
function bh_reprocess_administrador(mysqli $conn,int $tenantId):bool{$uid=bh_reprocess_user_id();if($uid<=0)return false;if(function_exists('rbacUsuarioAlvoTemAcessoTotal')&&function_exists('rbacTabelasDisponiveis')&&rbacTabelasDisponiveis($conn))return rbacUsuarioAlvoTemAcessoTotal($conn,$uid,$tenantId);return in_array(strtolower((string)($_SESSION['usuario_permissao']??$_SESSION['permissao']??$_SESSION['nivel']??'')),['admin','super_admin'],true);}
function bh_reprocess_exigir_permissao(mysqli $conn,string $action):void{if(function_exists('rbacTabelasDisponiveis')&&rbacTabelasDisponiveis($conn)){rbacExigir($conn,'recursos_humanos',$action,['submodulo_chave'=>'banco_horas']);return;}verificarPermissao($action==='visualizar'?'visualizador':'admin');}
function bh_reprocess_totais_previa(array $p):array{return ['credito_atual'=>(int)($p['totais']['credito_atual']??0),'debito_atual'=>(int)($p['totais']['debito_atual']??0),'excedente_atual'=>(int)($p['totais']['excedente_atual']??0),'saldo_atual'=>(int)($p['totais']['saldo_atual']??0)];}
function bh_reprocess_criar_previa(mysqli $conn,int $tenantId,array $filter,array $preview):int{
    bh_reprocess_historia_ensure($conn);$uid=bh_reprocess_user_id();$nome=trim((string)($_SESSION['usuario_nome']??$_SESSION['nome']??'Usuário'));if(function_exists('mb_substr'))$nome=mb_substr($nome,0,160,'UTF-8');elseif(preg_match('/^.{0,160}/us',$nome,$match))$nome=$match[0];$scope=$filter['colaborador_id']!==null?'COLABORADOR':'DEPARTAMENTO';$cid=$filter['colaborador_id'];$dept=$cid===null?$filter['departamento']:null;$rules=[];
    foreach($preview['colaboradores'] as $c){$s=bh_reprocess_escala($conn,$tenantId,(int)$c['colaborador_id']);$rules[(string)($s['politica_extra_excedente']??'CREDITAR_TUDO')]=true;}
    $rule=count($rules)>1?'MISTA':(string)(array_key_first($rules)?:'CREDITAR_TUDO');
    $version='2026.11';$hashP=$preview['hash_previa'];$hashI=$preview['hash_entrada'];$reason='Prévia pendente';$totals=json_encode(bh_reprocess_totais_previa($preview),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);$ignored=json_encode($preview['ignorados'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);$includeClosed=!empty($filter['incluir_fechados'])?1:0;$ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $st=$conn->prepare("INSERT INTO rh_banco_horas_reprocessamentos(tenant_id,usuario_id,usuario_nome,escopo,colaborador_id,departamento_id,data_inicio,data_fim,regra,versao_calculo,hash_previa,hash_entrada,motivo,totais_antes_json,ignorados_json,incluir_fechados,status,ip) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'PREVIA',?)");if(!$st)throw new RuntimeException('Falha ao preparar auditoria da prévia.');$st->bind_param('iississssssssssis',$tenantId,$uid,$nome,$scope,$cid,$dept,$filter['data_inicio'],$filter['data_fim'],$rule,$version,$hashP,$hashI,$reason,$totals,$ignored,$includeClosed,$ip);if(!$st->execute()){$st->close();throw new RuntimeException('Falha ao registrar prévia.');}$id=(int)$conn->insert_id;$st->close();return $id;
}
function bh_reprocess_simular(mysqli $conn,int $tenantId,array $input):array{bh_reprocess_exigir_permissao($conn,'editar');$filter=bh_reprocess_validar_periodo(bh_reprocess_normalizar_periodo_input($input));if($filter['incluir_fechados']&&!bh_reprocess_administrador($conn,$tenantId)){http_response_code(403);bh_responder(false,'Somente administrador pode incluir períodos fechados.');}$preview=bh_reprocess_mapear($conn,$tenantId,$filter,false);$preview['previsualizacao_id']=bh_reprocess_criar_previa($conn,$tenantId,$filter,$preview);return $preview;}
function bh_reprocess_aplicar(mysqli $conn,int $tenantId,array $input):array{
    bh_reprocess_exigir_permissao($conn,'editar');$filter=bh_reprocess_validar_periodo(bh_reprocess_normalizar_periodo_input($input));if($filter['incluir_fechados']&&!bh_reprocess_administrador($conn,$tenantId)){http_response_code(403);bh_responder(false,'Somente administrador pode incluir períodos fechados.');}
    if(function_exists('_ponto_garantir_colunas'))_ponto_garantir_colunas($conn);
    if(function_exists('_abono_garantir_colunas'))_abono_garantir_colunas($conn);
    if(!isset($input['revisei_diferencas'])||!is_scalar($input['revisei_diferencas'])||!in_array($input['revisei_diferencas'],[true,1,'1','true'],true))throw new InvalidArgumentException('Confirme a revisão das diferenças.');$rawReason=$input['motivo']??'';if(!is_string($rawReason))throw new InvalidArgumentException('O motivo deve ser texto.');$reason=trim($rawReason);if(bh_tamanho_texto($reason)<10||bh_tamanho_texto($reason)>500)throw new InvalidArgumentException('O motivo deve ter de 10 a 500 caracteres.');
    $previewId=filter_var($input['previsualizacao_id']??null,FILTER_VALIDATE_INT);if(!$previewId||$previewId<=0)throw new InvalidArgumentException('Prévia inválida; gere outra.');$uid=bh_reprocess_user_id();
    $st=$conn->prepare("SELECT * FROM rh_banco_horas_reprocessamentos WHERE tenant_id=? AND id=? AND usuario_id=? AND status='PREVIA' LIMIT 1");if(!$st)throw new RuntimeException('Falha ao consultar prévia.');$st->bind_param('iii',$tenantId,$previewId,$uid);if(!$st->execute()){$st->close();throw new RuntimeException('Falha ao consultar prévia.');}$previewRow=$st->get_result()->fetch_assoc();$st->close();
    $hashPreviewInput=$input['hash_previa']??null;$hashInputInput=$input['hash_entrada']??null;if(!is_string($hashPreviewInput)||!is_string($hashInputInput))throw new InvalidArgumentException('Hashes da prévia inválidos.');
    if(!$previewRow){$done=$conn->prepare("SELECT status,data_inicio,data_fim,hash_previa,hash_entrada,totais_antes_json,totais_depois_json,dias_alterados FROM rh_banco_horas_reprocessamentos WHERE tenant_id=? AND id=? AND usuario_id=? LIMIT 1");if($done){$done->bind_param('iii',$tenantId,$previewId,$uid);$done->execute();$r=$done->get_result()->fetch_assoc();$done->close();if($r&&$r['status']==='CONCLUIDO'&&$r['data_inicio']===$filter['data_inicio']&&$r['data_fim']===$filter['data_fim']&&hash_equals((string)$r['hash_previa'],$hashPreviewInput)&&hash_equals((string)$r['hash_entrada'],$hashInputInput))return ['idempotente'=>true,'reprocessamento_id'=>$previewId,'dias_alterados'=>(int)$r['dias_alterados'],'totais'=>json_decode((string)$r['totais_depois_json'],true)?:[],'totais_antes'=>json_decode((string)$r['totais_antes_json'],true)?:[]];}throw new InvalidArgumentException('Prévia expirada ou aplicada; gere outra simulação.');}
    if($previewRow['data_inicio']!==$filter['data_inicio']||$previewRow['data_fim']!==$filter['data_fim']||!hash_equals((string)$previewRow['hash_previa'],$hashPreviewInput)||!hash_equals((string)$previewRow['hash_entrada'],$hashInputInput)){http_response_code(409);bh_responder(false,'A requisição não corresponde à prévia. Gere nova simulação.',null,'PREVIA_INVALIDA');}
    $selected=bh_reprocess_colaboradores($conn,$tenantId,$filter);$ids=array_map(static fn($c)=>(int)$c['id'],$selected['itens']);sort($ids,SORT_NUMERIC);$locks=[];$inTx=false;
    try{
        foreach($ids as $id){if(!bh_obter_lock_colaborador($conn,$tenantId,$id,10))throw new RuntimeException('Outro lançamento está sendo processado neste lote.');$locks[]=$id;}
        if(!$conn->begin_transaction())throw new RuntimeException('Não foi possível iniciar transação.');$inTx=true;
        $mark=$conn->prepare("UPDATE rh_banco_horas_reprocessamentos SET status='PROCESSANDO',motivo=? WHERE tenant_id=? AND id=? AND usuario_id=? AND status='PREVIA'");if(!$mark)throw new RuntimeException('Falha ao iniciar auditoria.');$mark->bind_param('siii',$reason,$tenantId,$previewId,$uid);$marked=$mark->execute();$affected=$mark->affected_rows;$mark->close();if(!$marked)throw new RuntimeException('Falha ao iniciar auditoria.');if($affected!==1){$conn->rollback();$inTx=false;$done=$conn->prepare("SELECT status,data_inicio,data_fim,hash_previa,hash_entrada,totais_antes_json,totais_depois_json,dias_alterados FROM rh_banco_horas_reprocessamentos WHERE tenant_id=? AND id=? AND usuario_id=? LIMIT 1");if($done){$done->bind_param('iii',$tenantId,$previewId,$uid);$done->execute();$r=$done->get_result()->fetch_assoc();$done->close();if($r&&$r['status']==='CONCLUIDO'&&$r['data_inicio']===$filter['data_inicio']&&$r['data_fim']===$filter['data_fim']&&hash_equals((string)$r['hash_previa'],$hashPreviewInput)&&hash_equals((string)$r['hash_entrada'],$hashInputInput))return ['idempotente'=>true,'reprocessamento_id'=>$previewId,'dias_alterados'=>(int)$r['dias_alterados'],'totais'=>json_decode((string)$r['totais_depois_json'],true)?:[],'totais_antes'=>json_decode((string)$r['totais_antes_json'],true)?:[]];}throw new InvalidArgumentException('Prévia já utilizada; gere uma nova simulação.');}
        $current=bh_reprocess_mapear($conn,$tenantId,$filter,true);if(!hash_equals($previewRow['hash_entrada'],$current['hash_entrada'])||!hash_equals($previewRow['hash_previa'],$current['hash_previa'])){$conn->rollback();$inTx=false;http_response_code(409);bh_responder(false,'Espelho, escala, abono ou ledger mudou desde a prévia. Gere nova simulação.',['previsualizacao_id'=>$previewId],'ESPELHO_ALTERADO');}
        $before=bh_reprocess_totais_previa($current);$ignored=$current['ignorados'];$weekDates=[];$daysToChange=[];
        foreach($current['lancamentos'] as $mappedDay){if(in_array($mappedDay['status'],['DIVERGENTE','SEM_BANCO'],true))$daysToChange[(int)$mappedDay['colaborador_id'].':'.(string)$mappedDay['data']]=true;}
        foreach($selected['itens'] as $c){$cid=(int)$c['id'];$scale=bh_reprocess_escala($conn,$tenantId,$cid,true);$periods=bh_reprocess_periodos($conn,$tenantId,$cid,$filter['data_inicio'],$filter['data_fim'],true);if(!$periods){$ignored[]=['colaborador_id'=>$cid,'status'=>'SEM_PERIODO'];continue;}if(!$scale||empty($scale['banco_horas_ativo'])){$ignored[]=['colaborador_id'=>$cid,'status'=>'SEM_ESCALA_BH'];continue;}
            foreach($periods as $period){if($period['status']!=='aberto'&&!$filter['incluir_fechados']){$ignored[]=['colaborador_id'=>$cid,'periodo_id'=>(int)$period['id'],'status'=>'PERIODO_FECHADO'];continue;}$calc=bh_calcular_dias_periodo($conn,$tenantId,(int)$period['id'],$scale,true);bh_processar_periodo($conn,$tenantId,(int)$period['id'],$scale,['colaborador_id'=>$cid,'incluir_fechados'=>$filter['incluir_fechados'],'data_inicio'=>$filter['data_inicio'],'data_fim'=>$filter['data_fim'],'sincronizar_dsr'=>false,'lock_ja_adquirido'=>true,'transacao_ja_ativa'=>true,'reprocessamento_id'=>$previewId]);foreach($calc['itens'] as $item){$date=(string)$item['row']['data'];$in=$date>=$filter['data_inicio']&&$date<=$filter['data_fim'];$pair=(!empty($item['pareado_data'])&&$item['pareado_data']>=$filter['data_inicio']&&$item['pareado_data']<=$filter['data_fim'])||(!empty($item['pareado_de'])&&$item['pareado_de']>=$filter['data_inicio']&&$item['pareado_de']<=$filter['data_fim']);if($in||$pair)$weekDates[$cid][date('o-W',strtotime($date))]=$date;}}
        }
        foreach($weekDates as $cid=>$weeks){$scale=bh_reprocess_escala($conn,$tenantId,(int)$cid,true);foreach($weeks as $date){_bh_dsr_sincronizar($conn,(int)$cid,$date,$scale,$tenantId,['lock_ja_adquirido'=>true,'transacao_ja_ativa'=>true,'reprocessamento_id'=>$previewId,'incluir_fechados'=>$filter['incluir_fechados']]);$monday=bh_reprocess_monday($date);$sunday=date('Y-m-d',strtotime($monday.' +6 days'));$dsrPeriod=$conn->prepare('SELECT id,status FROM rh_ponto_periodo WHERE tenant_id=? AND colaborador_id=? AND ? BETWEEN STR_TO_DATE(CONCAT(ano,\'-\',LPAD(mes,2,\'0\'),\'-01\'),\'%Y-%m-%d\') AND LAST_DAY(STR_TO_DATE(CONCAT(ano,\'-\',LPAD(mes,2,\'0\'),\'-01\'),\'%Y-%m-%d\')) LIMIT 1');if(!$dsrPeriod)throw new RuntimeException('Falha ao localizar período de DSR.');$dsrPeriod->bind_param('iis',$tenantId,$cid,$sunday);if(!$dsrPeriod->execute()){$dsrPeriod->close();throw new RuntimeException('Falha ao localizar período de DSR.');}$dsrRow=$dsrPeriod->get_result()->fetch_assoc();$dsrPeriod->close();if($dsrRow&&($dsrRow['status']==='aberto'||$filter['incluir_fechados']))_recalcular_totais($conn,(int)$dsrRow['id'],$tenantId);}}
        foreach($ids as $cid){foreach(bh_reprocess_periodos($conn,$tenantId,$cid,$filter['data_inicio'],$filter['data_fim']) as $period){if($period['status']==='aberto'||$filter['incluir_fechados'])_recalcular_totais($conn,(int)$period['id'],$tenantId);}}
        $afterMap=bh_reprocess_mapear($conn,$tenantId,$filter,true);$after=['credito_atual'=>(int)$afterMap['totais']['credito_atual'],'debito_atual'=>(int)$afterMap['totais']['debito_atual'],'excedente_atual'=>(int)$afterMap['totais']['excedente_atual'],'saldo_atual'=>(int)$afterMap['totais']['saldo_atual'],'saldo_apos_reprocessar'=>(int)$afterMap['totais']['saldo_apos_reprocessar']];$beforeJson=json_encode($before,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);$afterJson=json_encode($after,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);$ignoredJson=json_encode(array_merge($ignored,$afterMap['ignorados']),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);$changed=count($daysToChange);
        $complete=$conn->prepare("UPDATE rh_banco_horas_reprocessamentos SET status='CONCLUIDO',totais_antes_json=?,totais_depois_json=?,dias_alterados=?,ignorados_json=?,concluido_em=NOW() WHERE tenant_id=? AND id=? AND usuario_id=?");if(!$complete)throw new RuntimeException('Falha ao concluir histórico.');$complete->bind_param('ssisiii',$beforeJson,$afterJson,$changed,$ignoredJson,$tenantId,$previewId,$uid);if(!$complete->execute()){$complete->close();throw new RuntimeException('Falha ao gravar totais finais.');}$complete->close();if(!$conn->commit())throw new RuntimeException('Falha ao confirmar reprocessamento.');$inTx=false;
        return ['idempotente'=>false,'reprocessamento_id'=>$previewId,'dias_alterados'=>$changed,'totais_antes'=>$before,'totais_depois'=>$after,'ignorados'=>array_merge($ignored,$afterMap['ignorados']),'hash_previa'=>$previewRow['hash_previa'],'lote'=>['offset'=>$selected['offset'],'limite'=>$selected['limite'],'total_colaboradores'=>$selected['total'],'processados'=>count($selected['itens']),'offset_proximo'=>$selected['offset_proximo']]];
    }catch(Throwable $e){if($inTx)$conn->rollback();throw $e;}finally{foreach(array_reverse($locks) as $id)bh_liberar_lock_colaborador($conn,$tenantId,$id);}
}
