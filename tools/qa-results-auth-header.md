# QA Results (Header-Injected Auth Mode)
Generated: 2026-02-07T03:34:19.266Z
Auth Mode: Cookie injected via HTTP headers (all requests)

| Page | Core Init | isAuth | Renew Triggered | Cookie Sent | Logout OK | Storage Safe | req/min | reduction |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| /frontend/TEST_FASE6_QA.html | NO | NO | NO | NO | NO | YES | 0 | 100% |
| /frontend/dashboard.html | NO | NO | NO | YES | NO | YES | 120 | 67% |
| /frontend/estoque.html | NO | NO | NO | YES | NO | YES | 72 | 80% |
| /frontend/acesso.html | NO | NO | NO | YES | NO | YES | 84 | 77% |

**Overall Status:** ❌ FAIL

## Notes
- Cookie injected via HTTP `Cookie` header in all requests (QA mode)
- All pages should show cookie being sent in API requests
- SessionManagerCore should initialize with auth=true
- Renewal and logout should work correctly with header-injected auth