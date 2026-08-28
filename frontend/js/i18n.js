/* Núcleo de internacionalização do ERP. Carregado antes do router. */
(function (window, document) {
    'use strict';
    const SUPPORTED = ['pt-BR', 'en-US', 'es-ES'];
    const DEFAULT_LOCALE = 'pt-BR';
    const state = { locale: localStorage.getItem('erp_locale') || DEFAULT_LOCALE, catalog: {}, loaded: false };

    function normalize(value) {
        if (!value) return DEFAULT_LOCALE;
        const raw = String(value).trim().replace('_', '-');
        const exact = SUPPORTED.find(item => item.toLowerCase() === raw.toLowerCase());
        if (exact) return exact;
        const language = raw.split('-')[0].toLowerCase();
        return SUPPORTED.find(item => item.toLowerCase().startsWith(language + '-')) || DEFAULT_LOCALE;
    }
    function t(key, variables = {}) {
        let text = state.catalog[key] || window.ERP_I18N_CATALOG?.[key] || key;
        Object.entries(variables).forEach(([name, value]) => { text = text.replaceAll(`{{${name}}}`, String(value)); });
        return text;
    }
    function translate(root = document) {
        root.querySelectorAll('[data-i18n]').forEach(el => { el.textContent = t(el.dataset.i18n); });
        root.querySelectorAll('[data-i18n-placeholder]').forEach(el => { el.placeholder = t(el.dataset.i18nPlaceholder); });
        root.querySelectorAll('[data-i18n-title]').forEach(el => { el.title = t(el.dataset.i18nTitle); });
        document.documentElement.lang = state.locale;
        document.dispatchEvent(new CustomEvent('erp:locale-changed', { detail: { locale: state.locale } }));
    }
    async function load(locale = state.locale) {
        if (!localStorage.getItem('erp_locale')) {
            try {
                const preference = await fetch('../api/api_i18n.php?acao=preferencia', { credentials: 'include' }).then(response => response.ok ? response.json() : null);
                if (preference?.sucesso && preference.dados?.locale) locale = preference.dados.locale;
            } catch (_) { /* sessão pública ou migration ausente: segue com fallback */ }
        }
        state.locale = normalize(locale);
        localStorage.setItem('erp_locale', state.locale);
        try {
            const response = await fetch(`i18n/${state.locale}.json`, { cache: 'no-cache' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            state.catalog = await response.json(); state.loaded = true;
        } catch (error) {
            console.warn('[i18n] Catálogo indisponível, usando fallback local:', error);
            state.catalog = state.locale === DEFAULT_LOCALE ? (window.ERP_I18N_CATALOG || {}) : {};
        }
        translate();
        try { await fetch('../api/api_i18n.php?acao=preferencia', { method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ locale: state.locale }) }); } catch (_) { /* login público ou API indisponível: localStorage mantém a preferência */ }
        return state.locale;
    }
    function formatNumber(value, options = {}) { return new Intl.NumberFormat(state.locale, options).format(Number(value) || 0); }
    function formatCurrency(value, currency = 'BRL') { return new Intl.NumberFormat(state.locale, { style: 'currency', currency }).format(Number(value) || 0); }
    function formatDate(value, options = {}) { const date = value instanceof Date ? value : new Date(value); return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(state.locale, options).format(date); }
    function getLocale() { return state.locale; }
    function available() { return [...SUPPORTED]; }
    window.ERP_I18N = { t, translate, load, formatNumber, formatCurrency, formatDate, getLocale, available, state };
    document.addEventListener('DOMContentLoaded', () => load(state.locale));
})(window, document);
