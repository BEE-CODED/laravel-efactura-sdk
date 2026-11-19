# Laravel e-Factura SDK

A Laravel package for integrating with Romania's ANAF e-Factura (electronic invoicing) system.

## Features

- **OAuth 2.0 Authentication** - Complete OAuth flow with JWT tokens and automatic token refresh
- **Document Operations** - Upload, download, and check status of invoices
- **UBL 2.1 XML Generation** - Generate CIUS-RO compliant invoice XML
- **Company Lookup** - Query ANAF for company details (VAT status, addresses, etc.)
- **Validation** - Validate XML against ANAF schemas before upload
- **PDF Conversion** - Convert XML invoices to PDF format
- **Rate Limiting** - Built-in protection against exceeding ANAF API quotas

## Requirements

- PHP 8.4+ with the `dom` and `zip` extensions
- Laravel 12 (laravel/framework 12.27.1 or later) or Laravel 13, with brick/math 0.14.4 up to 0.20,
  or 1.x (see [CHANGELOG.md](CHANGELOG.md))
- Valid ANAF OAuth credentials

## Installation

```bash
composer require bee-coded/laravel-efactura-sdk
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=efactura-sdk-config
```

## Upgrading

- 3.x→4.0: work through `## [4.0.0]` in [CHANGELOG.md](CHANGELOG.md), which ships with the
  package, starting with "Breaking (4.0): summary". Some breaks throw (amounts with more decimals
  than filed, a positive invoice without a due date, new interface methods and subclass
  signatures). Others change results quietly. Install 4.0.1 or later: it also accepts brick/math
  0.20 and 1.x, so the 4.0.0 notes about refusing those versions no longer apply.
- 4.0.0→4.0.1: no code changes (see `## [4.0.1]`).
- From 2.x: follow the v3.1.1 README first.

## Configuration

Add the following to your `.env` file:

```env
EFACTURA_SANDBOX=true
EFACTURA_CLIENT_ID=your-client-id
EFACTURA_CLIENT_SECRET=your-client-secret
EFACTURA_REDIRECT_URI=https://your-app.com/efactura/callback
```

Production hosts set `EFACTURA_SANDBOX=false`. A blank value means sandbox.

### Configuration Options

The keys you are most likely to change (**excerpt** — see below for the rest):

```php
// config/efactura-sdk.php
return [
    'sandbox' => env('EFACTURA_SANDBOX', true),

    'oauth' => [
        'client_id' => env('EFACTURA_CLIENT_ID'),
        'client_secret' => env('EFACTURA_CLIENT_SECRET'),
        'redirect_uri' => env('EFACTURA_REDIRECT_URI'),
    ],

    'http' => [
        'timeout' => env('EFACTURA_TIMEOUT', 30),
        // Read by EFacturaClient and AnafDetailsClient alike (since v3.0.0).
        // Bounds every retry; an upload retries only provably pre-send transport errors.
        'retry_times' => env('EFACTURA_RETRY_TIMES', 3),
        'retry_delay' => env('EFACTURA_RETRY_DELAY', 5),
    ],

    'logging' => [
        'channel' => env('EFACTURA_LOG_CHANNEL', 'efactura-sdk'),
        'debug' => env('EFACTURA_DEBUG', false), // logs full request/response bodies and headers (Authorization redacted)
    ],

    // ... plus 'endpoints' (ANAF base URLs — rarely changed) and 'rate_limits'
    // (documented under "Rate Limiting Configuration" below).
];
```

Publish the file to see every key with its comments:

```bash
php artisan vendor:publish --tag=efactura-sdk-config
```

### Logging Channel (Recommended)

Add a dedicated logging channel in `config/logging.php`:

```php
'efactura-sdk' => [
    'driver' => 'daily',
    'path' => storage_path('logs/efactura-sdk.log'),
    'level' => 'debug',
    'days' => 30,
],
```

If you do not define the channel, the SDK defines it at boot as a `stack` over your default
channel, so its entries go where your other logs go. A failing log handler never fails an ANAF
call. A blank or `false` `EFACTURA_LOG_CHANNEL` logs to your default channel.

Log entries and transport-failure exception messages name the filer's CIF (`vatNumber`, the request
route), which for a natural person is their CNP: set the channel's retention and access accordingly.

### Rate Limiting Configuration

