<?php
/** Controlador interno da Central RBAC. Deve ser usado após autenticação. */

function rbacMapaAcoesLegadas() {
    return [
        'pode_acessar' => 'visualizar',
        'pode_criar' => 'criar',
        'pode_editar' => 'editar',
        'pode_excluir' => 'excluir',
        'pode_exportar' => 'exportar'
    ];
}

function rbacCatalogoApi($conexao) {
    $res = $conexao->query("SELECT m.id,m.chave,m.nome,m.modulo_pai_id,m.pagina,m.grupo,m.tipo,m.icone,m.descricao,m.ordem,m.ativo,p.acao,p.nome AS permissao_nome FROM rbac_modulos m LEFT JOIN rbac_permissoes p ON p.modulo_id=m.id AND p.ativo=1 WHERE m.ativo=1 ORDER BY COALESCE(m.modulo_pai_id,0),m.ordem,m.nome,p.acao");
    $catalogo = [];
    while ($linha = $res->fetch_assoc()) {
        $chave = $linha['chave'];
        if (!isset($catalogo[$chave])) {
            $catalogo[$chave] = [
                'id'=>(int)$linha['id'], 'chave'=>$chave, 'nome'=>$linha['nome'],
                'modulo_pai_id'=>$linha['modulo_pai_id'] !== null ? (int)$linha['modulo_pai_id'] : null,
                'pagina'=>$linha['pagina'], 'grupo'=>$linha['grupo'], 'tipo'=>$linha['tipo'],
                'icone'=>$linha['icone'], 'descricao'=>$linha['descricao'], 'ordem'=>(int)$linha['ordem'],
                'acoes'=>[]
            ];
        }
        if ($linha['acao']) $catalogo[$chave]['acoes'][] = $linha['acao'];
    }
    return $catalogo;
}

function rbacFormatoPermissoesApi($conexao, $usuarioId, $tenantId, $forcar = false) {
    $efetivas = rbacObterPermissoesEfetivas($conexao, $usuarioId, $tenantId, $forcar);
    $catalogo = rbacCatalogoApi($conexao);
    $modulos = [];
    foreach ($catalogo as $chave => $modulo) {
        $saida = ['pode_acessar'=>false,'pode_criar'=>false,'pode_editar'=>false,'pode_excluir'=>false,'pode_exportar'=>false,'pode_imprimir'=>false,'pode_importar'=>false,'pode_aprovar'=>false,'pode_executar'=>false,'pode_configurar'=>false,'pode_bloquear'=>false,'pode_desbloquear'=>false,'escopos'=>[],'origens'=>[],'efetivas'=>[],'origens_efetivas'=>[]];
        foreach ($modulo['acoes'] as $acao) {
            $registro = $efetivas['permitidas'][$chave . '.' . $acao] ?? null;
            $permitido = $efetivas['is_admin_total'] ? true : (!empty($registro['permitido']));
            $mapa = ['visualizar'=>'acessar','criar'=>'criar','editar'=>'editar','excluir'=>'excluir','exportar'=>'exportar','imprimir'=>'imprimir','importar'=>'importar','aprovar'=>'aprovar','executar'=>'executar','configurar'=>'configurar','bloquear'=>'bloquear','desbloquear'=>'desbloquear'];
            $campo = 'pode_' . ($mapa[$acao] ?? $acao);
            $saida[$campo] = $permitido;
            $saida['efetivas'][$acao] = $efetivas['is_admin_total'] ? 'ADMIN_TOTAL' : ($registro ? ($registro['efeito_efetivo'] ?? ($permitido ? 'PERMITIR' : 'NEGAR')) : 'SEM_ACESSO');
            $saida['origens_efetivas'][$acao] = $efetivas['is_admin_total'] ? ['admin'] : array_values(array_unique(array_column($registro['origens_efetivas'] ?? [], 'origem')));
            $saida['estados_individuais'][$acao] = $efetivas['is_admin_total'] ? 'ADMIN_TOTAL' : ($registro['estado_individual'] ?? 'HERDAR');
            $saida['herdadas'][$acao] = $efetivas['is_admin_total'] ? 'ADMIN_TOTAL' : ($registro['herdado'] ?? 'SEM_ACESSO');
            $saida['origens_herdadas'][$acao] = array_values(array_unique(array_column($registro['origens_herdadas'] ?? [], 'origem')));
            if ($registro) {
                $saida['escopos'][$acao] = $registro['escopo'];
                $saida['origens'][$acao] = $registro['origens'];
            }
        }
        $saida['modulo'] = $modulo;
        $modulos[$chave] = $saida;
    }
    return ['is_admin' => $efetivas['is_admin_total'], 'permissao'=>rbacUsuarioPerfilBanco($conexao,$usuarioId,$tenantId), 'modo_compatibilidade'=>$efetivas['modo_compatibilidade'], 'revisao'=>$efetivas['revisao'] ?? 0, 'grupos'=>$efetivas['grupos'] ?? [], 'modulos'=>$modulos];
}

