/**
 * WebcamCapture — Componente reutilizável de captura de foto via webcam
 * Versão: 1.0.0
 *
 * Uso:
 *   import { WebcamCapture } from '../components/webcam-capture.js';
 *
 *   const cam = new WebcamCapture({
 *     onCapture: (file, dataUrl) => {
 *       // file  → File object pronto para FormData
 *       // dataUrl → string base64 para preview
 *     }
 *   });
 *   cam.open(); // abre o modal
 *
 * Compatível com: Chrome, Edge, Firefox, Opera, Android Chrome, Tablets
 * Requisitos: HTTPS ou localhost (getUserMedia exige contexto seguro)
 */

export class WebcamCapture {
    constructor(options = {}) {
        this.onCapture   = options.onCapture   || (() => {});
        this.onCancel    = options.onCancel    || (() => {});
        this.targetWidth  = options.targetWidth  || 800;
        this.targetHeight = options.targetHeight || 600;
        this.quality      = options.quality      || 0.90;
        this.maxSizeKB    = options.maxSizeKB    || 500;
        this.minWidth     = options.minWidth     || 640;
        this.minHeight    = options.minHeight    || 480;

        this._stream      = null;
        this._devices     = [];
        this._currentDeviceId = null;
        this._modal       = null;
        this._video       = null;
        this._canvas      = null;
        this._capturedDataUrl = null;
        this._capturedFile    = null;

        this._injectStyles();
    }

    // ─── PUBLIC API ────────────────────────────────────────────────────────────

    async open() {
        this._buildModal();
        document.body.appendChild(this._modal);
        requestAnimationFrame(() => this._modal.classList.add('wc-visible'));

        await this._checkPermissionAndStart();
    }

    destroy() {
        this._stopStream();
        if (this._modal && this._modal.parentNode) {
            this._modal.parentNode.removeChild(this._modal);
        }
        this._modal = null;
    }

    // ─── MODAL BUILD ───────────────────────────────────────────────────────────

    _buildModal() {
        const el = document.createElement('div');
        el.className = 'wc-overlay';
        el.innerHTML = `
        <div class="wc-modal" role="dialog" aria-modal="true" aria-label="Capturar foto do visitante">
            <div class="wc-header">
                <span class="wc-title"><i class="fas fa-camera"></i> Capturar Foto</span>
                <button class="wc-btn-close" id="wcBtnClose" title="Fechar" tabindex="0">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- STATUS / PERMISSÃO -->
            <div class="wc-status" id="wcStatus" style="display:none;"></div>

            <!-- SELETOR DE CÂMERA -->
            <div class="wc-camera-selector" id="wcCameraSelector" style="display:none;">
                <label class="wc-label"><i class="fas fa-video"></i> Selecionar câmera:</label>
                <div class="wc-device-list" id="wcDeviceList"></div>
            </div>

            <!-- PREVIEW AO VIVO -->
            <div class="wc-preview-wrap" id="wcPreviewWrap">
                <video class="wc-video" id="wcVideo" autoplay playsinline muted></video>
                <div class="wc-video-overlay" id="wcVideoOverlay" style="display:none;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <span>Iniciando câmera...</span>
                </div>
            </div>

            <!-- FOTO CAPTURADA -->
            <div class="wc-captured-wrap" id="wcCapturedWrap" style="display:none;">
                <img class="wc-captured-img" id="wcCapturedImg" src="" alt="Foto capturada">
                <div class="wc-captured-badge">
                    <i class="fas fa-check-circle"></i> Foto capturada
                </div>
            </div>

            <canvas id="wcCanvas" style="display:none;"></canvas>

            <!-- AÇÕES -->
            <div class="wc-actions" id="wcActions">
                <!-- Estado: câmera ativa -->
                <div id="wcActionsLive">
                    <button class="wc-btn wc-btn-secondary" id="wcBtnTrocarCamera" style="display:none;">
                        <i class="fas fa-sync-alt"></i> Trocar câmera
                    </button>
                    <button class="wc-btn wc-btn-primary" id="wcBtnCapturar">
                        <i class="fas fa-camera"></i> Capturar Foto
                    </button>
                    <button class="wc-btn wc-btn-ghost" id="wcBtnEscolherArquivoLive">
                        <i class="fas fa-folder-open"></i> Escolher Arquivo
                    </button>
                    <button class="wc-btn wc-btn-ghost" id="wcBtnCancelarLive">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
                <!-- Estado: foto capturada -->
                <div id="wcActionsCapturada" style="display:none;">
                    <button class="wc-btn wc-btn-success" id="wcBtnUsarFoto">
                        <i class="fas fa-check"></i> Usar Foto
                    </button>
                    <button class="wc-btn wc-btn-secondary" id="wcBtnTirarNovamente">
                        <i class="fas fa-redo"></i> Tirar Novamente
                    </button>
                    <button class="wc-btn wc-btn-ghost" id="wcBtnCancelarCapturada">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
                <!-- Estado: sem câmera / erro -->
                <div id="wcActionsErro" style="display:none;">
                    <button class="wc-btn wc-btn-primary" id="wcBtnEscolherArquivoErro">
                        <i class="fas fa-folder-open"></i> Selecionar Arquivo
                    </button>
                    <button class="wc-btn wc-btn-ghost" id="wcBtnCancelarErro">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
                <!-- Estado: aguardando permissão -->
                <div id="wcActionsPermissao" style="display:none;">
                    <button class="wc-btn wc-btn-primary" id="wcBtnPermitirAcesso">
                        <i class="fas fa-lock-open"></i> Permitir acesso
                    </button>
                    <button class="wc-btn wc-btn-ghost" id="wcBtnEscolherArquivoPermissao">
                        <i class="fas fa-folder-open"></i> Escolher Arquivo
                    </button>
                    <button class="wc-btn wc-btn-ghost" id="wcBtnCancelarPermissao">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
            </div>

            <!-- Input de arquivo oculto (fallback) -->
            <input type="file" id="wcFileInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
        </div>`;

        this._modal  = el;
        this._video  = el.querySelector('#wcVideo');
        this._canvas = el.querySelector('#wcCanvas');

        this._bindEvents();
    }

