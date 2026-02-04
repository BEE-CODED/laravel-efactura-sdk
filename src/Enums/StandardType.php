<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Enums;

/**
 * Standard document types supported by ANAF e-Factura.
 */
enum StandardType: string
{
    /** Universal Business Language format */
    case UBL = 'UBL';

    /** Credit Note format */
    case CN = 'CN';

    /** Cross Industry Invoice format */
    case CII = 'CII';

    /** Response message format */
    case RASP = 'RASP';
}
