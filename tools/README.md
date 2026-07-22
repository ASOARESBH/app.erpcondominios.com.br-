# Headless QA (Puppeteer)

This folder contains a small Puppeteer script to run basic headless QA checks against the local frontend.

Prerequisites
- Node.js (v14+)
- npm
- The web server must serve the frontend at `http://localhost/dashboard/asl.erpcondominios.com.br/frontend`

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
