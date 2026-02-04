<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Contracts;

use Beecoded\EFactura\Data\Invoice\InvoiceData;

/**
 * Interface for UBL XML generation.
 *
 * Defines the contract for generating UBL 2.1 XML invoices
 * compliant with Romanian CIUS-RO specification for ANAF e-Factura.
 */
interface UblBuilderInterface
{
    /**
     * Generate a UBL 2.1 XML invoice document.
     *
     * @param  InvoiceData  $invoiceData  The invoice data to serialize
     * @return string The generated XML string
     *
     * @throws \Beecoded\EFactura\Exceptions\ValidationException If invoice data is invalid
     */
    public function generateInvoiceXml(InvoiceData $invoiceData): string;
}