The SDK includes built-in rate limiting to prevent exceeding ANAF API quotas. Most defaults are **50% of ANAF's limits**; `company_lookup_per_second` and `status_per_day_message_provider` are ANAF's full figures (see [Status Reads at ANAF's Own Limit](#status-reads-at-anafs-own-limit)). Why the defaults are halved, how the windows count and which values are refused: see the `rate_limits` comment in `config/efactura-sdk.php`.

```env
# Enable/disable rate limiting (default: true; a blank value keeps it on)
EFACTURA_RATE_LIMIT_ENABLED=true

# Global API calls per minute (ANAF limit: 1000, default: 500)
EFACTURA_RATE_LIMIT_GLOBAL=500

# RASP file uploads per CUI per day (ANAF limit: 1000, default: 500)
EFACTURA_RATE_LIMIT_RASP_UPLOAD=500

# Status queries per message per day (ANAF limit: 100, default: 50)
EFACTURA_RATE_LIMIT_STATUS=50

# Status ceiling for reads that opt in with useProviderLimit: true (ANAF limit: 100, default: 100)
EFACTURA_RATE_LIMIT_STATUS_PROVIDER=100

# Simple list queries per CUI per day (ANAF limit: 1500, default: 750)
EFACTURA_RATE_LIMIT_SIMPLE_LIST=750

# Paginated list queries per CUI per day (ANAF limit: 100,000, default: 50,000)
EFACTURA_RATE_LIMIT_PAGINATED_LIST=50000

# Downloads per message per day (ANAF limit: 10, default: 5)
EFACTURA_RATE_LIMIT_DOWNLOAD=5

# Company lookups per 2-second window (ANAF limit: 1 per second, default: 1 — per request, not per CUI)
EFACTURA_RATE_LIMIT_COMPANY_LOOKUP=1
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
| `PlatitorTvaRest` (company lookup) | 1/second | 1 per 2 s window | Per request (global, not per CUI) |

## Usage

### OAuth Authentication Flow

The SDK provides a stateless OAuth implementation. **You are responsible for storing tokens** in your database.

#### Step 1: Redirect User to ANAF Authorization

The state travels in the authorization URL: the user can read and change it. Put only a random
CSRF token in it, and keep the company on the server.

```php
use BeeCoded\EFacturaSdk\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFacturaSdk\Facades\EFacturaSdkAuth;
use Illuminate\Support\Str;

$csrfToken = Str::random(40);
session(['efactura_oauth' => ['token' => $csrfToken, 'cif' => $cif]]);

$authUrl = EFacturaSdkAuth::getAuthorizationUrl(new AuthUrlSettingsData(
    state: ['csrf_token' => $csrfToken],
));

return redirect($authUrl);
```

#### Step 2: Handle OAuth Callback

```php
use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use Illuminate\Http\Request;

public function handleCallback(Request $request)
{
    // pull(): a state is accepted once
    $pending = $request->session()->pull('efactura_oauth');
    $auth = app(AnafAuthenticatorInterface::class);

    try {
        $state = $auth->decodeState((string) $request->query('state'));
    } catch (AuthenticationException) {
        abort(403);
    }

    $sent = $state['csrf_token'] ?? null;
    if (! (is_array($pending) && is_string($sent) && hash_equals($pending['token'], $sent))) {
        abort(403);
    }

    $tokens = $auth->exchangeCodeForToken((string) $request->query('code'));

    // Store tokens in YOUR database, under the company you kept on the server.
    // Cast access_token and refresh_token as 'encrypted' on the model.
    YourTokenModel::updateOrCreate(
        ['cif' => $pending['cif']],
        [
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'expires_at' => $tokens->expiresAt,
        ],
    );
}
```

#### Manual Token Refresh

ANAF rotates the refresh token, so a refresh outside the client must take the client's refresh
lock (`EFacturaClient::tokenRefreshLock()`) and re-read the stored token under it. Otherwise a
client refreshing the same grant can spend the same refresh token, and one of the two refreshes is
refused (the company must then be authorised again).

```php
use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;

// $cif is the canonical CIF (VatNumberValidator::canonical(), what getVatNumber() returns)
EFacturaClient::tokenRefreshLock($cif)->block(10, function () use ($tokenModel) {
    $tokenModel->refresh(); // another worker may already have rotated the pair

    $newTokens = app(AnafAuthenticatorInterface::class)->refreshAccessToken($tokenModel->refresh_token);

    // Update stored tokens
    $tokenModel->update([
        'access_token' => $newTokens->accessToken,
        'refresh_token' => $newTokens->refreshToken,
        'expires_at' => $newTokens->expiresAt,
    ]);
});
```

### API Operations

#### Creating the Client

```php
use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;

// Retrieve your stored tokens
$storedTokens = YourTokenModel::where('company_id', $companyId)->first();

// Create tokens DTO
$tokens = new OAuthTokensData(
    accessToken: $storedTokens->access_token,
    refreshToken: $storedTokens->refresh_token,
    expiresAt: $storedTokens->expires_at,
);

// Create client
// $vatNumber: a CUI or CNP; the constructor throws ValidationException otherwise
$client = EFacturaClient::fromTokens($vatNumber, $tokens);
```

#### Upload Invoice

```php
use BeeCoded\EFacturaSdk\Data\Invoice\UploadOptionsData;
use BeeCoded\EFacturaSdk\Enums\StandardType;
use BeeCoded\EFacturaSdk\Enums\UploadOutcomeKind;
use BeeCoded\EFacturaSdk\Enums\UploadOutcomeReason;
use BeeCoded\EFacturaSdk\Support\UploadOutcome;

$options = new UploadOptionsData(
    standard: StandardType::UBL,
    extern: false,      // The buyer is not a Romanian entity (sends extern=DA)
    selfBilled: false,  // Self-billed invoice (autofactura)
);

try {
    // uploadB2CDocument() for invoices to consumers
    $outcome = UploadOutcome::fromResponse($client->uploadDocument($xmlContent, $options));
} catch (\Throwable $e) {
    $outcome = UploadOutcome::fromThrowable($e);
}

match ($outcome->kind) {
    // ANAF took the document in: store $outcome->index and poll its status
    UploadOutcomeKind::Accepted => $this->pollLater($outcome->index),
    // Not filed: $outcome->providerText is ANAF's reason, when it gave one
    UploadOutcomeKind::Refused => $this->markRefused($outcome->providerText),
    // Not sent: re-authorise the company, or retry later
    UploadOutcomeKind::NotSent => $outcome->reason === UploadOutcomeReason::GrantRefused
        ? $this->reauthorise()
        : $this->retryAfter($outcome->retryAfterSeconds), // null: no wait given, pick your own
    // ANAF may hold the document: reconcile with getMessages(), never resend
    UploadOutcomeKind::Uncertain => $this->reconcile(),
};
```

`$result->isSuccessful()` and `$result->isFailed()` report only the raw ExecutionStatus flag and
must not decide a resend. The rules `UploadOutcome` applies are in [Exception Handling](#exception-handling).

#### Check Processing Status

- `nok` does not always carry a download id. A `nok` without `idDescarcare` is not a validation
  failure: park it like `hasAnafError()`.
- An XML `<header>` with only `<Errors/>` (an unknown upload id, "Nu aveti dreptul") has no
  `stare`: it gives `errors` and `hasAnafError()`, like the JSON `eroare` answer below (since 4.0;
  it used to map to `nok`). ANAF said nothing about the document.
- A `<header>` or `<Raspuns>` with a `stare` (or `id_descarcare`) keeps its errors (`<Errors>`, or
  `<Error>`/`<eroare>` in a `<Raspuns>`) in `errors`, like the JSON answer; the verdict still comes
  from `stare`.
- `hasAnafError()` is true when ANAF answered with an error instead of a status: no recognisable
  `stare`, but `errors` is set (HTTP 200 with `{"eroare": "..."}`; the `eroare` text becomes
  `errors` when `Errors` holds nothing usable). This is not a verdict on the document either.

`$status->verdict()` (since 4.0) reads all of this as one `StatusVerdict`: `InProgress`, `Ready`,
`Rejected`, `ReadyWithoutDownloadId`, `RejectedWithoutDownloadId`, `NotProcessed`, `AnafError` or
`Unknown` (no status ANAF defines and no error). `Ready` and `Rejected` need a download id of 1 to
64 digits; with any other id they are `...WithoutDownloadId`. `RejectedWithoutDownloadId` is no
verdict on the document. `ReadyWithoutDownloadId` is an `ok` without an archive: record it as
accepted without a receipt, or park it, but never upload it again.

```php
use BeeCoded\EFacturaSdk\Enums\StatusVerdict;
use BeeCoded\EFacturaSdk\Support\PollSchedule;

$status = $client->getStatusMessage($uploadId);
$verdict = $status->verdict();

if ($verdict === StatusVerdict::InProgress) {
    $readAgainIn = PollSchedule::status($secondsSinceUpload);
} elseif ($verdict === StatusVerdict::Ready || $verdict === StatusVerdict::Rejected) {
    $download = $client->downloadDocument($status->idDescarcare);
} elseif ($verdict === StatusVerdict::ReadyWithoutDownloadId) {
    // ok, but no archive: record it as accepted without a receipt, or park it; never re-upload.
} elseif ($verdict !== StatusVerdict::NotProcessed) {
    // No verdict on the document: park it for a human, do not re-upload.
}
```

`PollSchedule::status($ageSeconds)` returns the seconds until the next status read: 60 for the first
10 minutes after the upload, 300 until an hour, 900 until three hours, then 3600: 49 reads in the
first day, one under the SDK's default `status_per_day_message` cap of 50, so a retry or a manual check
that day can meet the SDK's limit (reschedule on `RateLimitExceededException`). `PollSchedule::archive($attempt, $first = 300, $max = 86400)` returns the wait after
the `$attempt`-th failed archive download: `$first`, doubling, at most `$max`. Both are exact: a
scheduler that runs on a fixed tick subtracts its own margin. With the archive defaults the sixth
download comes about 2.6 h after the first, over the default `download_per_day_message` of 5:
reschedule on `RateLimitExceededException` after its `retryAfterSeconds`.

ANAF's scalar types are not stable, so every response DTO normalises what it receives instead of
taking it verbatim (since 4.0): numeric ids (`indexIncarcare`, `idDescarcare`, `cui`, `serial`,
message ids) become strings, and big integers keep every digit. A `stare` that is not a string,
text that is not a scalar, and an id that is a float, bool or array all become `null`. `Errors`
can be a string, an `{errorMessage}` object or a list of either, and always comes back as a list of
non-empty strings or `null`. A 2xx body that is not JSON, or is a bare JSON scalar (for example a
number), throws `ApiException`. Every response DTO also reads its own `toArray()` output
back, so `StatusResponseData::from($status->toArray())` rebuilds an equal DTO (since 4.0), with
laravel-data's default camelCase output names (not with a snake_case output mapper).
`::from()` normalises ANAF's answer only when it gets an array: pass ANAF's body through
`json_decode($body, true)` first, because a JSON string or object takes laravel-data's plain
pipeline, where ANAF keys such as `id_descarcare`, `eroare` and `Errors` are not read and the
company DTOs return their defaults.

#### Download Document

```php
$download = $client->downloadDocument($downloadId);

// Save to file
$download->saveTo('/path/to/invoice.zip');

// Or get content directly
$zipContent = $download->content;
$contentType = $download->contentType;
```

#### Verify a Response Archive

`ArchiveInspector` (since 4.0) checks a downloaded archive before you keep or trust it:

```php
use BeeCoded\EFacturaSdk\Exceptions\ArchiveLimitException;
use BeeCoded\EFacturaSdk\Exceptions\ArchiveMismatchException;
use BeeCoded\EFacturaSdk\Support\ArchiveInspector;

try {
    (new ArchiveInspector)->verify($download->content, $uploadedXml); // or verify($content) for limits only
} catch (ArchiveLimitException $e) {
    // $e->reason (ArchiveLimitReason): not a verdict on the content; download again later.
} catch (ArchiveMismatchException $e) {
    // Final: the archive does not hold exactly the uploaded document.
}
```

- An uploaded XML over 10 MiB or 1 000 000 `<` can never be compared: it throws
  `ArchiveMismatchException` (final) before the archive is looked at.
- Limits, checked before any member is read: at most 20 MiB, a readable ZIP of 1 to 32 unencrypted members,
  each expanding to at most 10 MiB and all together to at most 20 MiB. The reasons are
  `ArchiveTooLarge`, `MemberTooLarge`, `Encrypted`, `TooManyMembers`, `Empty`, `Unopenable` (not a
  ZIP) and `Io` (a local temporary-file or memory failure; the same bytes may pass next time).
- With the uploaded XML (an accepted document), each member is read with a 10 MiB cap
  (`UblDocumentComparator::MAX_BYTES`, 10 485 760 bytes, also the comparator's own cap, so a member
  within the limits is never a mismatch for its size) and must be
  either the uploaded Invoice/CreditNote, exactly once, or an xmldsig `Signature`. A member with a
  DOCTYPE or entity declaration, a member holding more than 1 000 000 `<`, an empty member, one that
  is not XML, any other document or a second copy is a mismatch. Without it (for example a rejection archive, which holds ANAF's errors) no member is
  read.
- The comparison is `(new UblDocumentComparator)->assertSameDocument($candidate, $expected)`, also
  usable on its own: the XML declaration, namespace prefixes, whitespace-only text, anything outside
  the root and trailing decimal zeroes in the eleven elements the builder writes as numbers
  (`InvoicedQuantity`, `CreditedQuantity`, `PriceAmount`, `LineExtensionAmount`,
  `TaxExclusiveAmount`, `TaxInclusiveAmount`, `PayableAmount`, `PayableRoundingAmount`,
  `TaxableAmount`, `TaxAmount`, `Percent`) may differ, and CDATA counts as text. Any other difference, or a comment or processing instruction inside the
  root of a document that is not byte-identical to the other, throws `ArchiveMismatchException`.
- The archive is written to a temporary file in `sys_get_temp_dir()`, or the directory given to
  `new ArchiveInspector($directory)`, and removed afterwards.

#### List Messages

```php
use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Enums\MessageFilter;

// List messages from last 30 days
$messages = $client->getMessages(new ListMessagesParamsData(
    cif: '12345678',
    days: 30,  // 1-60 days allowed
    filter: MessageFilter::InvoiceSent,  // Optional: T, P, E, R
));

foreach ($messages->mesaje ?? [] as $message) {
    if ($message->id === null || ! ctype_digit($message->id)) {
        continue; // no usable id: it cannot be downloaded, and must not be keyed on
    }
    echo $message->id;
    echo $message->dataCreare;
    echo $message->tip;
}
```

#### Paginated Messages

```php
use BeeCoded\EFacturaSdk\Data\Invoice\PaginatedMessagesParamsData;

// Using timestamps (milliseconds)
$messages = $client->getMessagesPaginated(new PaginatedMessagesParamsData(
    cif: '12345678',
    startTime: $startTimestampMs,
    endTime: $endTimestampMs,
    page: 1,
    filter: MessageFilter::InvoiceReceived,
));

// Or create from Carbon dates (mutable or immutable)
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
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;

$validation = $client->validateXml($xmlContent, DocumentStandardType::FACT1);

if ($validation->valid) {
    // XML is valid
} else {
    // Validation errors
    $errors = $validation->errors;
    $details = $validation->details;
}
```

`validateXml()`, `convertXmlToPdf()` and `verifySignature()` send the access token only to a service
URL on the e-Factura API host (`api.anaf.ro`). The default `webservicesp.anaf.ro` services get none,
so these calls never refresh the token, and a 401 from them is an `ApiException`.

#### Convert to PDF

```php
use BeeCoded\EFacturaSdk\Enums\DocumentStandardType;

// Convert without validation
$pdfContent = $client->convertXmlToPdf($xmlContent, DocumentStandardType::FACT1);

// Convert with validation first
$pdfContent = $client->convertXmlToPdf($xmlContent, DocumentStandardType::FACT1, validate: true);

file_put_contents('invoice.pdf', $pdfContent);
```

`convertXmlToPdf()` throws `ApiException` with ANAF's status code when ANAF answers with a JSON
error (with or without a JSON content type), or with any body that is not a PDF, such as a
maintenance page (since 4.0; it used to return that body as the PDF).

#### Verify Signature

Pass the invoice XML and the XML holding its signature, both from the archive `downloadDocument()`
returns. They are posted as the multipart files `file` and `signature` to ANAF's
`/api/validate/signature` service (`endpoints.services.verify_signature`; changed in 4.0, see the
CHANGELOG).

```php
$result = $client->verifySignature($invoiceXml, $signatureXml);

if ($result->valid) {
    // Signature is valid
}
```

ANAF does not document this service's answer. How the SDK reads it, and when it throws, is in the
`verifySignature()` docblock of `EFacturaClientInterface`. In short: `valid: false` alone is not
proof of a bad signature.

### Automatic Token Refresh

The SDK automatically refreshes tokens when they're about to expire: at least 120 seconds before expiry, more when you raise `http.timeout`, `retry_times` or `retry_delay`, so a read's last retry never carries an expired token.

**Important:** ANAF uses rotating refresh tokens. When a token is refreshed, both the access token AND refresh token are replaced. The old refresh token becomes invalid.

```php
// $vatNumber: a CUI or CNP; the constructor throws ValidationException otherwise
$client = EFacturaClient::fromTokens($vatNumber, $tokens);

try {
    // Make API calls
    $result = $client->uploadDocument($xml);
    $status = $client->getStatusMessage($uploadId);
} finally {
    // IMPORTANT: a call that throws may already have rotated the tokens
    if ($client->wasTokenRefreshed()) {
        $newTokens = $client->getTokens();

        // You MUST persist ALL new token values
        $storedTokens->update([
            'access_token' => $newTokens->accessToken,
            'refresh_token' => $newTokens->refreshToken,  // Critical! Old one is now invalid
            'expires_at' => $newTokens->expiresAt,
        ]);
    }
}
```

**Multi-worker deployments:** pass a `tokenReloader` and a `tokenPersister` (since 4.0). The client
takes a cache lock before it refreshes, held for the HTTP timeout plus 30 seconds. Under that lock it
calls `tokenReloader` to adopt tokens another worker already rotated, so the reloader must read your
primary store (no read replica, no cache). If `tokenReloader` throws, the call fails with
`AuthenticationException` code 0 and nothing is refreshed. After a refresh it calls
`tokenPersister` with the new `OAuthTokensData` before releasing the lock, so no other worker can
spend the rotated refresh token first. If `tokenPersister` throws, the exception reaches your code;
the client keeps the new tokens and `wasTokenRefreshed()` is still true.

During an upload, a `tokenPersister` exception means the document was **not** sent (the persister
runs before the request), but `UploadOutcome::fromThrowable()` cannot tell your exception from the
upload's and reads it as `Uncertain`. Classify it yourself: the refresh token has rotated, so the new
pair lives only in `$client->getTokens()` and the one in your store is spent. Save `getTokens()` and
treat the document as not sent; if you cannot store them, the company must be authorised again (the
next refresh is refused with 400/401). A persister that never throws avoids the case.

```php
$client = EFacturaClient::fromTokens(
    $vatNumber,
    $tokens,
    tokenReloader: fn () => $this->loadTokens($company),
    tokenPersister: fn (OAuthTokensData $new) => $this->persistTokens($company, $new),
);
```

**Single-worker pattern** (always persist, even on errors):

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

The SDK automatically enforces rate limits before each API call. When a limit is exceeded, a `RateLimitExceededException` is thrown. A call that needs a token refresh refreshes first; a failed refresh counts nothing.

```php
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;

try {
    $result = $client->uploadDocument($xml);
} catch (RateLimitExceededException $e) {
    // Rate limit exceeded
    $remaining = $e->remaining;              // 0 (no calls remaining)
    $retryAfter = $e->retryAfterSeconds;     // Seconds until reset
    $scope = $e->scope;                      // ?RateLimitScope: which limit was hit
    $message = $e->getMessage();             // Human-readable message

    // Wait and retry, or queue for later
    Log::warning("Rate limit hit: {$message}. Retry in {$retryAfter}s");
}
```

`$e->scope` (since 4.0) is a `BeeCoded\EFacturaSdk\Enums\RateLimitScope`, whose values match
`getRemainingQuota()`'s types. `RateLimitScope::Status`, `RateLimitScope::Download` and the per-CUI
scopes block only that upload index, download id or CUI, so a polling batch can skip one item and
carry on. `RateLimitScope::Global` and `RateLimitScope::CompanyLookup` block every call of their
kind. The SDK's `RateLimiter` always sets the scope; it is `null` only when other code throws the
exception without one.

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

#### Status Reads at ANAF's Own Limit

A status check a user explicitly asked for can count against ANAF's own ceiling of 100 reads per message per day (`status_per_day_message_provider`) instead of the SDK's 50. It uses the same per-message counter, so automatic polling still sees those reads and keeps stopping at 50. The first attempt and every retry are checked against the higher ceiling. What that ceiling allows across a window boundary: see the `rate_limits` comment in `config/efactura-sdk.php`.

```php
$status = $client->getStatusMessage($uploadId, useProviderLimit: true);

$rateLimiter->getRemainingQuota('status', $uploadId, useProviderLimit: true);
// ['limit' => 100, 'remaining' => 40, 'resetsIn' => 52000]  // 60 reads already spent
```

The flag exists on the concrete `EFacturaClient` only; `EFacturaClientInterface::getStatusMessage()` is unchanged.

#### Disabling Rate Limiting

For testing or special cases, you can disable rate limiting:

```env
EFACTURA_RATE_LIMIT_ENABLED=false
```

Or check status in code:

```php
$rateLimiter = app(\BeeCoded\EFacturaSdk\Services\RateLimiter::class);

if ($rateLimiter->isEnabled()) {
    // Rate limiting is active
}
```

### Generating Invoice XML

#### Using the UBL Builder

```php
use BeeCoded\EFacturaSdk\Facades\UblBuilder;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Support\DateHelper;

$invoice = new InvoiceData(
    invoiceNumber: 'INV-2024-001',
    issueDate: now(DateHelper::ANAF_TIMEZONE),   // the date in Romania, not the server's
    dueDate: now(DateHelper::ANAF_TIMEZONE)->addDays(30),
    currency: 'RON',
    paymentIban: 'RO49AAAA1B31007593840000',

    supplier: new PartyData(
        registrationName: 'Supplier Company SRL',
        companyId: 'RO12345678',
        address: new AddressData(
            street: 'Str. Exemplu Nr. 1',
            city: 'Bucuresti',
            postalZone: '010101',
            county: 'Bucuresti',  // Filed as RO-B
            bucharestSector: 1,   // Filed as city SECTOR1 (BR-RO-100)
        ),
        registrationNumber: 'J40/1234/2020',  // Filed as CompanyLegalForm (BT-33)
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
            taxAmount: 190.00,   // Pre-computed: 10 * 100.00 * 0.19
            taxPercent: 19,
            unitCode: 'HUR',     // Hours
            description: 'Consultanta IT luna ianuarie',
        ),
        new InvoiceLineData(
            name: 'Licenta software',
            quantity: '1',
            unitPrice: '500.00',  // Decimal strings are filed exactly
            taxAmount: '95.00',   // Pre-computed: 1 * 500.00 * 0.19
            taxPercent: '19',
            unitCode: 'C62',     // Each
        ),
    ],
);

// Generate UBL 2.1 XML
$xml = UblBuilder::generateInvoiceXml($invoice);

// Or run every check without keeping the XML (throws ValidationException)
UblBuilder::validateInvoice($invoice);
```

**Dates.** `issueDate`, `dueDate` and the other dates are filed as calendar dates (`Y-m-d`) and
are not converted between timezones. ANAF reads them as dates in Romania, so build them in
`Europe/Bucharest` (`DateHelper::ANAF_TIMEZONE`): on a UTC server, `now()` between midnight and
02:00/03:00 Bucharest time is still the previous day.

`UblBuilderInterface::validateInvoice(InvoiceData)` builds the XML and discards it, so it runs
exactly the checks `generateInvoiceXml()` runs. A class that implements `UblBuilderInterface`
must add it (4.0).

**What the builder refuses.** These documents throw `ValidationException` instead of reaching
ANAF, which would reject them or file a wrong figure:

- an invoice (not a credit note) with a positive amount due and no `dueDate` (a blank string counts
  as none) (BR-CO-25). A blank `dueDate` on any other document, or an `issueDate`, `dueDate` or
  `taxPointDate` that is not a real date (unparseable, or impossible like `2024-02-30`), or a slash
  date that starts with the day or month (`05/03/2024`, which PHP reads month-first), is refused
  with `ValidationException` (`issue_date_invalid`, `due_date_invalid`, `tax_point_date_invalid`).
  The `get…AsCarbon()` getters throw `InvalidArgumentException`;
- a currency code that is not three uppercase letters, or a country code that is not two (empty
  included);
- a line `unitCode` that is not two or three uppercase letters or digits, an empty one included
  (the shape of a UN/ECE Rec. 20/21 code; the code list itself is not checked; key
  `line_unit_code_invalid`);
- a line whose `taxAmount` sign differs from its quantity's (`line_tax_sign_mismatch`);
- a required field that is empty after trimming (invoice number, issue date, names, company ids,
  street, city, line names, the county of a Romanian address); any element the builder would file
  blank, such as a line `id` of `''` or a company id that is only the `RO` prefix (the message names
  the element, key `element_blank`);
- text that is not valid UTF-8 or contains a character XML 1.0 cannot carry (control characters
  other than tab, line feed and carriage return; U+FFFE, U+FFFF). The message names the field,
  for example `lines.0.name` or `supplier.address.street`;
- a Romanian Bucharest address whose sector cannot be found (see Address Sanitization);
- a non-zero `taxAmountRon` on a document without standard-rated lines;
- a VAT breakdown outside S whose summed line tax rounds to a non-zero amount, for example three Z lines
  of `0.004` (BR-Z-09, BR-O-09);
- an amount with more decimals than it is filed with (see Invoice Calculations).

The supplier's `registrationNumber` (for example the trade register number) is filed as
`PartyLegalEntity/CompanyLegalForm` (BT-33, at most 1000 characters); a blank one is omitted. The
buyer's is not filed: EN 16931 has no such term for the buyer. A whitespace-only line
`description`, `postalZone` or `paymentIban` is omitted the same way.

#### Explicit VAT Categories: Reverse Charge, Exempt, Export and Intra-Community

`InvoiceLineData` accepts `?TaxCategoryId $taxCategoryId = null` after `taxPercent`.
Omitting it or passing null preserves the existing supplier/rate inference and exact serialized
keys/order. An explicit category serializes as the appended `taxCategoryId` string, such as `AE`.
`InvoiceLineData::from()` accepts that serialized shape. Each transformation uses the current
value: serializing null does not prevent a later `ReverseCharge` assignment from being included,
and setting it back to null omits the key again.

```php
use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;

$line = new InvoiceLineData(
    name: 'Eligible reverse-charge transaction',
    quantity: 1.0,
    unitPrice: 1000.00,
    taxAmount: 0.0,
    taxPercent: 0.0,
    taxCategoryId: TaxCategoryId::ReverseCharge,
);
```

- `ReverseCharge` has the UBL value `AE`. Both raw `taxPercent` and raw `taxAmount` must be
  exactly zero; a small nonzero value that would round to zero is rejected.
- Both supplier and customer must have `isVatPayer: true` and usable, nonempty `companyId`
  values. The builder uses its existing VAT-identifier normalization for their `PartyTaxScheme`
  identifiers. This does not verify registration or the transaction's legal eligibility.
- Explicit `Standard` (`S`), `ZeroRated` (`Z`) and `NotSubject` (`O`) must match the category
  that the existing supplier/rate inference would choose. They cannot override it. `O` cannot
  coexist with any other category in the same invoice.
- The builder and invoice total helpers group by resolved category plus normalized rate.
  AE at 0% and Z at 0% therefore have separate subtotals; SDK callers may combine valid AE/Z/S
  lines. AE emits `AE` and `0.00` on the line and tax subtotal, with zero category tax and subtotal
  `TaxExemptionReasonCode` `VATEX-EU-AE` plus `TaxExemptionReason` `Taxare inversa`.
- The existing document sign contract is unchanged: Invoice 380 preserves input signs,
  including negative invoices, and CreditNote 381 negates quantities and amounts once. AE tax
  remains zero. RON invoices must omit `taxAmountRon`; non-RON invoices still require it,
  including `taxAmountRon: 0.0` for any invoice without standard-rated lines. A nonzero RON VAT
  total is rejected when a document has no standard-rated lines.

The caller selects the legally applicable treatment and submission profile. Setting AE does not
establish that an arbitrary domestic or cross-border transaction qualifies, and local SDK checks
are not full external-profile validation. A zero rate by itself continues to infer Z for a VAT
payer, not AE.

`Exempt` (`E`), `Export` (`G`) and `IntraCommunitySupply` (`K`) are opt-in the same way: they are
never inferred, need a VAT-registered supplier, and carry an exactly zero rate and tax. Each files
`Percent` `0.00` and an exemption reason in its VAT breakdown. `InvoiceData::$exemptionReasons`
takes one `ExemptionReasonData{category, ?code, ?text}` per category:

| Category | Reason code (BT-121) | Reason text (BT-120, ≤ 100 characters) | Also requires |
|---|---|---|---|
| `AE` | always `VATEX-EU-AE` | yours, or `Taxare inversa` | supplier and customer VAT ids |
| `E` | yours, from the VATEX list (BR-CL-22) | yours; a code or a text is required (BR-E-10) | supplier VAT id |
| `G` | always `VATEX-EU-G` | yours, optional | supplier VAT id |
| `K` | always `VATEX-EU-IC` | yours, optional | supplier and customer VAT ids; a `delivery` with an actual delivery date and a delivery address |
| `O` | always `VATEX-EU-O` | none | not accepted in `exemptionReasons` |

A reason for `S`, `Z` or `O`, two reasons for one category, a reason for a category no line uses,
or a code other than the fixed one is refused. ANAF's BR-IC-11/12 would also accept an invoicing
period or a country alone for `K`; the SDK deliberately asks for the date and a full address.

```php
use BeeCoded\EFacturaSdk\Data\Invoice\ExemptionReasonData;

$invoice = new InvoiceData(
    // ...
    lines: [new InvoiceLineData('Servicii medicale', 1, '500.00', '0', taxPercent: '0', taxCategoryId: TaxCategoryId::Exempt)],
    exemptionReasons: [new ExemptionReasonData(TaxCategoryId::Exempt, 'VATEX-EU-132-1C', 'Scutit conform art. 292 alin. (1) lit. b)')],
);
```

#### Preceding Invoices, Notes, VAT Point Date Code and Delivery

All are optional and appended to the constructor; when empty or `null` they are left out of
`toArray()`/`toJson()` and of the XML.

- `precedingInvoices: list<PrecedingInvoiceData{number, ?issueDate}>` — BG-3, at most 500
  (BR-RO-A500), each with a non-blank number (BR-55) of at most 200 characters. Each is filed as a
  `cac:BillingReference`, with `cbc:IssueDate` (BT-26) when given; a blank or unparseable issue date
  is refused with `ValidationException`.
  `precedingInvoiceNumber` is deprecated but still read: alone it is one reference; together with
  `precedingInvoices` it must name that list's only entry, or the builder refuses the document.
  `InvoiceData::getPrecedingInvoices()` returns the resolved list.
- `notes: list<string>` — BT-22, at most 20 notes of at most 300 characters (BR-RO-L300), filed as
  `cbc:Note` after the type code. A blank note is refused.
- `vatPointDateCode: ?string` — BT-8, one of `'3'` (issue date), `'35'` (delivery date) or `'432'`
  (payment date), filed as `cac:InvoicePeriod/cbc:DescriptionCode`. It cannot be combined with
  `taxPointDate` (BR-CO-03).
- `delivery: ?DeliveryData{actualDeliveryDate, ?address}` — BG-13, filed as `cac:Delivery` with
  `cbc:ActualDeliveryDate` (BT-72) and `cac:DeliveryLocation/cac:Address`, whose country is BT-80.
  A delivery address needs a street, a city and a county or region in every country; a Romanian
  one is filed like a party address (ISO county code, `SECTORn` in Bucharest). A blank or
  unparseable delivery date is refused with `ValidationException`, on a category K document too.

```php
use BeeCoded\EFacturaSdk\Data\Invoice\DeliveryData;
use BeeCoded\EFacturaSdk\Data\Invoice\PrecedingInvoiceData;

$invoice = new InvoiceData(
    // ...
    precedingInvoices: [new PrecedingInvoiceData('INV-2024-001', '2024-03-15')],
    notes: ['Factura emisa conform contractului 12/2024'],
    delivery: new DeliveryData('2024-03-14', new AddressData('Hauptstr. 1', 'Berlin', '10115', 'Berlin', 'DE')),
);
```

#### VAT Applicability Date (BT-7)

`InvoiceData` accepts an optional `CarbonInterface|string|null $taxPointDate = null` (BT-7).
Supply the caller-selected tax date explicitly; the SDK never chooses today. Left null, it is not
filed and `toArray()`/`toJson()` leave it out; a non-null date is appended to serialization.
`InvoiceData::from()` reads it the same way: `::from()` keeps a date string as given and reads it
the same strict way.

```php
$invoice = new InvoiceData(
    invoiceNumber: 'INV-2024-001',
    issueDate: '2024-03-15',
    supplier: $supplier,
    customer: $customer,
    lines: $lines,
    taxPointDate: '2024-03-10',
);
```

`getTaxPointDateAsCarbon()` returns a copy or null. Direct construction accepts mutable and
immutable Carbon dates with the same normalization as issue/due dates. Empty or unparseable
strings fail when read for XML generation. UBL Invoice places `TaxPointDate` after
`InvoiceTypeCode` and before `DocumentCurrencyCode`; CreditNote places it after `IssueDate`
and before `CreditNoteTypeCode`. Credit-note payment due dates stay in `PaymentMeans`.

Either document VAT or RON VAT can legitimately round to `0.00` while the other remains nonzero.
The builder accepts both boundaries and still rejects opposite signs when both rounded amounts
are nonzero.

#### Payable Rounding (BT-114)

`InvoiceData` accepts `float|string|null $payableRoundingAmount = null`. It is a signed amount in
the document currency, supplied by your accounting calculation; the SDK does not choose a rounding
correction. No settings are required.

- Left null, it is not filed and is left out of serialized data.
- Zero emits no `PayableRoundingAmount` element. A nonzero amount emits BT-114 immediately
  before `PayableAmount` (BT-115).
- Supply an amount with at most 2 decimals: a string such as `'0.01'`, or a float within binary
  noise of one, below 2^46. Anything finer, or non-finite, throws `ValidationException` naming
  `payableRoundingAmount` wherever it is read (the helpers, `toArray()` and the builder), including
  values assigned after construction or populated through Spatie hydration. The value itself is
  kept as given; `toArray()` returns it unchanged.
- `getTotalIncludingVat()` is the final payable amount: net plus VAT plus optional rounding.
- `getTotalIncludingVatBeforeRounding()` returns net plus VAT (BT-112), excluding rounding.
  Use it when you opt into rounding and need the pre-rounding tax-inclusive total.

```php
$invoice->payableRoundingAmount = 0.01;
$invoice->getTotalIncludingVatBeforeRounding(); // e.g. 121.00
$invoice->getTotalIncludingVat();               // 121.01 payable
```

Rounding does not change the net amount, VAT or taxable base. Invoice 380 XML uses the helpers'
input sign; CreditNote 381 XML negates the input totals and rounding consistently with its
existing line convention. Do not add rounding again after calling `getTotalIncludingVat()`.
This is independent of the optional tax applicability date; neither field is required to use the
other.

#### Non-RON Invoices: `taxAmountRon` (BT-111)

`InvoiceData` has an optional `$taxAmountRon` (BT-111, `float|string|null`, at most 2 decimals)
that is **required when `currency !== 'RON'`** and **rejected when it is**; otherwise
`ValidationException`. A document without standard-rated lines owes no VAT, so its
`taxAmountRon` must be exactly zero.

ANAF cannot verify a conversion, so pass the converted amount (not a rate; the rate is never
transmitted):

```php
$invoice = new InvoiceData(
    // ...
    currency: 'EUR',
    taxAmountRon: 944.30,   // your ledger's BNR-rate figure for the VAT total
);
```

#### Creating a Credit Note

```php
use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;

$creditNote = new InvoiceData(
    invoiceNumber: 'CN-2024-001',
    issueDate: now(DateHelper::ANAF_TIMEZONE),
    currency: 'RON',
    invoiceTypeCode: InvoiceTypeCode::CreditNote,
    precedingInvoices: [new PrecedingInvoiceData('INV-2024-001', '2024-03-15')],  // BG-3: the original invoice

    supplier: $supplier,
    customer: $customer,

    lines: [
        new InvoiceLineData(
            name: 'Returned product',
            quantity: -2,       // Negative = items being credited/returned
            unitPrice: 100.00,
            taxAmount: -38.00,  // Negative — sign follows quantity
            taxPercent: 19,
        ),
    ],
);

$xml = UblBuilder::generateInvoiceXml($creditNote);
```

##### Credit Note Quantity Handling (Breaking Change in v1.1)

**The SDK automatically negates quantities for credit notes.** ANAF treats the `<CreditNote>` document type as inherently negative, so line quantities must be positive in the XML. The SDK handles this sign-flip internally.

**How it works:** pass quantities with their business meaning, and the SDK converts them for ANAF:

| You pass | SDK sends to ANAF | Meaning |
|----------|-------------------|---------|
| `quantity: -2` | `+2` | Crediting 2 returned items |
| `quantity: 1` | `-1` | Debiting back a discount line |

**Example — credit note with a discount reversal:**

```php
$creditNote = new InvoiceData(
    invoiceNumber: 'CN-2024-002',
    issueDate: now(DateHelper::ANAF_TIMEZONE),
    currency: 'RON',
    invoiceTypeCode: InvoiceTypeCode::CreditNote,
    precedingInvoices: [new PrecedingInvoiceData('INV-2024-050')],

    supplier: $supplier,
    customer: $customer,

    lines: [
        // Crediting 3 returned items (negative → becomes positive for ANAF)
        new InvoiceLineData(
            name: 'Returned product',
            quantity: -3,
            unitPrice: 150.00,
            taxAmount: -85.50,  // Pre-computed: -3 * 150.00 * 0.19 — sign follows quantity
            taxPercent: 19,
        ),
        // Reversing a discount that was on the original invoice (positive → becomes negative for ANAF)
        new InvoiceLineData(
            name: 'Discount reversal',
            quantity: 1,
            unitPrice: 50.00,
            taxAmount: 9.50,    // Pre-computed: 1 * 50.00 * 0.19
            taxPercent: 19,
        ),
    ],
);

$xml = UblBuilder::generateInvoiceXml($creditNote);
```

> **Upgrading from v1.0:** If your code was passing positive quantities for credit note lines and relying on them going to ANAF as-is, you must now pass **negative** quantities instead (the SDK will negate them to positive). If you were already passing negative quantities (as documented), no changes are needed — the SDK now correctly converts them for ANAF.

#### Invoice Calculations

```php
// Line-level calculations
$line = new InvoiceLineData(
    name: 'Product',
    quantity: 5,
    unitPrice: 100.00,
    taxAmount: 95.00,  // Pre-computed VAT for this line
    taxPercent: 19,
);

$line->getLineTotal();        // 500.00 (quantity * unitPrice)
$line->getTaxAmount();        // 95.00 (returns the pre-computed taxAmount)
$line->getLineTotalWithTax(); // 595.00

// Invoice-level calculations. These reproduce the filed XML totals exactly:
// getTotalExcludingVat() sums per-line ROUNDED net amounts (as cac:LegalMonetaryTotal does),
// and getTotalVat() rounds once per resolved category/rate group (as cac:TaxSubtotal does).
$invoice->getTotalExcludingVat(); // Sum of all line totals
$invoice->getTotalVat();          // Sum of all per-line taxAmount values
$invoice->getTotalIncludingVatBeforeRounding(); // Net plus VAT, before payable rounding
$invoice->getTotalIncludingVat();               // Amount payable, including optional rounding
```

**Money is exact decimal, and nothing is rounded silently.** Amount properties are typed
`float|string` and keep the caller's value as given (also in `toArray()`). Wherever an amount is
used it is read as an exact decimal at the precision it is filed with: `quantity`, `unitPrice` and
`taxAmount` at 6 decimals, `taxPercent`, `taxAmountRon` and `payableRoundingAmount` at 2. Every
amount is then computed in decimal arithmetic: each line net is rounded half-up once, and each VAT
group's tax sum is rounded half-up once. So `3 × 1.005` files `3.02`, and per-line taxes of
`1285.8489 + 130.7061` file a group VAT of `1416.56`; float arithmetic gave `3.01` and `1416.55` on
PHP 8.4+. The helpers above return the same amounts as floats.

- Pass decimal strings (`'1.005'`, `'12345678901.234567'`) when you hold exact figures: they are
  filed verbatim, with at most that many decimals (trailing zeros are fine).
- A float is accepted when it differs from its rounded rendering only by binary noise (`0.1 + 0.2`
  reads as `0.30`), and only below 2^46 for 2-decimal fields and 2^33 for 6-decimal fields, where a
  double still separates adjacent steps.
- Anything else — more decimals (`1/3` as a quantity, `19.001` as a rate, `944.3049` as
  `taxAmountRon`), `INF`, `NAN`, a non-numeric string, or a float past those bounds, such as a large
  accumulated float sum — throws `ValidationException` naming the field (`lines.0.quantity`,
  `taxAmountRon`). Round in your own ledger first, or pass a string.
- `::validateAndCreate()` checks the amounts as `numeric` (`taxPercent` also `min:0`); the precision
  is checked when the amount is used.

See [CHANGELOG.md](CHANGELOG.md) for upgrade notes.

#### Why `taxAmount` is Required (Breaking Change in v2.0)

In v1.x, the SDK calculated VAT amounts internally by grouping lines by tax rate and multiplying `sum_of_base_amounts × tax_rate`. This caused **rounding discrepancies** when your application used tax-included pricing.

**The problem:**

When a line item has a tax-included price (e.g., 100.00 RON including 19% VAT), your application extracts the base price by subtraction:

```
base = round(100.00 / 1.19, 2) = 84.03
vat  = 100.00 - 84.03 = 15.97
```

But when the SDK grouped multiple such lines and recalculated VAT from the grouped base:

```
grouped_base = 84.03 + 84.03 = 168.06
grouped_vat  = round(168.06 × 0.19, 2) = 31.93
```

Your application computed `15.97 + 15.97 = 31.94`. The SDK computed `31.93`. This 0.01 RON difference meant the XML total sent to ANAF didn't match your local invoice total.

**The fix:**

Starting in v2.0, `taxAmount` is a **required** parameter on `InvoiceLineData`. You pass the VAT amount you already computed for each line, and the SDK uses it directly instead of recalculating. This guarantees the XML total matches your application's total exactly.

**How to compute `taxAmount`:**

| Pricing model | Formula | Example |
|---------------|---------|---------|
| Tax-exclusive (net price) | `round(quantity × unitPrice × taxPercent / 100, 2)` | qty=2, price=100, 19% → `38.00` |
| Tax-inclusive (gross price) | `grossTotal - round(grossTotal / (1 + taxPercent / 100), 2)` | gross=200, 19% → `200 - 168.07 = 31.93` |

The key rule: **whatever VAT amount your application stores for the line item, pass that exact value as `taxAmount`**. The SDK will use it as-is.

**`taxAmount` sign convention:**

The `taxAmount` sign must follow the quantity:
- Positive quantity → positive `taxAmount`
- Negative quantity (credit note lines) → negative `taxAmount`

A line whose non-zero `taxAmount` has the other sign is refused (`line_tax_sign_mismatch`).

The SDK's credit note sign-flip (negating quantities for ANAF) also applies to `taxAmount` internally — you don't need to handle this yourself.

> **Upgrading from v1.x:** Add `taxAmount` to every `new InvoiceLineData(...)` call. If you were using net pricing (tax-exclusive `unitPrice`), compute it as `round(round(quantity * unitPrice, 2) * taxPercent / 100, 2)`. If you were using tax-included pricing, pass the VAT amount you already extracted from the gross total.

#### Address Sanitization

Romanian addresses are automatically sanitized to ISO 3166-2:RO format:

```php
// County names are normalized
'Cluj' -> 'RO-CJ'
'Judetul Cluj' -> 'RO-CJ'
'CJ' -> 'RO-CJ'
'BUCURESTI' -> 'RO-B'

// Bucharest sectors are extracted
'Sector 3' -> 'RO-B' (with sector in address)
'Sectorul 1, Str. Exemplu' -> extracts sector

// Diacritics are handled (precomposed or decomposed)
'Brașov' -> 'RO-BV'
'Constanța' -> 'RO-CT'
```

- Only addresses with `countryCode: 'RO'` are rewritten (BR-RO-100/101/110/111 apply only to RO).
  A foreign county such as Barcelona's `B` or an Italian `Sector 3` is filed as given;
  a blank one is not filed.
- In Bucharest (`RO-B`) the city is filed as `SECTOR1`…`SECTOR6` (BR-RO-100/101). The sector comes
  from `AddressData::$bucharestSector` (1–6) when set, else from a sector named in the city or the
  county (`Sector 2`, `S.2`, `Sectorul 2`). A Bucharest address without one throws
  `ValidationException`; so does a `bucharestSector` outside 1–6 or on a non-Bucharest address.
  The required-city and 50-character checks apply to the filed city, so a Bucharest address with a
  sector may give any city.
- `AddressSanitizer::canonicalize(AddressData $address)` returns `['county' => ?string, 'city' =>
  string]` exactly as the builder files them, so you can show or compare the filed values.

### Company Lookup

Query ANAF for company information (no authentication required):

```php
use BeeCoded\EFacturaSdk\Facades\AnafDetails;

// Single company lookup
$result = AnafDetails::getCompanyData('RO12345678');

if ($company = $result->first()) {
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
    $company->isActive();      // Not inactive and not deregistered (radiat)
    $company->isInactive;
    $company->isDeregistered;

    // Trade-registry status (stare_inregistrare)
    $company->isRegistered();           // status is "INREGISTRAT"
    $company->registrationStatus;       // RegistrationStatus enum (Registered|Deregistered|Unknown)
    $company->registrationStatusRaw;    // e.g. "RADIERE din data 29.03.2024"
    $company->registrationStatusDate;   // Carbon date parsed from the status string
    $company->registrationDate;         // Fiscal registration date (data_inregistrare)

    // RO e-Factura registry
    $company->isRegisteredInEFactura;   // enrolled in Registrul RO e-Factura
    $company->eFacturaRegistrationDate;

    // VAT registration history
    $company->vatPeriods;               // VatPeriodData[] (perioade_TVA)

    // Detailed addresses
    $company->headquartersAddress;     // AddressData object
    $company->fiscalDomicileAddress;   // AddressData object
    $company->getPrimaryAddress();     // Returns headquarters or fiscal
}

// Batch lookup (up to 100 companies — ANAF v9 limit)
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

// Validate VAT code format + mod-11 checksum
$isValid = AnafDetails::isValidVatCode('RO14399840'); // true
$isValid = AnafDetails::isValidVatCode('RO12345678'); // false — checksum fails
```

> **Note (v2.2.0):** a lookup whose CUIs are all not-found now returns `success === true`
> with the CUIs in `$result->notFound` (previously a single not-found CUI returned a
> failure result). Branch on `hasNotFound()` / `hasCompanies()`, not on `success`, to
> distinguish outcomes. ANAF returns this case as **HTTP 404** with a `{found, notFound}`
> body — the SDK treats that documented "none found" response as a not-found result, not an error.

> **Strict lookups (4.0):** `new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 5)`
> returns a company only when ANAF's answer confirms the request (each requested CUI answered
> once, today's date echoed, a name, a known registration state, valid inactivity dates). Any
> other answer is a failure with `$result->failureKind === CompanyLookupFailureKind::InvalidResponse`;
> transport and ANAF errors have `CompanyLookupFailureKind::Transport`. `failureKind` is `null`
> on success and when the SDK refused the input before sending. Each CUI is sent once, in its
> canonical form (`VatNumberValidator::canonical()`: no spaces, no `RO` prefix).

> **Detecting radiated (struck-off) companies:** ANAF reports trade-registry strike-off
> in `date_generale.stare_inregistrare` ("RADIERE din data ..."), which the SDK maps to
> `registrationStatus`, flips `isDeregistered` to `true`, and makes `isActive()` return
> `false`. This is distinct from fiscal inactivity (`isInactive`) and from leaving the
> TVA-la-încasare registry (`rtvaiDetails->actType === 'Radiere'`).

> **Rate limit:** ANAF limits the company-lookup endpoint to **1 request/second**. The SDK
> enforces this and throws `BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException`
> (HTTP 429, with `->retryAfterSeconds`) when exceeded. This is independent of the
> per-request **payload** cap of 100 CUIs. Disable via `efactura-sdk.rate_limits.enabled = false`.
> An HTTP 429 from ANAF throws the same exception with `->fromProvider === true`.

### Validators

#### VAT Number Validation

```php
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;

