# Plano do Hub Local Multimodal — ERP CONDOMÍNIOS

## Conclusão arquitetural

A integração de LPR, TAG/UHF, Face ID e futuros dispositivos deve convergir para a **API de ingestão do ERP CONDOMÍNIOS MONITORING**. A comunicação física com cada fabricante permanece no agente Windows local, por adaptadores específicos.

```text
Câmera RTSP + FastALPR ─┐
Control iD TAH UHF ─────┼─> Hub Windows ─> API Monitoring ─> MySQL/ERP
Terminal Face ID ───────┤
Frigate/MQTT ───────────┘
```

O ERP web continua como fonte de verdade de tenant, usuários, veículos, moradores, visitantes, permissões, dispositivos administrativos, histórico e auditoria. O agente não acessa MySQL e o PHP não abre RTSP nem executa OCR.

## O que existe atualmente

A tela `frontend/pages/dispositivos.html` já possui cadastro multifabricante para Control iD, Intelbras, Hikvision e Genérico, além de abas de eventos e fila de comandos. O módulo `frontend/js/pages/dispositivos.js` já organiza `tipo_leitor` (`uhf`, `rfid`, `facial`, `biometria`, `qrcode`, `outro`) e `tipo_integracao` (`bridge_local`, `monitor_nativo`, `manual`).

A base Control iD possui três caminhos legados: `api/rfid.php` para TAG/UHF, `api/controlid_monitor.php` para Monitor nativo e `api/controlid/_helper.php` para push e normalização de TAG, card e usuário. Eles atualmente escrevem diretamente em `registros_acesso` e usam tabelas/identificadores específicos do legado.

A API `api_monitoramento.php` já possui autenticação por agente, pareamento, heartbeat, outbox idempotente e ingestão de eventos, mas o schema atual de `monitoramento_eventos` ainda exige campos de placa e o `monitoramento_cameras` trata somente câmeras RTSP.

A API `api_face_id.php` atual é centrada no cadastro e validação de visitantes por descritores faciais. Ela não constitui uma integração genérica com um terminal Face ID local.

## Decisão sobre a API

A API `api_monitoramento.php` será a porta única para eventos originados pelo hub local. Os endpoints de login, heartbeat e ingestão continuam os mesmos. O payload de eventos será ampliado de forma compatível, mantendo os campos LPR atuais para não interromper o FastALPR.

Cada evento deverá informar, no mínimo:

| Campo | Finalidade |
|---|---|
| `event_uuid` | Idempotência por agente e evento. |
| `source_type` | `lpr`, `tag`, `uhf`, `face_id`, `rfid`, `qrcode`, `biometria` ou `outro`. |
| `source_protocol` | `rtsp_fastalpr`, `controlid_access_logs`, `controlid_push`, `mqtt`, `http` ou outro protocolo permitido. |
| `device_key` | Dispositivo local reportado pelo agente. |
| `direction` | `entrada`, `saida` ou `indeterminado`. |
| `decision` | `identified`, `authorized`, `denied`, `unknown` ou `inconclusive`. |
| `identifier_type` | `plate`, `tag`, `card`, `controlid_user`, `face_match`, `qrcode` ou outro. |
| `identifier_value` | Valor necessário para correlação, protegido por TLS e sujeito à política do tenant. |
| `identifier_hash` | Hash para busca quando não for necessário armazenar o valor em claro. |
| `confidence` | Confiança geral; subcampos podem registrar detecção, OCR ou similaridade. |
| `subject_external_id` | ID externo do usuário/visitante/veículo quando fornecido pelo equipamento. |
| `metadata_json` | Metadados sanitizados, sem senha, token, descritor facial ou URL de câmera. |

A resposta permanece com `accepted`, `duplicates`, `rejected` e `results`, incluindo `match_status`, `decision` e `registro_acesso_id` quando houver projeção.

## Control iD TAH UHF

O leitor TAH UHF já funciona localmente e deve ser incorporado ao hub por um adaptador Control iD. A lógica existente de login em `/login.fcgi`, leitura incremental de `access_logs` em `/load_objects.fcgi`, controle do último ID, `card_value`, `user_id`, `portal_id` e evento deve ser reaproveitada.

A mudança é o destino: o adaptador deve produzir um evento local e colocá-lo na mesma outbox SQLite do LPR. Ele não deve continuar chamando diretamente `bridge_receiver.php` ou escrevendo em `registros_acesso` por caminhos paralelos.

Para cada leitor Control iD deve existir uma única fonte primária: **Hub Local**, **Monitor Nativo** ou **Bridge legado**. Não se deve ativar duas fontes para o mesmo equipamento, pois o mesmo `access_log` poderá ser inserido duas vezes. Durante a migração, os caminhos legados devem permanecer somente para compatibilidade e receber uma marcação de origem.

## Face ID

