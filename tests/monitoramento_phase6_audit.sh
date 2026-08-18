#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

for file in api/monitoramento_helper.php api/api_monitoramento.php cron/monitoramento_retencao.php; do
  test -s "$file"
done

grep -q "tenant_id" sql/migration_monitoramento_lpr_mysql57.sql
grep -q "UNIQUE KEY.*event" sql/migration_monitoramento_lpr_mysql57.sql
grep -q "WHERE .*tenant_id" api/api_monitoramento.php
grep -q "WHERE .*tenant_id" api/monitoramento_helper.php
grep -q "tenant_id" cron/monitoramento_retencao.php
grep -q "DELETE" cron/monitoramento_retencao.php
grep -q "event_uuid" api/api_monitoramento.php
grep -q "duplicates" api/api_monitoramento.php
grep -q "monitoramento_eventos WHERE tenant_id" api/api_monitoramento.php
php -l api/monitoramento_helper.php >/dev/null
php -l api/api_monitoramento.php >/dev/null
php -l cron/monitoramento_retencao.php >/dev/null
printf '%s\n' 'monitoramento_phase6_audit=ok'
