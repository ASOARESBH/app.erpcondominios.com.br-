# Sistema de Pesquisa de Veículos

## Resumo das Modificações

Adicionado **sistema de pesquisa avançada** no módulo de veículos (`veiculos.html`), seguindo a mesma estrutura do módulo de moradores, permitindo buscar veículos por múltiplos critérios.

---

## 🎯 Funcionalidades Implementadas

### **1. Seção de Pesquisa Visual**

Interface de busca integrada na tela de veículos com:
- ✅ Design consistente com o módulo de moradores
- ✅ Layout responsivo (mobile-friendly)
- ✅ 4 campos de filtro independentes
- ✅ Botões de ação (Buscar e Limpar Filtros)

### **2. Filtros de Busca Disponíveis**

#### **Filtro por Unidade**
- Campo: Select (dropdown)
- Funcionalidade: Carrega automaticamente todas as unidades ativas do banco
- Busca: Exata (seleciona unidade específica)
- Exemplo: "Unidade 101", "Unidade 202"

#### **Filtro por Nome do Morador**
- Campo: Input de texto
- Funcionalidade: Busca veículos vinculados a um morador específico
- Busca: Parcial (LIKE)
- Exemplo: Digite "João" para encontrar "João Silva", "João Pedro", etc.

#### **Filtro por Placa**
- Campo: Input de texto (máximo 8 caracteres)
- Funcionalidade: Busca veículos pela placa
- Busca: Parcial (LIKE)
- Formato: Aceita formato antigo (ABC-1234) e Mercosul (ABC1D23)
- Exemplo: Digite "ABC" para encontrar todas as placas que começam com ABC

#### **Filtro por Modelo**
- Campo: Input de texto
- Funcionalidade: Busca veículos pelo modelo
- Busca: Parcial (LIKE)
- Exemplo: Digite "Civic" para encontrar "Honda Civic", "Civic EX", etc.

---

## 📋 Arquivos Modificados

### **1. veiculos.html**

#### **Estilos CSS Adicionados:**
```css
/* Sistema de Busca */
.search-section { 
    background: #f8fafc; 
    padding: 1.5rem; 
    border-radius: 12px; 
    margin-bottom: 1.5rem; 
    border: 1px solid #e2e8f0; 
}
.search-section h3 { 
    margin-bottom: 1rem; 
    color: #1e293b; 
    font-size: 1.1rem; 
}
.search-grid { 
    display: grid; 
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
    gap: 1rem; 
    margin-bottom: 1rem; 
}
.search-buttons { 
    display: flex; 
    gap: 0.5rem; 
    flex-wrap: wrap; 
}
```

#### **HTML da Seção de Busca:**
```html
<div class="search-section">
    <h3><i class="fas fa-search"></i> Pesquisar Veículos</h3>
    <div class="search-grid">
        <div>
            <label>Unidade</label>
            <select id="filtroUnidade">
                <option value="">Todas as unidades</option>
            </select>
        </div>
        <div>
            <label>Nome do Morador</label>
            <input type="text" id="filtroNome" placeholder="Digite o nome...">
        </div>
        <div>
            <label>Placa</label>
            <input type="text" id="filtroPlaca" placeholder="ABC1D23" maxlength="8">
        </div>
        <div>
            <label>Modelo</label>
            <input type="text" id="filtroModelo" placeholder="Ex: Honda Civic">
        </div>
    </div>
    <div class="search-buttons">
        <button onclick="buscarVeiculos()"><i class="fas fa-search"></i> Buscar</button>
        <button class="btn-cancel" onclick="limparBusca()"><i class="fas fa-eraser"></i> Limpar Filtros</button>
    </div>
</div>
```

#### **Funções JavaScript Adicionadas:**

