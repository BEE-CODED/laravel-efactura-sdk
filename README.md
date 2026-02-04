# Laravel e-Factura SDK

A Laravel package for integrating with Romania's ANAF e-Factura (electronic invoicing) system.

## Features

- **OAuth 2.0 Authentication** - Complete OAuth flow with automatic token refresh
- **Document Operations** - Upload, download, and check status of invoices
- **UBL 2.1 XML Generation** - Generate CIUS-RO compliant invoice XML
- **Company Lookup** - Query ANAF for company details (VAT status, addresses, etc.)
- **Validation** - Validate XML against ANAF schemas before upload
- **PDF Conversion** - Convert XML invoices to PDF format
- **Rate Limiting** - Built-in protection against exceeding ANAF API quotas

## Requirements

- PHP 8.2+
- Laravel 11.0+
- Valid ANAF OAuth credentials

## Installation

```bash
composer require beecoded/laravel-efactura-sdk
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=efactura-config
```

## Configuration

Add the following to your `.env` file:

```env
EFACTURA_SANDBOX=true
EFACTURA_CLIENT_ID=your-client-id
EFACTURA_CLIENT_SECRET=your-client-secret
EFACTURA_REDIRECT_URI=https://your-app.com/efactura/callback
```

### Configuration Options

```php
// config/efactura.php
return [
    'sandbox' => env('EFACTURA_SANDBOX', true),

    'oauth' => [
        'client_id' => env('EFACTURA_CLIENT_ID'),
        'client_secret' => env('EFACTURA_CLIENT_SECRET'),
        'redirect_uri' => env('EFACTURA_REDIRECT_URI'),
    ],

    'http' => [
        'timeout' => env('EFACTURA_TIMEOUT', 30),
        'retry_times' => env('EFACTURA_RETRY_TIMES', 3),
        'retry_delay' => env('EFACTURA_RETRY_DELAY', 5),
    ],

    'logging' => [
        'channel' => env('EFACTURA_LOG_CHANNEL', 'efactura'),
    ],
];
```

### Logging Channel (Recommended)

Add a dedicated logging channel in `config/logging.php`:

```php
'efactura' => [
    'driver' => 'daily',
    'path' => storage_path('logs/efactura.log'),
    'level' => 'debug',
    'days' => 30,
],
```

### Rate Limiting Configuration

The SDK includes built-in rate limiting to prevent exceeding ANAF API quotas. All defaults are set to **50% of ANAF's actual limits** for safety.

```env
# Enable/disable rate limiting (default: true)
EFACTURA_RATE_LIMIT_ENABLED=true

# Global API calls per minute (ANAF limit: 1000, default: 500)
EFACTURA_RATE_LIMIT_GLOBAL=500

# RASP file uploads per CUI per day (ANAF limit: 1000, default: 500)
EFACTURA_RATE_LIMIT_RASP_UPLOAD=500

# Status queries per message per day (ANAF limit: 100, default: 50)
EFACTURA_RATE_LIMIT_STATUS=50

# Simple list queries per CUI per day (ANAF limit: 1500, default: 750)
EFACTURA_RATE_LIMIT_SIMPLE_LIST=750

# Paginated list queries per CUI per day (ANAF limit: 100,000, default: 50,000)
EFACTURA_RATE_LIMIT_PAGINATED_LIST=50000

# Downloads per message per day (ANAF limit: 10, default: 5)
EFACTURA_RATE_LIMIT_DOWNLOAD=5
```

**ANAF Official Rate Limits:**

| Endpoint | ANAF Limit | SDK Default | Scope |
|----------|------------|-------------|-------|
| Global (all methods) | 1,000/minute | 500/minute | All API calls |
| `/upload` (RASP) | 1,000/day | 500/day | Per CUI |
| `/stare` (status) | 100/day | 50/day | Per message ID |
| `/lista` (simple) | 1,500/day | 750/day | Per CUI |
| `/lista` (paginated) | 100,000/day | 50,000/day | Per CUI |
| `/descarcare` (download) | 10/day | 5/day | Per message ID |

## Usage

### OAuth Authentication Flow

The SDK provides a stateless OAuth implementation. **You are responsible for storing tokens** in your database.

#### Step 1: Redirect User to ANAF Authorization

