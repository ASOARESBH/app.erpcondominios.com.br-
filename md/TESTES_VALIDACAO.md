# Testes de Validação: Padronização Moradores

## 📋 Checklist de Testes

### ✅ 1. Interface - moradores.html

#### 1.1 Tabela de Listagem
- [ ] Coluna "Status" aparece na tabela
- [ ] Badge "Ativo" aparece em verde para moradores ativos
- [ ] Badge "Inativo" aparece em vermelho para moradores inativos
- [ ] Todas as outras colunas continuam funcionando (ID, Nome, CPF, Unidade, Email, Telefone, Celular)
- [ ] Botões de ação (Editar, Excluir) continuam funcionando

#### 1.2 Formulário de Cadastro (Novo Morador)
- [ ] Todos os campos aparecem corretamente
- [ ] Validação de CPF funciona
- [ ] Validação de email funciona
- [ ] Campo senha é obrigatório
- [ ] Campo confirmar senha é obrigatório
- [ ] Validação de senhas iguais funciona
- [ ] Máscara de CPF funciona (000.000.000-00)
- [ ] Máscara de telefone funciona
- [ ] Máscara de celular funciona
- [ ] Select de unidades carrega corretamente
- [ ] Botão "Salvar Morador" funciona

#### 1.3 Formulário de Edição
- [ ] Ao clicar em "Editar", formulário é preenchido com dados do morador
- [ ] Campo senha é preenchido com '********'
- [ ] Campo confirmar senha é preenchido com '********'
- [ ] Campos senha e confirmar senha NÃO são obrigatórios ao editar
- [ ] Título muda para "Editar Morador"
- [ ] Botão muda para "Atualizar Morador"
- [ ] Botão "Cancelar" aparece
- [ ] Página rola para o topo automaticamente

#### 1.4 Atualização SEM Alterar Senha
- [ ] Editar morador sem alterar senha (deixar '********')
- [ ] Clicar em "Atualizar Morador"
- [ ] Morador é atualizado com sucesso
- [ ] Senha antiga continua funcionando no login

#### 1.5 Atualização COM Nova Senha
- [ ] Editar morador e alterar senha
- [ ] Digitar nova senha e confirmação
- [ ] Clicar em "Atualizar Morador"
- [ ] Morador é atualizado com sucesso
- [ ] Nova senha funciona no login
- [ ] Senha antiga NÃO funciona mais

### ✅ 2. API - api_moradores.php

#### 2.1 GET - Listar Moradores
```bash
# Teste via curl ou navegador
curl http://seu-dominio.com/api_moradores.php
```
- [ ] Retorna JSON com lista de moradores
- [ ] Campo `ativo` está presente (0 ou 1)
- [ ] Todos os campos esperados estão presentes

#### 2.2 POST - Criar Morador
```bash
curl -X POST http://seu-dominio.com/api_moradores.php \
  -H "Content-Type: application/json" \
  -d '{
    "nome": "Teste Morador",
    "cpf": "123.456.789-00",
    "unidade": "Gleba 1",
    "email": "teste@teste.com",
    "senha": "Senha123!",
    "telefone": "(31) 3333-3333",
    "celular": "(31) 99999-9999"
  }'
```
- [ ] Morador é criado com sucesso
- [ ] Senha é armazenada em BCRYPT (inicia com $2y$)
- [ ] CPF duplicado retorna erro
- [ ] Email duplicado retorna erro

#### 2.3 PUT - Atualizar Morador (SEM senha)
```bash
curl -X PUT http://seu-dominio.com/api_moradores.php \
  -H "Content-Type: application/json" \
  -d '{
    "id": 1,
    "nome": "Teste Morador Atualizado",
    "cpf": "123.456.789-00",
    "unidade": "Gleba 2",
    "email": "teste@teste.com",
    "telefone": "(31) 3333-4444",
    "celular": "(31) 99999-8888"
  }'
```
- [ ] Morador é atualizado com sucesso
- [ ] Senha NÃO é alterada no banco
- [ ] Login com senha antiga continua funcionando

