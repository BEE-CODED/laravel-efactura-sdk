<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * Company registration status at the trade registry, derived from ANAF's
 * date_generale.stare_inregistrare free-text field.
 *
 * ANAF does not document the full value set; observed values are
 * "INREGISTRAT din data dd.mm.yyyy" and "RADIERE din data dd.mm.yyyy".
 * Anything unrecognized maps to Unknown — consumers must treat Unknown
 * as "no verdict" (fail-open), never as deregistered.
 */
enum RegistrationStatus: string
{
    case Registered = 'registered';
    case Deregistered = 'deregistered';
    case Unknown = 'unknown';

    /**
     * Parse ANAF's stare_inregistrare string into a status.
     */
    public static function fromAnafStatus(?string $status): self
    {
        $normalized = mb_strtoupper(trim((string) $status));

        return match (true) {
            str_starts_with($normalized, 'INREGISTRAT'),
            str_starts_with($normalized, 'ÎNREGISTRAT') => self::Registered,
            str_starts_with($normalized, 'RADIERE'),
            str_starts_with($normalized, 'RADIAT') => self::Deregistered,
            default => self::Unknown,
        };
    }
}
