-- REVIEW ARTIFACT: DO NOT APPLY until report.json has executable_complete=true and required reviews are approved.
-- Generator code SHA-256: 9981bc8aa00ee740d7edc48e48d57052831a5d9d53b106091d0a57ea083867c1
-- Collation overrides SHA-256: 44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a

-- Generator version: 1.3.0

-- Catalog SHA-256: 9112484a2fd7ed128a51f4b39e167f6e025d6560ab78db24d341fc52e666c7f4

SET search_path TO "erp", pg_catalog;

CREATE FUNCTION "erp"."fn_on_update_46a7019aac5989c7"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_46a7019aac5989c7" BEFORE UPDATE ON "erp"."administradoras_importacao"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_46a7019aac5989c7"();

CREATE FUNCTION "erp"."fn_on_update_c1c2f6166aeb8e43"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_c1c2f6166aeb8e43" BEFORE UPDATE ON "erp"."administradoras_layouts"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_c1c2f6166aeb8e43"();

CREATE FUNCTION "erp"."fn_on_update_d501d267075285a0"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_d501d267075285a0" BEFORE UPDATE ON "erp"."alertas_acesso"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_d501d267075285a0"();

CREATE FUNCTION "erp"."fn_on_update_c325cd1d816c5b0e"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_c325cd1d816c5b0e" BEFORE UPDATE ON "erp"."alertas_unidade_preferencias"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_c325cd1d816c5b0e"();

CREATE FUNCTION "erp"."fn_on_update_0af2a1832d3fc78b"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_0af2a1832d3fc78b" BEFORE UPDATE ON "erp"."alertas_unidade_rate_limits"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_0af2a1832d3fc78b"();

CREATE FUNCTION "erp"."fn_on_update_33f95d3c88599e0e"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_33f95d3c88599e0e" BEFORE UPDATE ON "erp"."aplicativos_catalogo"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_33f95d3c88599e0e"();

CREATE FUNCTION "erp"."fn_on_update_eebac67a52c4b5b4"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_eebac67a52c4b5b4" BEFORE UPDATE ON "erp"."aplicativos_versoes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_eebac67a52c4b5b4"();

CREATE FUNCTION "erp"."fn_on_update_82d71b8c390bcf9c"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_82d71b8c390bcf9c" BEFORE UPDATE ON "erp"."assembleias"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_82d71b8c390bcf9c"();

CREATE FUNCTION "erp"."fn_on_update_1d15296375218f38"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['ultimo_contato']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['ultimo_contato']::text[]) THEN
    IF NEW."ultimo_contato" IS NOT DISTINCT FROM OLD."ultimo_contato" THEN
      NEW."ultimo_contato" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_1d15296375218f38" BEFORE UPDATE ON "erp"."bridge_status"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_1d15296375218f38"();

CREATE FUNCTION "erp"."fn_on_update_c758f639604284e6"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_c758f639604284e6" BEFORE UPDATE ON "erp"."chatin_conversas"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_c758f639604284e6"();

CREATE FUNCTION "erp"."fn_on_update_a32ad3b469f74468"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_a32ad3b469f74468" BEFORE UPDATE ON "erp"."chatin_preferencias"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_a32ad3b469f74468"();

CREATE FUNCTION "erp"."fn_on_update_bd6ac3c9a2bc88f9"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_bd6ac3c9a2bc88f9" BEFORE UPDATE ON "erp"."checklist_km_acumulado"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_bd6ac3c9a2bc88f9"();

CREATE FUNCTION "erp"."fn_on_update_c9ae6d933390fce8"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_c9ae6d933390fce8" BEFORE UPDATE ON "erp"."config_moradores_campos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_c9ae6d933390fce8"();

CREATE FUNCTION "erp"."fn_on_update_16f1af0cdc44be83"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_16f1af0cdc44be83" BEFORE UPDATE ON "erp"."config_moradores_opcoes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_16f1af0cdc44be83"();