Se o terminal facial for Control iD, o primeiro adaptador poderá consumir os mesmos `access_logs` e interpretar `user_id`, evento, portal e horário como `source_type = face_id` quando o equipamento registrar esse modo de identificação.

Se o terminal for Intelbras, Hikvision ou outro fabricante, será necessário confirmar fabricante, modelo, firmware, IP/porta e mecanismo de integração: push HTTP, API REST, SDK, MQTT ou exportação de eventos. Não é seguro inventar uma URL ou protocolo.

A regra de privacidade é não enviar descritor facial, template biométrico ou foto a cada evento. O evento deve transportar o resultado de correspondência e um identificador autorizado. O cadastro de descritores de visitantes permanece separado até que o vínculo entre visitante, morador, terminal e política do tenant seja definido.

## Banco de dados

A evolução deve ser aditiva e compatível com MySQL/MariaDB 5.7. A migration deverá:

1. criar `monitoramento_dispositivos` para representar câmera, leitor UHF, terminal facial, controlador, Frigate e dispositivos futuros;
2. ampliar `monitoramento_eventos` com origem, protocolo, identificador, decisão, confiança geral, ID externo e metadados sanitizados;
3. tornar os campos exclusivos de LPR anuláveis para eventos que não possuam placa;
4. preservar `tenant_id`, `agente_id`, `event_uuid`, retenção, auditoria e a chave de idempotência;
5. criar índices por tenant, dispositivo, origem, identificador hash e horário;
6. manter `monitoramento_cameras` como compatibilidade durante a transição.

A projeção para `registros_acesso` ocorrerá somente quando houver correlação autorizada com veículo, morador, visitante ou usuário. Eventos desconhecidos ou inconclusivos permanecem no histórico multimodal e não abrem portão automaticamente.

## Tela única no Monitoring

O painel Windows deverá apresentar uma linha do tempo única chamada **Acesso local**, com filtros por origem, dispositivo, decisão, período e status da sincronização. O cadastro técnico ficará separado em abas:

| Aba | Conteúdo |
|---|---|
| Câmeras / LPR | RTSP local, FastALPR, confiança, deduplicação e estado. |
| Control iD / TAG-UHF | IP local, modo de coleta, último `access_log`, leitor e sentido. |
| Face ID | Terminal, modo de identificação, limiar, estado e política de privacidade. |
| Outros | Protocolo, endereço, credencial protegida e adaptador selecionado. |
| Fila / Saúde | Outbox, retries, heartbeat, latência e últimos erros. |

A senha do equipamento e a URL completa ficam somente no computador local, protegidas pelo cofre do Windows. O ERP recebe metadados administrativos e estados de saúde.

## Tela Dispositivos no ERP

A página `Acesso → Dispositivos` deve ser o catálogo administrativo do hub. A seção Monitoring deverá listar as máquinas Windows e, abaixo de cada agente, os dispositivos reportados. O cadastro deve informar fabricante, modelo, tipo de dispositivo, protocolo, sentido, local, agente e status.

O ERP deve permitir habilitar/revogar máquinas, configurar política do tenant e visualizar estados. Ele não deve exibir senha, URL RTSP completa, descritor facial ou credencial operacional.

## Segurança e operação

Todos os eventos devem entrar por sessão bearer de agente, com tenant derivado do vínculo no servidor. O corpo nunca escolhe o tenant. O agente envia somente dispositivos associados ao próprio `agent_id`. A outbox local deve suportar queda de internet, resposta por evento e reenvio idempotente.

No primeiro ciclo, o hub é de captura, identificação, relato e alimentação do ERP. A abertura física de porta continua fora do contrato multimodal. Se futuramente for autorizada, deverá existir um comando separado, assinado, auditado e com política explícita.

## Critérios de aceite

A evolução será aprovada quando uma leitura LPR, uma TAG/UHF e um evento Face ID homologado entrarem na mesma linha do tempo, chegarem ao ERP pela mesma API, não duplicarem após retry, preservarem o tenant correto, continuarem funcionando com internet interrompida e exibirem origem/dispositivo/decisão no histórico.

## Dependências para implementação do Face ID

Para concluir a integração facial real, o usuário deverá informar o fabricante, modelo e firmware do terminal facial e confirmar se ele é Control iD ou outro equipamento. Esse dado define se será possível reaproveitar `access_logs`, consumir push HTTP, consultar API local ou instalar um SDK específico.

## Referências

[1]: https://github.com/ASOARESBH/erp-condominios-monitoring "Repositório do ERP CONDOMÍNIOS MONITORING"
[2]: https://github.com/ASOARESBH/app.erpcondominios.com.br- "Repositório do ERP web"
[3]: https://ankandrew.github.io/fast-alpr/latest/installation/ "FastALPR — instalação e backends"
[4]: https://docs.frigate.video/configuration/license_plate_recognition/ "Frigate — License Plate Recognition"
