#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
MIGRATION="$ROOT/sql/migration_chatin_v2_mysql.sql"
for bin in mariadb-install-db mariadbd mariadb mariadb-admin; do
  command -v "$bin" >/dev/null 2>&1 || { echo "SKIP: requer $bin"; exit 77; }
done

TMP=$(mktemp -d "${TMPDIR:-/tmp}/chatin-v2-mariadb.XXXXXXXX")
chmod 700 "$TMP"
DATADIR="$TMP/data"
SOCKET="$TMP/mysql.sock"
PID="$TMP/mysql.pid"
LOG="$TMP/mariadb.log"
mkdir -m 700 "$DATADIR"
SERVER_PID=''
cleanup() {
  if [[ -n "$SERVER_PID" ]]; then
    mariadb-admin --no-defaults --socket="$SOCKET" --user=root shutdown >/dev/null 2>&1 || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf -- "$TMP"
}
trap cleanup EXIT

if ! mariadb-install-db --no-defaults --datadir="$DATADIR" --auth-root-authentication-method=normal --skip-test-db --user="$(id -un)" >"$TMP/install.stdout" 2>"$TMP/install.stderr"; then
  cat "$TMP/install.stdout" "$TMP/install.stderr" >&2
  exit 1
fi
mariadbd --no-defaults --user="$(id -un)" --datadir="$DATADIR" --socket="$SOCKET" --pid-file="$PID" --log-error="$LOG" --skip-networking --port=0 --bind-address=127.0.0.1 &
SERVER_PID=$!
for _ in $(seq 1 80); do
  if mariadb-admin --no-defaults --socket="$SOCKET" --user=root ping >/dev/null 2>&1; then break; fi
  sleep 0.25
done
mariadb-admin --no-defaults --socket="$SOCKET" --user=root ping >/dev/null
DB_VERSION=$(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root -e 'SELECT VERSION()')
if [[ ! "$DB_VERSION" =~ ^10\.11\. ]]; then
  echo "FAIL: harness requer MariaDB 10.11; encontrado $DB_VERSION" >&2
  exit 1
fi

DB=chatin_v2_test
mariadb --no-defaults --socket="$SOCKET" --user=root -e "CREATE DATABASE chatin_v2_preflight_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE TABLE chatin_v2_preflight_test.tenants (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;"
if mariadb --no-defaults --batch --socket="$SOCKET" --user=root chatin_v2_preflight_test < "$MIGRATION" >"$TMP/preflight.stdout" 2>"$TMP/preflight.stderr"; then
  echo 'FAIL: migration deveria falhar quando faltam tabelas base/RBAC.' >&2
  exit 1
fi
if ! grep -q 'Preflight Chat-In v2: tabelas base/tenant/RBAC ausentes' "$TMP/preflight.stderr"; then
  cat "$TMP/preflight.stdout" "$TMP/preflight.stderr" >&2
  echo 'FAIL: o preflight não retornou o erro esperado para schema sintético incompleto.' >&2
  exit 1
fi
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root chatin_v2_preflight_test -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='chatin_v2_preflight_test' AND TABLE_NAME='chatin_configuracoes'") == 0 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root chatin_v2_preflight_test -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA='chatin_v2_preflight_test' AND ROUTINE_NAME='sp_erp_chatin_v2_20261114'") == 1 ]]
mariadb --no-defaults --socket="$SOCKET" --user=root chatin_v2_preflight_test -e 'DROP PROCEDURE IF EXISTS sp_erp_chatin_v2_20261114;'
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root chatin_v2_preflight_test -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA='chatin_v2_preflight_test' AND ROUTINE_NAME='sp_erp_chatin_v2_20261114'") == 0 ]]