CREATE FUNCTION "erp"."fn_on_update_6ac330eaa149bc70"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_6ac330eaa149bc70" BEFORE UPDATE ON "erp"."config_periodo_leitura"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_6ac330eaa149bc70"();

CREATE FUNCTION "erp"."fn_on_update_7934fcb57184524f"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado']::text[]) THEN
    IF NEW."atualizado" IS NOT DISTINCT FROM OLD."atualizado" THEN
      NEW."atualizado" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_7934fcb57184524f" BEFORE UPDATE ON "erp"."config_sessao"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_7934fcb57184524f"();

CREATE FUNCTION "erp"."fn_on_update_fcf127338605a22b"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_fcf127338605a22b" BEFORE UPDATE ON "erp"."config_visitantes_campos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_fcf127338605a22b"();

CREATE FUNCTION "erp"."fn_on_update_a8650dd4b6b0d2e3"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_a8650dd4b6b0d2e3" BEFORE UPDATE ON "erp"."configuracoes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_a8650dd4b6b0d2e3"();

CREATE FUNCTION "erp"."fn_on_update_a9d65875a0667feb"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_a9d65875a0667feb" BEFORE UPDATE ON "erp"."contas_bancarias"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_a9d65875a0667feb"();

CREATE FUNCTION "erp"."fn_on_update_e577fd7142ae9954"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_e577fd7142ae9954" BEFORE UPDATE ON "erp"."contas_pagar"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_e577fd7142ae9954"();

CREATE FUNCTION "erp"."fn_on_update_e54a38f919d664fa"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_e54a38f919d664fa" BEFORE UPDATE ON "erp"."contas_receber"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_e54a38f919d664fa"();

CREATE FUNCTION "erp"."fn_on_update_bb2876ff72f8d9a1"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_bb2876ff72f8d9a1" BEFORE UPDATE ON "erp"."contratos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_bb2876ff72f8d9a1"();

CREATE FUNCTION "erp"."fn_on_update_1f7fa9bb3cb9a0ac"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_1f7fa9bb3cb9a0ac" BEFORE UPDATE ON "erp"."convidados"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_1f7fa9bb3cb9a0ac"();

CREATE FUNCTION "erp"."fn_on_update_5714fbb43c045977"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['updated_at']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']::text[]) THEN
    IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN
      NEW."updated_at" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_5714fbb43c045977" BEFORE UPDATE ON "erp"."crm_relacionamentos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_5714fbb43c045977"();

CREATE FUNCTION "erp"."fn_on_update_04afdee99e0a784d"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_04afdee99e0a784d" BEFORE UPDATE ON "erp"."dashboard_empresa_widgets"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_04afdee99e0a784d"();

CREATE FUNCTION "erp"."fn_on_update_864eb996188bc745"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_864eb996188bc745" BEFORE UPDATE ON "erp"."dashboard_usuario_widgets"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_864eb996188bc745"();

CREATE FUNCTION "erp"."fn_on_update_ca5fd5c00e59be6b"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_ca5fd5c00e59be6b" BEFORE UPDATE ON "erp"."dashboard_widgets_catalogo"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_ca5fd5c00e59be6b"();

CREATE FUNCTION "erp"."fn_on_update_612faba2d7446651"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_612faba2d7446651" BEFORE UPDATE ON "erp"."departamentos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_612faba2d7446651"();

CREATE FUNCTION "erp"."fn_on_update_39dc0fab51128cc4"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_39dc0fab51128cc4" BEFORE UPDATE ON "erp"."dependentes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_39dc0fab51128cc4"();

CREATE FUNCTION "erp"."fn_on_update_74a1764e7720906f"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['updated_at']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']::text[]) THEN
    IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN
      NEW."updated_at" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_74a1764e7720906f" BEFORE UPDATE ON "erp"."documentos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_74a1764e7720906f"();

CREATE FUNCTION "erp"."fn_on_update_8892f107ffbd2b0c"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['updated_at']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']::text[]) THEN
    IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN
      NEW."updated_at" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_8892f107ffbd2b0c" BEFORE UPDATE ON "erp"."documentos_departamentos_migrado_bkp"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_8892f107ffbd2b0c"();

