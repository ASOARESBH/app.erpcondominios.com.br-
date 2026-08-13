# Correção de Fotos Repetidas em Ordens de Serviço

## Causa confirmada

A análise do dump do banco identificou que as tabelas `os_interacoes` e `os_interacao_fotos` podem existir com a coluna `id` sem **PRIMARY KEY** e sem **AUTO_INCREMENT**. Nessa condição, novas interações podem receber `id = 0`. Como as fotos usam `interacao_id`, várias fotos ficam associadas ao mesmo valor `0` e aparecem em mais de uma interação, inclusive na solução registrada ao finalizar a O.S.

> A finalização normal não envia nem duplica fotos. A repetição é consequência de vínculos ambíguos já gravados com `interacao_id = 0`.

| Componente | Correção aplicada |
|---|---|
| `api_ordens_servico.php` | Interações são filtradas por O.S. e tenant; fotos só são associadas a IDs positivos e válidos. Operações que criam interação são bloqueadas enquanto a integridade não for reparada. |
| `api_imagem_projeto.php` | A foto só é servida quando seu ID, interação, O.S. e tenant correspondem entre si. |
| Migration SQL | Normaliza IDs das tabelas, garante `PRIMARY KEY(id)` e `AUTO_INCREMENT`, e arquiva fotos ambíguas em quarentena antes de desvinculá-las. |

## Implantação obrigatória

1. Faça um backup completo do banco `inlaud99_erpcondor` no phpMyAdmin.
2. Importe `sql/corrigir_integridade_interacoes_os_mysql57.sql` uma única vez. Se uma execução anterior parou no erro de *collation*, use a versão corrigida deste pacote e execute-a novamente: a migration é idempotente e conclui as etapas pendentes.
3. Confira no resultado da migration a tabela `os_integridade_interacoes_log`. As linhas de `os_interacoes` e `os_interacao_fotos` devem estar com ação `corrigida`.
4. Confira `os_interacao_fotos_quarentena`. Ela preserva as fotos cujo vínculo era `interacao_id = 0`; tais fotos não podem ser atribuídas automaticamente sem risco de vinculá-las à interação errada.
5. Envie os arquivos PHP do pacote ao HostGator, preservando a estrutura de pastas.
6. Atualize o navegador com `Ctrl+F5` e abra uma O.S. finalizada. As fotos não devem mais ser repetidas em uma solução que não as possui.
7. Faça uma nova interação com uma foto e, em seguida, finalize uma nova O.S. A foto deverá aparecer apenas na interação que a recebeu.

## Observação sobre os registros legados

A correção prioriza integridade e não apaga arquivos. As fotos ambíguas são copiadas para `os_interacao_fotos_quarentena` e marcadas como desvinculadas na tabela operacional. Caso seja necessário reaproveitá-las, a reclassificação deve ser manual, usando a data, nome original e O.S. de origem como evidência.
