# Roadmap

Visão de futuro e próximos passos para o ERP.

## Curto Prazo
- Refatoração completa de todas as APIs procedurais (`switch/case`) para a classe `ApiBase` (Orientação a Objetos).
- Implementação de Constraints (Foreign Keys) nativas no MySQL em todas as tabelas.

## Médio Prazo
- Migração do sistema de permissões para RBAC (Role-Based Access Control) com permissões granulares por módulo.
- Melhorias de performance no Dashboard principal (cache de queries pesadas).

## Longo Prazo
- Refatoração do CSS global para utilizar CSS Modules ou Web Components com Shadow DOM, isolando estilos.
