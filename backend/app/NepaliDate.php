<?php

namespace App;

use App\Support\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class NepaliDate
{
    public static function table(): array
    {
        return require __DIR__.'/../config/bs-calendar.php';
    }

    public static function normalize(string|int $value): int
    {
        $digits = Money::digits((string) $value);
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})$|^(\d{4})-(\d{2})-(\d{2})$/D', $digits, $m)) {
            throw new InvalidArgumentException('Use BS date YYYY-MM-DD.');
        }
        $parts = strlen($digits) === 8 ? array_slice($m, 1, 3) : array_slice($m, 4, 3);
        [$year, $month, $day] = array_map('intval', $parts);
        $table = self::table();
        if (! isset($table[$year]) || $month < 1 || $month > 12 || $day < 1 || $day > $table[$year][$month - 1]) {
            throw new InvalidArgumentException('Invalid or unsupported BS date (2000–2090).');
        }

        return $year * 10000 + $month * 100 + $day;
    }

    private static function ordinal(int $date): int
    {
        $date = self::normalize($date);
        $year = intdiv($date, 10000);
        $month = intdiv($date % 10000, 100);
        $days = $date % 100 - 1;
        foreach (self::table() as $y => $months) {
            if ($y < $year) {
                $days += array_sum($months);
            }
            if ($y === $year) {
                $days += array_sum(array_slice($months, 0, $month - 1));
                break;
            }
        }

        return $days;
    }

    public static function fromAd(string $date): int
    {
        $base = new DateTimeImmutable('1943-04-14', new DateTimeZone('Asia/Kathmandu'));
        $days = (int) $base->diff(new DateTimeImmutable($date, new DateTimeZone('Asia/Kathmandu')))->format('%r%a');
        if ($days < 0) {
            throw new InvalidArgumentException('Unsupported calendar date.');
        }
        foreach (self::table() as $year => $months) {
            foreach ($months as $month => $length) {
                if ($days < $length) {
                    return $year * 10000 + ($month + 1) * 100 + $days + 1;
                } $days -= $length;
            }
        }
        throw new InvalidArgumentException('Unsupported calendar date.');
    }

    public static function today(): int
    {
        return self::fromAd((new DateTimeImmutable('now', new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d'));
    }

    public static function daysBetween(int $from, int $to): int
    {
        return self::ordinal($to) - self::ordinal($from);
    }

    public static function fiscalYearLabel(int $date): string
    {
        $date = self::normalize($date);
        $year = intdiv($date, 10000) - ($date % 10000 < 401 ? 1 : 0);

        return $year.'/'.($year + 1);
    }

    public static function monthRange(int $date): array
    {
        $date = self::normalize($date);
        $year = intdiv($date, 10000);
        $month = intdiv($date % 10000, 100);

        return [$year * 10000 + $month * 100 + 1, $year * 10000 + $month * 100 + self::table()[$year][$month - 1]];
    }

    public static function fiscalYearRange(int $date): array
    {
        $year = (int) explode('/', self::fiscalYearLabel($date))[0];

        return [$year * 10000 + 401, ($year + 1) * 10000 + 300 + (self::table()[$year + 1][2] ?? throw new InvalidArgumentException('Unsupported fiscal year.'))];
    }

    public static function nextMonth(int $date, int $day): int
    {
        self::normalize($date);
        if ($day < 1 || $day > 32) {
            throw new InvalidArgumentException('Monthly day must be 1–32.');
        }
        $year = intdiv($date, 10000);
        $month = intdiv($date % 10000, 100) + 1;
        if ($month > 12) {
            $year++;
            $month = 1;
        }
        $length = self::table()[$year][$month - 1] ?? throw new InvalidArgumentException('Unsupported next BS month.');

        return $year * 10000 + $month * 100 + min($day, $length);
    }
}
