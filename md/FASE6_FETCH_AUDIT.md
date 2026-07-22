# FASE 6 — Fetch() Audit (Resumo)

Data: 2026-02-07

Resumo rápido:
- Total (scan inicial): ~200 ocorrências de `fetch()` detectadas em `frontend/` e `js/`.
- Padrões recorrentes:
  - Uso inconsistente de caminhos: `api/...` (incorreto dentro de `frontend/`) vs `../api/...` (correto).
  - Falta de `credentials: 'include'` em muitas chamadas → cookies não enviados em CORS.
  - Falta de verificação `response.ok` antes de `response.json()` em muitos arquivos.

Prioridade de correção (ordem sugerida):
1. Normalizar chamadas frontend que usam `api/...` para `../api/...` onde aplicável.
2. Adicionar `credentials: 'include'` a chamadas que dependem de sessão (login, verificar_sessao, renovar, logout, endpoints de dados do usuário).
3. Substituir padrões repetidos por um wrapper `apiCall()` (ex.: o exemplo em `frontend/relatorios_hidrometro.html`) que garante `credentials`, `response.ok` e tratamento de erros legíveis.
4. Auditar calls no service-worker (`js/sw.js`) e chamadas a APIs externas separadamente (não forçar `credentials` para chamadas cross-origin públicas como viacep).

Sugestão de ação imediata para completar FASE 6 QA:
- Executar a variante QA que injeta o cabeçalho `Cookie` (tools/qa-puppeteer-auth-header.js) para verificar se, ao forçar envio do cookie no header, o backend aceita a sessão. Isso distingue transporte de cookie vs validade da sessão.
- Usar a variante que reporta `page.cookies()` para coletar atributos (SameSite, Secure, Domain) sem gravar valores em artefatos.

Onde estão os artefatos gerados nesta fase:
- `tools/fetch-audit.json` — exportação estruturada (pontapé inicial com amostra).
- `tools/qa-puppeteer-auth-header.js` — script de QA (injeta header + report de cookies).

Próximos passos recomendados:
1. Confirmar com backend validade do `PHPSESSID` e atributos do cookie.
2. Executar `tools/qa-puppeteer-auth-header.js --pages list` (instruções no topo do arquivo) para validar transporte/validação da sessão.
3. Após validação, aplicar correções críticas (paths + credentials) nas páginas de maior tráfego (dashboard, estoque, acesso, portais de morador).

---

Se quiser, aplico automaticamente um conjunto de patches para as correções de maior impacto (normalizar `api/` → `../api/`, adicionar `credentials` em endpoints críticos). Caso queira, diga "aplicar patches críticos".