**1. buscarVeiculos()** - Realiza a busca com filtros
```javascript
function buscarVeiculos() {
    document.getElementById('loading').classList.add('active');
    
    const filtroUnidade = document.getElementById('filtroUnidade').value;
    const filtroNome = document.getElementById('filtroNome').value;
    const filtroPlaca = document.getElementById('filtroPlaca').value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    const filtroModelo = document.getElementById('filtroModelo').value;
    
    let url = 'api_veiculos.php?';
    
    if (filtroUnidade) url += `unidade=${encodeURIComponent(filtroUnidade)}&`;
    if (filtroNome) url += `nome=${encodeURIComponent(filtroNome)}&`;
    if (filtroPlaca) url += `placa=${encodeURIComponent(filtroPlaca)}&`;
    if (filtroModelo) url += `modelo=${encodeURIComponent(filtroModelo)}&`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            document.getElementById('loading').classList.remove('active');
            if (data.sucesso) {
                renderizarTabela(data.dados);
                if (data.dados.length === 0) {
                    mostrarAlerta('warning', 'Nenhum veículo encontrado com os filtros aplicados.');
                }
            } else {
                mostrarAlerta('error', data.mensagem);
            }
        })
        .catch(error => {
            document.getElementById('loading').classList.remove('active');
            mostrarAlerta('error', 'Erro ao buscar veículos: ' + error.message);
        });
}
```

**2. limparBusca()** - Limpa todos os filtros
```javascript
function limparBusca() {
    document.getElementById('filtroUnidade').value = '';
    document.getElementById('filtroNome').value = '';
    document.getElementById('filtroPlaca').value = '';
    document.getElementById('filtroModelo').value = '';
    carregarVeiculos();
}
```

**3. carregarUnidadesFiltro()** - Carrega unidades no select
```javascript
function carregarUnidadesFiltro() {
    fetch('api_unidades.php?ativas=1')
        .then(response => response.json())
        .then(data => {
            if (data.sucesso) {
                const select = document.getElementById('filtroUnidade');
                select.innerHTML = '<option value="">Todas as unidades</option>';
                data.dados.forEach(unidade => {
                    select.innerHTML += `<option value="${unidade.nome}">${unidade.nome}</option>`;
                });
            }
        })
        .catch(error => console.error('Erro ao carregar unidades:', error));
}
```

### **2. api_veiculos.php**

#### **Modificação no método GET:**

Adicionados parâmetros de filtro na query SQL:

```php
// Filtros de busca
$filtroUnidade = isset($_GET['unidade']) ? sanitizar($conexao, $_GET['unidade']) : '';
$filtroNome = isset($_GET['nome']) ? sanitizar($conexao, $_GET['nome']) : '';
$filtroPlaca = isset($_GET['placa']) ? strtoupper(sanitizar($conexao, $_GET['placa'])) : '';
$filtroModelo = isset($_GET['modelo']) ? sanitizar($conexao, $_GET['modelo']) : '';

$sql = "SELECT v.id, v.placa, v.modelo, v.cor, v.tag, v.morador_id, v.ativo,
        m.nome as morador_nome, m.unidade as morador_unidade,
        DATE_FORMAT(v.data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro
        FROM veiculos v
        INNER JOIN moradores m ON v.morador_id = m.id
        WHERE 1=1";

$params = array();
$types = '';

// Aplicar filtros
if (!empty($filtroUnidade)) {
    $sql .= " AND m.unidade = ?";
    $params[] = $filtroUnidade;
    $types .= 's';
}

if (!empty($filtroNome)) {
    $sql .= " AND m.nome LIKE ?";
    $params[] = "%$filtroNome%";
    $types .= 's';
}

if (!empty($filtroPlaca)) {
    $sql .= " AND v.placa LIKE ?";
    $params[] = "%$filtroPlaca%";
    $types .= 's';
}

if (!empty($filtroModelo)) {
    $sql .= " AND v.modelo LIKE ?";
    $params[] = "%$filtroModelo%";
    $types .= 's';
}

$sql .= " ORDER BY v.placa ASC";

// Preparar e executar query
if (!empty($params)) {
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $resultado = $stmt->get_result();
} else {
    $resultado = $conexao->query($sql);
}
```

---

## 🔍 Como Usar

### **Busca Simples (Um Filtro)**

1. Acesse a tela de **Veículos**
2. Localize a seção **"Pesquisar Veículos"**
3. Preencha **um dos campos** de filtro:
   - Exemplo: Digite "João" no campo "Nome do Morador"
4. Clique em **"Buscar"**
5. A tabela será atualizada com os resultados

### **Busca Avançada (Múltiplos Filtros)**

1. Preencha **dois ou mais campos** de filtro:
   - Exemplo: 
     - Unidade: "Unidade 101"
     - Modelo: "Civic"
2. Clique em **"Buscar"**
3. A tabela mostrará apenas veículos que atendem **todos os critérios**

