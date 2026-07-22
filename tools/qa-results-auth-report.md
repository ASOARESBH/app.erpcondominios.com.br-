# QA Authenticated Report — Session Manager Core v2.0

**Data:** 2026-02-07T03:29:03Z
**Script:** `tools/qa-puppeteer-auth.js` → `tools/qa-results-auth.json`
**Base URL:** https://asl.erpcondominios.com.br

## Objetivo
Validar comportamento real do `SessionManagerCore` com sessão autenticada via injeção do cookie `PHPSESSID`.

## Execução
- Cookie injetado via variável de ambiente `QA_PHPSESSID` (valor não armazenado nos artefatos).
- Páginas testadas:
  - `/frontend/TEST_FASE6_QA.html`
  - `/frontend/dashboard.html`
  - `/frontend/estoque.html`
  - `/frontend/acesso.html`
- Arquivos gerados:
  - `tools/qa-results-auth.json` (detalhes por página)
  - `tools/qa-results-auth.md` (resumo tabular)

## Resultado resumido por critério (PASS/FAIL)
- SessionManagerCore inicializa com sessão: FAIL (não detectado em nenhuma página)
- Nenhum loop detectado: PASS (nenhum comportamento de loop detectado durante janela de observação)
- Nenhum logout aleatório: PASS (nenhuma desconexão inesperada observada durante testes curtos)
- localStorage seguro: INCONCLUSIVO (sem sessão ativa, localStorage vazio — seguro por ausência)
- Redução de requests: PARTIAL (algumas páginas >=80% redução, outras abaixo)
- Logout funcional: PARTIAL (não foi possível disparar logout via API em contexto autenticado)
- Sem erros críticos no console: PARTIAL (alguns 401/403 observados — esperado sem sessão válida)

### Resultado geral: FAIL
Motivo principal: `SessionManagerCore` não inicializou com a sessão injetada — as checagens de autenticação e eventos não puderam ser validadas.

## Dados essenciais (extraídos de `tools/qa-results-auth.json`)
- `dashboard.html` → observadas 9 chamadas `/api/` no intervalo curto (extrapolado ≈108 req/min, redução ≈70%)
- `estoque.html` → observadas 6 chamadas `/api/` (extrapolado ≈72 req/min, redução 80%)
- `acesso.html` → observadas 7 chamadas `/api/` (extrapolado ≈84 req/min, redução 77%)
- Em **nenhuma** requisição de teste foi detectado o cabeçalho `Cookie` sendo enviado nas requests coletadas (registro `hasCookieHeader: false`).

## Observações técnicas e causas prováveis
1. O arquivo `session-manager-core.js` existe em disco e é servido com HTTP 200 (confirmado). Contudo o objeto/instância `sessionManager` não apareceu no escopo das páginas testadas mesmo após injeção do cookie.
2. As requisições observadas aos endpoints `/api/...` não levaram o cabeçalho `Cookie` (logo o servidor respondeu como não autenticado → 401/403), o que explica porque o SessionManager não se inicializa com sessão.
3. Possíveis causas:
   - O cookie está sendo definido com atributos (SameSite, Secure, Domain) que impedem envio em fetch/XHR usados pela aplicação (ex.: SameSite=Lax ou Strict bloqueando em casos de CORS).
   - O navegador em contexto headless não tem confiança no certificado HTTPS do host (se for self-signed), impedindo envio de cookies Secure.
   - O domínio/path do cookie não corresponde exatamente ao host/origin usado nas requests (a aplicação pode usar um subpath ou subdomain diferente).
   - As chamadas do frontend usam fetch() sem `credentials: 'include'` para GETs; sem `credentials` as requisições CORS não enviarão cookies. (Observação: para POST/renovação o core foi atualizado para `credentials: 'include'`, mas muitos endpoints usam GET.)

## Recomendação imediata (próximo passo)
Para completar FASE 6 e alcançar PASS total, recomendo os passos a seguir (ordem sugerida):

1. Confirmar com o time backend que o cookie `PHPSESSID` fornecido é válido e ativo para `https://asl.erpcondominios.com.br` e não possui atributos de SameSite que impeçam envio nas requisições do app.
2. Confirmar que o ambiente onde o script é executado pode acessar `https://asl.erpcondominios.com.br` e confia no certificado TLS (ou configure `CHROME_PATH` para um Chrome com certificados confiáveis).
3. (Opção de teste) Rodar o script em modo que injete o cabeçalho `Cookie` diretamente (apenas para QA) para verificar comportamento da aplicação com sessão — isto valida rapidamente se o problema é o transporte do cookie ou a lógica do frontend.
   - Se aprovar, podemos automatizar essa opção como `--auth-mode header` (não armazenará o cookie nos artefatos).
4. Confirmar que os principais endpoints `/api/verificar_sessao_completa.php` aceitam cookies em requisições GET ou que o frontend envia `credentials: 'include'` em chamadas relevantes.
5. Após corrigir o transporte do cookie, re-executar `tools/qa-puppeteer-auth.js` — esperamos então ver:
   - `window.sessionManager` inicializado
   - eventos `sessionRenewed`, `userDataChanged` disparados ao chamar `sessionManager.renewSession()`
   - `logout()` removendo cookie e limpando localStorage
   - contagem de `/api/` requests/min <= 72 (≥80% redução vs 360)

## Problemas encontrados (para acompanhamento)
- Cookie injetado não estava sendo enviado em requests coletados (sem `Cookie` header).
- `sessionManager` não inicializou em qualquer página — possivelmente por falha de autenticação no servidor.
- Algumas páginas extrapolam redução alvo (ex.: `dashboard` reduziu apenas ~70%).

## Conclusão de prontidão para deploy
No estado atual: **NÃO APROVADO**.  
Principais bloqueios: SessionManager não inicializa com o cookie injetado (falha de autenticação de sessão), portanto não foi possível validar renew/logout/eventos sob contexto autenticado.

## Artefatos gerados
- `tools/qa-results-auth.json` (detalhes por página)
- `tools/qa-results-auth.md` (resumo)

---

Se deseja, eu posso agora:
- (1) Implementar uma variante de teste que injete o `Cookie` HTTP como header para forçar envio (somente para QA).  
- (2) Ajustar o script para verificar explicitamente `page.cookies()` e reportar cookie attributes e valores presenciais (não gravar valor no output final).  
- (3) Re-executar testes após você confirmar que o cookie QA é válido e ativo para `https://asl.erpcondominios.com.br`.

Informe qual opção prefere.  
