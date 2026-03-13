// src/index.ts
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

// src/content/config-reference.ts
var configReferenceContent = `# Laravel e-Factura SDK \u2014 Configuration Reference

Configuration file: \`config/efactura-sdk.php\`

Publish with:
\`\`\`bash
php artisan vendor:publish --tag=efactura-sdk-config
\`\`\`

---

## \`sandbox\`

| | |
|---|---|
| **Type** | boolean |
| **Environment variable** | \`EFACTURA_SANDBOX\` |
| **Default** | \`true\` |
| **Required** | No |

Controls which ANAF environment the SDK targets.

- \`true\` \u2014 Use the ANAF **test/sandbox** environment (\`https://api.anaf.ro/test/FCTEL/rest\`)
- \`false\` \u2014 Use the ANAF **production** environment (\`https://api.anaf.ro/prod/FCTEL/rest\`)

> Always keep \`true\` during development. Set \`false\` only in production.

---

## \`oauth\`

OAuth2 credentials obtained from [ANAF's OAuth2 system](https://www.anaf.ro/CompensareFacturi/).

### \`oauth.client_id\`

| | |
|---|---|
| **Type** | string |
| **Environment variable** | \`EFACTURA_CLIENT_ID\` |
| **Default** | none |
| **Required** | Yes |

The OAuth2 client ID issued by ANAF when registering your application.

### \`oauth.client_secret\`

| | |
|---|---|
| **Type** | string |
| **Environment variable** | \`EFACTURA_CLIENT_SECRET\` |
| **Default** | none |
| **Required** | Yes |

The OAuth2 client secret issued by ANAF when registering your application.

### \`oauth.redirect_uri\`

| | |
|---|---|
| **Type** | string (URL) |
| **Environment variable** | \`EFACTURA_REDIRECT_URI\` |
| **Default** | none |
| **Required** | Yes |

The callback URL registered with ANAF for OAuth2 authorization code flow. Must exactly match the URI registered in ANAF's developer portal.

---

## \`http\`

HTTP client settings for communicating with the ANAF API.

### \`http.timeout\`

| | |
|---|---|
| **Type** | integer (seconds) |
| **Environment variable** | \`EFACTURA_TIMEOUT\` |
| **Default** | \`30\` |
| **Required** | No |

Maximum number of seconds to wait for an API response before timing out.

### \`http.retry_times\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RETRY_TIMES\` |
| **Default** | \`3\` |
| **Required** | No |

Number of times a failed HTTP request will be automatically retried.

### \`http.retry_delay\`

| | |
|---|---|
| **Type** | integer (seconds) |
| **Environment variable** | \`EFACTURA_RETRY_DELAY\` |
| **Default** | \`5\` |
| **Required** | No |

Number of seconds to wait between retry attempts.

---

## \`logging\`

Logging configuration for API calls and debug output.

### \`logging.channel\`

| | |
|---|---|
| **Type** | string |
| **Environment variable** | \`EFACTURA_LOG_CHANNEL\` |
| **Default** | \`"efactura-sdk"\` |
| **Required** | No |

The Laravel logging channel to use for SDK log messages. Add a matching channel to \`config/logging.php\`:

\`\`\`php
'efactura-sdk' => [
    'driver' => 'daily',
    'path' => storage_path('logs/efactura-sdk.log'),
    'level' => 'debug',
    'days' => 30,
],
\`\`\`

### \`logging.debug\`

| | |
|---|---|
| **Type** | boolean |
| **Environment variable** | \`EFACTURA_DEBUG\` |
| **Default** | \`false\` |
| **Required** | No |

When \`true\`, enables verbose debug logging of all HTTP requests and responses.

---

## \`endpoints\`

Base URLs for ANAF API endpoints. These should not need to be changed unless ANAF updates their API.

### \`endpoints.api\`

| Key | URL |
|---|---|
| \`test\` | \`https://api.anaf.ro/test/FCTEL/rest\` |
| \`production\` | \`https://api.anaf.ro/prod/FCTEL/rest\` |

The active API base URL is selected automatically based on the \`sandbox\` config value.

### \`endpoints.oauth\`

| Key | URL |
|---|---|
| \`authorize\` | \`https://logincert.anaf.ro/anaf-oauth2/v1/authorize\` |
| \`token\` | \`https://logincert.anaf.ro/anaf-oauth2/v1/token\` |

OAuth2 authorization and token exchange endpoints.

### \`endpoints.services\`

Additional ANAF web service endpoints:

| Key | URL | Purpose |
|---|---|---|
| \`validate\` | \`https://webservicesp.anaf.ro/prod/FCTEL/rest/validare\` | Validate UBL XML before upload |
| \`transform\` | \`https://webservicesp.anaf.ro/prod/FCTEL/rest/transformare\` | Convert XML to PDF |
| \`verify_signature\` | \`https://webservicesp.anaf.ro/prod/FCTEL/rest/verificare-semnatura\` | Verify digital signatures |

### \`endpoints.company_lookup\`

| | |
|---|---|
| **URL** | \`https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva\` |
| **Purpose** | Look up company details by CUI (fiscal identification number) |

---

## \`rate_limits\`

Rate limiting configuration to prevent exceeding ANAF API quotas. All defaults are set to **50% of ANAF's official limits** as a safety margin.

### \`rate_limits.enabled\`

| | |
|---|---|
| **Type** | boolean |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_ENABLED\` |
| **Default** | \`true\` |
| **Required** | No |

Enable or disable rate limiting globally. Disable only for local testing.

### \`rate_limits.global_per_minute\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_GLOBAL\` |
| **Default** | \`500\` |
| **ANAF official limit** | 1000 calls/minute |
| **Valid range** | 1 \u2013 1000 |

Maximum total API calls allowed per minute across all endpoints.

### \`rate_limits.rasp_upload_per_day_cui\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_RASP_UPLOAD\` |
| **Default** | \`500\` |
| **ANAF official limit** | 1000/day/CUI |
| **Valid range** | 1 \u2013 1000 |

Maximum RASP file uploads per CUI (company tax ID) per day.

### \`rate_limits.status_per_day_message\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_STATUS\` |
| **Default** | \`50\` |
| **ANAF official limit** | 100/day/message |
| **Valid range** | 1 \u2013 100 |

Maximum upload status queries per message ID per day.

### \`rate_limits.simple_list_per_day_cui\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_SIMPLE_LIST\` |
| **Default** | \`750\` |
| **ANAF official limit** | 1500/day/CUI |
| **Valid range** | 1 \u2013 1500 |

Maximum simple list (non-paginated) queries per CUI per day.

### \`rate_limits.paginated_list_per_day_cui\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_PAGINATED_LIST\` |
| **Default** | \`50000\` |
| **ANAF official limit** | 100,000/day/CUI |
| **Valid range** | 1 \u2013 100000 |

Maximum paginated list queries per CUI per day.

### \`rate_limits.download_per_day_message\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_DOWNLOAD\` |
| **Default** | \`5\` |
| **ANAF official limit** | 10/day/message |
| **Valid range** | 1 \u2013 10 |

Maximum invoice XML downloads per message ID per day.

---

## Minimal \`.env\` configuration

\`\`\`dotenv
EFACTURA_SANDBOX=true
EFACTURA_CLIENT_ID=your-client-id
EFACTURA_CLIENT_SECRET=your-client-secret
EFACTURA_REDIRECT_URI=https://your-app.com/efactura/callback
\`\`\`

## Full \`.env\` reference

\`\`\`dotenv
# Environment
EFACTURA_SANDBOX=true

# OAuth2
EFACTURA_CLIENT_ID=
EFACTURA_CLIENT_SECRET=
EFACTURA_REDIRECT_URI=

# HTTP
EFACTURA_TIMEOUT=30
EFACTURA_RETRY_TIMES=3
EFACTURA_RETRY_DELAY=5

# Logging
EFACTURA_LOG_CHANNEL=efactura-sdk
EFACTURA_DEBUG=false

# Rate limits
EFACTURA_RATE_LIMIT_ENABLED=true
EFACTURA_RATE_LIMIT_GLOBAL=500
EFACTURA_RATE_LIMIT_RASP_UPLOAD=500
EFACTURA_RATE_LIMIT_STATUS=50
EFACTURA_RATE_LIMIT_SIMPLE_LIST=750
EFACTURA_RATE_LIMIT_PAGINATED_LIST=50000
EFACTURA_RATE_LIMIT_DOWNLOAD=5
\`\`\`
`;

