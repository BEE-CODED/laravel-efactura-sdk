<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Enums;

/**
 * The type of invoice. Usually invoices are sent for commercial purposes.
 *
 * @see https://github.com/OpenPEPPOL/peppol-bis-invoice-3/blob/master/guide/transaction-spec/codes/invoice-types-en.adoc
 */
enum InvoiceTypeCode: string
{
    /**
     * Commercial invoice.
     * Document/message claiming payment for goods or services supplied under conditions
     * agreed between seller and buyer.
     */
    case CommercialInvoice = '380';

    /**
     * Invoice information for accounting purposes.
     * A document/message containing accounting related information such as monetary summations,
     * seller id and VAT information. This may not be a complete invoice according to legal requirements.
     * For instance the line item information might be excluded.
     */
    case InvoiceInformationForAccountingPurposes = '751';

    /**
     * General Credit Note.
     * The credit note is used to correct amounts or settle balances between a Supplier to a Buyer.
     * You are not required to provide a reference to the previous invoice. However, we recommend that you do so.
     */
    case CreditNote = '381';
}