```php
use Beecoded\EFactura\Facades\EFactura;

// Generate authorization URL
$authUrl = EFactura::getAuthorizationUrl();

// Or with custom state data
$authUrl = EFactura::getAuthorizationUrl(new AuthUrlSettingsData(
    state: ['company_id' => 123, 'user_id' => 456],
    scope: 'custom-scope',
));

return redirect($authUrl);
```

#### Step 2: Handle OAuth Callback

```php
use Beecoded\EFactura\Facades\EFactura;

public function handleCallback(Request $request)
{
    $code = $request->get('code');

    // Exchange authorization code for tokens
    $tokens = EFactura::exchangeCodeForToken($code);

    // Store tokens in YOUR database
    YourTokenModel::create([
        'company_id' => $companyId,
        'access_token' => $tokens->accessToken,
        'refresh_token' => $tokens->refreshToken,
        'expires_at' => $tokens->expiresAt,
    ]);
}
```

#### Manual Token Refresh

```php
use Beecoded\EFactura\Facades\EFactura;

$newTokens = EFactura::refreshAccessToken($storedRefreshToken);

// Update stored tokens
$tokenModel->update([
    'access_token' => $newTokens->accessToken,
    'refresh_token' => $newTokens->refreshToken,
    'expires_at' => $newTokens->expiresAt,
]);
```

### API Operations

#### Creating the Client

```php
use Beecoded\EFactura\Services\ApiClients\EFacturaClient;
use Beecoded\EFactura\Data\Auth\OAuthTokensData;

// Retrieve your stored tokens
$storedTokens = YourTokenModel::where('company_id', $companyId)->first();

// Create tokens DTO
$tokens = new OAuthTokensData(
    accessToken: $storedTokens->access_token,
    refreshToken: $storedTokens->refresh_token,
    expiresAt: $storedTokens->expires_at,
);

// Create client
$client = EFacturaClient::fromTokens($vatNumber, $tokens);
```

#### Upload Invoice

```php
use Beecoded\EFactura\Data\Invoice\UploadOptionsData;
use Beecoded\EFactura\Enums\StandardType;

// Basic upload
$result = $client->uploadDocument($xmlContent);

// With options
$result = $client->uploadDocument($xmlContent, new UploadOptionsData(
    standard: StandardType::UBL,
    extern: false,      // External invoice (non-Romanian supplier)
    selfBilled: false,  // Self-billed invoice (autofactura)
));

// B2C upload (to consumers)
$result = $client->uploadB2CDocument($xmlContent);

// Check result
if ($result->isSuccessful()) {
    $uploadId = $result->indexIncarcare;
    // Store uploadId for status checking
}
```

#### Check Processing Status

```php
$status = $client->getStatusMessage($uploadId);

if ($status->isReady()) {
    $downloadId = $status->idDescarcare;
    // Document is ready for download
} elseif ($status->isInProgress()) {
    // Still processing, check again later
} elseif ($status->isFailed()) {
    // Processing failed
    $errors = $status->errors;
}
```

#### Download Document

```php
$download = $client->downloadDocument($downloadId);

// Save to file
$download->saveTo('/path/to/invoice.zip');

// Or get content directly
$zipContent = $download->content;
$contentType = $download->contentType;
```

#### List Messages

```php
use Beecoded\EFactura\Data\Invoice\ListMessagesParamsData;
use Beecoded\EFactura\Enums\MessageFilter;

// List messages from last 30 days
$messages = $client->getMessages(new ListMessagesParamsData(
    cif: '12345678',
    days: 30,  // 1-60 days allowed
    filter: MessageFilter::InvoiceSent,  // Optional: T, P, E, R
));

foreach ($messages->mesaje as $message) {
    echo $message->id;
    echo $message->dataCreare;
    echo $message->tip;
}
```

#### Paginated Messages

```php
use Beecoded\EFactura\Data\Invoice\PaginatedMessagesParamsData;

// Using timestamps (milliseconds)
$messages = $client->getMessagesPaginated(new PaginatedMessagesParamsData(
    cif: '12345678',
    startTime: $startTimestampMs,
    endTime: $endTimestampMs,
    page: 1,
    filter: MessageFilter::InvoiceReceived,
));

// Or create from Carbon dates
$messages = $client->getMessagesPaginated(
    PaginatedMessagesParamsData::fromDateRange(
        cif: '12345678',
        startDate: now()->subDays(30),
        endDate: now(),
        page: 1,
    )
);

// Pagination info
$messages->totalPages;
$messages->totalRecords;
$messages->currentPage;
$messages->hasNextPage();
```

