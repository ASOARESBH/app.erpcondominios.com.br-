<?php
/**
 * Estrutura de banco compartilhada do módulo de e-mail.
 *
 * Consolida a criação/migração de tabelas que antes estava duplicada em
 * api_smtp.php e api_email_alertas.php. `configuracao_smtp`/`email_providers`/
 * `email_delivery_logs` são GLOBAIS (uma única configuração de remetente para
 * toda a plataforma, administrada pelo Super-Admin). `email_alertas`/`email_log`
 * são POR TENANT (cada condomínio liga/desliga seus próprios alertas e vê
 * apenas o próprio histórico de envios).
 *
 * Corrige um bug de origem: a tabela `email_alertas` era criada sem
 * `tenant_id` e com `UNIQUE KEY (codigo)` — como o código só semeia via
 * `INSERT IGNORE`, o primeiro tenant a rodar a semente "reservava" o
 * `codigo` globalmente e nenhum outro tenant conseguia ter sua própria
 * linha para o mesmo alerta. Aqui a chave única passa a ser
 * `(tenant_id, codigo)`.
 */

if (!function_exists('_email_config_colunas')) {
    function _email_config_colunas($conexao, $tabela) {
        $cols = [];
        $res = mysqli_query($conexao, "DESCRIBE `$tabela`");
        if ($res) while ($r = mysqli_fetch_assoc($res)) $cols[] = $r['Field'];
        return $cols;
    }
}

if (!function_exists('_email_config_tem_indice')) {
    function _email_config_tem_indice($conexao, $tabela, $indice) {
        $res = mysqli_query($conexao, "SHOW INDEX FROM `$tabela` WHERE Key_name = '" . mysqli_real_escape_string($conexao, $indice) . "'");
        return $res && mysqli_num_rows($res) > 0;
    }
}

if (!function_exists('email_config_catalogo_alertas_padrao')) {
    function email_config_catalogo_alertas_padrao() {
        return [
            ['sistema.reset_senha', 'sistema', 'reset_senha', 'Reset de Senha',
             'Enviado ao usuário quando solicita redefinição de senha.', 1,
             'Redefinição de Senha — {{sistema_nome}}',
             'morador', '["nome_usuario","link_reset","expira_em","sistema_nome","logo_url","data_envio"]'],

            ['sistema.novo_usuario', 'sistema', 'novo_usuario', 'Boas-vindas — Novo Usuário',
             'Enviado quando um novo usuário é cadastrado no sistema.', 0,
             'Bem-vindo ao {{sistema_nome}}!',
             'morador', '["nome_usuario","email_usuario","perfil_usuario","link_sistema","sistema_nome","logo_url","data_envio"]'],

            ['hidrometro.leitura_realizada', 'hidrometro', 'leitura_realizada', 'Leitura Realizada — Notificação ao Morador',
             'Enviado ao morador após o lançamento da leitura do hidrômetro.', 0,
             'Leitura do Hidrômetro — {{mes_referencia}} — {{sistema_nome}}',
             'morador', '["nome_morador","unidade","numero_hidrometro","leitura_anterior","leitura_atual","consumo","valor_total","data_leitura","mes_referencia","sistema_nome","logo_url","data_envio"]'],

            ['hidrometro.consumo_alto', 'hidrometro', 'consumo_alto', 'Alerta de Consumo Elevado',
             'Enviado ao admin quando o consumo excede o limite configurado.', 0,
             '⚠️ Consumo Elevado — Unidade {{unidade}} — {{sistema_nome}}',
             'admin', '["nome_morador","unidade","consumo","limite","sistema_nome","logo_url","data_envio"]'],

            ['financeiro.conta_vencendo', 'financeiro', 'conta_vencendo', 'Conta a Vencer em Breve',
             'Enviado ao admin X dias antes do vencimento de uma conta a pagar.', 0,
             '⏰ Conta Vencendo em {{dias_para_vencer}} dias — {{sistema_nome}}',
             'todos_admins', '["fornecedor","descricao","data_vencimento","valor","dias_para_vencer","sistema_nome","logo_url","data_envio"]'],

            ['financeiro.conta_vencida', 'financeiro', 'conta_vencida', 'Conta Vencida',
             'Enviado ao admin quando uma conta a pagar vence sem pagamento.', 0,
             '🔴 Conta Vencida — {{fornecedor}} — {{sistema_nome}}',
             'todos_admins', '["fornecedor","descricao","data_vencimento","valor","sistema_nome","logo_url","data_envio"]'],

            ['acesso.visitante_registrado', 'acesso', 'visitante_registrado', 'Visitante Registrado',
             'Enviado ao morador quando um visitante é registrado para sua unidade.', 0,
             '🔔 Visitante Registrado — Unidade {{unidade}} — {{sistema_nome}}',
             'morador', '["nome_morador","unidade","nome_visitante","documento_visitante","data_hora","operador","sistema_nome","logo_url","data_envio"]'],

            ['rh.aniversario_colaborador', 'rh', 'aniversario_colaborador', 'Aniversário de Colaborador',
             'Enviado ao admin com a lista de colaboradores aniversariantes do dia.', 0,
             '🎂 Aniversariantes do Dia — {{sistema_nome}}',
             'todos_admins', '["lista_aniversariantes","sistema_nome","logo_url","data_envio"]'],

            ['moradores.cadastro_novo', 'moradores', 'cadastro_novo', 'Boas-vindas ao Morador',
             'Enviado ao morador quando seu cadastro é criado no sistema.', 0,
             'Bem-vindo ao {{sistema_nome}}!',
             'morador', '["nome_morador","unidade","cpf","sistema_nome","logo_url","data_envio"]'],
        ];
    }
}

