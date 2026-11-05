# Baseline técnico e de evidência

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.
> **Branch:** `pg/00-baseline-contratos`, derivada diretamente do commit exato indicado.

## Escopo congelado

O baseline considera os caminhos rastreados pelo Git no worktree oficial. `git ls-files` retornou 1.173 caminhos. Para a contagem estrutural, foram excluídos diretórios chamados `docs`, `md` e `api/md`, totalizando 199 caminhos excluídos e 974 em escopo; `AI-CONTEXT/` foi mantido por ser fonte autorizada de contexto, apesar de conter Markdown.

Contagens de primeiro nível em escopo: `api` 350, `frontend` 260, `sql` 148, `assets` 77, `AI-CONTEXT` 57, `tools` 15, `tests` 10, `ico` 9, `js` 8, outputs Claude 7, `bridge_local` 6, `PWA` 4, `cron` 4, `PHPMailer` 3, `config` 3 e outros arquivos isolados. Extensões principais: PHP 350, HTML 146, SQL 144, JavaScript 116, CSS 73, JSON 18 e demais formatos auxiliares.

## Fatos observados

- O contexto arquitetural descreve um monólito modular com frontend HTML/CSS/Vanilla JS, APIs PHP, MySQL e Apache/LiteSpeed com `.htaccess`. Isso documenta intenção e não comprova versões instaladas, servidor ativo ou execução.
- Há 336 caminhos PHP sob `api/`, 145 `api/api_*.php`, 23 helpers, 10 caminhos `api/controlid/` e 17 caminhos cujo nome contém `pdf`. Essas quantidades são de arquivos, não de rotas públicas únicas.
- O código contém `mysqli` em 87 arquivos PHP e não apresentou uso de PDO na busca estática informada. Há referências a sessões, JSON, cURL, mbstring, GD, OpenSSL, iconv, XML/DOM e zip; ocorrência não confirma extensão habilitada.
- Dependências PHP locais observáveis incluem 58 caminhos em `api/vendor/`, Smalot PDFParser, polyfill Symfony e PHPMailer direto em `PHPMailer/`. Não há manifesto Composer raiz ou lock do aplicativo.
- QA Node é auxiliar: `tools/package.json` declara `puppeteer-core ^21.11.0`; Chrome/Chromium é externo. O lock registra Node mínimo de engine para o pacote, enquanto `tools/README.md` pede Node v14+, criando conflito a resolver.
- O resumo de comparação informa 3.225 caminhos na união ZIP/Git, 656 diferentes, 123 iguais, 17 apenas no Git e 6 apenas no ZIP. O ZIP não foi tratado como código nem como autorização para completar o baseline.

## Fatos versus inferências

**Fato:** o caminho de banco evidenciado por código, SQL e testes é MySQL/MariaDB. **Inferência de planejamento:** a migração exige uma camada de compatibilidade e conversão semântica; trocar apenas o driver não preserva o comportamento.

**Fato:** a árvore contém módulos administrativos/RH, financeiro, manutenção, segurança/acesso, PWA, projetos, convidados, alertas, Chat-In, eventos e canais. **Inferência:** todos os fluxos devem ser cobertos por contratos e QA, mas a presença dos arquivos não prova publicação, habilitação ou uso ativo.

**Fato:** o commit-base está identificado por seu SHA e tree no [baseline-manifest.json](baseline-manifest.json). **Desconhecido:** versões instaladas, configuração real, banco corrente, cron, SMTP, FCM, CDN, permissões e estado de produção.

## Fora do escopo executado

Não houve `npm install`, execução de QA contra servidor, requests HTTP, login, upload/download, acesso a banco, SSH, migration, deploy, importação, alteração de VM, firewall, DNS, produção ou leitura de dados pessoais/segredos. O conteúdo histórico de `docs/` e `md/` não foi usado como fonte operacional; relatórios estruturados fornecidos foram utilizados apenas dentro do escopo autorizado.

## Riscos de interpretação

- Contagem de arquivos não é contagem de endpoints, tabelas instaladas, queries conformes ou funcionalidades ativas.
- Presença de DDL, migration ou trigger no Git não prova execução no HostGator.
- Ausência em schema-only não prova ausência na instância.
- Um endpoint que menciona `tenant_id` não está automaticamente isolado em todas as consultas.
- O CSV [inventario_sintaxe_mysql.csv](inventario_sintaxe_mysql.csv) é triagem lexical: arquivo/linha/categoria, sem SQL ou conteúdo sensível; serve para revisão semântica e não alega exaustividade de entendimento.

## Próximos passos e artefatos exatos

- `git show --stat` e hash do commit-base preservados em registro de baseline-manifest.
- Export read-only de metadados da instância: tabelas, colunas, índices, constraints, triggers, views, procedures, functions, events, versão e `AUTO_INCREMENT`.
- Perfil agregado sem linhas pessoais: contagens, NULLs, duplicidades, órfãos, tenant coverage, zero-date, limites numéricos e tamanhos de blobs/textos/JSON.
- Manifesto de runtime proposto, catálogo de contratos, migration ledger com checksum e evidência de QA/restore/rollback.

As pendências estão consolidadas em [PENDENCIAS.md](PENDENCIAS.md); a decisão de destino PostgreSQL está registrada em [DECISOES_TECNICAS.md](DECISOES_TECNICAS.md).
