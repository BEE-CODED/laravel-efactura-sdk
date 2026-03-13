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
`,
};