    _bindEvents() {
        const $ = id => this._modal.querySelector('#' + id);

        // Fechar
        $('wcBtnClose').addEventListener('click', () => this._cancelar());
        $('wcBtnCancelarLive').addEventListener('click', () => this._cancelar());
        $('wcBtnCancelarCapturada').addEventListener('click', () => this._cancelar());
        $('wcBtnCancelarErro').addEventListener('click', () => this._cancelar());
        $('wcBtnCancelarPermissao').addEventListener('click', () => this._cancelar());

        // Fechar ao clicar fora
        this._modal.addEventListener('click', e => {
            if (e.target === this._modal) this._cancelar();
        });

        // Capturar
        $('wcBtnCapturar').addEventListener('click', () => this._capturar());

        // Usar foto
        $('wcBtnUsarFoto').addEventListener('click', () => this._confirmarFoto());

        // Tirar novamente
        $('wcBtnTirarNovamente').addEventListener('click', () => this._tirarNovamente());

        // Trocar câmera
        $('wcBtnTrocarCamera').addEventListener('click', () => this._toggleCameraSelector());

        // Permitir acesso
        $('wcBtnPermitirAcesso').addEventListener('click', () => this._iniciarCamera());

        // Escolher arquivo (múltiplos botões)
        const fileInput = $('wcFileInput');
        ['wcBtnEscolherArquivoLive', 'wcBtnEscolherArquivoErro', 'wcBtnEscolherArquivoPermissao'].forEach(id => {
            $( id).addEventListener('click', () => fileInput.click());
        });
        fileInput.addEventListener('change', () => this._handleFileInput(fileInput));

        // ESC fecha
        this._escHandler = e => { if (e.key === 'Escape') this._cancelar(); };
        document.addEventListener('keydown', this._escHandler);
    }

    // ─── PERMISSÃO E CÂMERAS ───────────────────────────────────────────────────

