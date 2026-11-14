from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
PORTAL = (ROOT / "frontend/portal_morador.html").read_text(encoding="utf-8")
CSS = (ROOT / "assets/css/pages/portal_morador.css").read_text(encoding="utf-8")
FILES_API = (ROOT / "api/api_arquivos_tenant.php").read_text(encoding="utf-8")
LOGO_API = (ROOT / "api/get_logo_empresa.php").read_text(encoding="utf-8")
MARKET_API = (ROOT / "api/api_portal_marketplace.php").read_text(encoding="utf-8")
PWA = (ROOT / "PWA/js/portal.js").read_text(encoding="utf-8")
CHATIN = (ROOT / "frontend/js/chatin-widget.js").read_text(encoding="utf-8")

EXPECTED_TABS = [
    "perfil", "visitantes", "acessos", "dependentes", "convidados",
    "alertas", "protocolos", "hidrometro", "veiculos", "documentos",
    "projetos", "controle_acesso", "ordens_servico", "marketplace",
]


class PortalRegressionTests(unittest.TestCase):
    def test_all_14_tabs_are_kept_in_desktop_and_mobile_navigation(self):
        desktop = re.findall(r"abrirAba\('([^']+)', this\)", PORTAL)
        mobile = re.findall(r"abrirAbaMobile\('([^']+)'", PORTAL)
        self.assertEqual(desktop, EXPECTED_TABS)
        self.assertEqual(mobile, EXPECTED_TABS)
        self.assertIn('href="/assets/css/pages/portal_morador.css?', PORTAL)

    def test_sidebar_and_drawer_are_vertical_responsive_and_keyboard_aware(self):
        self.assertIn("flex-direction: column", CSS)
        self.assertIn("position: fixed", CSS)
        self.assertIn("prefers-reduced-motion: reduce", CSS)
        self.assertIn('role="dialog" aria-modal="false"', PORTAL)
        self.assertIn("aria-hidden", PORTAL)
        self.assertIn("e.key === 'Escape'", PORTAL)
        self.assertIn("e.key !== 'Tab'", PORTAL)
        self.assertIn("aria-current", PORTAL)

    def test_portal_logo_read_is_limited_to_same_tenant_logo(self):
        self.assertIn("tf_bearer_token()", FILES_API)
        self.assertIn("tf_portal_tenant_for_token($db, $portalToken)", FILES_API)
        self.assertRegex(FILES_API, r"tenant_id\s*=\s*\?\s+AND\s+tipo\s*=\s*'logo_tenant'")
        self.assertIn("strpos((string)$row['mime_type'], 'image/') !== 0", FILES_API)
        self.assertIn("tipo = 'logo_tenant'", LOGO_API)
        self.assertIn("'Authorization': 'Bearer ' + Portal.token", PORTAL)
        self.assertNotIn("token.substring", PORTAL)

    def test_logo_discovery_uses_portal_bearer_without_php_session_cookie(self):
        self.assertIn("'Authorization': 'Bearer ' + Portal.token", PORTAL)
        self.assertIn("credentials: 'omit'", PORTAL)
        self.assertIn("cache: 'no-store'", PORTAL)
        self.assertIn("logo_portal_bearer_token()", LOGO_API)
        self.assertIn("logo_portal_tenant_id($conexao, $portal_token)", LOGO_API)
        self.assertRegex(LOGO_API, r"WHERE sp\.token = \? AND sp\.ativo = 1 AND sp\.data_expiracao > NOW\(\) AND m\.ativo = 1")
        self.assertIn("JOIN moradores m ON m.id = sp.morador_id AND m.tenant_id = sp.tenant_id", LOGO_API)
        self.assertIn("bind_param('s', $token)", LOGO_API)
        self.assertIn("Access-Control-Allow-Headers: Content-Type, Authorization", LOGO_API)
        self.assertIn("Sessão expirada. Faça login novamente.", LOGO_API)
        self.assertIn("$auth = verificarAutenticacao(true)", LOGO_API)
        self.assertNotIn("$_SESSION['morador_logado']", LOGO_API)
        self.assertNotIn("$_SESSION['tenant_id']", LOGO_API)

    def test_firebase_config_requires_active_pwa_and_complete_web_messaging_fields(self):
        config_api = (ROOT / "api/api_pwa_sw_config.php").read_text(encoding="utf-8")
        sw = (ROOT / "PWA/firebase-messaging-sw.js").read_text(encoding="utf-8")
        self.assertIn("$firebase_configurado = $pwa_ativo", config_api)
        self.assertIn("$project_id_valido", config_api)
        self.assertIn("$firebase_config['messagingSenderId']", config_api)
        self.assertIn("$firebase_config['appId']", config_api)
        self.assertIn("isset($cfg['pwa_ativo']) && $cfg['pwa_ativo'] === '1'", config_api)
        self.assertIn("_pwa_config_value_ready", config_api)
        self.assertIn("CHANGE[_ -]?ME", config_api)
        self.assertIn("preg_match('/^AIza", config_api)
        self.assertIn("preg_match('/^1:", config_api)
        self.assertIn("preg_match('/^[A-Za-z0-9_-]{40,128}={0,2}$", config_api)
        self.assertIn("$api_key_valida", config_api)
        self.assertIn("$auth_domain_valido", config_api)
        self.assertIn("$sender_id_valido", config_api)
        self.assertIn("$app_id_valido", config_api)
        self.assertIn("$vapid_key_valida", config_api)
        self.assertNotIn("?? '1') === '1'", config_api)
        self.assertIn("self.PWA_CONFIG.pwaAtivo", sw)
        self.assertIn("self.PWA_CONFIG.vapidKey", sw)
        self.assertIn("_valorPronto(FIREBASE_CONFIG.apiKey)", sw)
        self.assertIn("CHANGE[_ -]?ME", sw)
        self.assertIn("_appIdValido", sw)
        self.assertIn("app.options.projectId === FIREBASE_CONFIG.projectId", sw)
        self.assertIn("push desativado", sw)

    def test_fcm_initialization_requires_browser_support_and_active_service_worker(self):
        self.assertIn("await firebase.messaging.isSupported()", PWA)
        self.assertIn("if (configOk && _swRegistration)", PWA)
        self.assertIn("if (firebaseOk)", PWA)
        self.assertIn("valorPronto(firebase.apiKey)", PWA)
        self.assertIn("CHANGE[_ -]?ME", PWA)
        self.assertIn("senderIdValido", PWA)
        self.assertIn("vapidKeyValida", PWA)
        self.assertIn("app.options.apiKey !== FIREBASE_CONFIG.apiKey", PWA)
        self.assertIn("app.options.appId !== FIREBASE_CONFIG.appId", PWA)
        self.assertIn("Firebase Installations negou a criação da instalação.", PWA)
        self.assertNotIn("console.error('[PWA] Erro ao obter token FCM:'", PWA)

    def test_marketplace_uses_validated_tenant_and_current_schema(self):
        self.assertIn("JOIN moradores m ON m.id = sp.morador_id AND m.tenant_id = sp.tenant_id", MARKET_API)
        self.assertIn("m.ativo = 1", MARKET_API)
        self.assertIn("ps.preco_venda AS valor", MARKET_API)
        self.assertIn("AVG(nota_morador)", MARKET_API)
        self.assertGreaterEqual(MARKET_API.count("WHERE tenant_id = ? AND nota_morador IS NOT NULL"), 2)
        self.assertIn("$dataParams = array_merge([$tenant_id], $params, [$por_pag, $offset]);", MARKET_API)
        self.assertIn("WHERE tenant_id = ? AND caminho_legado = ? AND tipo = 'logo_tenant'", LOGO_API)
        self.assertIn("catch (Throwable $e)", MARKET_API)
        self.assertIn("Não foi possível concluir a operação do Marketplace.", MARKET_API)
        self.assertNotIn("->error", MARKET_API)
        self.assertIn("throw new RuntimeException('Falha ao criar pedido do Marketplace.')", MARKET_API)
        self.assertRegex(MARKET_API, r"\$params\s*=\s*\[\$morador_id,\s*\$tenant_id,\s*\$morador_id\]")

    def test_fcm_waits_for_an_active_registration_and_passes_it_to_firebase(self):
        self.assertIn("navigator.serviceWorker.ready", PWA)
        self.assertIn("_swRegistration.active.state !== 'activated'", PWA)
        self.assertRegex(PWA, r"getToken\(\{\s*vapidKey:\s*VAPID_KEY,\s*serviceWorkerRegistration:\s*_swRegistration")
        self.assertNotIn("token.substring", PWA)

    def test_chatin_uses_absolute_routes_and_portal_token_option(self):
        self.assertIn("api: '/api/api_chatin.php'", CHATIN)
        self.assertIn("/frontend/js/chatin-widget.js", PORTAL)
        self.assertIn("ChatIn.iniciar({ plataforma: 'portal', token: () => Portal.token })", PORTAL)


if __name__ == "__main__":
    unittest.main(verbosity=2)
