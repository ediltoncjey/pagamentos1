<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class PaypalGateway extends UnavailableGateway
{
    public function code(): string
    {
        return 'paypal';
    }

    public function displayName(): string
    {
        return 'PayPal';
    }

    public function provider(): string
    {
        return 'paypal';
    }
}