mariadb --no-defaults --socket="$SOCKET" --user=root <<SQL
CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE \`$DB\`;
CREATE TABLE tenants (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO tenants VALUES (101),(202);
CREATE TABLE chatin_conversas (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT NOT NULL, PRIMARY KEY(id)) ENGINE=InnoDB;
CREATE TABLE chatin_mensagens (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT NOT NULL, conversa_id BIGINT UNSIGNED NOT NULL,
 remetente_tipo ENUM('USUARIO','MORADOR','SISTEMA') NOT NULL, remetente_id INT NOT NULL DEFAULT 0,
 remetente_nome VARCHAR(150) NOT NULL, tipo ENUM('TEXTO','AVISO','LEGADO') NOT NULL DEFAULT 'TEXTO', corpo TEXT NOT NULL,
 titulo VARCHAR(150) DEFAULT NULL, referencia_tipo VARCHAR(30) DEFAULT NULL, referencia_id INT DEFAULT NULL,
 idempotency_key VARCHAR(40) DEFAULT NULL, criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_chatin_msg_conversa(tenant_id,conversa_id,id),
 KEY idx_chatin_msg_remetente(tenant_id,remetente_tipo,remetente_id,criado_em),
 UNIQUE KEY uk_chatin_msg_idem(tenant_id,remetente_tipo,remetente_id,idempotency_key)
) ENGINE=InnoDB;
CREATE TABLE chatin_preferencias (
 tenant_id INT NOT NULL, participante_tipo ENUM('USUARIO','MORADOR') NOT NULL, participante_id INT NOT NULL,
 visivel_busca TINYINT(1) NOT NULL DEFAULT 1, mostrar_online TINYINT(1) NOT NULL DEFAULT 1,
 notificar_push TINYINT(1) NOT NULL DEFAULT 1, atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(tenant_id, participante_tipo, participante_id)
) ENGINE=InnoDB;
CREATE TABLE chatin_presenca (
 tenant_id INT NOT NULL, participante_tipo ENUM('USUARIO','MORADOR') NOT NULL, participante_id INT NOT NULL,
 ultimo_ping DATETIME NOT NULL, plataforma VARCHAR(20) NOT NULL DEFAULT 'web', conversa_aberta_id BIGINT UNSIGNED DEFAULT NULL,
 PRIMARY KEY(tenant_id, participante_tipo, participante_id), KEY idx_chatin_presenca_ping(tenant_id,ultimo_ping)
) ENGINE=InnoDB;
CREATE TABLE rbac_modulos (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, chave VARCHAR(80) NOT NULL, nome VARCHAR(100) NOT NULL, pagina VARCHAR(80) DEFAULT NULL,
 grupo VARCHAR(80) NOT NULL DEFAULT 'Sistema', tipo VARCHAR(20) NOT NULL DEFAULT 'MODULO', icone VARCHAR(80) NOT NULL,
 descricao VARCHAR(255) DEFAULT NULL, perfil_compatibilidade VARCHAR(24) NOT NULL DEFAULT 'operador', ordem SMALLINT NOT NULL DEFAULT 0,
 ativo TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY(id), UNIQUE KEY uk_rbac_modulo_chave(chave)
) ENGINE=InnoDB;
CREATE TABLE rbac_permissoes (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, modulo_id INT UNSIGNED NOT NULL, acao VARCHAR(32) NOT NULL,
 nome VARCHAR(80) NOT NULL, descricao VARCHAR(255) DEFAULT NULL, ativo TINYINT(1) NOT NULL DEFAULT 1,
 PRIMARY KEY(id), UNIQUE KEY uk_rbac_modulo_acao(modulo_id,acao)
) ENGINE=InnoDB;
CREATE TABLE rbac_grupos (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT DEFAULT NULL, slug VARCHAR(80) NOT NULL, nome VARCHAR(120) NOT NULL,
 ativo TINYINT(1) NOT NULL DEFAULT 1, excluido_em DATETIME DEFAULT NULL, PRIMARY KEY(id), UNIQUE KEY uk_rbac_grupo_tenant_slug(tenant_id,slug)
) ENGINE=InnoDB;
CREATE TABLE rbac_grupo_permissoes (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, grupo_id INT UNSIGNED NOT NULL, permissao_id INT UNSIGNED NOT NULL,
 efeito ENUM('PERMITIR','NEGAR') NOT NULL DEFAULT 'PERMITIR', escopo_dados ENUM('GLOBAL','DEPARTAMENTO','UNIDADE','PROPRIO','ATRIBUIDO') NOT NULL DEFAULT 'GLOBAL',
 PRIMARY KEY(id), UNIQUE KEY uk_rbac_grupo_permissao(grupo_id,permissao_id)
) ENGINE=InnoDB;
CREATE TABLE rbac_configuracoes (tenant_id INT NOT NULL PRIMARY KEY, revisao_permissoes INT NOT NULL DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE rbac_auditoria (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, evento_uuid CHAR(36) NOT NULL, tenant_id INT DEFAULT NULL,
 usuario_id INT DEFAULT NULL, usuario_nome VARCHAR(160), grupo_nome VARCHAR(120), sessao_id BIGINT UNSIGNED DEFAULT NULL,
 modulo_chave VARCHAR(80), submodulo_chave VARCHAR(80), acao VARCHAR(80) NOT NULL, origem VARCHAR(80) NOT NULL DEFAULT 'ERP_WEB',
 metodo_http VARCHAR(10), endpoint VARCHAR(255), registro_tipo VARCHAR(80), registro_id VARCHAR(80), resultado VARCHAR(10) NOT NULL,
 status_http SMALLINT UNSIGNED, ip VARCHAR(45), user_agent VARCHAR(500), dados_antes LONGTEXT, dados_depois LONGTEXT,
 motivo VARCHAR(500), hash_anterior CHAR(64), hash_evento CHAR(64) NOT NULL, ocorrido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO rbac_grupos(tenant_id,slug,nome) VALUES(101,'staff','Staff A'),(202,'staff','Staff B');
INSERT INTO rbac_grupos(tenant_id,slug,nome,ativo,excluido_em) VALUES(101,'inactive','Inactive A',0,NULL),(202,'deleted','Deleted B',1,'2026-01-01 00:00:00');
INSERT INTO rbac_configuracoes(tenant_id,revisao_permissoes) VALUES(101,0),(202,0);
INSERT INTO rbac_modulos(chave,nome,pagina,grupo,tipo,icone,descricao,perfil_compatibilidade,ordem,ativo)
 VALUES('chatin','Chat-In','chatin','Sistema','MODULO','fas fa-comments','Comunicação interna entre equipe e moradores','operador',1013,0);
INSERT INTO rbac_permissoes(modulo_id,acao,nome,descricao)
 SELECT id,'visualizar','Visualizar','Usar o Chat-In neste condomínio' FROM rbac_modulos WHERE chave='chatin';
INSERT INTO rbac_permissoes(modulo_id,acao,nome,descricao)
 SELECT id,'configurar','Configurar','Alterar a configuração tenant-scoped do Chat-In' FROM rbac_modulos WHERE chave='chatin';
UPDATE rbac_permissoes SET ativo=0;
INSERT INTO rbac_grupo_permissoes(grupo_id,permissao_id,efeito,escopo_dados)
 SELECT g.id,p.id,'NEGAR','GLOBAL' FROM rbac_grupos g JOIN rbac_permissoes p ON p.acao='visualizar'
 JOIN rbac_modulos m ON m.id=p.modulo_id AND m.chave='chatin' WHERE g.tenant_id=101 AND g.slug='staff';
CREATE TABLE pwa_configuracoes (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, chave VARCHAR(100) NOT NULL, valor TEXT DEFAULT NULL, UNIQUE KEY uq_pwa_tenant_chave(tenant_id,chave));
INSERT INTO pwa_configuracoes(tenant_id,chave,valor) VALUES(101,'push_chatin_ativo','0'),(202,'push_chatin_ativo','1');
SQL

run_migration() {
  mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" < "$MIGRATION"
}
# Uma divergência de ENUM deve falhar antes de ALTER/CREATE do schema da aplicação.
if mariadb --no-defaults --batch --socket="$SOCKET" --user=root "$DB" < "$MIGRATION" >"$TMP/divergent.stdout" 2>"$TMP/divergent.stderr"; then
  echo 'FAIL: migration deveria falhar para o ENUM divergente.' >&2
  exit 1
fi
if ! grep -q 'definição, nulabilidade ou default de chatin_mensagens.tipo diverge' "$TMP/divergent.stderr"; then
  cat "$TMP/divergent.stdout" "$TMP/divergent.stderr" >&2
  echo 'FAIL: o preflight não rejeitou ENUM divergente antes do DDL.' >&2
  exit 1
fi
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='chatin_preferencias' AND COLUMN_NAME='som_ativo'") == 0 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='chatin_presenca' AND COLUMN_NAME='digitando_conversa_id'") == 0 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='chatin_configuracoes'") == 0 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_modulos WHERE chave='chatin' AND ativo=0") == 1 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_configuracoes WHERE revisao_permissoes=0") == 2 ]]
mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e 'DROP PROCEDURE IF EXISTS sp_erp_chatin_v2_20261114;'
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA='$DB' AND ROUTINE_NAME='sp_erp_chatin_v2_20261114'") == 0 ]]
mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e "ALTER TABLE chatin_mensagens MODIFY tipo ENUM('TEXTO','AVISO') NOT NULL DEFAULT 'TEXTO';"
# The migration needs this legacy source for the tenant push default; absence must fail before any application DDL.
mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e 'DROP TABLE pwa_configuracoes;'
if mariadb --no-defaults --batch --socket="$SOCKET" --user=root "$DB" < "$MIGRATION" >"$TMP/pwa-missing.stdout" 2>"$TMP/pwa-missing.stderr"; then
  echo 'FAIL: migration deveria falhar antes do DDL quando pwa_configuracoes está ausente.' >&2
  exit 1
fi
if ! grep -q 'Preflight Chat-In v2: pwa_configuracoes ausente' "$TMP/pwa-missing.stderr"; then
  cat "$TMP/pwa-missing.stdout" "$TMP/pwa-missing.stderr" >&2
  echo 'FAIL: o preflight não identificou pwa_configuracoes ausente.' >&2
  exit 1
fi
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='chatin_preferencias' AND COLUMN_NAME='som_ativo'") == 0 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='chatin_configuracoes'") == 0 ]]
mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e 'DROP PROCEDURE IF EXISTS sp_erp_chatin_v2_20261114; CREATE TABLE pwa_configuracoes (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, chave VARCHAR(100) NOT NULL, valor TEXT DEFAULT NULL, UNIQUE KEY uq_pwa_tenant_chave(tenant_id,chave)); INSERT INTO pwa_configuracoes(tenant_id,chave,valor) VALUES(101,"push_chatin_ativo","0"),(202,"push_chatin_ativo","1");'
# A coluna parcialmente migrada com dado fora da faixa também deve parar antes do DDL restante.
mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e "ALTER TABLE chatin_preferencias ADD som_volume TINYINT UNSIGNED NOT NULL DEFAULT 70; INSERT INTO chatin_preferencias(tenant_id,participante_tipo,participante_id,som_volume) VALUES(101,'MORADOR',1,101);"
if mariadb --no-defaults --batch --socket="$SOCKET" --user=root "$DB" < "$MIGRATION" >"$TMP/volume.stdout" 2>"$TMP/volume.stderr"; then
  echo 'FAIL: migration deveria rejeitar som_volume fora da faixa.' >&2
  exit 1
fi
if ! grep -q 'existem valores de som_volume fora da faixa 0..100' "$TMP/volume.stderr"; then
  cat "$TMP/volume.stdout" "$TMP/volume.stderr" >&2
  echo 'FAIL: não foi emitido o diagnóstico esperado para som_volume inválido.' >&2
  exit 1
fi
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='chatin_preferencias' AND COLUMN_NAME='som_ativo'") == 0 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='chatin_configuracoes'") == 0 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA='$DB' AND ROUTINE_NAME='sp_erp_chatin_v2_20261114'") == 1 ]]
mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e 'DROP PROCEDURE IF EXISTS sp_erp_chatin_v2_20261114; DELETE FROM chatin_preferencias; ALTER TABLE chatin_preferencias DROP COLUMN som_volume;'
 mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e "ALTER TABLE chatin_preferencias ADD som_tipo VARCHAR(20) NOT NULL DEFAULT 'classico'; INSERT INTO chatin_preferencias(tenant_id,participante_tipo,participante_id,som_tipo) VALUES(101,'MORADOR',1,'invalid');"
if mariadb --no-defaults --batch --socket="$SOCKET" --user=root "$DB" < "$MIGRATION" >"$TMP/type.stdout" 2>"$TMP/type.stderr"; then
  echo 'FAIL: migration deveria rejeitar som_tipo inválido preexistente.' >&2
  exit 1
fi
if ! grep -q 'valores de som_tipo fora do catálogo permitido' "$TMP/type.stderr"; then
  cat "$TMP/type.stdout" "$TMP/type.stderr" >&2
  echo 'FAIL: o preflight não rejeitou som_tipo inválido.' >&2
  exit 1
fi
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='chatin_preferencias' AND COLUMN_NAME='som_ativo'") == 0 ]]
mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e "DROP PROCEDURE IF EXISTS sp_erp_chatin_v2_20261114; DELETE FROM chatin_preferencias; ALTER TABLE chatin_preferencias DROP COLUMN som_tipo;"
run_migration
# The two tenant-specific legacy values are preserved independently.
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$DB' AND TABLE_NAME='chatin_preferencias' AND CONSTRAINT_NAME='chk_chatin_som_volume' AND CONSTRAINT_TYPE='CHECK'") == 1 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$DB' AND TABLE_NAME='chatin_preferencias' AND CONSTRAINT_NAME='chk_chatin_som_tipo' AND CONSTRAINT_TYPE='CHECK'") == 1 ]]
if mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e "INSERT INTO chatin_preferencias(tenant_id,participante_tipo,participante_id,som_volume) VALUES(101,'MORADOR',2,101);" >/dev/null 2>&1; then
  echo 'FAIL: CHECK som_volume deveria rejeitar valor 101.' >&2
  exit 1
fi
mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e "INSERT INTO chatin_preferencias(tenant_id,participante_tipo,participante_id,som_volume) VALUES(101,'MORADOR',2,0); DELETE FROM chatin_preferencias WHERE tenant_id=101 AND participante_tipo='MORADOR' AND participante_id=2;"
if mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e "INSERT INTO chatin_preferencias(tenant_id,participante_tipo,participante_id,som_tipo) VALUES(101,'MORADOR',2,'invalid');" >/dev/null 2>&1; then
  echo 'FAIL: CHECK som_tipo deveria rejeitar valor fora do catálogo.' >&2
  exit 1
fi
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT recurso_push_app FROM chatin_configuracoes WHERE tenant_id=101") == 0 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT recurso_push_app FROM chatin_configuracoes WHERE tenant_id=202") == 1 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_modulos WHERE chave='chatin' AND ativo=1") == 1 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_permissoes WHERE ativo=1") == 2 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_configuracoes WHERE revisao_permissoes=1") == 2 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_grupo_permissoes gp JOIN rbac_permissoes p ON p.id=gp.permissao_id WHERE p.acao='visualizar'") == 4 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_grupo_permissoes gp JOIN rbac_grupos g ON g.id=gp.grupo_id JOIN rbac_permissoes p ON p.id=gp.permissao_id WHERE g.slug IN ('inactive','deleted') AND p.acao='visualizar' AND gp.efeito='PERMITIR'") == 2 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_grupo_permissoes gp JOIN rbac_grupos g ON g.id=gp.grupo_id JOIN rbac_permissoes p ON p.id=gp.permissao_id WHERE g.slug='staff' AND g.tenant_id=101 AND p.acao='visualizar' AND gp.efeito='NEGAR'") == 1 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_grupo_permissoes gp JOIN rbac_grupos g ON g.id=gp.grupo_id JOIN rbac_permissoes p ON p.id=gp.permissao_id WHERE g.slug='staff' AND g.tenant_id=202 AND p.acao='visualizar' AND gp.efeito='PERMITIR'") == 1 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='chatin_mensagens' AND COLUMN_NAME='tipo' AND COLUMN_TYPE LIKE '%CUTUCAR%'") == tipo ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='chatin_presenca' AND COLUMN_NAME IN ('digitando_conversa_id','digitando_ate')") == 2 ]]

# Re-run and preserve a tenant choice; adding/altering no application data is expected.
mariadb --no-defaults --socket="$SOCKET" --user=root "$DB" -e "UPDATE chatin_configuracoes SET ativo=0 WHERE tenant_id=101;"
run_migration
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT ativo FROM chatin_configuracoes WHERE tenant_id=101") == 0 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT recurso_push_app FROM chatin_configuracoes WHERE tenant_id=101") == 0 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT recurso_push_app FROM chatin_configuracoes WHERE tenant_id=202") == 1 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_grupo_permissoes") == 4 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM rbac_configuracoes WHERE revisao_permissoes=1") == 2 ]]
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root "$DB" -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_SCHEMA='$DB' AND ROUTINE_NAME='sp_erp_chatin_v2_20261114'") == 0 ]]

criar_cenario_pwa_global() {
  local alvo="$1" valor="$2" tabela
  mariadb --no-defaults --socket="$SOCKET" --user=root -e "CREATE DATABASE \`$alvo\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  for tabela in tenants chatin_conversas chatin_mensagens chatin_preferencias chatin_presenca rbac_modulos rbac_permissoes rbac_grupos rbac_grupo_permissoes rbac_configuracoes rbac_auditoria; do
    mariadb --no-defaults --socket="$SOCKET" --user=root -e "CREATE TABLE \`$alvo\`.\`$tabela\` LIKE \`$DB\`.\`$tabela\`; INSERT INTO \`$alvo\`.\`$tabela\` SELECT * FROM \`$DB\`.\`$tabela\`;"
  done
  mariadb --no-defaults --socket="$SOCKET" --user=root "$alvo" -e "CREATE TABLE pwa_configuracoes (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, chave VARCHAR(100) NOT NULL, valor TEXT DEFAULT NULL, UNIQUE KEY uq_pwa_global_chave(chave));"
  if [[ "$valor" != 'AUSENTE' ]]; then
    mariadb --no-defaults --socket="$SOCKET" --user=root "$alvo" -e "INSERT INTO pwa_configuracoes(chave,valor) VALUES('push_chatin_ativo','$valor');"
  fi
  mariadb --no-defaults --socket="$SOCKET" --user=root "$alvo" < "$MIGRATION"
}
criar_cenario_pwa_global chatin_v2_pwa_global_off 0
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root chatin_v2_pwa_global_off -e 'SELECT COUNT(*) FROM chatin_configuracoes WHERE recurso_push_app=0') == 2 ]]
criar_cenario_pwa_global chatin_v2_pwa_global_absent AUSENTE
[[ $(mariadb --no-defaults --batch --skip-column-names --socket="$SOCKET" --user=root chatin_v2_pwa_global_absent -e 'SELECT COUNT(*) FROM chatin_configuracoes WHERE recurso_push_app=1') == 2 ]]

echo "PASS: migration Chat-In v2 fail-closed na base incompleta, pwa_configuracoes ausente, ENUM divergente, som_volume e som_tipo preexistentes inválidos em MariaDB $DB_VERSION isolado; abort fail-fast sem DDL de aplicação, procedure temporária removida por cleanup explícito, CHECKs de som e volume, execução idempotente duas vezes, seed em todos os grupos com NEGAR preexistente preservado e push legado tenant/global/ausente preservado."
echo 'NOTA: MariaDB 10.11 não substitui homologação no MySQL 8.4.'
