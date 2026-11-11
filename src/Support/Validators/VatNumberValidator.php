<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support\Validators;

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
     * VAT identifier prefixes that differ from the party's ISO 3166-1 alpha-2 country code:
     * Greece registers VAT under "EL" (Elláda) while its country code is "GR".
     *
     * @var array<string, string>
     */
    private const VAT_PREFIX_OVERRIDES = [
        'GR' => 'EL',
    ];

    /**
     * Recognised VAT identifier prefixes: the EU-27 prefixes ("EL" for Greece), plus "GB" and
     * Northern Ireland's "XI", which appears on otherwise-"GB" addresses.
     *
     * @var list<string>
     */
    private const VAT_COUNTRY_PREFIXES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
        'GB', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    /**
     * Check if a VAT code is valid (format and checksum).
     *
     * This validates:
     * - CNP: 13 digits with valid checksum (Romanian personal identification number)
     * - CUI/CIF: Optional RO prefix followed by 2-10 digits with valid checksum
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

        // If it's 13 digits, it must be validated as a CNP (not as CUI)
        // CNPs are valid VAT identifiers for individual entrepreneurs (PFA)
        if (preg_match('/^\d{13}$/', $vatCode)) {
            return CnpValidator::isValid($vatCode);
        }

        // Check CUI format: optional RO prefix followed by 2-10 digits
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
     * @throws InvalidArgumentException If the VAT code is empty or has invalid format
     */
    public static function normalize(string $vatCode): string
    {
        $vatCode = trim($vatCode);

        if ($vatCode === '') {
            throw new InvalidArgumentException('Company VAT number is missing.');
        }

        // Validate format before normalizing to prevent creating invalid outputs
        if (! self::isValidFormat($vatCode)) {
            throw new InvalidArgumentException(
                "Invalid VAT number format: {$vatCode}. Expected CNP (13 digits) or CUI (2-10 digits with optional RO prefix)."
            );
        }

        // If it's a valid CNP, return it unchanged
        if (CnpValidator::isValid($vatCode)) {
            return $vatCode;
        }

        // If it's 13 digits but not a valid CNP, reject it
        // (valid CNP format but invalid checksum/date)
        if (CnpValidator::isValidFormat($vatCode)) {
            throw new InvalidArgumentException(
                "Invalid CNP: {$vatCode}. The 13-digit number has an invalid checksum or date."
            );
        }

        // Add RO prefix if not already present
        if (stripos($vatCode, self::RO_PREFIX) === 0) {
            // Normalize case to uppercase RO
            return self::RO_PREFIX.substr($vatCode, 2);
        }

        return self::RO_PREFIX.$vatCode;
    }

    /**
     * Prefix a VAT identifier with the VAT prefix of the state that issued it (BT-31/BT-48).
     *
     * Two things make "prefix with the address country code unless it already starts with
     * it" wrong:
     *
     *  - the prefix is not always the ISO 3166-1 country code (Greece files under "EL", not
     *    "GR"), so an already-prefixed Greek id would get prefixed again: "GREL123456789";
     *  - a party may hold a VAT identifier issued by a state other than the one its address
     *    is in (a foreign VAT registration, or Northern Ireland's "XI" on an otherwise "GB"
     *    address), which would likewise be re-prefixed.
     *
     * Either way the result is a syntactically invalid VAT identifier, so the prefix is only
     * added when the id does not already carry a recognised one.
     *
     * The mirror image of the first case is corrected rather than rejected: a caller who
     * prefixes the id with Greece's COUNTRY code ("GR123456789") instead of its VAT prefix
     * would otherwise be handed "ELGR123456789". Those country codes are deliberately absent
     * from VAT_COUNTRY_PREFIXES, precisely because no VAT id legitimately carries one.
     *
     * PASS VAT IDENTIFIERS WITH THEIR VAT PREFIX. One input is genuinely ambiguous, and no
     * logic here can resolve it. A French VAT is "FR" + a two-character key + 9 digits, and
     * that key MAY BE TWO LETTERS — HMRC documents "XX123456789", and France is the only
     * member state where this occurs. So a bare French id whose key happens to be "GR" is
     * indistinguishable from a Greek id carrying its country code:
     *
     *   withCountryPrefix('GR123456789', 'RO')  Greek party, foreign address -> EL123456789
     *   withCountryPrefix('GR123456789', 'FR')  bare French id, key "GR"     -> EL123456789 (wrong)
     *
     * This resolves toward the first reading: an explicit prefix is better evidence of the
     * issuing state than an address is, and a foreign VAT registration is far commoner than an
     * unprefixed French id with a letter-letter key. A prefixed "FRGR123456789" is unambiguous
     * and passes through untouched. Malformed-but-prefixed input such as "RO 12345678" is left
     * intact rather than doubled.
     *
     * @param  string  $id  The VAT identifier, with or without its prefix
     * @param  string|null  $country  The party's ISO 3166-1 alpha-2 country code (null = RO)
     * @return string The identifier carrying a VAT prefix, uppercased and trimmed
     */
    public static function withCountryPrefix(string $id, ?string $country): string
    {
        $id = strtoupper(trim($id));
        $country = strtoupper($country ?? self::RO_PREFIX);
        $expectedPrefix = self::VAT_PREFIX_OVERRIDES[$country] ?? $country;

        if (self::hasVatCountryPrefix($id, $expectedPrefix)) {
            return $id;
        }

        // Swap a country code that differs from its VAT prefix for the prefix the state
        // actually files under, rather than prefixing on top of it.
        foreach (self::VAT_PREFIX_OVERRIDES as $isoCode => $vatPrefix) {
            $remainder = substr($id, strlen($isoCode));

            if (str_starts_with($id, $isoCode) && preg_match('/^[0-9A-Z]/', $remainder) === 1) {
                return $vatPrefix.$remainder;
            }
        }

        return $expectedPrefix.$id;
    }

    /**
     * Whether a VAT identifier already carries a country prefix.
     *
     * Requiring an alphanumeric character after a recognised prefix keeps this narrow. It is
     * not airtight, and cannot be: a bare French id with a key of "EL" or "IT" reads as
     * already-prefixed and is filed without its "FR" — see withCountryPrefix().
     */
    private static function hasVatCountryPrefix(string $id, string $expectedPrefix): bool
    {
        if (str_starts_with($id, $expectedPrefix)) {
            return true;
        }

        return preg_match('/^([A-Z]{2})[0-9A-Z]/', $id, $matches) === 1
            && in_array($matches[1], self::VAT_COUNTRY_PREFIXES, true);
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
