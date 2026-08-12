# Controle de Acesso — Portal do Morador

## Objetivo

Disponibilizar ao morador autenticado um histórico somente-leitura dos acessos destinados à sua unidade. O mesmo histórico deverá ser apresentado de modo consistente no portal web e no aplicativo Flutter.

## Fonte de verdade

A fonte é a tabela `registros_acesso`, já usada pelo módulo administrativo de Controle de Acesso. Não haverá duplicação de registros em novas tabelas. O endpoint específico do Portal do Morador somente consulta essa fonte de forma filtrada.

| Campo retornado | Origem | Finalidade no portal |
|---|---|---|
| `id` | `registros_acesso.id` | Identificador técnico do evento |
| `data_hora` | `registros_acesso.data_hora` | Data e hora do acesso |
| `placa`, `modelo`, `cor` | Registro do veículo | Identificação do veículo, quando disponível |
| `tipo` | `registros_acesso.tipo` | Morador, Visitante ou Prestador |
| `nome` | `nome_visitante` ou morador vinculado | Identificação do acesso, quando disponível |
| `tipo_acesso` | `registros_acesso.tipo_acesso` | Entrada ou saída; entrada é o padrão em bases antigas |
| `status` | `registros_acesso.status` | Situação operacional registrada pela portaria |
| `unidade_destino` | Registro ou unidade do morador | Unidade relacionada ao evento |

## Regra de segurança multi-tenant

O token Bearer do Portal do Morador determina de forma exclusiva `tenant_id` e `morador_id`. A API busca a unidade diretamente no cadastro desse morador e aplica sempre:

```text
registros_acesso.tenant_id = tenant da sessão
AND (registros_acesso.morador_id = morador da sessão
     OR registros_acesso.unidade_destino corresponde à unidade da sessão)
```

A comparação textual de unidade remove espaços e diferenciação de maiúsculas/minúsculas, além de aceitar o prefixo legado `GLEBA`. O identificador do morador e o tenant jamais são recebidos como parâmetro confiável do cliente.

## Limites e privacidade

A API limita a consulta a 100 registros por chamada, admite filtro por período e tipo, e retorna somente dados necessários para o acompanhamento de acessos da unidade. O histórico não permite editar, excluir, liberar ou criar acessos pelo Portal do Morador.

## Diagnóstico

Cada consulta é registrada em `logs/portal_controle_acesso.log` com tenant, morador, quantidade retornada e filtros — sem token, CPF ou outros segredos.

## Compatibilidade

A consulta trata `tipo_acesso` como coluna opcional e usa `Entrada` como valor padrão em instalações que ainda não executaram a atualização administrativa de registros.

## Experiência de uso

No aplicativo, o menu é inserido abaixo de Projetos. No portal web, a aba é inserida imediatamente após Projetos tanto na navegação de desktop quanto no menu mobile. Ambos oferecem filtros por data e tipo, atualização manual, cartões de entrada/saída e estado vazio explicativo.