// isValid() checks format AND the mod-11 checksum
VatNumberValidator::isValid('RO14399840');  // true
VatNumberValidator::isValid('14399840');    // true (RO prefix is optional)
VatNumberValidator::isValid('RO12345678');  // false — well-formed, but the checksum fails
VatNumberValidator::isValid('invalid');     // false

// isValidFormat() skips the checksum (2-10 digits without a leading zero, or a 13-digit CNP;
// spaces and an RO prefix are ignored)
VatNumberValidator::isValidFormat('12345678'); // true

VatNumberValidator::normalize('12345678');  // 'RO12345678' (a CUI is format-checked only, without its control digit; a CNP must be valid and comes back bare, without RO)
VatNumberValidator::stripPrefix('RO14399840'); // '14399840'
VatNumberValidator::canonical(' RO 1439 9840 '); // '14399840' (null when malformed)

// The VAT identifier exactly as the builder files it (BT-31/BT-48), for any country
VatNumberValidator::withCountryPrefix('123456789', 'DE');   // 'DE123456789'
VatNumberValidator::withCountryPrefix('GR123456789', 'RO'); // 'EL123456789' (Greece files under EL)
VatNumberValidator::withCountryPrefix('XI123456789', 'GB'); // 'XI123456789' (kept)
```

#### CNP Validation

```php
use BeeCoded\EFacturaSdk\Support\Validators\CnpValidator;

