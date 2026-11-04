# Testes de integração — Convidados (pré-cadastro do morador + aba Convidados)

Cobrem `api/api_portal_convidados.php` (Portal/App) e `api/api_convidados.php` (painel),
com o esquema real das migrations, em MariaDB/MySQL e PHP 8 (CLI).

## Como rodar (Linux/WSL)
```bash
# 1) banco de teste
mysql -e "CREATE DATABASE erptest CHARACTER SET utf8mb4; CREATE USER 'erp'@'localhost' IDENTIFIED BY 'erp'; GRANT ALL ON erptest.* TO 'erp'@'localhost';"
mysql erptest < tests/convidados_pre_cadastro/schema_base.sql
mysql erptest < sql/migration_lista_convidados_mysql57.sql
mysql erptest < sql/migration_convidados_pre_cadastro_morador_mysql57.sql
mysql erptest < sql/migration_alertas_unidade_mysql57.sql

# 2) ambiente isolado: copia da API + stubs de config/autenticação (NUNCA usar o config.php real)
mkdir -p /tmp/erp_test/api/helpers
cp api/api_convidados.php api/api_portal_convidados.php /tmp/erp_test/api/
cp api/helpers/*.php /tmp/erp_test/api/helpers/
cp tests/convidados_pre_cadastro/api/config.stub.php /tmp/erp_test/api/config.php && cp tests/convidados_pre_cadastro/api/auth_helper.stub.php /tmp/erp_test/api/auth_helper.php && echo "<?php" > /tmp/erp_test/api/tenant_helper.php
(cd /tmp/erp_test && php -S 127.0.0.1:8099 &)

# 3) dados + testes (o caminho da migration no teste de fallback pode ser ajustado no script)
mysql erptest < tests/convidados_pre_cadastro/seed.sql
python3 tests/convidados_pre_cadastro/test_convidados.py
```
Resultado em 2026-11-04: **92/92 OK**.
