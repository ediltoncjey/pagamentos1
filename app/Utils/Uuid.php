<?php

declare(strict_types=1);

namespace App\Utils;

use Ramsey\Uuid\Uuid as RamseyUuid;

final class Uuid
{
    public function v4(): string
    {
        if (class_exists(RamseyUuid::class)) {
            return RamseyUuid::uuid4()->toString();
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
