# Diagnóstico do erro na detecção de veículo por placa — 2026-09-17

## Sintoma

Ao informar uma placa no Registro Manual, o console apresentava `TypeError: Cannot set properties of null (setting 'value')` em `esconderVeiculoEncontrado()`. O erro aparecia tanto no caminho de placa não encontrada quanto no tratamento da exceção de consulta.

## Causa

A função tentava executar `document.getElementById('moradorId').value = ''` e `document.getElementById('veiculoId').value = ''` sem confirmar que os elementos existiam no DOM. O módulo é carregado por uma SPA, e o ERP possui cópias históricas da tela de Registro; durante uma divergência de HTML em cache ou carregamento de uma versão antiga, esses campos podem não estar presentes. O mesmo padrão também existia em resets de morador, visitante, ocupante e no preenchimento automático de placa.

Quando a API não encontrava um veículo, `detectarVeiculoPorPlaca()` chamava `esconderVeiculoEncontrado()`. A ausência de `#moradorId` gerava a exceção. O `catch` então chamava a mesma função novamente, produzindo o segundo erro `Uncaught (in promise)` observado na tela.

## Correção

`frontend/js/pages/registro.js` passou a usar `setCampoValue(id, value)` para todas as atribuições diretas aos campos ocultos e inputs que podem não existir durante uma transição de página ou em HTML legado. O helper só grava o valor quando o elemento foi encontrado.

A correção cobre:

- limpeza após placa não encontrada;
- preenchimento automático de modelo, cor, morador e veículo;
- troca de tipo de registro;
- seleção de morador;
- busca de visitante e ocupante;
- limpeza do formulário e modo pedestre.

A lógica funcional do lançamento não foi alterada. Em uma página canônica completa, os campos continuam sendo preenchidos normalmente; em uma página antiga ou durante a desmontagem da SPA, a ausência do elemento não interrompe o fluxo.

## Validação

Foi executado `node --check frontend/js/pages/registro.js`, sem erros de sintaxe, além de uma auditoria que não encontrou acessos restantes no formato direto `document.getElementById(...).value` no módulo.

Se o erro reaparecer com a correção publicada, deve-se verificar o HTML efetivamente entregue pelo servidor e o cache do navegador, pois a tela canônica `frontend/pages/registro.html` contém os campos `moradorId` e `veiculoId`. A correção defensiva evita a quebra imediata, mas o deploy deve manter o HTML e o JavaScript da mesma versão.

A consulta também passou a rejeitar explicitamente respostas HTTP diferentes de 2xx e respostas que não sejam JSON. Assim, o console passa a indicar, por exemplo, `Consulta de veículo falhou (HTTP 401)` ou `HTTP 500`, em vez de tratar uma página de login/erro como se fosse uma placa não encontrada. Uma placa inexistente continua sendo um retorno JSON válido com `existe: false` e não é erro de sistema.
