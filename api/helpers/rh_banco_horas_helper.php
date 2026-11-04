<?php
/**
 * Helpers compartilhados do ledger de Banco de Horas.
 * Toda consulta/escrita recebe tenant_id já resolvido da sessão autenticada.
 */

if (!function_exists('bh_sinal')) {
    /** Retorna +1/-1 para tipos reconhecidos; null para dados legados inválidos. */
    function bh_sinal(string $tipo): ?int {
        if ($tipo === 'credito') return 1;
        if (in_array($tipo, ['debito', 'abatimento', 'pagamento'], true)) return -1;
        return null;
    }
}

if (!function_exists('bh_tipo_valido')) {
    function bh_tipo_valido(string $tipo): bool {
        return in_array($tipo, ['credito', 'debito', 'abatimento', 'pagamento', 'excedente'], true);
    }
}

if (!function_exists('bh_fmt')) {
    /** Formata minutos assinados como HH:MM, preservando o sinal negativo. */
    function bh_fmt(int $minutos): string {
        $negativo = $minutos < 0;
        $absoluto = abs($minutos);
        return ($negativo ? '-' : '') . sprintf('%02d:%02d', intdiv($absoluto, 60), $absoluto % 60);
    }
}

if (!function_exists('bh_tamanho_texto')) {
    function bh_tamanho_texto(string $texto): int {
        if (function_exists('mb_strlen')) return mb_strlen($texto, 'UTF-8');
        $total = preg_match_all('/./us', $texto, $matches);
        return $total === false ? strlen($texto) : $total;
    }
}

if (!function_exists('bh_saldo')) {
    /** Calcula o saldo apenas com tipos conhecidos; tipo vazio/desconhecido não vira débito. */
    function bh_saldo(array $linhas): int {
        $saldo = 0;
        foreach ($linhas as $linha) {
            $tipo = (string)($linha['tipo'] ?? '');
            $sinal = bh_sinal($tipo);
            if ($sinal === null) continue;
            $saldo += $sinal * (int)($linha['minutos'] ?? 0);
        }
        return $saldo;
    }
}

if (!function_exists('bh_apurar_dia')) {
    /** Apuração única usada pela sincronização, prévia e reprocessamento. */
    function bh_apurar_dia(array $calc, array $abono, array $escala, string $data): array {
        $extraBruto = max(0, (int)($calc['extras'] ?? $calc['extra_bruto'] ?? 0));
        $modoAbono = (string)($abono['abono_extras'] ?? 'nenhum');
        $extraAbonado = $modoAbono === 'total' ? $extraBruto
            : ($modoAbono === 'parcial' ? min($extraBruto, max(0, (int)($abono['abono_extras_min'] ?? 0))) : 0);
        $extraLiquido = max(0, $extraBruto - $extraAbonado);
        $politica = in_array(($escala['politica_extra_excedente'] ?? 'CREDITAR_TUDO'), ['CREDITAR_TUDO', 'LIMITAR_E_SEPARAR'], true)
            ? (string)($escala['politica_extra_excedente'] ?? 'CREDITAR_TUDO')
            : 'CREDITAR_TUDO';
        $limiteRaw = $escala['limite_extra_diario_min'] ?? null;
        $limiteDia = ($limiteRaw === null || $limiteRaw === '') ? 120 : max(1, (int)$limiteRaw);
        $credito = $politica === 'LIMITAR_E_SEPARAR' ? min($extraLiquido, $limiteDia) : $extraLiquido;
        $excedente = $politica === 'LIMITAR_E_SEPARAR' ? max(0, $extraLiquido - $limiteDia) : 0;

        $atrasoBruto = max(0, (int)($calc['atraso'] ?? $calc['atraso_min'] ?? 0));
        $modoAbonoAtraso = (string)($abono['abono_atraso'] ?? 'nenhum');
        $atrasoAbonado = $modoAbonoAtraso === 'total' ? $atrasoBruto
            : ($modoAbonoAtraso === 'parcial' ? min($atrasoBruto, max(0, (int)($abono['abono_atraso_min'] ?? 0))) : 0);
        $atrasoLiquido = max(0, $atrasoBruto - $atrasoAbonado);
        $saidaAntecipada = max(0, (int)($calc['saida_antecipada'] ?? $calc['saida_antecipada_min'] ?? 0));
        $jornada = function_exists('_obter_parametros_jornada_dia')
            ? _obter_parametros_jornada_dia($escala, $data)
            : ['carga_min' => (int)($escala['carga_horaria_diaria_min'] ?? 480)];
        $carga = max(0, (int)($jornada['carga_min'] ?? $escala['carga_horaria_diaria_min'] ?? 480));
        $faltaMin = (($abono['tipo_dia'] ?? '') === 'falta' && empty($abono['abono_falta'])) ? $carga : 0;
        $dsrPerdido = max(0, (int)($abono['dsr_perdido_min'] ?? 0));
        $alertas = $extraBruto > $limiteDia ? ['EXTRA_ACIMA_LIMITE_DIARIO'] : [];

        return [
            'extra_bruto' => $extraBruto,
            'extra_abonado' => $extraAbonado,
            'extra_liquido' => $extraLiquido,
            'limite_dia' => $limiteDia,
            'credito' => $credito,
            'excedente' => $excedente,
            'atraso_liquido' => $atrasoLiquido,
            'saida_antecipada' => $saidaAntecipada,
            'falta_min' => $faltaMin,
            'dsr_perdido_min' => $dsrPerdido,
            'debito_total' => $atrasoLiquido + $saidaAntecipada + $faltaMin + $dsrPerdido,
            'regra_aplicada' => $politica,
            'alertas' => $alertas,
        ];
    }
}