CREATE FUNCTION "erp"."fn_on_update_88f0d01deb64e6e5"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['updated_at']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']::text[]) THEN
    IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN
      NEW."updated_at" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_88f0d01deb64e6e5" BEFORE UPDATE ON "erp"."documentos_grupos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_88f0d01deb64e6e5"();

CREATE FUNCTION "erp"."fn_on_update_751b59b1aabdc36c"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['updated_at']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']::text[]) THEN
    IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN
      NEW."updated_at" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_751b59b1aabdc36c" BEFORE UPDATE ON "erp"."documentos_pastas"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_751b59b1aabdc36c"();

CREATE FUNCTION "erp"."fn_on_update_574e3bfd95b2b6bb"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['updated_at']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']::text[]) THEN
    IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN
      NEW."updated_at" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_574e3bfd95b2b6bb" BEFORE UPDATE ON "erp"."documentos_tipos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_574e3bfd95b2b6bb"();

CREATE FUNCTION "erp"."fn_on_update_bc18145eda7a052c"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_bc18145eda7a052c" BEFORE UPDATE ON "erp"."email_alertas"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_bc18145eda7a052c"();

CREATE FUNCTION "erp"."fn_on_update_78a535672fec7f5a"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_78a535672fec7f5a" BEFORE UPDATE ON "erp"."email_templates"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_78a535672fec7f5a"();

CREATE FUNCTION "erp"."fn_on_update_21dd9efa9af493fb"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_21dd9efa9af493fb" BEFORE UPDATE ON "erp"."empresa"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_21dd9efa9af493fb"();

CREATE FUNCTION "erp"."fn_on_update_289250f988da1ab3"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_289250f988da1ab3" BEFORE UPDATE ON "erp"."empresa_administradora"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_289250f988da1ab3"();

CREATE FUNCTION "erp"."fn_on_update_db4bd68e70b38502"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_db4bd68e70b38502" BEFORE UPDATE ON "erp"."empresa_administradora_layout"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_db4bd68e70b38502"();

CREATE FUNCTION "erp"."fn_on_update_49e6df3c13d39d03"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_49e6df3c13d39d03" BEFORE UPDATE ON "erp"."eventos_condominio"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_49e6df3c13d39d03"();

CREATE FUNCTION "erp"."fn_on_update_190532caa16373f5"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_190532caa16373f5" BEFORE UPDATE ON "erp"."grupos_inventario"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_190532caa16373f5"();

CREATE FUNCTION "erp"."fn_on_update_da4b17752388f439"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_da4b17752388f439" BEFORE UPDATE ON "erp"."hidrometro_config"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_da4b17752388f439"();

CREATE FUNCTION "erp"."fn_on_update_a40b76190af5c900"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_a40b76190af5c900" BEFORE UPDATE ON "erp"."hidrometros"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_a40b76190af5c900"();

CREATE FUNCTION "erp"."fn_on_update_12dee6f959595d33"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_12dee6f959595d33" BEFORE UPDATE ON "erp"."inadimplencia_comparacoes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_12dee6f959595d33"();

CREATE FUNCTION "erp"."fn_on_update_85a237ef9137b743"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_85a237ef9137b743" BEFORE UPDATE ON "erp"."inadimplencia_importacoes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_85a237ef9137b743"();

CREATE FUNCTION "erp"."fn_on_update_8e6b9dfc0ff64c9a"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_8e6b9dfc0ff64c9a" BEFORE UPDATE ON "erp"."inventario"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_8e6b9dfc0ff64c9a"();

CREATE FUNCTION "erp"."fn_on_update_cf67ae7eac315f9d"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_cf67ae7eac315f9d" BEFORE UPDATE ON "erp"."local_acessos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_cf67ae7eac315f9d"();

CREATE FUNCTION "erp"."fn_on_update_08886b1b3a5588ba"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_08886b1b3a5588ba" BEFORE UPDATE ON "erp"."manual_artigos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_08886b1b3a5588ba"();

