<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services;

use BeeCoded\EFacturaSdk\Builders\InvoiceBuilder;
use BeeCoded\EFacturaSdk\Contracts\UblBuilderInterface;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;

/**
 * UBL XML Builder Service.
 *
 * A thin wrapper around InvoiceBuilder that provides a clean interface
 * for generating UBL 2.1 XML invoices compliant with Romanian CIUS-RO
 * specification for ANAF e-Factura.
 */
class UblBuilder implements UblBuilderInterface
{
    /**
     * The invoice builder instance.
     */
    private readonly InvoiceBuilder $invoiceBuilder;

    /**
     * Create a new UblBuilder instance.
     */
    public function __construct(?InvoiceBuilder $invoiceBuilder = null)
    {
        $this->invoiceBuilder = $invoiceBuilder ?? new InvoiceBuilder;
    }

    /**
     * Generate a UBL 2.1 XML invoice document.
     *
     * @param  InvoiceData  $invoiceData  The invoice data to serialize
     * @return string The generated XML string
     *
     * @throws ValidationException If invoice data is invalid or XML generation fails
     */
    public function generateInvoiceXml(InvoiceData $invoiceData): string
    {
        try {
            return $this->invoiceBuilder->buildInvoiceXml($invoiceData);
        } catch (ValidationException $e) {
            // Re-throw validation exceptions as-is
            throw $e;
        } catch (\Throwable $e) {
            throw new ValidationException(
                "Failed to generate invoice XML: {$e->getMessage()}",
                previous: $e
            );
        }
    }
}