// src/content/enum-values.ts
var enumValuesContent = {
  InvoiceTypeCode: `# InvoiceTypeCode

Namespace: \`BeeCoded\\EFacturaSdk\\Enums\\InvoiceTypeCode\`

UBL Invoice and Credit Note type codes valid for Romanian e-Factura (rule BR-RO-020).

- For **Invoice** documents: codes \`380\`, \`384\`, \`389\`, \`751\`
- For **CreditNote** documents: code \`381\`

Reference: [OpenPEPPOL invoice type codes](https://github.com/OpenPEPPOL/peppol-bis-invoice-3/blob/master/guide/transaction-spec/codes/invoice-types-en.adoc)

## Cases

| Case | Backed value | Description |
|---|---|---|
| \`CommercialInvoice\` | \`'380'\` | Standard commercial invoice. Document/message claiming payment for goods or services supplied under conditions agreed between seller and buyer. |
| \`CreditNote\` | \`'381'\` | Credit note. Used to correct amounts or settle balances between a Supplier and a Buyer. Generates a UBL **CreditNote** document (not Invoice). |
| \`CorrectedInvoice\` | \`'384'\` | Corrected invoice. An invoice that corrects a previously issued invoice. |
| \`SelfBilledInvoice\` | \`'389'\` | Self-billed invoice. An invoice created by the buyer on behalf of the supplier. |
| \`AccountingInvoice\` | \`'751'\` | Invoice for accounting purposes. Issued for accounting/information purposes only. |

## Helper methods

### \`isCreditNote(): bool\`

Returns \`true\` only when the case is \`CreditNote\` (\`'381'\`). Use this to determine whether to generate a UBL CreditNote document instead of an Invoice.

\`\`\`php
InvoiceTypeCode::CreditNote->isCreditNote(); // true
InvoiceTypeCode::CommercialInvoice->isCreditNote(); // false
\`\`\`

### \`isInvoice(): bool\`

Returns \`true\` for all cases except \`CreditNote\`. Equivalent to \`!isCreditNote()\`.

\`\`\`php
InvoiceTypeCode::CommercialInvoice->isInvoice(); // true
InvoiceTypeCode::CreditNote->isInvoice(); // false
\`\`\`

## Usage example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\InvoiceTypeCode;

$type = InvoiceTypeCode::CommercialInvoice;
echo $type->value; // '380'

if ($type->isCreditNote()) {
    // build CreditNote UBL document
} else {
    // build Invoice UBL document
}
\`\`\`
`,
  MessageFilter: `# MessageFilter

Namespace: \`BeeCoded\\EFacturaSdk\\Enums\\MessageFilter\`

Message filters for listing operations in the ANAF e-Factura system. Each filter type represents a specific message category returned by the list endpoint.

## Cases

| Case | Backed value | Romanian label | Description |
|---|---|---|---|
| \`InvoiceSent\` | \`'T'\` | FACTURA TRIMISA | Invoice **sent** by you to a buyer |
| \`InvoiceReceived\` | \`'P'\` | FACTURA PRIMITA | Invoice **received** by you from a supplier |
| \`InvoiceErrors\` | \`'E'\` | ERORI FACTURA | Error messages returned after uploading invalid XML |
| \`BuyerMessage\` | \`'R'\` | MESAJ CUMPARATOR | RASP message/comment from buyer to issuer (or vice versa) |

## Usage example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\MessageFilter;

// List invoices you have sent
$messages = $client->listMessages(
    cif: '12345678',
    filter: MessageFilter::InvoiceSent,
    days: 60,
);

// List invoices you received
$received = $client->listMessages(
    cif: '12345678',
    filter: MessageFilter::InvoiceReceived,
    days: 60,
);
\`\`\`
`,
  ExecutionStatus: `# ExecutionStatus

Namespace: \`BeeCoded\\EFacturaSdk\\Enums\\ExecutionStatus\`

Execution status for upload operations. Represents the \`executionStatus\` field in ANAF upload responses.

## Cases

| Case | Backed value | Description |
|---|---|---|
| \`Success\` | \`0\` | Upload was processed successfully |
| \`Error\` | \`1\` | Upload failed due to an error |

Note: This enum is backed by **int** (not string).

## Usage example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\ExecutionStatus;

$status = ExecutionStatus::from($response['executionStatus']);

if ($status === ExecutionStatus::Success) {
    // upload accepted
} else {
    // handle upload error
}
\`\`\`
`,
  DocumentStandardType: `# DocumentStandardType

Namespace: \`BeeCoded\\EFacturaSdk\\Enums\\DocumentStandardType\`

Document standards used for XML validation and PDF conversion via ANAF web services.

## Cases

| Case | Backed value | Description |
|---|---|---|
| \`FACT1\` | \`'FACT1'\` | Standard invoice format |
| \`FCN\` | \`'FCN'\` | Credit note format |

## Usage example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\DocumentStandardType;

// Validate an invoice XML
$result = $validationService->validate(
    xml: $invoiceXml,
    standard: DocumentStandardType::FACT1,
);

// Validate a credit note XML
$result = $validationService->validate(
    xml: $creditNoteXml,
    standard: DocumentStandardType::FCN,
);
\`\`\`
`,
  StandardType: `# StandardType

Namespace: \`BeeCoded\\EFacturaSdk\\Enums\\StandardType\`

Standard document types supported by ANAF e-Factura. Used to indicate the XML format of submitted documents.

## Cases

| Case | Backed value | Description |
|---|---|---|
| \`UBL\` | \`'UBL'\` | Universal Business Language format (standard invoice) |
| \`CN\` | \`'CN'\` | Credit Note format |
| \`CII\` | \`'CII'\` | Cross Industry Invoice format |
| \`RASP\` | \`'RASP'\` | Response/message format (buyer reply) |

## Usage example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\StandardType;

// Upload a UBL invoice
$response = $client->uploadInvoice(
    xml: $invoiceXml,
    cif: '12345678',
    standard: StandardType::UBL,
);

// Upload a credit note
$response = $client->uploadInvoice(
    xml: $creditNoteXml,
    cif: '12345678',
    standard: StandardType::CN,
);
\`\`\`
`,
  TaxCategoryId: `# TaxCategoryId

Namespace: \`BeeCoded\\EFacturaSdk\\Enums\\TaxCategoryId\`

Tax Category identifiers for VAT classification in UBL invoice line items and tax totals.

## Cases

| Case | Backed value | Description |
|---|---|---|
| \`NotSubject\` | \`'O'\` | Not subject to VAT (outside scope) |
| \`Standard\` | \`'S'\` | Standard rated VAT (e.g. 19% in Romania) |
| \`ZeroRated\` | \`'Z'\` | Zero-rated VAT (0%) |

## Usage example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\TaxCategoryId;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceLineData;

$line = new InvoiceLineData(
    description: 'Consulting services',
    quantity: 1,
    unitPrice: 1000.00,
    taxCategory: TaxCategoryId::Standard,
    taxPercent: 19.0,
);
\`\`\`
`,
  UploadStatusValue: `# UploadStatusValue

Namespace: \`BeeCoded\\EFacturaSdk\\Enums\\UploadStatusValue\`

Status values for upload processing, corresponding to the \`stare\` (state) field in ANAF status check responses. As defined in the ANAF OpenAPI specification.

## Cases

| Case | Backed value | Description |
|---|---|---|
| \`Ok\` | \`'ok'\` | Processing completed successfully |
| \`Failed\` | \`'nok'\` | Processing failed |
| \`InProgress\` | \`'in prelucrare'\` | Currently being processed by ANAF |

Note: \`InProgress\` has a **multi-word** backed value with a space: \`'in prelucrare'\`.

## Usage example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\UploadStatusValue;

$status = UploadStatusValue::from($response['stare']);

match ($status) {
    UploadStatusValue::Ok         => $this->markAsAccepted($uploadId),
    UploadStatusValue::Failed     => $this->markAsFailed($uploadId),
    UploadStatusValue::InProgress => $this->scheduleRetry($uploadId),
};
\`\`\`
`
};

