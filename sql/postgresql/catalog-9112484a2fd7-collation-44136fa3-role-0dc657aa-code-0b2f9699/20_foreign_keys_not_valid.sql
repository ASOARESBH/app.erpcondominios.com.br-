-- REVIEW ARTIFACT: DO NOT APPLY until report.json has executable_complete=true and required reviews are approved.
-- Generator code SHA-256: 0b2f9699cfb98e68c177bfd0eef361ddc8b8632b4b51ddf679e63b378cda323b
-- Collation overrides SHA-256: 44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a

-- Generator version: 1.4.0

-- Catalog SHA-256: 9112484a2fd7ed128a51f4b39e167f6e025d6560ab78db24d341fc52e666c7f4

SET search_path TO "erp", pg_catalog;

ALTER TABLE "erp"."aplicativos_versoes" ADD CONSTRAINT "fk_aplicativos_versoes_fk_aplicativos_versoes_aplicativo" FOREIGN KEY ("aplicativo_id") REFERENCES "erp"."aplicativos_catalogo" ("id") ON UPDATE CASCADE ON DELETE CASCADE NOT VALID;

ALTER TABLE "erp"."dashboard_empresa_widgets" ADD CONSTRAINT "fk_dashboard_empresa_widgets_fk_dashboard_empresa__db6874f22b64" FOREIGN KEY ("widget_key") REFERENCES "erp"."dashboard_widgets_catalogo" ("widget_key") ON UPDATE NO ACTION ON DELETE CASCADE NOT VALID;

ALTER TABLE "erp"."dashboard_usuario_widgets" ADD CONSTRAINT "fk_dashboard_usuario_widgets_fk_dashboard_usuario__6e443e0eb95a" FOREIGN KEY ("widget_key") REFERENCES "erp"."dashboard_widgets_catalogo" ("widget_key") ON UPDATE NO ACTION ON DELETE CASCADE NOT VALID;

ALTER TABLE "erp"."rbac_grupo_permissoes" ADD CONSTRAINT "fk_rbac_grupo_permissoes_fk_rbac_gp_grupo" FOREIGN KEY ("grupo_id") REFERENCES "erp"."rbac_grupos" ("id") ON UPDATE CASCADE ON DELETE CASCADE NOT VALID;

ALTER TABLE "erp"."rbac_grupo_permissoes" ADD CONSTRAINT "fk_rbac_grupo_permissoes_fk_rbac_gp_permissao" FOREIGN KEY ("permissao_id") REFERENCES "erp"."rbac_permissoes" ("id") ON UPDATE CASCADE ON DELETE CASCADE NOT VALID;

ALTER TABLE "erp"."rbac_modulos" ADD CONSTRAINT "fk_rbac_modulos_fk_rbac_modulo_pai" FOREIGN KEY ("modulo_pai_id") REFERENCES "erp"."rbac_modulos" ("id") ON UPDATE CASCADE ON DELETE NO ACTION NOT VALID;

ALTER TABLE "erp"."rbac_permissoes" ADD CONSTRAINT "fk_rbac_permissoes_fk_rbac_permissao_modulo" FOREIGN KEY ("modulo_id") REFERENCES "erp"."rbac_modulos" ("id") ON UPDATE CASCADE ON DELETE CASCADE NOT VALID;

ALTER TABLE "erp"."rbac_usuario_grupos" ADD CONSTRAINT "fk_rbac_usuario_grupos_fk_rbac_ug_grupo" FOREIGN KEY ("grupo_id") REFERENCES "erp"."rbac_grupos" ("id") ON UPDATE CASCADE ON DELETE NO ACTION NOT VALID;

ALTER TABLE "erp"."rbac_usuario_permissoes" ADD CONSTRAINT "fk_rbac_usuario_permissoes_fk_rbac_up_permissao" FOREIGN KEY ("permissao_id") REFERENCES "erp"."rbac_permissoes" ("id") ON UPDATE CASCADE ON DELETE CASCADE NOT VALID;
