# SISTEM_PAY

Plataforma PHP + MySQL para venda de produtos digitais com comissionistas, checkout externo (M-Pesa/API), ledger interno e dashboards Admin/Reseller.

## URL local padrao

- `http://localhost/sistem_pay/`

## Acesso inicial padrao

- Admin email: `admin@sistempay.local`
- Admin password: `ChangeMe@123`

Altere a password no primeiro login.

## Instalacao rapida (XAMPP)

1. Copiar `.env.example` para `.env`
2. Ajustar `.env` (DB + API de pagamento)
3. Importar `database/schema.sql` e `database/seeds.sql`
4. Executar:
   - `composer install`
   - `composer dump-autoload`
5. Garantir escrita em:
   - `storage/logs`
   - `storage/tmp`
   - `storage/uploads/private`

## Testes rapidos

- Health web: `http://localhost/sistem_pay/health`
- Health API: `http://localhost/sistem_pay/api/health`

## Dashboard e icones

- Dashboard refatorada com sidebar/topbar responsiva, dark/light mode e design system unificado.
- Icones Bootstrap carregados por:
  - CDN: `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css`
  - Fallback local: `/assets/vendor/bootstrap-icons/bootstrap-icons.min.css`

Exemplo de uso:

```html
<i class="bi bi-alarm"></i>
```

## Modulos agora funcionais (Admin e Reseller)

Sem JSON bruto no menu principal:

- `Payments`
- `Transactions`
- `Disputes`
- `Wallets`
- `Payouts`
- `Contacts` (reseller)
- `API Settings`
- `Profile`
- `Security`
- `Preferences`

Notificacoes reais no sino da topbar:

- nova venda
- erro de pagamento
- alerta de seguranca
- alerta do sistema

Com suporte para:

- contador de nao lidas
- marcar como lida
- marcar todas como lidas

## Documentacao completa

Consulte:

- [readme/README.md](readme/README.md)
- [readme/acesso-inicial/README.md](readme/acesso-inicial/README.md)
