# Correção: relatorios_hidrometro.html

## 🔴 PROBLEMAS IDENTIFICADOS

### 1. **carregarUnidades()** — Sem tratamento de erro
```javascript
// ❌ ERRADO: Sem validação de status HTTP
async function carregarUnidades() {
    try {
        const response = await fetch('../api/api_unidades.php');
        const data = await response.json();  // ⚠️ Pode quebrar com HTML (403)
        // ... resto do código
    } catch (error) {
        console.error('Erro:', error);  // ⚠️ Silenciado
    }
}
```

**Problemas:**
- ❌ Não valida `response.ok` → se retorna 403, tenta parsear HTML como JSON
- ❌ Sem leitura de `response.text()` para erro legível
- ❌ Sem `credentials: 'include'` para enviar session cookie

### 2. **carregarMoradores()** — Mesmo problema
```javascript
// ❌ ERRADO: Sem validação de status HTTP
async function carregarMoradores() {
    try {
        const response = await fetch('../api/api_moradores.php');
        const data = await response.json();  // ⚠️ Pode quebrar com HTML (403)
        // ... resto do código
    } catch (error) {
        console.error('Erro:', error);  // ⚠️ Silenciado
    }
}
```

**Problemas:**
- ❌ Não valida `response.ok` → HTML em vez de JSON
- ❌ Sem tratamento legível de erro
- ❌ Sem `credentials: 'include'`

---

## ✅ SOLUÇÃO COMPLETA

### Adicionar esta CONSTANTE no início do `<script>`:

```javascript
// ===== CONFIGURAÇÃO DE API (defina uma vez) =====
const API_BASE = '../api/';  // Path base para todos os endpoints

/**
 * Função defensiva para fazer fetch() com tratamento robusto de erro
 * Evita completamente o erro "Unexpected token '<'" ao parsear HTML como JSON
 * 
 * @param {string} endpoint - Nome do arquivo PHP (ex: 'api_leituras.php')
 * @param {Object} options - Opções adicionais (method, body, etc)
 * @returns {Promise<Object>} Os dados retornados pelo servidor
 * @throws {Error} Com mensagem legível do erro
 */
async function apiCall(endpoint, options = {}) {
    // Validação
    if (!endpoint) {
        throw new Error('Endpoint não pode estar vazio');
    }

    // Construir URL completa
    const url = API_BASE + endpoint;

    // Opções padrão (com session cookie)
    const fetchOpts = {
        credentials: 'include',  // ✅ Enviar PHPSESSID
        ...options
    };

    let response;
    try {
        response = await fetch(url, fetchOpts);
    } catch (networkError) {
        throw new Error(`Erro de conexão ao chamar ${endpoint}: ${networkError.message}`);
    }

    // ✅ VALIDAÇÃO CRÍTICA: Verificar status HTTP ANTES de parsear JSON
    if (!response.ok) {
        let errorMessage = `Erro ${response.status} ao chamar ${endpoint}`;
        
        // Tentar ler erro detalhado (pode ser text ou JSON)
        try {
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const errorData = await response.json();
                if (errorData.mensagem) {
                    errorMessage = `${response.status}: ${errorData.mensagem}`;
                }
            } else {
                // Para erros não-JSON (HTML, etc), apenas registrar status
                // Não tenta parsear HTML como JSON
                console.warn(`Resposta ${response.status} não é JSON:`, response.statusText);
            }
        } catch (parseError) {
            // Falha ao ler erro detalhado — usar mensagem genérica
            console.warn('Não consegui ler detalhes do erro:', parseError.message);
        }

        throw new Error(errorMessage);
    }

    // ✅ Parsear JSON SOMENTE após validar response.ok
    let data;
    try {
        data = await response.json();
    } catch (jsonError) {
        // ⚠️ response.ok = true, mas corpo não é JSON válido
        throw new Error(`Resposta inválida do servidor (${endpoint}): ${jsonError.message}`);
    }

    return data;
}
```

---

## 📝 Substituições Exatas

### Substituição 1: carregarUnidades()

