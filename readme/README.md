# SISTEM_PAY - Documentacao Completa

Este diretorio contem o manual do sistema: instalacao, operacao, arquitetura, seguranca e checklist de producao.

## Ultima atualizacao

- Data: 13/04/2026
- Contexto: dashboard refatorada, design system unificado, tema dark/light e padronizacao global de icones Bootstrap.
- Contexto adicional: navegacao admin/reseller corrigida, modulos operacionais convertidos de JSON para UI e novas paginas de conta (Profile/Security/Preferences) com sistema de notificacoes.

## Indice

1. [00 - Acesso inicial (admin/comissionista)](./acesso-inicial/README.md)
2. [01 - O que falta para usar](./01-o-que-falta-para-usar.md)
3. [02 - Instalacao no XAMPP](./02-instalacao-xampp.md)
4. [03 - Como usar no dia a dia](./03-como-usar-no-dia-a-dia.md)
5. [04 - Arquitetura e estrutura](./04-arquitetura-e-estrutura.md)
6. [05 - Configuracao (.env) e integracoes](./05-configuracao-env-e-integracoes.md)
7. [06 - Rotas e APIs](./06-rotas-e-api.md)
8. [07 - Ledger, seguranca e operacao](./07-ledger-seguranca-operacao.md)
9. [08 - Troubleshooting](./08-troubleshooting.md)

## Leitura recomendada

- Primeiro uso local: documento 02 -> 03.
- Entrada em producao: documento 01 -> 05 -> 07.
- Integracoes API: documento 05 -> 06.

## Atalhos rapidos

- URL principal: `http://localhost/sistem_pay/`
- Login: `http://localhost/sistem_pay/login`
- Health: `http://localhost/sistem_pay/health`
- Dashboard admin: `http://localhost/sistem_pay/admin/dashboard`
- Dashboard comissionista: `http://localhost/sistem_pay/reseller/dashboard`

## Estado atual do sistema

As fases 1 a 9 foram implementadas no codigo atual, incluindo:

- Arquitetura limpa em camadas (`Controller -> Service -> Repository -> Model`)
- Checkout publico com API de pagamento externa
- Ledger interno com comissoes e carteira
- Entrega de produto com token seguro
- Dashboards admin/comissionista (novo layout premium SaaS)
- Modulos web completos para Payments, Transactions, Disputes, Wallets, Payouts e Contacts
- Conta de utilizador completa (Profile, Security, Preferences + avatar)
- Sino de notificacoes com contador, dropdown e marcacao de leitura
- Seguranca fase 9 (CSRF, idempotencia, rate limit, audit trail, headers, throttle de login)

## Icones da interface (Bootstrap Icons)

O sistema usa Bootstrap Icons em toda a interface com duas fontes de carregamento:

1. CDN:
   - `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css`
2. Fallback local:
   - `/assets/vendor/bootstrap-icons/bootstrap-icons.min.css`

Exemplo:

```html
<i class="bi bi-alarm"></i>
```