if (!function_exists('email_config_seed_alertas_padrao')) {
    /** Garante que o tenant informado tenha as 9 linhas padrão de email_alertas. */
    function email_config_seed_alertas_padrao($conexao, int $tenantId) {
        foreach (email_config_catalogo_alertas_padrao() as $a) {
            $codigo  = mysqli_real_escape_string($conexao, $a[0]);
            $modulo  = mysqli_real_escape_string($conexao, $a[1]);
            $evento  = mysqli_real_escape_string($conexao, $a[2]);
            $nome    = mysqli_real_escape_string($conexao, $a[3]);
            $desc    = mysqli_real_escape_string($conexao, $a[4]);
            $ativo   = (int) $a[5];
            $assunto = mysqli_real_escape_string($conexao, $a[6]);
            $dest    = mysqli_real_escape_string($conexao, $a[7]);
            $vars    = mysqli_real_escape_string($conexao, $a[8]);
            mysqli_query($conexao, "INSERT IGNORE INTO email_alertas
                (tenant_id,codigo,modulo,evento,nome,descricao,ativo,assunto,variaveis,destinatario_tipo)
                VALUES ($tenantId,'$codigo','$modulo','$evento','$nome','$desc',$ativo,'$assunto','$vars','$dest')");
        }
    }
}

if (!function_exists('email_config_garantir_tabelas')) {
    function email_config_garantir_tabelas($conexao) {
        // ── configuracao_smtp (global — remetente único da plataforma) ──────
        mysqli_query($conexao, "CREATE TABLE IF NOT EXISTS `configuracao_smtp` (
            `id`               INT(11)      NOT NULL AUTO_INCREMENT,
            `provedor`         VARCHAR(50)  NOT NULL DEFAULT 'custom',
            `smtp_host`        VARCHAR(255) NOT NULL DEFAULT '',
            `smtp_port`        INT(11)      NOT NULL DEFAULT 587,
            `smtp_usuario`     VARCHAR(255) NOT NULL DEFAULT '',
            `smtp_senha`       VARCHAR(255) NOT NULL DEFAULT '',
            `smtp_de_email`    VARCHAR(255) NOT NULL DEFAULT '',
            `smtp_de_nome`     VARCHAR(255) NOT NULL DEFAULT 'ERP Condomínios',
            `smtp_seguranca`   ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
            `smtp_ativo`       TINYINT(1)   NOT NULL DEFAULT 1,
            `timeout`          INT(11)      NOT NULL DEFAULT 30,
            `email_provider`   ENUM('brevo','resend','smtp') NOT NULL DEFAULT 'smtp',
            `api_key`          VARCHAR(1024) DEFAULT NULL,
            `sender_email`     VARCHAR(255) DEFAULT NULL,
            `sender_name`      VARCHAR(255) DEFAULT NULL,
            `data_criacao`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `data_atualizacao` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $colsSmtp = _email_config_colunas($conexao, 'configuracao_smtp');
        if (!in_array('provedor', $colsSmtp, true))
            mysqli_query($conexao, "ALTER TABLE configuracao_smtp ADD COLUMN `provedor` VARCHAR(50) NOT NULL DEFAULT 'custom' AFTER `id`");
        if (!in_array('timeout', $colsSmtp, true))
            mysqli_query($conexao, "ALTER TABLE configuracao_smtp ADD COLUMN `timeout` INT(11) NOT NULL DEFAULT 30 AFTER `smtp_seguranca`");
        if (!in_array('email_provider', $colsSmtp, true))
            mysqli_query($conexao, "ALTER TABLE configuracao_smtp ADD COLUMN `email_provider` ENUM('brevo','resend','smtp') NOT NULL DEFAULT 'smtp' AFTER `smtp_ativo`");
        if (!in_array('api_key', $colsSmtp, true))
            mysqli_query($conexao, "ALTER TABLE configuracao_smtp ADD COLUMN `api_key` VARCHAR(1024) DEFAULT NULL AFTER `email_provider`");
        if (!in_array('sender_email', $colsSmtp, true))
            mysqli_query($conexao, "ALTER TABLE configuracao_smtp ADD COLUMN `sender_email` VARCHAR(255) DEFAULT NULL AFTER `api_key`");
        if (!in_array('sender_name', $colsSmtp, true))
            mysqli_query($conexao, "ALTER TABLE configuracao_smtp ADD COLUMN `sender_name` VARCHAR(255) DEFAULT NULL AFTER `sender_email`");

        // Proteção de migração: instalações legadas com SMTP puro que receberam o
        // DEFAULT 'brevo' automaticamente ao ganhar a coluna email_provider. Só
        // rebaixa quando NÃO há api_key (uma linha com api_key foi configurada
        // explicitamente como Brevo/Resend e jamais deve ser sobrescrita).
        mysqli_query($conexao, "UPDATE configuracao_smtp
            SET email_provider = 'smtp'
            WHERE email_provider = 'brevo'
              AND (api_key IS NULL OR api_key = '')
              AND smtp_host    != ''
              AND smtp_usuario != ''");

        // ── email_providers (global — cadeia de fallback) ──────────────────
        mysqli_query($conexao, "CREATE TABLE IF NOT EXISTS `email_providers` (
            `id`               INT NOT NULL AUTO_INCREMENT,
            `provider`         ENUM('brevo','resend','smtp') NOT NULL DEFAULT 'smtp',
            `prioridade`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `ativo`            TINYINT(1) NOT NULL DEFAULT 1,
            `api_key`          VARCHAR(1024) DEFAULT NULL,
            `smtp_host`        VARCHAR(255) DEFAULT NULL,
            `smtp_port`        SMALLINT UNSIGNED DEFAULT 587,
            `smtp_user`        VARCHAR(255) DEFAULT NULL,
            `smtp_password`    VARCHAR(255) DEFAULT NULL,
            `sender_email`     VARCHAR(255) DEFAULT NULL,
            `sender_name`      VARCHAR(255) DEFAULT NULL,
            `status`           ENUM('ok','error','untested') NOT NULL DEFAULT 'untested',
            `ultima_validacao` TIMESTAMP NULL DEFAULT NULL,
            `data_criacao`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_prioridade` (`prioridade`),
            KEY `idx_ativo`      (`ativo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ── email_delivery_logs (global — log detalhado com fallback_chain) ─
        mysqli_query($conexao, "CREATE TABLE IF NOT EXISTS `email_delivery_logs` (
            `id`             INT NOT NULL AUTO_INCREMENT,
            `provider_usado` VARCHAR(50) NOT NULL,
            `fallback_chain` TEXT DEFAULT NULL,
            `destinatario`   VARCHAR(255) NOT NULL,
            `assunto`        VARCHAR(500) DEFAULT NULL,
            `status`         ENUM('enviado','erro','fallback','pendente') NOT NULL DEFAULT 'enviado',
            `erro`           TEXT DEFAULT NULL,
            `tempo_execucao` DECIMAL(8,3) DEFAULT NULL,
            `message_id`     VARCHAR(255) DEFAULT NULL,
            `response_code`  SMALLINT DEFAULT NULL,
            `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_status`     (`status`),
            KEY `idx_provider`   (`provider_usado`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ── email_alertas (por tenant) ──────────────────────────────────────
        mysqli_query($conexao, "CREATE TABLE IF NOT EXISTS `email_alertas` (
            `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
            `tenant_id`           INT(11)      NOT NULL DEFAULT 1,
            `codigo`              VARCHAR(80)  NOT NULL,
            `modulo`              VARCHAR(50)  NOT NULL,
            `evento`              VARCHAR(80)  NOT NULL,
            `nome`                VARCHAR(150) NOT NULL,
            `descricao`           TEXT,
            `ativo`               TINYINT(1)   NOT NULL DEFAULT 0,
            `assunto`             VARCHAR(255) NOT NULL DEFAULT '',
            `corpo_html`          LONGTEXT,
            `variaveis`           TEXT,
            `destinatario_tipo`   ENUM('morador','admin','email_fixo','todos_admins') NOT NULL DEFAULT 'admin',
            `destinatario_email`  VARCHAR(255) DEFAULT NULL,
            `cc_emails`           TEXT,
            `data_criacao`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `data_atualizacao`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_tenant_codigo` (`tenant_id`,`codigo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $colsAlertas = _email_config_colunas($conexao, 'email_alertas');
        if (!in_array('tenant_id', $colsAlertas, true)) {
            mysqli_query($conexao, "ALTER TABLE email_alertas ADD COLUMN `tenant_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`");
        }
        if (!_email_config_tem_indice($conexao, 'email_alertas', 'uk_tenant_codigo')) {
            if (_email_config_tem_indice($conexao, 'email_alertas', 'uk_codigo')) {
                mysqli_query($conexao, "ALTER TABLE email_alertas DROP INDEX `uk_codigo`");
            }
            mysqli_query($conexao, "ALTER TABLE email_alertas ADD UNIQUE KEY `uk_tenant_codigo` (`tenant_id`,`codigo`)");
        }

        // ── email_log (por tenant) ──────────────────────────────────────────
        mysqli_query($conexao, "CREATE TABLE IF NOT EXISTS `email_log` (
            `id`              INT(11)      NOT NULL AUTO_INCREMENT,
            `tenant_id`       INT(11)      NOT NULL DEFAULT 1,
            `alerta_codigo`   VARCHAR(80)  DEFAULT NULL,
            `morador_id`      INT(11)      DEFAULT NULL,
            `destinatario`    VARCHAR(255) NOT NULL,
            `assunto`         VARCHAR(255) NOT NULL,
            `tipo`            VARCHAR(80)  NOT NULL,
            `status`          ENUM('enviado','erro','pendente') NOT NULL DEFAULT 'pendente',
            `erro_mensagem`   TEXT,
            `dados_contexto`  TEXT,
            `provider`        VARCHAR(50)  DEFAULT NULL,
            `message_id`      VARCHAR(255) DEFAULT NULL,
            `response_code`   INT          DEFAULT NULL,
            `erro_detalhado`  TEXT,
            `data_envio`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_tenant`  (`tenant_id`),
            KEY `idx_alerta`  (`alerta_codigo`),
            KEY `idx_status`  (`status`),
            KEY `idx_data`    (`data_envio`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $colsLog = _email_config_colunas($conexao, 'email_log');
        if (!in_array('tenant_id', $colsLog, true))
            mysqli_query($conexao, "ALTER TABLE email_log ADD COLUMN `tenant_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`");
        if (!in_array('alerta_codigo', $colsLog, true))
            mysqli_query($conexao, "ALTER TABLE email_log ADD COLUMN `alerta_codigo` VARCHAR(80) DEFAULT NULL AFTER `tenant_id`");
        if (!in_array('dados_contexto', $colsLog, true))
            mysqli_query($conexao, "ALTER TABLE email_log ADD COLUMN `dados_contexto` TEXT DEFAULT NULL AFTER `erro_mensagem`");
        if (!in_array('provider', $colsLog, true))
            mysqli_query($conexao, "ALTER TABLE email_log ADD COLUMN `provider` VARCHAR(50) DEFAULT NULL AFTER `status`");
        if (!in_array('message_id', $colsLog, true))
            mysqli_query($conexao, "ALTER TABLE email_log ADD COLUMN `message_id` VARCHAR(255) DEFAULT NULL AFTER `provider`");
        if (!in_array('response_code', $colsLog, true))
            mysqli_query($conexao, "ALTER TABLE email_log ADD COLUMN `response_code` INT DEFAULT NULL AFTER `message_id`");
        if (!in_array('erro_detalhado', $colsLog, true))
            mysqli_query($conexao, "ALTER TABLE email_log ADD COLUMN `erro_detalhado` TEXT DEFAULT NULL AFTER `response_code`");
    }
}
