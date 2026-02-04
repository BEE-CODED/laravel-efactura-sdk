<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Services;

use Beecoded\EFactura\Contracts\EFacturaClientInterface;

class EFacturaClient implements EFacturaClientInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config
    ) {}

    /**
     * {@inheritDoc}
     */
    public function uploadInvoice(string $xml, string $cif): array
    {
        // TODO: Implement OAuth2 authentication and API call
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getInvoiceStatus(string $uploadIndex): array
    {
        // TODO: Implement API call
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function downloadInvoice(string $downloadId): array
    {
        // TODO: Implement API call
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getMessages(string $cif, int $days = 60): array
    {
        // TODO: Implement API call
        return [];
    }

    /**
     * Get the base URL for the API based on environment.
     */
    protected function getBaseUrl(): string
    {
        return $this->config['sandbox']
            ? 'https://api.anaf.ro/test/FCTEL/rest'
            : 'https://api.anaf.ro/prod/FCTEL/rest';
    }
}
