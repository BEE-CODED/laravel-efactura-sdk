<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Contracts;

interface EFacturaClientInterface
{
    /**
     * Upload an invoice to the e-Factura system.
     *
     * @param  string  $xml  The UBL 2.1 XML invoice content
     * @param  string  $cif  The fiscal identification code (CIF) of the issuer
     * @return array<string, mixed>
     */
    public function uploadInvoice(string $xml, string $cif): array;

    /**
     * Get the status of an uploaded invoice.
     *
     * @param  string  $uploadIndex  The upload index returned from uploadInvoice
     * @return array<string, mixed>
     */
    public function getInvoiceStatus(string $uploadIndex): array;

    /**
     * Download an invoice response/message from ANAF.
     *
     * @param  string  $downloadId  The download ID from the message list
     * @return array<string, mixed>
     */
    public function downloadInvoice(string $downloadId): array;

    /**
     * Get the list of messages (invoices) from ANAF.
     *
     * @param  string  $cif  The fiscal identification code
     * @param  int  $days  Number of days to look back (max 60)
     * @return array<string, mixed>
     */
    public function getMessages(string $cif, int $days = 60): array;
}
