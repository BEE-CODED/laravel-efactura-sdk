<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Support\Validators;

use InvalidArgumentException;

/**
 * Validator and normalizer for Romanian VAT numbers (CUI/CIF).
 *
 * Romanian VAT numbers can be provided with or without the "RO" country prefix.
 * This class provides utilities to validate format, normalize, and strip prefixes.
 *
 * Implements the CUI/CIF checksum validation algorithm as per Romanian regulations.
 *
 * @see https://ro.wikipedia.org/wiki/Număr_de_identificare_fiscală
 */
final class VatNumberValidator
{
    /**
     * Romanian country prefix for VAT numbers.
     */
    private const RO_PREFIX = 'RO';

    /**
     * Control key for CUI/CIF checksum validation.
     * Used with modulo 11 algorithm.
     */
    private const CUI_CONTROL_KEY = '753217532';

    /**
     * Check if a VAT code is valid (format and checksum).
     *
     * This validates:
     * - Format: Optional RO prefix followed by 2-10 digits
     * - Checksum: Using the control key algorithm (modulo 11)
     *
     * @param  string  $vatCode  The VAT code to validate
     * @return bool True if the format and checksum are valid
     */
    public static function isValid(string $vatCode): bool
    {
        $vatCode = trim($vatCode);

        if ($vatCode === '') {
            return false;
        }

        // Check if it's a valid CNP (13 digits with valid checksum)
        if (CnpValidator::isValid($vatCode)) {
            return true;
        }

        // Check format: optional RO prefix followed by 2-10 digits
        if (! preg_match('/^(RO)?(\d{2,10})$/i', $vatCode, $matches)) {
            return false;
        }

        // Extract numeric part for checksum validation
        $numericPart = $matches[2];

        return self::validateChecksum($numericPart);
    }

    /**
     * Validate only the format (without checksum).
     *
     * Use this for lenient validation when you only need to check
     * if the VAT code has a valid format but not verify the checksum.
     *
     * @param  string  $vatCode  The VAT code to validate
     * @return bool True if the format is valid
     */
    public static function isValidFormat(string $vatCode): bool
    {
        $vatCode = trim($vatCode);

        if ($vatCode === '') {
            return false;
        }

        // Check if it's a valid CNP format (13 digits - format only, no checksum)
        if (CnpValidator::isValidFormat($vatCode)) {
            return true;
        }

        // Check format: optional RO prefix followed by 2-10 digits
        return (bool) preg_match('/^(RO)?\d{2,10}$/i', $vatCode);
    }

    /**
     * Validate the CUI/CIF checksum using the control key algorithm.
     *
     * Algorithm (per Romanian regulations):
     * 1. Left-pad the number with zeros to 9 digits (if needed)
     * 2. Multiply each digit by the corresponding control key digit
     * 3. Sum all products
     * 4. Multiply sum by 10
     * 5. Calculate modulo 11
     * 6. If result is 10, use 0 as the check digit
     * 7. Compare with the last digit of the original number
     *
     * @param  string  $numericCui  The numeric part of the CUI (without RO prefix)
     * @return bool True if checksum is valid
     *
     * @see https://ro.wikipedia.org/wiki/Număr_de_identificare_fiscală
     */
    private static function validateChecksum(string $numericCui): bool
    {
        $length = strlen($numericCui);

        // CUI must be between 2 and 10 digits
        if ($length < 2 || $length > 10) {
            return false;
        }

        // Extract the check digit (last digit)
        $checkDigit = (int) $numericCui[$length - 1];

        // Get the number without the check digit
        $numberWithoutCheck = substr($numericCui, 0, $length - 1);

        // Left-pad with zeros to 9 digits (control key length)
        $paddedNumber = str_pad($numberWithoutCheck, 9, '0', STR_PAD_LEFT);

        // Calculate the weighted sum
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $paddedNumber[$i] * (int) self::CUI_CONTROL_KEY[$i];
        }

        // Multiply by 10 and get modulo 11
        $remainder = ($sum * 10) % 11;

        // If remainder is 10, the check digit should be 0
        $expectedCheckDigit = ($remainder === 10) ? 0 : $remainder;

        return $checkDigit === $expectedCheckDigit;
    }

    /**
     * Normalize a VAT number by adding the RO prefix if missing.
     *
     * If the value is a valid CNP, it is returned unchanged.
     * Otherwise, the RO prefix is added if not already present.
     *
     * @param  string  $vatCode  The VAT code to normalize
     * @return string The normalized VAT code with RO prefix
     *
     * @throws InvalidArgumentException If the VAT code is empty
     */
    public static function normalize(string $vatCode): string
    {
        $vatCode = trim($vatCode);

        if ($vatCode === '') {
            throw new InvalidArgumentException('Company VAT number is missing.');
        }

        // If it's a valid CNP, return it unchanged
        if (CnpValidator::isValid($vatCode)) {
            return $vatCode;
        }

        // Add RO prefix if not already present
        if (stripos($vatCode, self::RO_PREFIX) === 0) {
            // Normalize case to uppercase RO
            return self::RO_PREFIX.substr($vatCode, 2);
        }

        return self::RO_PREFIX.$vatCode;
    }

    /**
     * Remove the RO prefix from a VAT number.
     *
     * @param  string  $vatCode  The VAT code to strip
     * @return string The VAT code without the RO prefix
     *
     * @throws InvalidArgumentException If the VAT code is empty
     */
    public static function stripPrefix(string $vatCode): string
    {
        $vatCode = trim($vatCode);

        if ($vatCode === '') {
            throw new InvalidArgumentException('Company VAT number is missing.');
        }

        // Remove RO prefix case-insensitively
        return preg_replace('/^ro/i', '', $vatCode) ?? $vatCode;
    }
}
