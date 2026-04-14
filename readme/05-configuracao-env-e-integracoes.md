# 05 - Configuração (.env) e integrações

## Variáveis obrigatórias (mínimo)

1. Aplicação

- `APP_URL`
- `APP_TIMEZONE`

2. Banco

- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

3. Pagamentos

- `PAYMENT_API_BASE_URL`
- `PAYMENT_API_KEY`
- `PAYMENT_PROVIDER`

## Callback e polling

- `PAYMENT_ENABLE_CALLBACK=true|false`
- `PAYMENT_ENABLE_POLLING=true|false`
- `PAYMENT_POLL_SECRET` (protege endpoint de polling manual)

## Assinatura do callback (fortemente recomendado)

- `PAYMENT_CALLBACK_SIGNATURE_HEADER` (ex: `X-Signature`)
- `PAYMENT_CALLBACK_SECRET`

Se `PAYMENT_CALLBACK_SECRET` estiver definido, o sistema valida HMAC SHA-256 do corpo bruto da requisição.

## Segurança

- `SECURITY_SESSION_SECURE`
- `SECURITY_SESSION_ENFORCE_FINGERPRINT`
- `SECURITY_SESSION_ROTATE_INTERVAL_SECONDS`
- `SECURITY_CSRF_ENFORCE_ORIGIN`
- `SECURITY_RATE_LIMIT_*`
- `SECURITY_LOGIN_*`

## Uploads e limites

- `PRODUCT_IMAGE_MAX_BYTES`
- `PRODUCT_DIGITAL_MAX_BYTES`
- `PRODUCT_IMAGE_EXTENSIONS`
- `PRODUCT_DIGITAL_EXTENSIONS`

## Admin padrão

- `AUTH_BOOTSTRAP_DEFAULT_ADMIN=true|false`
- `AUTH_DEFAULT_ADMIN_EMAIL`
- `AUTH_DEFAULT_ADMIN_PASSWORD`

Produção: desative bootstrap após criação do primeiro admin real.

