-- REVIEW ARTIFACT: DO NOT APPLY until report.json has executable_complete=true and required reviews are approved.
-- Generator code SHA-256: 0b2f9699cfb98e68c177bfd0eef361ddc8b8632b4b51ddf679e63b378cda323b
-- Collation overrides SHA-256: 44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a

-- Generator version: 1.4.0

-- Catalog SHA-256: 9112484a2fd7ed128a51f4b39e167f6e025d6560ab78db24d341fc52e666c7f4

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'abastecimento_lancamentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."abastecimento_lancamentos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."abastecimento_lancamentos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."abastecimento_lancamentos";
  v_next := GREATEST(COALESCE(v_max, 0), 41);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'abastecimento_lancamentos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'abastecimento_recargas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."abastecimento_recargas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."abastecimento_recargas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."abastecimento_recargas";
  v_next := GREATEST(COALESCE(v_max, 0), 8);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'abastecimento_recargas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'abastecimento_saldo.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."abastecimento_saldo" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."abastecimento_saldo"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."abastecimento_saldo";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'abastecimento_saldo.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'abastecimento_veiculos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."abastecimento_veiculos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."abastecimento_veiculos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."abastecimento_veiculos";
  v_next := GREATEST(COALESCE(v_max, 0), 2);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'abastecimento_veiculos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'administradoras_importacao.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."administradoras_importacao" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."administradoras_importacao"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."administradoras_importacao";
  v_next := GREATEST(COALESCE(v_max, 0), 4);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'administradoras_importacao.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'administradoras_layouts.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."administradoras_layouts" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."administradoras_layouts"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."administradoras_layouts";
  v_next := GREATEST(COALESCE(v_max, 0), 10);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'administradoras_layouts.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_acesso.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_acesso" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."alertas_acesso"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."alertas_acesso";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_acesso.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_acesso_criterios.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_acesso_criterios" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."alertas_acesso_criterios"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."alertas_acesso_criterios";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_acesso_criterios.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_acesso_entregas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_acesso_entregas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."alertas_acesso_entregas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."alertas_acesso_entregas";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_acesso_entregas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_acesso_eventos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_acesso_eventos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."alertas_acesso_eventos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."alertas_acesso_eventos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_acesso_eventos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_acesso_leituras.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_acesso_leituras" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."alertas_acesso_leituras"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."alertas_acesso_leituras";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_acesso_leituras.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_estoque.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_estoque" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."alertas_estoque"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."alertas_estoque";
  v_next := GREATEST(COALESCE(v_max, 0), 40);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_estoque.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_unidade_acionamentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_unidade_acionamentos" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."alertas_unidade_acionamentos";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_unidade_acionamentos.id';
END
$erp_numeric_identity$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_unidade_alvos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_unidade_alvos" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."alertas_unidade_alvos";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_unidade_alvos.id';
END
$erp_numeric_identity$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_unidade_ciencias.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_unidade_ciencias" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."alertas_unidade_ciencias";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_unidade_ciencias.id';
END
$erp_numeric_identity$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_unidade_historico.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_unidade_historico" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 2) INTO v_current FROM "erp"."alertas_unidade_historico";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_unidade_historico.id';
END
$erp_numeric_identity$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_unidade_lembretes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_unidade_lembretes" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."alertas_unidade_lembretes";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_unidade_lembretes.id';
END
$erp_numeric_identity$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_unidade_notificacoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_unidade_notificacoes" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."alertas_unidade_notificacoes";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_unidade_notificacoes.id';
END
$erp_numeric_identity$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'alertas_unidade_rate_limits.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."alertas_unidade_rate_limits" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 3148) INTO v_current FROM "erp"."alertas_unidade_rate_limits";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'alertas_unidade_rate_limits.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'aplicativos_catalogo.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."aplicativos_catalogo" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."aplicativos_catalogo"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."aplicativos_catalogo";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'aplicativos_catalogo.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'aplicativos_versionamento_log.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."aplicativos_versionamento_log" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."aplicativos_versionamento_log"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."aplicativos_versionamento_log";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'aplicativos_versionamento_log.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'aplicativos_versoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."aplicativos_versoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."aplicativos_versoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."aplicativos_versoes";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'aplicativos_versoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'assembleias.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."assembleias" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."assembleias"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."assembleias";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'assembleias.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'avaliacoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."avaliacoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."avaliacoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."avaliacoes";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'avaliacoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'avaliacoes_backup.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."avaliacoes_backup" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."avaliacoes_backup"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."avaliacoes_backup";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'avaliacoes_backup.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'bancos_brasileiros.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."bancos_brasileiros" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."bancos_brasileiros"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."bancos_brasileiros";
  v_next := GREATEST(COALESCE(v_max, 0), 332);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'bancos_brasileiros.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'bridge_eventos_log.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."bridge_eventos_log" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."bridge_eventos_log"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."bridge_eventos_log";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'bridge_eventos_log.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'bridge_fila_comandos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."bridge_fila_comandos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."bridge_fila_comandos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."bridge_fila_comandos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'bridge_fila_comandos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'bridge_status.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."bridge_status" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."bridge_status"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."bridge_status";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'bridge_status.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'categorias_estoque.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."categorias_estoque" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."categorias_estoque"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."categorias_estoque";
  v_next := GREATEST(COALESCE(v_max, 0), 8);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'categorias_estoque.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'chatin_conversas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."chatin_conversas" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 6) INTO v_current FROM "erp"."chatin_conversas";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'chatin_conversas.id';