function rbacUsuarioMesmoTenant($conexao, $usuarioId, $tenantId) {
    $st = $conexao->prepare("SELECT id,nome,email,permissao,ativo FROM usuarios WHERE id=? AND tenant_id=? AND LOWER(COALESCE(permissao, '')) <> 'super_admin' LIMIT 1");
    if (!$st) return null;
    $st->bind_param('ii',$usuarioId,$tenantId);
    $st->execute();
    $usuario = $st->get_result()->fetch_assoc();
    $st->close();
    return $usuario ?: null;
}

function rbacPodeConceder($conexao, $modulo, $acao) {
    return rbacPode($conexao, $modulo, $acao);
}

function rbacSalvarExcecoesLegadas($conexao, $tenantId, $alvoId, array $permissoes) {
    $ator = (int)($_SESSION['usuario_id'] ?? 0);
    if ($alvoId <= 0 || $tenantId <= 0) return ['ok'=>false,'mensagem'=>'Usuário ou condomínio inválido.'];
    if ($alvoId === $ator) return ['ok'=>false,'mensagem'=>'Não é permitido alterar as próprias permissões.'];
    $alvo = rbacUsuarioMesmoTenant($conexao, $alvoId, $tenantId);
    if (!$alvo) return ['ok'=>false,'mensagem'=>'Usuário não encontrado no condomínio atual.'];
    if (strtolower((string)$alvo['permissao']) === 'super_admin') return ['ok'=>false,'mensagem'=>'Permissões de Super Admin não podem ser alteradas nesta tela.'];
    if (strtolower((string)$alvo['permissao']) === 'admin') return ['ok'=>false,'mensagem'=>'Usuário com perfil administrador possui acesso total; altere o perfil autorizado em vez de criar exceções granulares.'];

    $solicitadas = [];
    $mapaAcoes = rbacMapaAcoesLegadas();
    foreach ($permissoes as $chave => $linha) {
        if (!is_string($chave) || !is_array($linha) || strlen($chave) > 80) return ['ok'=>false,'mensagem'=>'Matriz de permissões inválida.'];
        $estados = $linha['estados'] ?? null;
        if (!is_array($estados)) {
            $estados = [];
            foreach ($mapaAcoes as $campo => $acao) {
                if (array_key_exists($campo, $linha)) $estados[$acao] = !empty($linha[$campo]) ? 'PERMITIR' : 'NEGAR';
            }
        }
        foreach ($estados as $acao => $estado) {
            if (!is_string($acao) || !in_array($estado, ['HERDAR','PERMITIR','NEGAR'], true)) return ['ok'=>false,'mensagem'=>'Estado de permissão inválido.'];
            $solicitadas[$chave][$acao] = $estado;
        }
    }
    if (!$solicitadas) return ['ok'=>false,'mensagem'=>'Nenhuma alteração de permissão foi informada.'];

    $efetivasAtorAntes = rbacObterPermissoesEfetivas($conexao, $ator, $tenantId, true);
    $efetivasAntes = rbacObterPermissoesEfetivas($conexao, $alvoId, $tenantId, true);
    foreach ($solicitadas as $chave => $estados) {
        foreach ($estados as $acao => $estado) {
            $atual=$efetivasAntes['permitidas'][$chave . '.' . $acao]['estado_individual'] ?? 'HERDAR';
            if ($estado === 'PERMITIR' && $estado !== $atual && !rbacPodeConceder($conexao, $chave, $acao)) {
                return ['ok'=>false,'mensagem'=>'Você não pode conceder a permissão ' . $chave . '.' . $acao . '.'];
            }
        }
    }

    $antes = [];
    $depois = [];
    $processadas = 0;
    $conexao->begin_transaction();
    try {
        foreach ($solicitadas as $chave => $estados) {
            $stMod = $conexao->prepare('SELECT id FROM rbac_modulos WHERE chave=? AND ativo=1 LIMIT 1');
            if (!$stMod) throw new RuntimeException('prepare módulo: ' . $conexao->error);
            $stMod->bind_param('s',$chave); $stMod->execute();
            $modulo = $stMod->get_result()->fetch_assoc(); $stMod->close();
            if (!$modulo) throw new DomainException('Módulo não encontrado: ' . $chave);

            $stPerm = $conexao->prepare('SELECT id,acao FROM rbac_permissoes WHERE modulo_id=? AND ativo=1');
            if (!$stPerm) throw new RuntimeException('prepare permissões: ' . $conexao->error);
            $stPerm->bind_param('i',$modulo['id']); $stPerm->execute(); $rsPerm=$stPerm->get_result();
            $acoesCatalogadas=[];
            while ($linhaPerm=$rsPerm->fetch_assoc()) $acoesCatalogadas[$linhaPerm['acao']]=(int)$linhaPerm['id'];
            $stPerm->close();

            foreach ($estados as $acao => $estado) {
                if (!isset($acoesCatalogadas[$acao])) throw new DomainException('Ação não encontrada no módulo: ' . $chave . '.' . $acao);
                $permissaoId=$acoesCatalogadas[$acao];
                $atual=$efetivasAntes['permitidas'][$chave . '.' . $acao] ?? null;
                $antes[$chave][$acao]=[
                    'individual'=>$atual['estado_individual'] ?? 'HERDAR',
                    'efetiva'=>$atual['efeito_efetivo'] ?? 'SEM_ACESSO',
                    'origem'=>$atual['origem_efetiva'] ?? [],
                ];
                if ($estado === 'HERDAR') {
                    $stSave=$conexao->prepare('DELETE FROM rbac_usuario_permissoes WHERE usuario_id=? AND tenant_id=? AND permissao_id=?');
                    if (!$stSave) throw new RuntimeException('prepare herança: ' . $conexao->error);
                    $stSave->bind_param('iii',$alvoId,$tenantId,$permissaoId);
                } else {
                    $motivo='Central RBAC';
                    $stSave=$conexao->prepare("INSERT INTO rbac_usuario_permissoes (usuario_id,tenant_id,permissao_id,efeito,escopo_dados,motivo,atribuido_por_usuario_id,revogado_em) VALUES (?,?,?,?,'GLOBAL',?,?,NULL) ON DUPLICATE KEY UPDATE efeito=VALUES(efeito),escopo_dados='GLOBAL',motivo=VALUES(motivo),atribuido_por_usuario_id=VALUES(atribuido_por_usuario_id),revogado_em=NULL,atualizado_em=NOW()");
                    if (!$stSave) throw new RuntimeException('prepare exceção: ' . $conexao->error);
                    $stSave->bind_param('iiissi',$alvoId,$tenantId,$permissaoId,$estado,$motivo,$ator);
                }
                if (!$stSave->execute()) { $erro=$stSave->error; $stSave->close(); throw new RuntimeException('gravar permissão: ' . $erro); }
                $stSave->close();
                $processadas++;
            }
        }
        $efetivasDepois = rbacObterPermissoesEfetivas($conexao, $alvoId, $tenantId, true);
        $concessaoNaoAutorizada = rbacPrimeiraConcessaoNaoPossuida($efetivasAtorAntes, $efetivasAntes, $efetivasDepois);
        if ($concessaoNaoAutorizada !== null) throw new DomainException('Você não pode conceder ao usuário a permissão ' . $concessaoNaoAutorizada . '.');
        $conexao->commit();
        rbacInvalidarCache($conexao,$tenantId);
        foreach ($solicitadas as $chave=>$estados) foreach ($estados as $acao=>$estado) {
            $atual=$efetivasDepois['permitidas'][$chave . '.' . $acao] ?? null;
            $depois[$chave][$acao]=[
                'individual'=>$atual['estado_individual'] ?? 'HERDAR',
                'efetiva'=>$atual['efeito_efetivo'] ?? 'SEM_ACESSO',
                'origem'=>$atual['origem_efetiva'] ?? [],
            ];
        }
        unset($_SESSION['_rbac_cache']);
        rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'permissoes','acao'=>'CONFIGURAR_PERMISSOES','registro_tipo'=>'usuarios','registro_id'=>$alvoId,'dados_antes'=>$antes,'dados_depois'=>$depois,'resultado'=>'SUCESSO','status_http'=>200]);
        return ['ok'=>true,'salvos'=>$processadas,'permissoes'=>$depois];
    } catch (Throwable $e) {
        $conexao->rollback();
        unset($_SESSION['_rbac_cache']);
        error_log('[RBAC] Falha ao salvar matriz individual tenant=' . $tenantId . ' usuario=' . $alvoId . ': ' . $e->getMessage());
        rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'permissoes','acao'=>'CONFIGURAR_PERMISSOES','registro_tipo'=>'usuarios','registro_id'=>$alvoId,'resultado'=>'ERRO','status_http'=>400,'motivo'=>$e instanceof DomainException ? $e->getMessage() : 'Falha interna ao persistir a matriz.']);
        return ['ok'=>false,'mensagem'=>$e instanceof DomainException ? $e->getMessage() : 'Não foi possível salvar as permissões.'];
    }
}

