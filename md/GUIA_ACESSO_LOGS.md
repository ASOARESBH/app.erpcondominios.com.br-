# 🚀 Guia Rápido: Como Acessar os Logs do Sistema

## ✅ Problema Resolvido!

Os arquivos **logs_sistema.html** e **api_logs_sistema.php** foram criados e integrados com sucesso ao sistema.

---

## 📍 Como Acessar

### **Opção 1: Via Menu Configurações**

1. Faça login no sistema
2. Clique em **"Configurações"** no menu lateral
3. Na página de configurações, localize o card **"Logs do Sistema"**
4. Clique em **"Acessar Logs"**

### **Opção 2: Via Submenu (Mais Rápido)**

1. Faça login no sistema
2. Clique em **"Configurações"** no menu lateral
3. No **submenu superior**, clique diretamente em **"Logs do Sistema"**

### **Opção 3: URL Direta**

Acesse diretamente pelo navegador:
```
https://seu-dominio.com/logs_sistema.html
```

---

## 🎯 O Que Você Encontrará

### **Cards de Estatísticas**
- Total de Logs
- Logs Hoje
- Tipos Diferentes
- Usuários Ativos

### **Filtros Avançados**
- **Tipo de Log:** Dropdown com todos os tipos disponíveis
- **Usuário:** Busca por nome
- **Período:** Data início e fim
- **Busca Geral:** Pesquisa em descrição, tipo ou usuário
- **Limite:** 50, 100, 200 ou 500 registros por página

### **Tabela de Logs**
- ID do registro
- Data e hora formatada
- Tipo com badge colorido
- Descrição completa
- Usuário responsável
- IP de origem

### **Botões de Ação**
- 🔍 **Buscar** - Aplica os filtros
- 🧹 **Limpar Filtros** - Remove todos os filtros
- 📤 **Exportar CSV** - Baixa logs em CSV
- 📊 **Atualizar Estatísticas** - Recarrega estatísticas
- 🗑️ **Limpar Logs Antigos** - Remove logs antigos (mínimo 30 dias)

---

## 🎨 Cores dos Badges

| Tipo | Cor | Exemplo |
|------|-----|---------|
| Acesso Autorizado | 🟢 Verde | `ACESSO_RFID` |
| Acesso Negado | 🔴 Vermelho | `ACESSO_NEGADO_RFID` |
| Login Sucesso | 🟢 Verde | `LOGIN_SUCESSO` |
| Login Falha | 🔴 Vermelho | `LOGIN_FALHA` |
| Criação | 🔵 Azul | `MORADOR_CRIADO` |
| Atualização | 🟡 Amarelo | `MORADOR_ATUALIZADO` |
| Exclusão | 🔴 Vermelho | `MORADOR_EXCLUIDO` |
| Registro Manual | 🟣 Roxo | `REGISTRO_CRIADO` |
| Sistema | ⚪ Cinza | `LIMPEZA_LOGS` |

---

## 📊 Exemplos de Uso

### **1. Ver Todos os Acessos RFID de Hoje**
1. Selecione **Tipo:** `ACESSO_RFID`
2. Defina **Data Início:** data de hoje
3. Defina **Data Fim:** data de hoje
4. Clique em **Buscar**

### **2. Auditar Ações de um Usuário Específico**
1. Digite o nome do usuário em **Usuário**
2. Defina o **Período** desejado
3. Clique em **Buscar**
4. Clique em **Exportar CSV** para análise externa

### **3. Investigar Acessos Negados**
1. Selecione **Tipo:** `ACESSO_NEGADO_RFID`
2. Defina **Período** (ex: últimos 7 dias)
3. Clique em **Buscar**
4. Analise as TAGs não cadastradas

### **4. Monitorar Logins Falhados**
1. Selecione **Tipo:** `LOGIN_FALHA`
2. Defina **Período**
3. Clique em **Buscar**
4. Verifique IPs com múltiplas tentativas

### **5. Limpar Logs Antigos (Manutenção)**
1. Clique em **Limpar Logs Antigos**
2. Digite o número de dias (ex: 90)
3. Confirme a ação (dupla confirmação)
4. Verifique quantidade de registros removidos

---

## 🔒 Segurança

- ✅ Requer **login** para acessar
- ✅ Registra **IP** de todas as ações
- ✅ **Confirmação dupla** para exclusões
- ✅ **Validação** de mínimo 30 dias para limpeza
- ✅ **Proteção SQL Injection** (prepared statements)

---

## 📱 Responsividade

A interface funciona perfeitamente em:
- 💻 **Desktop** - Layout completo
- 📱 **Tablet** - Sidebar recolhível
- 📱 **Mobile** - Menu em overlay

---

## 🎓 Dicas Importantes

1. **Filtros Combinados:** Você pode usar múltiplos filtros ao mesmo tempo
2. **Enter para Buscar:** Pressione Enter nos campos de texto para buscar rapidamente
3. **Exportação:** O CSV exportado respeita os filtros aplicados
4. **Paginação:** Use as setas ou clique nos números para navegar
5. **Estatísticas:** Clique em "Atualizar Estatísticas" após aplicar filtros

---

## 📞 Suporte

Se encontrar algum problema:
1. Verifique se está logado no sistema
2. Confirme que o arquivo `logs_sistema.html` existe
3. Confirme que o arquivo `api_logs_sistema.php` existe
4. Verifique os logs de erro do PHP
5. Entre em contato com o administrador

---

## ✅ Checklist de Verificação

- [x] Arquivo `logs_sistema.html` criado
- [x] Arquivo `api_logs_sistema.php` criado
- [x] Card adicionado em `configuracao.html`
- [x] Link adicionado no submenu
- [x] Commit realizado no GitHub
- [x] Push para repositório remoto

---

**Status:** ✅ Totalmente Funcional  
**Última Atualização:** 18 de Dezembro de 2024  
**Repositório:** https://github.com/andreprogramadorbh-ai/erpserra

---

## 🎉 Pronto para Usar!

Agora você pode acessar os logs do sistema e começar a auditar todas as ações, eventos e erros registrados. Aproveite! 🚀