CnpValidator::isValid('1800101221144'); // true (valid date + checksum)
CnpValidator::isValid('1234567890123'); // false (month '45' is not a valid date)
CnpValidator::isValid('0000000000000'); // true (special ANAF case)
```

### Date Helpers

```php
use BeeCoded\EFacturaSdk\Support\DateHelper;

// Format for ANAF API
DateHelper::formatForAnaf(now(DateHelper::ANAF_TIMEZONE)); // '2024-01-15': a Carbon keeps its own timezone, so pin it to ANAF's
DateHelper::formatForAnaf('2024-01-15');    // '2024-01-15'
DateHelper::formatForAnaf(1710459000);      // '2024-03-15': a Unix timestamp (s or ms) is read as a Bucharest date
DateHelper::formatForAnaf('2024-02-30');    // throws InvalidArgumentException: no such day

// The strict readers behind them
DateHelper::exact('15.03.2024', 'd.m.Y');   // Carbon, or null unless the string is exactly that real date
DateHelper::parse('2024-03-15 10:00');      // Carbon; throws InvalidArgumentException for a blank, unparseable or impossible date, or a day/month-first slash date

// Timestamps in milliseconds (for paginated messages)
DateHelper::toTimestamp(now());             // 1705312800000

// Day range for queries — returns a STRING-KEYED array: ['start' => int, 'end' => int]
['start' => $start, 'end' => $end] = DateHelper::getDayRange('2024-01-15');
// Boundaries are resolved in your app's timezone (config/app.php 'timezone'),
// so the exact millisecond values depend on it:
//
//   UTC (Laravel's default):  $start = 1705276800000, $end = 1705363199999
//   Europe/Bucharest:         $start = 1705269600000, $end = 1705355999999
//
// $start is 00:00:00.000 and $end is 23:59:59.999 of that local day either way.