    async _checkPermissionAndStart() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            this._showErro('Seu navegador não suporta acesso à câmera. Use Chrome, Edge ou Firefox atualizado.');
            return;
        }

        // Verificar estado da permissão (API Permissions, se disponível)
        if (navigator.permissions) {
            try {
                const perm = await navigator.permissions.query({ name: 'camera' });
                if (perm.state === 'denied') {
                    this._showPermissaoBloqueada();
                    return;
                }
            } catch (_) { /* API não disponível em todos os browsers */ }
        }

        await this._iniciarCamera();
    }

    async _iniciarCamera() {
        this._showLoading('Iniciando câmera...');
        try {
            // Primeiro acesso: pedir permissão e listar devices
            const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            stream.getTracks().forEach(t => t.stop()); // só para obter permissão

            await this._listarDispositivos();

            const deviceId = this._currentDeviceId || (this._devices[0] && this._devices[0].deviceId);
            await this._startStream(deviceId);
        } catch (err) {
            console.warn('[WebcamCapture] Erro ao iniciar câmera:', err);
            if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                this._showPermissaoBloqueada();
            } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                this._showErro('Nenhuma câmera foi encontrada neste equipamento.');
            } else {
                this._showErro('Não foi possível acessar a câmera: ' + err.message);
            }
        }
    }

    async _listarDispositivos() {
        const devices = await navigator.mediaDevices.enumerateDevices();
        this._devices = devices.filter(d => d.kind === 'videoinput');

        if (this._devices.length === 0) {
            this._showErro('Nenhuma câmera foi encontrada neste equipamento.');
            return;
        }

        if (this._devices.length > 1) {
            this._renderDeviceList();
        }
    }

    _renderDeviceList() {
        const selector = this._modal.querySelector('#wcCameraSelector');
        const list     = this._modal.querySelector('#wcDeviceList');
        list.innerHTML = '';

        this._devices.forEach((dev, i) => {
            const label = dev.label || `Câmera ${i + 1}`;
            const isSelected = dev.deviceId === (this._currentDeviceId || this._devices[0].deviceId);
            const item = document.createElement('label');
            item.className = 'wc-device-item' + (isSelected ? ' selected' : '');
            item.innerHTML = `
                <input type="radio" name="wcCamera" value="${dev.deviceId}" ${isSelected ? 'checked' : ''}>
                <i class="fas fa-video"></i>
                <span>${this._escapeHtml(label)}</span>`;
            item.querySelector('input').addEventListener('change', async () => {
                this._currentDeviceId = dev.deviceId;
                this._modal.querySelectorAll('.wc-device-item').forEach(el => el.classList.remove('selected'));
                item.classList.add('selected');
                selector.style.display = 'none';
                await this._startStream(dev.deviceId);
            });
            list.appendChild(item);
        });

        selector.style.display = 'none'; // começa fechado
        const btnTrocar = this._modal.querySelector('#wcBtnTrocarCamera');
        if (btnTrocar) btnTrocar.style.display = 'inline-flex';
    }

    _toggleCameraSelector() {
        const selector = this._modal.querySelector('#wcCameraSelector');
        selector.style.display = selector.style.display === 'none' ? 'block' : 'none';
    }

    async _startStream(deviceId) {
        this._stopStream();
        this._showLoading('Conectando à câmera...');

        const constraints = {
            video: {
                deviceId: deviceId ? { exact: deviceId } : undefined,
                width:  { ideal: this.targetWidth },
                height: { ideal: this.targetHeight },
                facingMode: deviceId ? undefined : 'user'
            },
            audio: false
        };

        try {
            this._stream = await navigator.mediaDevices.getUserMedia(constraints);
            this._currentDeviceId = deviceId;
            this._video.srcObject = this._stream;
            await new Promise(resolve => { this._video.onloadedmetadata = resolve; });
            this._showLive();
        } catch (err) {
            console.error('[WebcamCapture] Erro ao iniciar stream:', err);
            this._showErro('Não foi possível iniciar a câmera selecionada.');
        }
    }

    _stopStream() {
        if (this._stream) {
            this._stream.getTracks().forEach(t => t.stop());
            this._stream = null;
        }
        if (this._video) {
            this._video.srcObject = null;
        }
    }

    // ─── CAPTURA ───────────────────────────────────────────────────────────────

    async _capturar() {
        const video  = this._video;
        const canvas = this._canvas;

        const vw = video.videoWidth  || this.targetWidth;
        const vh = video.videoHeight || this.targetHeight;

        // Validar resolução mínima
        if (vw < this.minWidth || vh < this.minHeight) {
            this._showStatus(`warning`, `Resolução da câmera (${vw}×${vh}) abaixo do mínimo recomendado (${this.minWidth}×${this.minHeight}). A foto será aceita mesmo assim.`);
        }

        // Calcular dimensões para crop 3:4 (retrato)
        const targetRatio = 3 / 4;
        let cropW = vw;
        let cropH = Math.round(vw / targetRatio);
        if (cropH > vh) {
            cropH = vh;
            cropW = Math.round(vh * targetRatio);
        }
        const offsetX = Math.round((vw - cropW) / 2);
        const offsetY = Math.round((vh - cropH) / 2);

        // Dimensão de saída
        const outW = Math.min(cropW, this.targetWidth);
        const outH = Math.round(outW / targetRatio);

        canvas.width  = outW;
        canvas.height = outH;

        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, offsetX, offsetY, cropW, cropH, 0, 0, outW, outH);

        // Gerar JPEG com qualidade configurada
        let dataUrl = canvas.toDataURL('image/jpeg', this.quality);

        // Compressão progressiva se necessário
        dataUrl = await this._comprimirSeNecessario(canvas, dataUrl);

        this._capturedDataUrl = dataUrl;
        this._capturedFile    = this._dataUrlToFile(dataUrl, `visitante_${Date.now()}.jpg`);

        this._showCapturada(dataUrl);
    }

    async _comprimirSeNecessario(canvas, dataUrl) {
        let quality = this.quality;
        let size    = Math.round((dataUrl.length * 3) / 4); // estimativa em bytes

        while (size > this.maxSizeKB * 1024 && quality > 0.3) {
            quality -= 0.1;
            dataUrl  = canvas.toDataURL('image/jpeg', quality);
            size     = Math.round((dataUrl.length * 3) / 4);
        }
        return dataUrl;
    }

    _dataUrlToFile(dataUrl, filename) {
        const arr  = dataUrl.split(',');
        const mime = arr[0].match(/:(.*?);/)[1];
        const bstr = atob(arr[1]);
        let n = bstr.length;
        const u8arr = new Uint8Array(n);
        while (n--) u8arr[n] = bstr.charCodeAt(n);
        return new File([u8arr], filename, { type: mime });
    }

    _confirmarFoto() {
        this._stopStream();
        this.onCapture(this._capturedFile, this._capturedDataUrl);
        this.destroy();
    }

    _tirarNovamente() {
        this._capturedDataUrl = null;
        this._capturedFile    = null;
        this._showLoading('Reiniciando câmera...');
        this._startStream(this._currentDeviceId);
    }

    _cancelar() {
        this._stopStream();
        if (this._escHandler) document.removeEventListener('keydown', this._escHandler);
        this.onCancel();
        this.destroy();
    }

    // ─── FALLBACK: ARQUIVO ─────────────────────────────────────────────────────

    _handleFileInput(input) {
        const file = input.files[0];
        if (!file) return;

        if (file.size > 10 * 1024 * 1024) {
            this._showStatus('error', 'Arquivo muito grande. Máximo: 10MB.');
            return;
        }

        const reader = new FileReader();
        reader.onload = async e => {
            const dataUrl = e.target.result;

            // Validar dimensões mínimas
            const img = new Image();
            img.onload = async () => {
                if (img.width < this.minWidth || img.height < this.minHeight) {
                    this._showStatus('warning', `Imagem com resolução baixa (${img.width}×${img.height}). Recomendado: ${this.minWidth}×${this.minHeight} ou maior.`);
                }

                // Redimensionar e comprimir via canvas
                const canvas = this._canvas;
                const targetRatio = 3 / 4;
                let outW = Math.min(img.width, this.targetWidth);
                let outH = Math.round(outW / targetRatio);
                if (outH > img.height) {
                    outH = img.height;
                    outW = Math.round(outH * targetRatio);
                }
                canvas.width  = outW;
                canvas.height = outH;
                const ctx = canvas.getContext('2d');
                const srcX = Math.round((img.width  - outW * (img.height / outH)) / 2);
                ctx.drawImage(img, Math.max(0, srcX), 0, img.width - Math.max(0, srcX * 2), img.height, 0, 0, outW, outH);

                let finalDataUrl = canvas.toDataURL('image/jpeg', this.quality);
                finalDataUrl = await this._comprimirSeNecessario(canvas, finalDataUrl);

                this._capturedDataUrl = finalDataUrl;
                this._capturedFile    = this._dataUrlToFile(finalDataUrl, `visitante_${Date.now()}.jpg`);
                this._stopStream();
                this._showCapturada(finalDataUrl);
            };
            img.src = dataUrl;
        };
        reader.readAsDataURL(file);
        input.value = '';
    }

    // ─── ESTADOS DA UI ─────────────────────────────────────────────────────────

    _showLoading(msg = 'Aguarde...') {
        const overlay = this._modal.querySelector('#wcVideoOverlay');
        overlay.innerHTML = `<i class="fas fa-spinner fa-spin fa-2x"></i><span>${msg}</span>`;
        overlay.style.display = 'flex';
        this._modal.querySelector('#wcPreviewWrap').style.display = 'flex';
        this._modal.querySelector('#wcCapturedWrap').style.display = 'none';
        this._modal.querySelector('#wcActionsLive').style.display = 'none';
        this._modal.querySelector('#wcActionsCapturada').style.display = 'none';
        this._modal.querySelector('#wcActionsErro').style.display = 'none';
        this._modal.querySelector('#wcActionsPermissao').style.display = 'none';
    }

    _showLive() {
        const overlay = this._modal.querySelector('#wcVideoOverlay');
        overlay.style.display = 'none';
        this._modal.querySelector('#wcPreviewWrap').style.display = 'flex';
        this._modal.querySelector('#wcCapturedWrap').style.display = 'none';
        this._modal.querySelector('#wcActionsLive').style.display = 'flex';
        this._modal.querySelector('#wcActionsCapturada').style.display = 'none';
        this._modal.querySelector('#wcActionsErro').style.display = 'none';
        this._modal.querySelector('#wcActionsPermissao').style.display = 'none';
        this._clearStatus();
    }

    _showCapturada(dataUrl) {
        const img = this._modal.querySelector('#wcCapturedImg');
        img.src = dataUrl;
        this._modal.querySelector('#wcPreviewWrap').style.display = 'none';
        this._modal.querySelector('#wcCapturedWrap').style.display = 'flex';
        this._modal.querySelector('#wcActionsLive').style.display = 'none';
        this._modal.querySelector('#wcActionsCapturada').style.display = 'flex';
        this._modal.querySelector('#wcActionsErro').style.display = 'none';
        this._modal.querySelector('#wcActionsPermissao').style.display = 'none';
    }

    _showErro(msg) {
        this._stopStream();
        this._showStatus('error', msg);
        this._modal.querySelector('#wcPreviewWrap').style.display = 'none';
        this._modal.querySelector('#wcCapturedWrap').style.display = 'none';
        this._modal.querySelector('#wcActionsLive').style.display = 'none';
        this._modal.querySelector('#wcActionsCapturada').style.display = 'none';
        this._modal.querySelector('#wcActionsErro').style.display = 'flex';
        this._modal.querySelector('#wcActionsPermissao').style.display = 'none';
    }

    _showPermissaoBloqueada() {
        this._showStatus('warning', 'O ERP necessita acessar a câmera para capturar a foto do visitante. Clique em "Permitir acesso" ou escolha um arquivo.');
        this._modal.querySelector('#wcPreviewWrap').style.display = 'none';
        this._modal.querySelector('#wcCapturedWrap').style.display = 'none';
        this._modal.querySelector('#wcActionsLive').style.display = 'none';
        this._modal.querySelector('#wcActionsCapturada').style.display = 'none';
        this._modal.querySelector('#wcActionsErro').style.display = 'none';
        this._modal.querySelector('#wcActionsPermissao').style.display = 'flex';
    }

    _showStatus(type, msg) {
        const el = this._modal.querySelector('#wcStatus');
        const icons = { error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle', success: 'fa-check-circle' };
        el.className = `wc-status wc-status-${type}`;
        el.innerHTML = `<i class="fas ${icons[type] || 'fa-info-circle'}"></i> ${msg}`;
        el.style.display = 'flex';
    }

    _clearStatus() {
        const el = this._modal.querySelector('#wcStatus');
        if (el) { el.style.display = 'none'; el.textContent = ''; }
    }

    // ─── UTILITÁRIOS ───────────────────────────────────────────────────────────

    _escapeHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ─── ESTILOS INJETADOS ─────────────────────────────────────────────────────

    _injectStyles() {
        if (document.getElementById('wc-capture-styles')) return;
        const style = document.createElement('style');
        style.id = 'wc-capture-styles';
        style.textContent = `
/* ══ WebcamCapture Component ══════════════════════════════════════════════ */
.wc-overlay {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(10, 20, 40, 0.75);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .25s ease;
    backdrop-filter: blur(4px);
}
.wc-overlay.wc-visible { opacity: 1; }

.wc-modal {
    background: #fff; border-radius: 16px;
    width: min(560px, 96vw); max-height: 92vh;
    display: flex; flex-direction: column;
    box-shadow: 0 24px 64px rgba(0,0,0,.35);
    overflow: hidden;
    transform: translateY(20px); transition: transform .25s ease;
}
.wc-overlay.wc-visible .wc-modal { transform: translateY(0); }

.wc-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid #e8edf5;
    background: linear-gradient(135deg, #1a3a6b 0%, #2563eb 100%);
    color: #fff;
}
.wc-title { font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.wc-btn-close {
    background: rgba(255,255,255,.15); border: none; color: #fff;
    width: 32px; height: 32px; border-radius: 50%; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .2s;
}
.wc-btn-close:hover { background: rgba(255,255,255,.3); }

.wc-status {
    margin: 12px 16px 0; padding: 10px 14px; border-radius: 8px;
    font-size: 13px; display: flex; align-items: flex-start; gap: 8px;
    line-height: 1.4;
}
.wc-status-error   { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.wc-status-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.wc-status-info    { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.wc-status-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }

.wc-camera-selector {
    padding: 12px 16px 0;
}
.wc-label { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.wc-device-list { display: flex; flex-direction: column; gap: 6px; }
.wc-device-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px; border-radius: 8px; cursor: pointer;
    border: 1.5px solid #e5e7eb; transition: all .15s;
    font-size: 13px; color: #374151;
}
.wc-device-item:hover { border-color: #2563eb; background: #eff6ff; }
.wc-device-item.selected { border-color: #2563eb; background: #eff6ff; color: #2563eb; font-weight: 600; }
.wc-device-item input[type=radio] { accent-color: #2563eb; }

.wc-preview-wrap {
    position: relative; background: #0a0a0a;
    display: flex; align-items: center; justify-content: center;
    min-height: 280px; max-height: 380px; overflow: hidden;
    margin: 12px 16px 0; border-radius: 10px;
}
.wc-video {
    width: 100%; height: 100%; object-fit: cover;
    transform: scaleX(-1); /* espelho */
    border-radius: 10px;
}
.wc-video-overlay {
    position: absolute; inset: 0; display: flex;
    flex-direction: column; align-items: center; justify-content: center;
    gap: 12px; color: #fff; font-size: 14px;
    background: rgba(0,0,0,.6); border-radius: 10px;
}

.wc-captured-wrap {
    position: relative; display: flex;
    align-items: center; justify-content: center;
    min-height: 280px; max-height: 380px;
    margin: 12px 16px 0; border-radius: 10px; overflow: hidden;
    background: #f1f5f9;
}
.wc-captured-img {
    max-width: 100%; max-height: 380px; object-fit: contain;
    border-radius: 10px;
}
.wc-captured-badge {
    position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%);
    background: rgba(22, 163, 74, .92); color: #fff;
    padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 600;
    display: flex; align-items: center; gap: 6px; white-space: nowrap;
}

.wc-actions {
    padding: 14px 16px 18px;
    border-top: 1px solid #e8edf5; margin-top: 12px;
}
.wc-actions > div {
    display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end;
}
.wc-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: 8px; border: none;
    font-size: 13px; font-weight: 600; cursor: pointer;
    transition: all .15s; white-space: nowrap;
}
.wc-btn-primary   { background: #2563eb; color: #fff; }
.wc-btn-primary:hover { background: #1d4ed8; }
.wc-btn-success   { background: #16a34a; color: #fff; }
.wc-btn-success:hover { background: #15803d; }
.wc-btn-secondary { background: #e5e7eb; color: #374151; }
.wc-btn-secondary:hover { background: #d1d5db; }
.wc-btn-ghost     { background: transparent; color: #6b7280; border: 1.5px solid #e5e7eb; }
.wc-btn-ghost:hover { background: #f9fafb; color: #374151; }

@media (max-width: 480px) {
    .wc-modal { border-radius: 12px 12px 0 0; max-height: 98vh; }
    .wc-overlay { align-items: flex-end; }
    .wc-preview-wrap, .wc-captured-wrap { min-height: 220px; max-height: 300px; }
    .wc-actions > div { justify-content: stretch; }
    .wc-btn { flex: 1; justify-content: center; }
}
/* ══════════════════════════════════════════════════════════════════════════ */
        `;
        document.head.appendChild(style);
    }
}
