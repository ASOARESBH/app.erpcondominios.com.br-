<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Atualização dos Arquivos</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .content {
            padding: 30px;
        }
        
        .status-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        
        .status-card.success {
            border-left-color: #28a745;
            background: #d4edda;
        }
        
        .status-card.error {
            border-left-color: #dc3545;
            background: #f8d7da;
        }
        
        .status-card.warning {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        
        .status-card h3 {
            margin-bottom: 10px;
            color: #333;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        th, td {
            padding: 15px;
            text-align: left;
        }
        
        tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        tbody tr:hover {
            background: #e9ecef;
        }
        
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .badge-success {
            background: #28a745;
            color: white;
        }
        
        .badge-error {
            background: #dc3545;
            color: white;
        }
        
        .badge-warning {
            background: #ffc107;
            color: #333;
        }
        
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        
        .summary-card h2 {
            font-size: 36px;
            margin-bottom: 5px;
        }
        
        .summary-card p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Verificação de Atualização</h1>
            <p>Sistema de Verificação de Arquivos do ERP Serra da Liberdade</p>
        </div>
        
        <div class="content">
            <?php
            // Data esperada da atualização (26/12/2024)
            $data_esperada = strtotime('2024-12-26');
            
            // Lista de arquivos que devem ter sido atualizados
            $arquivos_criticos = [
                'dispositivos_console.html',
                'checklist_visualizar.html',
                'checklist_fechar.html',
                'teste_dispositivo.html',
                'configuracao.html',
                'console_acesso.html'
            ];
            
            $arquivos_todos = [
                'abastecimento.html',
                'acesso_morador.html',
                'administrativa.html',
                'cadastro_fornecedor.html',
                'cadastros.html',
                'checklist_alertas.html',
                'checklist_fechar.html',
                'checklist_novo.html',
                'checklist_preencher.html',
                'checklist_veicular.html',
                'checklist_visualizar.html',
                'config_email_log.html',
                'config_email_template.html',
                'config_smtp.html',
                'configuracao.html',
                'console_acesso.html',
                'dispositivos_console.html',
                'entrada_estoque.html',
                'esqueci_senha.html',
                'estoque.html',
                'hidrometro.html',
                'leitura.html',
                'manutencao.html',
                'painel_fornecedor.html',
                'redefinir_senha.html',
                'relatorio_estoque.html',
                'relatorios_hidrometro.html',
                'saida_estoque.html',
                'teste_dispositivo.html'
            ];
            
            $total = 0;
            $atualizados = 0;
            $nao_atualizados = 0;
            $nao_encontrados = 0;
            
            $resultados = [];
            
            foreach ($arquivos_todos as $arquivo) {
                $total++;
                $caminho = __DIR__ . '/' . $arquivo;
                
                if (file_exists($caminho)) {
                    $data_modificacao = filemtime($caminho);
                    $tamanho = filesize($caminho);
                    
                    // Verificar se tem main-container (correção aplicada)
                    $conteudo = file_get_contents($caminho);
                    $tem_correcao = strpos($conteudo, 'class="main-container"') !== false;
                    
                    if ($data_modificacao >= $data_esperada || $tem_correcao) {
                        $status = 'atualizado';
                        $atualizados++;
                    } else {
                        $status = 'desatualizado';
                        $nao_atualizados++;
                    }
                    
                    $resultados[] = [
                        'arquivo' => $arquivo,
                        'status' => $status,
                        'data' => date('d/m/Y H:i:s', $data_modificacao),
                        'tamanho' => number_format($tamanho / 1024, 2) . ' KB',
                        'correcao' => $tem_correcao ? 'Sim' : 'Não',
                        'critico' => in_array($arquivo, $arquivos_criticos)
                    ];
                } else {
                    $nao_encontrados++;
                    $resultados[] = [
                        'arquivo' => $arquivo,
                        'status' => 'nao_encontrado',
                        'data' => '-',
                        'tamanho' => '-',
                        'correcao' => '-',
                        'critico' => in_array($arquivo, $arquivos_criticos)
                    ];
                }
            }
            
            // Determinar status geral
            if ($atualizados == $total) {
                $status_geral = 'success';
                $mensagem_geral = '✅ Todos os arquivos foram atualizados com sucesso!';
            } elseif ($nao_atualizados > 0 || $nao_encontrados > 0) {
                $status_geral = 'error';
                $mensagem_geral = '❌ Alguns arquivos não foram atualizados ou não foram encontrados.';
            } else {
                $status_geral = 'warning';
                $mensagem_geral = '⚠️ Verificação parcial concluída.';
            }
            ?>
            
            <div class="status-card <?php echo $status_geral; ?>">
                <h3><?php echo $mensagem_geral; ?></h3>
                <p>Data da verificação: <?php echo date('d/m/Y H:i:s'); ?></p>
            </div>
            
            <div class="summary">
                <div class="summary-card">
                    <h2><?php echo $total; ?></h2>
                    <p>Total de Arquivos</p>
                </div>
                <div class="summary-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                    <h2><?php echo $atualizados; ?></h2>
                    <p>Atualizados</p>
                </div>
                <div class="summary-card" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                    <h2><?php echo $nao_atualizados; ?></h2>
                    <p>Desatualizados</p>
                </div>
                <div class="summary-card" style="background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);">
                    <h2><?php echo $nao_encontrados; ?></h2>
                    <p>Não Encontrados</p>
                </div>
            </div>
            
            <h3 style="margin-bottom: 15px;">📋 Detalhes dos Arquivos</h3>
            
            <table>
                <thead>
                    <tr>
                        <th>Arquivo</th>
                        <th>Status</th>
                        <th>Data Modificação</th>
                        <th>Tamanho</th>
                        <th>Correção Aplicada</th>
                        <th>Crítico</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados as $resultado): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($resultado['arquivo']); ?></td>
                        <td>
                            <?php
                            if ($resultado['status'] == 'atualizado') {
                                echo '<span class="badge badge-success">✅ Atualizado</span>';
                            } elseif ($resultado['status'] == 'desatualizado') {
                                echo '<span class="badge badge-warning">⚠️ Desatualizado</span>';
                            } else {
                                echo '<span class="badge badge-error">❌ Não Encontrado</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo $resultado['data']; ?></td>
                        <td><?php echo $resultado['tamanho']; ?></td>
                        <td><?php echo $resultado['correcao']; ?></td>
                        <td><?php echo $resultado['critico'] ? '🔴 Sim' : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($nao_atualizados > 0 || $nao_encontrados > 0): ?>
            <div class="status-card error" style="margin-top: 30px;">
                <h3>📝 Ações Necessárias</h3>
                <ul style="margin-left: 20px; margin-top: 10px;">
                    <li>Faça upload do pacote <strong>correcao_layout_completa.zip</strong></li>
                    <li>Extraia os arquivos na pasta raiz do site</li>
                    <li>Verifique as permissões dos arquivos (644)</li>
                    <li>Limpe o cache do navegador</li>
                    <li>Execute esta verificação novamente</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p>ERP Serra da Liberdade - Sistema de Verificação v1.0</p>
            <p>Desenvolvido em 26 de Dezembro de 2024</p>
        </div>
    </div>
</body>
</html>