// src/content/dto-structures.ts
var dtoStructuresContent = {
  InvoiceData: `# InvoiceData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceData\`

Complete invoice data for e-Factura submission. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$invoiceNumber\` | \`string\` | yes | \u2014 | Invoice number/identifier |
| \`$issueDate\` | \`Carbon|string\` | yes | \u2014 | Invoice issue date |
| \`$supplier\` | \`PartyData\` | yes | \u2014 | Supplier (seller) information |
| \`$customer\` | \`PartyData\` | yes | \u2014 | Customer (buyer) information |
| \`$lines\` | \`InvoiceLineData[]\` | yes | \u2014 | Invoice line items (annotated with \`#[DataCollectionOf(InvoiceLineData::class)]\`) |
| \`$dueDate\` | \`Carbon|string|null\` | no | \`null\` | Payment due date |
| \`$currency\` | \`string\` | no | \`'RON'\` | Currency code (ISO 4217) |
| \`$paymentIban\` | \`?string\` | no | \`null\` | IBAN for payment |
| \`$invoiceTypeCode\` | \`?InvoiceTypeCode\` | no | \`null\` | Type of invoice \u2014 resolved via \`getInvoiceTypeCode()\` which defaults to \`CommercialInvoice\` |
| \`$precedingInvoiceNumber\` | \`?string\` | no | \`null\` | Preceding invoice number for credit notes (BT-25, used in BillingReference element) |

## Public Methods

### \`getIssueDateAsCarbon(): Carbon\`
Returns the issue date as a Carbon instance. Returns a copy to prevent mutation. Throws \`\\InvalidArgumentException\` if the date string cannot be parsed.

### \`getDueDateAsCarbon(): ?Carbon\`
Returns the due date as a Carbon instance, or null if not set. Returns a copy. Throws \`\\InvalidArgumentException\` on unparseable string.

### \`getInvoiceTypeCode(): InvoiceTypeCode\`
Returns \`$invoiceTypeCode ?? InvoiceTypeCode::CommercialInvoice\`. Use this accessor rather than the raw property.

### \`getTotalExcludingVat(): float\`
Sums raw (unrounded) line totals and rounds once at the end to 2 decimal places.

### \`getTotalVat(): float\`
Sums per-line \`taxAmount\` values (pre-computed, not recalculated). Rounded to 2 decimal places.

### \`getTotalIncludingVat(): float\`
Returns \`getTotalExcludingVat() + getTotalVat()\`, rounded to 2 decimal places.

## Usage Notes

- **Credit notes:** Set \`$invoiceTypeCode = InvoiceTypeCode::CreditNote\` and provide \`$precedingInvoiceNumber\` (the original invoice number being credited). The builder uses \`precedingInvoiceNumber\` to populate the UBL \`BillingReference\` element (BT-25).
- If \`$invoiceTypeCode\` is \`null\` (omitted), the builder treats the document as a standard commercial invoice (code \`380\`).
- \`$currency\` defaults to \`'RON'\`. For EUR invoices, pass \`'EUR'\`.

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceData;
use BeeCoded\\EFacturaSdk\\Enums\\InvoiceTypeCode;

$invoice = new InvoiceData(
    invoiceNumber: 'INV-2024-001',
    issueDate: '2024-01-15',
    supplier: $supplierParty,
    customer: $customerParty,
    lines: [$line1, $line2],
    dueDate: '2024-02-15',
    currency: 'RON',
    paymentIban: 'RO49AAAA1B31007593840000',
);

// Credit note example
$creditNote = new InvoiceData(
    invoiceNumber: 'CN-2024-001',
    issueDate: '2024-01-20',
    supplier: $supplierParty,
    customer: $customerParty,
    lines: [$creditLine],
    invoiceTypeCode: InvoiceTypeCode::CreditNote,
    precedingInvoiceNumber: 'INV-2024-001',
);
\`\`\`
`,
  InvoiceLineData: `# InvoiceLineData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceLineData\`

Invoice line item data. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$name\` | \`string\` | yes | \u2014 | Product or service name |
| \`$quantity\` | \`float\` | yes | \u2014 | Quantity of items. Can be negative for credit notes/corrective invoices. |
| \`$unitPrice\` | \`float\` | yes | \u2014 | Unit price (excluding VAT) |
| \`$taxAmount\` | \`float\` | yes | \u2014 | **Pre-computed tax amount for this line** (v2.0 breaking change \u2014 now required, no default) |
| \`$id\` | \`string|int|null\` | no | \`null\` | Line item identifier (auto-generated by builder if null) |
| \`$description\` | \`?string\` | no | \`null\` | Additional description |
| \`$unitCode\` | \`string\` | no | \`'EA'\` | Unit of measure code (UN/ECE rec 20, e.g. \`'EA'\` = each, \`'KGM'\` = kilogram) |
| \`$taxPercent\` | \`float\` | no | \`0\` | VAT percentage (e.g. \`19\` for 19%). Must be non-negative (\`#[Min(0)]\` validation). |

## Validation

- \`$taxPercent\` has a \`#[Min(0)]\` attribute from \`Spatie\\LaravelData\\Attributes\\Validation\\Min\`. Tax percent must be zero or positive.

## Public Methods

### \`getLineTotal(): float\`
Returns \`round(quantity * unitPrice, 2)\`.

### \`getTaxAmount(): float\`
Returns \`round(taxAmount, 2)\`. This is the pre-computed value passed at construction.

### \`getRawLineTotal(): float\`
Returns unrounded \`quantity * unitPrice\`. Used internally by \`InvoiceData::getTotalExcludingVat()\` for tax grouping to avoid double-rounding.

### \`getLineTotalWithTax(): float\`
Returns \`round(getLineTotal() + getTaxAmount(), 2)\`.

## Critical Notes

- **\`taxAmount\` is required** (positional parameter, no default). This is a **v2.0 breaking change** from previous versions where it was optional or auto-calculated.
- The SDK **does not compute taxAmount from taxPercent \xD7 unitPrice \xD7 quantity**. You must pre-compute it in your application.
- **Sign must follow quantity:** for credit note lines with negative quantity, taxAmount must also be negative.
- The builder sums per-line \`taxAmount\` values directly for the invoice tax total, rather than recalculating from the rate, to avoid rounding discrepancies.

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceLineData;

// Standard line: 10 units \xD7 100 RON, 19% VAT
// taxAmount = 10 * 100 * 0.19 = 190.00 (pre-computed by your app)
$line = new InvoiceLineData(
    name: 'Widget A',
    quantity: 10.0,
    unitPrice: 100.0,
    taxAmount: 190.0,
    taxPercent: 19.0,
    unitCode: 'EA',
);

// Credit note line: negative quantity, negative taxAmount
$creditLine = new InvoiceLineData(
    name: 'Widget A',
    quantity: -10.0,
    unitPrice: 100.0,
    taxAmount: -190.0,
    taxPercent: 19.0,
);
\`\`\`
`,
  PartyData: `# PartyData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\PartyData\`

Party information (supplier or customer) for an invoice. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$registrationName\` | \`string\` | yes | \u2014 | Legal name of the party as registered |
| \`$companyId\` | \`string\` | yes | \u2014 | CIF/CUI number without RO prefix (e.g. \`'49296198'\`). The builder adds \`RO\` prefix automatically for VAT payers. |
| \`$address\` | \`AddressData\` | yes | \u2014 | Address of the party (Invoice\\AddressData) |
| \`$registrationNumber\` | \`?string\` | no | \`null\` | ONRC trade register identifier (e.g. \`'J40/1234/2020'\`) |
| \`$isVatPayer\` | \`bool\` | no | \`false\` | Whether the party is a VAT payer |

## Critical Notes

- **\`$isVatPayer\` affects XML output:** when \`true\`, the UBL builder prepends \`RO\` to \`$companyId\` in the \`CompanyID\` XML element. Pass the raw numeric CIF (without \`RO\`) and let the builder handle the prefix.
- \`$address\` is \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\AddressData\`, not the Company namespace AddressData.

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\PartyData;
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\AddressData;

$supplier = new PartyData(
    registrationName: 'Acme SRL',
    companyId: '49296198',           // no RO prefix
    address: new AddressData(
        street: 'Str. Exemplu 1',
        city: 'Bucuresti',
        county: 'RO-B',
        postalZone: '010101',
    ),
    registrationNumber: 'J40/1234/2020',
    isVatPayer: true,                // builder will write <CompanyID>RO49296198</CompanyID>
);
\`\`\`
`,
  InvoiceAddressData: `# InvoiceAddressData (class name: AddressData)

**Actual PHP class name:** \`AddressData\`
**Full namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\AddressData\`

Address information for a party (supplier or customer) in an invoice. Extends \`Spatie\\LaravelData\\Data\`.

Note: This class shares the simple name \`AddressData\` with \`BeeCoded\\EFacturaSdk\\Data\\Company\\AddressData\`. Always import with the full namespace or alias to avoid conflicts.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$street\` | \`string\` | yes | \u2014 | Street address |
| \`$city\` | \`string\` | yes | \u2014 | City name |
| \`$postalZone\` | \`?string\` | no | \`null\` | Postal/ZIP code |
| \`$county\` | \`?string\` | no | \`null\` | County/region code. **Required for Romanian addresses** (ISO 3166-2:RO codes, e.g. \`'RO-B'\` for Bucharest, \`'RO-CJ'\` for Cluj). |
| \`$countryCode\` | \`string\` | no | \`'RO'\` | ISO 3166-1 alpha-2 country code |

## Critical Notes

- **\`$county\` is required for Romanian addresses.** ANAF validation rejects invoices with \`countryCode = 'RO'\` and a missing county. Use ISO 3166-2:RO sub-region codes (format \`RO-XX\`).
- \`$countryCode\` defaults to \`'RO'\`. For foreign parties, set this to their country code (e.g. \`'DE'\`, \`'FR'\`).

## ISO 3166-2:RO county codes (common examples)

- \`RO-B\` \u2014 Municipiul Bucuresti
- \`RO-CJ\` \u2014 Cluj
- \`RO-TM\` \u2014 Timis
- \`RO-IS\` \u2014 Iasi
- \`RO-BV\` \u2014 Brasov

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\AddressData;

$address = new AddressData(
    street: 'Str. Victoriei 10',
    city: 'Cluj-Napoca',
    postalZone: '400001',
    county: 'RO-CJ',
    countryCode: 'RO',
);
\`\`\`
`,
  UploadOptionsData: `# UploadOptionsData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\UploadOptionsData\`

Options for uploading a document to ANAF e-Factura. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$standard\` | \`?StandardType\` | no | \`null\` | Document standard type (UBL, CN, CII, RASP). Resolved via \`getStandard()\` which defaults to \`StandardType::UBL\`. |
| \`$extern\` | \`bool\` | no | \`false\` | External invoice (B2B outside the e-Factura system) |
| \`$selfBilled\` | \`bool\` | no | \`false\` | Self-billed invoice (autofactura) \u2014 invoice issued by buyer on behalf of supplier |
| \`$executare\` | \`bool\` | no | \`false\` | Execution/enforcement invoice (executare silita) |

## Public Methods

### \`getStandard(): StandardType\`
Returns \`$standard ?? StandardType::UBL\`. Use this instead of the raw property to get the resolved default.

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\UploadOptionsData;
use BeeCoded\\EFacturaSdk\\Enums\\StandardType;

// Default UBL upload
$options = new UploadOptionsData();

// Credit note upload
$options = new UploadOptionsData(standard: StandardType::CN);

// Self-billed invoice
$options = new UploadOptionsData(selfBilled: true);
\`\`\`
`,
  OAuthTokensData: `# OAuthTokensData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Auth\\OAuthTokensData\`

OAuth 2.0 token data from ANAF. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$accessToken\` | \`string\` | yes | \u2014 | OAuth access token |
| \`$refreshToken\` | \`string\` | yes | \u2014 | OAuth refresh token |
| \`$expiresAt\` | \`?Carbon\` | no | \`null\` | Absolute expiry timestamp |
| \`$expiresIn\` | \`?int\` | no | \`null\` | Token lifetime in seconds (as returned by ANAF) |
| \`$tokenType\` | \`string\` | no | \`'Bearer'\` | Token type |

## Static Factory Methods

### \`fromAnafResponse(array $response): self\`
Creates an instance from the raw ANAF token response array. Computes \`$expiresAt\` as \`Carbon::now()->addSeconds($response['expires_in'])\` when \`expires_in\` is present.

Expected keys: \`access_token\`, \`refresh_token\`, \`expires_in\` (optional), \`token_type\` (optional).

## Public Methods

### \`isExpired(int $bufferSeconds = 120): bool\`
Returns \`true\` if the token has expired or will expire within \`$bufferSeconds\` (default 120 s). Returns \`false\` if \`$expiresAt\` is null (unknown expiry = treated as not expired).

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Auth\\OAuthTokensData;

// From ANAF response
$tokens = OAuthTokensData::fromAnafResponse($response);

// Check before using
if ($tokens->isExpired()) {
    $tokens = $authenticator->refreshTokens($tokens->refreshToken);
}
\`\`\`
`,
  AuthUrlSettingsData: `# AuthUrlSettingsData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Auth\\AuthUrlSettingsData\`

Settings for building the OAuth authorization URL. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$state\` | \`array<string, mixed>|null\` | no | \`null\` | State data to encode into the authorization URL (CSRF protection / round-trip data) |
| \`$scope\` | \`?string\` | no | \`null\` | OAuth scope string |

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Auth\\AuthUrlSettingsData;

$settings = new AuthUrlSettingsData(
    state: ['user_id' => 42, 'redirect' => '/dashboard'],
    scope: 'read write',
);

$url = $authenticator->getAuthorizationUrl($settings);
\`\`\`
`,
  ListMessagesParamsData: `# ListMessagesParamsData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\ListMessagesParamsData\`

Parameters for listing messages from ANAF e-Factura (simple days-based listing). Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$cif\` | \`string\` | yes | \u2014 | Company fiscal identifier (CIF/CUI without \`RO\` prefix) |
| \`$days\` | \`int\` | yes | \u2014 | Number of days to look back (1\u201360). Validated with \`#[Between(1, 60)]\`. |
| \`$filter\` | \`?MessageFilter\` | no | \`null\` | Filter by message type (optional \u2014 returns all types if null) |

## Validation

- \`$days\` has a \`#[Between(1, 60)]\` attribute from \`Spatie\\LaravelData\\Attributes\\Validation\\Between\`. Values outside the 1\u201360 range will fail validation.

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\ListMessagesParamsData;
use BeeCoded\\EFacturaSdk\\Enums\\MessageFilter;

$params = new ListMessagesParamsData(
    cif: '12345678',
    days: 30,
    filter: MessageFilter::InvoiceSent,
);
\`\`\`
`,
  PaginatedMessagesParamsData: `# PaginatedMessagesParamsData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\PaginatedMessagesParamsData\`

Parameters for the paginated message listing endpoint from ANAF e-Factura. Uses millisecond timestamps for date range. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$cif\` | \`string\` | yes | \u2014 | Company fiscal identifier (CIF/CUI without \`RO\` prefix) |
| \`$startTime\` | \`int\` | yes | \u2014 | Start of date range in **milliseconds** since Unix epoch |
| \`$endTime\` | \`int\` | yes | \u2014 | End of date range in **milliseconds** since Unix epoch |
| \`$page\` | \`int\` | no | \`1\` | Page number (1-indexed). Validated with \`#[Min(1)]\`. |
| \`$filter\` | \`?MessageFilter\` | no | \`null\` | Filter by message type |

## Validation

- \`$page\` has a \`#[Min(1)]\` attribute. Zero or negative values will fail validation.

## Static Factory Methods

### \`fromDateRange(string $cif, Carbon $startDate, Carbon $endDate, int $page = 1, ?MessageFilter $filter = null): self\`
Convenience constructor that accepts Carbon dates and converts them to millisecond timestamps via \`->getTimestampMs()\`.

## Public Methods

### \`getStartTimeAsCarbon(): Carbon\`
Converts \`$startTime\` (ms) back to a Carbon instance via \`Carbon::createFromTimestampMs()\`.

### \`getEndTimeAsCarbon(): Carbon\`
Converts \`$endTime\` (ms) back to a Carbon instance via \`Carbon::createFromTimestampMs()\`.

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\PaginatedMessagesParamsData;
use Carbon\\Carbon;

// Using the factory method (recommended)
$params = PaginatedMessagesParamsData::fromDateRange(
    cif: '12345678',
    startDate: Carbon::now()->subDays(30),
    endDate: Carbon::now(),
    page: 1,
);

// Manual construction with ms timestamps
$params = new PaginatedMessagesParamsData(
    cif: '12345678',
    startTime: 1700000000000,
    endTime: 1702592000000,
    page: 2,
);
\`\`\`
`,
  UploadResponseData: `# UploadResponseData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Response\\UploadResponseData\`

Response from a document upload operation. Extends \`Spatie\\LaravelData\\Data\`. Annotated with \`#[MapInputName(SnakeCaseMapper::class)]\` so snake_case input fields are mapped to camelCase properties.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$executionStatus\` | \`ExecutionStatus\` | yes | \u2014 | Execution status (0 = Success, 1 = Error) |
| \`$dateResponse\` | \`?string\` | no | \`null\` | ANAF response timestamp |
| \`$indexIncarcare\` | \`?string\` | no | \`null\` | Upload/load index ID \u2014 only present on success; use this as the upload ID for status polling |
| \`$errors\` | \`string[]|null\` | no | \`null\` | Error messages \u2014 only present on error |

## Static Factory Methods

### \`fromAnafResponse(array $response): self\`
Parses the raw ANAF response array. Uses \`ExecutionStatus::tryFrom()\` with safe fallback to \`ExecutionStatus::Error\` if the field is missing or invalid.

Expected keys: \`ExecutionStatus\`, \`dateResponse\`, \`index_incarcare\`, \`Errors\`.

## Public Methods

### \`isSuccessful(): bool\`
Returns \`true\` when \`$executionStatus === ExecutionStatus::Success\`.

### \`isFailed(): bool\`
Returns \`true\` when \`$executionStatus === ExecutionStatus::Error\`.

## Example

\`\`\`php
$response = UploadResponseData::fromAnafResponse($apiResponse);

if ($response->isSuccessful()) {
    $uploadId = $response->indexIncarcare; // poll status with this
} else {
    $errors = $response->errors;
}
\`\`\`
`,
  StatusResponseData: `# StatusResponseData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Response\\StatusResponseData\`

Response from a status check operation for a previously uploaded document. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$stare\` | \`?UploadStatusValue\` | no | \`null\` | Processing status (ok, nok, in prelucrare) |
| \`$idDescarcare\` | \`?string\` | no | \`null\` | Download ID \u2014 present for both \`ok\` and \`nok\` responses; use to download the result ZIP |
| \`$errors\` | \`string[]|null\` | no | \`null\` | Error messages |

## Static Factory Methods

### \`fromAnafResponse(array $response): self\`
Parses the raw ANAF response. Uses \`UploadStatusValue::tryFrom()\` for safe enum parsing.

Expected keys: \`stare\`, \`id_descarcare\`, \`Errors\`.

## Public Methods

### \`isReady(): bool\`
Returns \`true\` when \`$stare === UploadStatusValue::Ok\` (processing complete and successful).

### \`isFailed(): bool\`
Returns \`true\` when \`$stare === UploadStatusValue::Failed\`.

### \`isInProgress(): bool\`
Returns \`true\` when \`$stare === UploadStatusValue::InProgress\` (still being processed by ANAF).

## Usage Notes

- When \`isInProgress()\` is true, wait and retry the status check (ANAF typically processes within minutes).
- \`$idDescarcare\` is available for both \`ok\` and \`nok\` results \u2014 for \`nok\` it points to an error report ZIP.

## Example

\`\`\`php
$status = StatusResponseData::fromAnafResponse($apiResponse);

if ($status->isReady()) {
    $downloadId = $status->idDescarcare;
} elseif ($status->isFailed()) {
    // download error report using $status->idDescarcare
} elseif ($status->isInProgress()) {
    // schedule retry
}
\`\`\`
`,
  DownloadResponseData: `# DownloadResponseData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Response\\DownloadResponseData\`

Response from a document download operation. Contains the binary content of the downloaded ZIP file. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$content\` | \`string\` | yes | \u2014 | Binary content of the ZIP file |
| \`$contentType\` | \`string\` | yes | \u2014 | Content-Type header value (e.g. \`'application/zip'\`) |
| \`$filename\` | \`?string\` | no | \`null\` | Suggested filename from the \`Content-Disposition\` response header |
| \`$contentLength\` | \`?int\` | no | \`null\` | Content length in bytes |

## Static Factory Methods

### \`fromHttpResponse(string $content, array $headers = []): self\`
Creates an instance from binary content and HTTP response headers. Handles both capitalized and lowercase header names. Parses \`Content-Disposition\` to extract filename. Falls back to \`strlen($content)\` if \`Content-Length\` header is absent.

## Public Methods

### \`saveTo(string $path): bool\`
Saves binary content to a file at the given path. Returns \`true\` on success, \`false\` on failure.

### \`getStream(): resource|false\`
Returns a seeked in-memory stream resource (\`php://memory\`) containing the content. Returns \`false\` on failure.

## Example

\`\`\`php
$download = DownloadResponseData::fromHttpResponse($body, $headers);

// Save to disk
$download->saveTo('/tmp/invoice_bundle.zip');

// Use as stream (e.g. for Laravel response streaming)
$stream = $download->getStream();
\`\`\`
`,
  ValidationResultData: `# ValidationResultData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Response\\ValidationResultData\`

Response from an XML validation operation. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$valid\` | \`bool\` | yes | \u2014 | Whether the document passed validation |
| \`$details\` | \`?string\` | no | \`null\` | Validation details/messages |
| \`$info\` | \`?string\` | no | \`null\` | Additional informational text |
| \`$errors\` | \`string[]|null\` | no | \`null\` | Array of error messages |

## Static Factory Methods

### \`fromAnafResponse(array $response): self\`
Creates from a raw ANAF response array. Expected keys: \`valid\`, \`details\`, \`info\`, \`Errors\`.

### \`success(?string $details = null): self\`
Creates a result with \`valid = true\`.

### \`failure(?string $details = null, ?array $errors = null): self\`
Creates a result with \`valid = false\`.

## Example

\`\`\`php
$result = ValidationResultData::fromAnafResponse($apiResponse);

if (!$result->valid) {
    foreach ($result->errors ?? [] as $error) {
        logger()->error('Validation error: ' . $error);
    }
}
\`\`\`
`,
  ListMessagesResponseData: `# ListMessagesResponseData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Response\\ListMessagesResponseData\`

Response from the list messages operation (non-paginated). Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$mesaje\` | \`MessageDetailsData[]|null\` | no | \`null\` | Array of messages (annotated with \`#[DataCollectionOf(MessageDetailsData::class)]\`) |
| \`$serial\` | \`?string\` | no | \`null\` | Serial number from ANAF response |
| \`$cui\` | \`?string\` | no | \`null\` | CIF/CUI from ANAF response |
| \`$titlu\` | \`?string\` | no | \`null\` | Response title (Romanian) |
| \`$info\` | \`?string\` | no | \`null\` | Additional information |
| \`$error\` | \`?string\` | no | \`null\` | Error message (mapped from \`eroare\` via \`#[MapInputName('eroare')]\`) |
| \`$downloadError\` | \`?string\` | no | \`null\` | Download error message (mapped from \`eroare_descarcare\`) |

## Static Factory Methods

### \`fromAnafResponse(array $response): self\`
Parses the raw ANAF response. Filters \`mesaje\` to only include valid array items before mapping each to \`MessageDetailsData::fromAnafResponse()\`.

## Public Methods

### \`hasMessages(): bool\`
Returns \`true\` if \`$mesaje\` is non-empty.

### \`getMessageCount(): int\`
Returns the count of messages in \`$mesaje\` (0 if null).

### \`hasError(): bool\`
Returns \`true\` if either \`$error\` or \`$downloadError\` is non-null.

## Example

\`\`\`php
$response = ListMessagesResponseData::fromAnafResponse($apiResponse);

if ($response->hasError()) {
    logger()->error($response->error ?? $response->downloadError);
} elseif ($response->hasMessages()) {
    foreach ($response->mesaje as $message) {
        // process MessageDetailsData
    }
}
\`\`\`
`,
  PaginatedMessagesResponseData: `# PaginatedMessagesResponseData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Response\\PaginatedMessagesResponseData\`

Response from the paginated list messages operation. Extends \`Spatie\\LaravelData\\Data\`. Uses \`#[MapInputName]\` attributes on several properties to map Romanian snake_case ANAF field names to English camelCase.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$mesaje\` | \`MessageDetailsData[]|null\` | no | \`null\` | Array of messages for the current page |
| \`$recordsInPage\` | \`?int\` | no | \`null\` | Number of records in the current page (from \`numar_inregistrari_in_pagina\`) |
| \`$recordsPerPage\` | \`?int\` | no | \`null\` | Total records per page / page size limit (from \`numar_total_inregistrari_per_pagina\`) |
| \`$totalRecords\` | \`?int\` | no | \`null\` | Total records across all pages (from \`numar_total_inregistrari\`) |
| \`$totalPages\` | \`?int\` | no | \`null\` | Total number of pages (from \`numar_total_pagini\`) |
| \`$currentPage\` | \`?int\` | no | \`null\` | Current page index, 1-based (from \`index_pagina_curenta\`) |
| \`$serial\` | \`?string\` | no | \`null\` | Serial number |
| \`$cui\` | \`?string\` | no | \`null\` | CIF/CUI |
| \`$titlu\` | \`?string\` | no | \`null\` | Response title |
| \`$error\` | \`?string\` | no | \`null\` | Error message (mapped from \`eroare\`) |

## Static Factory Methods

### \`fromAnafResponse(array $response): self\`
Parses the raw ANAF response array, mapping Romanian field names to typed properties.

## Public Methods

### \`hasMessages(): bool\`
Returns \`true\` if \`$mesaje\` is non-empty.

### \`getMessageCount(): int\`
Returns the count of messages on the current page.

### \`hasError(): bool\`
Returns \`true\` if \`$error\` is non-null.

### \`hasNextPage(): bool\`
Returns \`true\` if \`currentPage < totalPages\`. Returns \`false\` if either is null.

### \`hasPreviousPage(): bool\`
Returns \`true\` if \`currentPage > 1\`. Returns \`false\` if \`currentPage\` is null.

### \`isFirstPage(): bool\`
Returns \`true\` if \`currentPage === 1\`.

### \`isLastPage(): bool\`
Returns \`true\` if \`currentPage >= totalPages\`, or \`true\` if either is null (defensive default).

## Usage Notes

- Pages are **1-indexed** (first page = 1). Pass \`page: 1\` in \`PaginatedMessagesParamsData\`.

## Example

\`\`\`php
$page = 1;
do {
    $params = PaginatedMessagesParamsData::fromDateRange(
        cif: '12345678',
        startDate: $start,
        endDate: $end,
        page: $page,
    );
    $response = $client->listMessagesPaginated($params);

    foreach ($response->mesaje ?? [] as $message) {
        // process
    }

    $page++;
} while ($response->hasNextPage());
\`\`\`
`,
  MessageDetailsData: `# MessageDetailsData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Response\\MessageDetailsData\`

Single message details within a message list response. Extends \`Spatie\\LaravelData\\Data\`. Uses \`#[MapInputName]\` attributes on \`dataCreare\` and \`idSolicitare\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$id\` | \`string\` | yes | \u2014 | Download ID for this message |
| \`$cif\` | \`string\` | yes | \u2014 | CIF number associated with the message |
| \`$dataCreare\` | \`string\` | yes | \u2014 | Creation date string (mapped from \`data_creare\` via \`#[MapInputName('data_creare')]\`) |
| \`$tip\` | \`string\` | yes | \u2014 | Message type (e.g. \`'FACTURA TRIMISA'\`, \`'FACTURA PRIMITA'\`) |
| \`$detalii\` | \`string\` | yes | \u2014 | Message details/description |
| \`$idSolicitare\` | \`string\` | yes | \u2014 | Request/upload ID (mapped from \`id_solicitare\` via \`#[MapInputName('id_solicitare')]\`) |

## Static Factory Methods

### \`fromAnafResponse(array $data): self\`
Creates an instance from a raw ANAF message item array. All fields are cast to \`string\` with empty-string fallback.

Expected keys: \`id\`, \`cif\`, \`data_creare\`, \`tip\`, \`detalii\`, \`id_solicitare\`.

## Usage Notes

- \`$id\` is the **download ID** used to download the message ZIP via the download endpoint.
- \`$idSolicitare\` corresponds to the original upload \`indexIncarcare\` (upload ID).

## Example

\`\`\`php
foreach ($listResponse->mesaje ?? [] as $message) {
    echo $message->tip;          // 'FACTURA TRIMISA'
    echo $message->dataCreare;   // '202401150930'
    echo $message->id;           // used for downloading
}
\`\`\`
`,
  CompanyData: `# CompanyData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\CompanyData\`

Company data from an ANAF company lookup. Comprehensive DTO containing general details, VAT status, addresses, and registration statuses. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$cui\` | \`string\` | yes | \u2014 | Company fiscal identification code (CUI/CIF) **without** RO prefix |
| \`$name\` | \`string\` | yes | \u2014 | Company name (denumire) |
| \`$address\` | \`?string\` | no | \`null\` | Free-text address string from \`date_generale\` |
| \`$registrationNumber\` | \`?string\` | no | \`null\` | Trade register number (nrRegCom), e.g. \`'J40/1234/2020'\` |
| \`$phone\` | \`?string\` | no | \`null\` | Phone number |
| \`$fax\` | \`?string\` | no | \`null\` | Fax number |
| \`$postalCode\` | \`?string\` | no | \`null\` | Postal code from \`date_generale\` |
| \`$isVatPayer\` | \`bool\` | no | \`false\` | Whether the company is a VAT payer (platitor TVA) |
| \`$vatRegistrationDate\` | \`?Carbon\` | no | \`null\` | VAT registration date |
| \`$vatDeregistrationDate\` | \`?Carbon\` | no | \`null\` | VAT deregistration date |
| \`$isSplitVat\` | \`bool\` | no | \`false\` | Whether company uses Split VAT (plata defalcata TVA) |
| \`$splitVatStartDate\` | \`?Carbon\` | no | \`null\` | Split VAT start date |
| \`$isRtvai\` | \`bool\` | no | \`false\` | Whether company uses TVA la incasare (RTVAI) |
| \`$rtvaiStartDate\` | \`?Carbon\` | no | \`null\` | RTVAI start date |
| \`$isInactive\` | \`bool\` | no | \`false\` | Whether the company is fiscally inactive |
| \`$inactiveDate\` | \`?Carbon\` | no | \`null\` | Date when company became inactive |
| \`$isDeregistered\` | \`bool\` | no | \`false\` | Whether the company has been deregistered (radiat) |
| \`$deregistrationDate\` | \`?Carbon\` | no | \`null\` | Deregistration date |
| \`$headquartersAddress\` | \`?AddressData\` | no | \`null\` | Headquarters address (Company\\AddressData) |
| \`$fiscalDomicileAddress\` | \`?AddressData\` | no | \`null\` | Fiscal domicile address (Company\\AddressData) |
| \`$rtvaiDetails\` | \`?VatRegistrationData\` | no | \`null\` | Detailed RTVAI registration data |
| \`$splitVatDetails\` | \`?SplitVatData\` | no | \`null\` | Detailed Split VAT registration data |
| \`$inactiveStatusDetails\` | \`?InactiveStatusData\` | no | \`null\` | Detailed inactive/deregistered status data |

## Static Factory Methods

### \`fromAnafResponse(array $data): self\`
Parses the full ANAF found company response structure. Processes nested keys: \`date_generale\`, \`inregistrare_scop_Tva\`, \`inregistrare_RTVAI\`, \`stare_inactiv\`, \`inregistrare_SplitTVA\`, \`adresa_sediu_social\`, \`adresa_domiciliu_fiscal\`.

## Public Methods

### \`getVatNumber(): string\`
Returns \`'RO' . $this->cui\`.

### \`isActive(): bool\`
Returns \`true\` if the company is neither inactive nor deregistered.

### \`getPrimaryAddress(): ?AddressData\`
Returns \`$headquartersAddress ?? $fiscalDomicileAddress\`.

## Example

\`\`\`php
$company = CompanyData::fromAnafResponse($anafFoundCompany);

if ($company->isActive() && $company->isVatPayer) {
    // safe to issue VAT invoice
}

$vatNumber = $company->getVatNumber(); // 'RO12345678'
\`\`\`
`,
  CompanyLookupResultData: `# CompanyLookupResultData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\CompanyLookupResultData\`

Result wrapper for company lookup operations. Contains found companies, not-found CUIs, and error information. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$success\` | \`bool\` | yes | \u2014 | Whether the lookup API call succeeded |
| \`$companies\` | \`CompanyData[]\` | no | \`[]\` | Array of found companies |
| \`$notFound\` | \`int[]\` | no | \`[]\` | Array of CUI integers that were not found in ANAF |
| \`$invalidCodes\` | \`string[]\` | no | \`[]\` | Array of VAT codes that failed validation |
| \`$error\` | \`?string\` | no | \`null\` | Error message if the lookup failed |

## Static Factory Methods

### \`success(array $companies, array $notFound = [], array $invalidCodes = []): self\`
Creates a successful result.

### \`failure(string $error, array $invalidCodes = []): self\`
Creates a failed result with an error message.

## Public Methods

### \`first(): ?CompanyData\`
Returns the first company in \`$companies\`, or \`null\` if empty.

### \`hasCompanies(): bool\`
Returns \`true\` if at least one company was found.

### \`hasNotFound(): bool\`
Returns \`true\` if any CUIs were not found.

### \`hasInvalidCodes(): bool\`
Returns \`true\` if any VAT codes were invalid.

### \`getByCui(string $cui): ?CompanyData\`
Finds a company by CUI. Handles optional \`RO\` prefix (case-insensitive) before matching. Returns \`null\` if not found or if \`$cui\` is just \`'RO'\`.

## Example

\`\`\`php
$result = $companyService->lookup(['12345678', '98765432']);

if ($result->success && $result->hasCompanies()) {
    $company = $result->getByCui('RO12345678');
    $first = $result->first();
}

if ($result->hasNotFound()) {
    // $result->notFound contains the missing CUIs
}
\`\`\`
`,
  CompanyAddressData: `# CompanyAddressData (class name: AddressData)

**Actual PHP class name:** \`AddressData\`
**Full namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\AddressData\`

Address data from an ANAF company lookup. Used for company headquarters (sediu social) and fiscal domicile (domiciliu fiscal) addresses. Extends \`Spatie\\LaravelData\\Data\`.

Note: This class shares the simple name \`AddressData\` with \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\AddressData\`. Always import with the full namespace or alias to avoid conflicts.

## Constructor Parameters

All parameters are optional (all default to \`null\`):

| Parameter | Type | Default | Description |
|---|---|---|---|
| \`$street\` | \`?string\` | \`null\` | Street name |
| \`$streetNumber\` | \`?string\` | \`null\` | Street number |
| \`$city\` | \`?string\` | \`null\` | City/locality name |
| \`$cityCode\` | \`?string\` | \`null\` | Locality code from ANAF |
| \`$county\` | \`?string\` | \`null\` | County/judet name |
| \`$countyCode\` | \`?string\` | \`null\` | County code from ANAF |
| \`$countyAutoCode\` | \`?string\` | \`null\` | County auto code (vehicle plate prefix) |
| \`$country\` | \`?string\` | \`null\` | Country name |
| \`$postalCode\` | \`?string\` | \`null\` | Postal code |
| \`$details\` | \`?string\` | \`null\` | Additional address details |

## Static Factory Methods

### \`fromHeadquartersResponse(array $data): self\`
Creates from ANAF \`adresa_sediu_social\` data structure. Maps keys prefixed with \`s\` (e.g. \`sdenumire_Strada\`, \`snumar_Strada\`).

### \`fromFiscalDomicileResponse(array $data): self\`
Creates from ANAF \`adresa_domiciliu_fiscal\` data structure. Maps keys prefixed with \`d\` (e.g. \`ddenumire_Strada\`, \`dnumar_Strada\`).

## Public Methods

### \`getFullAddress(): string\`
Returns a comma-separated string of non-empty address parts in the order: street, "nr. {streetNumber}", details, city, county, postalCode, country.

## Usage Notes

- This DTO is populated from ANAF lookup results \u2014 you will not typically construct it manually.
- All fields may be \`null\` depending on what ANAF returns for a given company.

## Example

\`\`\`php
$company = CompanyData::fromAnafResponse($data);

if ($hq = $company->headquartersAddress) {
    echo $hq->getFullAddress();
    // 'Str. Exemplu, nr. 1, Bucuresti, Sector 1, 010101, Romania'
}
\`\`\`
`,
  VatRegistrationData: `# VatRegistrationData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\VatRegistrationData\`

VAT registration data for the RTVAI (TVA la incasare / cash-based VAT) scheme from ANAF. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$isActive\` | \`bool\` | no | \`false\` | Whether RTVAI is currently active |
| \`$startDate\` | \`?Carbon\` | no | \`null\` | Start date of RTVAI registration (dataInceputTvaInc) |
| \`$endDate\` | \`?Carbon\` | no | \`null\` | End date of RTVAI registration (dataSfarsitTvaInc) |
| \`$updateDate\` | \`?Carbon\` | no | \`null\` | Last update date (dataActualizareTvaInc) |
| \`$publishDate\` | \`?Carbon\` | no | \`null\` | Publication date (dataPublicareTvaInc) |
| \`$actType\` | \`?string\` | no | \`null\` | Type of legislative act (tipActTvaInc) |

## Static Factory Methods

### \`fromAnafResponse(array $data): self\`
Creates from ANAF \`inregistrare_RTVAI\` data. Date strings are parsed with \`Carbon::parse()\`; empty/null strings produce \`null\`.

## Example

\`\`\`php
$company = CompanyData::fromAnafResponse($data);

if ($company->rtvaiDetails?->isActive) {
    // company uses cash-based VAT accounting
    $since = $company->rtvaiDetails->startDate?->format('Y-m-d');
}
\`\`\`
`,
  SplitVatData: `# SplitVatData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\SplitVatData\`

Split VAT registration data (plata defalcata TVA) from ANAF. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$isActive\` | \`bool\` | no | \`false\` | Whether Split VAT is currently active (statusSplitTVA) |
| \`$startDate\` | \`?Carbon\` | no | \`null\` | Start date of Split VAT registration (dataInceputSplitTVA) |
| \`$cancelDate\` | \`?Carbon\` | no | \`null\` | Cancellation date (dataAnulareSplitTVA) |

## Static Factory Methods

### \`fromAnafResponse(array $data): self\`
Creates from ANAF \`inregistrare_SplitTVA\` data. Date strings are parsed with \`Carbon::parse()\`; empty/null strings produce \`null\`.

## Usage Notes

- Split VAT means the buyer pays VAT directly to the tax authority rather than to the supplier. Invoices issued to Split VAT companies require separate IBAN for VAT portion.

## Example

\`\`\`php
$company = CompanyData::fromAnafResponse($data);

if ($company->splitVatDetails?->isActive) {
    // buyer pays VAT to separate treasury account
}
\`\`\`
`,
  InactiveStatusData: `# InactiveStatusData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\InactiveStatusData\`

Inactive and deregistration status data from ANAF. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$isInactive\` | \`bool\` | no | \`false\` | Whether the company is currently inactive (statusInactivi) |
| \`$inactiveDate\` | \`?Carbon\` | no | \`null\` | Date when company became inactive (dataInactivare) |
| \`$reactivationDate\` | \`?Carbon\` | no | \`null\` | Reactivation date if company was re-activated (dataReactivare) |
| \`$publishDate\` | \`?Carbon\` | no | \`null\` | Date when status was published (dataPublicare) |
| \`$deregistrationDate\` | \`?Carbon\` | no | \`null\` | Deregistration/dissolution date (dataRadiere) |

## Static Factory Methods

### \`fromAnafResponse(array $data): self\`
Creates from ANAF \`stare_inactiv\` data. Date strings are parsed with \`Carbon::parse()\`; empty/null strings produce \`null\`.

## Usage Notes

- \`$deregistrationDate\` being non-null drives \`CompanyData::$isDeregistered\`. The \`CompanyData\` builder sets \`isDeregistered = inactiveStatusDetails->deregistrationDate !== null\`.
- An inactive company can still transact but may face tax consequences. A deregistered (radiat) company should not be issued invoices.

## Example

\`\`\`php
$company = CompanyData::fromAnafResponse($data);

$details = $company->inactiveStatusDetails;
if ($details?->isInactive) {
    logger()->warning('Company is fiscally inactive since: ' . $details->inactiveDate?->format('Y-m-d'));
}
if ($company->isDeregistered) {
    throw new \\Exception('Cannot issue invoice to deregistered company');
}
\`\`\`
`
};