// Validate days parameter
DateHelper::isValidDaysParameter(30);  // true (1-60 allowed)
DateHelper::isValidDaysParameter(100); // false
```

### Immutable Dates (CarbonImmutable)

> **Since v2.3.0:** the date fields you pass data *into* accept any `Carbon\CarbonInterface`
> implementation — see the list below.

Apps that call `Date::use(CarbonImmutable::class)` get a `CarbonImmutable` from every Eloquent
`datetime` cast. `CarbonImmutable` is **not** a subclass of `Carbon` — both implement
`CarbonInterface` — so before v2.3.0 passing a cast in either threw a `TypeError` (on the
Carbon-only params) or, where the field also accepted a `string` and the caller had no
`declare(strict_types=1)`, was silently coerced to a timezone-less string via `__toString()`.
You can now pass model casts straight in:

```php
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\PaginatedMessagesParamsData;
use BeeCoded\EFacturaSdk\Support\DateHelper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

Date::use(CarbonImmutable::class);

new InvoiceData(
    invoiceNumber: $invoice->number,
    issueDate: $invoice->issued_at,   // CarbonImmutable — accepted
    dueDate: $invoice->due_at,
    // ...
);

new OAuthTokensData(
    accessToken: $token->access_token,
    refreshToken: $token->refresh_token,
    expiresAt: $token->expires_at,    // CarbonImmutable — accepted
);

