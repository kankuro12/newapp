<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function digits(string $value): string
    {
        return strtr(trim($value), array_combine(preg_split('//u', '०१२३४५६७८९', -1, PREG_SPLIT_NO_EMPTY), str_split('0123456789')));
    }

    public static function parse(string $value, int $places = 2, int $cap = 1000000000): int
    {
        $value = self::digits($value);
        if (! preg_match('/^\d+(?:\.\d{1,'.$places.'})?$/D', $value)) {
            throw new InvalidArgumentException('Enter a positive decimal with at most '.$places.' decimal places.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        $integer = ltrim($whole.str_pad($fraction, $places, '0'), '0') ?: '0';
        if (bccomp($integer, (string) $cap) > 0) {
            throw new InvalidArgumentException('Amount exceeds supported limit.');
        }

        return (int) $integer;
    }

    public static function quantity(string $value): int
    {
        return self::parse($value, 3);
    }

    public static function format(int $value, int $places = 2): string
    {
        if ($places === 0) {
            return (string) $value;
        }
        $digits = str_pad((string) abs($value), $places + 1, '0', STR_PAD_LEFT);

        return ($value < 0 ? '-' : '').substr($digits, 0, -$places).'.'.substr($digits, -$places);
    }

    public static function checked(string $value): int
    {
        if (bccomp($value, (string) PHP_INT_MAX) > 0 || bccomp($value, (string) PHP_INT_MIN) < 0) {
            throw new InvalidArgumentException('Financial total exceeds supported limit.');
        }

        return (int) $value;
    }

    public static function multiplyDivide(int $a, int $b, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Invalid divisor.');
        }
        $product = bcmul((string) $a, (string) $b, 0);
        $negative = str_starts_with($product, '-');
        $absolute = ltrim($product, '-');
        $quotient = bcdiv($absolute, (string) $denominator, 0);
        if (bccomp(bcmul(bcmod($absolute, (string) $denominator), '2'), (string) $denominator) >= 0) {
            $quotient = bcadd($quotient, '1', 0);
        }

        return self::checked(($negative ? '-' : '').$quotient);
    }

    public static function allocate(int $discount, array $bases): array
    {
        $total = array_sum($bases);
        if ($discount < 0 || $discount > $total) {
            throw new InvalidArgumentException('Discount exceeds line amounts.');
        }
        $shares = array_fill(0, count($bases), 0);
        if (! $total) {
            return $shares;
        }
        $remainders = [];
        foreach ($bases as $i => $base) {
            $product = bcmul((string) $discount, (string) $base, 0);
            $shares[$i] = (int) bcdiv($product, (string) $total, 0);
            $remainders[$i] = bcmod($product, (string) $total);
        }
        $order = array_keys($bases);
        usort($order, fn ($a, $b) => -bccomp($remainders[$a], $remainders[$b]) ?: $a <=> $b);
        for ($i = 0, $left = $discount - array_sum($shares); $i < $left; $i++) {
            $shares[$order[$i]]++;
        }

        return $shares;
    }
}
