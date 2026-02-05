<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Contracts;

use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\PaginatedMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\UploadOptionsData;
use BeeCoded\EFacturaSdk\Data\Response\DownloadResponseData;
use BeeCoded\EFacturaSdk\Data\Response\ListMessagesResponseData;
use BeeCoded\EFacturaSdk\Data\Response\PaginatedMessagesResponseData;
use BeeCoded\EFacturaSdk\Data\Response\StatusResponseData;
use BeeCoded\EFacturaSdk\Data\Response\UploadResponseData;
use BeeCoded\EFacturaSdk\Data\Response\ValidationResultData;
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;

/**
 * Interface for ANAF e-Factura API client.
 *
 * This interface defines all operations available for interacting with the
 * ANAF e-Factura (electronic invoicing) system. The client is stateless
 * and requires tokens to be passed in at construction time.
 *
 * Token Management:
 * - Tokens are passed in via constructor (stateless design)
 * - Auto-refresh occurs when tokens are expired (with 120-second buffer)
 * - Use wasTokenRefreshed() to check if tokens were refreshed
 * - Use getTokens() to get the current (potentially refreshed) tokens
 */
interface EFacturaClientInterface
{
    /**
     * Upload a B2B document to ANAF e-Factura system.
     *
     * @param  string  $xml  The UBL 2.1 XML invoice content
     * @param  UploadOptionsData|null  $options  Optional upload options (standard, extern, selfBilled, executare)
     * @return UploadResponseData Contains upload ID on success
     *
     * @throws ValidationException When XML content is empty or invalid
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    public function uploadDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData;

    /**
     * Upload a B2C document to ANAF e-Factura system.
     *
     * Used for invoices to consumers (not registered businesses).
     *
     * @param  string  $xml  The UBL 2.1 XML invoice content
     * @param  UploadOptionsData|null  $options  Optional upload options
     * @return UploadResponseData Contains upload ID on success
     *
     * @throws ValidationException When XML content is empty or invalid
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    public function uploadB2CDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData;

    /**
     * Get the processing status of an uploaded document.
     *
     * After uploading, documents are processed asynchronously. Use this method
     * to check if processing is complete and get the download ID.
     *
     * @param  string  $uploadId  The upload index returned from uploadDocument/uploadB2CDocument
     * @return StatusResponseData Contains processing status and download ID when ready
     *
     * @throws ValidationException When upload ID is empty or invalid
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    public function getStatusMessage(string $uploadId): StatusResponseData;

    /**
     * Download a document from ANAF.
     *
     * Downloads the ZIP archive containing the processed invoice or error response.
     *
     * @param  string  $downloadId  The download ID from status response or message list
     * @return DownloadResponseData Contains binary ZIP content
     *
     * @throws ValidationException When download ID is empty or invalid
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    public function downloadDocument(string $downloadId): DownloadResponseData;

    /**
     * Get list of messages (invoices) from ANAF.
     *
     * Retrieves messages for the specified CIF within the given number of days.
     * Maximum 60 days lookback.
     *
     * @param  ListMessagesParamsData  $params  Parameters including CIF, days, and optional filter
     * @return ListMessagesResponseData Contains array of message details
     *
     * @throws ValidationException When parameters are invalid
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    public function getMessages(ListMessagesParamsData $params): ListMessagesResponseData;

    /**
     * Get paginated list of messages from ANAF.
     *
     * Retrieves messages for the specified CIF within a time range with pagination.
     *
     * @param  PaginatedMessagesParamsData  $params  Parameters including CIF, time range, page, and optional filter
     * @return PaginatedMessagesResponseData Contains paginated array of message details
     *
     * @throws ValidationException When parameters are invalid
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    public function getMessagesPaginated(PaginatedMessagesParamsData $params): PaginatedMessagesResponseData;

    /**
     * Validate XML document against ANAF schema.
     *
     * Validates the XML structure without uploading to the system.
     *
     * @param  string  $xml  The XML content to validate
     * @param  DocumentStandardType  $standard  Document standard (FACT1 for invoice, FCN for credit note)
     * @return ValidationResultData Contains validation result and any errors
     *
     * @throws ValidationException When XML content is empty
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    public function validateXml(string $xml, DocumentStandardType $standard): ValidationResultData;

    /**
     * Verify the digital signature of an XML document.
     *
     * @param  string  $xml  The signed XML content to verify
     * @return ValidationResultData Contains signature verification result
     *
     * @throws ValidationException When XML content is empty
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    public function verifySignature(string $xml): ValidationResultData;

    /**
     * Convert XML invoice to PDF format.
     *
     * Transforms the UBL XML invoice into a human-readable PDF document.
     * Includes validation before conversion.
     *
     * @param  string  $xml  The XML content to convert
     * @param  DocumentStandardType  $standard  Document standard (FACT1 for invoice, FCN for credit note)
     * @param  bool  $validate  Whether to validate before conversion (default: false)
     * @return string Binary PDF content
     *
     * @throws ValidationException When XML content is empty or validation fails
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     */
    public function convertXmlToPdf(string $xml, DocumentStandardType $standard, bool $validate = false): string;

    /**
     * Check if the access token was refreshed during API operations.
     *
     * Use this after API calls to determine if tokens need to be persisted.
     */
    public function wasTokenRefreshed(): bool;

    /**
     * Get the current OAuth tokens (may be refreshed from original).
     *
     * Call this after API operations to get potentially updated tokens.
     */
    public function getTokens(): OAuthTokensData;

    /**
     * Get the VAT number (CIF) associated with this client instance.
     */
    public function getVatNumber(): string;
}
