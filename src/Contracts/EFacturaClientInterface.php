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
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
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
 * - Auto-refresh occurs when tokens are about to expire: at least 120 seconds before, more when
 *   http.timeout, retry_times or retry_delay make one call's retries outlast that
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
     * @return UploadResponseData Contains the upload index whenever ANAF took the document in
     *
     * @throws ValidationException When XML content is empty or whitespace only
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     * @throws RateLimitExceededException When an SDK rate limit is exhausted (not an ApiException: catch it first)
     */
    public function uploadDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData;

    /**
     * Upload a B2C document to ANAF e-Factura system.
     *
     * Used for invoices to consumers (not registered businesses).
     *
     * @param  string  $xml  The UBL 2.1 XML invoice content
     * @param  UploadOptionsData|null  $options  Optional upload options
     * @return UploadResponseData Contains the upload index whenever ANAF took the document in
     *
     * @throws ValidationException When XML content is empty or whitespace only
     * @throws AuthenticationException When authentication fails
     * @throws ApiException When API call fails
     * @throws RateLimitExceededException When an SDK rate limit is exhausted (not an ApiException: catch it first)
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
     * @throws RateLimitExceededException When an SDK rate limit is exhausted (not an ApiException: catch it first)
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
     * @throws RateLimitExceededException When an SDK rate limit is exhausted (not an ApiException: catch it first)
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
     * @throws ApiException When API call fails, or the 2xx body is not JSON or is a bare JSON scalar
     * @throws RateLimitExceededException When an SDK rate limit is exhausted (not an ApiException: catch it first)
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
     * @throws ApiException When API call fails, or the 2xx body is not JSON or is a bare JSON scalar
     * @throws RateLimitExceededException When an SDK rate limit is exhausted (not an ApiException: catch it first)
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
     * @throws ValidationException When XML content is empty or endpoints.services.validate is not configured
     * @throws AuthenticationException When the service URL is on the e-Factura API host and authentication fails
     * @throws ApiException When the call fails, or the 2xx body is not JSON or is a bare JSON scalar
     * @throws RateLimitExceededException When an SDK rate limit is exhausted (not an ApiException: catch it first)
     */
    public function validateXml(string $xml, DocumentStandardType $standard): ValidationResultData;

    /**
     * Verify the Ministry of Finance signature on an invoice (ANAF's validate/signature service).
     *
     * Both files come from the archive downloadDocument() returns.
     * ANAF documents no answer, so it is read like a validation answer.
     * A JSON object or array in another shape reads as valid: false (not proof of a bad signature);
     * when nothing in it is read, details holds its first 500 bytes.
     * A 2xx body that is not JSON, or is a bare JSON scalar, throws ApiException
     * (ANAF's statusCode, typically 200), as validateXml() does.
     *
     * @param  string  $xml  The invoice XML
     * @param  string  $signature  The XML holding the signature applied to that invoice
     * @return ValidationResultData Contains signature verification result
     *
     * @throws ValidationException When either XML is empty or endpoints.services.verify_signature is not configured
     * @throws AuthenticationException When the service URL is on the e-Factura API host and authentication fails
     * @throws ApiException When the call fails, or the 2xx body is not JSON or is a bare JSON scalar
     * @throws RateLimitExceededException When an SDK rate limit is exhausted (not an ApiException: catch it first)
     */
    public function verifySignature(string $xml, string $signature): ValidationResultData;

    /**
     * Convert XML invoice to PDF format.
     *
     * Transforms the UBL XML invoice into a human-readable PDF document.
     * ANAF validates the XML first only when $validate is true; the default posts to {standard}/DA,
     * which skips validation.
     *
     * @param  string  $xml  The XML content to convert
     * @param  DocumentStandardType  $standard  Document standard (FACT1 for invoice, FCN for credit note)
     * @param  bool  $validate  Whether to validate before conversion (default: false)
     * @return string Binary PDF content
     *
     * @throws ValidationException When XML content is empty or endpoints.services.transform is not configured
     * @throws AuthenticationException When the service URL is on the e-Factura API host and authentication fails
     * @throws ApiException When ANAF refuses the document (a JSON error), answers with something other than a PDF, or the call fails
     * @throws RateLimitExceededException When an SDK rate limit is exhausted (not an ApiException: catch it first)
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
