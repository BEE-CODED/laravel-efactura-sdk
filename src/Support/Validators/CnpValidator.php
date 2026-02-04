<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support\Validators;

/**
 * Validator for Romanian Personal Numeric Code (CNP).
 *
 * CNP is a 13-digit identifier assigned to Romanian citizens and residents.
 * The last digit is a control digit calculated using a weighted checksum algorithm.
 */
final class CnpValidator
{
    /**
     * Control key used for CNP validation checksum.
     */
    private const CONTROL_KEY = '279146358279';

    /**
     * Valid first digits (sex/century indicator).
     * 1-2: born 1900-1999
     * 3-4: born 1800-1899
     * 5-6: born 2000-2099
     * 7-8: foreign residents
     * 9: foreign citizens
     */
    private const VALID_SEX_DIGITS = [1, 2, 3, 4, 5, 6, 7, 8, 9];

    /**
     * ANAF special case: all zeros is considered valid for e-Factura.
     */
    private const ANAF_ZERO_CNP = '0000000000000';

    /**
     * Check if a string has valid CNP format (13 digits).
     * Does NOT validate checksum or date - use isValid() for full validation.
     *
     * @param  string  $cnp  The CNP code to check
     * @return bool True if the format matches CNP pattern
     */
    public static function isValidFormat(string $cnp): bool
    {
        return (bool) preg_match('/^\d{13}$/', $cnp);
    }

    /**
     * Validate if the code is a valid Romanian CNP.
     *
     * @param  string  $cnp  The CNP code to validate
     * @return bool True if the CNP is valid, false otherwise
     */
    public static function isValid(string $cnp): bool
    {
        // CNP should have exactly 13 digits
        if (! preg_match('/^\d{13}$/', $cnp)) {
            return false;
        }

        // ANAF allows 13 zeros as valid CNP for e-factura
        if ($cnp === self::ANAF_ZERO_CNP) {
            return true;
        }

        // Verify first digit is a valid sex/century indicator
        $sexDigit = (int) $cnp[0];
        if (! in_array($sexDigit, self::VALID_SEX_DIGITS, true)) {
            return false;
        }

        // Validate the embedded date (positions 2-7: YYMMDD)
        if (! self::isValidDate($cnp, $sexDigit)) {
            return false;
        }

        // Calculate checksum
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $cnp[$i] * (int) self::CONTROL_KEY[$i];
        }

        $remainder = $sum % 11;
        $controlDigit = $remainder === 10 ? 1 : $remainder;

        // Verify control digit matches calculated value
        return (int) $cnp[12] === $controlDigit;
    }

    /**
     * Validate the date embedded in the CNP.
     *
     * @param  string  $cnp  The full CNP
     * @param  int  $sexDigit  The first digit (sex/century indicator)
     * @return bool True if the date is valid
     */
    private static function isValidDate(string $cnp, int $sexDigit): bool
    {
        $yearPart = (int) substr($cnp, 1, 2);
        $month = (int) substr($cnp, 3, 2);
        $day = (int) substr($cnp, 5, 2);

        // Determine century based on sex digit
        $century = match ($sexDigit) {
            1, 2 => 1900,      // Born 1900-1999
            3, 4 => 1800,      // Born 1800-1899
            5, 6 => 2000,      // Born 2000-2099
            7, 8, 9 => 1900,   // Foreign residents (assume 1900s for validation)
            default => 1900,
        };

        $year = $century + $yearPart;

        // Validate month (1-12)
        if ($month < 1 || $month > 12) {
            return false;
        }

        // Validate day using checkdate (handles leap years, month lengths)
        return checkdate($month, $day, $year);
    }
}