// src/content/sdk-docs.ts
var sdkDocsContent = {
  overview: `# Laravel e-Factura SDK \u2014 Overview

A Laravel package for integrating with Romania's ANAF e-Factura (electronic invoicing) system.

## What the Package Does

The SDK handles the full lifecycle of Romanian electronic invoicing:

- **UBL 2.1 XML Generation** \u2014 Builds CIUS-RO compliant invoice XML from PHP DTOs
- **OAuth 2.0 Authentication** \u2014 Complete OAuth flow with JWT tokens and automatic token refresh
- **Document Operations** \u2014 Upload invoices, poll status, and download processed documents
- **Company Lookup** \u2014 Query ANAF for company details (VAT status, addresses, etc.) without authentication
- **XML Validation** \u2014 Validate XML against ANAF schemas before upload
- **PDF Conversion** \u2014 Convert XML invoices to PDF format
- **Rate Limiting** \u2014 Built-in protection against exceeding ANAF API quotas

## Package Namespace

All classes live under \`BeeCoded\\EFacturaSdk\`.

## High-Level Architecture

\`\`\`
Facades (UblBuilder, EFacturaSdkAuth, AnafDetails)
    \u2193
Services (UblBuilder, AnafAuthenticator, EFacturaClient, AnafDetailsClient, RateLimiter)
    \u2193
Builders (InvoiceBuilder \u2014 UBL 2.1 XML generation)
    \u2193
Data (InvoiceData, InvoiceLineData, PartyData, AddressData, OAuthTokensData, CompanyData, ...)
    \u2193
Support (AddressSanitizer, VatNumberValidator, CnpValidator, XmlParser, DateHelper)
    \u2193
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

- **\`EFacturaClient\`** (\`BeeCoded\\EFacturaSdk\\Services\\ApiClients\\EFacturaClient\`) \u2014 Stateless client for upload, status, download, list operations. Constructed with tokens per request.
- **\`AnafAuthenticator\`** (\`BeeCoded\\EFacturaSdk\\Services\\AnafAuthenticator\`) \u2014 Stateless OAuth service; returns \`OAuthTokensData\`.
- **\`RateLimiter\`** (\`BeeCoded\\EFacturaSdk\\Services\\RateLimiter\`) \u2014 Enforces per-endpoint limits using Laravel cache with atomic increments.
- **\`InvoiceBuilder\`** (\`BeeCoded\\EFacturaSdk\\Builders\\InvoiceBuilder\`) \u2014 Low-level UBL 2.1 XML builder; used internally by \`UblBuilder\` service.

### Support Utilities

- **\`AddressSanitizer\`** (\`BeeCoded\\EFacturaSdk\\Support\\AddressSanitizer\`) \u2014 Converts Romanian county names to ISO 3166-2:RO codes; handles Bucharest sector mapping.
- **\`VatNumberValidator\`** (\`BeeCoded\\EFacturaSdk\\Support\\Validators\\VatNumberValidator\`) \u2014 Validates and normalizes Romanian CUI/CIF numbers including checksum verification.
- **\`CnpValidator\`** (\`BeeCoded\\EFacturaSdk\\Support\\Validators\\CnpValidator\`) \u2014 Validates Romanian personal identification numbers (CNP).

## Data Transfer Objects

All DTOs use \`spatie/laravel-data\`. Key DTOs:

- **\`InvoiceData\`** \u2014 Complete invoice (invoiceNumber, issueDate, supplier, customer, lines, currency, etc.)
- **\`InvoiceLineData\`** \u2014 Line item (name, quantity, unitPrice, taxAmount, taxPercent, etc.)
- **\`PartyData\`** \u2014 Supplier or customer (registrationName, companyId, address, isVatPayer)
- **\`AddressData\`** \u2014 Address (street, city, county, postalZone, countryCode)
- **\`OAuthTokensData\`** \u2014 Token set (accessToken, refreshToken, expiresAt)
- **\`CompanyData\`** \u2014 Company details from ANAF lookup (cui, name, isVatPayer, addresses, etc.)
`,
  "invoice-flow": `# Laravel e-Factura SDK \u2014 Invoice Flow (End-to-End)

## Complete Flow Overview

\`\`\`
1. Build InvoiceData DTO (with PartyData + AddressData + InvoiceLineData[])
2. Generate XML \u2192 UblBuilder::generateInvoiceXml($invoiceData)
3. Create EFacturaClient with OAuth tokens
4. Upload \u2192 $client->uploadDocument($xml)
5. Poll status \u2192 $client->getStatusMessage($uploadId)
6. Download result \u2192 $client->downloadDocument($downloadId)
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
    // Handle validation errors \u2014 see error-handling topic
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
        // Save $newTokens to database (CRITICAL \u2014 old refresh token is now invalid)
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
        // Invoice rejected \u2014 check $status->errors
        break;
    }
    // UploadStatusValue::Processing \u2014 keep polling
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
  - \`county\` required for Romanian addresses (countryCode = 'RO') \u2014 must map to valid ISO 3166-2:RO code

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
  "credit-notes": `# Laravel e-Factura SDK \u2014 Credit Notes

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

ANAF's UBL schema treats the \`CreditNote\` document type as inherently negative. The builder negates quantities when writing to XML so that the XML contains positive values \u2014 which ANAF then treats as negative credits.

| What you provide | What goes in XML | Effect in ANAF |
|-----------------|-----------------|----------------|
| quantity = -1 (return 1 item) | CreditedQuantity = +1 | ANAF credits 1 unit |
| quantity = +1 (unusual \u2014 negative credit) | CreditedQuantity = -1 | ANAF debits back |

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
- **Negative quantity** \u2192 **negative taxAmount** (standard credit note return)
- **Positive quantity** \u2192 **positive taxAmount** (unusual negative credit scenario)

\`\`\`php
// Standard: returning items (qty negative \u2192 taxAmount negative)
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
        name: 'Servicii consultanta \u2014 anulare partiala',
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

This design ensures the XML document totals are expressed as positive numbers in the CreditNote \u2014 matching ANAF's UBL validation expectations.
`,
  "tax-calculation": `# Laravel e-Factura SDK \u2014 Tax Calculation

## taxAmount is required (v2.0 Breaking Change)

Since v2.0, \`InvoiceLineData\` requires a **pre-computed \`taxAmount\`** for every line. This field is required \u2014 a breaking change from v1.x which calculated tax automatically.

**Why?** Automatic tax calculation from \`qty \xD7 unitPrice \xD7 taxRate\` causes rounding discrepancies in tax-inclusive pricing scenarios. Suppliers often quote gross prices and compute tax by subtracting the net \u2014 if the SDK recalculates tax independently, the totals won't match the supplier's books, causing ANAF validation errors.

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
sum(line_taxes) \u2260 sum(lineAmounts) \xD7 taxRate

Example:
Line 1: 1 \xD7 10.555 = 10.56 net \u2192 tax = 2.01
Line 2: 1 \xD7 10.555 = 10.56 net \u2192 tax = 2.01
Sum of line taxes: 4.02

But: (10.56 + 10.56) \xD7 0.19 = 21.12 \xD7 0.19 = 4.01 \u2190 different!
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
    name: 'Export \u2014 zero VAT',
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
  "oauth-flow": `# Laravel e-Factura SDK \u2014 OAuth Flow

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
        abort(403, 'Invalid state \u2014 possible CSRF attack');
    }

    // Exchange code for tokens
    try {
        $tokens = EFacturaSdkAuth::exchangeCodeForToken($code);
    } catch (AuthenticationException $e) {
        Log::error('Token exchange failed', ['error' => $e->getMessage()]);
        return redirect()->route('error');
    }

    // Persist tokens (encrypt them \u2014 see below)
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
// ANAF uses rotating refresh tokens \u2014 the old refresh token is immediately invalidated
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
$tokens->accessToken   // string \u2014 JWT access token
$tokens->refreshToken  // string \u2014 JWT refresh token (rotate after use!)
$tokens->expiresAt     // Carbon|null \u2014 when the access token expires
\`\`\`
`,
  "error-handling": `# Laravel e-Factura SDK \u2014 Error Handling

## Exception Hierarchy

All exceptions extend \`EFacturaException\`, which extends PHP's native \`Exception\`.

\`\`\`
EFacturaException (base)
\u251C\u2500\u2500 AuthenticationException
\u251C\u2500\u2500 ValidationException
\u251C\u2500\u2500 ApiException
\u251C\u2500\u2500 RateLimitExceededException
\u2514\u2500\u2500 XmlParsingException
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
    // Token invalid or expired \u2014 re-authenticate
    Log::error('Authentication failed', [
        'message' => $e->getMessage(),
        'code'    => $e->getCode(),
    ]);
    return redirect()->route('efactura.reauth');

} catch (RateLimitExceededException $e) {
    // Rate limit hit \u2014 tell client to retry
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
    // Refresh token is invalid or revoked \u2014 must re-authenticate via OAuth
    Log::error('Token refresh failed \u2014 re-auth required', ['cif' => $cif]);
}
\`\`\`
`,
  "address-sanitization": `# Laravel e-Factura SDK \u2014 Address Sanitization

## Why Sanitization is Required

ANAF enforces the ISO 3166-2:RO standard for Romanian county codes in the \`CountrySubentity\` UBL field (rule BR-RO-111). If you pass a plain county name like \`"Cluj"\` or \`"Judetul Maramures"\`, ANAF will reject the invoice.

The \`InvoiceBuilder\` automatically sanitizes county values via \`AddressSanitizer\`. If the county cannot be mapped to a valid ISO code, it throws a \`ValidationException\` immediately \u2014 this is intentional "fail fast" behavior to prevent submission of invalid invoices.

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
AddressSanitizer::sanitizeCounty('Maramure\u0219');         // 'RO-MM' (handles diacritics)
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

Extracts the sector number (1\u20136) from an address string.

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
| Arges / Arge\u0219 | RO-AG |
| Bacau / Bac\u0103u | RO-BC |
| Bihor | RO-BH |
| Bistrita-Nasaud / Bistri\u021Ba-N\u0103s\u0103ud | RO-BN |
| Botosani / Boto\u0219ani | RO-BT |
| Braila / Br\u0103ila | RO-BR |
| Brasov / Bra\u0219ov | RO-BV |
| Buzau / Buz\u0103u | RO-BZ |
| Calarasi / C\u0103l\u0103ra\u0219i | RO-CL |
| Caras-Severin / Cara\u0219-Severin | RO-CS |
| Cluj | RO-CJ |
| Constanta / Constan\u021Ba | RO-CT |
| Covasna | RO-CV |
| Dambovita / D\xE2mbovi\u021Ba | RO-DB |
| Dolj | RO-DJ |
| Galati / Gala\u021Bi | RO-GL |
| Giurgiu | RO-GR |
| Gorj | RO-GJ |
| Harghita | RO-HR |
| Hunedoara | RO-HD |
| Ialomita / Ialomi\u021Ba | RO-IL |
| Iasi / Ia\u0219i | RO-IS |
| Ilfov | RO-IF |
| Maramures / Maramure\u0219 | RO-MM |
| Mehedinti / Mehedin\u021Bi | RO-MH |
| Mures / Mure\u0219 | RO-MS |
| Neamt / Neam\u021B | RO-NT |
| Olt | RO-OT |
| Prahova | RO-PH |
| Salaj / S\u0103laj | RO-SJ |
| Satu Mare | RO-SM |
| Sibiu | RO-SB |
| Suceava | RO-SV |
| Teleorman | RO-TR |
| Timis / Timi\u0219 | RO-TM |
| Tulcea | RO-TL |
| Valcea / V\xE2lcea (also: Vilcea) | RO-VL |
| Vaslui | RO-VS |
| Vrancea | RO-VN |

## Bucharest Sector Handling

Bucharest sectors (1\u20136) are **not** part of ISO 3166-2:RO. They all map to county code \`RO-B\`. However, ANAF requires (BR-RO-100/101) that for Bucharest addresses, the city name must be formatted as \`SECTOR1\` through \`SECTOR6\`.

The builder handles this automatically:

\`\`\`php
// You provide:
$address = new AddressData(
    street: 'Str. Exemplu nr. 5',
    city: 'Bucuresti',       // or 'Sector 3'
    county: 'Sector 3',      // Detected as Bucharest \u2192 CountrySubentity = RO-B
    countryCode: 'RO',
);

// In the generated XML:
// <cbc:CityName>SECTOR3</cbc:CityName>        \u2190 formatted automatically
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

- \u0103 / \xE2 \u2192 a
- \xEE \u2192 i
- \u0219 / \u015F \u2192 s
- \u021B / \u0163 \u2192 t

\`\`\`php
// All of these map to the same county:
AddressSanitizer::sanitizeCounty('Timi\u0219');    // 'RO-TM'
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
  "rate-limiting": `# Laravel e-Factura SDK \u2014 Rate Limiting

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
  "company-lookup": `# Laravel e-Factura SDK \u2014 Company Lookup

## Overview

The \`AnafDetails\` facade and underlying \`AnafDetailsClient\` provide company information from ANAF's public \`PlatitorTvaRest\` API. This API **does not require OAuth authentication** \u2014 no tokens needed.

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
AnafDetails::isValidVatCode('abc');          // false \u2014 invalid format
\`\`\`

## CompanyLookupResultData Fields

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\CompanyLookupResultData\`

\`\`\`php
$result->success;        // bool \u2014 whether the lookup succeeded
$result->errorMessage;   // string|null \u2014 error description if success=false
$result->companies;      // CompanyData[] \u2014 found companies
$result->notFoundCuis;   // int[] \u2014 CUIs that were not found in ANAF
$result->invalidCodes;   // string[] \u2014 codes that failed format validation
\`\`\`

## CompanyData Fields

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\CompanyData\`

\`\`\`php
$company->cui;                   // string \u2014 numeric CUI without RO prefix
$company->name;                  // string \u2014 company name (denumire)
$company->address;               // string|null \u2014 raw address string from ANAF
$company->registrationNumber;    // string|null \u2014 trade register number (J40/1234/2020)
$company->phone;                 // string|null
$company->fax;                   // string|null
$company->postalCode;            // string|null
$company->isVatPayer;            // bool \u2014 registered for VAT (scop TVA)
$company->vatRegistrationDate;   // Carbon|null \u2014 VAT registration start date
$company->vatDeregistrationDate; // Carbon|null \u2014 VAT deregistration date
$company->isSplitVat;            // bool \u2014 uses split VAT payment
$company->splitVatStartDate;     // Carbon|null
$company->isRtvai;               // bool \u2014 TVA la incasare
$company->rtvaiStartDate;        // Carbon|null
$company->isInactive;            // bool \u2014 fiscally inactive (stare inactiva)
$company->inactiveDate;          // Carbon|null
$company->isDeregistered;        // bool \u2014 deregistered (radiat)
$company->deregistrationDate;    // Carbon|null
$company->headquartersAddress;   // AddressData|null \u2014 sediu social
$company->fiscalDomicileAddress; // AddressData|null \u2014 domiciliu fiscal

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
`
};