END
$erp_numeric_identity$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'chatin_mensagens.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."chatin_mensagens" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 33) INTO v_current FROM "erp"."chatin_mensagens";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'chatin_mensagens.id';
END
$erp_numeric_identity$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'chatin_participantes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."chatin_participantes" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 12) INTO v_current FROM "erp"."chatin_participantes";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'chatin_participantes.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'checklist_alertas_config.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."checklist_alertas_config" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."checklist_alertas_config"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."checklist_alertas_config";
  v_next := GREATEST(COALESCE(v_max, 0), 10);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'checklist_alertas_config.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'checklist_alertas_gerados.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."checklist_alertas_gerados" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."checklist_alertas_gerados"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."checklist_alertas_gerados";
  v_next := GREATEST(COALESCE(v_max, 0), 20);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'checklist_alertas_gerados.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'checklist_itens.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."checklist_itens" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."checklist_itens"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."checklist_itens";
  v_next := GREATEST(COALESCE(v_max, 0), 600);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'checklist_itens.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'checklist_km_acumulado.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."checklist_km_acumulado" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."checklist_km_acumulado"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."checklist_km_acumulado";
  v_next := GREATEST(COALESCE(v_max, 0), 26);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'checklist_km_acumulado.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'checklist_veicular.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."checklist_veicular" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."checklist_veicular"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."checklist_veicular";
  v_next := GREATEST(COALESCE(v_max, 0), 50);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'checklist_veicular.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'conciliacoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."conciliacoes" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."conciliacoes";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'conciliacoes.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'config_moradores_campos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."config_moradores_campos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."config_moradores_campos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."config_moradores_campos";
  v_next := GREATEST(COALESCE(v_max, 0), 16);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'config_moradores_campos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'config_moradores_opcoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."config_moradores_opcoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."config_moradores_opcoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."config_moradores_opcoes";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'config_moradores_opcoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'config_periodo_leitura.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."config_periodo_leitura" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."config_periodo_leitura"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."config_periodo_leitura";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'config_periodo_leitura.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'config_sessao.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."config_sessao" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."config_sessao"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."config_sessao";
  v_next := GREATEST(COALESCE(v_max, 0), 3);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'config_sessao.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'config_visitantes_campos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."config_visitantes_campos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."config_visitantes_campos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."config_visitantes_campos";
  v_next := GREATEST(COALESCE(v_max, 0), 10);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'config_visitantes_campos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'configuracoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."configuracoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."configuracoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."configuracoes";
  v_next := GREATEST(COALESCE(v_max, 0), 10);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'configuracoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'contas_bancarias.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."contas_bancarias" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."contas_bancarias"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."contas_bancarias";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'contas_bancarias.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'contas_pagar.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."contas_pagar" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."contas_pagar"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."contas_pagar";
  v_next := GREATEST(COALESCE(v_max, 0), 15);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'contas_pagar.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'contas_receber.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."contas_receber" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."contas_receber"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."contas_receber";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'contas_receber.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'contrato_aditivos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."contrato_aditivos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."contrato_aditivos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."contrato_aditivos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'contrato_aditivos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'contrato_documentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."contrato_documentos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."contrato_documentos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."contrato_documentos";
  v_next := GREATEST(COALESCE(v_max, 0), 36);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'contrato_documentos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'contrato_orcamento_documentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."contrato_orcamento_documentos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."contrato_orcamento_documentos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."contrato_orcamento_documentos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'contrato_orcamento_documentos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'contrato_orcamentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."contrato_orcamentos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."contrato_orcamentos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."contrato_orcamentos";
  v_next := GREATEST(COALESCE(v_max, 0), 39);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'contrato_orcamentos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'contratos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."contratos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."contratos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."contratos";
  v_next := GREATEST(COALESCE(v_max, 0), 15);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'contratos.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'controlid_eventos_acesso.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."controlid_eventos_acesso" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."controlid_eventos_acesso";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'controlid_eventos_acesso.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'controlid_fila_comandos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."controlid_fila_comandos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."controlid_fila_comandos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."controlid_fila_comandos";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'controlid_fila_comandos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'controlid_push_queue.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."controlid_push_queue" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."controlid_push_queue"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."controlid_push_queue";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'controlid_push_queue.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'convidados.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."convidados" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."convidados"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."convidados";
  v_next := GREATEST(COALESCE(v_max, 0), 2);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'convidados.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'crm_interacoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."crm_interacoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."crm_interacoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."crm_interacoes";
  v_next := GREATEST(COALESCE(v_max, 0), 14);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'crm_interacoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'crm_relacionamentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."crm_relacionamentos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."crm_relacionamentos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."crm_relacionamentos";
  v_next := GREATEST(COALESCE(v_max, 0), 3);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'crm_relacionamentos.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'dashboard_empresa_widgets.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."dashboard_empresa_widgets" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."dashboard_empresa_widgets";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'dashboard_empresa_widgets.id';