#### Validate XML

```php
use Beecoded\EFactura\Enums\DocumentStandardType;

$validation = $client->validateXml($xmlContent, DocumentStandardType::FACT1);

if ($validation->valid) {
    // XML is valid
} else {
    // Validation errors
    $errors = $validation->errors;
    $details = $validation->details;
}
```

#### Convert to PDF

```php
use Beecoded\EFactura\Enums\DocumentStandardType;

// Convert without validation
$pdfContent = $client->convertXmlToPdf($xmlContent, DocumentStandardType::FACT1);

// Convert with validation first
$pdfContent = $client->convertXmlToPdf($xmlContent, DocumentStandardType::FACT1, validate: true);

file_put_contents('invoice.pdf', $pdfContent);
```

#### Verify Signature

```php
$result = $client->verifySignature($signedXmlContent);

if ($result->valid) {
    // Signature is valid
}
```

### Automatic Token Refresh

The SDK automatically refreshes tokens when they're about to expire (30-second buffer before expiration).

**Important:** ANAF uses rotating refresh tokens. When a token is refreshed, both the access token AND refresh token are replaced. The old refresh token becomes invalid.

```php
$client = EFacturaClient::fromTokens($vatNumber, $tokens);

// Make API calls
$result = $client->uploadDocument($xml);
$status = $client->getStatusMessage($uploadId);

// IMPORTANT: Check if tokens were refreshed
if ($client->wasTokenRefreshed()) {
    $newTokens = $client->getTokens();

    // You MUST persist ALL new token values
    $storedTokens->update([
        'access_token' => $newTokens->accessToken,
        'refresh_token' => $newTokens->refreshToken,  // Critical! Old one is now invalid
        'expires_at' => $newTokens->expiresAt,
    ]);
}
```

**Recommended Pattern:**

```php
public function uploadInvoice(string $xml, Company $company): UploadResponseData
{
    $tokens = $this->getTokensForCompany($company);
    $client = EFacturaClient::fromTokens($company->vat_number, $tokens);

    try {
        $result = $client->uploadDocument($xml);

        return $result;
    } finally {
        // Always check for token refresh, even on errors
        if ($client->wasTokenRefreshed()) {
            $this->persistTokens($company, $client->getTokens());
        }
    }
}
```

### Rate Limiting

The SDK automatically enforces rate limits before each API call. When a limit is exceeded, a `RateLimitExceededException` is thrown.

```php
use Beecoded\EFactura\Exceptions\RateLimitExceededException;

try {
    $result = $client->uploadDocument($xml);
} catch (RateLimitExceededException $e) {
    // Rate limit exceeded
    $remaining = $e->remaining;              // 0 (no calls remaining)
    $retryAfter = $e->retryAfterSeconds;     // Seconds until reset
    $message = $e->getMessage();             // Human-readable message

    // Wait and retry, or queue for later
    Log::warning("Rate limit hit: {$message}. Retry in {$retryAfter}s");
}
```

#### Checking Remaining Quota

Before making API calls, you can check remaining quota:

```php
$rateLimiter = $client->getRateLimiter();

// Check global limit (per minute)
$globalQuota = $rateLimiter->getRemainingQuota('global');
// ['limit' => 500, 'remaining' => 485, 'resetsIn' => 45]  // seconds until reset

// Check per-CUI limits
$listQuota = $rateLimiter->getRemainingQuota('simple_list', $vatNumber);
// ['limit' => 750, 'remaining' => 742, 'resetsIn' => 43200]  // seconds until reset

// Check per-message limits
$statusQuota = $rateLimiter->getRemainingQuota('status', $uploadId);
// ['limit' => 50, 'remaining' => 48, 'resetsIn' => 86400]

$downloadQuota = $rateLimiter->getRemainingQuota('download', $downloadId);
// ['limit' => 5, 'remaining' => 3, 'resetsIn' => 86400]
```

#### Disabling Rate Limiting

For testing or special cases, you can disable rate limiting:

```env
EFACTURA_RATE_LIMIT_ENABLED=false
```

Or check status in code:

```php
$rateLimiter = app(\Beecoded\EFactura\Services\RateLimiter::class);

if ($rateLimiter->isEnabled()) {
    // Rate limiting is active
}
```

### Generating Invoice XML

#### Using the UBL Builder

