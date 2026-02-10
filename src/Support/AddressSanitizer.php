<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support;

/**
 * Address sanitizer for Romanian e-Factura system.
 *
 * Converts Romanian county names and Bucharest sectors to ISO 3166-2:RO codes
 * as required by ANAF e-Factura validation.
 */
class AddressSanitizer
{
    /**
     * Map of Romanian county names (normalized) to ISO 3166-2:RO codes.
     *
     * Includes all 41 Romanian counties plus Bucharest with diacritic variants.
     *
     * @var array<string, string>
     */
    private const RO_ISO_3166_2_RO_MAP = [
        // Bucharest
        'BUCURESTI' => 'RO-B',
        'BUC' => 'RO-B',
        'B' => 'RO-B',
        'MUNICIPIUL BUCURESTI' => 'RO-B',

        // Alba
        'ALBA' => 'RO-AB',
        'ALBA IULIA' => 'RO-AB',
        'JUDETUL ALBA' => 'RO-AB',

        // Arad
        'ARAD' => 'RO-AR',
        'JUDETUL ARAD' => 'RO-AR',

        // Arges (with diacritic variants)
        'ARGES' => 'RO-AG',
        'JUDETUL ARGES' => 'RO-AG',

        // Bacau (with diacritic variants)
        'BACAU' => 'RO-BC',
        'JUDETUL BACAU' => 'RO-BC',

        // Bihor
        'BIHOR' => 'RO-BH',
        'JUDETUL BIHOR' => 'RO-BH',

        // Bistrita-Nasaud (with diacritic variants)
        'BISTRITA NASAUD' => 'RO-BN',
        'BISTRITA-NASAUD' => 'RO-BN',
        'BISTRITANASAUD' => 'RO-BN',
        'JUDETUL BISTRITA NASAUD' => 'RO-BN',

        // Botosani (with diacritic variants)
        'BOTOSANI' => 'RO-BT',
        'JUDETUL BOTOSANI' => 'RO-BT',

        // Braila
        'BRAILA' => 'RO-BR',
        'JUDETUL BRAILA' => 'RO-BR',

        // Brasov (with diacritic variants)
        'BRASOV' => 'RO-BV',
        'JUDETUL BRASOV' => 'RO-BV',

        // Buzau (with diacritic variants)
        'BUZAU' => 'RO-BZ',
        'JUDETUL BUZAU' => 'RO-BZ',

        // Calarasi (with diacritic variants)
        'CALARASI' => 'RO-CL',
        'JUDETUL CALARASI' => 'RO-CL',

        // Caras-Severin (with multiple variants)
        'CARAS SEVERIN' => 'RO-CS',
        'CARAS-SEVERIN' => 'RO-CS',
        'CARASSEVERIN' => 'RO-CS',
        'JUDETUL CARAS SEVERIN' => 'RO-CS',

        // Cluj
        'CLUJ' => 'RO-CJ',
        'CLUJ NAPOCA' => 'RO-CJ',
        'JUDETUL CLUJ' => 'RO-CJ',

        // Constanta (with diacritic variants)
        'CONSTANTA' => 'RO-CT',
        'JUDETUL CONSTANTA' => 'RO-CT',

        // Covasna
        'COVASNA' => 'RO-CV',
        'JUDETUL COVASNA' => 'RO-CV',

        // Dambovita (with diacritic variants)
        'DAMBOVITA' => 'RO-DB',
        'JUDETUL DAMBOVITA' => 'RO-DB',

        // Dolj
        'DOLJ' => 'RO-DJ',
        'JUDETUL DOLJ' => 'RO-DJ',

        // Galati (with diacritic variants)
        'GALATI' => 'RO-GL',
        'JUDETUL GALATI' => 'RO-GL',

        // Giurgiu
        'GIURGIU' => 'RO-GR',
        'JUDETUL GIURGIU' => 'RO-GR',

        // Gorj
        'GORJ' => 'RO-GJ',
        'JUDETUL GORJ' => 'RO-GJ',

        // Harghita
        'HARGHITA' => 'RO-HR',
        'JUDETUL HARGHITA' => 'RO-HR',

        // Hunedoara
        'HUNEDOARA' => 'RO-HD',
        'JUDETUL HUNEDOARA' => 'RO-HD',

        // Ialomita (with diacritic variants)
        'IALOMITA' => 'RO-IL',
        'JUDETUL IALOMITA' => 'RO-IL',

        // Iasi (with diacritic variants)
        'IASI' => 'RO-IS',
        'JUDETUL IASI' => 'RO-IS',

        // Ilfov
        'ILFOV' => 'RO-IF',
        'JUDETUL ILFOV' => 'RO-IF',

        // Maramures (with diacritic variants)
        'MARAMURES' => 'RO-MM',
        'JUDETUL MARAMURES' => 'RO-MM',

        // Mehedinti (with diacritic variants)
        'MEHEDINTI' => 'RO-MH',
        'JUDETUL MEHEDINTI' => 'RO-MH',

        // Mures (with diacritic variants)
        'MURES' => 'RO-MS',
        'JUDETUL MURES' => 'RO-MS',

        // Neamt (with diacritic variants)
        'NEAMT' => 'RO-NT',
        'JUDETUL NEAMT' => 'RO-NT',

        // Olt
        'OLT' => 'RO-OT',
        'JUDETUL OLT' => 'RO-OT',

        // Prahova
        'PRAHOVA' => 'RO-PH',
        'JUDETUL PRAHOVA' => 'RO-PH',

        // Salaj (with diacritic variants)
        'SALAJ' => 'RO-SJ',
        'JUDETUL SALAJ' => 'RO-SJ',

        // Satu Mare
        'SATU MARE' => 'RO-SM',
        'SATU-MARE' => 'RO-SM',
        'SATUMARE' => 'RO-SM',
        'JUDETUL SATU MARE' => 'RO-SM',

        // Sibiu
        'SIBIU' => 'RO-SB',
        'JUDETUL SIBIU' => 'RO-SB',

        // Suceava
        'SUCEAVA' => 'RO-SV',
        'JUDETUL SUCEAVA' => 'RO-SV',

        // Teleorman
        'TELEORMAN' => 'RO-TR',
        'JUDETUL TELEORMAN' => 'RO-TR',

        // Timis (with diacritic variants)
        'TIMIS' => 'RO-TM',
        'JUDETUL TIMIS' => 'RO-TM',

        // Tulcea
        'TULCEA' => 'RO-TL',
        'JUDETUL TULCEA' => 'RO-TL',

        // Valcea (with diacritic variants - including common typo 'vilcea')
        'VALCEA' => 'RO-VL',
        'VILCEA' => 'RO-VL',
        'JUDETUL VALCEA' => 'RO-VL',

        // Vaslui
        'VASLUI' => 'RO-VS',
        'JUDETUL VASLUI' => 'RO-VS',

        // Vrancea
        'VRANCEA' => 'RO-VN',
        'JUDETUL VRANCEA' => 'RO-VN',
    ];