if (!function_exists('bh_garantir_tabela')) {
    /** Verifica schema aplicado sem executar DDL durante uma requisição web. */
    function bh_garantir_tabela(mysqli $conn): void {
        $tabela = $conn->query("SHOW TABLES LIKE 'rh_banco_horas'");
        if (!$tabela || $tabela->num_rows === 0) {
            throw new RuntimeException('Migration de Banco de Horas pendente.');
        }
        $tabela->free();
        $obrigatorias = [
            'tenant_id','colaborador_id','lancamento_id','data','tipo','minutos','descricao','usuario',
            'saldo_anterior_min','saldo_posterior_min','saldo_negativo_confirmado','justificativa_saldo_negativo',
            'usuario_id','ip','idempotency_key','criado_em','minutos_apurados','limite_aplicado_min',
            'regra_aplicada','versao_calculo','reprocessamento_id',
        ];
        foreach ($obrigatorias as $coluna) {
            $colunas = $conn->query("SHOW COLUMNS FROM rh_banco_horas LIKE '" . $conn->real_escape_string($coluna) . "'");
            if (!$colunas || $colunas->num_rows === 0) {
                if ($colunas) $colunas->free();
                throw new RuntimeException('Migration de Banco de Horas incompleta.');
            }
            $colunas->free();
        }
    }
}

if (!function_exists('bh_colaborador_pertence_tenant')) {
    function bh_colaborador_pertence_tenant(mysqli $conn, int $tenantId, int $colaboradorId): bool {
        $st = $conn->prepare('SELECT id FROM rh_colaboradores WHERE tenant_id = ? AND id = ? LIMIT 1');
        if (!$st) throw new RuntimeException('Não foi possível validar o colaborador.');
        $st->bind_param('ii', $tenantId, $colaboradorId);
        if (!$st->execute()) {
            error_log('[RH Banco de Horas] Falha ao validar colaborador; errno=' . (int)$st->errno);
            $st->close();
            throw new RuntimeException('Não foi possível validar o colaborador.');
        }
        $existe = $st->get_result()->num_rows > 0;
        $st->close();
        return $existe;
    }
}

