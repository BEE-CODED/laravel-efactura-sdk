<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * Why a company lookup failed after the request was made.
 *
 * Null on a failure the SDK refused before sending (no or invalid codes, a batch
 * that is too large, a payload it could not encode).
 */
enum CompanyLookupFailureKind: string
{
    /** ANAF answered, but the answer is unreadable or does not confirm the request */
    case InvalidResponse = 'invalid_response';

    /** No usable answer: a transport failure or an ANAF error status */
    case Transport = 'transport';
}
