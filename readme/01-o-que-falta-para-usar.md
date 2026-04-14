# 01 - O que falta para usar

## Para usar localmente (XAMPP)

Quase nada estrutural falta. Para subir local você só precisa:

1. Criar `.env` a partir de `.env.example`
2. Importar `database/schema.sql` e `database/seeds.sql`
3. Garantir permissões de escrita em `storage/`
4. Definir credenciais da API de pagamento no `.env`

## O que ainda falta para produção real

O sistema já está funcional, mas para produção comercial você deve fechar estes itens operacionais:

1. `PAYMENT_API_KEY` real do provedor
2. `PAYMENT_CALLBACK_SECRET` + cabeçalho de assinatura com o provedor
3. HTTPS real e endurecimento de sessão
4. Rotina agendada (cron) para polling e reconciliação
5. Monitoramento/alertas de falhas (API, callbacks, reconciliação)
6. Backup e política de restauração do MySQL
7. Rotação de segredo e procedimento de incidentes
8. Desativar bootstrap de admin padrão após setup inicial

## Limitações conhecidas (não bloqueantes para MVP)

1. Não há módulo de payout bancário/M-Pesa final (apenas ledger preparado)
2. Não há suíte de testes automatizados no repositório
3. Não há fila assíncrona dedicada (funciona síncrono + polling)

## Go-live checklist

1. Configurar `.env` de produção com segredos reais
2. Definir `AUTH_BOOTSTRAP_DEFAULT_ADMIN=false`
3. Alterar senha do admin padrão
4. Habilitar `SECURITY_SESSION_SECURE=true`
5. Habilitar `SECURITY_HEADERS_HSTS_ENABLED=true` (somente com HTTPS)
6. Validar callback assinado de ponta a ponta
7. Validar reconciliação e saldos da carteira

