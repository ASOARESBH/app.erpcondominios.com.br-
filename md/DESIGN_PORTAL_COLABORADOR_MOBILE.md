# Portal do Colaborador Mobile — Desenho Técnico

## Objetivo

O aplicativo ERP Condomínios manterá o **Portal do Morador** como experiência padrão e disponibilizará um Portal do Colaborador operacional, acessado mediante cinco toques consecutivos na logo da tela de login. Esse gesto é apenas um atalho discreto de interface; a proteção efetiva será fornecida por autenticação, autorização e isolamento obrigatório do tenant no servidor.

## Módulos entregues

| Módulo | Finalidade | Permissão mínima |
|---|---|---|
| Painel do colaborador | Exibe identidade do colaborador, condomínio e atalhos operacionais | `operador` |
| Chamados | Abre e acompanha chamados internos criados pelo colaborador | `operador` |
| Receber protocolo | Registra mercadoria recebida para um morador | `operador` |
| Leitor QR Code | Lê o código da mercadoria pela câmera, preenche o recebimento ou localiza uma entrega pendente | `operador` |
| Entregar protocolo | Localiza a mercadoria, valida o morador e registra a entrega | `operador` |
| Histórico de protocolos | Consulta os protocolos pendentes e entregues do tenant | `operador` |

## Autenticação e isolamento multi-tenant

O Portal do Colaborador usa o mesmo cadastro administrativo da plataforma web, isto é, a tabela `usuarios` e a senha armazenada com `password_verify`. O login recebe somente e-mail e senha; não recebe nem aceita uma URL de condomínio.

| Etapa | Regra de segurança |
|---|---|
| Identidade | Usuário precisa estar ativo em `usuarios` |
| Tenant | Vínculo ativo obrigatório em `usuario_tenant` com tenant ativo |
| Múltiplos tenants | O servidor não escolhe um tenant por padrão: devolve a lista permitida e exige seleção explícita do colaborador |
| Sessão móvel | Token aleatório de 64 caracteres, armazenado somente em hash no banco e transmitido como `Authorization: Bearer` |
| Expiração | O token expira após oito horas e é revogado no logout ou ao inativar o usuário |
| Consultas e escritas | Toda consulta recebe o `tenant_id` exclusivamente da sessão móvel validada; não aceita `tenant_id` do aplicativo |

## Protocolo de recebimento

O colaborador pode começar digitando os campos ou tocar no ícone de QR Code. O scanner usa a câmera e interpreta somente o código de mercadoria. Nenhuma informação do QR é usada como autorização; o servidor consulta o código dentro do tenant autenticado.

| Campo | Origem | Regra |
|---|---|---|
| Morador e unidade | Pesquisa do mesmo tenant | Obrigatórios; morador define a unidade correta |
| Descrição | Digitada ou preenchida por QR estruturado | Obrigatória |
| Código | Digitado ou obtido da leitura | Salvo como `codigo_nf` |
| Página | Digitada ou preenchida pelo QR | Opcional |
| Data e hora | Preenchida com a hora atual, editável | Obrigatória |
| Recebedor | Nome do usuário autenticado | Derivado no servidor, não confiado ao campo enviado pelo app |

O QR estruturado pode ser JSON com os campos `codigo`, `descricao`, `pagina`, `morador_id` e `unidade_id`; QR simples é interpretado como código. O fluxo manual permanece disponível para etiquetas que não possuam QR.

## Protocolo de entrega

Na entrega, o scanner consulta um protocolo pendente por ID ou `codigo_nf`, sempre dentro do tenant autenticado. O aplicativo mostra o morador e a unidade retornados pela API. A confirmação exige o nome do recebedor e a validação do CPF do morador responsável, que é conferida no servidor. O servidor grava a entrega, o recebedor e a trilha de auditoria, sem aceitar a identidade do destinatário baseada somente no conteúdo de um QR.

## Auditoria e falhas

As APIs registram eventos sem expor senha, token ou CPF completo nos logs. O aplicativo emite logs de diagnóstico com o prefixo `[Colaborador]`, contendo apenas a ação, o módulo e identificadores técnicos. Erros de QR inválido, sessão expirada, permissão insuficiente e protocolo de outro tenant possuem mensagens específicas.

## Compatibilidade

A implementação usa PHP 8 no ERP, Flutter no aplicativo e SQL compatível com MySQL/MariaDB 5.7. Nenhum fluxo do Portal do Morador, seus tokens ou suas rotas será modificado.
