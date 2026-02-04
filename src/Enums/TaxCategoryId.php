<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * Tax Category identifiers for VAT classification.
 */
enum TaxCategoryId: string
{
    /** Not subject to VAT */
    case NotSubject = 'O';

    /** Standard rated VAT */
    case Standard = 'S';

    /** Zero-rated VAT */
    case ZeroRated = 'Z';
}
