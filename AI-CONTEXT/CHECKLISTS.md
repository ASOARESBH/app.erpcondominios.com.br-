# Checklists Obrigatórios

Antes de finalizar qualquer entrega, a IA deve validar este checklist.

## 1. Checklist de Frontend
- [ ] A página HTML não possui tags `<html>` ou `<body>`?
- [ ] O arquivo JS exporta as funções `init()` e `destroy()`?
- [ ] O CSS está isolado na pasta `assets/css/pages/`?
- [ ] Os botões usam as classes do Design System (`.btn-primary-modern`, etc)?
- [ ] As chamadas `fetch` incluem `credentials: 'include'`?

## 2. Checklist de Backend (PHP)
- [ ] O arquivo inclui `auth_helper.php` e valida a sessão?
- [ ] As queries SQL usam Prepared Statements (`bind_param`)?
- [ ] O output é estritamente JSON? (Sem `echo` perdidos)
- [ ] Erros estão sendo capturados com `try/catch` e logados com `error_log()`?

## 3. Checklist de Deploy
- [ ] Todos os arquivos modificados foram adicionados ao pacote ZIP?
- [ ] Scripts SQL de migração foram incluídos (se houver alteração de banco)?
- [ ] A documentação do AI-CONTEXT foi atualizada?
