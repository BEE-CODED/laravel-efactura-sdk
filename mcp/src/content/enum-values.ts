export const enumValuesContent: Record<string, string> = {
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
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\ListMessagesParamsData;
use BeeCoded\\EFacturaSdk\\Enums\\MessageFilter;

// List invoices you have sent
$messages = $client->getMessages(new ListMessagesParamsData(
    cif: '12345678',
    days: 60,
    filter: MessageFilter::InvoiceSent,
));

// List invoices you received
$received = $client->getMessages(new ListMessagesParamsData(
    cif: '12345678',
    days: 60,
    filter: MessageFilter::InvoiceReceived,
));
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

// Validate an invoice XML ($client is an EFacturaClient instance)
$result = $client->validateXml($invoiceXml, DocumentStandardType::FACT1);

// Validate a credit note XML
$result = $client->validateXml($creditNoteXml, DocumentStandardType::FCN);

// validateXml() returns a ValidationResultData — a failed validation does NOT throw
if (! $result->valid) {
    // Inspect $result->details and $result->errors
}
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
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\UploadOptionsData;
use BeeCoded\\EFacturaSdk\\Enums\\StandardType;

// Upload a UBL invoice. The CIF is not a parameter — it comes from the
// $vatNumber the EFacturaClient was constructed with.
$response = $client->uploadDocument(
    $invoiceXml,
    new UploadOptionsData(standard: StandardType::UBL),
);

// Upload a credit note
$response = $client->uploadDocument(
    $creditNoteXml,
    new UploadOptionsData(standard: StandardType::CN),
);

// $options is optional — omitting it defaults the standard to UBL
$response = $client->uploadDocument($invoiceXml);
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

## You never pass this enum

\`TaxCategoryId\` is **derived internally** by \`InvoiceBuilder\` — it is not a constructor
parameter on \`InvoiceLineData\` or any other DTO, and there is no way to override it.
The builder picks the category per line from the **supplier's VAT-payer flag** and the
line's \`taxPercent\`:

| Condition | Resulting category |
|---|---|
| \`$invoice->supplier->isVatPayer === false\` | \`NotSubject\` (\`'O'\`) — regardless of \`taxPercent\` |
| Supplier is a VAT payer and \`taxPercent\` is ~0 (\`abs(taxPercent) < 0.01\`) | \`ZeroRated\` (\`'Z'\`) |
| Supplier is a VAT payer and \`taxPercent\` > 0 | \`Standard\` (\`'S'\`) |

The chosen value is written to \`ClassifiedTaxCategory/ID\` on each line and to the
matching \`TaxSubtotal\` group. Import the enum only if you need to *read* or compare
these values (e.g. when parsing XML you received).

## Category \`O\` suppresses the line VAT rate (v3.0.0)

When a line resolves to \`NotSubject\` (\`'O'\`), its \`cac:ClassifiedTaxCategory\` omits
\`cbc:Percent\` entirely. BR-O-05 asserts \`not(cbc:Percent)\` on such a line and fails
**fatally** otherwise — through v2 the builder always emitted it, so every invoice from a
non-VAT-payer supplier was rejected by ANAF.

The document-level \`cac:TaxSubtotal/cac:TaxCategory\` is **not** affected and still carries
\`<cbc:Percent>0.00</cbc:Percent>\` alongside \`<cbc:TaxExemptionReasonCode>VATEX-EU-O</cbc:TaxExemptionReasonCode>\`:

\`\`\`xml
<!-- invoice LINE: no Percent -->
<cac:ClassifiedTaxCategory>
  <cbc:ID>O</cbc:ID>
  <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
</cac:ClassifiedTaxCategory>

<!-- document TAX BREAKDOWN: Percent retained -->
<cac:TaxCategory>
  <cbc:ID>O</cbc:ID>
  <cbc:Percent>0.00</cbc:Percent>
  <cbc:TaxExemptionReasonCode>VATEX-EU-O</cbc:TaxExemptionReasonCode>
  <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
</cac:TaxCategory>
\`\`\`

A category-\`O\` line must also carry \`taxPercent: 0\` and \`taxAmount: 0.0\`, or the builder throws
\`ValidationException: Line N: A supplier that is not registered for VAT cannot charge VAT (BR-O-09)\`.

## Usage example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceLineData;

// Supplier is a VAT payer + taxPercent 19 → builder emits ClassifiedTaxCategory/ID = 'S'
$line = new InvoiceLineData(
    name: 'Consulting services',   // required — this is the line item name, not 'description'
    quantity: 1,
    unitPrice: 1000.00,
    taxAmount: 190.00,             // required — pre-computed: 1 * 1000.00 * 0.19
    taxPercent: 19.0,
    description: 'Optional longer description',
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
`,

  RegistrationStatus: `# RegistrationStatus

Namespace: \`BeeCoded\\EFacturaSdk\\Enums\\RegistrationStatus\`

Company registration status at the trade registry, derived from ANAF's free-text \`date_generale.stare_inregistrare\` field. ANAF does not document the full value set; observed values are \`"INREGISTRAT din data dd.mm.yyyy"\` and \`"RADIERE din data dd.mm.yyyy"\`.

## Cases

| Case | Backed value | Description |
|---|---|---|
| \`Registered\` | \`'registered'\` | Trade-registry status is "INREGISTRAT" (active registration) |
| \`Deregistered\` | \`'deregistered'\` | Trade-registry status is "RADIERE"/"RADIAT*" (struck off) |
| \`Unknown\` | \`'unknown'\` | Unrecognized, missing, or empty status — **no verdict** |

## Static Methods

### \`fromAnafStatus(?string $status): self\`
Parses a \`stare_inregistrare\` string. Prefix-matches (case-insensitive, diacritic-tolerant): \`INREGISTRAT\`/\`ÎNREGISTRAT\` → \`Registered\`, \`RADIERE\`/\`RADIAT\` → \`Deregistered\`, everything else (including \`null\`/empty) → \`Unknown\`.

## Usage Notes

- **\`Unknown\` is fail-open:** treat it as "no verdict", never as deregistered. \`Unknown\` never *causes* \`CompanyData::$isDeregistered\` to be \`true\`. Note this says nothing about the other inputs: a company whose \`registrationStatus\` is \`Unknown\` can still be \`$isDeregistered\` (via \`dataRadiere\`, see below) or \`$isInactive\`, either of which makes \`isActive()\` return \`false\`.
- \`Deregistered\` is **not** the only trigger for \`CompanyData::$isDeregistered\`. That flag is \`true\` when **either** source says so: the inactive registry supplied a \`dataRadiere\` date (surfaced as \`inactiveStatusDetails->deregistrationDate\`), **or** \`registrationStatus === Deregistered\`. Either one alone is enough, so \`$isDeregistered\` can be \`true\` while \`registrationStatus\` is \`Registered\` or \`Unknown\`. \`$deregistrationDate\` prefers the \`dataRadiere\` value and falls back to the date parsed out of the status string. The raw string is always preserved in \`CompanyData::$registrationStatusRaw\`.

## Usage example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Enums\\RegistrationStatus;

$status = RegistrationStatus::fromAnafStatus($data['date_generale']['stare_inregistrare'] ?? null);

if ($status === RegistrationStatus::Deregistered) {
    throw new \\Exception('Company is struck off (radiat)');
}
\`\`\`
`,
};
