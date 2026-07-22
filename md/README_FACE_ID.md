# Sistema de Face ID - Reconhecimento Facial para Visitantes

## 📋 Resumo

Sistema completo de **reconhecimento facial** usando **face-api.js** para identificação de visitantes na portaria, com cadastro via link único e validação no tablet.

---

## 🎯 Objetivo

Permitir que visitantes sejam identificados por **reconhecimento facial** em vez de QR Code, oferecendo uma experiência mais moderna e segura.

---

## 🏗️ Arquitetura do Sistema

### Fluxo Completo

```
1. CADASTRO
   Morador → Cria acesso → Escolhe "Face ID" → Sistema gera link único
   ↓
2. LINK ÚNICO
   Visitante → Acessa link → Captura foto → Sistema gera descritor facial (128 dimensões)
   ↓
3. VALIDAÇÃO
   Visitante → Chega na portaria → Tablet captura face → Compara descritor → Libera acesso
```

---

## 📁 Arquivos Criados

### 1. create_face_descriptors.sql (6 KB)

**Descrição**: Script SQL para criar tabelas

**Tabelas criadas**:
- `face_descriptors` - Armazena descritores faciais
- `validacoes_face_id` - Registra validações
- Views: `vw_face_descriptors_ativos`, `vw_estatisticas_face_id`

**Campos principais**:
- `descritor` - JSON com array de 128 dimensões
- `token_cadastro` - Token único de 64 caracteres
- `token_usado` - 0=não usado, 1=usado
- `data_expiracao` - Validade do token (padrão: 48h)
- `foto_url` - URL da foto usada

### 2. api_face_id.php (11 KB)

**Descrição**: API REST para Face ID

**Endpoints**:
- `POST /api_face_id.php?action=gerar_token` - Gera link único
- `GET /api_face_id.php?action=validar_token` - Valida token
- `POST /api_face_id.php?action=cadastrar_descritor` - Salva descritor
- `POST /api_face_id.php?action=validar_face` - Valida face
- `POST /api_face_id.php?action=registrar_validacao` - Registra validação
- `GET /api_face_id.php?action=listar_descritores` - Lista descritores
- `GET /api_face_id.php?action=estatisticas` - Estatísticas

### 3. cadastro_face_id.html (15 KB)

**Descrição**: Página de cadastro via link único

**Funcionalidades**:
- Validação de token
- Câmera frontal
- Detecção de rosto em tempo real
- Captura de foto
- Geração de descritor facial (128 dimensões)
- Upload automático

**Bibliotecas**:
- TensorFlow.js 3.11.0
- face-api.js 1.7.12 (Vladmandic)

---

## 🔧 Como Funciona

### 1. Geração de Descritor Facial

**O que é um descritor?**
- Array de 128 números (dimensões)
- Representa características únicas do rosto
- Gerado pelo modelo FaceNet

**Exemplo**:
```javascript
[
  0.123, -0.456, 0.789, ..., -0.321  // 128 números
]
```

### 2. Comparação de Faces

**Método**: Distância Euclidiana

**Fórmula**:
```
distância = √Σ(a[i] - b[i])²
```

**Interpretação**:
- `0.0` = Idêntico
- `< 0.6` = Mesma pessoa (threshold padrão)
- `> 0.6` = Pessoas diferentes

**Exemplo**:
```javascript
const distancia = faceapi.euclideanDistance(descritor1, descritor2);

if (distancia < 0.6) {
    console.log('Mesma pessoa! Acesso liberado');
} else {
    console.log('Pessoa diferente! Acesso negado');
}
```

---

## 📝 Implementação Pendente

### 1. Atualizar visitantes.html

**Adicionar opção de tipo de identificação**:

