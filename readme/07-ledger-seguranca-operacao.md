# 07 - Ledger, segurança e operação

## Regras financeiras

1. `platform_fee = gross * 0.10`
2. `reseller_earning = gross * 0.90`
3. Pagamento confirmado gera comissão e crédito `pending`
4. Reconciliação move créditos para `available`

## Estados principais

1. Pedido: `pending`, `paid`, `failed`, `cancelled`, `expired`
2. Pagamento: `initiated`, `processing`, `confirmed`, `failed`, `timeout`
3. Comissão: `pending|paid|failed` + `settlement_status`
4. Wallet transaction: `pending|available|failed|reversed`

## Segurança implementada

1. Password hash (`bcrypt`)
2. CSRF token + validação de origem
3. Idempotência em endpoints críticos
4. Rate limiting por rota/identidade
5. Throttle de login por email+IP
6. Headers de segurança (CSP, frame deny, nosniff, etc.)
7. Sessão com fingerprint e rotação
8. Auditoria HTTP e auditoria de domínio

## Logs e auditoria

1. `api_logs`: request/response de integrações externas
2. `audit_logs`: ações de usuário/sistema
3. Arquivo de log app: `storage/logs/app.log`

## Operação recomendada

1. Rodar polling automático (cron) quando callback não for confiável
2. Rodar reconciliação periódica de comissões
3. Monitorar taxas de erro de callback e timeout
4. Revisar eventos `audit_logs` suspeitos

## Cron sugeridos (produção)

1. Polling pagamentos: a cada 1-5 minutos
2. Reconciliação ledger: a cada 5-15 minutos
3. Limpeza de temporários e rotação de logs: diário

