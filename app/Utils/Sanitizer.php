<?php

declare(strict_types=1);

namespace App\Utils;

final class Sanitizer
{
    public function string(mixed $value, int $maxLength = 255): string
    {
        $clean = trim((string) $value);
        $clean = strip_tags($clean);
        return mb_substr($clean, 0, $maxLength);
    }

    public function email(mixed $value): string
    {
        return (string) filter_var((string) $value, FILTER_SANITIZE_EMAIL);
    }

    public function phone(mixed $value): string
    {
        return preg_replace('/[^0-9]/', '', (string) $value) ?? '';
    }

    public function html(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
