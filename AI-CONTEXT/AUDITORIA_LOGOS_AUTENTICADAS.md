--- Relatórios com referência a logo ---

api/api_relatorio_abastecimento_pdf.php
30:$tenant_id = exigirTenantId();
38:$_tenant_id_rel = $_SESSION["tenant_id"] ?? 1;
39:$sql_empresa = "SELECT razao_social, nome_fantasia, cnpj, logo_url FROM tenants WHERE id = $_tenant_id_rel LIMIT 1";
51:if (!empty($empresa['logo_url'])) {
52:    // logo_url pode ser "uploads/logo/arquivo.png" — converter para URL absoluta
55:    $logo_url    = $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/');
59:    $logo_url = $protocolo . '://' . $host . '/assets/img/logos/logo_padrao.png';
399:        <img src="<?= htmlspecialchars($logo_url) ?>"

api/api_relatorio_acessos_pdf.php
30:$tenant_id = exigirTenantId();
37:$_tenant_id_rel = $_SESSION['tenant_id'] ?? 1;
40:$_stmt_t = $conn->prepare("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM tenants WHERE id = ? LIMIT 1");
42:    $_stmt_t->bind_param('i', $_tenant_id_rel);
48:if (empty($empresa['logo_url'])) {
49:    $_stmt_e = $conn->prepare("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM empresa WHERE tenant_id = ? LIMIT 1");
51:        $_stmt_e->bind_param('i', $_tenant_id_rel);
64:$logo_url  = !empty($empresa['logo_url'])
65:           ? $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
282:        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo" onerror="this.style.display='none'">

api/api_relatorio_acessos_veiculos_pdf.php
25:$tenant_id = exigirTenantId();
32:$_tenant_id_rel = $_SESSION['tenant_id'] ?? 1;
35:$_stmt_t = $conn->prepare("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM tenants WHERE id = ? LIMIT 1");
37:    $_stmt_t->bind_param('i', $_tenant_id_rel);
43:if (empty($empresa['logo_url'])) {
44:    $_stmt_e = $conn->prepare("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM empresa WHERE tenant_id = ? LIMIT 1");
46:        $_stmt_e->bind_param('i', $_tenant_id_rel);
59:$logo_url  = !empty($empresa['logo_url'])
60:           ? $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
306:        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo" onerror="this.style.display='none'">

api/api_relatorio_hidrometro_pdf.php
28:$tenant_id = exigirTenantId();
34:$_tenant_id_rel = $_SESSION["tenant_id"] ?? 1;
35:$_stmt_emp = $conn->prepare("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM tenants WHERE id = ? LIMIT 1");
36:$_stmt_emp->bind_param("i", $_tenant_id_rel);
50:$logo_url  = !empty($empresa['logo_url'])
51:           ? $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
822:        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo" onerror="this.style.display='none'">

api/api_relatorio_inventario_pdf.php
24:$tenant_id = exigirTenantId();
37:$_tenant_id_rel = $_SESSION["tenant_id"] ?? 1;
38:$_stmt_emp = $conn->prepare("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM tenants WHERE id = ? LIMIT 1");
39:$_stmt_emp->bind_param("i", $_tenant_id_rel);
53:$logo_url  = !empty($empresa['logo_url'])
54:    ? $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
284:        <?php if ($logo_url): ?>
285:        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo"

api/api_relatorio_licitacao_pdf.php
20:$tenant_id = exigirTenantId();
35:$_tenant_id_rel = $_SESSION["tenant_id"] ?? 1;
36:$_stmt_emp = $conn->prepare("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM tenants WHERE id = ? LIMIT 1");
37:$_stmt_emp->bind_param("i", $_tenant_id_rel);
58:$logo_url  = !empty($empresa['logo_url'])
59:    ? $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
282:        <?php if ($logo_url): ?>
283:        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo"

api/api_relatorio_moradores_pdf.php
27:$tenant_id = exigirTenantId();
34:$_tenant_id_rel = $_SESSION['tenant_id'] ?? 1;
35:$res_emp = $conn->prepare("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM empresa WHERE tenant_id = ? LIMIT 1");
36:$res_emp->bind_param('i', $_tenant_id_rel);
49:if (!empty($empresa['logo_url'])) {
50:    $logo_url = $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/');
52:    $logo_url = $protocolo . '://' . $host . '/assets/img/logos/logo_padrao.png';
335:        <?php if ($logo_url): ?>
336:        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo"

api/api_relatorio_veiculos_pdf.php
22:$tenant_id = exigirTenantId();
29:$_tenant_id_rel = $_SESSION['tenant_id'] ?? 1;
30:$res_emp = $conn->prepare("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM empresa WHERE tenant_id = ? LIMIT 1");
31:$res_emp->bind_param('i', $_tenant_id_rel);
44:if (!empty($empresa['logo_url'])) {
45:    $logo_url = $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/');
47:    $logo_url = $protocolo . '://' . $host . '/assets/img/logos/logo_padrao.png';
294:        <?php if ($logo_url): ?>
295:        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo" onerror="this.style.display='none'">

api/api_relatorio_visitantes_pdf.php
27:$tenant_id = exigirTenantId();
34:$_tenant_id_rel = $_SESSION['tenant_id'] ?? 1;
35:$res_emp = $conn->prepare("SELECT razao_social, nome_fantasia, cnpj, logo_url FROM empresa WHERE tenant_id = ? LIMIT 1");
36:$res_emp->bind_param('i', $_tenant_id_rel);
49:if (!empty($empresa['logo_url'])) {
50:    $logo_url = $protocolo . '://' . $host . '/' . ltrim($empresa['logo_url'], '/');
52:    $logo_url = $protocolo . '://' . $host . '/assets/img/logos/logo_padrao.png';
298:        <?php if ($logo_url): ?>
299:        <img src="<?= esc($logo_url) ?>" alt="Logo" class="header-logo" onerror="this.style.display='none'">

api/api_rh_relatorio_pdf.php
16:$tenant_id = exigirTenantId(); }
403:$_tenant_id_rel = $_SESSION["tenant_id"] ?? 1;
404:$_stmt_emp = $conn->prepare("SELECT e.razao_social, e.nome_fantasia, e.cnpj, e.telefone, e.email_principal, e.endereco_rua, e.endereco_cidade, e.endereco_estado, t.logo_url FROM empresa e JOIN tenants t ON t.id = e.tenant_id WHERE e.tenant_id = ? LIMIT 1");
405:$_stmt_emp->bind_param("i", $_tenant_id_rel);

api/api_demonstrativo_agua.php
26:$tenant_id = exigirTenantId();
46:$_tenant_id_rel = $_SESSION['tenant_id'] ?? 1;
52:    "SELECT razao_social, nome_fantasia, cnpj, telefone, logo_url,
54:     FROM tenants WHERE id = ? LIMIT 1"
57:    $_stmt_t->bind_param('i', $_tenant_id_rel);
65:        "SELECT razao_social, nome_fantasia, cnpj, telefone, logo_url,
68:         FROM empresa WHERE tenant_id = ? LIMIT 1"
71:        $_stmt_e->bind_param('i', $_tenant_id_rel);
86:$logo_url  = !empty($empresa['logo_url'])
87:    ? $proto . '://' . $host . '/' . ltrim($empresa['logo_url'], '/')
712:                <img src="<?= esc($logo_url) ?>"
967:                    <img src="<?= esc($logo_url) ?>" alt="Logo" onerror="this.style.display='none'">

--- Página e carregadores autenticados ---
frontend/js/pages/empresa.js:193:            localStorage.setItem('tenant_logo_url', logoUrl);
frontend/js/sessao_manager.js:197:            const sidebarLogo = document.getElementById('dynamicSidebarLogo');
frontend/js/user-profile-sidebar.js:128:                         id="dynamicSidebarLogo"
frontend/js/user-profile-sidebar.js:188:        const IMG_ID = 'dynamicSidebarLogo';
frontend/js/user-profile-sidebar.js:189:        const CACHE_KEY = 'tenant_logo_url';
frontend/js/user-profile-sidebar.js:198:        fetch(apiBase + 'api/get_logo_empresa.php', {
frontend/js/visual-identity.js:43:            const dynamicSidebarLogo = document.getElementById('dynamicSidebarLogo');
frontend/js/visual-identity.js:44:            if (dynamicSidebarLogo && logoUrl) {
frontend/js/visual-identity.js:45:                dynamicSidebarLogo.src = logoUrl;
