# Correção de abertura de Ordens de Serviço

A falha observada ao abrir determinadas Ordens de Serviço é causada por registros com `os_chamados.id = 0`. O log fornecido confirma chamadas como `acao=buscar&id=0`; a API rejeitava corretamente esse identificador como inválido. A origem é estrutural: a tabela `os_chamados` não possuía uma chave primária `id` com `AUTO_INCREMENT`, permitindo que inserções posteriores fossem gravadas com valor zero.

| Entrega | Finalidade |
|---|---|
| `api/api_ordens_servico.php` | Filtra todas as consultas de lista e busca pelo tenant da sessão, grava `tenant_id` na criação e interrompe a criação se o banco não devolver um ID válido. |
| `frontend/js/pages/ordens_servico.js` | Abre temporariamente O.S. legadas pelo número, bloqueia ações destrutivas enquanto o ID é zero e impede novas chamadas inválidas `buscar&id=0`. |
| `sql/corrigir_ids_ordens_servico_mysql57.sql` | Faz backup lógico das O.S. afetadas, reatribui IDs únicos aos registros principais e restabelece `PRIMARY KEY(id)` com `AUTO_INCREMENT`. |

## Ordem obrigatória de implantação

Faça um backup completo do banco `inlaud99_erpcondor` pelo phpMyAdmin. Em seguida, importe primeiro o arquivo `sql/corrigir_ids_ordens_servico_mysql57.sql`. O script preserva os dados e registra o resultado em `mt_os_integridade_log`; ele não apaga nenhuma Ordem de Serviço nem tenta associar automaticamente vínculos filhos que tenham ficado com `os_id = 0`.

Depois de conferir que a última linha de `mt_os_integridade_log` possui o status `corrigido`, envie os arquivos PHP e JavaScript do pacote para a raiz do sistema no HostGator, mantendo a estrutura de diretórios. Atualize a página com `Ctrl+F5` para eliminar arquivos JavaScript em cache e teste a abertura de uma O.S. antiga e a criação de uma nova O.S.

> Registros em `os_interacoes`, `os_materiais_usados`, `os_recursos_humanos` ou fotos com `os_id = 0` ficam registrados na auditoria e requerem associação manual somente se existirem. Isso evita ligar histórico de uma O.S. à outra de forma incorreta.

## Validação pós-implantação

| Verificação | Resultado esperado |
|---|---|
| `SELECT COUNT(*) FROM os_chamados WHERE id = 0 OR id IS NULL` | `0` |
| Última linha de `mt_os_integridade_log` | `status = corrigido` |
| Criar nova O.S. | A resposta retorna um `id` maior que zero |
| Abrir O.S. pela lista | Detalhe carregado sem aviso “ID inválido” |
| Usuário de outro tenant | Não visualiza nem consulta O.S. de empresa diferente |