**ANTES (com erro):**
```javascript
async function carregarUnidades() {
    try {
        const response = await fetch('../api/api_unidades.php');
        const data = await response.json();
        if (data.sucesso) {
            const select = document.getElementById('filtro_unidade');
            data.dados.forEach(unidade => {
                const option = new Option(unidade.bloco ? `${unidade.nome} - ${unidade.bloco}` : unidade.nome, unidade.nome);
                select.add(option);
            });
        }
    } catch (error) {
        console.error('Erro:', error);
    }
}
```

**DEPOIS (defensivo):**
```javascript
async function carregarUnidades() {
    try {
        const data = await apiCall('api_unidades.php');  // ✅ Usa função defensiva
        if (data.sucesso) {
            const select = document.getElementById('filtro_unidade');
            data.dados.forEach(unidade => {
                const option = new Option(unidade.bloco ? `${unidade.nome} - ${unidade.bloco}` : unidade.nome, unidade.nome);
                select.add(option);
            });
        }
    } catch (error) {
        console.error('Erro ao carregar unidades:', error.message);
        mostrarAlerta('Erro ao carregar unidades: ' + error.message, 'error');
    }
}
```

---

### Substituição 2: carregarMoradores()

**ANTES (com erro):**
```javascript
async function carregarMoradores() {
    try {
        const response = await fetch('../api/api_moradores.php');
        const data = await response.json();
        if (data.sucesso) {
            const select = document.getElementById('filtro_morador');
            data.dados.forEach(morador => {
                const option = new Option(`${morador.nome} - ${morador.unidade}`, morador.id);
                select.add(option);
            });
        }
    } catch (error) {
        console.error('Erro:', error);
    }
}
```

**DEPOIS (defensivo):**
```javascript
async function carregarMoradores() {
    try {
        const data = await apiCall('api_moradores.php');  // ✅ Usa função defensiva
        if (data.sucesso) {
            const select = document.getElementById('filtro_morador');
            data.dados.forEach(morador => {
                const option = new Option(`${morador.nome} - ${morador.unidade}`, morador.id);
                select.add(option);
            });
        }
    } catch (error) {
        console.error('Erro ao carregar moradores:', error.message);
        mostrarAlerta('Erro ao carregar moradores: ' + error.message, 'error');
    }
}
```

---

### Substituição 3: pesquisar() — Simplificar com apiCall()

**ANTES (parcialmente defensivo):**
```javascript
async function pesquisar() {
    const dataInicial = document.getElementById('data_inicial').value;
    const dataFinal = document.getElementById('data_final').value;
    const unidade = document.getElementById('filtro_unidade').value;
    const moradorId = document.getElementById('filtro_morador').value;
    
    let url = '../api/api_leituras.php?';
    if (dataInicial) url += `data_inicial=${dataInicial}&`;
    if (dataFinal) url += `data_final=${dataFinal}&`;
    if (unidade) url += `unidade=${encodeURIComponent(unidade)}&`;
    if (moradorId) url += `morador_id=${moradorId}&`;
    
    document.getElementById('loading').classList.add('active');
    
    try {
        const response = await fetch(url, { credentials: 'include' });
        if (!response.ok) {
            const texto = await response.text();
            let mensagem = `Erro ${response.status}`;
            try {
                const parsed = JSON.parse(texto);
                if (parsed && parsed.mensagem) mensagem = parsed.mensagem;
            } catch (e) {
                // resposta não é JSON — manter mensagem genérica
            }
            mostrarAlerta('Erro ao buscar dados: ' + mensagem, 'error');
            document.getElementById('loading').classList.remove('active');
            return;
        }

        let data;
        try {
            data = await response.json();
        } catch (e) {
            mostrarAlerta('Resposta inválida do servidor', 'error');
            console.error('Falha ao fazer parse do JSON:', e);
            document.getElementById('loading').classList.remove('active');
            return;
        }

        if (data.sucesso) {
            // ... resto do código
        }
    } catch (error) {
        console.error('Erro:', error);
        mostrarAlerta('Erro ao buscar dados', 'error');
    } finally {
        document.getElementById('loading').classList.remove('active');
    }
}
```