// src/content/api-reference.ts
var apiReferenceContent = {
  EFacturaClient: `# EFacturaClient

**Namespace:** \`BeeCoded\\EFacturaSdk\\Services\\ApiClients\\EFacturaClient\`
**Implements:** \`EFacturaClientInterface\`
**Facade:** None \u2014 instantiated directly

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

The client automatically refreshes expired tokens before making API calls. A 120-second buffer is applied \u2014 if the token expires within 120 seconds, it is refreshed proactively.

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

Resolved via the Laravel service container \u2014 typically used through the \`EFacturaSdkAuth\` facade rather than direct instantiation.

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
**Facade:** None \u2014 used internally by \`UblBuilder\` or instantiated directly

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
| \`taxPercent\` | Must be in range 0\u2013100 |

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

- No authentication required \u2014 uses the public ANAF company details API
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

Performs **format validation only** \u2014 does not make an API call. Returns \`true\` if the VAT code matches the expected format.

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
`
};

// src/index.ts
var server = new McpServer({
  name: "efactura-sdk",
  version: "1.0.0"
});
var VALID_ENUMS = [
  "InvoiceTypeCode",
  "MessageFilter",
  "ExecutionStatus",
  "DocumentStandardType",
  "StandardType",
  "TaxCategoryId",
  "UploadStatusValue"
];
server.tool(
  "get-config-reference",
  "Get the full configuration schema for the Laravel e-Factura SDK",
  {},
  async () => ({
    content: [{ type: "text", text: configReferenceContent }]
  })
);
server.tool(
  "get-enum-values",
  "Get all values for a Laravel e-Factura SDK enum",
  { name: z.enum(VALID_ENUMS).describe("Enum name") },
  async ({ name }) => {
    const content = enumValuesContent[name];
    if (!content) {
      return {
        isError: true,
        content: [
          {
            type: "text",
            text: `Unknown enum "${name}". Valid enums: ${VALID_ENUMS.join(", ")}`
          }
        ]
      };
    }
    return { content: [{ type: "text", text: content }] };
  }
);
var VALID_DTOS = [
  "InvoiceData",
  "InvoiceLineData",
  "PartyData",
  "InvoiceAddressData",
  "UploadOptionsData",
  "OAuthTokensData",
  "AuthUrlSettingsData",
  "ListMessagesParamsData",
  "PaginatedMessagesParamsData",
  "UploadResponseData",
  "StatusResponseData",
  "DownloadResponseData",
  "ValidationResultData",
  "ListMessagesResponseData",
  "PaginatedMessagesResponseData",
  "MessageDetailsData",
  "CompanyData",
  "CompanyLookupResultData",
  "CompanyAddressData",
  "VatRegistrationData",
  "SplitVatData",
  "InactiveStatusData"
];
server.tool(
  "get-dto-structure",
  "Get the complete structure of a Laravel e-Factura SDK data transfer object",
  { name: z.enum(VALID_DTOS).describe("DTO class name") },
  async ({ name }) => {
    const content = dtoStructuresContent[name];
    if (!content) {
      return {
        isError: true,
        content: [{ type: "text", text: `Unknown DTO "${name}". Valid DTOs: ${VALID_DTOS.join(", ")}` }]
      };
    }
    return { content: [{ type: "text", text: content }] };
  }
);
var VALID_TOPICS = [
  "overview",
  "invoice-flow",
  "credit-notes",
  "tax-calculation",
  "oauth-flow",
  "error-handling",
  "address-sanitization",
  "rate-limiting",
  "company-lookup"
];
server.tool(
  "get-sdk-docs",
  "Get documentation about the Laravel e-Factura SDK for a specific topic",
  { topic: z.enum(VALID_TOPICS).describe("Documentation topic") },
  async ({ topic }) => {
    const content = sdkDocsContent[topic];
    if (!content) {
      return {
        isError: true,
        content: [{ type: "text", text: `Unknown topic "${topic}". Valid topics: ${VALID_TOPICS.join(", ")}` }]
      };
    }
    return { content: [{ type: "text", text: content }] };
  }
);
var VALID_SERVICES = [
  "EFacturaClient",
  "AnafAuthenticator",
  "UblBuilder",
  "InvoiceBuilder",
  "AnafDetailsClient"
];
server.tool(
  "get-api-reference",
  "Get API method documentation for a Laravel e-Factura SDK service",
  { service: z.enum(VALID_SERVICES).describe("Service class name") },
  async ({ service }) => {
    const content = apiReferenceContent[service];
    if (!content) {
      return {
        isError: true,
        content: [{ type: "text", text: `Unknown service "${service}". Valid services: ${VALID_SERVICES.join(", ")}` }]
      };
    }
    return { content: [{ type: "text", text: content }] };
  }
);
async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("efactura-sdk MCP server running on stdio");
}
main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