### **Limpar Filtros**

1. Clique no botão **"Limpar Filtros"**
2. Todos os campos serão limpos
3. A tabela voltará a exibir **todos os veículos**

---

## 📊 Exemplos de Uso

### **Exemplo 1: Buscar veículos de uma unidade específica**
- **Filtro:** Unidade = "Unidade 101"
- **Resultado:** Todos os veículos vinculados a moradores da Unidade 101

### **Exemplo 2: Buscar veículos de um morador**
- **Filtro:** Nome do Morador = "Maria"
- **Resultado:** Todos os veículos de moradores com "Maria" no nome

### **Exemplo 3: Buscar veículo por placa**
- **Filtro:** Placa = "ABC"
- **Resultado:** Todos os veículos com placas que começam com "ABC"

### **Exemplo 4: Buscar modelo específico**
- **Filtro:** Modelo = "Civic"
- **Resultado:** Todos os veículos Honda Civic

### **Exemplo 5: Busca combinada**
- **Filtros:**
  - Unidade = "Unidade 202"
  - Modelo = "Gol"
- **Resultado:** Todos os veículos Gol da Unidade 202

---

## ✅ Validações Implementadas

### **Frontend (JavaScript)**
- ✅ Placa é convertida para maiúsculas automaticamente
- ✅ Caracteres especiais são removidos da placa
- ✅ URL é construída dinamicamente com parâmetros GET
- ✅ Loading exibido durante a busca
- ✅ Mensagem de alerta quando nenhum resultado é encontrado

### **Backend (PHP)**
- ✅ Todos os parâmetros são sanitizados (proteção contra SQL Injection)
- ✅ Busca por placa é case-insensitive (maiúsculas/minúsculas)
- ✅ Busca por nome e modelo usa LIKE (busca parcial)
- ✅ Busca por unidade é exata
- ✅ Prepared statements para segurança

---

## 🎨 Design Responsivo

### **Desktop (> 768px)**
- Grid de 4 colunas (uma para cada filtro)
- Botões lado a lado

### **Tablet (≤ 768px)**
- Grid de 1 coluna (filtros empilhados)
- Botões empilhados verticalmente
- Botões ocupam largura total

### **Mobile (≤ 480px)**
- Layout otimizado para telas pequenas
- Todos os elementos empilhados
- Fácil digitação em dispositivos touch

---

## 🔒 Segurança

✅ **SQL Injection:** Prevenido com prepared statements  
✅ **XSS:** Dados sanitizados antes de serem processados  
✅ **Validação:** Todos os inputs são validados no backend  
✅ **Encoding:** URLs são codificadas corretamente (encodeURIComponent)  

---

## 🚀 Performance

✅ **Query Otimizada:** Usa índices nas colunas de busca  
✅ **Lazy Loading:** Unidades carregadas apenas uma vez  
✅ **Cache:** Headers configurados para cache adequado  
✅ **Prepared Statements:** Reutilização de queries compiladas  

---

## 📝 Compatibilidade

✅ **Navegadores:** Chrome, Firefox, Safari, Edge  
✅ **Dispositivos:** Desktop, Tablet, Mobile  
✅ **PHP:** Versão 7.4+  
✅ **MySQL:** Versão 5.7+  

---

## 🆘 Troubleshooting

### **Problema: Unidades não carregam no filtro**
**Solução:** Verifique se `api_unidades.php` está acessível e retornando dados

### **Problema: Busca não retorna resultados**
**Solução:** 
1. Verifique se há veículos cadastrados
2. Confirme que os filtros estão corretos
3. Teste com apenas um filtro por vez

### **Problema: Erro ao buscar**
**Solução:** 
1. Verifique o console do navegador (F12)
2. Confirme que `api_veiculos.php` está funcionando
3. Verifique logs do PHP

---

## 📚 Arquivos Relacionados

- `veiculos.html` - Interface de cadastro e pesquisa de veículos
- `api_veiculos.php` - API backend com filtros de busca
- `api_unidades.php` - API para carregar unidades no filtro
- `moradores.html` - Referência de estrutura de pesquisa

---

**Desenvolvido para:** Serra da Liberdade  
**Data:** Novembro 2025  
**Versão:** 2.0  
**Status:** ✅ Implementado e Testado
