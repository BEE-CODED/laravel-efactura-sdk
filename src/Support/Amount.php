<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support;

use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Reads a caller's amount as an exact decimal at the precision the XML files it with.
 *
 * Nothing is rounded silently: a value carrying more precision than its field files is
 * refused instead of being changed on the way into a legal document.
 */
final class Amount
{
    /**
     * Parse an int, numeric string, BigDecimal or float into a BigDecimal at $scale decimals.
     *
     * Strings and BigDecimals may carry at most $scale decimals (trailing zeros allowed). A
     * float is accepted only when it differs from its $scale-decimal rendering by binary
     * representation noise — at most min(10^-scale / 10, max(1e-9, |f| x 2 x PHP_FLOAT_EPSILON))
     * — and only below 2^46 at scale 2 (2^33 at scale 6), where adjacent steps of 10^-scale stay
     * distinguishable in a double. It is then read from that rounded rendering.
     *
     * @param  int  $scale  Decimals the field is filed with (2 for amounts and rates, 6 for
     *                      quantities, unit prices and line tax)
     * @param  string  $field  Field name reported in the ValidationException
     *
     * @throws ValidationException If the value is not such an amount
     */
    public static function parse(mixed $value, int $scale, string $field): BigDecimal
    {
        if (is_float($value)) {
            $value = self::fromFloat($value, $scale, $field);
        }

        if (! is_int($value) && ! is_string($value) && ! $value instanceof BigDecimal) {
            throw self::invalid($field, $scale);
        }

        try {
            return BigDecimal::of($value)->toScale($scale, RoundingMode::Unnecessary);
        } catch (MathException) {
            throw self::invalid($field, $scale);
        }
    }

    /** @throws ValidationException */
    private static function fromFloat(float $value, int $scale, string $field): string
    {
        // 2^46 at scale 2 and 2^33 at scale 6: the largest magnitude where 10^-scale steps survive.
        $limit = 2 ** (53 - (int) ceil($scale * log(10, 2)));
        if (! is_finite($value) || abs($value) >= $limit) {
            throw self::invalid($field, $scale);
        }

        $rounded = number_format($value, $scale, '.', '');
        $tolerance = min(10 ** -$scale / 10, max(1e-9, abs($value) * 2 * PHP_FLOAT_EPSILON));
        if (abs($value - (float) $rounded) > $tolerance) {
            throw self::invalid($field, $scale);
        }

        return $rounded;
    }

    private static function invalid(string $field, int $scale): ValidationException
    {
        return new ValidationException(__('efactura-sdk::validation.amount_invalid', ['field' => $field, 'scale' => $scale]));
    }
}
