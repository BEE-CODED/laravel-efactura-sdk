<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Enums;

/**
 * Message filters for listing operations.
 * Each filter type represents a specific message category in the ANAF e-Factura system.
 */
enum MessageFilter: string
{
    /** FACTURA TRIMISA - Invoice sent by you to a buyer */
    case InvoiceSent = 'T';

    /** FACTURA PRIMITA - Invoice received by you from a supplier */
    case InvoiceReceived = 'P';

    /** ERORI FACTURA - Error messages returned after uploading invalid XML */
    case InvoiceErrors = 'E';

    /** MESAJ CUMPARATOR - RASP message/comment from buyer to issuer (or vice versa) */
    case BuyerMessage = 'R';
}