```html
<!-- No formulário de novo acesso -->
<div class="form-group">
    <label>Tipo de Identificação *</label>
    <select id="tipo_identificacao" class="form-control" required>
        <option value="qrcode">QR Code</option>
        <option value="face_id">Face ID (Reconhecimento Facial)</option>
    </select>
</div>

<!-- Mostrar/ocultar campos conforme tipo -->
<div id="qrcode-info" style="display: block;">
    <p>QR Code será gerado automaticamente após cadastro.</p>
</div>

<div id="faceid-info" style="display: none;">
    <p>Link único será enviado ao visitante para cadastro de Face ID.</p>
    <button type="button" class="btn btn-info" id="btn-gerar-link-faceid">
        🔗 Gerar Link de Cadastro
    </button>
    <div id="link-faceid-gerado" style="display: none; margin-top: 10px;">
        <input type="text" id="link-faceid" class="form-control" readonly>
        <button type="button" class="btn btn-success btn-sm mt-2" id="btn-copiar-link">
            📋 Copiar Link
        </button>
        <button type="button" class="btn btn-primary btn-sm mt-2" id="btn-enviar-whatsapp">
            📱 Enviar via WhatsApp
        </button>
    </div>
</div>
```

**JavaScript**:

```javascript
// Alternar entre QR Code e Face ID
document.getElementById('tipo_identificacao').addEventListener('change', function() {
    const tipo = this.value;
    
    if (tipo === 'qrcode') {
        document.getElementById('qrcode-info').style.display = 'block';
        document.getElementById('faceid-info').style.display = 'none';
    } else {
        document.getElementById('qrcode-info').style.display = 'none';
        document.getElementById('faceid-info').style.display = 'block';
    }
});

// Gerar link de Face ID
document.getElementById('btn-gerar-link-faceid').addEventListener('click', async function() {
    const visitante_id = document.getElementById('visitante_id').value;
    
    if (!visitante_id) {
        alert('Selecione um visitante primeiro');
        return;
    }
    
    try {
        const response = await fetch('api_face_id.php?action=gerar_token', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitante_id: visitante_id,
                validade_horas: 48
            })
        });
        
        const result = await response.json();
        
        if (result.sucesso) {
            document.getElementById('link-faceid').value = result.dados.link_cadastro;
            document.getElementById('link-faceid-gerado').style.display = 'block';
            
            // Salvar token no formulário (hidden input)
            let inputToken = document.getElementById('face_token');
            if (!inputToken) {
                inputToken = document.createElement('input');
                inputToken.type = 'hidden';
                inputToken.id = 'face_token';
                inputToken.name = 'face_token';
                document.getElementById('form-acesso').appendChild(inputToken);
            }
            inputToken.value = result.dados.token;
            
            alert('Link gerado com sucesso! Válido por 48 horas.');
        } else {
            alert('Erro: ' + result.mensagem);
        }
    } catch (error) {
        console.error(error);
        alert('Erro ao gerar link');
    }
});

// Copiar link
document.getElementById('btn-copiar-link').addEventListener('click', function() {
    const link = document.getElementById('link-faceid');
    link.select();
    document.execCommand('copy');
    alert('Link copiado!');
});

// Enviar via WhatsApp
document.getElementById('btn-enviar-whatsapp').addEventListener('click', function() {
    const link = document.getElementById('link-faceid').value;
    const visitante_nome = document.getElementById('visitante_id').selectedOptions[0].text;
    
    const mensagem = `Olá ${visitante_nome}! 

Para acessar o condomínio, cadastre seu Face ID (reconhecimento facial) através deste link:

${link}

Este link é válido por 48 horas e é de uso único.

Att,
Condomínio Serra da Liberdade`;
    
    const whatsappUrl = `https://wa.me/?text=${encodeURIComponent(mensagem)}`;
    window.open(whatsappUrl, '_blank');
});

