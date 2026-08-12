# Incidente: abertura de Ordens de Serviço com ID zero

## Evidências fornecidas

- Na tela de Ordens de Serviço, o usuário recebe o aviso **"Erro ao carregar OS"** ao abrir determinados registros.
- O console mostra a resposta da API: `{sucesso: false, mensagem: 'ID inválido'}`.
- O log de `api_ordens_servico.php` enviado pelo usuário registra chamadas válidas, como `buscar&id=242` e `buscar&id=253`, seguidas pelos carregamentos de interações e materiais.
- O mesmo log registra chamadas inválidas recorrentes: `GET acao=buscar&id=0` nas linhas 597, 598 e 619–621 do arquivo `pasted_content_3.txt`.

## Hipótese de diagnóstico

A falha ocorre no frontend: pelo menos um item renderizado dispara `osVerDetalhe(0)`, causando a chamada `buscar&id=0`. A API responde corretamente ao recusar ID não positivo. A correção deve preservar a validação do backend, garantir que toda listagem traga `os_chamados.id` corretamente e impedir o frontend de abrir detalhes quando o valor de ID estiver ausente ou inválido.

## Fontes locais

- Log fornecido: `/home/ubuntu/upload/pasted_content_3.txt`
- Capturas fornecidas: `/home/ubuntu/upload/pasted_file_PE19cv_image.png` e `/home/ubuntu/upload/pasted_file_OPNyoM_image.png`
- Frontend: `frontend/js/pages/ordens_servico.js`
- Backend: `api/api_ordens_servico.php`
