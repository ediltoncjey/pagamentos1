<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Repositories\PaymentGatewayRepository;
use App\Utils\Env;
use App\Utils\Logger;

final class GatewayCatalogService
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $cacheByCode = null;

    public function __construct(
        private readonly PaymentGatewayRepository $gateways,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $this->syncDefaults();
        return $this->gateways->listAll();
    }

    /**
     * @param array<string, mixed> $page
     * @return array<int, array<string, mixed>>
     */
    public function listCheckoutMethods(array $page): array
    {
        $this->syncDefaults();
        $catalog = $this->catalogByCode();
        $definitions = $this->definitions();
        $items = [];

        foreach ($definitions as $code => $definition) {
            $db = $catalog[$code] ?? [];
            $pageAllowed = $this->isPageGatewayAllowed($page, $code);
            $envEnabled = (bool) ($definition['enabled'] ?? false);
            $envConfigured = (bool) ($definition['configured'] ?? false);
            $dbEnabled = !isset($db['is_enabled']) || (int) $db['is_enabled'] === 1;
            $dbConfigured = !isset($db['is_configured']) || (int) $db['is_configured'] === 1;
            $available = $pageAllowed && $envEnabled && $dbEnabled && $envConfigured && $dbConfigured;

            $unavailableReason = null;
            if (!$pageAllowed) {
                $unavailableReason = 'Metodo desativado nesta pagina.';
            } elseif (!$envEnabled || !$dbEnabled) {
                $unavailableReason = 'Metodo desativado na configuracao.';
            } elseif (!$envConfigured || !$dbConfigured) {
                $unavailableReason = 'Integracao em preparacao.';
            }

            $items[] = [
                'code' => $code,
                'display_name' => (string) ($db['display_name'] ?? $definition['display_name']),
                'icon_class' => (string) ($db['icon_class'] ?? $definition['icon_class']),
                'provider' => (string) ($definition['provider'] ?? $code),
                'page_allowed' => $pageAllowed,
                'is_enabled' => $envEnabled && $dbEnabled,
                'is_configured' => $envConfigured && $dbConfigured,
                'is_available' => $available,
                'is_live' => (int) ($db['is_live'] ?? 0) === 1,
                'unavailable_reason' => $unavailableReason,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $page
     */
    public function resolveGatewayForCheckout(array $page, ?string $requestedCode): string
    {
        $requestedCode = strtolower(trim((string) $requestedCode));
        $methods = $this->listCheckoutMethods($page);

        if ($requestedCode !== '') {
            foreach ($methods as $method) {
                if ((string) $method['code'] !== $requestedCode) {
                    continue;
                }

                if (($method['is_available'] ?? false) === true) {
                    return $requestedCode;
                }

                throw new \RuntimeException((string) ($method['unavailable_reason'] ?? 'Metodo de pagamento indisponivel.'));
            }

            throw new \RuntimeException('Metodo de pagamento invalido para este checkout.');
        }

        foreach ($methods as $method) {
            if (($method['is_available'] ?? false) === true) {
                return (string) $method['code'];
            }
        }

        throw new \RuntimeException('Nenhum metodo de pagamento disponivel nesta pagina.');
    }

    /**
     * @param array<string, mixed> $page
     */
    public function isPageGatewayAllowed(array $page, string $gatewayCode): bool
    {
        $gatewayCode = strtolower(trim($gatewayCode));
        return match ($gatewayCode) {
            'mpesa' => (int) ($page['allow_mpesa'] ?? 1) === 1,
            'emola' => (int) ($page['allow_emola'] ?? 0) === 1,
            'visa' => (int) ($page['allow_visa'] ?? 0) === 1,
            'paypal' => (int) ($page['allow_paypal'] ?? 0) === 1,
            default => false,
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function catalogByCode(): array
    {
        if ($this->cacheByCode !== null) {
            return $this->cacheByCode;
        }

        $map = [];
        foreach ($this->gateways->listAll() as $row) {
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            if ($code === '') {
                continue;
            }

            $map[$code] = $row;
        }

        $this->cacheByCode = $map;
        return $map;
    }

    private function syncDefaults(): void
    {
        try {
            $definitions = $this->definitions();
            foreach ($definitions as $code => $definition) {
                $existing = $this->gateways->findByCode($code);
                if ($existing !== null) {
                    continue;
                }

                $this->gateways->upsert([
                    'code' => $code,
                    'display_name' => $definition['display_name'],
                    'description' => $definition['description'],
                    'icon_class' => $definition['icon_class'],
                    'is_enabled' => $definition['enabled'] ? 1 : 0,
                    'is_configured' => $definition['configured'] ? 1 : 0,
                    'is_live' => $code === 'mpesa' ? 1 : 0,
                    'sort_order' => $definition['sort_order'],
                    'settings_json' => json_encode([
                        'provider' => $definition['provider'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Gateway catalog sync failed', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            'mpesa' => [
                'display_name' => (string) Env::get('PAYMENT_GATEWAY_MPESA_NAME', 'M-Pesa'),
                'description' => 'Pagamento M-Pesa',
                'icon_class' => 'bi-phone',
                'enabled' => filter_var(Env::get('PAYMENT_GATEWAY_MPESA_ENABLED', true), FILTER_VALIDATE_BOOL),
                'configured' => filter_var(Env::get('PAYMENT_GATEWAY_MPESA_CONFIGURED', true), FILTER_VALIDATE_BOOL),
                'provider' => (string) Env::get('PAYMENT_GATEWAY_MPESA_PROVIDER', 'rozvitech'),
                'sort_order' => 1,
            ],
            'emola' => [
                'display_name' => (string) Env::get('PAYMENT_GATEWAY_EMOLA_NAME', 'e-Mola'),
                'description' => 'Pagamento e-Mola',
                'icon_class' => 'bi-wallet2',
                'enabled' => filter_var(Env::get('PAYMENT_GATEWAY_EMOLA_ENABLED', false), FILTER_VALIDATE_BOOL),
                'configured' => filter_var(Env::get('PAYMENT_GATEWAY_EMOLA_CONFIGURED', false), FILTER_VALIDATE_BOOL),
                'provider' => (string) Env::get('PAYMENT_GATEWAY_EMOLA_PROVIDER', 'emola'),
                'sort_order' => 2,
            ],
            'visa' => [
                'display_name' => (string) Env::get('PAYMENT_GATEWAY_VISA_NAME', 'Visa'),
                'description' => 'Pagamento Visa',
                'icon_class' => 'bi-credit-card-2-front',
                'enabled' => filter_var(Env::get('PAYMENT_GATEWAY_VISA_ENABLED', false), FILTER_VALIDATE_BOOL),
                'configured' => filter_var(Env::get('PAYMENT_GATEWAY_VISA_CONFIGURED', false), FILTER_VALIDATE_BOOL),
                'provider' => (string) Env::get('PAYMENT_GATEWAY_VISA_PROVIDER', 'visa'),
                'sort_order' => 3,
            ],
            'paypal' => [
                'display_name' => (string) Env::get('PAYMENT_GATEWAY_PAYPAL_NAME', 'PayPal'),
                'description' => 'Pagamento PayPal',
                'icon_class' => 'bi-paypal',
                'enabled' => filter_var(Env::get('PAYMENT_GATEWAY_PAYPAL_ENABLED', false), FILTER_VALIDATE_BOOL),
                'configured' => filter_var(Env::get('PAYMENT_GATEWAY_PAYPAL_CONFIGURED', false), FILTER_VALIDATE_BOOL),
                'provider' => (string) Env::get('PAYMENT_GATEWAY_PAYPAL_PROVIDER', 'paypal'),
                'sort_order' => 4,
            ],
        ];
    }
}