DateHelper::formatForAnaf(CarbonImmutable::now());
PaginatedMessagesParamsData::fromDateRange(cif: '12345678', startDate: $from, endDate: $to);
```

**Where it applies.** These are the entry points that take a date from you:

| Entry point | Date parameters |
|---|---|
| `OAuthTokensData::__construct` | `$expiresAt` |
| `EFacturaClient::__construct` | `$expiresAt` |
| `InvoiceData::__construct` | `$issueDate`, `$dueDate`, `$taxPointDate` |
| `PrecedingInvoiceData::__construct`, `DeliveryData::__construct` | `$issueDate`, `$actualDeliveryDate` |
| `PaginatedMessagesParamsData::fromDateRange` | `$startDate`, `$endDate` |
| `DateHelper::formatForAnaf`, `toTimestamp`, `getDayRange`, `daysBetween` | date arguments |

The company-lookup DTOs (`CompanyData`, `VatRegistrationData`, `SplitVatData`,
`InactiveStatusData`, `VatPeriodData`) are **not** included: they are built by the SDK from ANAF
responses and never receive a date from you, so their date parameters remain `?Carbon`.

**What you get back is unchanged.** An immutable date is converted to a mutable `Carbon` on the
way in (timezone and microseconds preserved); a date that is already a mutable `Carbon` is stored
as the same instance, and a `string` is left as a `string`. Reading never gives you a
`CarbonImmutable`: `$tokens->expiresAt` is `?Carbon`, `InvoiceData::$issueDate` is
`Carbon|string`, and `getIssueDateAsCarbon()` returns a concrete `Carbon`.

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

Valid codes per ANAF BR-RO-020 schematron rule:

```php
// Invoice document types (generates <Invoice> XML)
InvoiceTypeCode::CommercialInvoice  // '380' - Standard commercial invoice
InvoiceTypeCode::CorrectedInvoice   // '384' - Corrected invoice
InvoiceTypeCode::SelfBilledInvoice  // '389' - Self-billed invoice (autofactura)
InvoiceTypeCode::AccountingInvoice  // '751' - Invoice for accounting purposes

