<?php
/**
 * API de configuração SMTP / Providers de E-mail
 *
 * Ações:
 *   GET  ?acao=buscar   — Carrega configuração atual (mascarando credenciais)
 *   POST acao=salvar    — Salva configuração (criptografa API Key se necessário)
 *   GET  ?acao=testar&email=... — Envia e-mail de teste
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_clean();

header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';
require_once __DIR__ . '/email/EmailCrypto.php';

if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $r['dados'] = $dados;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('sanitizar')) {
    function sanitizar($conexao, $valor) {
        return mysqli_real_escape_string($conexao, trim($valor));
    }
}

$conexao = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conexao) {
    retornar_json(false, 'Erro ao conectar ao banco de dados');
}
mysqli_set_charset($conexao, 'utf8mb4');
$tenant_id = exigirTenantId();

// Garante que a tabela existe e todas as colunas novas estão presentes
_garantir_tabela($conexao);

$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

switch ($acao) {
    case 'buscar':
        _buscar($conexao);
        break;
    case 'salvar':
        _salvar($conexao);
        break;
    case 'testar':
        _testar($conexao);
        break;
    default:
        retornar_json(false, "Ação '$acao' não reconhecida.");
}

// =====================================================================
// GARANTIA DE ESTRUTURA
// =====================================================================

function _garantir_tabela($db) {
    // Tabela base
    mysqli_query($db, "CREATE TABLE IF NOT EXISTS `configuracao_smtp` (
        `id`             INT(11) NOT NULL AUTO_INCREMENT,
        `smtp_host`      VARCHAR(255) NOT NULL DEFAULT '',
        `smtp_port`      INT(11) NOT NULL DEFAULT 587,
        `smtp_usuario`   VARCHAR(255) NOT NULL DEFAULT '',
        `smtp_senha`     VARCHAR(255) NOT NULL DEFAULT '',
        `smtp_de_email`  VARCHAR(255) NOT NULL DEFAULT '',
        `smtp_de_nome`   VARCHAR(255) NOT NULL DEFAULT 'Sistema ERP',
        `smtp_seguranca` ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
        `smtp_ativo`     TINYINT(1) NOT NULL DEFAULT 1,
        `data_criacao`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migração incremental das colunas novas
    $cols = [];
    $res  = mysqli_query($db, "DESCRIBE configuracao_smtp");
    while ($r = mysqli_fetch_assoc($res)) {
        $cols[] = $r['Field'];
    }

    $alter = [];

    if (!in_array('email_provider', $cols)) {
        $alter[] = "ADD COLUMN `email_provider` ENUM('brevo','resend','smtp') NOT NULL DEFAULT 'brevo' AFTER `smtp_ativo`";
    }
    if (!in_array('api_key', $cols)) {
        $alter[] = "ADD COLUMN `api_key` VARCHAR(1024) DEFAULT NULL AFTER `email_provider`";
    }
    if (!in_array('sender_email', $cols)) {
        $alter[] = "ADD COLUMN `sender_email` VARCHAR(255) DEFAULT NULL AFTER `api_key`";
    }
    if (!in_array('sender_name', $cols)) {
        $alter[] = "ADD COLUMN `sender_name` VARCHAR(255) DEFAULT NULL AFTER `sender_email`";
    }
    if (!in_array('provedor', $cols)) {
        $alter[] = "ADD COLUMN `provedor` VARCHAR(50) NOT NULL DEFAULT 'custom' AFTER `id`";
    }
    if (!in_array('timeout', $cols)) {
        $alter[] = "ADD COLUMN `timeout` INT(11) NOT NULL DEFAULT 30 AFTER `smtp_seguranca`";
    }

    if (!empty($alter)) {
        mysqli_query($db, "ALTER TABLE `configuracao_smtp` " . implode(', ', $alter));
    }

    // Migração de proteção: instalações LEGADAS com SMTP puro que receberam o DEFAULT 'brevo'
    // automaticamente ao receber a nova coluna email_provider.
    // CRÍTICO: só atualiza linhas SEM api_key — uma linha com api_key foi explicitamente
    // configurada como Brevo/Resend REST API e JAMAIS deve ser rebaixada para smtp.
    mysqli_query($db, "UPDATE configuracao_smtp
        SET email_provider = 'smtp'
        WHERE email_provider = 'brevo'
          AND (api_key IS NULL OR api_key = '')
          AND smtp_host    != ''
          AND smtp_usuario != ''");
}

// =====================================================================
// BUSCAR
// =====================================================================

function _buscar($db) {
    $res  = mysqli_query($db, "SELECT * FROM configuracao_smtp ORDER BY id DESC LIMIT 1");
    $conf = $res ? mysqli_fetch_assoc($res) : null;

    if ($conf) {
        // Nunca devolver credenciais em texto puro
        $conf['smtp_senha'] = '';

        // API Key: mostrar apenas máscara
        if (!empty($conf['api_key'])) {
            try {
                $plain              = EmailCrypto::decrypt($conf['api_key']);
                $conf['api_key_mask'] = EmailCrypto::mask($plain);
            } catch (Throwable $e) {
                $conf['api_key_mask'] = '••••••••';
            }
        } else {
            $conf['api_key_mask'] = '';
        }
        $conf['api_key'] = ''; // nunca expõe a chave real
    }

    retornar_json(true, 'OK', $conf);
}

// =====================================================================
// SALVAR
// =====================================================================

function _salvar($db) {
    $emailProvider = $_POST['email_provider'] ?? 'smtp';
    if (!in_array($emailProvider, ['brevo', 'resend', 'smtp'], true)) {
        $emailProvider = 'smtp';
    }

    // Campos de API (Brevo / Resend)
    $novaApiKey   = $_POST['api_key']     ?? '';
    $senderEmail  = sanitizar($db, $_POST['sender_email'] ?? '');
    $senderName   = sanitizar($db, $_POST['sender_name']  ?? '');

    // Campos SMTP
    $host      = sanitizar($db, $_POST['smtp_host']    ?? '');
    $port      = (int) ($_POST['smtp_port']            ?? 587);
    $usuario   = sanitizar($db, $_POST['smtp_usuario'] ?? '');
    $novaSenha = $_POST['smtp_senha']                  ?? '';
    $deEmail   = sanitizar($db, $_POST['smtp_de_email'] ?? '');
    $deNome    = sanitizar($db, $_POST['smtp_de_nome']  ?? 'Sistema ERP');
    $seguranca = in_array($_POST['smtp_seguranca'] ?? 'tls', ['tls','ssl','none'])
                 ? $_POST['smtp_seguranca'] : 'tls';
    $timeout   = (int) ($_POST['timeout'] ?? 30);
    $provedor  = sanitizar($db, $_POST['provedor'] ?? 'custom');

    // Validação por provider
    if ($emailProvider === 'brevo' || $emailProvider === 'resend') {
        if (empty($senderEmail) || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            retornar_json(false, 'E-mail remetente inválido ou ausente.');
        }
        if (empty($senderName)) {
            retornar_json(false, 'Nome do remetente é obrigatório.');
        }
    } else {
        if (empty($host)) {
            retornar_json(false, 'Servidor SMTP é obrigatório.');
        }
        if (empty($usuario)) {
            retornar_json(false, 'Usuário/E-mail é obrigatório.');
        }
        if (empty($deEmail)) {
            retornar_json(false, 'E-mail Remetente é obrigatório.');
        }
    }

    // Busca configuração existente para preservar credenciais não alteradas
    $res       = mysqli_query($db, "SELECT id, smtp_senha, api_key FROM configuracao_smtp ORDER BY id DESC LIMIT 1");
    $existente = $res ? mysqli_fetch_assoc($res) : null;

    // Senha SMTP: preservar a atual se não foi informada nova
    $senhaFinal = '';
    if (!empty($novaSenha)) {
        $senhaFinal = sanitizar($db, $novaSenha);
    } elseif ($existente) {
        $senhaFinal = sanitizar($db, $existente['smtp_senha']);
    }

    // API Key: criptografar se nova foi fornecida; preservar a existente caso contrário
    $apiKeyFinal = '';
    if (!empty($novaApiKey)) {
        try {
            $apiKeyFinal = EmailCrypto::encrypt($novaApiKey);
        } catch (Throwable $e) {
            $apiKeyFinal = sanitizar($db, $novaApiKey); // fallback: salva sem criptografia
        }
    } elseif ($existente && !empty($existente['api_key'])) {
        $apiKeyFinal = sanitizar($db, $existente['api_key']);
    }
    $apiKeyEsc = $apiKeyFinal !== '' ? "'$apiKeyFinal'" : 'NULL';

    $ep = sanitizar($db, $emailProvider);
    $se = $senderEmail !== '' ? "'$senderEmail'" : 'NULL';
    $sn = $senderName  !== '' ? "'$senderName'"  : 'NULL';

    if ($existente) {
        $id  = (int) $existente['id'];
        $sql = "UPDATE configuracao_smtp SET
            email_provider = '$ep', api_key = $apiKeyEsc,
            sender_email = $se, sender_name = $sn,
            provedor = '$provedor', smtp_host = '$host', smtp_port = $port,
            smtp_usuario = '$usuario', smtp_senha = '$senhaFinal',
            smtp_de_email = '$deEmail', smtp_de_nome = '$deNome',
            smtp_seguranca = '$seguranca', timeout = $timeout, smtp_ativo = 1
            WHERE id = $id";
    } else {
        $sql = "INSERT INTO configuracao_smtp
            (email_provider, api_key, sender_email, sender_name, provedor,
             smtp_host, smtp_port, smtp_usuario, smtp_senha,
             smtp_de_email, smtp_de_nome, smtp_seguranca, timeout, smtp_ativo)
            VALUES
            ('$ep', $apiKeyEsc, $se, $sn, '$provedor',
             '$host', $port, '$usuario', '$senhaFinal',
             '$deEmail', '$deNome', '$seguranca', $timeout, 1)";
    }

    if (mysqli_query($db, $sql)) {
        retornar_json(true, 'Configuração salva com sucesso!',
            ['id' => $existente ? (int)$existente['id'] : mysqli_insert_id($db)]);
    } else {
        retornar_json(false, 'Erro ao salvar: ' . mysqli_error($db));
    }
}

// =====================================================================
// TESTAR
// =====================================================================

function _testar($db) {
    $emailDestino = trim($_GET['email'] ?? $_POST['email'] ?? '');

    if (empty($emailDestino) || !filter_var($emailDestino, FILTER_VALIDATE_EMAIL)) {
        retornar_json(false, 'E-mail de destino inválido.');
    }

    try {
        require_once __DIR__ . '/EmailSender.php';
        $sender  = new EmailSender($db, true);
        $sender->enviarTeste($emailDestino);
        $cfg     = $sender->getConfiguracao();

        retornar_json(true, "E-mail de teste enviado para $emailDestino!", [
            'destinatario' => $emailDestino,
            'provider'     => $cfg['provider'],
        ]);
    } catch (Throwable $e) {
        retornar_json(false, 'Falha no envio: ' . $e->getMessage());
    }
}