    /**
     * Bucharest sector patterns for extraction from addresses.
     *
     * @var array<string>
     */
    private const BUCHAREST_SECTOR_PATTERNS = [
        '/\bSECTOR\s*(\d)\b/i',
        '/\bSECTORUL\s*(\d)\b/i',
        '/\bSECT\.?\s*(\d)\b/i',
        '/\bS\.?\s*(\d)\b/i',
    ];

    /**
     * Romanian diacritics mapping for normalization.
     *
     * @var array<string, string>
     */
    private const DIACRITICS_MAP = [
        // Lowercase
        "\xC4\x83" => 'a', // ă
        "\xC3\xA2" => 'a', // â
        "\xC3\xAE" => 'i', // î
        "\xC8\x99" => 's', // ș (with comma below)
        "\xC5\x9F" => 's', // ş (with cedilla - legacy)
        "\xC8\x9B" => 't', // ț (with comma below)
        "\xC5\xA3" => 't', // ţ (with cedilla - legacy)

        // Uppercase
        "\xC4\x82" => 'A', // Ă
        "\xC3\x82" => 'A', // Â
        "\xC3\x8E" => 'I', // Î
        "\xC8\x98" => 'S', // Ș (with comma below)
        "\xC5\x9E" => 'S', // Ş (with cedilla - legacy)
        "\xC8\x9A" => 'T', // Ț (with comma below)
        "\xC5\xA2" => 'T', // Ţ (with cedilla - legacy)
    ];

    /**
     * Normalize Romanian diacritics to ASCII equivalents.
     *
     * Replaces Romanian-specific characters: ă->a, â->a, î->i, ș->s, ț->t
     */
    public static function normalizeDiacritics(string $text): string
    {
        return strtr($text, self::DIACRITICS_MAP);
    }

    /**
     * Sanitize county name to ISO 3166-2:RO code.
     *
     * @param  string  $county  The county name to sanitize
     * @return string|null The ISO 3166-2:RO code or null if not found
     */
    public static function sanitizeCounty(string $county): ?string
    {
        // Normalize: uppercase, trim, remove diacritics
        $normalized = self::normalizeInput($county);

        // If already a valid ISO 3166-2:RO code, return as-is
        if (in_array($normalized, self::RO_ISO_3166_2_RO_MAP, true)) {
            return $normalized;
        }

        // Direct lookup
        if (isset(self::RO_ISO_3166_2_RO_MAP[$normalized])) {
            return self::RO_ISO_3166_2_RO_MAP[$normalized];
        }

        // Try stripping common prefixes
        $stripped = self::stripAdministrativePrefixes($normalized);
        if ($stripped !== $normalized && isset(self::RO_ISO_3166_2_RO_MAP[$stripped])) {
            return self::RO_ISO_3166_2_RO_MAP[$stripped];
        }

        return null;
    }

