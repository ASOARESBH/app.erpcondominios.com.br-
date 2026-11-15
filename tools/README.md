# Headless QA (Puppeteer)

This folder contains a small Puppeteer script to run basic headless QA checks against the local frontend.

Prerequisites
- Node.js (v14+)
- npm
- The web server must serve the frontend at `http://localhost/dashboard/app.erpcondominios.com.br/frontend`

Install
```bash
cd tools
npm install
```

Run
```bash
npm run qa
```

Output
- `tools/qa-results.json` will contain test results.

Notes
- Adjust `base` URL in `qa-puppeteer.js` if your local server uses a different path or port.
- The script runs headless; remove `headless: true` to see browser window.


## Linter SQL portátil

Execute a partir de qualquer diretório, informando explicitamente os arquivos de aplicação PHP/SQL do PR (não passe o próprio scanner nem fixtures que contêm sintaxe MySQL intencionalmente):

```bash
php tools/pg_sql_lint.php api/api_exemplo.php sql/migration_exemplo.sql
php tests/pg_sql_lint_test.php
```

O linter não faz busca recursiva. Retorna status diferente de zero para caminhos inválidos ou padrões de dialeto MySQL do catálogo inicial; a saída não inclui conteúdo SQL. `NOW()` não é tratado como exclusivo do MySQL; comentários `--` com separador, `#` e `/* ... */` são ignorados. A forma `--comentário` sem separador é marcada como ambígua entre drivers. Os operadores PostgreSQL `#>`, `#>>` e `#-` são preservados. É uma verificação estática, não um parser completo nem substituto de teste funcional nos dois drivers. O MySQL continua sendo o driver padrão.