```php
use Beecoded\EFactura\Facades\UblBuilder;
use Beecoded\EFactura\Data\Invoice\InvoiceData;
use Beecoded\EFactura\Data\Invoice\PartyData;
use Beecoded\EFactura\Data\Invoice\AddressData;
use Beecoded\EFactura\Data\Invoice\InvoiceLineData;

$invoice = new InvoiceData(
    invoiceNumber: 'INV-2024-001',
    issueDate: now(),
    dueDate: now()->addDays(30),
    currency: 'RON',
    paymentIban: 'RO49AAAA1B31007593840000',

    supplier: new PartyData(
        registrationName: 'Supplier Company SRL',
        companyId: 'RO12345678',
        address: new AddressData(
            street: 'Str. Exemplu Nr. 1',
            city: 'Bucuresti',
            postalZone: '010101',
            county: 'Sector 1',  // Auto-sanitized to RO-B format
        ),
        registrationNumber: 'J40/1234/2020',
        isVatPayer: true,
    ),

    customer: new PartyData(
        registrationName: 'Customer Company SRL',
        companyId: 'RO87654321',
        address: new AddressData(
            street: 'Str. Client Nr. 2',
            city: 'Cluj-Napoca',
            postalZone: '400001',
            county: 'Cluj',  // Auto-sanitized to RO-CJ
        ),
        isVatPayer: true,
    ),

    lines: [
        new InvoiceLineData(
            name: 'Servicii consultanta',
            quantity: 10,
            unitPrice: 100.00,
            taxPercent: 19,
            unitCode: 'HUR',  // Hours
            description: 'Consultanta IT luna ianuarie',
        ),
        new InvoiceLineData(
            name: 'Licenta software',
            quantity: 1,
            unitPrice: 500.00,
            taxPercent: 19,
            unitCode: 'C62',  // Each
        ),
    ],
);

// Generate UBL 2.1 XML
$xml = UblBuilder::generateInvoiceXml($invoice);
```

#### Invoice Calculations

```php
// Line-level calculations
$line = new InvoiceLineData(
    name: 'Product',
    quantity: 5,
    unitPrice: 100.00,
    taxPercent: 19,
);

$line->getLineTotal();        // 500.00 (quantity * unitPrice)
$line->getTaxAmount();        // 95.00 (lineTotal * taxPercent / 100)
$line->getLineTotalWithTax(); // 595.00

// Invoice-level calculations
$invoice->getTotalExcludingVat(); // Sum of all line totals
$invoice->getTotalVat();          // Sum of all tax amounts
$invoice->getTotalIncludingVat(); // Total with VAT
```

#### Address Sanitization

Romanian addresses are automatically sanitized to ISO 3166-2:RO format:

```php
// County names are normalized
'Cluj' -> 'RO-CJ'
'Judetul Cluj' -> 'RO-CJ'
'BUCURESTI' -> 'RO-B'

// Bucharest sectors are extracted
'Sector 3' -> 'RO-B' (with sector in address)
'Sectorul 1, Str. Exemplu' -> extracts sector

// Diacritics are handled
'Brașov' -> 'RO-BV'
'Constanța' -> 'RO-CT'
```

### Company Lookup

Query ANAF for company information (no authentication required):

```php
use Beecoded\EFactura\Facades\AnafDetails;

// Single company lookup
$result = AnafDetails::getCompanyData('RO12345678');

if ($result->success) {
    $company = $result->first();

    echo $company->name;              // Company name
    echo $company->cui;               // CUI without RO prefix
    echo $company->getVatNumber();    // CUI with RO prefix
    echo $company->address;           // General address
    echo $company->registrationNumber; // J40/1234/2020

    // VAT status
    $company->isVatPayer;
    $company->vatRegistrationDate;
    $company->vatDeregistrationDate;

    // Special regimes
    $company->isSplitVat;      // Split VAT payment
    $company->isRtvai;         // VAT on collection

    // Status
    $company->isActive();      // Not inactive and not deregistered
    $company->isInactive;
    $company->isDeregistered;

    // Detailed addresses
    $company->headquartersAddress;     // AddressData object
    $company->fiscalDomicileAddress;   // AddressData object
    $company->getPrimaryAddress();     // Returns headquarters or fiscal
}

// Batch lookup (up to 500 companies)
$result = AnafDetails::batchGetCompanyData([
    'RO12345678',
    'RO87654321',
    '11223344',  // RO prefix is optional
]);

foreach ($result->companies as $company) {
    // Process each company
}

// Check for not found
foreach ($result->notFound as $cui) {
    echo "Company not found: $cui";
}

// Validate VAT code format
$isValid = AnafDetails::isValidVatCode('RO12345678'); // true
```

