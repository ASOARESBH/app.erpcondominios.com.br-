/**
 * Página Chat-In (painel). Reutiliza o componente global window.ChatIn
 * (frontend/js/chatin-widget.js) em modo embutido; ao sair, devolve o chat ao botão flutuante.
 */
let container = null;

export function init() {
    container = document.getElementById('chatinPagina');
    if (!container) return;
    if (!window.ChatIn) {
        container.innerHTML = '<div class="chatin-vazio"><i class="fas fa-exclamation-triangle"></i><p>O componente do Chat-In não foi carregado. Atualize a página (Ctrl+F5).</p></div>';
        return;
    }
    container.innerHTML = '';
    window.ChatIn.montarEmbutido(container);
}

export function destroy() {
    if (window.ChatIn && container) window.ChatIn.desmontarEmbutido(container);
    container = null;
}
