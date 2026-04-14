# 06 - Rotas e APIs principais

## Rotas web

1. `GET /health`
2. `GET /login`, `POST /login`
3. `GET /register`, `POST /register`
4. `GET /reseller/dashboard`
5. `GET /reseller/products`, `GET /reseller/products/create`
6. `GET /reseller/payment-pages`, `GET /reseller/payment-pages/create`
7. `GET /admin/dashboard`
8. `GET /admin/payments`, `GET /admin/transactions`, `GET /admin/disputes`
9. `GET /admin/wallets`, `GET /admin/payouts`, `GET /admin/api-settings`
10. `POST /admin/payouts/reconcile`, `POST /admin/payouts/{id}/settle`
11. `GET /reseller/contacts`, `GET /reseller/payments`, `GET /reseller/transactions`
12. `GET /reseller/disputes`, `GET /reseller/wallet`, `GET /reseller/payouts`, `GET /reseller/api-settings`
13. `GET /account/profile`, `POST /account/profile`
14. `GET /account/security`, `POST /account/security`
15. `GET /account/preferences`, `POST /account/preferences`
16. `GET /account/avatar`
17. `GET /p/{slug}` (página pública)
18. `POST /checkout/{slug}`
19. `GET /checkout/status/{order_no}`
20. `GET /d/{token}`

## APIs públicas

1. `GET /api/health`
2. `POST /api/auth/login`
3. `POST /api/auth/register`
4. `POST /api/payments/callback`
5. `POST /api/payments/poll` (protegido por `X-Poll-Token` quando configurado)
6. `GET /api/checkout/status/{order_no}`
7. `GET /api/downloads/{token}`
8. `GET /api/notifications`
9. `POST /api/notifications/read`
10. `GET /api/account/profile`, `POST /api/account/profile`
11. `GET /api/account/security`, `POST /api/account/security`
12. `GET /api/account/preferences`, `POST /api/account/preferences`

## APIs admin

1. `GET /api/admin/dashboard`
2. `GET /api/admin/users`
3. `GET /api/admin/reports/*`
4. `GET /api/admin/ledger/pending-commissions`
5. `POST /api/admin/ledger/commissions/{id}/settle`
6. `POST /api/admin/ledger/reconcile`

## APIs comissionista

1. `GET /api/reseller/dashboard`
2. `CRUD /api/reseller/products`
3. `CRUD /api/reseller/payment-pages`
4. `GET /api/reseller/wallet`
5. `GET /api/reseller/wallet/transactions`
6. `GET /api/reseller/reports/*`

## Exemplo de polling manual

```bash
curl -X POST "http://localhost/sistem_pay/api/payments/poll" \
  -H "X-Poll-Token: SEU_TOKEN" \
  -H "Accept: application/json"
```

## Exemplo de callback assinado (teste)

```bash
# Assinatura deve ser HMAC SHA-256 do body bruto com PAYMENT_CALLBACK_SECRET
curl -X POST "http://localhost/sistem_pay/api/payments/callback" \
  -H "Content-Type: application/json" \
  -H "X-Signature: <assinatura_hex>" \
  -d '{"reference":"PEDIDO-123","status":"confirmed"}'
```

