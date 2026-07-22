# Guia de Testes e QA

O sistema não possui testes automatizados (Jest/PHPUnit). Os testes são manuais e focados em comportamento.

## 1. Testes de Frontend
- **Navegação**: Verificar se o AppRouter carrega a página, CSS e JS sem recarregar a janela.
- **Console**: Garantir que não há erros vermelhos no Console do DevTools.
- **Responsividade**: Testar a sidebar em modo mobile (hambúrguer) e desktop (expandida/recolhida).

## 2. Testes de Backend
- **Output**: A API NUNCA deve retornar HTML ou Warnings PHP. O response deve ser estritamente JSON.
- **Segurança**: Tentar acessar uma API protegida sem o cookie `PHPSESSID`. Deve retornar 401 ou 403.
- **SQL Injection**: Tentar inserir aspas simples `'` em campos de busca para garantir que o Prepared Statement está funcionando.

## 3. Validação de Entrega
Antes de gerar o `.zip` final, certifique-se de que nenhum caminho absoluto (ex: `C:/Users/...` ou `/home/ubuntu/...`) foi hardcoded no código.