END
$erp_numeric_identity$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'dashboard_usuario_widgets.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."dashboard_usuario_widgets" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."dashboard_usuario_widgets";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'dashboard_usuario_widgets.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'dashboard_widgets_catalogo.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."dashboard_widgets_catalogo" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."dashboard_widgets_catalogo"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."dashboard_widgets_catalogo";
  v_next := GREATEST(COALESCE(v_max, 0), 16);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'dashboard_widgets_catalogo.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'departamentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."departamentos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."departamentos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."departamentos";
  v_next := GREATEST(COALESCE(v_max, 0), 9);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'departamentos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'dependentes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."dependentes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."dependentes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."dependentes";
  v_next := GREATEST(COALESCE(v_max, 0), 232);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'dependentes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'dispositivos_controlid_leituras.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."dispositivos_controlid_leituras" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."dispositivos_controlid_leituras"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."dispositivos_controlid_leituras";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'dispositivos_controlid_leituras.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'dispositivos_controlid_sync_log.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."dispositivos_controlid_sync_log" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."dispositivos_controlid_sync_log"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."dispositivos_controlid_sync_log";
  v_next := GREATEST(COALESCE(v_max, 0), 18);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'dispositivos_controlid_sync_log.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'documentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."documentos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."documentos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."documentos";
  v_next := GREATEST(COALESCE(v_max, 0), 12);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'documentos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'documentos_departamentos_migrado_bkp.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."documentos_departamentos_migrado_bkp" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."documentos_departamentos_migrado_bkp"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."documentos_departamentos_migrado_bkp";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'documentos_departamentos_migrado_bkp.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'documentos_grupos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."documentos_grupos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."documentos_grupos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."documentos_grupos";
  v_next := GREATEST(COALESCE(v_max, 0), 3);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'documentos_grupos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'documentos_grupos_moradores.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."documentos_grupos_moradores" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."documentos_grupos_moradores"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."documentos_grupos_moradores";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'documentos_grupos_moradores.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'documentos_grupos_usuarios.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."documentos_grupos_usuarios" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."documentos_grupos_usuarios"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."documentos_grupos_usuarios";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'documentos_grupos_usuarios.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'documentos_logs.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."documentos_logs" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."documentos_logs"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."documentos_logs";
  v_next := GREATEST(COALESCE(v_max, 0), 27);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'documentos_logs.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'documentos_pastas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."documentos_pastas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."documentos_pastas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."documentos_pastas";
  v_next := GREATEST(COALESCE(v_max, 0), 9);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'documentos_pastas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'documentos_tipos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."documentos_tipos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."documentos_tipos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."documentos_tipos";
  v_next := GREATEST(COALESCE(v_max, 0), 10);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'documentos_tipos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'documentos_usuarios_acesso.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."documentos_usuarios_acesso" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."documentos_usuarios_acesso"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."documentos_usuarios_acesso";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'documentos_usuarios_acesso.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'email_alertas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."email_alertas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."email_alertas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."email_alertas";
  v_next := GREATEST(COALESCE(v_max, 0), 135);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'email_alertas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'email_delivery_logs.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."email_delivery_logs" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."email_delivery_logs"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."email_delivery_logs";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'email_delivery_logs.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'email_log.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."email_log" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."email_log"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."email_log";
  v_next := GREATEST(COALESCE(v_max, 0), 594);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'email_log.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'email_templates.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."email_templates" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."email_templates"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."email_templates";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'email_templates.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'empresa.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."empresa" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."empresa"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."empresa";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'empresa.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'empresa_administradora.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."empresa_administradora" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."empresa_administradora"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."empresa_administradora";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'empresa_administradora.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'empresa_administradora_layout.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."empresa_administradora_layout" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."empresa_administradora_layout"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."empresa_administradora_layout";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'empresa_administradora_layout.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'eventos_condominio.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."eventos_condominio" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."eventos_condominio"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."eventos_condominio";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'eventos_condominio.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'grupos_inventario.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."grupos_inventario" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."grupos_inventario"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."grupos_inventario";
  v_next := GREATEST(COALESCE(v_max, 0), 11);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'grupos_inventario.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'hidrometro.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."hidrometro" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."hidrometro"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."hidrometro";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'hidrometro.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'hidrometros.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."hidrometros" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."hidrometros"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."hidrometros";
  v_next := GREATEST(COALESCE(v_max, 0), 145);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'hidrometros.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'hidrometros_historico.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."hidrometros_historico" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."hidrometros_historico"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."hidrometros_historico";
  v_next := GREATEST(COALESCE(v_max, 0), 64);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'hidrometros_historico.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'historico_importacoes_ofx.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."historico_importacoes_ofx" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."historico_importacoes_ofx"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."historico_importacoes_ofx";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'historico_importacoes_ofx.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'historico_pagamentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."historico_pagamentos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."historico_pagamentos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."historico_pagamentos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'historico_pagamentos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'historico_status_pedido.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."historico_status_pedido" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."historico_status_pedido"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."historico_status_pedido";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'historico_status_pedido.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'importacoes_financeiras.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."importacoes_financeiras" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."importacoes_financeiras"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."importacoes_financeiras";
  v_next := GREATEST(COALESCE(v_max, 0), 2);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'importacoes_financeiras.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'importacoes_financeiras_itens.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."importacoes_financeiras_itens" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."importacoes_financeiras_itens"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."importacoes_financeiras_itens";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'importacoes_financeiras_itens.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'inadimplencia_comparacoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."inadimplencia_comparacoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."inadimplencia_comparacoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."inadimplencia_comparacoes";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'inadimplencia_comparacoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'inadimplencia_importacoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."inadimplencia_importacoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."inadimplencia_importacoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."inadimplencia_importacoes";
  v_next := GREATEST(COALESCE(v_max, 0), 5);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'inadimplencia_importacoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'inadimplencia_lancamentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."inadimplencia_lancamentos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."inadimplencia_lancamentos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."inadimplencia_lancamentos";
  v_next := GREATEST(COALESCE(v_max, 0), 2306);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'inadimplencia_lancamentos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'inventario.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."inventario" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."inventario"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."inventario";
  v_next := GREATEST(COALESCE(v_max, 0), 213);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'inventario.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'leituras.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."leituras" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."leituras"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."leituras";
  v_next := GREATEST(COALESCE(v_max, 0), 1452);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'leituras.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'local_acessos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."local_acessos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."local_acessos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."local_acessos";
  v_next := GREATEST(COALESCE(v_max, 0), 4);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'local_acessos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'local_acessos_log.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."local_acessos_log" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."local_acessos_log"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."local_acessos_log";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'local_acessos_log.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'local_acessos_tipos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."local_acessos_tipos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."local_acessos_tipos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."local_acessos_tipos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'local_acessos_tipos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'logs_erro.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."logs_erro" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."logs_erro"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."logs_erro";
  v_next := GREATEST(COALESCE(v_max, 0), 6);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'logs_erro.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'logs_financeiro.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."logs_financeiro" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."logs_financeiro"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."logs_financeiro";
  v_next := GREATEST(COALESCE(v_max, 0), 15);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'logs_financeiro.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'manual_artigos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."manual_artigos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."manual_artigos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."manual_artigos";
  v_next := GREATEST(COALESCE(v_max, 0), 23);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'manual_artigos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'manual_avaliacoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."manual_avaliacoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."manual_avaliacoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."manual_avaliacoes";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'manual_avaliacoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'manual_buscas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."manual_buscas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."manual_buscas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."manual_buscas";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'manual_buscas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'manual_categorias.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."manual_categorias" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."manual_categorias"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."manual_categorias";
  v_next := GREATEST(COALESCE(v_max, 0), 22);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'manual_categorias.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'manual_historico.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."manual_historico" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."manual_historico"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."manual_historico";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'manual_historico.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'manual_modulos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."manual_modulos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."manual_modulos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."manual_modulos";
  v_next := GREATEST(COALESCE(v_max, 0), 15);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'manual_modulos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'marcas_dispositivo.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."marcas_dispositivo" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."marcas_dispositivo"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."marcas_dispositivo";
  v_next := GREATEST(COALESCE(v_max, 0), 15);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'marcas_dispositivo.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'modelos_dispositivo.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."modelos_dispositivo" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."modelos_dispositivo"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."modelos_dispositivo";
  v_next := GREATEST(COALESCE(v_max, 0), 19);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'modelos_dispositivo.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'modulos_sistema.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."modulos_sistema" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."modulos_sistema"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."modulos_sistema";
  v_next := GREATEST(COALESCE(v_max, 0), 41);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'modulos_sistema.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'monitoramento_cameras.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."monitoramento_cameras" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."monitoramento_cameras"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."monitoramento_cameras";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'monitoramento_cameras.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'monitoramento_configuracoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."monitoramento_configuracoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."monitoramento_configuracoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."monitoramento_configuracoes";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'monitoramento_configuracoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'monitoramento_dispositivos_local.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."monitoramento_dispositivos_local" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."monitoramento_dispositivos_local"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."monitoramento_dispositivos_local";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'monitoramento_dispositivos_local.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'monitoramento_evento_acesso.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."monitoramento_evento_acesso" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."monitoramento_evento_acesso"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."monitoramento_evento_acesso";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'monitoramento_evento_acesso.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'monitoramento_evento_revisoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."monitoramento_evento_revisoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."monitoramento_evento_revisoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."monitoramento_evento_revisoes";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'monitoramento_evento_revisoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'monitoramento_eventos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."monitoramento_eventos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."monitoramento_eventos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."monitoramento_eventos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'monitoramento_eventos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'monitoring_releases.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."monitoring_releases" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."monitoring_releases"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."monitoring_releases";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'monitoring_releases.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'monitoring_update_log.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."monitoring_update_log" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."monitoring_update_log"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."monitoring_update_log";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'monitoring_update_log.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'movimentacoes_bancarias.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."movimentacoes_bancarias" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."movimentacoes_bancarias";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'movimentacoes_bancarias.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'movimentacoes_estoque.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."movimentacoes_estoque" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."movimentacoes_estoque"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."movimentacoes_estoque";
  v_next := GREATEST(COALESCE(v_max, 0), 249);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'movimentacoes_estoque.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'mt_consolidacao_exclusoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."mt_consolidacao_exclusoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."mt_consolidacao_exclusoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."mt_consolidacao_exclusoes";
  v_next := GREATEST(COALESCE(v_max, 0), 17);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'mt_consolidacao_exclusoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'mt_consolidacao_tabelas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."mt_consolidacao_tabelas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."mt_consolidacao_tabelas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."mt_consolidacao_tabelas";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'mt_consolidacao_tabelas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'mt_integridade_ids_log.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."mt_integridade_ids_log" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."mt_integridade_ids_log"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."mt_integridade_ids_log";
  v_next := GREATEST(COALESCE(v_max, 0), 167);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'mt_integridade_ids_log.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'mt_os_integridade_log.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."mt_os_integridade_log" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."mt_os_integridade_log"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."mt_os_integridade_log";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'mt_os_integridade_log.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'mt_reconstrucao_exclusoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."mt_reconstrucao_exclusoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."mt_reconstrucao_exclusoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."mt_reconstrucao_exclusoes";
  v_next := GREATEST(COALESCE(v_max, 0), 16);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'mt_reconstrucao_exclusoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'mt_reconstrucao_tabelas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."mt_reconstrucao_tabelas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."mt_reconstrucao_tabelas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."mt_reconstrucao_tabelas";
  v_next := GREATEST(COALESCE(v_max, 0), 414);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'mt_reconstrucao_tabelas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'notif_regras.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."notif_regras" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."notif_regras"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."notif_regras";
  v_next := GREATEST(COALESCE(v_max, 0), 6);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'notif_regras.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'notificacoes_downloads.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."notificacoes_downloads" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."notificacoes_downloads"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."notificacoes_downloads";
  v_next := GREATEST(COALESCE(v_max, 0), 12);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'notificacoes_downloads.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'notificacoes_morador.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."notificacoes_morador" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."notificacoes_morador"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."notificacoes_morador";
  v_next := GREATEST(COALESCE(v_max, 0), 670);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'notificacoes_morador.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'notificacoes_visualizacoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."notificacoes_visualizacoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."notificacoes_visualizacoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."notificacoes_visualizacoes";
  v_next := GREATEST(COALESCE(v_max, 0), 4);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'notificacoes_visualizacoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_assuntos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_assuntos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."os_assuntos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."os_assuntos";
  v_next := GREATEST(COALESCE(v_max, 0), 24);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_assuntos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_chamados.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_chamados" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."os_chamados"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."os_chamados";
  v_next := GREATEST(COALESCE(v_max, 0), 614);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_chamados.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_config_homem_hora.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_config_homem_hora" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."os_config_homem_hora"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."os_config_homem_hora";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_config_homem_hora.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_etapas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_etapas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."os_etapas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."os_etapas";
  v_next := GREATEST(COALESCE(v_max, 0), 17);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_etapas.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_integridade_interacoes_log.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_integridade_interacoes_log" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 2) INTO v_current FROM "erp"."os_integridade_interacoes_log";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_integridade_interacoes_log.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_interacao_fotos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_interacao_fotos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."os_interacao_fotos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."os_interacao_fotos";
  v_next := GREATEST(COALESCE(v_max, 0), 79);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_interacao_fotos.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_interacao_fotos_quarentena.backup_id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_interacao_fotos_quarentena" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("backup_id"), 0), 3) INTO v_current FROM "erp"."os_interacao_fotos_quarentena";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_interacao_fotos_quarentena.backup_id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_interacoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_interacoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."os_interacoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."os_interacoes";
  v_next := GREATEST(COALESCE(v_max, 0), 2689);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_interacoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_materiais_usados.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_materiais_usados" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."os_materiais_usados"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."os_materiais_usados";
  v_next := GREATEST(COALESCE(v_max, 0), 30);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_materiais_usados.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_permissoes_departamento.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_permissoes_departamento" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."os_permissoes_departamento"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."os_permissoes_departamento";
  v_next := GREATEST(COALESCE(v_max, 0), 9);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_permissoes_departamento.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_recursos_humanos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_recursos_humanos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."os_recursos_humanos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."os_recursos_humanos";
  v_next := GREATEST(COALESCE(v_max, 0), 227);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_recursos_humanos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'os_usuarios_config.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."os_usuarios_config" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."os_usuarios_config"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."os_usuarios_config";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'os_usuarios_config.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'pedidos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."pedidos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."pedidos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."pedidos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'pedidos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'planos_contas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."planos_contas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."planos_contas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."planos_contas";
  v_next := GREATEST(COALESCE(v_max, 0), 15);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'planos_contas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'produtos_estoque.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."produtos_estoque" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."produtos_estoque"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."produtos_estoque";
  v_next := GREATEST(COALESCE(v_max, 0), 292);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'produtos_estoque.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'produtos_servicos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."produtos_servicos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."produtos_servicos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."produtos_servicos";
  v_next := GREATEST(COALESCE(v_max, 0), 3);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'produtos_servicos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'protocolos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."protocolos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."protocolos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."protocolos";
  v_next := GREATEST(COALESCE(v_max, 0), 1074);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'protocolos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'publico_rate_limit.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."publico_rate_limit" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."publico_rate_limit"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."publico_rate_limit";
  v_next := GREATEST(COALESCE(v_max, 0), 295);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'publico_rate_limit.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'pwa_logs.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."pwa_logs" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."pwa_logs"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."pwa_logs";
  v_next := GREATEST(COALESCE(v_max, 0), 7);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'pwa_logs.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'pwa_notificacoes_push.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."pwa_notificacoes_push" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."pwa_notificacoes_push"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."pwa_notificacoes_push";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'pwa_notificacoes_push.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'pwa_notificacoes_recebidas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."pwa_notificacoes_recebidas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."pwa_notificacoes_recebidas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."pwa_notificacoes_recebidas";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'pwa_notificacoes_recebidas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'pwa_versao.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."pwa_versao" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."pwa_versao"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."pwa_versao";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'pwa_versao.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'ramos_atividade.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."ramos_atividade" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."ramos_atividade"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."ramos_atividade";
  v_next := GREATEST(COALESCE(v_max, 0), 57);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'ramos_atividade.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rbac_grupo_permissoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rbac_grupo_permissoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rbac_grupo_permissoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rbac_grupo_permissoes";
  v_next := GREATEST(COALESCE(v_max, 0), 1014);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rbac_grupo_permissoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rbac_grupos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rbac_grupos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rbac_grupos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rbac_grupos";
  v_next := GREATEST(COALESCE(v_max, 0), 5);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rbac_grupos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rbac_modulos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rbac_modulos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rbac_modulos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rbac_modulos";
  v_next := GREATEST(COALESCE(v_max, 0), 59);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rbac_modulos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rbac_permissoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rbac_permissoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rbac_permissoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rbac_permissoes";
  v_next := GREATEST(COALESCE(v_max, 0), 238);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rbac_permissoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rbac_politicas_api.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rbac_politicas_api" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rbac_politicas_api"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rbac_politicas_api";
  v_next := GREATEST(COALESCE(v_max, 0), 23);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rbac_politicas_api.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rbac_usuario_grupos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rbac_usuario_grupos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rbac_usuario_grupos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rbac_usuario_grupos";
  v_next := GREATEST(COALESCE(v_max, 0), 46);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rbac_usuario_grupos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rbac_usuario_permissoes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rbac_usuario_permissoes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rbac_usuario_permissoes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rbac_usuario_permissoes";
  v_next := GREATEST(COALESCE(v_max, 0), 298);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rbac_usuario_permissoes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'recebedores.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."recebedores" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."recebedores"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."recebedores";
  v_next := GREATEST(COALESCE(v_max, 0), 4);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'recebedores.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rh_banco_horas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rh_banco_horas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rh_banco_horas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rh_banco_horas";
  v_next := GREATEST(COALESCE(v_max, 0), 519);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rh_banco_horas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rh_escala.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rh_escala" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rh_escala"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rh_escala";
  v_next := GREATEST(COALESCE(v_max, 0), 18);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rh_escala.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rh_ponto_lancamento.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rh_ponto_lancamento" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rh_ponto_lancamento"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rh_ponto_lancamento";
  v_next := GREATEST(COALESCE(v_max, 0), 1162);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rh_ponto_lancamento.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'rh_ponto_periodo.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."rh_ponto_periodo" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."rh_ponto_periodo"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."rh_ponto_periodo";
  v_next := GREATEST(COALESCE(v_max, 0), 38);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'rh_ponto_periodo.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'ronda_auditoria.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."ronda_auditoria" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 17) INTO v_current FROM "erp"."ronda_auditoria";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'ronda_auditoria.id';
