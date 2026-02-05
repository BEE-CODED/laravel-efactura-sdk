<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Enums;

/**
 * UBL Invoice and Credit Note type codes valid for Romanian e-Factura (BR-RO-020).
 *
 * For Invoice documents: 380, 384, 389, 751
 * For CreditNote documents: 381
 *
 * @see https://github.com/OpenPEPPOL/peppol-bis-invoice-3/blob/master/guide/transaction-spec/codes/invoice-types-en.adoc
 */
enum InvoiceTypeCode: string
{
    /**
     * Commercial invoice (380).
     * Document/message claiming payment for goods or services supplied under conditions
     * agreed between seller and buyer.
     */
    case CommercialInvoice = '380';

    /**
     * Credit note (381).
     * Document used to correct amounts or settle balances between a Supplier and a Buyer.
     * Generates a UBL CreditNote document (not Invoice).
     */
    case CreditNote = '381';

    /**
     * Corrected invoice (384).
     * An invoice that corrects a previously issued invoice.
     */
    case CorrectedInvoice = '384';

    /**
     * Self-billed invoice (389).
     * An invoice created by the buyer on behalf of the supplier.
     */
    case SelfBilledInvoice = '389';

    /**
     * Invoice for accounting purposes (751).
     * An invoice issued for accounting/information purposes only.
     */
    case AccountingInvoice = '751';

    /**
     * Check if this type code generates a CreditNote document.
     */
    public function isCreditNote(): bool
    {
        return $this === self::CreditNote;
    }

    /**
     * Check if this type code generates an Invoice document.
     */
    public function isInvoice(): bool
    {
        return ! $this->isCreditNote();
    }
}