CREATE FUNCTION "erp"."fn_on_update_2d6069f3c8aa0be6"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_2d6069f3c8aa0be6" BEFORE UPDATE ON "erp"."monitoramento_cameras"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_2d6069f3c8aa0be6"();

CREATE FUNCTION "erp"."fn_on_update_de4c90e79db722c6"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_de4c90e79db722c6" BEFORE UPDATE ON "erp"."monitoramento_configuracoes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_de4c90e79db722c6"();

CREATE FUNCTION "erp"."fn_on_update_60faf312e1752580"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_60faf312e1752580" BEFORE UPDATE ON "erp"."monitoramento_dispositivos_local"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_60faf312e1752580"();

CREATE FUNCTION "erp"."fn_on_update_bd9b06bc8ec2fdc7"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_bd9b06bc8ec2fdc7" BEFORE UPDATE ON "erp"."monitoramento_eventos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_bd9b06bc8ec2fdc7"();

CREATE FUNCTION "erp"."fn_on_update_6f6242c89c2345d9"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['updated_at']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']::text[]) THEN
    IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN
      NEW."updated_at" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_6f6242c89c2345d9" BEFORE UPDATE ON "erp"."monitoring_releases"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_6f6242c89c2345d9"();

CREATE FUNCTION "erp"."fn_on_update_2d2fa063dc11bc6b"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_2d2fa063dc11bc6b" BEFORE UPDATE ON "erp"."movimentacoes_bancarias"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_2d2fa063dc11bc6b"();

CREATE FUNCTION "erp"."fn_on_update_a7aca21193568f60"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_a7aca21193568f60" BEFORE UPDATE ON "erp"."mt_protocolos_id_zero_backup"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_a7aca21193568f60"();

CREATE FUNCTION "erp"."fn_on_update_adccd86fb5893b2e"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_adccd86fb5893b2e" BEFORE UPDATE ON "erp"."notif_regras"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_adccd86fb5893b2e"();

CREATE FUNCTION "erp"."fn_on_update_af8e44f78cca9981"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_af8e44f78cca9981" BEFORE UPDATE ON "erp"."notificacao_canais_config"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_af8e44f78cca9981"();

CREATE FUNCTION "erp"."fn_on_update_6d9d17128f4147a8"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_6d9d17128f4147a8" BEFORE UPDATE ON "erp"."notificacoes_morador"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_6d9d17128f4147a8"();

CREATE FUNCTION "erp"."fn_on_update_a041a5196b2cc790"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_a041a5196b2cc790" BEFORE UPDATE ON "erp"."os_permissoes_departamento"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_a041a5196b2cc790"();

CREATE FUNCTION "erp"."fn_on_update_74001adb20bf5519"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_74001adb20bf5519" BEFORE UPDATE ON "erp"."os_usuarios_config"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_74001adb20bf5519"();

CREATE FUNCTION "erp"."fn_on_update_14ba60cd1269a9a9"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_14ba60cd1269a9a9" BEFORE UPDATE ON "erp"."planos_contas"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_14ba60cd1269a9a9"();

CREATE FUNCTION "erp"."fn_on_update_72386f0865daf2c5"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_72386f0865daf2c5" BEFORE UPDATE ON "erp"."produtos_estoque"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_72386f0865daf2c5"();

CREATE FUNCTION "erp"."fn_on_update_ddf8f28b6fda6976"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_ddf8f28b6fda6976" BEFORE UPDATE ON "erp"."produtos_servicos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_ddf8f28b6fda6976"();

CREATE FUNCTION "erp"."fn_on_update_496f1c459c5c3eff"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_496f1c459c5c3eff" BEFORE UPDATE ON "erp"."protocolos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_496f1c459c5c3eff"();

CREATE FUNCTION "erp"."fn_on_update_418b90c72e4e26d4"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_418b90c72e4e26d4" BEFORE UPDATE ON "erp"."pwa_configuracoes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_418b90c72e4e26d4"();

