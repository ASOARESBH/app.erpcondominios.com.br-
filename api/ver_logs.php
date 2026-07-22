<?php
// =====================================================
// VISUALIZADOR DE LOGS DE DEBUG
// =====================================================

header('Content-Type: application/json; charset=utf-8');

// Verificar se pasta de logs existe
$log_dir = __DIR__ . '/logs';

if (!file_exists($log_dir)) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Pasta de logs não encontrada',
        'caminho' => $log_dir
    ], JSON_PRETTY_PRINT);
    exit;
}

// Obter lista de arquivos de log
$log_files = glob($log_dir . '/debug_*.log');

// Ordenar por data (mais recente primeiro)
usort($log_files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

// Parâmetros
$arquivo = isset($_GET['arquivo']) ? basename($_GET['arquivo']) : null;
$linhas = isset($_GET['linhas']) ? intval($_GET['linhas']) : 100;
$buscar = isset($_GET['buscar']) ? $_GET['buscar'] : null;

// Se não especificou arquivo, pegar o mais recente
if (!$arquivo && !empty($log_files)) {
    $arquivo = basename($log_files[0]);
}

$resultado = [
    'timestamp' => date('Y-m-d H:i:s'),
    'arquivos_disponiveis' => array_map('basename', $log_files),
    'total_arquivos' => count($log_files)
];

// Se especificou arquivo, ler conteúdo
if ($arquivo) {
    $arquivo_path = $log_dir . '/' . $arquivo;
    
    if (file_exists($arquivo_path)) {
        $conteudo = file_get_contents($arquivo_path);
        
        // Se especificou busca, filtrar
        if ($buscar) {
            $linhas_array = explode("\n", $conteudo);
            $linhas_filtradas = array_filter($linhas_array, function($linha) use ($buscar) {
                return stripos($linha, $buscar) !== false;
            });
            $conteudo = implode("\n", $linhas_filtradas);
        }
        
        // Limitar número de linhas
        $linhas_array = explode("\n", $conteudo);
        $total_linhas = count($linhas_array);
        
        if ($total_linhas > $linhas) {
            $linhas_array = array_slice($linhas_array, -$linhas);
        }
        
        $resultado['arquivo_atual'] = $arquivo;
        $resultado['tamanho'] = filesize($arquivo_path);
        $resultado['tamanho_formatado'] = number_format(filesize($arquivo_path) / 1024, 2) . ' KB';
        $resultado['ultima_modificacao'] = date('Y-m-d H:i:s', filemtime($arquivo_path));
        $resultado['total_linhas'] = $total_linhas;
        $resultado['linhas_exibidas'] = count($linhas_array);
        $resultado['conteudo'] = implode("\n", $linhas_array);
        $resultado['sucesso'] = true;
    } else {
        $resultado['sucesso'] = false;
        $resultado['mensagem'] = 'Arquivo não encontrado';
    }
} else {
    $resultado['sucesso'] = false;
    $resultado['mensagem'] = 'Nenhum arquivo de log disponível';
}

// Adicionar instruções de uso
$resultado['instrucoes'] = [
    'ver_arquivo_especifico' => 'ver_logs.php?arquivo=debug_2026-01-08.log',
    'limitar_linhas' => 'ver_logs.php?linhas=50',
    'buscar_texto' => 'ver_logs.php?buscar=ERRO',
    'combinar' => 'ver_logs.php?arquivo=debug_2026-01-08.log&linhas=100&buscar=LOGIN'
];

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
