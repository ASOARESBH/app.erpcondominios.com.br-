-- Manual PostgreSQL translation of the seven source MySQL triggers and two procedures.
-- The source snapshot is the read-only restored 2026-11-07 dump.
-- These definitions are installed only in the isolated rehearsal database.
BEGIN;

CREATE OR REPLACE FUNCTION erp.after_qrcode_token_insert_fn()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    UPDATE erp.acessos_visitantes
       SET token_atual = NEW.token
     WHERE id = NEW.acesso_id
       AND tenant_id = NEW.tenant_id;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER after_qrcode_token_insert
AFTER INSERT ON erp.qrcode_tokens
FOR EACH ROW EXECUTE FUNCTION erp.after_qrcode_token_insert_fn();

CREATE OR REPLACE FUNCTION erp.after_validacao_dispositivo_insert_fn()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    UPDATE erp.dispositivos_tablets
       SET ultimo_acesso = NEW.validado_em,
           total_validacoes = total_validacoes + 1
     WHERE id = NEW.dispositivo_id
       AND tenant_id = NEW.tenant_id;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER after_validacao_dispositivo_insert
AFTER INSERT ON erp.logs_validacoes_dispositivo
FOR EACH ROW EXECUTE FUNCTION erp.after_validacao_dispositivo_insert_fn();

CREATE OR REPLACE FUNCTION erp.trg_alerta_estoque_baixo_fn()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NEW.quantidade_estoque <= NEW.estoque_minimo AND NEW.ativo = 1 THEN
        INSERT INTO erp.alertas_estoque (tenant_id, produto_id, tipo_alerta, mensagem)
        VALUES (
            NEW.tenant_id,
            NEW.id,
            CASE WHEN NEW.quantidade_estoque = 0 THEN 'Estoque_Zerado' ELSE 'Estoque_Minimo' END,
            concat(NEW.nome, ' está ',
                   CASE WHEN NEW.quantidade_estoque = 0 THEN 'ZERADO' ELSE 'abaixo do estoque mínimo' END,
                   ': ', NEW.quantidade_estoque, ' ', NEW.unidade_medida,
                   ' (mínimo: ', NEW.estoque_minimo, ' ', NEW.unidade_medida, ')')
        );
    END IF;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER trg_alerta_estoque_baixo
AFTER UPDATE ON erp.produtos_estoque
FOR EACH ROW EXECUTE FUNCTION erp.trg_alerta_estoque_baixo_fn();

CREATE OR REPLACE FUNCTION erp.trg_atualizar_km_acumulado_fn()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NEW.status = 'fechado' AND OLD.status = 'aberto' THEN
        INSERT INTO erp.checklist_km_acumulado
            (tenant_id, veiculo_id, categoria, km_acumulado, ultimo_checklist_id)
        SELECT NEW.tenant_id, NEW.veiculo_id, categoria, (NEW.km_final - NEW.km_inicial), NEW.id
          FROM erp.checklist_alertas_config
         WHERE ativo = 1
           AND tenant_id = NEW.tenant_id
        ON CONFLICT (id) DO UPDATE
          SET km_acumulado = erp.checklist_km_acumulado.km_acumulado + EXCLUDED.km_acumulado,
              ultimo_checklist_id = EXCLUDED.ultimo_checklist_id,
              data_atualizacao = LOCALTIMESTAMP(0);
    END IF;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER trg_atualizar_km_acumulado
AFTER UPDATE ON erp.checklist_veicular
FOR EACH ROW EXECUTE FUNCTION erp.trg_atualizar_km_acumulado_fn();

CREATE OR REPLACE FUNCTION erp.tr_dependente_ativar_veiculos_fn()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NEW.ativo = 1 AND OLD.ativo = 0 THEN
        UPDATE erp.veiculos SET ativo = 1 WHERE dependente_id = NEW.id AND tenant_id = NEW.tenant_id AND ativo = 0;
    END IF;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER tr_dependente_ativar_veiculos
BEFORE UPDATE ON erp.dependentes
FOR EACH ROW EXECUTE FUNCTION erp.tr_dependente_ativar_veiculos_fn();

CREATE OR REPLACE FUNCTION erp.tr_dependente_deletar_veiculos_fn()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    DELETE FROM erp.veiculos WHERE dependente_id = OLD.id AND tenant_id = OLD.tenant_id;
    RETURN OLD;
END;
$fn$;
CREATE TRIGGER tr_dependente_deletar_veiculos
BEFORE DELETE ON erp.dependentes
FOR EACH ROW EXECUTE FUNCTION erp.tr_dependente_deletar_veiculos_fn();

CREATE OR REPLACE FUNCTION erp.tr_dependente_inativar_veiculos_fn()
RETURNS trigger
LANGUAGE plpgsql
AS $fn$
BEGIN
    IF NEW.ativo = 0 AND OLD.ativo = 1 THEN
        UPDATE erp.veiculos SET ativo = 0 WHERE dependente_id = NEW.id AND tenant_id = NEW.tenant_id;
    END IF;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER tr_dependente_inativar_veiculos
BEFORE UPDATE ON erp.dependentes
FOR EACH ROW EXECUTE FUNCTION erp.tr_dependente_inativar_veiculos_fn();

