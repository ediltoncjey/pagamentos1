# Como Comecar a Usar (Fluxo Operacional)

## Fluxo completo comissionista -> cliente -> ledger

1. Comissionista cria produto com:
- nome
- preco
- entrega por link externo ou ficheiro privado

2. Comissionista cria pagina de pagamento com slug.

3. Cliente entra em `/p/{slug}` e informa telefone M-Pesa.

4. Sistema cria ordem (`pending`) e chama API externa de pagamento.

5. Se pagamento confirmar:
- ordem vira `paid`
- comissao e criada (10% plataforma / 90% comissionista)
- carteira recebe credito `pending`
- token de entrega e emitido

6. Admin executa reconciliacao para mover `pending -> available`.

## URLs mais usadas

- Home: `http://localhost/sistem_pay/`
- Login: `http://localhost/sistem_pay/login`
- Registo: `http://localhost/sistem_pay/register`
- Admin dashboard: `http://localhost/sistem_pay/admin/dashboard`
- Reseller dashboard: `http://localhost/sistem_pay/reseller/dashboard`

## Areas principais da dashboard

1. `Dashboard`: visao financeira e operacao
2. `Contacts`: gestao de utilizadores (admin)
3. `Transactions`: pagamentos, transacoes e disputas
4. `Finance`: wallets e payout-ready
5. `Settings`: API settings, profile e seguranca

## Seguranca minima antes de producao

1. Alterar senha do admin default
2. Configurar `PAYMENT_CALLBACK_SECRET`
3. Habilitar HTTPS + sessao segura
4. Desativar bootstrap default admin (`AUTH_BOOTSTRAP_DEFAULT_ADMIN=false`)
