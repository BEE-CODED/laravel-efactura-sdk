<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Enums;

/**
 * Document standards for validation and PDF conversion.
 */
enum DocumentStandardType: string
{
    /** Standard invoice format */
    case FACT1 = 'FACT1';

    /** Credit note format */
    case FCN = 'FCN';
}