if (!function_exists('bh_obter_saldo')) {
    /** Totais somente de tipos válidos e do colaborador no tenant solicitado. */
    function bh_obter_saldo(mysqli $conn, int $tenantId, int $colaboradorId): array {
        $st = $conn->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN tipo = 'credito' THEN minutos ELSE 0 END), 0) AS credito,
                COALESCE(SUM(CASE WHEN tipo IN ('debito','abatimento','pagamento') THEN minutos ELSE 0 END), 0) AS debito
             FROM rh_banco_horas
             WHERE tenant_id = ? AND colaborador_id = ?
               AND tipo IN ('credito','debito','abatimento','pagamento')"
        );
        if (!$st) throw new RuntimeException('Não foi possível consultar o saldo do Banco de Horas.');
        $st->bind_param('ii', $tenantId, $colaboradorId);
        if (!$st->execute()) {
            error_log('[RH Banco de Horas] Falha ao somar saldo; errno=' . (int)$st->errno);
            $st->close();
            throw new RuntimeException('Não foi possível consultar o saldo do Banco de Horas.');
        }
        $row = $st->get_result()->fetch_assoc() ?: ['credito' => 0, 'debito' => 0];
        $st->close();
        $credito = (int)$row['credito'];
        $debito = (int)$row['debito'];
        $saldo = bh_saldo([
            ['tipo' => 'credito', 'minutos' => $credito],
            ['tipo' => 'debito', 'minutos' => $debito],
        ]);
        return ['credito' => $credito, 'debito' => $debito, 'saldo' => $saldo];
    }
}

if (!function_exists('bh_obter_limite_negativo')) {
    /** NULL significa sem limite; verifica coluna para compatibilidade pré-migration. */
    function bh_obter_limite_negativo(mysqli $conn, int $tenantId, int $colaboradorId): ?int {
        $meta = $conn->query("SHOW COLUMNS FROM rh_escala LIKE 'limite_saldo_negativo_min'");
        if (!$meta) {
            error_log('[RH Banco de Horas] Não foi possível verificar a coluna do limite negativo; errno=' . (int)$conn->errno);
            throw new RuntimeException('Não foi possível validar o limite de saldo negativo.');
        }
        $temColuna = $meta->num_rows > 0;
        $meta->free();
        if (!$temColuna) return null;

        $st = $conn->prepare(
            'SELECT limite_saldo_negativo_min FROM rh_escala
             WHERE tenant_id = ? AND colaborador_id = ? AND ativo = 1
             ORDER BY id ASC LIMIT 1'
        );
        if (!$st) throw new RuntimeException('Não foi possível consultar o limite da escala.');
        $st->bind_param('ii', $tenantId, $colaboradorId);
        if (!$st->execute()) {
            error_log('[RH Banco de Horas] Falha ao consultar limite; errno=' . (int)$st->errno);
            $st->close();
            throw new RuntimeException('Não foi possível consultar o limite da escala.');
        }
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return ($row && $row['limite_saldo_negativo_min'] !== null)
            ? max(0, (int)$row['limite_saldo_negativo_min'])
            : null;
    }
}

if (!function_exists('bh_obter_lock_colaborador')) {
    /** Named lock compartilhado por gravações manuais, sincronização automática e DSR. */
    function bh_obter_lock_colaborador(mysqli $conn, int $tenantId, int $colaboradorId, int $timeout = 10): bool {
        $nome = 'bh_' . $tenantId . '_' . $colaboradorId;
        $st = $conn->prepare('SELECT GET_LOCK(?, ?) AS adquirido');
        if (!$st) throw new RuntimeException('Não foi possível iniciar o bloqueio do Banco de Horas.');
        $st->bind_param('si', $nome, $timeout);
        if (!$st->execute()) {
            error_log('[RH Banco de Horas] Falha ao adquirir lock; errno=' . (int)$st->errno);
            $st->close();
            throw new RuntimeException('Não foi possível iniciar o bloqueio do Banco de Horas.');
        }
        $adquirido = (int)($st->get_result()->fetch_assoc()['adquirido'] ?? 0) === 1;
        $st->close();
        return $adquirido;
    }
}

if (!function_exists('bh_liberar_lock_colaborador')) {
    function bh_liberar_lock_colaborador(mysqli $conn, int $tenantId, int $colaboradorId): void {
        $nome = 'bh_' . $tenantId . '_' . $colaboradorId;
        $st = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if (!$st) return;
        $st->bind_param('s', $nome);
        if (!$st->execute()) error_log('[RH Banco de Horas] Falha ao liberar lock; errno=' . (int)$st->errno);
        $st->close();
    }
}

