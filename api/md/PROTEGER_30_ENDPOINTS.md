# Proteção dos 30+ Endpoints Restantes

## Resumo

Este documento fornece instruções para proteger todos os endpoints restantes com autenticação de sessão.

## Endpoints a Proteger (34 endpoints identificados)

### Grupo 1: Administrativos (3 endpoints)
```
api_admin_fornecedores.php       → admin
api_ramos_atividade.php          → admin
api_config_periodo_leitura.php   → admin
```

### Grupo 2: Inventário e Leitura (4 endpoints)
```
api_inventario.php               → operador (GET), gerente (POST/PUT/DELETE)
api_leituras.php                 → operador
api_hidrometros.php              → operador
api_avaliacoes.php               → operador
```

### Grupo 3: Dispositivos (3 endpoints)
```
api_dispositivos.php             → operador (GET), admin (POST/PUT/DELETE)
api_dispositivos_console.php     → operador (GET), admin (POST/PUT/DELETE)
api_dispositivos_seguranca.php   → operador (GET), admin (POST/PUT/DELETE)
```

### Grupo 4: Portais (2 endpoints)
```
api_portal.php                   → operador
api_portal_morador.php           → operador
```

### Grupo 5: Logs (2 endpoints)
```
api_logs_sistema.php             → admin
api_logs_erro.php                → admin
```

### Grupo 6: Dados de Moradores (5 endpoints)
```
api_morador_dados.php            → operador
api_morador_notificacoes.php     → operador
api_morador_veiculos.php         → operador
api_morador_protocolos.php       → operador
api_morador_hidrometro.php       → operador
```

### Grupo 7: Financeiro (2 endpoints)
```
api_planos_contas.php            → gerente
api_marketplace.php              → operador
```

### Grupo 8: Gerenciamento (4 endpoints)
```
api_email_log.php                → admin
api_email_templates.php          → admin
api_fornecedores.php             → operador (GET), gerente (POST/PUT/DELETE)
api_produtos_servicos.php        → operador (GET), gerente (POST/PUT/DELETE)
```

### Grupo 9: Outros (9 endpoints)
```
api_console_acesso.php           → operador
api_dashboard_acessos.php        → operador
api_dashboard_agua.php           → operador
api_rfid.php                     → operador (GET), admin (POST/PUT/DELETE)
api_unidades.php                 → operador (GET), admin (POST/PUT/DELETE)
api_veiculos.php                 → operador (GET), gerente (POST/PUT/DELETE)
api_protocolos.php               → operador
api_checklist_alertas.php        → operador
api_checklist_itens.php          → operador
```

## Template de Proteção

Use este template para proteger cada endpoint:

```php
<?php
/**
 * API: [NOME_DO_ENDPOINT]
 * Descrição: [DESCRIÇÃO]
 * Permissão Mínima: [PERMISSÃO]
 */

// Configurações iniciais
require_once 'config.php';
require_once 'auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://erp.asserradaliberdade.ong.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// VERIFICAÇÃO CRÍTICA DE AUTENTICAÇÃO
verificarAutenticacao(true, 'operador'); // Ajustar permissão conforme necessário

// Para operações de escrita, verificar permissão apropriada
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    verificarPermissao('admin'); // Ajustar conforme necessário
}

// Resto do código original...
?>
```

## Instruções Passo-a-Passo

Para cada endpoint:

1. **Abrir arquivo** em editor de texto
2. **Localizar a primeira linha** `<?php`
3. **Adicionar após a primeira linha:**
   ```php
   require_once 'config.php';
   require_once 'auth_helper.php';
   ```
4. **Adicionar headers CORS** (se não existirem):
   ```php
   header('Content-Type: application/json; charset=utf-8');
   header('Access-Control-Allow-Origin: http://erp.asserradaliberdade.ong.br');
   header('Access-Control-Allow-Credentials: true');
   header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
   header('Access-Control-Allow-Headers: Content-Type, Authorization');
   
   if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
       http_response_code(200);
       exit;
   }
   ```
5. **Adicionar verificação de autenticação:**
   ```php
   verificarAutenticacao(true, 'operador');
   ```
6. **Para operações de escrita:**
   ```php
   if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
       verificarPermissao('admin');
   }
   ```
7. **Salvar arquivo**
8. **Testar com Postman ou curl**

## Hierarquia de Permissões

| Nível | Permissão | Acesso |
|-------|-----------|--------|
| 1 | visualizador | Apenas leitura |
| 2 | operador | Leitura e escrita básica |
| 3 | gerente | Leitura, escrita e aprovações |
| 4 | admin | Acesso total |

## Checklist de Implementação

- [ ] Backup de todos os endpoints criado
- [ ] Rate limiting implementado em api_login.php
- [ ] Todos os 34 endpoints protegidos com auth_helper.php
- [ ] Headers CORS corrigidos em todos os endpoints
- [ ] Testes de funcionamento realizados
- [ ] Documentação atualizada
- [ ] Deploy em produção

## Próximas Etapas

1. Implementar JWT (Passo #3)
2. Adicionar 2FA (autenticação de dois fatores)
3. Implementar auditoria completa
4. Realizar penetration testing

---

**Data:** 24 de Janeiro de 2026  
**Versão:** 1.0
