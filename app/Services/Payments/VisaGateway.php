<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class VisaGateway extends UnavailableGateway
{
    public function code(): string
    {
        return 'visa';
    }

    public function displayName(): string
    {
        return 'Visa';
    }

    public function provider(): string
    {
        return 'visa';
    }
}