CREATE FUNCTION "erp"."fn_on_update_f0fea4217debdc1b"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_f0fea4217debdc1b" BEFORE UPDATE ON "erp"."ramos_atividade"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_f0fea4217debdc1b"();

CREATE FUNCTION "erp"."fn_on_update_aeeeab77e0cbe39c"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_aeeeab77e0cbe39c" BEFORE UPDATE ON "erp"."rbac_configuracoes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_aeeeab77e0cbe39c"();

CREATE FUNCTION "erp"."fn_on_update_f592f0c25d869585"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_f592f0c25d869585" BEFORE UPDATE ON "erp"."rbac_grupo_permissoes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_f592f0c25d869585"();

CREATE FUNCTION "erp"."fn_on_update_cfb0a5a8a061f70a"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_cfb0a5a8a061f70a" BEFORE UPDATE ON "erp"."rbac_grupos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_cfb0a5a8a061f70a"();

CREATE FUNCTION "erp"."fn_on_update_39c10f0c93eee1e3"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_39c10f0c93eee1e3" BEFORE UPDATE ON "erp"."rbac_modulos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_39c10f0c93eee1e3"();

CREATE FUNCTION "erp"."fn_on_update_869e4b7d386dc43d"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_869e4b7d386dc43d" BEFORE UPDATE ON "erp"."rbac_politicas_api"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_869e4b7d386dc43d"();

CREATE FUNCTION "erp"."fn_on_update_7ccbc37cd742fd1f"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_7ccbc37cd742fd1f" BEFORE UPDATE ON "erp"."rbac_usuario_permissoes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_7ccbc37cd742fd1f"();

CREATE FUNCTION "erp"."fn_on_update_851fe85f0b8abcb5"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_851fe85f0b8abcb5" BEFORE UPDATE ON "erp"."rbac_usuario_permissoes_bkp_ids_a_partir_15_20261027"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_851fe85f0b8abcb5"();

CREATE FUNCTION "erp"."fn_on_update_e0d7e02198bfb225"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['updated_at']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']::text[]) THEN
    IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN
      NEW."updated_at" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_e0d7e02198bfb225" BEFORE UPDATE ON "erp"."rh_escala"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_e0d7e02198bfb225"();

CREATE FUNCTION "erp"."fn_on_update_d73397073e7b411c"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['updated_at']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']::text[]) THEN
    IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN
      NEW."updated_at" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_d73397073e7b411c" BEFORE UPDATE ON "erp"."rh_ponto_lancamento"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_d73397073e7b411c"();

CREATE FUNCTION "erp"."fn_on_update_f5c3b6f82c372607"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['updated_at']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']::text[]) THEN
    IF NEW."updated_at" IS NOT DISTINCT FROM OLD."updated_at" THEN
      NEW."updated_at" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_f5c3b6f82c372607" BEFORE UPDATE ON "erp"."rh_ponto_periodo"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_f5c3b6f82c372607"();

CREATE FUNCTION "erp"."fn_on_update_1e3ea95f6dc84600"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_1e3ea95f6dc84600" BEFORE UPDATE ON "erp"."ronda_rotas"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_1e3ea95f6dc84600"();

CREATE FUNCTION "erp"."fn_on_update_36766ff637bd2073"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_36766ff637bd2073" BEFORE UPDATE ON "erp"."ronda_vigilantes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_36766ff637bd2073"();

CREATE FUNCTION "erp"."fn_on_update_231d4910c587098e"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['ultima_atividade']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['ultima_atividade']::text[]) THEN
    IF NEW."ultima_atividade" IS NOT DISTINCT FROM OLD."ultima_atividade" THEN
      NEW."ultima_atividade" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_231d4910c587098e" BEFORE UPDATE ON "erp"."sessoes_usuarios"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_231d4910c587098e"();

CREATE FUNCTION "erp"."fn_on_update_14a81a14cb7c486e"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['ultima_atividade']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['ultima_atividade']::text[]) THEN
    IF NEW."ultima_atividade" IS NOT DISTINCT FROM OLD."ultima_atividade" THEN
      NEW."ultima_atividade" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_14a81a14cb7c486e" BEFORE UPDATE ON "erp"."sessoes_usuarios_bkp_ids_a_partir_15_20261027"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_14a81a14cb7c486e"();