#### 2.4 PUT - Atualizar Morador (COM senha)
```bash
curl -X PUT http://seu-dominio.com/api_moradores.php \
  -H "Content-Type: application/json" \
  -d '{
    "id": 1,
    "nome": "Teste Morador",
    "cpf": "123.456.789-00",
    "unidade": "Gleba 1",
    "email": "teste@teste.com",
    "senha": "NovaSenha123!",
    "telefone": "(31) 3333-3333",
    "celular": "(31) 99999-9999"
  }'
```
- [ ] Morador é atualizado com sucesso
- [ ] Senha é atualizada em BCRYPT
- [ ] Login com nova senha funciona
- [ ] Login com senha antiga NÃO funciona

### ✅ 3. Autenticação - validar_login_morador.php

#### 3.1 Login com Senha BCRYPT (Nova)
```bash
# Criar morador novo via interface
# Fazer login com CPF e senha
```
- [ ] Login bem-sucedido
- [ ] Sessão é criada corretamente
- [ ] Redirecionamento para portal funciona
- [ ] Campo `ultimo_acesso` é atualizado no banco

#### 3.2 Login com Senha SHA1 (Antiga) - Primeira Vez
```bash
# Usar CPF de morador antigo do banco (senha em SHA1)
# Fazer login com senha correta
```
- [ ] Login bem-sucedido
- [ ] Senha é migrada automaticamente para BCRYPT
- [ ] Log de migração é registrado em `logs_sistema`
- [ ] Campo `ultimo_acesso` é atualizado

#### 3.3 Login com Senha Migrada - Segunda Vez
```bash
# Fazer logout
# Fazer login novamente com mesmo CPF e senha
```
- [ ] Login bem-sucedido
- [ ] Agora usa BCRYPT (não SHA1)
- [ ] Mais rápido que primeira vez (não precisa migrar)

#### 3.4 Login com Senha Incorreta
- [ ] Retorna erro "CPF ou senha incorretos"
- [ ] Log de tentativa falha é registrado
- [ ] Sessão NÃO é criada

#### 3.5 Login com Morador Inativo
- [ ] Retorna erro "Morador inativo"
- [ ] Log de tentativa com morador inativo é registrado
- [ ] Sessão NÃO é criada

### ✅ 4. Migração de Senhas

#### 4.1 Verificar Senhas SHA1 no Banco
```sql
SELECT 
    id, nome, email,
    CASE 
        WHEN LENGTH(senha) = 40 THEN 'SHA1'
        WHEN senha LIKE '$2y$%' THEN 'BCRYPT'
        ELSE 'OUTRO'
    END as tipo_senha
FROM moradores
WHERE LENGTH(senha) = 40;
```
- [ ] Consulta retorna moradores com senhas SHA1
- [ ] Anotar IDs para teste de migração

#### 4.2 Executar Migração via Login
- [ ] Fazer login com cada morador SHA1
- [ ] Verificar se senha foi migrada no banco
```sql
SELECT id, nome, senha FROM moradores WHERE id = ?;
```
- [ ] Senha agora inicia com $2y$ (BCRYPT)

#### 4.3 Verificar Logs de Migração
```sql
SELECT * FROM logs_sistema 
WHERE tipo = 'senha_atualizada' 
ORDER BY data_hora DESC;
```
- [ ] Logs de migração estão sendo registrados
- [ ] Cada migração tem nome do morador

### ✅ 5. Estatísticas de Migração

#### 5.1 Executar Script de Estatísticas
```sql
-- Copiar consulta de migracao_senhas_moradores.sql
SELECT 
    CASE 
        WHEN LENGTH(senha) = 40 THEN 'SHA1 (Pendente)'
        WHEN senha LIKE '$2y$%' THEN 'BCRYPT (Migrado)'
        ELSE 'Outro'
    END as tipo_senha,
    COUNT(*) as total,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM moradores), 2) as percentual
FROM moradores
GROUP BY tipo_senha;
```
- [ ] Consulta retorna estatísticas corretas
- [ ] Percentual de migração está aumentando

### ✅ 6. Testes de Integração

