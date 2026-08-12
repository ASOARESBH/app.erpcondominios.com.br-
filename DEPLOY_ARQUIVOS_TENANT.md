# Implantação — Armazenamento Centralizado de Arquivos Multi-Tenant

Este pacote migra os uploads do ERP Condomínio para **BLOBs isolados por tenant**. Os arquivos novos deixam de ser gravados em diretórios públicos; as referências legadas continuam sendo preservadas para compatibilidade com as telas existentes.

## Pré-requisitos

| Item | Requisito |
|---|---|
| Banco de dados | MySQL/MariaDB 5.7 compatível |
| Banco alvo | `inlaud99_erpcondor` |
| Backup | Exportação completa do banco e cópia do diretório `uploads/` antes da implantação |
| Usuário | Acesso administrativo ao phpMyAdmin/cPanel |

> **Importante:** execute a migration antes de disponibilizar os arquivos PHP atualizados. Sem as tabelas de arquivos, os novos uploads serão recusados de propósito para evitar persistência fora do banco.

## Ordem de implantação

1. No phpMyAdmin, selecione o banco `inlaud99_erpcondor` e faça um backup completo.
2. Importe `sql/migration_arquivos_tenant_mysql57.sql` sem alterar os delimitadores do script. Ele cria `tenant_arquivos`, `tenant_arquivo_referencias`, `tenant_arquivos_migracao_log` e adequa os documentos de orçamento para `tenant_id`.
3. Faça upload do conteúdo deste pacote preservando a estrutura de pastas, mesclando-o à raiz da aplicação no HostGator.
4. Confirme que `uploads/.htaccess` permanece presente. Ele encaminha URLs legadas para a API central, em vez de expor arquivos físicos.
5. Execute o teste de upload e leitura para cada módulo listado abaixo, usando uma conta de um tenant de teste.
6. Após validar uploads novos, importe os arquivos históricos pelo endpoint de Super-Admin `POST /api/api_arquivos_tenant.php?acao=importar_zip_legado`, enviando o arquivo `uploads.zip` previamente guardado.
7. Execute `sql/auditoria_arquivos_tenant_mysql57.sql` para conferir quantidade, tamanho e eventuais erros de importação por tenant.

## Cobertura migrada

| Módulo | Tipo de arquivo | Persistência |
|---|---|---|
| Empresa | Logo do tenant | `logo_tenant` |
| Visitantes | Foto e documento | `visitante_foto`, `visitante_documento` |
| RH | Foto de colaborador | `rh_foto` |
| Hidrômetros | Foto de leitura | `leitura_foto` |
| Moradores | Anexo | `morador_anexo` |
| Contratos | Documento e orçamento | `contrato_anexo` |
| CRM | Anexo | `crm_anexo` |
| GED | Documento | `documento` |
| Notificações | Anexo | `notificacao_anexo` |
| Projetos | Capa e foto de obra | `projeto_imagem` público controlado |
| Assembleias | Anexo | `assembleia_anexo` |
| Manual | Imagem inline | `anexo_geral` público controlado |

## Validação recomendada

| Cenário | Resultado esperado |
|---|---|
| Upload por tenant A | Registro criado apenas com `tenant_id` de A |
| Leitura de URL legada | Arquivo servido pela API central, sem acesso físico direto |
| Consulta como tenant B | Arquivo do tenant A não é localizado nem baixado |
| Substituição/exclusão | BLOB anterior marcado como inativo; histórico de referência preservado |
| Login | Continua usando exclusivamente `assets/img/logos/logo_padrao.png` |
| Branding após login | Logo dinâmica somente do tenant autenticado |

## Rollback

Se for necessário reverter o código, restaure somente os arquivos PHP anteriores. **Não exclua** as tabelas `tenant_arquivos` nem os BLOBs já gravados: elas preservam os documentos produzidos após a implantação. As referências legadas continuam armazenadas nos registros do módulo e podem ser auditadas pelo script fornecido.