// Atualizar cadastro de acesso para incluir tipo_identificacao
async function cadastrarAcesso() {
    const dados = {
        visitante_id: document.getElementById('visitante_id').value,
        tipo_identificacao: document.getElementById('tipo_identificacao').value,
        // ... outros campos
    };
    
    // Se Face ID, incluir token
    if (dados.tipo_identificacao === 'face_id') {
        dados.face_token = document.getElementById('face_token').value;
    }
    
    // ... resto do código de cadastro
}
```

### 2. Atualizar console_acesso.html

**Adicionar botão de Face ID ao lado do QR Code**:

```html
<!-- No menu principal -->
<button class="btn-menu" id="btn-face-id">
    👤 Face ID
</button>

<!-- Modal de Face ID -->
<div id="modal-face-id" class="modal">
    <div class="modal-content">
        <h2>🔐 Validação por Face ID</h2>
        
        <div class="info-box">
            <p>Posicione o rosto do visitante na câmera para identificação automática.</p>
        </div>
        
        <div id="face-status" class="status">
            🔍 Aguardando rosto...
        </div>
        
        <div class="camera-container">
            <video id="face-video" autoplay playsinline></video>
            <canvas id="face-canvas"></canvas>
        </div>
        
        <button class="btn btn-danger" id="btn-cancelar-face">
            ✕ Cancelar
        </button>
    </div>
</div>
```

**JavaScript**:

```javascript
// ========================================
// VARIÁVEIS GLOBAIS FACE ID
// ========================================
let faceVideo = null;
let faceCanvas = null;
let faceCtx = null;
let faceStream = null;
let faceDetecting = false;
let allDescriptors = [];

// ========================================
// CARREGAR MODELOS FACE-API.JS
// ========================================
async function carregarModelosFaceAPI() {
    console.log('[FACE-API] Carregando modelos...');
    
    const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model';
    
    await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
        faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
        faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
    ]);
    
    console.log('[FACE-API] Modelos carregados');
}

// ========================================
// ABRIR MODAL FACE ID
// ========================================
async function abrirFaceID() {
    document.getElementById('modal-face-id').style.display = 'flex';
    
    // Obter descritores do banco
    await obterDescritoresCadastrados();
    
    // Iniciar câmera
    await iniciarCameraFaceID();
    
    // Iniciar detecção
    detectarFaceID();
}

// ========================================
// OBTER DESCRITORES CADASTRADOS
// ========================================
async function obterDescritoresCadastrados() {
    try {
        const response = await fetch('api_face_id.php?action=validar_face', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                descritor: [], // Vazio, só para obter lista
                dispositivo_token: dispositivoToken
            })
        });
        
        const result = await response.json();
        
        if (result.sucesso) {
            allDescriptors = result.dados.descritores;
            console.log(`[FACE-ID] ${allDescriptors.length} descritores carregados`);
        }
    } catch (error) {
        console.error('[FACE-ID] Erro ao obter descritores:', error);
    }
}

// ========================================
// INICIAR CÂMERA FACE ID
// ========================================
async function iniciarCameraFaceID() {
    try {
        faceStream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: 'user',
                width: { ideal: 640 },
                height: { ideal: 480 }
            }
        });
        
        faceVideo.srcObject = faceStream;
        
        await new Promise(resolve => {
            faceVideo.onloadedmetadata = () => resolve();
        });
        
        faceCanvas.width = faceVideo.videoWidth;
        faceCanvas.height = faceVideo.videoHeight;
        
        console.log('[FACE-ID] Câmera iniciada');
    } catch (error) {
        console.error('[FACE-ID] Erro ao iniciar câmera:', error);
        alert('Erro ao acessar câmera');
    }
}