CREATE FUNCTION "erp"."fn_on_update_0e0f29ef2c3318b6"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_0e0f29ef2c3318b6" BEFORE UPDATE ON "erp"."tenants"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_0e0f29ef2c3318b6"();

CREATE FUNCTION "erp"."fn_on_update_d649c968e2e58bc2"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_d649c968e2e58bc2" BEFORE UPDATE ON "erp"."unidades"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_d649c968e2e58bc2"();

CREATE FUNCTION "erp"."fn_on_update_36e8fb173aa54247"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_36e8fb173aa54247" BEFORE UPDATE ON "erp"."usuario_modulos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_36e8fb173aa54247"();

CREATE FUNCTION "erp"."fn_on_update_beb3ccfea2183ef4"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['atualizado_em']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['atualizado_em']::text[]) THEN
    IF NEW."atualizado_em" IS NOT DISTINCT FROM OLD."atualizado_em" THEN
      NEW."atualizado_em" := date_trunc('second', LOCALTIMESTAMP);
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_beb3ccfea2183ef4" BEFORE UPDATE ON "erp"."usuario_modulos_bkp_ids_a_partir_15_20261027"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_beb3ccfea2183ef4"();

CREATE FUNCTION "erp"."fn_on_update_62e2f9888656031b"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_envio']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_envio']::text[]) THEN
    IF NEW."data_envio" IS NOT DISTINCT FROM OLD."data_envio" THEN
      NEW."data_envio" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_62e2f9888656031b" BEFORE UPDATE ON "erp"."v_emails_recentes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_62e2f9888656031b"();

CREATE FUNCTION "erp"."fn_on_update_80653edf96debe4e"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_80653edf96debe4e" BEFORE UPDATE ON "erp"."veiculos"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_80653edf96debe4e"();

CREATE FUNCTION "erp"."fn_on_update_494f48664e7acd88"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_atualizacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_atualizacao']::text[]) THEN
    IF NEW."data_atualizacao" IS NOT DISTINCT FROM OLD."data_atualizacao" THEN
      NEW."data_atualizacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_494f48664e7acd88" BEFORE UPDATE ON "erp"."visitantes"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_494f48664e7acd88"();

CREATE FUNCTION "erp"."fn_on_update_b28a7b3ae01c1be0"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['ultima_retirada']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['ultima_retirada']::text[]) THEN
    IF NEW."ultima_retirada" IS NOT DISTINCT FROM OLD."ultima_retirada" THEN
      NEW."ultima_retirada" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_b28a7b3ae01c1be0" BEFORE UPDATE ON "erp"."vw_consumo_por_morador"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_b28a7b3ae01c1be0"();

CREATE FUNCTION "erp"."fn_on_update_0f08b8e1c039025a"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_cadastro']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_cadastro']::text[]) THEN
    IF NEW."data_cadastro" IS NOT DISTINCT FROM OLD."data_cadastro" THEN
      NEW."data_cadastro" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_0f08b8e1c039025a" BEFORE UPDATE ON "erp"."vw_dependentes_completo"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_0f08b8e1c039025a"();

CREATE FUNCTION "erp"."fn_on_update_99b36ae84c7ba067"() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF (to_jsonb(NEW) - ARRAY['data_movimentacao']::text[]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['data_movimentacao']::text[]) THEN
    IF NEW."data_movimentacao" IS NOT DISTINCT FROM OLD."data_movimentacao" THEN
      NEW."data_movimentacao" := date_trunc('second', timezone('America/Sao_Paulo', statement_timestamp()));
    END IF;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_on_update_99b36ae84c7ba067" BEFORE UPDATE ON "erp"."vw_movimentacoes_detalhadas"
FOR EACH ROW EXECUTE FUNCTION "erp"."fn_on_update_99b36ae84c7ba067"();
