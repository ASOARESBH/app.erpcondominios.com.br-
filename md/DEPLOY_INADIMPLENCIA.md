

## Atualização — Histórico persistente e comparação gerencial

A partir desta versão, cada importação concluída é mantida como um **snapshot imutável por tenant**. O sistema grava todos os lançamentos, o PDF no armazenamento BLOB, os totais de conciliação e o resultado do comparativo com o snapshot imediatamente anterior do mesmo condomínio.

Antes do deploy, execute `sql/migration_inadimplencia_comparacoes_mysql57.sql` no banco `inlaud99_erpcondor`. O script é compatível com MySQL/MariaDB 5.7 e cria apenas a tabela `inadimplencia_comparacoes`; ele não altera títulos, baixas ou negociações financeiras.

Na abertura da tela, o sistema consulta inicialmente somente o histórico de snapshots concluídos. Se houver registros para o tenant autenticado, o último snapshot é recuperado e o dashboard é exibido. Se não houver histórico, somente o formulário de PDF permanece visível. Assim, o estado inicial limpo é preservado para novos condomínios, enquanto dados já importados voltam a ser acessíveis após recarregar a página.

Após cada nova importação, a comparação identifica novas Glebas inadimplentes, aumento de saldo, reduções, quitações e risco alto por aumentos em duas importações consecutivas. O resultado é persistido e exibido no painel **Prioridades do gestor**. Importações idênticas continuam auditáveis, mas são identificadas como **Sem alteração relevante**.

### Roteiro de validação

1. Importe um relatório BRCondos e confirme o status **Baseline criado**.
2. Atualize a página: o dashboard e o histórico devem reaparecer automaticamente.
3. Importe um relatório de período posterior e confirme o status **Comparação atualizada**.
4. Verifique o painel de prioridades, a variação do período, as listas de novas Glebas, evoluções e regularizações.
5. No histórico, selecione snapshots anteriores e confirme que cada relatório permanece consultável sem misturar dados de outro tenant.
6. Execute a consulta de validação presente ao final da migration e confirme que cada `importacao_atual_id` tem no máximo uma comparação por tenant.
