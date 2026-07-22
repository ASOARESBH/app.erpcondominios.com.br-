# 🧪 Guia Rápido de Teste — relatorios_hidrometro.html

**Tempo estimado:** 5 minutos

---

## ✅ PRÉ-REQUISITOS

- [ ] Servidor XAMPP/Apache rodando
- [ ] Base de dados com dados de hidrômetro
- [ ] Usuário autenticado na aplicação
- [ ] Browser moderno (Chrome, Firefox, Edge)

---

## 🚀 TESTE 1: Carregamento de Unidades e Moradores (Básico)

### Passos:
1. Abrir: https://asl.erpcondominios.com.br/frontend/relatorios_hidrometro.html
2. Aguardar carregamento
3. Verificar DevTools (F12):
   - **Console** → Não deve ter `SyntaxError: Unexpected token '<'`
   - **Network** → Requests para `/api/api_unidades.php` e `/api/api_moradores.php` com status 200

### Esperado:
```
✅ Select "Filtro Unidade" preenchido com opções
✅ Select "Filtro Morador" preenchido com opções
✅ Sem erros no console
✅ Network tab mostra 2 requests com status 200
```

### Se falhar:
```
❌ Unidades não carregam
   → DevTools → Console → Mensagem de erro?
   → DevTools → Network → Status code?
   → Verificar se /api/ está acessível

❌ SyntaxError no console
   → ANTES da correção: será este o erro
   → DEPOIS da correção: não deve existir
```

---

## 🚀 TESTE 2: Pesquisa com Filtros (Funcionalidade)

### Passos:
1. Na página relatorios_hidrometro.html
2. Clicar "Pesquisar" (sem filtros)
3. Aguardar ~3 segundos

### Esperado:
```
✅ Tabela preenchida com dados
✅ Mensagem "X registro(s) encontrado(s)"
✅ Estatísticas aparecem (Total, Consumo, Valor)
✅ Botões PDF/Excel ficam habilitados
✅ Sem erros no console
```

### DevTools Validation:
```
Network tab → procurar por api_leituras.php
  ✅ Status: 200
  ✅ Type: xhr (XHR request)
  ✅ Headers tab → Cookie header presente
     → PHPSESSID=...
```

---

## 🚀 TESTE 3: Filtro por Unidade

### Passos:
1. Na página, selecionar uma unidade em "Filtro Unidade"
2. Clicar "Pesquisar"
3. Aguardar resultados

### Esperado:
```
✅ Tabela mostra apenas registros dessa unidade
✅ Número de registros reduz (< resultado anterior)
✅ Mensagem confirma quantidade
✅ Sem erros
```

---

## 🚀 TESTE 4: Filtro por Morador

### Passos:
1. Na página, selecionar um morador em "Filtro Morador"
2. Clicar "Pesquisar"
3. Aguardar resultados

### Esperado:
```
✅ Tabela mostra apenas registros desse morador
✅ Sem erros
```

---

## 🚀 TESTE 5: Filtro por Datas

### Passos:
1. Preencher "Data Inicial" (ex: 01/01/2025)
2. Preencher "Data Final" (ex: 31/01/2025)
3. Clicar "Pesquisar"

### Esperado:
```
✅ Tabela mostra apenas registros nesse período
✅ Sem erros
```

---

## 🚀 TESTE 6: Filtro Avançado (Número Hidrômetro)

### Passos:
1. Preencher "Número do Hidrômetro" (ex: H123)
2. Clicar "Pesquisar"
3. Aguardar

### Esperado:
```
✅ Tabela filtra por números contêm "H123"
✅ Sem erros
```

---

## 🚀 TESTE 7: Exportar PDF

### Passos:
1. Fazer uma pesquisa (para ter dados)
2. Clicar botão "📄 Exportar PDF"
3. Aguardar download

### Esperado:
```
✅ Navegador baixa arquivo "relatorio_hidrometros.pdf"
✅ Arquivo abre no leitor de PDF
✅ Tabela está formatada corretamente
✅ Mensagem "PDF gerado com sucesso!"
```

---

## 🚀 TESTE 8: Exportar Excel

### Passos:
1. Fazer uma pesquisa (para ter dados)
2. Clicar botão "📊 Exportar Excel"
3. Aguardar download

### Esperado:
```
✅ Navegador baixa arquivo "relatorio_hidrometros.xlsx"
✅ Arquivo abre no Excel/Calc
✅ Coluna headers corretas
✅ Dados formatados
✅ Mensagem "Excel gerado com sucesso!"
```

---

## 🚀 TESTE 9: Limpar Filtros

### Passos:
1. Preencher alguns filtros
2. Clicar "🗑️ Limpar Filtros"

### Esperado:
```
✅ Todos os filtros voltam ao padrão vazio
✅ Tabela volta a "Use os filtros acima..."
✅ Estatísticas desaparecem
✅ Botões PDF/Excel desabilitam
```

---

## 🧐 TESTE 10: DevTools - Network (Crítico)

### Passos:
1. DevTools → Network tab
2. Limpar histórico (ícone de lata)
3. Fazer uma pesquisa na página
4. Observar requisições

### Esperado (Para cada request api/*)
```
Name: api_leituras.php (ou outro endpoint)
Status: 200 ✅
Type: xhr (XHR request)

Headers tab:
  Cookie: PHPSESSID=... ✅

Response tab:
  {
    "sucesso": true,
    "dados": [...]
  }
```

### ❌ Se Status = 403
```
❌ ERRO: Backend não reconhece sessão
-> Verificar se cookie PHPSESSID é válido
-> Fazer login novamente
-> Se persistir: backend problem, não frontend
```

### ❌ Se Response = HTML
```
❌ ERRO: Servidor retornando HTML (erro)
-> Code ANTES teria SyntaxError
-> Code DEPOIS deve tratá-lo como erro legível
-> Verificar se arquivo está apareça "Erro ao buscar dados: ..."
```

---

## 📋 Checklist Final

### Funcionalidade
- [ ] Unidades carregam na inicialização
- [ ] Moradores carregam na inicialização
- [ ] Pesquisa sem filtros retorna dados
- [ ] Pesquisa com unidade filtra
- [ ] Pesquisa com morador filtra
- [ ] Pesquisa com datas filtra
- [ ] Pesquisa com número hidrômetro filtra
- [ ] PDF exporta
- [ ] Excel exporta
- [ ] Limpar filtros funciona

### Segurança & Performance
- [ ] Console sem SyntaxError
- [ ] Console sem "Unexpected token '<'"
- [ ] Network requests têm Cookie header
- [ ] Respostas são JSON (status 200)
- [ ] SessionManager não quebra

### Mensagens
- [ ] Nenhuma mensagem de erro técnica exposta
- [ ] Erros são legíveis ("Erro 403 (api_leituras.php)")
- [ ] Sucesso mostra quantidade registros
- [ ] Loading spinner aparece durante busca

---

## 🎯 RESULTADO FINAL

Se **TODOS** os testes passarem ✅:
```
Código está PRONTO PARA PRODUÇÃO
```

Se **ALGUM** teste falhar ❌:
```
1. Verificar console (F12 → Console)
2. Verificar network (F12 → Network)
3. Verificar status HTTP
4. Verificar se messages são legíveis
```

---

## 💬 Suporte

Se erro persiste:
1. Verificar se `/api/` está acessível (não bloqueado por .htaccess)
2. Verificar se PHPSESSID é válido (fazer login novamente)
3. Verificar se `/api/` endpoints retornam JSON (não HTML)
4. Verificar console para mensagens específicas

---

**Tempo total esperado:** 5-10 minutos ⏱️