    /**
     * Extract and sanitize Bucharest sector from address.
     *
     * Returns the ISO 3166-2:RO code for Bucharest (RO-B) for all Bucharest addresses.
     * Bucharest sectors are NOT part of ISO 3166-2:RO, so all sectors map to RO-B.
     *
     * @param  string  $address  The address to extract sector from
     * @return string|null 'RO-B' if Bucharest address detected, or null
     */
    public static function sanitizeBucharestSector(string $address): ?string
    {
        $normalized = self::normalizeInput($address);

        // Check if any Bucharest sector pattern matches
        foreach (self::BUCHAREST_SECTOR_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $sectorNumber = (int) $matches[1];
                if ($sectorNumber >= 1 && $sectorNumber <= 6) {
                    // Return ISO 3166-2:RO code for Bucharest (not sector-specific)
                    return 'RO-B';
                }
            }
        }

        // If address contains Bucharest reference but no sector found
        if (self::isBucharest($address)) {
            return 'RO-B';
        }

        return null;
    }

    /**
     * Extract the Bucharest sector number from an address.
     *
     * This method returns the sector number (1-6) for informational purposes.
     * For UBL CountrySubentity field, use sanitizeBucharestSector() which returns RO-B.
     *
     * @param  string  $address  The address to extract sector from
     * @return int|null The sector number (1-6) or null if not found
     */
    public static function extractBucharestSectorNumber(string $address): ?int
    {
        $normalized = self::normalizeInput($address);

        foreach (self::BUCHAREST_SECTOR_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $sectorNumber = (int) $matches[1];
                if ($sectorNumber >= 1 && $sectorNumber <= 6) {
                    return $sectorNumber;
                }
            }
        }

        return null;
    }

    /**
     * Check if county represents Bucharest.
     *
     * @param  string  $county  The county to check
     */
    public static function isBucharest(string $county): bool
    {
        $normalized = self::normalizeInput($county);

        // Check for Bucharest indicators (exact match to avoid false positives like "BUCEGI")
        $bucharestIndicators = [
            'BUCURESTI',
            'BUC',
            'MUNICIPIUL BUCURESTI',
            'RO-B',
            'B',
        ];

        foreach ($bucharestIndicators as $indicator) {
            if ($normalized === $indicator) {
                return true;
            }
        }

        // Check for Bucharest sector patterns (Sector 1-6)
        // This allows county fields containing "Sector 1", "Sectorul 2", etc.
        foreach (self::BUCHAREST_SECTOR_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $sectorNumber = (int) $matches[1];
                if ($sectorNumber >= 1 && $sectorNumber <= 6) {
                    return true;
                }
            }
        }

        // Also check if the sanitized county returns Bucharest code
        $code = self::sanitizeCounty($county);

        return $code === 'RO-B';
    }

    /**
     * Normalize input string for comparison.
     *
     * @param  string  $input  The input to normalize
     */
    private static function normalizeInput(string $input): string
    {
        // Trim whitespace
        $normalized = trim($input);

        // Convert to uppercase
        $normalized = mb_strtoupper($normalized, 'UTF-8');

        // Replace diacritics
        $normalized = self::normalizeDiacritics($normalized);

        // Normalize separators (replace multiple spaces with single space)
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

        return $normalized;
    }

    /**
     * Strip common administrative prefixes from county name.
     *
     * @param  string  $normalized  The normalized county name
     */
    private static function stripAdministrativePrefixes(string $normalized): string
    {
        $prefixes = [
            'JUDETUL ',
            'JUD. ',
            'JUD ',
            'MUNICIPIUL ',
            'MUN. ',
            'MUN ',
            'ORAS ',
            'OR. ',
            'COMUNA ',
            'COM. ',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return substr($normalized, strlen($prefix));
            }
        }

        return $normalized;
    }

    /**
     * Get all valid ISO 3166-2:RO county codes.
     *
     * @return array<string> Array of valid codes
     */
    public static function getValidCountyCodes(): array
    {
        return array_unique(array_values(self::RO_ISO_3166_2_RO_MAP));
    }

    /**
     * Check if a code is a valid ISO 3166-2:RO county code.
     *
     * @param  string  $code  The code to validate
     */
    public static function isValidCountyCode(string $code): bool
    {
        return in_array($code, self::getValidCountyCodes(), true);
    }
}
