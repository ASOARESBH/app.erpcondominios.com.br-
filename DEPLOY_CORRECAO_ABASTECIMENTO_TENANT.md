# Deploy — Correção de Veículos e Operadores no Abastecimento

## Causa identificada

As ações `listar_veiculos` e `listar_usuarios` chamavam funções que usavam `$tenant_id` fora do seu escopo PHP. A query era montada sem valor após `tenant_id =`, o que produzia HTTP 500 e deixava os seletores vazios.

## Correção aplicada

A API agora recebe o tenant autenticado explicitamente nas funções de veículos e operadores. As consultas usam `prepared statements`, filtram `tenant_id = ?` e retornam JSON padronizado. Também foram corrigidos o cadastro de veículo e o lançamento de abastecimento para gravar e validar o tenant da sessão, impedindo vínculo entre veículos ou operadores de condomínios diferentes.

## Arquivo para deploy

| Caminho | Ação |
|---|---|
| `api/api_abastecimento.php` | Sobrescrever no HostGator. |

Não há migration nesta correção: o `tenant_id` já faz parte das tabelas de abastecimento pela migration multi-tenant existente.

## Validação após upload

Acesse **Abastecimento → Lançamento** e atualize com `Ctrl+F5`. O seletor **Veículo** deve carregar somente veículos do condomínio da sessão; o seletor **Operador** deve listar somente usuários ativos do mesmo tenant. O histórico de abastecimentos deve continuar visível apenas para o tenant autenticado.

Se houver falha, consulte o log PHP pelo prefixo `[ABASTECIMENTO]`. Ele registra o tenant e a quantidade retornada, sem expor dados pessoais ou valores de sessão.