ALTER FUNCTION erp.after_qrcode_token_insert_fn() OWNER TO erp_owner;
ALTER FUNCTION erp.after_validacao_dispositivo_insert_fn() OWNER TO erp_owner;
ALTER FUNCTION erp.trg_alerta_estoque_baixo_fn() OWNER TO erp_owner;
ALTER FUNCTION erp.trg_atualizar_km_acumulado_fn() OWNER TO erp_owner;
ALTER FUNCTION erp.tr_dependente_ativar_veiculos_fn() OWNER TO erp_owner;
ALTER FUNCTION erp.tr_dependente_deletar_veiculos_fn() OWNER TO erp_owner;
ALTER FUNCTION erp.tr_dependente_inativar_veiculos_fn() OWNER TO erp_owner;

CREATE OR REPLACE PROCEDURE legado.corrigir_moradores_anexos_tenant()
LANGUAGE plpgsql
AS $proc$
BEGIN
    ALTER TABLE erp.moradores_anexos
        ADD COLUMN IF NOT EXISTS tenant_id integer NOT NULL DEFAULT 1;
END;
$proc$;

CREATE OR REPLACE PROCEDURE legado.mt_adicionar_coluna(
    IN p_tabela varchar(64),
    IN p_coluna varchar(64),
    IN p_definicao text
)
LANGUAGE plpgsql
AS $proc$
DECLARE
    v_match text[];
    v_source_type text;
    v_type text;
    v_null text;
    v_default text;
BEGIN
    IF p_tabela !~ '^[A-Za-z_][A-Za-z0-9_]{0,62}$'
       OR p_coluna !~ '^[A-Za-z_][A-Za-z0-9_]{0,62}$' THEN
        RAISE EXCEPTION 'invalid identifier';
    END IF;
    v_match := regexp_match(p_definicao,
        '^\s*(TINYINT(?:\([0-9]+\))?|SMALLINT(?:\([0-9]+\))?|MEDIUMINT(?:\([0-9]+\))?|INT(?:EGER)?(?:\([0-9]+\))?|BIGINT(?:\([0-9]+\))?|VARCHAR\([0-9]{1,5}\)|TEXT|DATE|DATETIME|TIMESTAMP|TIME|DECIMAL\([0-9]{1,3}\s*,\s*[0-9]{1,3}\))\s*(NOT NULL)?\s*(?:DEFAULT\s+(NULL|-?[0-9]+(?:\.[0-9]+)?|CURRENT_TIMESTAMP(?:\(\d*\))?|CURRENT_DATE|CURRENT_TIME|''[A-Za-z0-9 _-]*''))?\s*(?:AFTER\s+`?[A-Za-z_][A-Za-z0-9_]*`?)?\s*$',
        'i');
    IF v_match IS NULL THEN
        RAISE EXCEPTION 'unsupported column definition';
    END IF;
    v_source_type := upper(regexp_replace(v_match[1], '\([0-9,\s]+\)', '', 'g'));
    v_type := CASE
        WHEN v_source_type IN ('TINYINT', 'SMALLINT') THEN 'smallint'
        WHEN v_source_type IN ('MEDIUMINT', 'INT', 'INTEGER') THEN 'integer'
        WHEN v_source_type = 'BIGINT' THEN 'bigint'
        WHEN v_source_type = 'DATE' THEN 'date'
        WHEN v_source_type IN ('DATETIME', 'TIMESTAMP') THEN 'timestamp(0) without time zone'
        WHEN v_source_type = 'TIME' THEN 'time(0) without time zone'
        WHEN v_source_type = 'TEXT' THEN 'text'
        WHEN v_source_type = 'DECIMAL' THEN regexp_replace(lower(v_match[1]), '^decimal', 'numeric', 'i')
        ELSE lower(v_match[1])
    END;
    v_null := CASE WHEN v_match[2] IS NOT NULL THEN ' NOT NULL' ELSE '' END;
    v_default := '';
    IF v_match[3] IS NOT NULL THEN
        IF upper(v_match[3]) LIKE 'CURRENT_TIMESTAMP%' AND v_source_type = 'DATE' THEN
            v_default := ' DEFAULT CURRENT_DATE';
        ELSIF upper(v_match[3]) LIKE 'CURRENT_TIMESTAMP%' AND v_source_type = 'TIME' THEN
            v_default := ' DEFAULT LOCALTIME(0)';
        ELSIF upper(v_match[3]) LIKE 'CURRENT_TIMESTAMP%' THEN
            v_default := ' DEFAULT LOCALTIMESTAMP(0)';
        ELSIF upper(v_match[3]) = 'CURRENT_DATE' THEN
            v_default := ' DEFAULT CURRENT_DATE';
        ELSIF upper(v_match[3]) = 'CURRENT_TIME' THEN
            v_default := ' DEFAULT LOCALTIME(0)';
        ELSE
            v_default := ' DEFAULT ' || v_match[3];
        END IF;
    END IF;
    IF to_regclass(format('%I.%I', 'erp', p_tabela)) IS NULL THEN
        RAISE EXCEPTION 'target table does not exist';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'erp' AND table_name = p_tabela AND column_name = p_coluna
    ) THEN
        EXECUTE format('ALTER TABLE %I.%I ADD COLUMN %I %s%s%s', 'erp', p_tabela, p_coluna, v_type, v_null, v_default);
    END IF;
END;
$proc$;

ALTER PROCEDURE legado.corrigir_moradores_anexos_tenant() OWNER TO erp_owner;
ALTER PROCEDURE legado.mt_adicionar_coluna(varchar, varchar, text) OWNER TO erp_owner;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA erp FROM PUBLIC;
REVOKE EXECUTE ON ALL ROUTINES IN SCHEMA legado FROM PUBLIC;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA erp TO erp_app;
REVOKE EXECUTE ON ALL ROUTINES IN SCHEMA legado FROM erp_app;
COMMIT;