END
$erp_numeric_identity$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'ronda_registros.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."ronda_registros" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."ronda_registros";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'ronda_registros.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'ronda_rotas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."ronda_rotas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."ronda_rotas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."ronda_rotas";
  v_next := GREATEST(COALESCE(v_max, 0), 4);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'ronda_rotas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'ronda_vigilantes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."ronda_vigilantes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."ronda_vigilantes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."ronda_vigilantes";
  v_next := GREATEST(COALESCE(v_max, 0), 2);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'ronda_vigilantes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'sessoes_usuarios.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."sessoes_usuarios" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."sessoes_usuarios"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."sessoes_usuarios";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'sessoes_usuarios.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'tenant_arquivo_referencias.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."tenant_arquivo_referencias" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 5) INTO v_current FROM "erp"."tenant_arquivo_referencias";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'tenant_arquivo_referencias.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'tenants.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."tenants" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."tenants"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."tenants";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'tenants.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'tipos_dispositivo.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."tipos_dispositivo" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."tipos_dispositivo"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."tipos_dispositivo";
  v_next := GREATEST(COALESCE(v_max, 0), 15);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'tipos_dispositivo.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'unidades.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."unidades" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."unidades"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."unidades";
  v_next := GREATEST(COALESCE(v_max, 0), 195);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'unidades.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'usuario_modulos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."usuario_modulos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."usuario_modulos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."usuario_modulos";
  v_next := GREATEST(COALESCE(v_max, 0), 1);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'usuario_modulos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'usuario_tenant.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."usuario_tenant" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."usuario_tenant"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."usuario_tenant";
  v_next := GREATEST(COALESCE(v_max, 0), 61);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'usuario_tenant.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'v_emails_recentes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."v_emails_recentes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."v_emails_recentes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."v_emails_recentes";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'v_emails_recentes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'validacoes_face_id.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."validacoes_face_id" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."validacoes_face_id"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."validacoes_face_id";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'validacoes_face_id.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'veiculos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."veiculos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."veiculos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."veiculos";
  v_next := GREATEST(COALESCE(v_max, 0), 305);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'veiculos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'visitantes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."visitantes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."visitantes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."visitantes";
  v_next := GREATEST(COALESCE(v_max, 0), 4137);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'visitantes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_alertas_pendentes.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_alertas_pendentes" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."vw_alertas_pendentes"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."vw_alertas_pendentes";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_alertas_pendentes.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_checklist_completo.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_checklist_completo" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."vw_checklist_completo"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."vw_checklist_completo";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_checklist_completo.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_consumo_veiculos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_consumo_veiculos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."vw_consumo_veiculos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."vw_consumo_veiculos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_consumo_veiculos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_dependentes_ativos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_dependentes_ativos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."vw_dependentes_ativos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."vw_dependentes_ativos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_dependentes_ativos.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_dependentes_completo.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_dependentes_completo" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."vw_dependentes_completo"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."vw_dependentes_completo";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_dependentes_completo.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_extrato_bancario.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_extrato_bancario" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."vw_extrato_bancario";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_extrato_bancario.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_movimentacoes_detalhadas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_movimentacoes_detalhadas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."vw_movimentacoes_detalhadas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."vw_movimentacoes_detalhadas";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_movimentacoes_detalhadas.id';