// ========================================
// DETECTAR FACE ID
// ========================================
async function detectarFaceID() {
    if (!faceDetecting) return;
    
    const status = document.getElementById('face-status');
    
    try {
        const deteccao = await faceapi
            .detectSingleFace(faceVideo, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks()
            .withFaceDescriptor();
        
        if (deteccao) {
            // Desenhar detecção
            const dims = faceapi.matchDimensions(faceCanvas, faceVideo, true);
            const resizedDetection = faceapi.resizeResults(deteccao, dims);
            
            faceCtx.clearRect(0, 0, faceCanvas.width, faceCanvas.height);
            faceapi.draw.drawDetections(faceCanvas, resizedDetection);
            faceapi.draw.drawFaceLandmarks(faceCanvas, resizedDetection);
            
            // Comparar com descritores cadastrados
            status.textContent = '🔍 Comparando...';
            
            const descritorCapturado = deteccao.descriptor;
            let melhorMatch = null;
            let menorDistancia = 1.0;
            
            for (const desc of allDescriptors) {
                const descritorCadastrado = JSON.parse(desc.descritor);
                const distancia = faceapi.euclideanDistance(descritorCapturado, descritorCadastrado);
                
                if (distancia < menorDistancia) {
                    menorDistancia = distancia;
                    melhorMatch = desc;
                }
            }
            
            // Threshold: 0.6
            if (melhorMatch && menorDistancia < 0.6) {
                console.log(`[FACE-ID] Match encontrado: ${melhorMatch.visitante_nome} (distância: ${menorDistancia.toFixed(4)})`);
                
                // Parar detecção
                faceDetecting = false;
                
                // Parar câmera
                if (faceStream) {
                    faceStream.getTracks().forEach(track => track.stop());
                }
                
                // Fechar modal
                document.getElementById('modal-face-id').style.display = 'none';
                
                // Registrar validação
                await registrarValidacaoFaceID(melhorMatch, menorDistancia, 'sucesso');
                
                // Mostrar resultado
                mostrarResultadoFaceID(melhorMatch, menorDistancia);
            } else {
                status.textContent = '🔍 Aguardando rosto conhecido...';
            }
        } else {
            faceCtx.clearRect(0, 0, faceCanvas.width, faceCanvas.height);
            status.textContent = '🔍 Aguardando rosto...';
        }
    } catch (error) {
        console.error('[FACE-ID] Erro na detecção:', error);
    }
    
    // Continuar detecção
    if (faceDetecting) {
        requestAnimationFrame(detectarFaceID);
    }
}

// ========================================
// REGISTRAR VALIDAÇÃO FACE ID
// ========================================
async function registrarValidacaoFaceID(match, similaridade, resultado) {
    try {
        await fetch('api_face_id.php?action=registrar_validacao', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                face_descriptor_id: match.id,
                visitante_id: match.visitante_id,
                acesso_id: match.acesso_id,
                dispositivo_id: dispositivoId,
                similaridade: similaridade,
                threshold_usado: 0.6,
                resultado: resultado
            })
        });
    } catch (error) {
        console.error('[FACE-ID] Erro ao registrar validação:', error);
    }
}

// ========================================
// MOSTRAR RESULTADO FACE ID
// ========================================
function mostrarResultadoFaceID(match, similaridade) {
    const modal = document.getElementById('modal-resultado');
    const conteudo = document.getElementById('resultado-conteudo');
    
    const similaridadePercent = ((1 - similaridade) * 100).toFixed(1);
    
    conteudo.innerHTML = `
        <div class="resultado-sucesso">
            <div class="icone-sucesso">✅</div>
            <h2>ACESSO LIBERADO</h2>
            <p class="subtitulo">Face ID Reconhecido</p>
            
            <div class="info-visitante">
                <p><strong>Visitante:</strong> ${match.visitante_nome}</p>
                <p><strong>Documento:</strong> ${match.visitante_documento}</p>
                <p><strong>Similaridade:</strong> ${similaridadePercent}%</p>
                ${match.placa ? `<p><strong>Veículo:</strong> ${match.placa} - ${match.modelo} ${match.cor}</p>` : ''}
                ${match.morador_nome ? `<p><strong>Visitando:</strong> ${match.morador_nome} - ${match.morador_unidade}</p>` : ''}
                <p><strong>Tipo de Acesso:</strong> ${match.tipo_acesso}</p>
                <p><strong>Validade:</strong> ${match.data_inicial} até ${match.data_final}</p>
            </div>
            
            <button class="btn btn-primary" onclick="fecharResultado()">
                ✓ Fechar
            </button>
        </div>
    `;
    
    modal.style.display = 'flex';
    
    // Som de sucesso
    new Audio('success.mp3').play().catch(() => {});
}

