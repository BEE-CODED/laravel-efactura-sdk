<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Facades;

use Beecoded\EFactura\Contracts\UblBuilderInterface;
use Beecoded\EFactura\Data\Invoice\InvoiceData;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for UBL 2.1 invoice XML generation.
 *
 * @method static string generateInvoiceXml(InvoiceData $invoiceData) Generate UBL 2.1 compliant XML from invoice data
 *
 * @see \Beecoded\EFactura\Services\UblBuilder
 * @see \Beecoded\EFactura\Builders\InvoiceBuilder
 */
final class UblBuilder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return UblBuilderInterface::class;
    }
}
