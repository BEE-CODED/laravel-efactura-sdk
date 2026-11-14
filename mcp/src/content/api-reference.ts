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
    ?Closure $tokenPersister = null,         // added in v4.0.0
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
    ?Closure $tokenPersister = null,         // added in v4.0.0
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

## \`$tokenPersister\` — store rotated tokens under the lock (new in v4.0.0, optional)

\`(Closure(OAuthTokensData): mixed)|null\`. Called with the tokens a refresh just minted, **inside
the refresh lock**, right after the refresh succeeds and before the lock is released. The next
worker to take the lock reloads what you stored, so nobody can spend the rotated refresh token
first. The lock is held for the HTTP timeout (\`http.timeout\`) plus 30 seconds.

\`\`\`php
$client = EFacturaClient::fromTokens(
    vatNumber: $vatNumber,
    tokens: $tokens,
    // Your own storage: load and save the company's token pair.
    tokenReloader: fn () => $this->loadTokens($company),
    tokenPersister: fn (OAuthTokensData $new) => $this->persistTokens($company, $new),
);
\`\`\`

Contract:
- An exception it throws **reaches your code**: persistence failed and you must know. The client
  keeps the new tokens and \`wasTokenRefreshed()\` is still \`true\`.
- \`wasTokenRefreshed()\` keeps its meaning, so the after-the-call persistence pattern still works.

## Public Methods

\`\`\`php
public function uploadDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData
public function uploadB2CDocument(string $xml, ?UploadOptionsData $options = null): UploadResponseData
public function getStatusMessage(string $uploadId, bool $useProviderLimit = false): StatusResponseData // flag: concrete class only, not on interface
public function downloadDocument(string $downloadId): DownloadResponseData
public function getMessages(ListMessagesParamsData $params): ListMessagesResponseData
public function getMessagesPaginated(PaginatedMessagesParamsData $params): PaginatedMessagesResponseData
public function validateXml(string $xml, DocumentStandardType $standard): ValidationResultData
public function verifySignature(string $xml, string $signature): ValidationResultData // multipart file + signature (4.0)
public function convertXmlToPdf(string $xml, DocumentStandardType $standard, bool $validate = false): string
public function wasTokenRefreshed(): bool
public function getTokens(): OAuthTokensData
public function getVatNumber(): string
public function getRateLimiter(): RateLimiter // concrete class only, not on interface
\`\`\`

\`getMessages()\` and \`getMessagesPaginated()\` throw \`ValidationException('CIF cannot be empty')\` for an
empty or blank \`cif\`, before any quota is spent (since 4.0).

\`verifySignature($xml, $signature)\` posts the invoice XML and the XML holding its signature (both from the
\`downloadDocument()\` archive) as the multipart files \`file\` and \`signature\` to ANAF's
\`https://webservicesp.anaf.ro/api/validate/signature\` (since 4.0; 3.x posted the invoice alone to an
undocumented URL). ANAF does not document the answer: it is read like a validation answer, and any other
shape reads as \`valid: false\`, which is not proof of a bad signature; when nothing in it is read, \`details\` holds its
first 500 bytes. Blank arguments throw \`ValidationException\`.

\`convertXmlToPdf()\` throws \`ApiException\` with ANAF's status code when ANAF answers with a JSON
error (with or without a JSON content type), or with any body that does not start with \`%PDF\`, such
as a maintenance page (since 4.0).

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
| Read timeout (cURL errno 28 after the request was sent) | **no retry** — \`ApiException\` on attempt 1 | retried |
| Unclassifiable transport error | **no retry** — \`ApiException\` on attempt 1 | retried |
| DNS / connect / TLS failure (errno 5, 6, 7, 35) | retried — provably never left the machine; \`RequestNotSentException\` when spent | retried |
| Connect-phase timeout (errno 28, \`pretransfer_time\` 0 and \`request_size\` 0) | retried, like the row above | retried |
| HTTP 4xx (incl. 429) | never retried | never retried |

An upload that fails is therefore **not proof the invoice was not filed**. Before re-submitting,
reconcile with \`getMessages()\` — do not blindly re-upload.

\`validateXml()\`, \`verifySignature()\` and \`convertXmlToPdf()\` go through the same transport with
retries on: they are pure functions of the posted XML and file nothing.

## Rate Limiting

Built-in rate limiting is enforced via the \`RateLimiter\` class. When the limit is exceeded, a \`RateLimitExceededException\` is thrown. Access the limiter directly via \`getRateLimiter()\`.

**Since v3.0.0 every retry attempt consumes global quota** (ANAF meters per HTTP request, so each
retry calls \`checkGlobal()\` again). With a tight \`global_per_minute\`, a retrying read can now throw
\`RateLimitExceededException\` part-way through where v2 raised \`ApiException\` after exhausting its
attempts. Catch \`RateLimitExceededException\` **before** \`ApiException\` — it does not extend it.

**Which limit was hit (since 4.0).** \`RateLimitExceededException::$scope\` is a \`?RateLimitScope\`:
\`RateLimitScope::Status\` means only that upload index's daily status quota is spent (keep polling the
others), \`RateLimitScope::Global\` means every call is blocked for now.

**Status reads at ANAF's own limit.** \`getStatusMessage($uploadId, useProviderLimit: true)\` checks
the same per-message status counter against \`rate_limits.status_per_day_message_provider\` (default
100, ANAF's ceiling) instead of \`status_per_day_message\` (default 50), on its first attempt and on
every retry. Use it for a check a user explicitly asked for; automatic polling keeps the default and
still sees the units such reads spend. \`getRemainingQuota('status', $uploadId, useProviderLimit: true)\`
reports that counter against the same ceiling. \`EFacturaClientInterface::getStatusMessage()\` is
unchanged; the flag exists on the concrete \`EFacturaClient\` only.

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
| \`ApiException\` | ANAF API returns an error response, or a 2xx body that is not a usable JSON object (e.g. a bare number). Its \`statusCode\` is ANAF's own, so \`200\` for the latter. Since 4.0 this includes \`getMessages()\`/\`getMessagesPaginated()\`, which used to return an empty response |
| \`RateLimitExceededException\` | Rate limit is exceeded; \`->scope\` (\`?RateLimitScope\`) names which limit, e.g. \`RateLimitScope::Status\` (one upload index) vs \`RateLimitScope::Global\` |

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

\`\`\`php
public function validateInvoice(InvoiceData $invoiceData): void
\`\`\`

New in 4.0 (also on \`UblBuilderInterface\`, so an implementation must add it): builds the XML and
discards it, so it runs exactly the checks of \`generateInvoiceXml()\` and throws the same
\`ValidationException\`.

## Usage Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\UblBuilder;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceData;

UblBuilder::validateInvoice($invoiceData); // e.g. before issuing; throws ValidationException
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
| \`invoiceNumber\` | Required (blank after trimming is refused), must contain at least one digit, max 200 chars |
| \`issueDate\` | Required |
| \`currency\` | Three uppercase letters (BR-CL-04); empty is refused |
| \`dueDate\` | Required on an Invoice (not a credit note) whose amount due is positive (BR-CO-25); a blank string counts as none and is refused on every document |
| \`lines\` | At least one line item required |
| any string | Valid UTF-8 and XML 1.0 characters only (no control characters other than tab, LF, CR; no U+FFFE/U+FFFF); the message names the field, e.g. \`lines.0.name\` |
| \`notes\` | At most 20, each non-blank and at most 300 chars (BR-RO-L300) |
| \`vatPointDateCode\` | \`'3'\`, \`'35'\` or \`'432'\`; not with \`taxPointDate\` (BR-CO-03) |
| \`delivery\` | Address, when given: street, city and county required in every country. Date and address required when a line is category K |
| \`exemptionReasons\` | Categories E, G, K, AE only, one each, each with lines; fixed codes for AE/G/K; E needs a VATEX code or a text; text max 100 chars |

### Invoice Lines

| Field | Rules |
|-------|-------|
| \`name\` | Required, max 100 chars |
| \`description\` | Optional, max 200 chars |
| \`quantity\` | Cannot be zero (negative values allowed for credit notes). At most 6 decimals (more is refused, not rounded); filed with 2–6 |
| \`unitPrice\` | Must be >= 0. At most 6 decimals; filed with 2–6 |
| \`taxAmount\` | At most 6 decimals |
| \`taxPercent\` | Must be in range 0–100, at most 2 decimals |
| amounts | \`float\|string\`; \`INF\`/\`NAN\`, non-numeric strings and floats past 2^33 (6 decimals) / 2^46 (2 decimals) are refused with \`ValidationException\` naming the field (\`lines.0.quantity\`) |
| Z/O/E/G/K breakdown | The summed line tax must round to exactly zero (BR-Z-09, BR-O-09, ...) |
| \`taxPercent\` / \`taxAmount\` | When \`supplier->isVatPayer === false\`, **both must be zero** — otherwise \`ValidationException: Line N: A supplier that is not registered for VAT cannot charge VAT (BR-O-09)\` (v3.0.0) |

### Tax Accounting Currency (v3.0.0)

| Field | Rules |
|-------|-------|
| \`taxAmountRon\` | **Required** when \`currency !== 'RON'\`; **rejected** when \`currency === 'RON'\`; at most 2 decimals; exactly zero when no line is standard-rated; must match the sign of \`getTotalVat()\` when both amounts are nonzero; either zero boundary is permitted |

### Payable rounding and total helpers

Optional \`InvoiceData::$payableRoundingAmount\` (\`float|string|null\`) is a signed amount in
document currency with at most 2 decimals (a float only within binary noise of one, below 2^46).
Null is omitted from serialization; null/zero emits no BT-114. It is kept as given and checked
where it is read — the payable helpers, \`toArray()\` and the builder — after direct
construction, hydration or property mutation; anything finer throws \`ValidationException\`.

- \`getTotalIncludingVat()\` returns the payable amount including optional rounding (BT-115).
- \`getTotalIncludingVatBeforeRounding()\` returns net plus VAT, excluding rounding (BT-112).
- \`getTotalExcludingVat()\` and \`getTotalVat()\` are unchanged.

For code380, XML uses those helper amounts; for code381, it negates them and rounding under
the existing credit-note convention. BT-114 precedes BT-115 and is applied exactly once.
No new setting or interface method is required. Existing callers omitting rounding keep their
results. Opting-in callers that need the pre-rounding total use the new helper.

### Party (Supplier / Customer)

| Field | Rules |
|-------|-------|
| \`registrationName\` | Required (trimmed), max 200 chars |
| \`companyId\` | Required (trimmed); a usable VAT id for AE/K (both parties) and E/G (supplier) |
| \`isVatPayer\` | Required at construction — no default since v3.0.0 (an omission fails before the builder is reached) |
| \`registrationNumber\` | Supplier only: filed as BT-33 \`CompanyLegalForm\`, max 1000 chars, omitted when blank |

### Address

| Field | Rules |
|-------|-------|
| \`street\` | Required (trimmed), max 150 chars |
| \`city\` | Required (trimmed), max 50 chars; in Bucharest filed as \`SECTOR1\`–\`SECTOR6\` |
| \`postalZone\` | Optional, max 20 chars |
| \`countryCode\` | Two uppercase letters (BR-CL-14); empty is refused |
| \`county\` | Required for Romanian addresses; must map to a valid ISO 3166-2:RO code. Only RO addresses are rewritten |
| \`bucharestSector\` | Optional 1–6, only on a Bucharest RO address. A Bucharest address needs a sector from this, the city or the county (BR-RO-100/101) |

### Preceding Invoices (BG-3)

| Field | Rules |
|-------|-------|
| \`precedingInvoices\` | At most 500 (BR-RO-A500); each number non-blank (BR-55), max 200 chars |
| \`precedingInvoiceNumber\` | Deprecated alias, max 200 chars; with \`precedingInvoices\` it must name its only entry |

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
public function __construct(bool $strict = false, ?int $tries = null, int|float|null $timeoutSeconds = null)
\`\`\`

Can be instantiated directly with \`new AnafDetailsClient()\` or used via the \`AnafDetails\` facade.
\`$tries\` and \`$timeoutSeconds\` override \`http.retry_times\` and \`http.timeout\` for that instance.

## Notes

- No authentication required — uses the public ANAF company details API
- **Honours \`efactura-sdk.http.retry_times\` and \`http.retry_delay\` (since v3.0.0).** Previously it ignored both and was pinned to \`BaseApiClient\`'s hardcoded \`MAX_TRY_COUNT = 3\` / \`RETRY_DELAY = 5\`. Company lookups are reads, so retrying is always safe and applies to transport failures and 5xx alike. If you had tuned these keys for \`EFacturaClient\`, they now also change lookup behaviour — a \`retry_times\` of \`6\` means up to six lookup attempts with a blocking \`sleep(retry_delay)\` between each.
- Maximum batch size: **100** VAT codes per request (\`MAX_BATCH_SIZE = 100\`, ANAF v9 payload limit)
- Rate limit: **1 request/second** (\`company_lookup_per_second\`, ANAF limit). Throws \`RateLimitExceededException\` (HTTP 429, \`->retryAfterSeconds\`) when exceeded — independent of the 100-CUI payload cap. Gated by \`rate_limits.enabled\`.
- Error handling: API/network errors return \`CompanyLookupResultData::failure()\` (check \`$result->error\`). The rate-limit breach is the exception — it **throws** \`RateLimitExceededException\` rather than returning a failure result. \`$result->failureKind\` is \`Transport\` or \`InvalidResponse\` for a sent lookup, \`null\` for input refused before sending.
- **Strict mode (since 4.0):** \`new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 5)\` returns a company only when ANAF's answer confirms the request (see the CHANGELOG entry "strict company lookups"); otherwise a failure with \`failureKind\` \`InvalidResponse\`.
- ANAF's own HTTP 429 throws \`RateLimitExceededException\` with \`fromProvider: true\` (since 4.0).
- Each CUI is sent once (\`RO\` prefix removed) (since 4.0).
- The lookup limit is counted over a 2-second window (since 4.0), so lookups are at least 1 s apart on every cache store.
- **Not-found CUIs are not failures:** a lookup whose CUIs are all not-found returns \`success === true\` with the CUIs in \`$result->notFound\`. Branch on \`hasNotFound()\` / \`hasCompanies()\`, not on \`success\`, to distinguish outcomes. (Changed in v2.2.0 — previously a single not-found CUI returned a failure result.)

## Public Methods

\`\`\`php
public function getCompanyData(string $vatCode): CompanyLookupResultData
public function batchGetCompanyData(array $vatCodes): CompanyLookupResultData
public function isValidVatCode(string $vatCode): bool
\`\`\`

### getCompanyData

Looks up a single company by VAT code. Returns a \`CompanyLookupResultData\` object containing company details, address, VAT registration status, and more. Throws \`RateLimitExceededException\` when the SDK's lookup limit (1 per 2-second window by default; ANAF allows 1 per second) is exceeded.

### batchGetCompanyData

Looks up multiple companies in a single API call. The \`$vatCodes\` array must contain at most 100 entries. Returns a \`CompanyLookupResultData\` object whose \`$companies\` holds found companies and \`$notFound\` holds the CUIs ANAF did not find. Throws \`RateLimitExceededException\` when the SDK's lookup limit (1 per 2-second window by default) is exceeded (the limit is per request, not per CUI).

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

// $result->failureKind (CompanyLookupFailureKind): Transport = no usable answer,
// InvalidResponse = ANAF answered but the answer is unreadable or does not confirm the request.
\`\`\`
`,

  ArchiveInspector: `# ArchiveInspector

**Namespace:** \`BeeCoded\\EFacturaSdk\\Support\\ArchiveInspector\` (since 4.0)

Checks a response archive from \`downloadDocument()\` before you keep or trust it. Requires \`ext-zip\` and \`ext-dom\`.

## Constructor

\`\`\`php
public function __construct(?string $temporaryDirectory = null)
\`\`\`

The archive is written to a temporary file in \`$temporaryDirectory\` (default \`sys_get_temp_dir()\`) and removed afterwards. An unusable directory is \`ArchiveLimitReason::Io\`.

## \`verify(string $archive, ?string $uploadedXml = null): void\`

1. Limits, always and before any member is read: at most 20 MiB; a readable ZIP of 1 to 32 unencrypted members, each expanding to at most 10 MiB and all together to at most 20 MiB. A failure throws \`ArchiveLimitException\` with \`$reason\` (\`ArchiveLimitReason\`): retryable, not a verdict on the content.
2. Only with \`$uploadedXml\` (an accepted document whose upload you stored): each member is read with a 10 MiB cap (\`UblDocumentComparator::MAX_BYTES\`, 10 485 760 bytes, also the comparator's own cap, so a member within the limits is never a mismatch for its size) and must be either the uploaded Invoice/CreditNote, exactly once, or an xmldsig \`Signature\` (\`http://www.w3.org/2000/09/xmldsig#\`). Anything else throws \`ArchiveMismatchException\` (final): a DOCTYPE or entity declaration, a member that is not XML or not its declared size, an unrelated document, a second copy, no copy, or a content difference.

An \`$uploadedXml\` over 10 MiB or over 1 000 000 \`<\` can never be compared: \`verify()\` throws \`ArchiveMismatchException\` (final) before step 1, so its archive is never a retryable \`MemberTooLarge\`.

Without \`$uploadedXml\` (limits only, e.g. a rejection archive holding ANAF's errors) no member is read.

## UblDocumentComparator

\`BeeCoded\\EFacturaSdk\\Support\\UblDocumentComparator::assertSameDocument(string $candidate, string $expected): void\` compares two UBL documents as element trees. The XML declaration, namespace prefixes, whitespace-only text, anything outside the root and trailing decimal zeroes in \`InvoicedQuantity\`, \`CreditedQuantity\`, \`PriceAmount\`, \`LineExtensionAmount\`, \`TaxExclusiveAmount\`, \`TaxInclusiveAmount\`, \`PayableAmount\`, \`PayableRoundingAmount\`, \`TaxableAmount\`, \`TaxAmount\` and \`Percent\` may differ; CDATA counts as text. Any other difference, a DOCTYPE, unparseable XML, a comment or processing instruction inside the root, or text mixed with elements throws \`ArchiveMismatchException\`.

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Exceptions\\ArchiveLimitException;
use BeeCoded\\EFacturaSdk\\Exceptions\\ArchiveMismatchException;
use BeeCoded\\EFacturaSdk\\Support\\ArchiveInspector;

try {
    (new ArchiveInspector)->verify($download->content, $uploadedXml);
} catch (ArchiveLimitException $e) {
    // $e->reason: download again later
} catch (ArchiveMismatchException $e) {
    // final: the archive is not the uploaded document
}
\`\`\`
`,

  UploadOutcome: `# UploadOutcome

**Namespace:** \`BeeCoded\\EFacturaSdk\\Support\\UploadOutcome\` (since 4.0)

A \`final readonly\` value that says what one \`uploadDocument()\` / \`uploadB2CDocument()\` call means for the document. Classify the call's answer with \`fromResponse()\` and anything the call itself threw with \`fromThrowable()\`.

## Fields

\`\`\`php
public function __construct(
    public UploadOutcomeKind $kind,               // Accepted, Refused, NotSent, Uncertain
    public ?UploadOutcomeReason $reason = null,   // null for Accepted and Uncertain
    public ?string $index = null,                 // only for Accepted: ANAF's index_incarcare
    public ?int $retryAfterSeconds = null,        // only for the SDK's own rate limit
    public ?string $providerText = null,          // ANAF's own words for a refusal, untranslated
)
\`\`\`

## \`fromResponse(UploadResponseData $response): self\`

- An \`indexIncarcare\` of 1 to 64 digits → \`Accepted\` with that index, whatever \`ExecutionStatus\` says.
- No index and an explicit \`ExecutionStatus\` 1 → \`Refused\` / \`FailedWithoutIndex\`, \`providerText\` = ANAF's first error (or \`null\`).
- Anything else (status 0 without an index, a non-numeric or longer index, an unknown status) → \`Uncertain\`.

## \`fromThrowable(\\Throwable $e): self\`

For throwables raised by the upload call itself, in this order:

| Throwable | Outcome |
|---|---|
| \`RequestNotSentException\` (provably unsent transport failure, or a failing rate-limit cache store) | \`NotSent\` / \`Transport\` |
| The SDK's \`RateLimitExceededException\` | \`NotSent\` / \`RateLimited\`, its \`retryAfterSeconds\` (\`null\` when \`fromProvider\`; only a company lookup throws that) |
| \`ApiException\` 429 (ANAF's limit) | \`NotSent\` / \`RateLimited\`, no wait known |
| \`ApiException\` 4xx except 408 | \`Refused\` / \`Rejected\`, \`providerText\` = ANAF's message (\`null\` when it sent none: \`BaseApiClient::NO_ERROR_MESSAGE\`) |
| \`AuthenticationException\` 400/401 | \`NotSent\` / \`GrantRefused\`: reconnect the company |
| Any other \`AuthenticationException\` code | \`NotSent\` / \`Transient\` |
| \`ValidationException\` (including every builder refusal) | \`Refused\` / \`LocalRefusal\` |
| \`ApiException\` 408, 5xx (502), an unreadable 2xx, a transport failure that may have delivered, anything else | \`Uncertain\`: reconcile before resending |

Your own earlier stages (building, storing the XML, picking the company) and your \`tokenPersister\` are yours to classify: \`fromThrowable()\` reads their exceptions as \`Uncertain\`. A \`tokenPersister\` exception means the document was not sent, but the rotated tokens live only in \`$client->getTokens()\`: save them, then treat the document as not sent.

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Support\\UploadOutcome;

try {
    $outcome = UploadOutcome::fromResponse($client->uploadDocument($xml));
} catch (\\Throwable $e) {
    $outcome = UploadOutcome::fromThrowable($e);
}
\`\`\`
`,

  PollSchedule: `# PollSchedule

**Namespace:** \`BeeCoded\\EFacturaSdk\\Support\\PollSchedule\` (since 4.0)

Static helpers for when to read a status or retry an archive download. The values are exact: a scheduler that runs on a fixed tick subtracts its own margin.

## \`status(int $ageSeconds): int\`

Seconds until the next status read, by the seconds since the upload: 60 under 600 s, 300 under 3600 s, 900 under 10800 s, then 3600. That is 49 reads in the first day, one under the SDK's default \`status_per_day_message\` of 50 (ANAF allows 100), so a retry or manual check that day can meet the SDK's limit: reschedule on \`RateLimitExceededException\`.

## \`archive(int $attempt, int $first = 300, int $max = 86400): int\`

Seconds before the next try after the \`$attempt\`-th failed archive download (1-based): \`$first\`, doubling each attempt, at most \`$max\` (300, 600, 1200, … 86400).

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\StatusVerdict;
use BeeCoded\\EFacturaSdk\\Support\\PollSchedule;

if ($client->getStatusMessage($index)->verdict() === StatusVerdict::InProgress) {
    $nextReadAt = now()->addSeconds(PollSchedule::status(now()->getTimestamp() - $uploadedAt->getTimestamp()));
}
\`\`\`
`,
};
