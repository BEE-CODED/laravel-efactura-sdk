<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * Tax Category identifiers for VAT classification.
 *
 * O, S and Z are inferred from the supplier's VAT status and the line rate. AE, E, G
 * and K are opt-in: a line declares them through InvoiceLineData::$taxCategoryId, and
 * they always carry rate 0 and tax 0.
 */
enum TaxCategoryId: string
{
    /** Not subject to VAT */
    case NotSubject = 'O';

    /** Standard rated VAT */
    case Standard = 'S';

    /** Zero-rated VAT */
    case ZeroRated = 'Z';

    /** VAT accounted for by the recipient */
    case ReverseCharge = 'AE';

    /** Exempt from VAT; needs an exemption reason code or text (BR-E-10) */
    case Exempt = 'E';

    /** Free export item, VAT not charged (VATEX-EU-G) */
    case Export = 'G';

    /** VAT exempt for EEA intra-community supply of goods and services (VATEX-EU-IC) */
    case IntraCommunitySupply = 'K';
}
