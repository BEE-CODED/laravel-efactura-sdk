<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Facades;

use BeeCoded\EFactura\Contracts\UblBuilderInterface;
use BeeCoded\EFactura\Data\Invoice\InvoiceData;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for UBL 2.1 invoice XML generation.
 *
 * @method static string generateInvoiceXml(InvoiceData $invoiceData) Generate UBL 2.1 compliant XML from invoice data
 *
 * @see \BeeCoded\EFactura\Services\UblBuilder
 * @see \BeeCoded\EFactura\Builders\InvoiceBuilder
 */
final class UblBuilder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return UblBuilderInterface::class;
    }
}