#### 6.1 Fluxo Completo: Novo Morador
1. [ ] Cadastrar novo morador via interface
2. [ ] Verificar senha BCRYPT no banco
3. [ ] Fazer login no portal do morador
4. [ ] Verificar sessão criada
5. [ ] Navegar pelo portal
6. [ ] Fazer logout
7. [ ] Fazer login novamente

#### 6.2 Fluxo Completo: Editar Morador (Sem Senha)
1. [ ] Editar morador existente
2. [ ] Alterar apenas nome e email
3. [ ] Deixar senha como '********'
4. [ ] Salvar
5. [ ] Fazer login com senha antiga
6. [ ] Login bem-sucedido

#### 6.3 Fluxo Completo: Editar Morador (Com Senha)
1. [ ] Editar morador existente
2. [ ] Alterar senha para nova
3. [ ] Salvar
4. [ ] Fazer login com senha antiga (deve falhar)
5. [ ] Fazer login com senha nova (deve funcionar)

#### 6.4 Fluxo Completo: Migração Automática
1. [ ] Identificar morador com senha SHA1
2. [ ] Fazer login no portal
3. [ ] Verificar senha migrada no banco
4. [ ] Fazer logout
5. [ ] Fazer login novamente (agora com BCRYPT)

### ✅ 7. Testes de Segurança

#### 7.1 Verificar Hash BCRYPT
```sql
SELECT id, nome, senha FROM moradores WHERE id = 1;
```
- [ ] Senha inicia com $2y$10$ (BCRYPT)
- [ ] Senha tem ~60 caracteres
- [ ] Cada senha tem hash diferente (mesmo para senhas iguais)

#### 7.2 Verificar Salt Automático
- [ ] Criar dois moradores com mesma senha
- [ ] Verificar hashes no banco
- [ ] Hashes devem ser DIFERENTES (salt automático)

#### 7.3 Tentar SQL Injection
```bash
# Tentar injetar SQL no login
CPF: 123' OR '1'='1
Senha: qualquer
```
- [ ] Login falha (proteção contra SQL Injection)
- [ ] Erro genérico é retornado

### ✅ 8. Testes de Responsividade

#### 8.1 Desktop (1920x1080)
- [ ] Tabela exibe todas as colunas
- [ ] Badge de status visível
- [ ] Formulário em grid 2x2
- [ ] Botões alinhados horizontalmente

#### 8.2 Tablet (768x1024)
- [ ] Sidebar recolhe
- [ ] Menu toggle aparece
- [ ] Tabela responsiva
- [ ] Formulário ajusta colunas

#### 8.3 Mobile (375x667)
- [ ] Sidebar em overlay
- [ ] Tabela com scroll horizontal
- [ ] Formulário em coluna única
- [ ] Botões em largura total

## 📊 Resultados Esperados

### Antes da Implementação
- ❌ Senhas em SHA1 (inseguro)
- ❌ Campo status não exibido
- ❌ Senha obrigatória ao editar
- ❌ Sem badge visual de status

### Depois da Implementação
- ✅ Senhas em BCRYPT (seguro)
- ✅ Migração automática no login
- ✅ Campo status exibido com badge
- ✅ Senha opcional ao editar
- ✅ Interface padronizada com usuários

## 🐛 Problemas Conhecidos e Soluções

### Problema: Senha não migra automaticamente
**Solução:** Verificar se validar_login_morador.php está atualizado

### Problema: Badge de status não aparece
**Solução:** Verificar se CSS foi adicionado e se campo `ativo` está no SELECT da API

### Problema: Erro ao editar sem senha
**Solução:** Verificar se lógica de senha opcional está implementada na API

## ✅ Conclusão dos Testes

Após executar todos os testes acima, o sistema deve:

1. ✅ Autenticar moradores com senhas SHA1 e BCRYPT
2. ✅ Migrar automaticamente senhas antigas
3. ✅ Exibir status com badge colorido
4. ✅ Permitir edição sem alterar senha
5. ✅ Registrar logs de auditoria
6. ✅ Manter compatibilidade retroativa

---

**Data dos Testes:** _____________  
**Testado por:** _____________  
**Status:** [ ] Aprovado [ ] Reprovado  
**Observações:** _____________________________________________
