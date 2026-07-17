export const apiReferenceContent: Record<string, string> = {
  EFacturaClient: `# EFacturaClient

**Namespace:** \`BeeCoded\\EFacturaSdk\\Services\\ApiClients\\EFacturaClient\`
**Implements:** \`EFacturaClientInterface\`
**Facade:** None — instantiated directly

## Constructor

\`\`\`php
public function __construct(
    string $vatNumber,
    string $accessToken,
    string $refreshToken,
    ?CarbonInterface $expiresAt = null,
    ?AnafAuthenticatorInterface $authenticator = null,
    ?Closure $tokenReloader = null,          // added in v3.0.0
)
\`\`\`

\`$expiresAt\` accepts any \`Carbon\\CarbonInterface\` implementation (since v2.3.0), so a
\`CarbonImmutable\` from \`Date::use(CarbonImmutable::class)\` can be passed directly. It is
normalised to a mutable \`Carbon\` internally.

## Factory Method

\`\`\`php
public static function fromTokens(
    string $vatNumber,
    OAuthTokensData $tokens,
    ?AnafAuthenticatorInterface $authenticator = null,
    ?Closure $tokenReloader = null,          // added in v3.0.0
): self
\`\`\`

Use \`fromTokens()\` when you already have an \`OAuthTokensData\` object (e.g. loaded from storage).

## \`$tokenReloader\` — multi-worker token rotation (new in v3.0.0, optional)

\`(Closure(): ?OAuthTokensData)|null\`. Both parameters are **optional and additive** — existing
call sites keep working unchanged. Strongly recommended for multi-worker deployments.

ANAF **rotates** refresh tokens: refreshing invalidates the old one. When two workers hold the same
tokens and both find them expired, one wins the refresh lock and rotates; the loser then wakes up
holding a refresh token that is already dead and its refresh fails. The reloader is invoked **only
after the lock is acquired**, so the loser re-reads whatever the winner just persisted and adopts
it instead of spending a spent token.

\`\`\`php
$client = EFacturaClient::fromTokens(
    vatNumber: '49296198',
    tokens: $stored,
    tokenReloader: fn () => EfacturaToken::where('cif', '49296198')->first()?->toOAuthTokensData(),
);
\`\`\`

Contract:
- Return the tokens **currently in your store**, or \`null\` if there are none.
- The returned tokens are adopted **unconditionally**, even if themselves expired — the stored
  refresh token is by definition at least as fresh as the in-memory one.
- It must be cheap and side-effect free; it runs while the refresh lock is held.
- It **never throws through**: an exception is caught and logged as a warning, and the client falls
  back to its in-memory tokens (exactly the v2 behaviour).
- It does **not** mark \`wasTokenRefreshed()\` — the tokens came out of your store, so there is
  nothing new to write back.

## Public Methods

\`\`\`php
public function uploadDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData
public function uploadB2CDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData
public function getStatusMessage(string $uploadId): StatusResponseData
public function downloadDocument(string $downloadId): DownloadResponseData
public function getMessages(ListMessagesParamsData $params): ListMessagesResponseData
public function getMessagesPaginated(PaginatedMessagesParamsData $params): PaginatedMessagesResponseData
public function validateXml(string $xml, DocumentStandardType $standard): ValidationResultData
public function verifySignature(string $xml): ValidationResultData
public function convertXmlToPdf(string $xml, DocumentStandardType $standard, bool $validate = false): string
public function wasTokenRefreshed(): bool
public function getTokens(): OAuthTokensData
public function getVatNumber(): string
public function getRateLimiter(): RateLimiter // concrete class only, not on interface
\`\`\`

## Token Refresh Behavior

The client automatically refreshes expired tokens before making API calls. A 120-second buffer is applied — if the token expires within 120 seconds, it is refreshed proactively.

After any API call, check whether a refresh occurred and persist the updated tokens:

\`\`\`php
$client->uploadDocument($xml);

if ($client->wasTokenRefreshed()) {
    $updatedTokens = $client->getTokens();
    // Persist $updatedTokens to your storage
}
\`\`\`

## Retry Behaviour (changed in v3.0.0)

Retries are governed by \`http.retry_times\` / \`http.retry_delay\`, but **uploads are deliberately
excluded from most of it**. ANAF accepts no idempotency key and mints a fresh \`index_incarcare\`
for every accepted POST, so a blind retry files the same invoice twice — a duplicate legal
submission the recipient also receives twice.

| Failure | \`uploadDocument()\` / \`uploadB2CDocument()\` | Reads (status, list, download) |
|---|---|---|
| HTTP 5xx | **no retry** — \`ApiException\` on attempt 1 | retried up to \`retry_times\` |
| Read timeout (cURL errno 28) | **no retry** — \`ApiException\` on attempt 1 | retried |
| Unclassifiable transport error | **no retry** — \`ApiException\` on attempt 1 | retried |
| DNS / connect / TLS failure (errno 5, 6, 7, 35) | retried — provably never left the machine | retried |
| HTTP 4xx (incl. 429) | never retried | never retried |

An upload that fails is therefore **not proof the invoice was not filed**. Before re-submitting,
reconcile with \`getMessages()\` — do not blindly re-upload.

\`validateXml()\`, \`verifySignature()\` and \`convertXmlToPdf()\` retry unconditionally: they are pure
functions of the posted XML and file nothing.

## Rate Limiting

Built-in rate limiting is enforced via the \`RateLimiter\` class. When the limit is exceeded, a \`RateLimitExceededException\` is thrown. Access the limiter directly via \`getRateLimiter()\`.

**Since v3.0.0 every retry attempt consumes global quota** (ANAF meters per HTTP request, so each
retry calls \`checkGlobal()\` again). With a tight \`global_per_minute\`, a retrying read can now throw
\`RateLimitExceededException\` part-way through where v2 raised \`ApiException\` after exhausting its
attempts. Catch \`RateLimitExceededException\` **before** \`ApiException\` — it does not extend it.

## \`downloadDocument()\` — non-ZIP bodies now throw (changed in v3.0.0)

A \`200\` from \`/descarcare\` is not proof of success: ANAF reports some errors as \`200\` with a JSON
body. v2 handed that body back as a "successful" download whose \`saveTo()\` wrote JSON into a
\`.zip\`. v3 inspects the body and throws \`ApiException\` unless it is a real ZIP (\`PK\` signature):

| Body | v2 | v3 |
|---|---|---|
| \`{"eroare":"Nu aveti dreptul"}\` (200) | returned as content ✗ | \`ApiException\` — message is ANAF's own, e.g. \`Nu aveti dreptul\`; \`->statusCode\` is \`200\` |
| HTML maintenance page (200) | returned as content ✗ | \`ApiException: ANAF did not return a ZIP archive for download ID (content-type: text/html).\` |
| plain text (200) | returned as content ✗ | \`ApiException: ANAF did not return a ZIP archive for download ID (content-type: text/plain).\` |
| real ZIP (\`PK\\x03\\x04\` / empty \`PK\\x05\\x06\`) | returned | returned (unchanged) |

\`->details\` carries the first 500 bytes of the offending body for the non-ZIP case.

## Usage Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Services\\ApiClients\\EFacturaClient;
use BeeCoded\\EFacturaSdk\\Data\\Auth\\OAuthTokensData;

// Instantiate via constructor
$client = new EFacturaClient(
    vatNumber: '12345678',
    accessToken: $accessToken,
    refreshToken: $refreshToken,
    expiresAt: $expiresAt,
);

// Instantiate via factory (preferred when you have OAuthTokensData)
$client = EFacturaClient::fromTokens(
    vatNumber: '12345678',
    tokens: $tokens, // OAuthTokensData instance
);

// Upload an invoice
$response = $client->uploadDocument($xml);

// Persist refreshed tokens if needed
if ($client->wasTokenRefreshed()) {
    $updatedTokens = $client->getTokens();
}
\`\`\`

## Exceptions

| Exception | When Thrown |
|-----------|-------------|
| \`ValidationException\` | An **argument** is unusable or an endpoint is unconfigured — empty XML; an empty/non-numeric upload or download ID; \`days\` outside 1–60; a non-positive/out-of-order timestamp or a range exceeding 60 days; \`page\` < 1; or a missing \`endpoints.services.*\` config value. **Not** thrown when a document fails ANAF validation. |
| \`AuthenticationException\` | Token refresh fails or credentials are invalid |
| \`ApiException\` | ANAF API returns an error response |
| \`RateLimitExceededException\` | Rate limit is exceeded |

> **\`validateXml()\` / \`verifySignature()\` never throw on an invalid document.** A document
> that ANAF rejects comes back as a normal \`ValidationResultData\` with \`valid === false\`.
> Check the flag — do not wrap the call in \`try/catch\` and assume success:
>
> \`\`\`php
> $result = $client->validateXml($xml, DocumentStandardType::FACT1);
>
> if (! $result->valid) {
>     // $result->details / $result->errors explain why
> }
> \`\`\`
`,

  AnafAuthenticator: `# AnafAuthenticator

**Namespace:** \`BeeCoded\\EFacturaSdk\\Services\\AnafAuthenticator\`
**Implements:** \`AnafAuthenticatorInterface\`
**Facade:** \`EFacturaSdkAuth\`

## Constructor

Resolved via the Laravel service container — typically used through the \`EFacturaSdkAuth\` facade rather than direct instantiation.

## Required Configuration

\`\`\`php
// config/efactura-sdk.php
'oauth' => [
    'client_id'     => env('EFACTURA_CLIENT_ID'),      // efactura-sdk.oauth.client_id
    'client_secret' => env('EFACTURA_CLIENT_SECRET'),  // efactura-sdk.oauth.client_secret
    'redirect_uri'  => env('EFACTURA_REDIRECT_URI'),   // efactura-sdk.oauth.redirect_uri
],
\`\`\`

## Public Methods

\`\`\`php
public function getAuthorizationUrl(?AuthUrlSettingsData $settings = null): string
public function exchangeCodeForToken(string $code): OAuthTokensData
public function refreshAccessToken(string $refreshToken): OAuthTokensData
public function decodeState(string $encodedState): array
\`\`\`

### getAuthorizationUrl

Returns the ANAF OAuth2 authorization URL to redirect the user to. Optionally accepts \`AuthUrlSettingsData\` to customize scope, state, or other parameters.

### exchangeCodeForToken

Exchanges an authorization code (received in the OAuth callback) for an \`OAuthTokensData\` object containing access and refresh tokens.

### refreshAccessToken

Exchanges a refresh token for a new \`OAuthTokensData\` object. Used internally by \`EFacturaClient\` but can also be called directly.

### decodeState

Decodes a base64-encoded state parameter received in the OAuth callback. Always validate the state value to prevent CSRF attacks.

## CSRF Protection

The \`state\` parameter in the authorization URL is base64-encoded. In your callback route, decode it with \`decodeState()\` and verify it matches the value you set before redirecting the user.

## Usage Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\EFacturaSdkAuth;

// Step 1: Redirect user to ANAF
$url = EFacturaSdkAuth::getAuthorizationUrl();
return redirect($url);

// Step 2: Handle callback
$tokens = EFacturaSdkAuth::exchangeCodeForToken($request->code);
// $tokens->accessToken, $tokens->refreshToken, $tokens->expiresAt
\`\`\`

## Exceptions

| Exception | When Thrown |
|-----------|-------------|
| \`AuthenticationException\` | Token exchange or refresh fails |
`,

  UblBuilder: `# UblBuilder

**Namespace:** \`BeeCoded\\EFacturaSdk\\Services\\UblBuilder\`
**Implements:** \`UblBuilderInterface\`
**Facade:** \`UblBuilder\`

## Constructor

\`\`\`php
public function __construct(?InvoiceBuilder $invoiceBuilder = null)
\`\`\`

When \`$invoiceBuilder\` is \`null\`, a default \`InvoiceBuilder\` instance is created automatically.

## Public Methods

\`\`\`php
public function generateInvoiceXml(InvoiceData $invoiceData): string
\`\`\`

Generates a UBL 2.1 XML string from an \`InvoiceData\` DTO. Delegates to \`InvoiceBuilder\` internally.

## Usage Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\UblBuilder;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceData;

$xml = UblBuilder::generateInvoiceXml($invoiceData);

// Then upload the XML
$response = $client->uploadDocument($xml);
\`\`\`

## Exceptions

| Exception | When Thrown |
|-----------|-------------|
| \`ValidationException\` | \`InvoiceData\` fails validation rules |
`,

  InvoiceBuilder: `# InvoiceBuilder

**Namespace:** \`BeeCoded\\EFacturaSdk\\Builders\\InvoiceBuilder\`
**Facade:** None — used internally by \`UblBuilder\` or instantiated directly

## Public Methods

\`\`\`php
public function buildInvoiceXml(InvoiceData $input): string
\`\`\`

Validates the \`InvoiceData\` DTO and generates a CIUS-RO compliant UBL 2.1 XML string.

## Validation Rules

### Invoice

| Field | Rules |
|-------|-------|
| \`invoiceNumber\` | Required, must contain at least one digit, max 200 chars |
| \`issueDate\` | Required |
| \`lines\` | At least one line item required |

### Invoice Lines

| Field | Rules |
|-------|-------|
| \`name\` | Required, max 100 chars |
| \`description\` | Optional, max 200 chars |
| \`quantity\` | Cannot be zero (negative values allowed for credit notes). Filed with 2–6 decimals |
| \`unitPrice\` | Must be >= 0. Filed with 2–6 decimals |
| \`taxPercent\` | Must be in range 0–100 |
| \`taxPercent\` / \`taxAmount\` | When \`supplier->isVatPayer === false\`, **both must be zero** — otherwise \`ValidationException: Line N: A supplier that is not registered for VAT cannot charge VAT (BR-O-09)\` (v3.0.0) |

### Tax Accounting Currency (v3.0.0)

| Field | Rules |
|-------|-------|
| \`taxAmountRon\` | **Required** when \`currency !== 'RON'\`; **rejected** when \`currency === 'RON'\`; must match the sign of \`getTotalVat()\` |

### Party (Supplier / Customer)

| Field | Rules |
|-------|-------|
| \`registrationName\` | Required, max 200 chars |
| \`companyId\` | Required |
| \`isVatPayer\` | Required at construction — no default since v3.0.0 (an omission fails before the builder is reached) |

### Address

| Field | Rules |
|-------|-------|
| \`street\` | Required, max 150 chars |
| \`city\` | Required, max 50 chars |
| \`postalZone\` | Optional, max 20 chars |
| \`county\` | Required for Romanian addresses; must be a valid ISO 3166-2:RO code |

### Preceding Invoice (Credit Notes)

| Field | Rules |
|-------|-------|
| \`precedingInvoiceNumber\` | Optional, max 200 chars |

## Credit Note Handling

Credit notes are auto-detected from \`InvoiceTypeCode::CreditNote\` (value \`381\`). When a credit note is detected, line quantities are automatically negated to comply with ANAF requirements. You do not need to pass negative quantities yourself.

## XML Output

Generates UBL 2.1 XML conforming to the CIUS-RO customization profile used by ANAF e-Factura.

## Usage Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Builders\\InvoiceBuilder;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceData;

$builder = new InvoiceBuilder();
$xml = $builder->buildInvoiceXml($invoiceData);
\`\`\`

## Exceptions

| Exception | When Thrown |
|-----------|-------------|
| \`ValidationException\` | Any validation rule is violated |
`,

  AnafDetailsClient: `# AnafDetailsClient

**Namespace:** \`BeeCoded\\EFacturaSdk\\Services\\ApiClients\\AnafDetailsClient\`
**Implements:** \`AnafDetailsClientInterface\`
**Facade:** \`AnafDetails\`

## Constructor

\`\`\`php
public function __construct()
\`\`\`

Can be instantiated directly with \`new AnafDetailsClient()\` or used via the \`AnafDetails\` facade.

## Notes

- No authentication required — uses the public ANAF company details API
- **Honours \`efactura-sdk.http.retry_times\` and \`http.retry_delay\` (since v3.0.0).** Previously it ignored both and was pinned to \`BaseApiClient\`'s hardcoded \`MAX_TRY_COUNT = 3\` / \`RETRY_DELAY = 5\`. Company lookups are reads, so retrying is always safe and applies to transport failures and 5xx alike. If you had tuned these keys for \`EFacturaClient\`, they now also change lookup behaviour — a \`retry_times\` of \`6\` means up to six lookup attempts with a blocking \`sleep(retry_delay)\` between each.
- Maximum batch size: **100** VAT codes per request (\`MAX_BATCH_SIZE = 100\`, ANAF v9 payload limit)
- Rate limit: **1 request/second** (\`company_lookup_per_second\`, ANAF limit). Throws \`RateLimitExceededException\` (HTTP 429, \`->retryAfterSeconds\`) when exceeded — independent of the 100-CUI payload cap. Gated by \`rate_limits.enabled\`.
- Error handling: API/network errors return \`CompanyLookupResultData::failure()\` (check \`$result->error\`). The rate-limit breach is the exception — it **throws** \`RateLimitExceededException\` rather than returning a failure result.
- **Not-found CUIs are not failures:** a lookup whose CUIs are all not-found returns \`success === true\` with the CUIs in \`$result->notFound\`. Branch on \`hasNotFound()\` / \`hasCompanies()\`, not on \`success\`, to distinguish outcomes. (Changed in v2.2.0 — previously a single not-found CUI returned a failure result.)

## Public Methods

\`\`\`php
public function getCompanyData(string $vatCode): CompanyLookupResultData
public function batchGetCompanyData(array $vatCodes): CompanyLookupResultData
public function isValidVatCode(string $vatCode): bool
\`\`\`

### getCompanyData

Looks up a single company by VAT code. Returns a \`CompanyLookupResultData\` object containing company details, address, VAT registration status, and more. Throws \`RateLimitExceededException\` if the 1 req/sec limit is exceeded.

### batchGetCompanyData

Looks up multiple companies in a single API call. The \`$vatCodes\` array must contain at most 100 entries. Returns a \`CompanyLookupResultData\` object whose \`$companies\` holds found companies and \`$notFound\` holds the CUIs ANAF did not find. Throws \`RateLimitExceededException\` if the 1 req/sec limit is exceeded (the per-second limit is per request, not per CUI).

### isValidVatCode

Performs **format *and* checksum validation** — does not make an API call. Delegates to \`VatNumberValidator::isValid()\`, which runs the mod-11 CUI checksum (or the full CNP check for 13-digit values), so a well-formed but bogus code like \`'RO12345678'\` returns \`false\`. If you want the format check on its own, call \`VatNumberValidator::isValidFormat()\` instead.

## Usage Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\AnafDetails;

// Single lookup
$result = AnafDetails::getCompanyData('12345678');

// Batch lookup
$result = AnafDetails::batchGetCompanyData(['12345678', '87654321']);

// Format + checksum validation (no API call)
$isValid = AnafDetails::isValidVatCode('14399840');
\`\`\`

## Error Handling

Unlike \`EFacturaClient\`, this client does **not** throw exceptions on API errors. Instead, it returns \`CompanyLookupResultData::failure()\` with an error message. Always check the result:

\`\`\`php
$result = AnafDetails::getCompanyData('12345678');
if ($result->error) {
    // Handle error: $result->error contains the message
}
\`\`\`
`,
};