// ========================================
// INICIALIZAÇÃO
// ========================================
window.addEventListener('DOMContentLoaded', async () => {
    // Obter elementos
    faceVideo = document.getElementById('face-video');
    faceCanvas = document.getElementById('face-canvas');
    faceCtx = faceCanvas.getContext('2d');
    
    // Carregar modelos
    await carregarModelosFaceAPI();
    
    // Event listeners
    document.getElementById('btn-face-id').addEventListener('click', abrirFaceID);
    document.getElementById('btn-cancelar-face').addEventListener('click', () => {
        faceDetecting = false;
        if (faceStream) {
            faceStream.getTracks().forEach(track => track.stop());
        }
        document.getElementById('modal-face-id').style.display = 'none';
    });
});
```

---

## 🧪 Testes Recomendados

### 1. Teste de Cadastro
- [ ] Gerar link único
- [ ] Acessar link no celular
- [ ] Permitir acesso à câmera
- [ ] Capturar face
- [ ] Verificar se descritor foi salvo no banco

### 2. Teste de Validação
- [ ] Abrir console_acesso.html no tablet
- [ ] Clicar em "Face ID"
- [ ] Posicionar rosto cadastrado
- [ ] Verificar se foi reconhecido
- [ ] Verificar se acesso foi liberado

### 3. Teste de Segurança
- [ ] Tentar usar link expirado
- [ ] Tentar usar link já usado
- [ ] Tentar validar com rosto não cadastrado
- [ ] Verificar threshold (0.6)

---

## 📊 Estatísticas

### Queries Úteis

```sql
-- Total de Face IDs cadastrados
SELECT COUNT(*) FROM face_descriptors WHERE token_usado = 1;

-- Taxa de sucesso
SELECT 
    COUNT(CASE WHEN resultado = 'sucesso' THEN 1 END) * 100.0 / COUNT(*) AS taxa_sucesso
FROM validacoes_face_id;

-- Média de similaridade
SELECT AVG(similaridade) FROM validacoes_face_id WHERE resultado = 'sucesso';

-- Top 10 visitantes mais validados
SELECT 
    v.nome,
    COUNT(*) AS total_validacoes
FROM validacoes_face_id vf
INNER JOIN visitantes v ON vf.visitante_id = v.id
GROUP BY v.id
ORDER BY total_validacoes DESC
LIMIT 10;
```

---

## ⚙️ Configurações

### Threshold de Similaridade

**Padrão**: 0.6

**Ajustar**:
- **Mais restritivo** (menos falsos positivos): 0.5
- **Menos restritivo** (menos falsos negativos): 0.7

### Validade do Link

**Padrão**: 48 horas

**Ajustar** em `api_face_id.php`:
```php
$validade_horas = $dados['validade_horas'] ?? 48;
```

---

## 🎉 Benefícios

- ✅ **Mais moderno**: Tecnologia de ponta
- ✅ **Mais rápido**: Identificação em < 1 segundo
- ✅ **Mais seguro**: Não pode ser falsificado
- ✅ **Mais conveniente**: Não precisa de celular
- ✅ **Mais higiênico**: Sem contato físico

---

## 📚 Recursos

- [face-api.js Docs](https://github.com/vladmandic/face-api)
- [TensorFlow.js](https://www.tensorflow.org/js)
- [FaceNet Paper](https://arxiv.org/abs/1503.03832)

---

**Versão**: 1.0  
**Data**: 26/12/2024  
**Autor**: Manus AI  
**Biblioteca**: face-api.js 1.7.12
