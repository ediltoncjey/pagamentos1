<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Utils\Env;
use RuntimeException;

final class PaymentGatewayResolver
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $gateways;

    public function __construct(
        MpesaGateway $mpesa,
        EmolaGateway $emola,
        VisaGateway $visa,
        PaypalGateway $paypal,
    ) {
        $this->gateways = [
            $mpesa->code() => $mpesa,
            $emola->code() => $emola,
            $visa->code() => $visa,
            $paypal->code() => $paypal,
        ];
    }

    public function defaultCode(): string
    {
        $default = strtolower(trim((string) Env::get('PAYMENT_DEFAULT_GATEWAY', 'mpesa')));
        return $default !== '' ? $default : 'mpesa';
    }

    public function resolve(string $gatewayCode): PaymentGatewayInterface
    {
        $gatewayCode = strtolower(trim($gatewayCode));
        if ($gatewayCode === '') {
            $gatewayCode = $this->defaultCode();
        }

        $gateway = $this->gateways[$gatewayCode] ?? null;
        if ($gateway === null) {
            throw new RuntimeException('Gateway de pagamento invalido: ' . $gatewayCode);
        }

        return $gateway;
    }
}

