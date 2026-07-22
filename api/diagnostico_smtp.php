<?php
/**
 * Script de Diagnóstico SMTP
 * 
 * Verifica conectividade e configuração do servidor SMTP
 * Ajuda a identificar problemas de conexão
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cores para output
$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[34m";
$reset = "\033[0m";

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║        DIAGNÓSTICO DE CONECTIVIDADE SMTP                  ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Conectar ao banco
echo "[1] Conectando ao banco de dados...\n";
require_once 'config.php';

$conexao = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conexao) {
    echo "${vermelho}❌ ERRO ao conectar ao banco${reset}\n";
    echo "Erro: " . mysqli_connect_error() . "\n";
    exit;
}
echo "${verde}✅ Banco conectado${reset}\n\n";

// Buscar configuração SMTP
echo "[2] Buscando configuração SMTP no banco...\n";
$sql = "SELECT * FROM configuracao_smtp LIMIT 1";
$resultado = mysqli_query($conexao, $sql);

if (!$resultado || mysqli_num_rows($resultado) == 0) {
    echo "${vermelho}❌ Nenhuma configuração SMTP encontrada${reset}\n";
    echo "Você precisa salvar a configuração SMTP primeiro!\n";
    mysqli_close($conexao);
    exit;
}

$config = mysqli_fetch_assoc($resultado);
echo "${verde}✅ Configuração encontrada${reset}\n\n";

// Exibir configuração
echo "[3] Configuração SMTP Atual:\n";
echo "────────────────────────────────────────────────────────────\n";
echo "Servidor SMTP: " . $config['smtp_host'] . "\n";
echo "Porta:         " . $config['smtp_port'] . "\n";
echo "Usuário:       " . $config['smtp_usuario'] . "\n";
echo "Segurança:     " . strtoupper($config['smtp_seguranca']) . "\n";
echo "De Nome:       " . $config['smtp_de_nome'] . "\n";
echo "De Email:      " . $config['smtp_de_email'] . "\n";
echo "Ativo:         " . ($config['smtp_ativo'] ? "Sim" : "Não") . "\n";
echo "────────────────────────────────────────────────────────────\n\n";

// Validar configuração
echo "[4] Validando Configuração:\n";
$erros = [];

if (empty($config['smtp_host'])) {
    $erros[] = "Servidor SMTP vazio";
}
if (empty($config['smtp_port'])) {
    $erros[] = "Porta SMTP vazia";
}
if (empty($config['smtp_usuario'])) {
    $erros[] = "Usuário SMTP vazio";
}
if (empty($config['smtp_senha'])) {
    $erros[] = "Senha SMTP vazia";
}
if (empty($config['smtp_de_email'])) {
    $erros[] = "E-mail de origem vazio";
}

if (!empty($erros)) {
    echo "${vermelho}❌ Erros encontrados:${reset}\n";
    foreach ($erros as $erro) {
        echo "  - $erro\n";
    }
    echo "\nCorreja os campos vazios e tente novamente!\n";
    mysqli_close($conexao);
    exit;
}

echo "${verde}✅ Configuração válida${reset}\n\n";

// Testar conectividade
echo "[5] Testando Conectividade com Servidor SMTP:\n";
echo "────────────────────────────────────────────────────────────\n";

$host = $config['smtp_host'];
$port = intval($config['smtp_port']);

echo "Tentando conectar em: $host:$port\n";

// Determinar tipo de conexão
$errno = 0;
$errstr = '';
$timeout = 10;

// Testar conexão
if ($config['smtp_seguranca'] == 'ssl') {
    $host = 'ssl://' . $host;
}

$socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

if (!$socket) {
    echo "${vermelho}❌ FALHA na conexão${reset}\n";
    echo "Erro: $errstr (Código: $errno)\n\n";
    
    echo "Possíveis causas:\n";
    echo "1. Servidor SMTP inativo ou não existe\n";
    echo "2. Porta bloqueada pelo firewall\n";
    echo "3. Endereço do servidor está incorreto\n";
    echo "4. Problema de DNS\n";
    echo "5. Servidor não permite conexão de seu IP\n\n";
    
    echo "Soluções:\n";
    echo "1. Verificar se o servidor SMTP está correto\n";
    echo "2. Verificar se a porta está correta\n";
    echo "3. Testar ping para o servidor: ping $host\n";
    echo "4. Verificar com seu provedor de e-mail\n";
    echo "5. Tentar porta alternativa (587, 465, 25)\n";
    
} else {
    echo "${verde}✅ CONEXÃO ESTABELECIDA${reset}\n";
    
    // Ler resposta do servidor
    $response = fgets($socket, 1024);
    echo "Resposta do servidor: " . trim($response) . "\n";
    
    // Fechar conexão
    fclose($socket);
    
    echo "\n${verde}✅ Servidor SMTP está respondendo${reset}\n";
    echo "O problema pode estar em:\n";
    echo "1. Autenticação (usuário/senha incorretos)\n";
    echo "2. Configuração de segurança (TLS/SSL)\n";
    echo "3. Permissões de envio\n";
}

echo "\n";

// Testar DNS
echo "[6] Testando Resolução DNS:\n";
echo "────────────────────────────────────────────────────────────\n";

$host_sem_protocolo = str_replace(['ssl://', 'tls://'], '', $host);
$ip = @gethostbyname($host_sem_protocolo);

if ($ip === $host_sem_protocolo) {
    echo "${vermelho}❌ DNS não resolvido${reset}\n";
    echo "Não foi possível resolver: $host_sem_protocolo\n";
} else {
    echo "${verde}✅ DNS resolvido${reset}\n";
    echo "Domínio: $host_sem_protocolo\n";
    echo "IP:      $ip\n";
}

echo "\n";

// Verificar extensões PHP
echo "[7] Verificando Extensões PHP:\n";
echo "────────────────────────────────────────────────────────────\n";

$extensoes = ['openssl', 'sockets', 'curl'];
foreach ($extensoes as $ext) {
    if (extension_loaded($ext)) {
        echo "${verde}✅ $ext${reset}\n";
    } else {
        echo "${amarelo}⚠️  $ext (não carregada)${reset}\n";
    }
}

echo "\n";

// Recomendações
echo "[8] Recomendações:\n";
echo "────────────────────────────────────────────────────────────\n";

echo "Se o servidor SMTP não está respondendo:\n\n";

echo "Para Gmail:\n";
echo "  Servidor: smtp.gmail.com\n";
echo "  Porta: 587 (TLS) ou 465 (SSL)\n";
echo "  Usuário: seu-email@gmail.com\n";
echo "  Senha: Senha de app (não a senha normal)\n";
echo "  Ativar: Acesso a apps menos seguros\n\n";

echo "Para Outlook/Hotmail:\n";
echo "  Servidor: smtp-mail.outlook.com\n";
echo "  Porta: 587 (TLS)\n";
echo "  Usuário: seu-email@outlook.com\n";
echo "  Senha: Sua senha\n\n";

echo "Para Servidor Local:\n";
echo "  Servidor: localhost ou 127.0.0.1\n";
echo "  Porta: 25\n";
echo "  Usuário: (deixar em branco)\n";
echo "  Senha: (deixar em branco)\n\n";

echo "────────────────────────────────────────────────────────────\n";
echo "Próximos passos:\n";
echo "1. Verificar configuração SMTP em config_smtp.html\n";
echo "2. Testar novamente após corrigir\n";
echo "3. Verificar logs do servidor\n";
echo "────────────────────────────────────────────────────────────\n\n";

mysqli_close($conexao);

echo "${azul}Diagnóstico concluído!${reset}\n\n";
?>
