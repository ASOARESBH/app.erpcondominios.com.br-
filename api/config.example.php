<?php
/**
 * Exemplo sem segredos para instalações do ERP Condomínios.
 *
 * Não copie este arquivo para o document root. Crie o arquivo externo
 * /home/USUARIO/erpcondominios-private/erp_config.php com permissões 0600.
 */

define('ERP_DB_DRIVER', 'mysql'); // MySQL permanece como padrão da aplicação.
define('ERP_DB_HOST', 'localhost');
define('ERP_DB_NAME', 'NOME_DO_BANCO');
define('ERP_DB_USER', 'USUARIO_DO_BANCO');
define('ERP_DB_PASS', 'SENHA_FORTE_FORA_DO_GIT');
define('ERP_DB_CHARSET', 'utf8mb4');
define('ERP_TIMEZONE', 'America/Sao_Paulo');

/*
 * Perfil PostgreSQL isolado (opt-in; não homologado para produção).
 * Ao selecionar este perfil, remova as constantes ERP_DB_* acima e use apenas:
 *
 * define('ERP_DB_DRIVER', 'pgsql');
 * define('ERP_PG_HOST', '127.0.0.1');
 * define('ERP_PG_PORT', 5432);
 * define('ERP_PG_NAME', 'erp_pg_dev');
 * define('ERP_PG_USER', 'erp_app');
 * define('ERP_PG_PASS', 'SENHA_FORTE_FORA_DO_GIT');
 * define('ERP_PG_SCHEMA', 'erp');
 *
 * O pool PHP-FPM isolado deve carregar api/db/MysqliDropInAutoPrepend.php.
 */
