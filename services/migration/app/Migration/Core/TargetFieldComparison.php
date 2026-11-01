<?php

namespace App\Migration\Core;

/**
 * Compare imported scalar business fields without treating a changed audit
 * timestamp as a change to the business record.
 *
 * Unknown representation pairs deliberately compare as different. A false
 * negative causes an unnecessary UPDATE; a false positive could lose a change.
 */
final class TargetFieldComparison
{
    private const NUMERIC = [
        'hourly_rate', 'hours_per_day', 'hours', 'confirmed_hours',
        'quantity', 'payment_days', 'act_approval_days',
    ];

    public static function matches(mixed $actual, mixed $expected, string $field): bool
    {
        if ($actual === $expected) {
            return true;
        }
        if ($actual === null || $expected === null) {
            return false;
        }
        if ($expected instanceof \DateTimeInterface) {
            $expected = $expected->format('Y-m-d H:i:s');
        }
        if ($actual instanceof \DateTimeInterface) {
            $actual = $actual->format('Y-m-d H:i:s');
        }
        if (is_bool($expected)) {
            if (is_bool($actual)) {
                return $actual === $expected;
            }
            if (!is_scalar($actual)) {
                return false;
            }
            $text = strtolower((string) $actual);
            return in_array($text, $expected ? ['1', 't', 'true'] : ['0', 'f', 'false'], true);
        }
        if (!is_scalar($actual) || !is_scalar($expected)) {
            return false;
        }
        if ((string) $actual === (string) $expected) {
            return true;
        }
        // Compare only known numeric columns numerically. Logins, phone
        // numbers and identifiers may legitimately contain leading zeros.
        if (!in_array($field, self::NUMERIC, true)) {
            return false;
        }
        $a = self::decimal((string) $actual);
        $b = self::decimal((string) $expected);
        return $a !== null && $a === $b;
    }

    private static function decimal(string $value): ?string
    {
        if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)) {
            return null;
        }
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-');
        $pieces = explode('.', $absolute, 2);
        $whole = ltrim($pieces[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($pieces[1] ?? '', '0');
        if ($whole === '0' && $fraction === '') {
            $negative = false;
        }
        return ($negative ? '-' : '').$whole.($fraction === '' ? '' : '.'.$fraction);
    }
}
