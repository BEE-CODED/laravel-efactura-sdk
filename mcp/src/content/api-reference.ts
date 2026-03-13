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
    ?Carbon $expiresAt = null,
    ?AnafAuthenticatorInterface $authenticator = null,
)
\`\`\`

## Factory Method

\`\`\`php
public static function fromTokens(
    string $vatNumber,
    OAuthTokensData $tokens,
    ?AnafAuthenticatorInterface $authenticator = null,
): self
\`\`\`

Use \`fromTokens()\` when you already have an \`OAuthTokensData\` object (e.g. loaded from storage).

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

## Rate Limiting

Built-in rate limiting is enforced via the \`RateLimiter\` class. When the limit is exceeded, a \`RateLimitExceededException\` is thrown. Access the limiter directly via \`getRateLimiter()\`.

## Usage Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Services\\ApiClients\\EFacturaClient;
use BeeCoded\\EFacturaSdk\\Data\\OAuthTokensData;

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
| \`ValidationException\` | XML validation fails |
| \`AuthenticationException\` | Token refresh fails or credentials are invalid |
| \`ApiException\` | ANAF API returns an error response |
| \`RateLimitExceededException\` | Rate limit is exceeded |
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
| \`quantity\` | Cannot be zero (negative values allowed for credit notes) |
| \`unitPrice\` | Must be >= 0 |
| \`taxPercent\` | Must be in range 0–100 |

### Party (Supplier / Customer)

| Field | Rules |
|-------|-------|
| \`registrationName\` | Required, max 200 chars |
| \`companyId\` | Required |

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
- Maximum batch size: **500** VAT codes per request (\`MAX_BATCH_SIZE = 500\`)
- Error handling: returns \`CompanyLookupResultData::failure()\` instead of throwing exceptions. Check \`$result->error\` for failures.

## Public Methods

\`\`\`php
public function getCompanyData(string $vatCode): CompanyLookupResultData
public function batchGetCompanyData(array $vatCodes): CompanyLookupResultData
public function isValidVatCode(string $vatCode): bool
\`\`\`

### getCompanyData

Looks up a single company by VAT code. Returns a \`CompanyLookupResultData\` object containing company details, address, VAT registration status, and more.

### batchGetCompanyData

Looks up multiple companies in a single API call. The \`$vatCodes\` array must contain at most 500 entries. Returns a \`CompanyLookupResultData\` object.

### isValidVatCode

Performs **format validation only** — does not make an API call. Returns \`true\` if the VAT code matches the expected format.

## Usage Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\AnafDetails;

// Single lookup
$result = AnafDetails::getCompanyData('12345678');

// Batch lookup
$result = AnafDetails::batchGetCompanyData(['12345678', '87654321']);

// Format validation (no API call)
$isValid = AnafDetails::isValidVatCode('12345678');
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
