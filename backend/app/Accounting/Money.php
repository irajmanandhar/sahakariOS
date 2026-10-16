<?php

namespace App\Accounting;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * NPR amounts: exact decimals with 2 places (paisa). Never floats.
 * Input must already be in paisa precision; nothing is rounded silently.
 */
final class Money
{
    public const PATTERN = '/^\d{1,16}(\.\d{1,2})?$/';

    public static function parse(string $amount): BigDecimal
    {
        if (! preg_match(self::PATTERN, $amount)) {
            throw new PostingRejected("Invalid amount '{$amount}': use digits with at most 2 decimal places.");
        }

        return BigDecimal::of($amount)->toScale(2, RoundingMode::Unnecessary);
    }

    /** Like parse(), but allows a leading minus (e.g. an overdrawn bank statement balance). */
    public static function parseSigned(string $amount): BigDecimal
    {
        $negative = str_starts_with($amount, '-');
        $value = self::parse($negative ? substr($amount, 1) : $amount);

        return $negative ? $value->negated() : $value;
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::of('0.00');
    }

    public static function format(BigDecimal|string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2, RoundingMode::Unnecessary);
    }
}
