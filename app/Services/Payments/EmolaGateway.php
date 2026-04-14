<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class EmolaGateway extends UnavailableGateway
{
    public function code(): string
    {
        return 'emola';
    }

    public function displayName(): string
    {
        return 'e-Mola';
    }

    public function provider(): string
    {
        return 'emola';
    }
}

