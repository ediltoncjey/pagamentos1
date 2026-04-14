# Guia de Acesso Inicial

Este guia explica como abrir o sistema, entrar como admin/comissionista e comecar a operar.

## 1) URL padrao do sistema

Use este link como entrada principal:

- `http://localhost/sistem_pay/`

Nao e necessario adicionar `/public`.

## 2) Login Admin (default)

Credenciais default atuais:

- Email: `admin@sistempay.local`
- Password: `ChangeMe@123`

Estas credenciais podem vir de 2 fontes:

1. `database/seeds.sql` (importado no banco)
2. bootstrap automatico (`AUTH_BOOTSTRAP_DEFAULT_ADMIN=true`) quando nao existe admin

Apos o primeiro login, altere a password imediatamente.

## 3) Login Comissionista

Nao existe comissionista default no seed.

Formas de criar comissionista:

1. Auto-registo em `http://localhost/sistem_pay/register`
2. Admin cria no painel/API de utilizadores

Depois do registo:

- login em `http://localhost/sistem_pay/login`
- dashboard em `http://localhost/sistem_pay/reseller/dashboard`

## 4) Primeiros passos (rapido)

1. Entrar como comissionista
2. Criar produto: `/reseller/products/create`
3. Criar pagina de pagamento: `/reseller/payment-pages/create`
4. Divulgar link publico: `/p/{slug}`
5. Testar checkout e entrega digital

## 5) Primeiros passos (admin)

1. Entrar em `/login` com admin default
2. Abrir dashboard: `/admin/dashboard`
3. Ver relatorios e top comissionistas
4. Reconciliar comissoes pendentes no ledger

## 6) Interface da dashboard

- Sidebar vertical fixa com submenu
- Topbar com notificacoes, perfil e toggle de tema
- Tema dark/light com persistencia
- Icones Bootstrap em toda a interface (`<i class="bi bi-..."></i>`)

## 7) Checklist para comecar sem erro

1. `.env` configurado
2. `schema.sql` e `seeds.sql` importados
3. `PAYMENT_API_KEY` definida
4. escrita em `storage/` funcionando
5. `http://localhost/sistem_pay/health` respondendo `ok`
