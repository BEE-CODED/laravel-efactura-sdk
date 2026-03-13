export const sdkDocsContent: Record<string, string> = {
  overview: `# Laravel e-Factura SDK — Overview

A Laravel package for integrating with Romania's ANAF e-Factura (electronic invoicing) system.

## What the Package Does

The SDK handles the full lifecycle of Romanian electronic invoicing:

- **UBL 2.1 XML Generation** — Builds CIUS-RO compliant invoice XML from PHP DTOs
- **OAuth 2.0 Authentication** — Complete OAuth flow with JWT tokens and automatic token refresh
- **Document Operations** — Upload invoices, poll status, and download processed documents
- **Company Lookup** — Query ANAF for company details (VAT status, addresses, etc.) without authentication
- **XML Validation** — Validate XML against ANAF schemas before upload
- **PDF Conversion** — Convert XML invoices to PDF format
- **Rate Limiting** — Built-in protection against exceeding ANAF API quotas

## Package Namespace

All classes live under \`BeeCoded\\EFacturaSdk\`.

## High-Level Architecture

\`\`\`
Facades (UblBuilder, EFacturaSdkAuth, AnafDetails)
    ↓
Services (UblBuilder, AnafAuthenticator, EFacturaClient, AnafDetailsClient, RateLimiter)
    ↓
Builders (InvoiceBuilder — UBL 2.1 XML generation)
    ↓
Data (InvoiceData, InvoiceLineData, PartyData, AddressData, OAuthTokensData, CompanyData, ...)
    ↓
Support (AddressSanitizer, VatNumberValidator, CnpValidator, XmlParser, DateHelper)
    ↓
Exceptions (EFacturaException hierarchy)
\`\`\`

## Key Classes and Their Roles

### Facades

| Facade | Namespace | Service Backed By |
|--------|-----------|-------------------|
| \`UblBuilder\` | \`BeeCoded\\EFacturaSdk\\Facades\\UblBuilder\` | \`BeeCoded\\EFacturaSdk\\Services\\UblBuilder\` |
| \`EFacturaSdkAuth\` | \`BeeCoded\\EFacturaSdk\\Facades\\EFacturaSdkAuth\` | \`BeeCoded\\EFacturaSdk\\Services\\AnafAuthenticator\` |
| \`AnafDetails\` | \`BeeCoded\\EFacturaSdk\\Facades\\AnafDetails\` | \`BeeCoded\\EFacturaSdk\\Services\\ApiClients\\AnafDetailsClient\` |

#### UblBuilder Facade
Generates UBL 2.1 XML from \`InvoiceData\` DTOs. Automatically selects the correct document type (Invoice vs CreditNote) based on \`InvoiceTypeCode\`.

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\UblBuilder;

$xml = UblBuilder::generateInvoiceXml($invoiceData);
\`\`\`

#### EFacturaSdkAuth Facade
Handles the OAuth 2.0 flow. Generates authorization URLs, exchanges codes for tokens, refreshes expired tokens.

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\EFacturaSdkAuth;

$url = EFacturaSdkAuth::getAuthorizationUrl();
$tokens = EFacturaSdkAuth::exchangeCodeForToken($code);
$newTokens = EFacturaSdkAuth::refreshAccessToken($refreshToken);
\`\`\`

#### AnafDetails Facade
Looks up company information from ANAF's public API. Does not require OAuth authentication.

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\AnafDetails;

$result = AnafDetails::getCompanyData('12345678');
\`\`\`

### Core Services

- **\`EFacturaClient\`** (\`BeeCoded\\EFacturaSdk\\Services\\ApiClients\\EFacturaClient\`) — Stateless client for upload, status, download, list operations. Constructed with tokens per request.
- **\`AnafAuthenticator\`** (\`BeeCoded\\EFacturaSdk\\Services\\AnafAuthenticator\`) — Stateless OAuth service; returns \`OAuthTokensData\`.
- **\`RateLimiter\`** (\`BeeCoded\\EFacturaSdk\\Services\\RateLimiter\`) — Enforces per-endpoint limits using Laravel cache with atomic increments.
- **\`InvoiceBuilder\`** (\`BeeCoded\\EFacturaSdk\\Builders\\InvoiceBuilder\`) — Low-level UBL 2.1 XML builder; used internally by \`UblBuilder\` service.

### Support Utilities

- **\`AddressSanitizer\`** (\`BeeCoded\\EFacturaSdk\\Support\\AddressSanitizer\`) — Converts Romanian county names to ISO 3166-2:RO codes; handles Bucharest sector mapping.
- **\`VatNumberValidator\`** (\`BeeCoded\\EFacturaSdk\\Support\\Validators\\VatNumberValidator\`) — Validates and normalizes Romanian CUI/CIF numbers including checksum verification.
- **\`CnpValidator\`** (\`BeeCoded\\EFacturaSdk\\Support\\Validators\\CnpValidator\`) — Validates Romanian personal identification numbers (CNP).

## Data Transfer Objects

All DTOs use \`spatie/laravel-data\`. Key DTOs:

- **\`InvoiceData\`** — Complete invoice (invoiceNumber, issueDate, supplier, customer, lines, currency, etc.)
- **\`InvoiceLineData\`** — Line item (name, quantity, unitPrice, taxAmount, taxPercent, etc.)
- **\`PartyData\`** — Supplier or customer (registrationName, companyId, address, isVatPayer)
- **\`AddressData\`** — Address (street, city, county, postalZone, countryCode)
- **\`OAuthTokensData\`** — Token set (accessToken, refreshToken, expiresAt)
- **\`CompanyData\`** — Company details from ANAF lookup (cui, name, isVatPayer, addresses, etc.)
`,

  "invoice-flow": `# Laravel e-Factura SDK — Invoice Flow (End-to-End)

## Complete Flow Overview

\`\`\`
1. Build InvoiceData DTO (with PartyData + AddressData + InvoiceLineData[])
2. Generate XML → UblBuilder::generateInvoiceXml($invoiceData)
3. Create EFacturaClient with OAuth tokens
4. Upload → $client->uploadDocument($xml)
5. Poll status → $client->getStatusMessage($uploadId)
6. Download result → $client->downloadDocument($downloadId)
\`\`\`

## Step 1: Build the InvoiceData DTO

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\AddressData;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceData;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceLineData;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\PartyData;

$supplier = new PartyData(
    registrationName: 'Firma Mea SRL',
    companyId: '49296198',           // CUI without RO prefix; builder adds it for VAT payers
    address: new AddressData(
        street: 'Str. Exemplu nr. 1',
        city: 'Cluj-Napoca',
        postalZone: '400001',
        county: 'Cluj',              // Auto-sanitized to RO-CJ by InvoiceBuilder
        countryCode: 'RO',
    ),
    isVatPayer: true,
);

$customer = new PartyData(
    registrationName: 'Client SRL',
    companyId: '12345678',
    address: new AddressData(
        street: 'Bd. Unirii nr. 5',
        city: 'Bucuresti',
        postalZone: '030167',
        county: 'Sector 3',          // Auto-mapped to RO-B; city becomes SECTOR3
        countryCode: 'RO',
    ),
    isVatPayer: false,
);

$lines = [
    new InvoiceLineData(
        name: 'Servicii consultanta',
        quantity: 10.0,
        unitPrice: 100.00,           // Excluding VAT
        taxAmount: 190.00,           // Pre-computed: 10 * 100 * 0.19
        taxPercent: 19.0,
    ),
];

$invoice = new InvoiceData(
    invoiceNumber: 'FC-2024-001',
    issueDate: '2024-01-15',
    supplier: $supplier,
    customer: $customer,
    lines: $lines,
    dueDate: '2024-02-15',
    currency: 'RON',
    paymentIban: 'RO49AAAA1B31007593840000',
);
\`\`\`

## Step 2: Generate XML

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\UblBuilder;
use BeeCoded\\EFacturaSdk\\Exceptions\\ValidationException;

try {
    $xml = UblBuilder::generateInvoiceXml($invoice);
} catch (ValidationException $e) {
    // Handle validation errors — see error-handling topic
    Log::error('Invoice validation failed', ['error' => $e->getMessage()]);
}
\`\`\`

## Step 3: Create EFacturaClient

\`\`\`php
use BeeCoded\\EFacturaSdk\\Services\\ApiClients\\EFacturaClient;

// Retrieve stored tokens (you stored these after OAuth exchange)
$storedTokens = // ... load OAuthTokensData from your database

$client = EFacturaClient::fromTokens(
    vatNumber: '49296198',   // Supplier CUI
    tokens: $storedTokens,
);
\`\`\`

## Step 4: Upload Document

\`\`\`php
use BeeCoded\\EFacturaSdk\\Exceptions\\RateLimitExceededException;
use BeeCoded\\EFacturaSdk\\Exceptions\\ApiException;

try {
    $uploadResult = $client->uploadDocument($xml);
    $uploadId = $uploadResult->uploadId;   // Numeric string, e.g. "5067734920"

    // Persist tokens if refreshed automatically during upload
    if ($client->wasTokenRefreshed()) {
        $newTokens = $client->getTokens();
        // Save $newTokens to database (CRITICAL — old refresh token is now invalid)
    }
} catch (RateLimitExceededException $e) {
    // Retry after $e->retryAfterSeconds
} catch (ApiException $e) {
    Log::error('Upload failed', ['status' => $e->statusCode, 'details' => $e->details]);
}
\`\`\`

## Step 5: Poll Status

ANAF processes invoices asynchronously. Poll with a delay between attempts.

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\UploadStatusValue;

$maxAttempts = 10;
$downloadId = null;

for ($i = 0; $i < $maxAttempts; $i++) {
    sleep(30); // Wait 30 seconds between polls

    $status = $client->getStatusMessage($uploadId);

    if ($status->executionStatus === UploadStatusValue::Ok) {
        $downloadId = $status->downloadId; // Ready to download
        break;
    }

    if ($status->executionStatus === UploadStatusValue::Error) {
        // Invoice rejected — check $status->errors
        break;
    }
    // UploadStatusValue::Processing — keep polling
}
\`\`\`

## Step 6: Download Document

\`\`\`php
$download = $client->downloadDocument($downloadId);

// $download->content is a ZIP archive (binary)
// $download->filename is the suggested filename
file_put_contents(storage_path('efactura/' . $download->filename), $download->content);
\`\`\`

## InvoiceBuilder Validation Rules

The builder validates these constraints and throws \`ValidationException\` on failure:

### Invoice Number
- Required (cannot be empty)
- Must contain at least one digit (BR-RO-010)
- Maximum 200 characters (BR-RO-L200)

### Issue Date
- Required

### Party Validation (Supplier and Customer)
- \`registrationName\` required, max 200 characters
- \`companyId\` required
- Address required with:
  - \`street\` required, max 150 characters (BR-RO-L150)
  - \`city\` required, max 50 characters (BR-RO-L050)
  - \`postalZone\` optional, max 20 characters if present
  - \`county\` required for Romanian addresses (countryCode = 'RO') — must map to valid ISO 3166-2:RO code

### Line Items
- At least one line required
- \`name\` required, max 100 characters (BR-RO-L100)
- \`description\` optional, max 200 characters (BR-RO-L200)
- \`quantity\` cannot be zero (negative allowed for credit notes)
- \`unitPrice\` cannot be negative
- \`taxPercent\` must be between 0 and 100

### Preceding Invoice Number (Credit Notes)
- Optional, max 200 characters if provided
`,

  "credit-notes": `# Laravel e-Factura SDK — Credit Notes

## What is a Credit Note?

A credit note (nota de creditare) corrects or reverses a previously issued invoice. In the ANAF e-Factura system, credit notes use \`InvoiceTypeCode::CreditNote\` (value \`'381'\`) and generate a \`CreditNote\` UBL document instead of an \`Invoice\` document.

## InvoiceTypeCode for Credit Notes

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\InvoiceTypeCode;

// Credit note type code
InvoiceTypeCode::CreditNote  // value: '381'

// Standard invoice types
InvoiceTypeCode::CommercialInvoice   // value: '380' (default)
InvoiceTypeCode::CorrectiveInvoice   // value: '384'
InvoiceTypeCode::SelfBilledInvoice   // value: '389'
InvoiceTypeCode::AccountingDocument  // value: '751'
\`\`\`

## Quantity Convention (Sign Convention)

**You provide negative quantities in \`InvoiceLineData\`. The builder negates them again internally.**

ANAF's UBL schema treats the \`CreditNote\` document type as inherently negative. The builder negates quantities when writing to XML so that the XML contains positive values — which ANAF then treats as negative credits.

| What you provide | What goes in XML | Effect in ANAF |
|-----------------|-----------------|----------------|
| quantity = -1 (return 1 item) | CreditedQuantity = +1 | ANAF credits 1 unit |
| quantity = +1 (unusual — negative credit) | CreditedQuantity = -1 | ANAF debits back |

\`\`\`php
// Returning 3 items worth 100 RON each with 19% VAT
$line = new InvoiceLineData(
    name: 'Produs returnat',
    quantity: -3.0,          // Negative: you are returning 3 items
    unitPrice: 100.00,       // Always positive
    taxAmount: -57.00,       // Negative (follows quantity sign): -3 * 100 * 0.19
    taxPercent: 19.0,
);
// In XML: CreditedQuantity = +3.00, LineExtensionAmount = -300.00
// ANAF interprets as: credit of 300 RON + 57 RON VAT
\`\`\`

## taxAmount Sign Convention

The \`taxAmount\` sign must follow the quantity sign:
- **Negative quantity** → **negative taxAmount** (standard credit note return)
- **Positive quantity** → **positive taxAmount** (unusual negative credit scenario)

\`\`\`php
// Standard: returning items (qty negative → taxAmount negative)
new InvoiceLineData(
    name: 'Retur marfa',
    quantity: -5.0,
    unitPrice: 200.00,
    taxAmount: -190.00,   // -5 * 200 * 0.19 = -190
    taxPercent: 19.0,
);
\`\`\`

## BillingReference (precedingInvoiceNumber)

Credit notes should reference the original invoice using \`precedingInvoiceNumber\`. This maps to the UBL \`BillingReference/InvoiceDocumentReference/ID\` element (BG-3, BT-25).

\`\`\`php
$creditNote = new InvoiceData(
    invoiceNumber: 'CN-2024-001',
    issueDate: '2024-02-01',
    supplier: $supplier,
    customer: $customer,
    lines: $creditNoteLines,
    invoiceTypeCode: InvoiceTypeCode::CreditNote,
    precedingInvoiceNumber: 'FC-2024-050',  // Original invoice being credited
);
\`\`\`

> Note: \`dueDate\` is ignored for credit notes. The UBL \`CreditNote\` schema does not include a \`DueDate\` element; the builder silently drops it.

## DueDate Behavior

Credit notes do not have a \`DueDate\` in the UBL CreditNote schema. If you set \`dueDate\` on the \`InvoiceData\`, the builder will silently ignore it (no exception thrown).

## Complete Credit Note Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceData;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceLineData;
use BeeCoded\\EFacturaSdk\\Enums\\InvoiceTypeCode;
use BeeCoded\\EFacturaSdk\\Facades\\UblBuilder;

$creditNoteLines = [
    new InvoiceLineData(
        name: 'Servicii consultanta — anulare partiala',
        quantity: -1.0,
        unitPrice: 500.00,
        taxAmount: -95.00,     // -1 * 500 * 0.19
        taxPercent: 19.0,
    ),
];

$creditNote = new InvoiceData(
    invoiceNumber: 'CN-2024-001',
    issueDate: '2024-02-15',
    supplier: $supplier,
    customer: $customer,
    lines: $creditNoteLines,
    currency: 'RON',
    invoiceTypeCode: InvoiceTypeCode::CreditNote,
    precedingInvoiceNumber: 'FC-2024-001',
);

$xml = UblBuilder::generateInvoiceXml($creditNote);
// Generates a <CreditNote> UBL document (not <Invoice>)
\`\`\`

## Builder Auto-Negation Summary

| Builder behavior | Detail |
|-----------------|--------|
| You provide | Negative quantity (e.g. -3) |
| XML contains | Positive CreditedQuantity (e.g. +3) |
| taxAmount in XML | Also negated (you provide -57, XML gets +57 from builder) |
| ANAF interprets | As a credit (negative financial impact) |

This design ensures the XML document totals are expressed as positive numbers in the CreditNote — matching ANAF's UBL validation expectations.
`,

  "tax-calculation": `# Laravel e-Factura SDK — Tax Calculation

## taxAmount is required (v2.0 Breaking Change)

Since v2.0, \`InvoiceLineData\` requires a **pre-computed \`taxAmount\`** for every line. This field is required — a breaking change from v1.x which calculated tax automatically.

**Why?** Automatic tax calculation from \`qty × unitPrice × taxRate\` causes rounding discrepancies in tax-inclusive pricing scenarios. Suppliers often quote gross prices and compute tax by subtracting the net — if the SDK recalculates tax independently, the totals won't match the supplier's books, causing ANAF validation errors.

By requiring explicit \`taxAmount\`, the caller controls rounding and ensures the XML matches the invoice exactly.

## Tax-Exclusive Formula (Standard Case)

When your prices are **net** (excluding VAT):

\`\`\`php
// Formula: taxAmount = round(quantity * unitPrice * taxPercent / 100, 2)

$quantity = 10.0;
$unitPrice = 100.00;   // Net price
$taxPercent = 19.0;

$taxAmount = round($quantity * $unitPrice * $taxPercent / 100, 2);
// = round(10 * 100 * 19 / 100, 2)
// = round(190.00, 2)
// = 190.00

$line = new InvoiceLineData(
    name: 'Servicii',
    quantity: $quantity,
    unitPrice: $unitPrice,
    taxAmount: $taxAmount,     // 190.00
    taxPercent: $taxPercent,
);
\`\`\`

## Tax-Inclusive Formula (Gross Prices)

When your prices are **gross** (including VAT), you must extract the net and tax:

\`\`\`php
// Formula:
// grossAmount = quantity * grossUnitPrice
// netAmount   = round(grossAmount / (1 + taxPercent/100), 2)
// taxAmount   = grossAmount - netAmount
// unitPrice   = netAmount / quantity  (net unit price for the DTO)

$quantity = 5.0;
$grossUnitPrice = 119.00;  // Price including 19% VAT
$taxPercent = 19.0;

$grossAmount = $quantity * $grossUnitPrice;  // 595.00
$netAmount   = round($grossAmount / (1 + $taxPercent / 100), 2);  // round(595/1.19, 2) = 500.00
$taxAmount   = $grossAmount - $netAmount;    // 595.00 - 500.00 = 95.00
$netUnitPrice = $netAmount / $quantity;       // 500/5 = 100.00

$line = new InvoiceLineData(
    name: 'Produs cu pret inclusiv TVA',
    quantity: $quantity,
    unitPrice: $netUnitPrice,   // 100.00 (net, not gross)
    taxAmount: $taxAmount,       // 95.00
    taxPercent: $taxPercent,
);
\`\`\`

## Multi-Line Rounding Strategy

The builder sums per-line tax amounts without recalculating from group totals. This prevents the "double rounding" problem where:

\`\`\`
sum(line_taxes) ≠ sum(lineAmounts) × taxRate

Example:
Line 1: 1 × 10.555 = 10.56 net → tax = 2.01
Line 2: 1 × 10.555 = 10.56 net → tax = 2.01
Sum of line taxes: 4.02

But: (10.56 + 10.56) × 0.19 = 21.12 × 0.19 = 4.01 ← different!
\`\`\`

The SDK accumulates your per-line \`taxAmount\` values directly, so always round at the individual line level.

## Sign Convention for Credit Notes

For credit note lines, \`taxAmount\` must follow the quantity sign:

\`\`\`php
// Returning 2 items at 100 RON net, 19% VAT
$quantity  = -2.0;                   // Negative for return
$unitPrice = 100.00;                 // Always positive
$taxAmount = round(-2.0 * 100.00 * 19 / 100, 2);  // -38.00

$line = new InvoiceLineData(
    name: 'Produs returnat',
    quantity: $quantity,
    unitPrice: $unitPrice,
    taxAmount: $taxAmount,   // Must be negative when quantity is negative
    taxPercent: 19.0,
);
\`\`\`

## Zero VAT Rate

For zero-rated or exempt items, set \`taxPercent\` to 0 and \`taxAmount\` to 0:

\`\`\`php
$line = new InvoiceLineData(
    name: 'Export — zero VAT',
    quantity: 1.0,
    unitPrice: 500.00,
    taxAmount: 0.0,    // No tax
    taxPercent: 0.0,   // Builder maps to TaxCategoryId::ZeroRated (Z)
);
\`\`\`

## Non-VAT Payer Supplier

When \`PartyData::isVatPayer = false\`, all lines receive \`TaxCategoryId::NotSubject\` (category \`O\`) regardless of \`taxPercent\`. The \`taxAmount\` should be 0.

\`\`\`php
$supplier = new PartyData(
    registrationName: 'PFA Ion Popescu',
    companyId: '12345678',
    address: $address,
    isVatPayer: false,   // No VAT registration
);

// Lines for non-VAT payer:
$line = new InvoiceLineData(
    name: 'Servicii PFA',
    quantity: 1.0,
    unitPrice: 500.00,
    taxAmount: 0.0,     // Non-VAT payer: no tax
    taxPercent: 0.0,
);
\`\`\`

## Tax Category Determination

The builder determines the \`TaxCategoryId\` automatically from \`taxPercent\` and supplier VAT status:

| Supplier isVatPayer | taxPercent | TaxCategoryId | UBL Code |
|---------------------|------------|---------------|----------|
| false | any | NotSubject | O |
| true | 0 | ZeroRated | Z |
| true | > 0 | Standard | S |
`,

  "oauth-flow": `# Laravel e-Factura SDK — OAuth Flow

## Overview

ANAF uses OAuth 2.0 with JWT tokens for API access. The flow:

1. Generate authorization URL and redirect user to ANAF login
2. ANAF redirects back to your callback URL with an authorization code
3. Exchange the code for access + refresh tokens
4. Store tokens encrypted in your database
5. Use tokens when creating \`EFacturaClient\`
6. Automatically refresh when the token approaches expiry

## Step 1: Generate Authorization URL

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Auth\\AuthUrlSettingsData;
use BeeCoded\\EFacturaSdk\\Facades\\EFacturaSdkAuth;

// Simple (no state)
$url = EFacturaSdkAuth::getAuthorizationUrl();

// With CSRF state (recommended)
$csrfToken = bin2hex(random_bytes(16));
session(['efactura_csrf' => $csrfToken]);

$url = EFacturaSdkAuth::getAuthorizationUrl(
    new AuthUrlSettingsData(
        state: [
            'csrf_token' => $csrfToken,
            'user_id' => auth()->id(),
            'company_cif' => '12345678',
        ],
        // state is JSON-encoded then base64-encoded automatically
    )
);

return redirect($url);
\`\`\`

## Step 2: Handle ANAF Callback

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\EFacturaSdkAuth;
use BeeCoded\\EFacturaSdk\\Exceptions\\AuthenticationException;

public function handleCallback(Request $request)
{
    $code  = $request->get('code');
    $state = $request->get('state');

    // Validate CSRF state before exchanging the code
    $decodedState = EFacturaSdkAuth::decodeState($state);

    if ($decodedState['csrf_token'] !== session('efactura_csrf')) {
        abort(403, 'Invalid state — possible CSRF attack');
    }

    // Exchange code for tokens
    try {
        $tokens = EFacturaSdkAuth::exchangeCodeForToken($code);
    } catch (AuthenticationException $e) {
        Log::error('Token exchange failed', ['error' => $e->getMessage()]);
        return redirect()->route('error');
    }

    // Persist tokens (encrypt them — see below)
    $this->saveTokens($decodedState['company_cif'], $tokens);

    return redirect()->route('dashboard');
}
\`\`\`

## Step 3: Store Tokens Encrypted

Always store tokens encrypted. Use Laravel's \`Crypt\` facade:

\`\`\`php
use Illuminate\\Support\\Facades\\Crypt;
use BeeCoded\\EFacturaSdk\\Data\\Auth\\OAuthTokensData;

// Saving
EFacturaToken::updateOrCreate(
    ['cif' => $cif],
    [
        'access_token'  => Crypt::encryptString($tokens->accessToken),
        'refresh_token' => Crypt::encryptString($tokens->refreshToken),
        'expires_at'    => $tokens->expiresAt,
    ]
);

// Loading
$record = EFacturaToken::where('cif', $cif)->firstOrFail();
$tokens = new OAuthTokensData(
    accessToken:  Crypt::decryptString($record->access_token),
    refreshToken: Crypt::decryptString($record->refresh_token),
    expiresAt:    $record->expires_at,
);
\`\`\`

## Step 4: Automatic Token Refresh

\`EFacturaClient\` refreshes tokens automatically when they approach expiry. After any API call, check if refresh occurred:

\`\`\`php
use BeeCoded\\EFacturaSdk\\Services\\ApiClients\\EFacturaClient;

$client = EFacturaClient::fromTokens('12345678', $storedTokens);

$result = $client->uploadDocument($xml);

// CRITICAL: Persist the new tokens if refreshed
// ANAF uses rotating refresh tokens — the old refresh token is immediately invalidated
if ($client->wasTokenRefreshed()) {
    $newTokens = $client->getTokens();
    // Re-encrypt and save to database
    EFacturaToken::where('cif', '12345678')->update([
        'access_token'  => Crypt::encryptString($newTokens->accessToken),
        'refresh_token' => Crypt::encryptString($newTokens->refreshToken),
        'expires_at'    => $newTokens->expiresAt,
    ]);
}
\`\`\`

## CRITICAL: Rotating Refresh Tokens

ANAF uses **rotating refresh tokens**. When a token is refreshed:
- The old refresh token is **immediately invalidated**
- A new refresh token is issued
- You **must** persist the new tokens before making another API call

If you fail to persist the new refresh token and the access token expires, you will lose API access and must re-authenticate via the OAuth flow.

## Token Expiry Buffer

The \`EFacturaClient\` considers a token expired if it expires within **120 seconds** from now. This prevents edge cases where the token expires between the validity check and the actual API call.

\`\`\`php
// Internal constant in EFacturaClient:
private const int TOKEN_EXPIRY_BUFFER_SECONDS = 120;

// isTokenValid() logic:
return $this->expiresAt->copy()->subSeconds(120)->isFuture();
\`\`\`

## Manual Token Refresh

You can also refresh tokens manually via the facade:

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\EFacturaSdkAuth;

$newTokens = EFacturaSdkAuth::refreshAccessToken($currentRefreshToken);
// Returns a new OAuthTokensData with new accessToken and refreshToken
\`\`\`

## State Parameter (CSRF Protection)

The state parameter is base64-encoded JSON. The SDK handles encoding/decoding:

\`\`\`php
// Encoding (automatic when you pass state array to AuthUrlSettingsData)
$encoded = base64_encode(json_encode(['csrf' => 'abc123', 'user' => 42]));

// Decoding
$decoded = EFacturaSdkAuth::decodeState($encodedState);
// Returns: ['csrf' => 'abc123', 'user' => 42]
\`\`\`

## OAuthTokensData Fields

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Auth\\OAuthTokensData;

// Fields:
$tokens->accessToken   // string — JWT access token
$tokens->refreshToken  // string — JWT refresh token (rotate after use!)
$tokens->expiresAt     // Carbon|null — when the access token expires
\`\`\`
`,

  "error-handling": `# Laravel e-Factura SDK — Error Handling

## Exception Hierarchy

All exceptions extend \`EFacturaException\`, which extends PHP's native \`Exception\`.

\`\`\`
EFacturaException (base)
├── AuthenticationException
├── ValidationException
├── ApiException
├── RateLimitExceededException
└── XmlParsingException
\`\`\`

## EFacturaException (Base)

**Namespace:** \`BeeCoded\\EFacturaSdk\\Exceptions\\EFacturaException\`

The base exception. All SDK exceptions extend this class.

\`\`\`php
class EFacturaException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\\Throwable $previous = null,
        public readonly array $context = []   // Additional context data
    ) {}
}
\`\`\`

The \`context\` array contains additional structured data for logging and debugging.

## AuthenticationException

**Namespace:** \`BeeCoded\\EFacturaSdk\\Exceptions\\AuthenticationException\`

Thrown for OAuth/token failures: invalid tokens, expired tokens, missing credentials, failed token exchange or refresh.

\`\`\`php
class AuthenticationException extends EFacturaException
{
    public function __construct(
        string $message = 'Authentication failed. Check your credentials or token.',
        int $code = 401,
        ?\\Throwable $previous = null,
        array $context = []
    ) {}
}
\`\`\`

**When thrown:**
- \`EFacturaSdkAuth::exchangeCodeForToken()\` fails (bad code, network error)
- \`EFacturaSdkAuth::refreshAccessToken()\` fails (invalid/expired refresh token)
- \`EFacturaClient\` token refresh fails during an API call
- Token refresh lock timeout (concurrent refresh attempt)
- API returns HTTP 401

## ValidationException

**Namespace:** \`BeeCoded\\EFacturaSdk\\Exceptions\\ValidationException\`

Thrown for input validation failures and XML validation errors.

\`\`\`php
class ValidationException extends EFacturaException
{
    public function __construct(
        string $message,
        int $code = 422,
        ?\\Throwable $previous = null,
        array $context = []
    ) {}
}
\`\`\`

**When thrown:**
- \`InvoiceBuilder\` fails validation (missing invoice number, invalid county, empty lines, etc.)
- \`UblBuilder::generateInvoiceXml()\` wraps unexpected XML generation errors
- \`EFacturaClient\` receives empty XML string
- \`EFacturaClient\` receives invalid upload/download ID format

## ApiException

**Namespace:** \`BeeCoded\\EFacturaSdk\\Exceptions\\ApiException\`

Thrown for HTTP-level failures when communicating with ANAF APIs.

\`\`\`php
class ApiException extends EFacturaException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,    // HTTP status code
        public readonly ?string $details = null,  // Raw response body
        ?\\Throwable $previous = null,
        array $context = []
    ) {}
}
\`\`\`

**When thrown:**
- ANAF API returns a non-2xx HTTP response (after retries exhausted)
- PDF conversion returns JSON error instead of PDF
- Upload/status response cannot be parsed

## RateLimitExceededException

**Namespace:** \`BeeCoded\\EFacturaSdk\\Exceptions\\RateLimitExceededException\`

Thrown when an SDK rate limit is exceeded before the request reaches ANAF.

\`\`\`php
class RateLimitExceededException extends EFacturaException
{
    public function __construct(
        string $message,
        public readonly int $remaining = 0,              // Remaining quota (always 0)
        public readonly int $retryAfterSeconds = 60,     // Seconds until quota resets
        ?\\Throwable $previous = null,
        array $context = []
    ) {}
    // HTTP code: 429
}
\`\`\`

**When thrown:**
- Global rate limit (500/min by default) exceeded
- RASP upload limit (500/day/CUI) exceeded
- Status query limit (50/day/message) exceeded
- Download limit (5/day/message) exceeded
- Simple/paginated list limits exceeded

## XmlParsingException

**Namespace:** \`BeeCoded\\EFacturaSdk\\Exceptions\\XmlParsingException\`

Thrown when XML response from ANAF cannot be parsed.

\`\`\`php
class XmlParsingException extends EFacturaException
{
    public function __construct(
        string $message,
        public readonly ?string $rawResponse = null,  // Raw response for debugging
        int $code = 500,
        ?\\Throwable $previous = null,
        array $context = []
    ) {}
}
\`\`\`

## Try/Catch Patterns

### Full Invoice Upload Flow

\`\`\`php
use BeeCoded\\EFacturaSdk\\Exceptions\\ApiException;
use BeeCoded\\EFacturaSdk\\Exceptions\\AuthenticationException;
use BeeCoded\\EFacturaSdk\\Exceptions\\EFacturaException;
use BeeCoded\\EFacturaSdk\\Exceptions\\RateLimitExceededException;
use BeeCoded\\EFacturaSdk\\Exceptions\\ValidationException;

try {
    $xml    = UblBuilder::generateInvoiceXml($invoiceData);
    $result = $client->uploadDocument($xml);

} catch (ValidationException $e) {
    // Input data or XML generation failed
    Log::warning('Invoice validation failed', [
        'message' => $e->getMessage(),
        'context' => $e->context,
    ]);
    return response()->json(['error' => $e->getMessage()], 422);

} catch (AuthenticationException $e) {
    // Token invalid or expired — re-authenticate
    Log::error('Authentication failed', [
        'message' => $e->getMessage(),
        'code'    => $e->getCode(),
    ]);
    return redirect()->route('efactura.reauth');

} catch (RateLimitExceededException $e) {
    // Rate limit hit — tell client to retry
    Log::info('Rate limit exceeded', [
        'retryAfter' => $e->retryAfterSeconds,
    ]);
    return response()->json([
        'error'       => $e->getMessage(),
        'retry_after' => $e->retryAfterSeconds,
    ], 429);

} catch (ApiException $e) {
    // HTTP-level ANAF API error
    Log::error('ANAF API error', [
        'statusCode' => $e->statusCode,
        'details'    => $e->details,
        'context'    => $e->context,
    ]);
    return response()->json(['error' => 'ANAF API error: ' . $e->getMessage()], 502);

} catch (EFacturaException $e) {
    // Catch-all for any other SDK exception
    Log::error('Unexpected e-Factura error', ['message' => $e->getMessage()]);
    return response()->json(['error' => 'Invoice submission failed'], 500);
}
\`\`\`

### OAuth Flow

\`\`\`php
use BeeCoded\\EFacturaSdk\\Exceptions\\AuthenticationException;

try {
    $tokens = EFacturaSdkAuth::exchangeCodeForToken($code);
} catch (AuthenticationException $e) {
    // $e->getCode() is 401
    Log::error('Token exchange failed', ['message' => $e->getMessage()]);
}

try {
    $newTokens = EFacturaSdkAuth::refreshAccessToken($refreshToken);
} catch (AuthenticationException $e) {
    // Refresh token is invalid or revoked — must re-authenticate via OAuth
    Log::error('Token refresh failed — re-auth required', ['cif' => $cif]);
}
\`\`\`
`,

  "address-sanitization": `# Laravel e-Factura SDK — Address Sanitization

## Why Sanitization is Required

ANAF enforces the ISO 3166-2:RO standard for Romanian county codes in the \`CountrySubentity\` UBL field (rule BR-RO-111). If you pass a plain county name like \`"Cluj"\` or \`"Judetul Maramures"\`, ANAF will reject the invoice.

The \`InvoiceBuilder\` automatically sanitizes county values via \`AddressSanitizer\`. If the county cannot be mapped to a valid ISO code, it throws a \`ValidationException\` immediately — this is intentional "fail fast" behavior to prevent submission of invalid invoices.

## AddressSanitizer Class

**Namespace:** \`BeeCoded\\EFacturaSdk\\Support\\AddressSanitizer\`

All methods are static.

### sanitizeCounty(string $county): ?string

Maps a county name to its ISO 3166-2:RO code. Returns \`null\` if no match found.

\`\`\`php
use BeeCoded\\EFacturaSdk\\Support\\AddressSanitizer;

AddressSanitizer::sanitizeCounty('Cluj');              // 'RO-CJ'
AddressSanitizer::sanitizeCounty('Judetul Cluj');      // 'RO-CJ'
AddressSanitizer::sanitizeCounty('CLUJ NAPOCA');       // 'RO-CJ'
AddressSanitizer::sanitizeCounty('Maramureș');         // 'RO-MM' (handles diacritics)
AddressSanitizer::sanitizeCounty('RO-CJ');             // 'RO-CJ' (already a valid code)
AddressSanitizer::sanitizeCounty('UnknownCounty');     // null
\`\`\`

### isBucharest(string $county): bool

Detects if a county string refers to Bucharest.

\`\`\`php
AddressSanitizer::isBucharest('Bucuresti');     // true
AddressSanitizer::isBucharest('Sector 3');      // true
AddressSanitizer::isBucharest('MUNICIPIUL BUCURESTI'); // true
AddressSanitizer::isBucharest('B');             // true (abbreviation)
AddressSanitizer::isBucharest('Cluj');          // false
\`\`\`

### extractBucharestSectorNumber(string $address): ?int

Extracts the sector number (1–6) from an address string.

\`\`\`php
AddressSanitizer::extractBucharestSectorNumber('Sector 3');    // 3
AddressSanitizer::extractBucharestSectorNumber('Sectorul 1');  // 1
AddressSanitizer::extractBucharestSectorNumber('Sect. 6');     // 6
AddressSanitizer::extractBucharestSectorNumber('S.2');         // 2
AddressSanitizer::extractBucharestSectorNumber('Cluj');        // null
\`\`\`

## County Code Mapping (All 41 Counties + Bucharest)

The sanitizer maps all 41 Romanian counties plus Bucharest to ISO 3166-2:RO codes. Input is normalized (uppercase, trim, remove diacritics) before matching.

| County | ISO Code |
|--------|----------|
| Bucharest / Sector 1-6 / MUNICIPIUL BUCURESTI | RO-B |
| Alba | RO-AB |
| Arad | RO-AR |
| Arges / Argeș | RO-AG |
| Bacau / Bacău | RO-BC |
| Bihor | RO-BH |
| Bistrita-Nasaud / Bistrița-Năsăud | RO-BN |
| Botosani / Botoșani | RO-BT |
| Braila / Brăila | RO-BR |
| Brasov / Brașov | RO-BV |
| Buzau / Buzău | RO-BZ |
| Calarasi / Călărași | RO-CL |
| Caras-Severin / Caraș-Severin | RO-CS |
| Cluj | RO-CJ |
| Constanta / Constanța | RO-CT |
| Covasna | RO-CV |
| Dambovita / Dâmbovița | RO-DB |
| Dolj | RO-DJ |
| Galati / Galați | RO-GL |
| Giurgiu | RO-GR |
| Gorj | RO-GJ |
| Harghita | RO-HR |
| Hunedoara | RO-HD |
| Ialomita / Ialomița | RO-IL |
| Iasi / Iași | RO-IS |
| Ilfov | RO-IF |
| Maramures / Maramureș | RO-MM |
| Mehedinti / Mehedinți | RO-MH |
| Mures / Mureș | RO-MS |
| Neamt / Neamț | RO-NT |
| Olt | RO-OT |
| Prahova | RO-PH |
| Salaj / Sălaj | RO-SJ |
| Satu Mare | RO-SM |
| Sibiu | RO-SB |
| Suceava | RO-SV |
| Teleorman | RO-TR |
| Timis / Timiș | RO-TM |
| Tulcea | RO-TL |
| Valcea / Vâlcea (also: Vilcea) | RO-VL |
| Vaslui | RO-VS |
| Vrancea | RO-VN |

## Bucharest Sector Handling

Bucharest sectors (1–6) are **not** part of ISO 3166-2:RO. They all map to county code \`RO-B\`. However, ANAF requires (BR-RO-100/101) that for Bucharest addresses, the city name must be formatted as \`SECTOR1\` through \`SECTOR6\`.

The builder handles this automatically:

\`\`\`php
// You provide:
$address = new AddressData(
    street: 'Str. Exemplu nr. 5',
    city: 'Bucuresti',       // or 'Sector 3'
    county: 'Sector 3',      // Detected as Bucharest → CountrySubentity = RO-B
    countryCode: 'RO',
);

// In the generated XML:
// <cbc:CityName>SECTOR3</cbc:CityName>        ← formatted automatically
// <cbc:CountrySubentity>RO-B</cbc:CountrySubentity>
\`\`\`

The sector number is extracted from either \`city\` or \`county\` (whichever contains it). Accepted formats: \`Sector 3\`, \`Sectorul 3\`, \`Sect. 3\`, \`S.3\`.

## Administrative Prefix Stripping

The sanitizer strips common Romanian administrative prefixes before matching:

- \`JUDETUL \` / \`JUD. \` / \`JUD \`
- \`MUNICIPIUL \` / \`MUN. \` / \`MUN \`
- \`ORAS \` / \`OR. \`
- \`COMUNA \` / \`COM. \`

\`\`\`php
AddressSanitizer::sanitizeCounty('Judetul Prahova');   // 'RO-PH'
AddressSanitizer::sanitizeCounty('Municipiul Cluj');   // 'RO-CJ'
\`\`\`

## Diacritics Normalization

Romanian diacritics are normalized before matching. Both modern (comma-below) and legacy (cedilla) variants are handled:

- ă / â → a
- î → i
- ș / ş → s
- ț / ţ → t

\`\`\`php
// All of these map to the same county:
AddressSanitizer::sanitizeCounty('Timiș');    // 'RO-TM'
AddressSanitizer::sanitizeCounty('Timis');    // 'RO-TM'
AddressSanitizer::sanitizeCounty('TIMIS');    // 'RO-TM'
\`\`\`

## ValidationException for Invalid Counties

For Romanian addresses (countryCode = 'RO'), if the county cannot be mapped:

\`\`\`php
$address = new AddressData(
    street: 'Str. Test',
    city: 'TestCity',
    county: 'NotACounty',   // Cannot be mapped
    countryCode: 'RO',
);

// UblBuilder::generateInvoiceXml() throws:
// ValidationException: "County 'NotACounty' cannot be mapped to a valid ISO 3166-2:RO code..."
\`\`\`

For non-Romanian addresses (e.g., \`countryCode = 'DE'\`), the county is passed through as-is without validation.
`,

  "rate-limiting": `# Laravel e-Factura SDK — Rate Limiting

## Overview

The SDK includes built-in rate limiting to prevent exceeding ANAF API quotas. All limits are checked **before** the HTTP request is made. If a limit is exceeded, \`RateLimitExceededException\` is thrown without consuming a quota slot.

The rate limiter uses atomic cache increments to prevent race conditions in concurrent environments.

## Default Limits (50% Safety Margin)

All defaults are set to **50% of ANAF's actual limits** for safety.

| Limit Type | SDK Default | ANAF Limit | Config Key |
|-----------|------------|------------|-----------|
| Global (all endpoints) | 500/min | 1000/min | \`global_per_minute\` |
| RASP uploads | 500/day/CUI | 1000/day/CUI | \`rasp_upload_per_day_cui\` |
| Status queries | 50/day/message | 100/day/message | \`status_per_day_message\` |
| Simple list queries | 750/day/CUI | 1500/day/CUI | \`simple_list_per_day_cui\` |
| Paginated list queries | 50000/day/CUI | 100000/day/CUI | \`paginated_list_per_day_cui\` |
| Downloads | 5/day/message | 10/day/message | \`download_per_day_message\` |

## Configuration

\`\`\`php
// config/efactura-sdk.php
'rate_limits' => [
    'enabled' => env('EFACTURA_RATE_LIMIT_ENABLED', true),
    'global_per_minute'        => env('EFACTURA_RATE_LIMIT_GLOBAL', 500),
    'rasp_upload_per_day_cui'  => env('EFACTURA_RATE_LIMIT_RASP_UPLOAD', 500),
    'status_per_day_message'   => env('EFACTURA_RATE_LIMIT_STATUS', 50),
    'simple_list_per_day_cui'  => env('EFACTURA_RATE_LIMIT_SIMPLE_LIST', 750),
    'paginated_list_per_day_cui' => env('EFACTURA_RATE_LIMIT_PAGINATED_LIST', 50000),
    'download_per_day_message' => env('EFACTURA_RATE_LIMIT_DOWNLOAD', 5),
],
\`\`\`

## How RateLimiter Works

The \`RateLimiter\` service (\`BeeCoded\\EFacturaSdk\\Services\\RateLimiter\`) uses Laravel's cache for atomic counter increments via \`RateLimitStore\`.

- **Minute-window store:** Resets every 60 seconds. Used for global limit.
- **Daily store:** Resets every 86400 seconds (24 hours). Used for per-endpoint/CUI/message limits.

Rate limit keys are scoped by identifier:
- Global: \`global\`
- RASP upload: \`rasp_upload:{CUI}\`
- Status: \`status:{messageId}\`
- Simple list: \`list_simple:{CUI}\`
- Paginated list: \`list_paginated:{CUI}\`
- Download: \`download:{messageId}\`

## Cache Driver Requirement

Rate limiting requires a persistent cache driver. The SDK logs a warning if you use \`null\` or \`array\` drivers (which don't persist between requests).

**Recommended drivers:** \`redis\`, \`memcached\`, or \`database\`

\`\`\`env
CACHE_DRIVER=redis
\`\`\`

## RateLimitExceededException

\`\`\`php
use BeeCoded\\EFacturaSdk\\Exceptions\\RateLimitExceededException;

try {
    $result = $client->uploadDocument($xml);
} catch (RateLimitExceededException $e) {
    $e->getMessage();            // Human-readable description
    $e->remaining;               // Always 0 (limit was hit)
    $e->retryAfterSeconds;       // Seconds until the window resets
    $e->getCode();               // Always 429
}
\`\`\`

## Backoff Strategy

When catching \`RateLimitExceededException\`, use the \`retryAfterSeconds\` value to schedule a retry:

\`\`\`php
use BeeCoded\\EFacturaSdk\\Exceptions\\RateLimitExceededException;

try {
    $result = $client->getStatusMessage($uploadId);
} catch (RateLimitExceededException $e) {
    // Schedule retry via Laravel queue
    StatusCheckJob::dispatch($uploadId)
        ->delay(now()->addSeconds($e->retryAfterSeconds));

    Log::info('Rate limited, retry scheduled', [
        'retryAfter' => $e->retryAfterSeconds,
        'uploadId'   => $uploadId,
    ]);
}
\`\`\`

## Checking Remaining Quota

\`\`\`php
$rateLimiter = $client->getRateLimiter();

// Check global remaining quota
$globalQuota = $rateLimiter->getRemainingQuota('global');
// ['limit' => 500, 'remaining' => 347, 'resetsIn' => 23]

// Check per-CUI quota
$uploadQuota = $rateLimiter->getRemainingQuota('rasp_upload', '12345678');
// ['limit' => 500, 'remaining' => 498, 'resetsIn' => 86342]

// Check per-message quota
$statusQuota = $rateLimiter->getRemainingQuota('status', '5067734920');
// ['limit' => 50, 'remaining' => 49, 'resetsIn' => 86342]
\`\`\`

Valid types for \`getRemainingQuota()\`: \`global\`, \`rasp_upload\`, \`status\`, \`simple_list\`, \`paginated_list\`, \`download\`

## Disabling Rate Limiting

For testing or special cases, disable rate limiting:

\`\`\`env
EFACTURA_RATE_LIMIT_ENABLED=false
\`\`\`

Or in \`phpunit.xml\`:
\`\`\`xml
<env name="EFACTURA_RATE_LIMIT_ENABLED" value="false"/>
\`\`\`
`,

  "company-lookup": `# Laravel e-Factura SDK — Company Lookup

## Overview

The \`AnafDetails\` facade and underlying \`AnafDetailsClient\` provide company information from ANAF's public \`PlatitorTvaRest\` API. This API **does not require OAuth authentication** — no tokens needed.

**API endpoint:** \`https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva\`

## Facade Usage

\`\`\`php
use BeeCoded\\EFacturaSdk\\Facades\\AnafDetails;

// Single lookup
$result = AnafDetails::getCompanyData('12345678');

// Batch lookup (up to 500 VAT codes)
$result = AnafDetails::batchGetCompanyData(['12345678', 'RO87654321', '11111111']);

// Format validation only (no API call)
$isValid = AnafDetails::isValidVatCode('12345678');  // true/false
\`\`\`

## getCompanyData(string $vatCode): CompanyLookupResultData

Look up a single company by CUI/CIF. Accepts both \`'12345678'\` and \`'RO12345678'\` formats.

\`\`\`php
$result = AnafDetails::getCompanyData('49296198');

if ($result->success) {
    $company = $result->companies[0];   // CompanyData instance

    echo $company->cui;                  // '49296198'
    echo $company->name;                 // 'FIRMA MEA SRL'
    echo $company->isVatPayer;           // true/false
    echo $company->getVatNumber();       // 'RO49296198'
    echo $company->registrationNumber;   // 'J12/345/2023' (trade register)

    // Addresses
    $hq = $company->headquartersAddress;   // AddressData|null
    $fd = $company->fiscalDomicileAddress; // AddressData|null

    // Status checks
    $company->isActive();        // !isInactive && !isDeregistered
    $company->isVatPayer;        // true = registered for VAT
    $company->isInactive;        // true = fiscally inactive
    $company->isDeregistered;    // true = deregistered (radiat)
    $company->isSplitVat;        // true = uses split VAT payment
    $company->isRtvai;           // true = TVA la incasare

} else {
    echo $result->errorMessage;  // e.g. 'Company not found for the provided VAT code.'
}
\`\`\`

## batchGetCompanyData(array $vatCodes): CompanyLookupResultData

Look up multiple companies in a single API call. Maximum **500** VAT codes per request.

\`\`\`php
$result = AnafDetails::batchGetCompanyData([
    '12345678',
    'RO87654321',
    '11111111',
]);

if ($result->success) {
    foreach ($result->companies as $company) {
        // CompanyData instances for found companies
        echo $company->cui . ': ' . $company->name . PHP_EOL;
    }

    // CUIs that were not found in ANAF
    foreach ($result->notFoundCuis as $cui) {
        echo "Not found: {$cui}" . PHP_EOL;
    }

    // VAT codes that failed format validation (never sent to ANAF)
    foreach ($result->invalidCodes as $code) {
        echo "Invalid format: {$code}" . PHP_EOL;
    }
}
\`\`\`

**Batch size limit:** If you provide more than 500 VAT codes, the method returns a failure result without calling the API.

## isValidVatCode(string $vatCode): bool

Validates VAT code format and checksum without making an API call. Uses \`VatNumberValidator\` internally.

\`\`\`php
AnafDetails::isValidVatCode('12345678');     // Validates CUI checksum
AnafDetails::isValidVatCode('RO12345678');   // Also valid (with RO prefix)
AnafDetails::isValidVatCode('abc');          // false — invalid format
\`\`\`

## CompanyLookupResultData Fields

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\CompanyLookupResultData\`

\`\`\`php
$result->success;        // bool — whether the lookup succeeded
$result->errorMessage;   // string|null — error description if success=false
$result->companies;      // CompanyData[] — found companies
$result->notFoundCuis;   // int[] — CUIs that were not found in ANAF
$result->invalidCodes;   // string[] — codes that failed format validation
\`\`\`

## CompanyData Fields

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\CompanyData\`

\`\`\`php
$company->cui;                   // string — numeric CUI without RO prefix
$company->name;                  // string — company name (denumire)
$company->address;               // string|null — raw address string from ANAF
$company->registrationNumber;    // string|null — trade register number (J40/1234/2020)
$company->phone;                 // string|null
$company->fax;                   // string|null
$company->postalCode;            // string|null
$company->isVatPayer;            // bool — registered for VAT (scop TVA)
$company->vatRegistrationDate;   // Carbon|null — VAT registration start date
$company->vatDeregistrationDate; // Carbon|null — VAT deregistration date
$company->isSplitVat;            // bool — uses split VAT payment
$company->splitVatStartDate;     // Carbon|null
$company->isRtvai;               // bool — TVA la incasare
$company->rtvaiStartDate;        // Carbon|null
$company->isInactive;            // bool — fiscally inactive (stare inactiva)
$company->inactiveDate;          // Carbon|null
$company->isDeregistered;        // bool — deregistered (radiat)
$company->deregistrationDate;    // Carbon|null
$company->headquartersAddress;   // AddressData|null — sediu social
$company->fiscalDomicileAddress; // AddressData|null — domiciliu fiscal

// Helper methods:
$company->getVatNumber();        // 'RO' . $company->cui
$company->isActive();            // !isInactive && !isDeregistered
$company->getPrimaryAddress();   // headquartersAddress ?? fiscalDomicileAddress
\`\`\`

## VatNumberValidator Utility

**Namespace:** \`BeeCoded\\EFacturaSdk\\Support\\Validators\\VatNumberValidator\`

Standalone utility for CUI/CIF validation and normalization. No API calls.

\`\`\`php
use BeeCoded\\EFacturaSdk\\Support\\Validators\\VatNumberValidator;

// Full validation (format + checksum)
VatNumberValidator::isValid('12345678');      // true/false
VatNumberValidator::isValid('RO12345678');    // true/false (with prefix)
VatNumberValidator::isValid('1234567890123'); // validates as CNP (13 digits)

// Format-only validation (no checksum)
VatNumberValidator::isValidFormat('12345678'); // true

// Add RO prefix
VatNumberValidator::normalize('12345678');     // 'RO12345678'
VatNumberValidator::normalize('RO12345678');   // 'RO12345678' (idempotent)

// Remove RO prefix
VatNumberValidator::stripPrefix('RO12345678'); // '12345678'
VatNumberValidator::stripPrefix('12345678');   // '12345678' (idempotent)
\`\`\`

## MAX_BATCH_SIZE

The maximum batch size is **500** VAT codes per request (enforced by ANAF). If you need to look up more than 500 companies, split the array into chunks:

\`\`\`php
$allCuis = range(10000000, 10001000); // 1001 CUIs
$chunks = array_chunk($allCuis, 500);

$allCompanies = [];
foreach ($chunks as $chunk) {
    $result = AnafDetails::batchGetCompanyData(
        array_map('strval', $chunk)
    );
    if ($result->success) {
        $allCompanies = array_merge($allCompanies, $result->companies);
    }
    sleep(1); // Be polite to ANAF servers
}
\`\`\`
`,
};
