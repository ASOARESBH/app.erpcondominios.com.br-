<?php
/**
 * Dedicated file logger for e-mail/SMTP diagnostics.
 */

if (!function_exists('email_error_log_path')) {
    function email_error_log_path() {
        return __DIR__ . '/../logs/erroe_email/email_' . date('Y-m-d') . '.log';
    }
}

if (!function_exists('email_error_sanitize')) {
    function email_error_sanitize($value) {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $keyText = strtolower((string)$key);
                if (in_array($keyText, ['senha_tamanho', 'password_length'], true)) {
                    $clean[$key] = email_error_sanitize($item);
                    continue;
                }
                if (preg_match('/senha|password|pass|token|secret|authorization/', $keyText)) {
                    $clean[$key] = '[REDACTED]';
                    continue;
                }
                $clean[$key] = email_error_sanitize($item);
            }
            return $clean;
        }

        if (is_object($value)) {
            return get_class($value);
        }

        return $value;
    }
}

if (!function_exists('email_error_log')) {
    function email_error_log($level, $message, array $context = []) {
        $path = email_error_log_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => strtoupper((string)$level),
            'message' => (string)$message,
            'request' => [
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'uri' => $_SERVER['REQUEST_URI'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ],
            'session' => [
                'usuario_id' => $_SESSION['usuario_id'] ?? null,
                'usuario_email' => $_SESSION['usuario_email'] ?? null,
                'usuario_nome' => $_SESSION['usuario_nome'] ?? null,
            ],
            'context' => email_error_sanitize($context),
        ];

        @file_put_contents(
            $path,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}

if (!function_exists('email_error_log_exception')) {
    function email_error_log_exception($level, $message, $exception, array $context = []) {
        $context['exception'] = [
            'class' => is_object($exception) ? get_class($exception) : null,
            'message' => is_object($exception) && method_exists($exception, 'getMessage') ? $exception->getMessage() : null,
            'file' => is_object($exception) && method_exists($exception, 'getFile') ? $exception->getFile() : null,
            'line' => is_object($exception) && method_exists($exception, 'getLine') ? $exception->getLine() : null,
            'trace' => is_object($exception) && method_exists($exception, 'getTraceAsString') ? $exception->getTraceAsString() : null,
        ];

        email_error_log($level, $message, $context);
    }
}

if (!function_exists('email_error_install_shutdown_handler')) {
    function email_error_install_shutdown_handler() {
        register_shutdown_function(function() {
            $error = error_get_last();
            if (!$error) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($error['type'], $fatalTypes, true)) {
                return;
            }

            email_error_log('FATAL', 'Fatal error in e-mail API flow', [
                'php_error' => $error,
                'get' => $_GET ?? [],
                'post' => $_POST ?? [],
            ]);

            if (!headers_sent()) {
                if (ob_get_length()) {
                    @ob_clean();
                }
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Erro interno no fluxo de e-mail. Consulte logs/erroe_email.',
                ], JSON_UNESCAPED_UNICODE);
            }
        });
    }
}
