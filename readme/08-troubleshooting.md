# 08 - Troubleshooting

## Problema: 500 no `/health`

Verifique:

1. `.env` existe
2. `vendor/` existe (`composer install`)
3. Permissoes em `storage/`
4. Erros em `storage/logs/app.log`

## Problema: nao autentica

1. Confirme usuario em `users`
2. Confirme role em `roles`
3. Verifique bloqueio por throttle (`SECURITY_LOGIN_*`)
4. Verifique se conta esta `active`

## Problema: callback retorna 401

1. `PAYMENT_CALLBACK_SECRET` deve bater com segredo do provedor
2. Cabecalho deve bater com `PAYMENT_CALLBACK_SIGNATURE_HEADER`
3. Assinatura deve ser HMAC SHA-256 do body bruto

## Problema: checkout cria pedido, mas nao paga

1. Confira `PAYMENT_API_KEY` e URL base
2. Inspecione `api_logs`
3. Verifique resposta do provedor em `payments.response_payload`
4. Acione endpoint de polling manual

## Problema: sem entrega apos pagamento

1. Verifique status do pedido em `orders`
2. Verifique `downloads` e token
3. Para `file_upload`, confirme arquivo em `storage/uploads/private`
4. Para `external_link`, confirme URL valida

## Problema: saldo nao aparece como disponivel

1. Isso e esperado no modelo ledger (`pending` primeiro)
2. Execute reconciliacao em `/api/admin/ledger/reconcile`
3. Verifique `wallet_transactions.status`

## Problema: icones nao aparecem na dashboard

1. Verifique se o CSS responde:
   - `http://localhost/sistem_pay/assets/vendor/bootstrap-icons/bootstrap-icons.min.css`
2. Verifique se a fonte responde:
   - `http://localhost/sistem_pay/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2`
3. Confirmar no HTML do layout:
   - `<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">`
   - `<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">`
4. Force refresh (`Ctrl+F5`) para limpar cache do browser
5. Se ainda falhar, teste com um icone simples:
   - `<i class="bi bi-alarm"></i>`