// Credit note (generates <CreditNote> XML)
InvoiceTypeCode::CreditNote         // '381' - Credit note

// Helper methods
$type->isCreditNote();  // true for 381
$type->isInvoice();     // true for 380, 384, 389, 751
```

**Note:** The SDK automatically generates the correct UBL document type. Code 381 generates a `<CreditNote>` document with `<CreditNoteTypeCode>` and `<CreditNoteLine>` elements, while all other codes generate an `<Invoice>` document.

## Exception Handling

```php
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Exceptions\RequestNotSentException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;

try {
    $result = $client->uploadDocument($xml);
} catch (AuthenticationException $e) {
    // getCode(): 400/401 = ANAF refused the grant or token -> reconnect the company.
    // 0 = OAuth server unreachable, refresh-lock timeout or lock store failure, the token reloader
    // failed, or missing OAuth configuration -> retry later / fix the configuration. Another code = the OAuth server's HTTP status.
    // "Token refresh previously failed": this client is spent; create a new one.
} catch (RateLimitExceededException $e) {
    // The SDK's own limit: nothing was sent.
    $retryAfter = $e->retryAfterSeconds;  // Seconds until limit resets
    $scope = $e->scope;                   // ?RateLimitScope, e.g. RateLimitScope::Global
} catch (ValidationException $e) {
    // Refused locally (empty XML, invalid parameters): nothing was sent.
} catch (RequestNotSentException $e) {
    // No attempt of the call reached ANAF (DNS, connect, TLS, connect-phase timeout, or the cache
    // store behind the SDK's rate limiter failed; $e->getPrevious() is the cause): safe to resend.
} catch (ApiException $e) {
    // 429: ANAF's own rate limit, not filed (it is an ApiException, not RateLimitExceededException).
    // Other 4xx except 408: ANAF refused the upload, not filed; $e->getMessage() is its reason.
    // 3xx (an upload never follows a redirect), 408, 500 (no response), 502 (ANAF 5xx) or a 2xx
    // we could not read: POSSIBLY FILED.
    // Reconcile with getMessages() before resending.
    $statusCode = $e->statusCode;
    $details = $e->details;
}
```

`UploadOutcome::fromResponse()` / `UploadOutcome::fromThrowable()` apply exactly these rules and
return an `UploadOutcomeKind` (`Accepted`, `Refused`, `NotSent`, `Uncertain`) with a reason, the
index and ANAF's text. The invoice builder throws `ValidationException` too (see "What the builder
refuses"); `fromThrowable()` reads it as `Refused` / `LocalRefusal`, because nothing was sent.
`XmlParsingException` reaches you only from direct `XmlParser` calls; no client method lets it through.
`ArchiveInspector` throws `ArchiveLimitException` (retryable) or `ArchiveMismatchException` (final),
both `ArchiveException`s (see "Verify a Response Archive").

## Testing

When testing your application, you can mock the SDK services:

```php
use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;

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

## AI Assistant Integration (MCP)

This package includes a small MCP server for AI coding assistants. It reads this package's
README.md, CHANGELOG.md and config/*.php from `vendor/` on every call, so it always matches the
installed version. It has no documentation of its own.

**Setup:** Add to your AI tool's MCP configuration:

```json
{
  "mcpServers": {
    "efactura-sdk": {
      "command": "node",
      "args": ["vendor/bee-coded/laravel-efactura-sdk/mcp/dist/index.js"]
    }
  }
}
```

Requires Node.js 18+.

The MCP server provides these tools:

| Tool | Description |
|------|-------------|
| `get-sdk-docs` | One README section. The topic is the section's heading anchor, such as `configuration` or `generating-invoice-xml`. An unknown topic returns the list of topics. |
| `get-changelog` | The whole CHANGELOG (the 3.x→4.0 upgrade list), or one section of it by its heading anchor. |
| `get-config-reference` | The configuration files (`config/*.php`), with their comments. |

## License

Licensed under the Apache License, Version 2.0. See [LICENSE](LICENSE) for details.