### Validators

#### VAT Number Validation

```php
use Beecoded\EFactura\Support\Validators\VatNumberValidator;

VatNumberValidator::isValid('RO12345678');  // true
VatNumberValidator::isValid('12345678');    // true (2-10 digits)
VatNumberValidator::isValid('invalid');     // false

VatNumberValidator::normalize('12345678');  // 'RO12345678'
VatNumberValidator::stripPrefix('RO12345678'); // '12345678'
```

#### CNP Validation

```php
use Beecoded\EFactura\Support\Validators\CnpValidator;

CnpValidator::isValid('1234567890123'); // Validates checksum
CnpValidator::isValid('0000000000000'); // true (special ANAF case)
```

### Date Helpers

```php
use Beecoded\EFactura\Support\DateHelper;

// Format for ANAF API
DateHelper::formatForAnaf(now());           // '2024-01-15'
DateHelper::formatForAnaf('2024-01-15');    // '2024-01-15'

// Timestamps in milliseconds (for paginated messages)
DateHelper::toTimestamp(now());             // 1705312800000

// Day range for queries
[$start, $end] = DateHelper::getDayRange('2024-01-15');
// $start = 1705269600000 (00:00:00.000)
// $end = 1705355999999 (23:59:59.999)

// Validate days parameter
DateHelper::isValidDaysParameter(30);  // true (1-60 allowed)
DateHelper::isValidDaysParameter(100); // false
```

## Enums

### StandardType
```php
StandardType::UBL   // 'UBL' - UBL 2.1 format
StandardType::CN    // 'CN' - Credit Note
StandardType::CII   // 'CII' - Cross Industry Invoice
StandardType::RASP  // 'RASP' - Response
```

### DocumentStandardType
```php
DocumentStandardType::FACT1  // 'FACT1' - Invoice
DocumentStandardType::FCN    // 'FCN' - Credit Note
```

### MessageFilter
```php
MessageFilter::InvoiceSent     // 'T' - Sent invoices
MessageFilter::InvoiceReceived // 'P' - Received invoices
MessageFilter::InvoiceErrors   // 'E' - Errors
MessageFilter::BuyerMessage    // 'R' - Buyer messages
```

### InvoiceTypeCode
```php
InvoiceTypeCode::Invoice    // '380' - Standard invoice
InvoiceTypeCode::CreditNote // '381' - Credit note
InvoiceTypeCode::DebitNote  // '383' - Debit note
```

## Exception Handling

```php
use Beecoded\EFactura\Exceptions\AuthenticationException;
use Beecoded\EFactura\Exceptions\ValidationException;
use Beecoded\EFactura\Exceptions\ApiException;
use Beecoded\EFactura\Exceptions\RateLimitExceededException;
use Beecoded\EFactura\Exceptions\XmlParsingException;

try {
    $result = $client->uploadDocument($xml);
} catch (AuthenticationException $e) {
    // OAuth token invalid or expired (and refresh failed)
    // User needs to re-authenticate
} catch (RateLimitExceededException $e) {
    // Rate limit exceeded
    $retryAfter = $e->retryAfterSeconds;  // Seconds until limit resets
    // Queue for later or wait
} catch (ValidationException $e) {
    // Input validation failed (empty XML, invalid parameters)
    $message = $e->getMessage();
} catch (ApiException $e) {
    // API call failed
    $statusCode = $e->statusCode;
    $details = $e->details;
} catch (XmlParsingException $e) {
    // Failed to parse XML response from ANAF
}
```

## Testing

When testing your application, you can mock the SDK services:

```php
use Beecoded\EFactura\Contracts\AnafAuthenticatorInterface;
use Beecoded\EFactura\Contracts\AnafDetailsClientInterface;

// In your test
$this->mock(AnafAuthenticatorInterface::class, function ($mock) {
    $mock->shouldReceive('exchangeCodeForToken')
        ->andReturn(new OAuthTokensData(
            accessToken: 'test-token',
            refreshToken: 'test-refresh',
            expiresAt: now()->addHour(),
        ));
});
```

## License

MIT License. See [LICENSE](LICENSE) for details.
