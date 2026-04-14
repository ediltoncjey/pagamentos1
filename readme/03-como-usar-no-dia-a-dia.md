# 03 - Como usar no dia a dia

## Fluxo do comissionista

1. Registrar conta em `/register` (ou admin cria)
2. Entrar em `/login`
3. Criar produto em `/reseller/products/create`
4. Criar pagina de pagamento em `/reseller/payment-pages/create`
5. Publicar link de venda `/p/{slug}`

## Fluxo do cliente final

1. Cliente abre `/p/{slug}`
2. Informa telefone M-Pesa
3. Sistema cria pedido pendente
4. Sistema chama API externa de pagamento
5. Cliente acompanha status (polling da pagina)
6. Apos confirmacao, recebe acesso por token em `/d/{token}`

## Fluxo financeiro interno (ledger)

Quando pagamento confirma:

1. Venda marcada como `paid`
2. Comissao criada em `commissions`
3. `10%` plataforma e `90%` comissionista
4. Credito entra em carteira como `pending`
5. Reconciliacao move `pending -> available`

## Fluxo do admin

1. Dashboard: `/admin/dashboard`
2. Gestao de usuarios: `/admin/users`
3. Pendencias ledger: `/api/admin/ledger/pending-commissions`
4. Reconciliacao em lote: `/api/admin/ledger/reconcile`

## Entrega digital

- Produto com link externo: redireciona com seguranca
- Produto com ficheiro: download protegido fora de `/public`
- Token de entrega com expiracao e limite de downloads

## UI da dashboard (nova)

- Sidebar fixa com submenu
- Topbar com busca, notificacoes e menu de usuario
- Dark mode e light mode com persistencia
- Icones Bootstrap em toda a interface