if (!function_exists('bh_avaliar_registro_manual')) {
    /** Avalia a regra de saldo após o saldo ser recalculado dentro do lock/transação. */
    function bh_avaliar_registro_manual(
        string $tipo,
        int $minutos,
        int $saldoAtual,
        bool $confirmarSaldoNegativo,
        string $justificativa,
        ?int $limiteNegativo
    ): array {
        $saldoProjetado = bh_saldo([
            ['tipo' => 'credito', 'minutos' => $saldoAtual],
            ['tipo' => $tipo, 'minutos' => $minutos],
        ]);
        $dados = [
            'saldo_atual' => $saldoAtual,
            'saldo_atual_fmt' => bh_fmt($saldoAtual),
            'minutos' => $minutos,
            'minutos_fmt' => bh_fmt($minutos),
            'saldo_projetado' => $saldoProjetado,
            'saldo_projetado_fmt' => bh_fmt($saldoProjetado),
            'excedente' => max(0, $minutos - $saldoAtual),
            'excedente_fmt' => bh_fmt(max(0, $minutos - $saldoAtual)),
        ];

        if ($tipo === 'pagamento' && $minutos > $saldoAtual) {
            return [
                'permitido' => false,
                'http' => 422,
                'codigo' => 'PAGAMENTO_SALDO_INSUFICIENTE',
                'mensagem' => 'Pagamento maior que o saldo disponível (' . bh_fmt($saldoAtual) . '). Para compensar com folga, use Abatimento.',
                'dados' => $dados,
                'saldo_negativo_confirmado' => false,
            ];
        }

        $precisaConfirmar = $tipo === 'abatimento' && $minutos > $saldoAtual;
        if ($tipo === 'abatimento' && $saldoProjetado < 0 && $limiteNegativo !== null && abs($saldoProjetado) > $limiteNegativo) {
            $dados['limite_saldo_negativo_min'] = $limiteNegativo;
            $dados['limite_saldo_negativo_fmt'] = bh_fmt($limiteNegativo);
            return [
                'permitido' => false,
                'http' => 422,
                'codigo' => 'LIMITE_SALDO_NEGATIVO_EXCEDIDO',
                'mensagem' => 'O saldo projetado ultrapassa o limite negativo configurado (' . bh_fmt(-$limiteNegativo) . '). Ajuste a quantidade ou o limite da escala.',
                'dados' => $dados,
                'saldo_negativo_confirmado' => false,
            ];
        }
        if ($precisaConfirmar && !$confirmarSaldoNegativo) {
            return [
                'permitido' => false,
                'http' => 409,
                'codigo' => 'CONFIRMAR_SALDO_NEGATIVO',
                'mensagem' => 'O abatimento é maior que o saldo. O colaborador ficará com saldo negativo.',
                'dados' => $dados,
                'saldo_negativo_confirmado' => false,
            ];
        }
        if ($precisaConfirmar && bh_tamanho_texto(trim($justificativa)) < 10) {
            return [
                'permitido' => false,
                'http' => 422,
                'codigo' => 'JUSTIFICATIVA_SALDO_NEGATIVO_OBRIGATORIA',
                'mensagem' => 'A justificativa da compensação antecipada deve ter no mínimo 10 caracteres.',
                'dados' => $dados,
                'saldo_negativo_confirmado' => false,
            ];
        }
        if ($precisaConfirmar && bh_tamanho_texto(trim($justificativa)) > 500) {
            return [
                'permitido' => false,
                'http' => 422,
                'codigo' => 'JUSTIFICATIVA_SALDO_NEGATIVO_INVALIDA',
                'mensagem' => 'A justificativa deve ter no máximo 500 caracteres.',
                'dados' => $dados,
                'saldo_negativo_confirmado' => false,
            ];
        }

        return [
            'permitido' => true,
            'http' => 200,
            'codigo' => null,
            'mensagem' => '',
            'dados' => $dados,
            'saldo_negativo_confirmado' => $precisaConfirmar,
        ];
    }
}
