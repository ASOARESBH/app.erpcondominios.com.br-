CREATE FUNCTION erp.trg_atualizar_km_acumulado_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, erp
AS $function$
BEGIN
    IF NEW.status = 'fechado' AND OLD.status = 'aberto' THEN
        INSERT INTO erp.checklist_km_acumulado
            (tenant_id, veiculo_id, categoria, km_acumulado, ultimo_checklist_id, data_atualizacao)
        SELECT
            NEW.tenant_id,
            NEW.veiculo_id,
            c.categoria,
            NEW.km_final - NEW.km_inicial,
            NEW.id,
            statement_timestamp() AT TIME ZONE 'America/Sao_Paulo'
        FROM erp.checklist_alertas_config AS c
        WHERE c.ativo = 1
          AND c.tenant_id = NEW.tenant_id;
    END IF;
    RETURN NEW;
END;
$function$;

CREATE TRIGGER trg_atualizar_km_acumulado
AFTER UPDATE ON erp.checklist_veicular
FOR EACH ROW
EXECUTE FUNCTION erp.trg_atualizar_km_acumulado_fn();

CREATE FUNCTION erp.tr_dependente_ativar_veiculos_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, erp
AS $function$
BEGIN
    IF NEW.ativo = 1 AND OLD.ativo = 0 THEN
        UPDATE erp.veiculos AS v
        SET ativo = 1
        WHERE v.dependente_id = NEW.id
          AND v.tenant_id = NEW.tenant_id
          AND v.ativo = 0;
    END IF;
    RETURN NEW;
END;
$function$;

CREATE TRIGGER tr_dependente_ativar_veiculos
BEFORE UPDATE ON erp.dependentes
FOR EACH ROW
EXECUTE FUNCTION erp.tr_dependente_ativar_veiculos_fn();

CREATE FUNCTION erp.tr_dependente_deletar_veiculos_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, erp
AS $function$
BEGIN
    DELETE FROM erp.veiculos AS v
    WHERE v.dependente_id = OLD.id
      AND v.tenant_id = OLD.tenant_id;
    RETURN OLD;
END;
$function$;

CREATE TRIGGER tr_dependente_deletar_veiculos
BEFORE DELETE ON erp.dependentes
FOR EACH ROW
EXECUTE FUNCTION erp.tr_dependente_deletar_veiculos_fn();

CREATE FUNCTION erp.tr_dependente_inativar_veiculos_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, erp
AS $function$
BEGIN
    IF NEW.ativo = 0 AND OLD.ativo = 1 THEN
        UPDATE erp.veiculos AS v
        SET ativo = 0
        WHERE v.dependente_id = NEW.id
          AND v.tenant_id = NEW.tenant_id;
    END IF;
    RETURN NEW;
END;
$function$;

CREATE TRIGGER tr_dependente_inativar_veiculos
BEFORE UPDATE ON erp.dependentes
FOR EACH ROW
EXECUTE FUNCTION erp.tr_dependente_inativar_veiculos_fn();

CREATE FUNCTION erp.after_validacao_dispositivo_insert_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, erp
AS $function$
BEGIN
    UPDATE erp.dispositivos_tablets AS d
    SET ultimo_acesso = NEW.validado_em,
        total_validacoes = d.total_validacoes + 1
    WHERE d.id = NEW.dispositivo_id
      AND d.tenant_id = NEW.tenant_id;
    RETURN NEW;
END;
$function$;

CREATE TRIGGER after_validacao_dispositivo_insert
AFTER INSERT ON erp.logs_validacoes_dispositivo
FOR EACH ROW
EXECUTE FUNCTION erp.after_validacao_dispositivo_insert_fn();

CREATE FUNCTION erp.trg_alerta_estoque_baixo_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, erp
AS $function$
DECLARE
    v_mensagem text;
BEGIN
    IF NEW.quantidade_estoque <= NEW.estoque_minimo AND NEW.ativo = 1 THEN
        v_mensagem :=
            NEW.nome || ' está ' ||
            CASE WHEN NEW.quantidade_estoque = 0
                 THEN 'ZERADO'
                 ELSE 'abaixo do estoque mínimo'
            END ||
            ': ' || NEW.quantidade_estoque::text || ' ' || NEW.unidade_medida ||
            ' (mínimo: ' || NEW.estoque_minimo::text || ' ' || NEW.unidade_medida || ')';

        INSERT INTO erp.alertas_estoque
            (tenant_id, produto_id, tipo_alerta, mensagem)
        VALUES
            (NEW.tenant_id, NEW.id,
             CASE WHEN NEW.quantidade_estoque = 0
                  THEN 'Estoque_Zerado'
                  ELSE 'Estoque_Minimo'
             END,
             v_mensagem);
    END IF;
    RETURN NEW;
END;
$function$;

CREATE TRIGGER trg_alerta_estoque_baixo
AFTER UPDATE ON erp.produtos_estoque
FOR EACH ROW
EXECUTE FUNCTION erp.trg_alerta_estoque_baixo_fn();

CREATE FUNCTION erp.after_qrcode_token_insert_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, erp
AS $function$
BEGIN
    UPDATE erp.acessos_visitantes AS a
    SET token_atual = NEW.token
    WHERE a.id = NEW.acesso_id
      AND a.tenant_id = NEW.tenant_id;
    RETURN NEW;
END;
$function$;

CREATE TRIGGER after_qrcode_token_insert
AFTER INSERT ON erp.qrcode_tokens
FOR EACH ROW
EXECUTE FUNCTION erp.after_qrcode_token_insert_fn();
