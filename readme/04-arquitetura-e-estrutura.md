# 04 - Arquitetura e estrutura

## Arquitetura aplicada

Camadas:

1. `Controllers`: HTTP e resposta
2. `Services`: regras de negócio
3. `Repositories`: acesso a dados
4. `Models`: entidades
5. `Middlewares`: segurança transversal
6. `Utils`: suporte (DI, request, response, logger, money, retry...)

## Estrutura de diretórios

```text
/app
  /Controllers
  /Services
  /Repositories
  /Models
  /Middlewares
  /Utils
/config
/database
/public
/resources/views
/routes
/storage
```

## Componentes críticos

1. `PaymentService`: integração com API externa + retry + logs + polling/callback
2. `CheckoutService`: criação de pedidos pendentes
3. `LedgerService`: comissão + wallet + reconciliação
4. `DownloadService`: emissão/validação/consumo de token de entrega

## Banco de dados

Tabelas principais:

- `users`, `roles`
- `products`, `payment_pages`
- `orders`, `payments`
- `commissions`, `wallets`, `wallet_transactions`
- `downloads`
- `api_logs`, `audit_logs`

## Princípios de design

1. Dinheiro não entra no sistema (ledger interno)
2. Comissão calculada internamente
3. Idempotência para evitar duplicidade de cobrança/processamento
4. Auditoria de ações e chamadas de API