END
$erp_sequence$;

DO $erp_numeric_identity$
DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;
BEGIN
  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_pendencias_conciliacao.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_pendencias_conciliacao" IN ACCESS EXCLUSIVE MODE;
  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT GREATEST(COALESCE(MAX("id"), 0), 0) INTO v_current FROM "erp"."vw_pendencias_conciliacao";
  IF v_current <= 9223372036854775807 THEN
    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;
    PERFORM pg_catalog.setval(v_high_sequence, 0, false);
  ELSE
    PERFORM pg_catalog.setval(v_low_sequence, 9223372036854775807, true);
    PERFORM pg_catalog.setval(v_high_sequence, (v_current - 9223372036854775808)::bigint, true);
  END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_pendencias_conciliacao.id';
END
$erp_numeric_identity$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_produtos_estoque_baixo.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_produtos_estoque_baixo" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."vw_produtos_estoque_baixo"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."vw_produtos_estoque_baixo";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_produtos_estoque_baixo.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_saldo_contas.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_saldo_contas" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."vw_saldo_contas"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."vw_saldo_contas";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_saldo_contas.id';
END
$erp_sequence$;

DO $erp_sequence$
DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;
BEGIN
  SELECT post_load_sync_complete INTO v_synced FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = 'vw_ultimos_abastecimentos.id' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;
  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;
  LOCK TABLE "erp"."vw_ultimos_abastecimentos" IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_serial_sequence('"erp"."vw_ultimos_abastecimentos"', 'id') INTO v_sequence;
  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;
  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;
  SELECT MAX("id") INTO v_max FROM "erp"."vw_ultimos_abastecimentos";
  v_next := GREATEST(COALESCE(v_max, 0), 0);
  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);
  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;
  UPDATE "erp"."_p2_autoincrement_state" SET post_load_sync_complete = TRUE WHERE sequence_name = 'vw_ultimos_abastecimentos.id';
END
$erp_sequence$;
