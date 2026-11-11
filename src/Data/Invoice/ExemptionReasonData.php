<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;
use Spatie\LaravelData\Data;

/**
 * VAT exemption reason for one VAT category: code (BT-121) and text (BT-120, at most 100
 * characters).
 *
 * Only E, G, K and AE take a reason. G, K and AE always file their fixed code (VATEX-EU-G,
 * VATEX-EU-IC, VATEX-EU-AE), so only the text is the caller's; AE's text defaults to
 * "Taxare inversa". E needs a VATEX code (BR-CL-22) and/or a text (BR-E-10).
 */
class ExemptionReasonData extends Data
{
    public function __construct(
        public TaxCategoryId $category,
        public ?string $code = null,
        public ?string $text = null,
    ) {}
}
