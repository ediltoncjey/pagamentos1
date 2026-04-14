<?php

declare(strict_types=1);

namespace App\Utils;

final class Money
{
    public function round(float $amount): float
    {
        return round($amount, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * @return array{gross_amount:float,platform_fee:float,reseller_earning:float}
     */
    public function splitCommission(float $grossAmount, float $platformRate = 0.10): array
    {
        $platformFee = $this->round($grossAmount * $platformRate);
        $resellerEarning = $this->round($grossAmount - $platformFee);

        return [
            'gross_amount' => $this->round($grossAmount),
            'platform_fee' => $platformFee,
            'reseller_earning' => $resellerEarning,
        ];
    }

    public function add(float $left, float $right): float
    {
        return $this->round($left + $right);
    }

    public function subtract(float $left, float $right): float
    {
        return $this->round($left - $right);
    }
}
