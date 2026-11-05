# Dependências e runtime

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.

## Evidência de dependências

| Área | Comprovado no commit | Limitação |
|---|---|---|
| PHP | `mysqli` em 87 PHP; sessões, JSON, cURL, mbstring/iconv, GD, OpenSSL, XML/DOM e zip aparecem em buscas | Ocorrência não confirma versão/extensão habilitada. |
| PDF | Smalot PDFParser em `api/vendor/`, polyfill Symfony e helper de extração | TCPDF/FPDF/FPDI são citados, mas não comprovados como classe executável no baseline. |
| E-mail | PHPMailer local e `api/email/SmtpProvider.php` | SMTP, Resend/Brevo, credenciais e entrega não validados. |
| Frontend | CDN FontAwesome 6.4.0, html5-qrcode 2.3.8, Chart.js 3.9.1; há carregamentos 4.4.0/6.5.2 em página específica | Deriva de versões e disponibilidade CDN não fechadas. |
| QA Node | `tools/package.json` com `puppeteer-core ^21.11.0`; lock fixa 21.11.0 | Chrome/Chromium externo; README v14+ conflita com engine Node >=16.13.2. |
| PHP packaging | Dependências locais/parcialmente vendorizadas | Não há `composer.json`/`composer.lock` raiz reproduzível. |
| PostgreSQL | Nenhum adapter, driver ou migration PostgreSQL comprovado | Driver, dialect, SQLSTATE, tipos e runtime são futuros. |

A documentação anterior menciona PHP 8.x e arquitetura Apache/LiteSpeed/MySQL; isso é contexto do código, não prova de instalação. PHP 8.3/PDO_PGSQL/Nginx/PHP-FPM/Ubuntu podem ser requisitos propostos, mas ainda não estão confirmados no alvo.

## Incompatibilidades a tratar

1. **QA Node:** fixar Node mínimo compatível com `puppeteer-core 21.11.0`, corrigir o comando relativo do script npm e remover a implementação duplicada/redeclaração em `tools/qa-puppeteer-auth-header.js`; escolher uma estratégia única `puppeteer-core + Chrome/Chromium`.
2. **PHP:** definir versão/SAPI/extensões por endpoint e imagem de runtime; não inferir de um único `composer.json` vendorizado.
3. **Frontend CDN:** normalizar Chart.js e FontAwesome; comprovar carregamento e fallback em cada shell/página.
4. **PDF/e-mail:** fechar biblioteca de geração versus extração, licenças, autoload e provedor de envio sem expor credenciais.
5. **PostgreSQL:** escolher driver/adapter e política de prepared statements, transações, tipos, timezone, JSON, upsert, locks e funções equivalentes.

## Manifesto de runtime que falta

Deve registrar, em versão e checksum: PHP/SAPI, extensões, driver PostgreSQL, Node/npm e Chrome/Chromium para QA, web server/PHP-FPM, TLS, timezone, locale/collation, scheduler, limites de upload/memória/tempo, storage, permissões, observabilidade, política de logs sem PII e versões/licenças de dependências.

O manifesto não deve conter segredos. Credenciais, chaves, tokens, endpoints privados e arquivos de configuração devem ser referenciados por identificador seguro/secret manager, nunca copiados para esta documentação.

## Validação controlada

Antes do Gate 1: instalação limpa em ambiente descartável; `php -l` nos arquivos aplicáveis; `node --check` nos scripts; instalação reproduzível do QA; verificação de Chrome/Chromium; conexão de teste ao PostgreSQL; smoke de API sem produção; e matriz de extensões exercitadas por endpoint. Nenhum desses passos foi executado como deploy ou homologação neste baseline.

## Referências

`AI-CONTEXT/DEPENDENCIES.md`, `AI-CONTEXT/ARCHITECTURE.md`; `tools/package.json`, `tools/package-lock.json`, `tools/README.md`, `tools/qa-puppeteer*.js`; `api/vendor/autoload_pdfparser.php`, `api/vendor/symfony/polyfill-mbstring/composer.json`, `api/email/SmtpProvider.php`, `api/helpers/pdf_text_extractor_helper.php`, `PHPMailer/`; `tests/convidados_pre_cadastro/README.md`; `review_operations.md` e `plano_migracao_postgresql_erp.md`.