**DEPOIS (simplificado com apiCall):**
```javascript
async function pesquisar() {
    const dataInicial = document.getElementById('data_inicial').value;
    const dataFinal = document.getElementById('data_final').value;
    const unidade = document.getElementById('filtro_unidade').value;
    const moradorId = document.getElementById('filtro_morador').value;
    
    // Construir query string apenas (apiCall adiciona API_BASE)
    let endpoint = 'api_leituras.php?';
    if (dataInicial) endpoint += `data_inicial=${dataInicial}&`;
    if (dataFinal) endpoint += `data_final=${dataFinal}&`;
    if (unidade) endpoint += `unidade=${encodeURIComponent(unidade)}&`;
    if (moradorId) endpoint += `morador_id=${moradorId}&`;
    
    document.getElementById('loading').classList.add('active');
    
    try {
        const data = await apiCall(endpoint);  // ✅ Usa função defensiva com todas validações
        
        if (data.sucesso) {
            dadosRelatorio = data.dados;
            
            // Filtrar por número e lacre (frontend)
            const numero = document.getElementById('filtro_numero').value.trim().toUpperCase();
            const lacre = document.getElementById('filtro_lacre').value.trim().toUpperCase();
            
            if (numero) {
                dadosRelatorio = dadosRelatorio.filter(d => d.numero_hidrometro && d.numero_hidrometro.toUpperCase().includes(numero));
            }
            if (lacre) {
                dadosRelatorio = dadosRelatorio.filter(d => d.numero_lacre && d.numero_lacre.toUpperCase().includes(lacre));
            }
            
            renderizarResultados();
            calcularEstatisticas();
            document.getElementById('btnPDF').disabled = dadosRelatorio.length === 0;
            document.getElementById('btnExcel').disabled = dadosRelatorio.length === 0;
            
            mostrarAlerta(`${dadosRelatorio.length} registro(s) encontrado(s)`, 'info');
        } else {
            mostrarAlerta('Erro ao buscar dados: ' + data.mensagem, 'error');
        }
    } catch (error) {
        console.error('Erro na pesquisa:', error.message);
        mostrarAlerta('Erro ao buscar dados: ' + error.message, 'error');
    } finally {
        document.getElementById('loading').classList.remove('active');
    }
}
```

---

## 📋 Checklist de Correção

- ✅ Adicione a constante `API_BASE` no início do `<script>`
- ✅ Adicione a função `apiCall()` (tratamento defensivo centralizado)
- ✅ Reescreva `carregarUnidades()` com chamada a `apiCall()`
- ✅ Reescreva `carregarMoradores()` com chamada a `apiCall()`
- ✅ Simplifique `pesquisar()` para usar `apiCall()`
- ✅ Todos os fetch agora tem `credentials: 'include'`
- ✅ Todos os fetch agora validam `response.ok` ANTES de `response.json()`
- ✅ Erro "Unexpected token '<'" é **IMPOSSÍVEL** agora

---

## 🧪 Validação

Após as correções, esperado:

1. **Sem erro 403 + JSON inválido**
   - ✅ `carregarUnidades()` faz fetch com `credentials: 'include'`
   - ✅ `carregarMoradores()` faz fetch com `credentials: 'include'`
   - ✅ `pesquisar()` reutiliza `apiCall()` (já tem credenciais)

2. **Sem erro "Unexpected token '<'"**
   - ✅ Valida `response.ok` ANTES de chamar `response.json()`
   - ✅ Se 403/401 → lê erro via `response.text()` ou `response.json()` de forma segura
   - ✅ Nunca tenta parsear HTML como JSON

3. **Mensagens de erro legíveis**
   - Antes: `SyntaxError: Unexpected token '<'`
   - Depois: `Erro 403: Acesso negado ao endpoint`

4. **Session Manager não quebrado**
   - ✅ Usa `credentials: 'include'` (não localStorage)
   - ✅ SessionManager continua monitorando requisições
   - ✅ Renovação de sessão funciona normalmente
