# Dependências e Bibliotecas

O sistema utiliza bibliotecas externas via CDN ou incluídas localmente.

## 1. Frontend (JS/CSS)
- **FontAwesome 6.4.0**: Ícones (`<i class="fas fa-..."></i>`).
- **Chart.js**: Gráficos nos dashboards.
- **Html5Qrcode**: Leitura de QR Code via câmera no Console de Acesso.
- **SweetAlert2 / Toastify**: (Se aplicável) para popups e notificações visuais.

## 2. Backend (PHP)
- **TCPDF / FPDF**: Geração de relatórios em PDF.
- **PHPMailer**: (Substituído pelo `EmailProviderFactory` com Curl direto, mas pode haver legado).

## 3. Proibido
- jQuery (Código deve ser Vanilla JS `document.querySelector`).
- Bootstrap / Tailwind (O CSS é customizado).
