# 02 - Instalacao no XAMPP (Passo a passo)

## Pre-requisitos

1. XAMPP com PHP 8.1+
2. MySQL 8.0+
3. Composer instalado
4. Apache com `mod_rewrite` ativo

## 1) Clonar/copiar projeto

Coloque o projeto em:

- `C:\xampp\htdocs\SISTEM_PAY`

## 2) Dependencias PHP

No terminal dentro do projeto:

```bash
composer install
composer dump-autoload
```

## 3) Configurar ambiente

1. Copiar `.env.example` para `.env`
2. Ajustar no minimo:

- `APP_URL=http://localhost/sistem_pay`
- `DB_DATABASE=sistem_pay`
- `DB_USERNAME=root`
- `DB_PASSWORD=`
- `PAYMENT_API_KEY=...`

## 4) Criar banco e tabelas

Execute no MySQL (ou phpMyAdmin):

```sql
SOURCE C:/xampp/htdocs/SISTEM_PAY/database/schema.sql;
SOURCE C:/xampp/htdocs/SISTEM_PAY/database/seeds.sql;
```

## 5) Permissoes de escrita

Garanta escrita para o usuario do Apache em:

- `storage/logs`
- `storage/tmp`
- `storage/uploads/private`

## 6) Apache / URL

- Acesse via: `http://localhost/sistem_pay/`
- O acesso padrao ja redireciona internamente para o front controller.

## 7) Teste rapido de saude

- Browser: `http://localhost/sistem_pay/health`
- API: `http://localhost/sistem_pay/api/health`

Se retornar status `ok`, bootstrap + rotas + DB estao prontos.

## 8) Login inicial

Com `seeds.sql`:

- Email: `admin@sistempay.local`
- Password: `ChangeMe@123`

Altere a senha imediatamente.

## 9) Verificacao de icones (Bootstrap Icons)

A UI usa Bootstrap Icons em modo duplo (CDN + fallback local).

Arquivos locais esperados:

- `public/assets/vendor/bootstrap-icons/bootstrap-icons.min.css`
- `public/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2`
- `public/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff`

Teste rapido no browser:

- `http://localhost/sistem_pay/assets/vendor/bootstrap-icons/bootstrap-icons.min.css`

Se os icones nao aparecerem, force refresh com `Ctrl+F5`.
