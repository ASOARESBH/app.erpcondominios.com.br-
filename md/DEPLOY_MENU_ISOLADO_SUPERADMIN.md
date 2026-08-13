# Menu Isolado do Super-Admin e Navegação por Unidade

O sistema passa a trabalhar com dois contextos de navegação exclusivos para a conta `super_admin`. O contexto global apresenta somente o **Painel Super-Admin**, enquanto o contexto de unidade apresenta os módulos operacionais do condomínio selecionado.

| Contexto | Menu exibido | Como acessar |
|---|---|---|
| Super-Admin global | Painel Super-Admin, sair e suporte | Login da conta `super_admin` ou retorno pelo botão **Voltar ao Painel**. |
| Unidade selecionada | Módulos operacionais do condomínio e **Voltar ao Painel** | Ação **Entrar** no cartão do condomínio. |

## Regras implementadas

O `MenuController` agora mantém um modo explícito de navegação global do Super-Admin. Esse modo não reutiliza nem oculta parcialmente o menu de um tenant: ele renderiza uma coleção própria de itens administrativos, preparada para receber novos menus via `addSuperAdminItem()`.

A ação **Entrar** grava o contexto antes de trocar a sessão, respeita o destino devolvido pela API e abre o dashboard do condomínio. Caso a troca falhe, a flag local é revertida. O retorno ao painel remove o contexto operacional, recompõe o menu global e volta para `?page=superadmin`.

## Implantação

Envie o conteúdo do pacote ZIP à raiz da aplicação no HostGator, preservando os diretórios. Não há migration de banco. Depois do upload, use `Ctrl+F5`, entre novamente com a conta Super-Admin e valide os dois fluxos:

1. No painel global, o menu lateral não deve exibir Moradores, Financeiro, Unidades ou outros módulos de condomínio.
2. Ao clicar em **Entrar** em um condomínio, o dashboard operacional deve abrir com os módulos da unidade e o link **Voltar ao Painel**.
