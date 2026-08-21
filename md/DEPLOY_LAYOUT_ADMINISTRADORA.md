# Deploy — Layout Administradora

## Objetivo

A segunda chamada **Layout Administradora**, disponível em **Configurações → Empresa**, permite que cada condomínio selecione a administradora responsável pelos relatórios e indique um layout analítico por módulo financeiro. A configuração é isolada por `tenant_id` e não modifica títulos, baixas, cobranças ou dados financeiros operacionais.

## Arquivos do pacote

| Caminho | Finalidade |
|---|---|
| `sql/migration_layout_administradora_mysql57.sql` | Cria cadastro mestre, layouts e vínculos por tenant. |
| `api/api_empresa.php` | Expõe as ações autenticadas de leitura e gravação. |
| `frontend/pages/empresa.html` | Adiciona a segunda chamada na tela Empresa. |
| `frontend/js/pages/empresa.js` | Carrega, filtra e salva a administradora e os layouts. |
| `assets/css/pages/empresa.css` | Estiliza as abas e cartões responsivos. |

## Ordem de deploy

Execute primeiro a migration no banco `inlaud99_erpcondor`. O script é compatível com MySQL/MariaDB 5.7 e usa apenas `CREATE TABLE IF NOT EXISTS` para novas tabelas. Depois envie os arquivos do pacote para a raiz do sistema, preservando as pastas internas, e sobrescreva os quatro arquivos da aplicação.

Não use `ADD COLUMN IF NOT EXISTS` e não envie `tenant_id` pelo navegador. A API obtém o tenant exclusivamente da sessão autenticada.

## Validação funcional

Acesse **Configurações → Empresa → Layout Administradora**. Selecione uma administradora, como BRCondos, PACTO ou Superlógica. Os cartões exibem apenas layouts associados à administradora escolhida. Selecione no máximo um layout por módulo e clique em **Salvar Layout Administradora**.

O layout **Inadimplência Detalhada BRCondos** é marcado como pronto porque o parser correspondente já está implantado. Os demais layouts são configurações de referência; eles orientam a homologação e futuros importadores, mas não devem ser apresentados como parsers concluídos antes da implementação específica.

## Consultas de auditoria

```sql
SELECT ea.tenant_id, a.nome AS administradora, al.modulo, al.nome AS layout, eal.ativo
FROM empresa_administradora ea
INNER JOIN administradoras_importacao a ON a.id = ea.administradora_id
LEFT JOIN empresa_administradora_layout eal ON eal.tenant_id = ea.tenant_id
LEFT JOIN administradoras_layouts al ON al.id = eal.administradora_layout_id
ORDER BY ea.tenant_id, al.ordem;
```

A consulta deve retornar somente as configurações do tenant analisado quando receber um filtro em `ea.tenant_id`.

## Reversão

Para reverter somente a interface, restaure os quatro arquivos do pacote a partir do backup anterior. As tabelas novas são independentes e não alteram dados existentes em `empresa`, `tenants`, contas a receber ou inadimplência. Não exclua tabelas de configuração sem preservar um backup e sem avaliar seus vínculos por tenant.