function rbacResetarExcecoesUsuario($conexao,$tenantId,$alvoId) {
    $ator=(int)($_SESSION['usuario_id'] ?? 0);
    if ($alvoId===$ator) return ['ok'=>false,'mensagem'=>'Não é permitido redefinir as próprias permissões.'];
    $alvo=rbacUsuarioMesmoTenant($conexao,$alvoId,$tenantId);
    if (!$alvo) return ['ok'=>false,'mensagem'=>'Usuário não encontrado no condomínio atual.'];
    if (strtolower((string)$alvo['permissao'])==='admin') return ['ok'=>false,'mensagem'=>'Não é possível redefinir exceções granulares de um usuário com acesso total.'];
    $permissoesAtor=rbacObterPermissoesEfetivas($conexao,$ator,$tenantId,true);
    $permissoesAntes=rbacObterPermissoesEfetivas($conexao,$alvoId,$tenantId,true);
    $conexao->begin_transaction();
    try {
        $st=$conexao->prepare('DELETE FROM rbac_usuario_permissoes WHERE usuario_id=? AND tenant_id=?');
        if (!$st) throw new RuntimeException('prepare resetar exceções: ' . $conexao->error);
        $st->bind_param('ii',$alvoId,$tenantId);
        if (!$st->execute()) { $erro=$st->error; $st->close(); throw new RuntimeException($erro); }
        $afetados=$st->affected_rows; $st->close();
        $permissoesDepois=rbacObterPermissoesEfetivas($conexao,$alvoId,$tenantId,true);
        $naoAutorizada=rbacPrimeiraConcessaoNaoPossuida($permissoesAtor,$permissoesAntes,$permissoesDepois);
        if ($naoAutorizada!==null) throw new DomainException('Você não pode restaurar ao usuário a permissão ' . $naoAutorizada . '.');
        $conexao->commit(); rbacInvalidarCache($conexao,$tenantId); unset($_SESSION['_rbac_cache']);
        rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'permissoes','acao'=>'REDEFINIR_PERMISSOES','registro_tipo'=>'usuarios','registro_id'=>$alvoId,'dados_antes'=>$permissoesAntes['permitidas'],'dados_depois'=>['excecoes_removidas'=>$afetados,'permissoes'=>$permissoesDepois['permitidas']],'resultado'=>'SUCESSO','status_http'=>200]);
        return ['ok'=>true,'afetados'=>$afetados];
    } catch(Throwable $e) {
        $conexao->rollback(); unset($_SESSION['_rbac_cache']);
        error_log('[RBAC] Falha ao redefinir permissões tenant=' . $tenantId . ' usuario=' . $alvoId . ': ' . $e->getMessage());
        rbacAuditar($conexao,['modulo_chave'=>'usuarios','submodulo_chave'=>'permissoes','acao'=>'REDEFINIR_PERMISSOES','registro_tipo'=>'usuarios','registro_id'=>$alvoId,'resultado'=>'ERRO','status_http'=>400,'motivo'=>$e instanceof DomainException ? $e->getMessage() : 'Falha ao redefinir exceções.']);
        return ['ok'=>false,'mensagem'=>$e instanceof DomainException ? $e->getMessage() : 'Não foi possível redefinir as permissões.'];
    }
}
?>
