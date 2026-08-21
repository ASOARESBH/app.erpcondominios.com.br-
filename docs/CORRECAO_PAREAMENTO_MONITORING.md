# Correção do pareamento do ERP CONDOMÍNIOS MONITORING

## Causa identificada

O agente mantinha um código inicial apenas no SQLite local. Esse código podia ser exibido no painel antes que a solicitação `solicitar_pareamento` fosse confirmada pela API. Quando o operador colava esse valor em **Acesso → Dispositivos**, o ERP respondia que não havia solicitação pendente.

Também havia um retorno pouco útil no painel local: falhas de rede durante o login eram apresentadas como `Failed to fetch`, sem distinguir indisponibilidade da API de credencial recusada.

## Comportamento corrigido

O painel só apresenta o código como utilizável depois que a API confirma a solicitação remota. Se a API estiver indisponível, o agente retorna ao estado `novo`, registra o código de erro no SQLite e orienta o operador a verificar a conectividade.

Uma instalação já ativa não é sobrescrita silenciosamente. A API informa `AGENT_ALREADY_REGISTERED` e exige que o operador revogue a máquina no ERP antes de gerar novo pareamento.

Solicitações pendentes podem ser limpas por **Limpar solicitação** ou pela ação **Limpar pendente** na lista. A limpeza é uma revogação lógica: preserva histórico e invalida o hash do código; não remove fisicamente eventos, sessões ou auditoria.

## Procedimento de recuperação

1. No painel local, clique em **Gerar novo pareamento**. Não copie o código exibido antes dessa ação.
2. No ERP, abra **Acesso → Dispositivos → ERP CONDOMÍNIOS MONITORING**.
3. Se houver uma máquina ativa com a mesma instalação, use **Revogar**. Se houver uma pendência antiga, use **Limpar pendente** ou cole o código antigo em **Limpar solicitação**.
4. Copie o novo código somente depois que o painel informar que a solicitação foi registrada na API.
5. Cole o código em **Habilitar esta máquina** e guarde a credencial exibida uma única vez.
6. No painel local, informe usuário, senha do ERP e a nova credencial da máquina.

## Diagnóstico de rede

Se o painel apresentar uma mensagem de indisponibilidade da API, verifique no Windows:

```powershell
Test-NetConnection app.erpcondominios.com.br -Port 443
Invoke-WebRequest https://app.erpcondominios.com.br/api/api_monitoramento.php?action=acessos_recentes -UseBasicParsing
```

A segunda chamada pode retornar `401`, o que é esperado sem sessão; o importante é receber uma resposta HTTP do servidor, e não erro de DNS, TLS ou conexão.

## Segurança e retenção

A exclusão é lógica para preservar auditoria e relacionamentos históricos. Uma nova habilitação reutiliza a identidade de instalação depois que a credencial anterior foi revogada, mas gera novo segredo operacional e novo código de pareamento.
