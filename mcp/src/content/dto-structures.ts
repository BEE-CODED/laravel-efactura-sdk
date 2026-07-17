export const dtoStructuresContent: Record<string, string> = {
  InvoiceData: `# InvoiceData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceData\`

Complete invoice data for e-Factura submission. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$invoiceNumber\` | \`string\` | yes | — | Invoice number/identifier |
| \`$issueDate\` | \`CarbonInterface\|string\` | yes | — | Invoice issue date — see Date handling below |
| \`$supplier\` | \`PartyData\` | yes | — | Supplier (seller) information |
| \`$customer\` | \`PartyData\` | yes | — | Customer (buyer) information |
| \`$lines\` | \`InvoiceLineData[]\` | yes | — | Invoice line items (the \`$lines\` **property** is annotated with \`#[DataCollectionOf(InvoiceLineData::class)]\`) |
| \`$dueDate\` | \`CarbonInterface\|string\|null\` | no | \`null\` | Payment due date — see Date handling below |
| \`$currency\` | \`string\` | no | \`'RON'\` | Currency code (ISO 4217) |
| \`$paymentIban\` | \`?string\` | no | \`null\` | IBAN for payment |
| \`$invoiceTypeCode\` | \`?InvoiceTypeCode\` | no | \`null\` | Type of invoice — resolved via \`getInvoiceTypeCode()\` which defaults to \`CommercialInvoice\` |
| \`$precedingInvoiceNumber\` | \`?string\` | no | \`null\` | Preceding invoice number for credit notes (BT-25, used in BillingReference element) |
| \`$taxAmountRon\` | \`?float\` | conditional | \`null\` | Total VAT expressed in RON (BT-111). **Required when \`$currency\` is not \`'RON'\`, and rejected when it is.** See below |

## Multi-currency: \`$taxAmountRon\` (BT-111) — new and required in v3.0.0

A non-RON invoice must also declare its VAT total in RON, the tax accounting currency:
BR-RO-030 forces \`TaxCurrencyCode\` (BT-6) to \`RON\` whenever \`DocumentCurrencyCode\` (BT-5) is not,
and BR-53 then requires a \`cac:TaxTotal/cbc:TaxAmount\` at \`@currencyID='RON'\` to exist.

**ANAF cannot verify the conversion, so a wrong figure here is accepted and filed as a true
statement of VAT owed.** Before v3.0.0 the builder emitted the document-currency amount unchanged
under \`currencyID="RON"\` — a EUR invoice with 190.00 EUR of VAT filed \`190.00\` RON instead of
~945 RON. There is now no way to make that mistake: the builder throws a \`ValidationException\`
when a non-RON invoice omits \`$taxAmountRon\`.

\`\`\`php
new InvoiceData(
    // ...
    currency: 'EUR',
    taxAmountRon: 944.30,   // 190.00 EUR converted at the applicable BNR rate
);
\`\`\`

- **Supply the converted AMOUNT, not an exchange rate.** The rate is not part of the filed
  document — EN 16931 defines no business term for it, and \`UBL-CR-490\` warns against
  \`cac:TaxExchangeRate\`. Only the RON amount is transmitted. Taking a rate would also make this
  library's rounding authoritative over the figure your ledger already holds.
- **Rejected on a RON invoice.** BR-CO-15 permits exactly one \`TaxTotal\` in the document currency,
  so a second RON total cannot be emitted; passing it throws rather than being silently discarded.
- Supply it in the same positive sense as the per-line \`taxAmount\`; the builder sign-flips it for
  credit notes alongside the lines. It must agree in sign with the invoice VAT total, and is
  rounded to 2 decimals (BR-DEC-RO-15).

## Date handling (since v2.3.0)

\`$issueDate\` and \`$dueDate\` accept **any** \`Carbon\\CarbonInterface\` implementation, so apps that
call \`Date::use(CarbonImmutable::class)\` can pass Eloquent \`datetime\` casts straight in:

\`\`\`php
new InvoiceData(
    invoiceNumber: $this->number,
    issueDate: $this->issued_at,   // CarbonImmutable — accepted
    dueDate: $this->due_at,
    // ...
);
\`\`\`

An immutable date is normalised to a mutable \`Carbon\` on the way in (timezone and microseconds
preserved); a date that is already a mutable \`Carbon\` is stored as-is (same instance), and a
\`string\` is left as a \`string\`. The **stored property types are unchanged** — \`$issueDate\` is
\`Carbon|string\` and \`$dueDate\` is \`Carbon|string|null\` — and the getters below still return
concrete \`Carbon\`, so reading an invoice never gives you a \`CarbonImmutable\`.

## Public Methods

### \`getIssueDateAsCarbon(): Carbon\`
Returns the issue date as a Carbon instance. Returns a copy to prevent mutation. Throws \`\\InvalidArgumentException\` if the date string cannot be parsed.

### \`getDueDateAsCarbon(): ?Carbon\`
Returns the due date as a Carbon instance, or null if not set. Returns a copy. Throws \`\\InvalidArgumentException\` on unparseable string.

### \`getInvoiceTypeCode(): InvoiceTypeCode\`
Returns \`$invoiceTypeCode ?? InvoiceTypeCode::CommercialInvoice\`. Use this accessor rather than the raw property.

> **Fixed in v3.0.0:** these three helpers used to round differently from the XML the builder
> actually files, so a wrapper recording a helper value as the receivable could disagree with the
> legal document by a bani. They now reproduce the filed totals exactly, and are pinned against
> generated XML by tests.

### \`getTotalExcludingVat(): float\`
Sums the per-line **rounded** net amounts (BT-106), matching \`cac:LegalMonetaryTotal\` — every line
files its own \`cbc:LineExtensionAmount\` capped at 2 decimals, and the document total adds those up.
(Previously summed raw line totals and rounded once, which lost a bani per pair of sub-cent lines.)

### \`getTotalVat(): float\`
Sums the pre-computed per-line \`taxAmount\` values rounded **once per tax-rate group** (BT-110),
matching the filed \`cac:TaxSubtotal\` breakdown. The grouping is load-bearing: rounding the raw
all-lines sum understates across rate groups, and rounding per line overstates within one.

### \`getTotalIncludingVat(): float\`
Returns \`getTotalExcludingVat() + getTotalVat()\`, rounded to 2 decimal places — matching
\`cbc:TaxInclusiveAmount\` / \`cbc:PayableAmount\`.

> Sign note: all three report totals in the same positive sense the lines are supplied in. A credit
> note sign-flips every line, so the filed document states the negation of these values.

## Usage Notes

- **Credit notes:** Set \`$invoiceTypeCode = InvoiceTypeCode::CreditNote\` and provide \`$precedingInvoiceNumber\` (the original invoice number being credited). The builder uses \`precedingInvoiceNumber\` to populate the UBL \`BillingReference\` element (BT-25).
- If \`$invoiceTypeCode\` is \`null\` (omitted), the builder treats the document as a standard commercial invoice (code \`380\`).
- \`$currency\` defaults to \`'RON'\`. For EUR invoices pass \`'EUR'\` — and you must then also pass \`$taxAmountRon\` (see above), or \`buildInvoiceXml()\` throws a \`ValidationException\`.

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
| \`$name\` | \`string\` | yes | — | Product or service name |
| \`$quantity\` | \`float\` | yes | — | Quantity of items. Can be negative for credit notes/corrective invoices. Filed with **2–6 decimals** — see Precision below. |
| \`$unitPrice\` | \`float\` | yes | — | Unit price (excluding VAT). Filed with **2–6 decimals** — see Precision below. |
| \`$taxAmount\` | \`float\` | yes | — | **Pre-computed tax amount for this line** (v2.0 breaking change — now required, no default) |
| \`$id\` | \`string\|int\|null\` | no | \`null\` | Line item identifier (auto-generated by builder if null) |
| \`$description\` | \`?string\` | no | \`null\` | Additional description |
| \`$unitCode\` | \`string\` | no | \`'EA'\` | Unit of measure code (UN/ECE rec 20, e.g. \`'EA'\` = each, \`'KGM'\` = kilogram) |
| \`$taxPercent\` | \`float\` | no | \`0\` | VAT percentage (e.g. \`19\` for 19%). Must be non-negative (\`#[Min(0)]\` validation). |

## Validation

- \`$taxPercent\` has a \`#[Min(0)]\` attribute from \`Spatie\\LaravelData\\Attributes\\Validation\\Min\`. Tax percent must be zero or positive.

## Precision of \`$quantity\` and \`$unitPrice\` (changed in v3.0.0)

\`$quantity\` (BT-129/BT-130) and \`$unitPrice\` (BT-146) are filed with a **minimum of 2 and a
maximum of 6 decimals**, trailing zeros trimmed beyond the second. They are **not** monetary
amount fields — the EN 16931 \`BR-DEC-*\` rules cap decimals at 2 for amounts only, and quantities
and unit prices are explicitly allowed more precision.

Through v2 both were formatted as money at 2 decimals, which **corrupted the filed document**:

| Value passed | v2 filed | v3 filed |
|---|---|---|
| \`quantity: 1.375\` | \`1.38\` ✗ | \`1.375\` ✓ |
| \`unitPrice: 0.0075\` | \`0.01\` ✗ (overstates by 33%) | \`0.0075\` ✓ |
| \`quantity: 5.0\` | \`5.00\` | \`5.00\` (unchanged) |
| \`unitPrice: 100.0\` | \`100.00\` | \`100.00\` (unchanged) |

This bites per-unit pricing below one ban (telecom, energy, per-page/per-SMS tariffs) and
fine-grained quantities (grams billed in KGM, fractional hours). Whole and 2-decimal values render
exactly as before, so most invoices are byte-identical.

Amount fields — \`cbc:LineExtensionAmount\`, all VAT amounts, and the document totals — remain at
exactly 2 decimals. Beyond 6 decimals the value is truncated by rounding, which also keeps binary
floating-point noise out of the XML (a quantity of \`1/3\` files as \`0.333333\`, not seventeen digits).

## Public Methods

### \`getLineTotal(): float\`
Returns \`round(quantity * unitPrice, 2)\`.

### \`getTaxAmount(): float\`
Returns \`round(taxAmount, 2)\`. This is the pre-computed value passed at construction.

### \`getRawLineTotal(): float\`
Returns unrounded \`quantity * unitPrice\`. **Not** what the invoice files — \`cbc:LineExtensionAmount\`
is capped at 2 decimals, so \`getLineTotal()\` is the figure that reaches ANAF and the one the
document totals are built from. Summing this across lines and rounding once at the end does not
reproduce the filed total.

### \`getLineTotalWithTax(): float\`
Returns \`round(getLineTotal() + getTaxAmount(), 2)\`.

## Critical Notes

- **\`taxAmount\` is required** (positional parameter, no default). This is a **v2.0 breaking change** from previous versions where it was optional or auto-calculated.
- The SDK **does not compute taxAmount from taxPercent × unitPrice × quantity**. You must pre-compute it in your application.
- **Sign must follow quantity:** for credit note lines with negative quantity, taxAmount must also be negative.
- The builder sums per-line \`taxAmount\` values directly for the invoice tax total, rather than recalculating from the rate, to avoid rounding discrepancies.

## Example

\`\`\`php
use BeeCoded\\EFacturaSdk\\Data\\Invoice\\InvoiceLineData;

// Standard line: 10 units × 100 RON, 19% VAT
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
| \`$registrationName\` | \`string\` | yes | — | Legal name of the party as registered |
| \`$companyId\` | \`string\` | yes | — | CIF/CUI number without RO prefix (e.g. \`'49296198'\`). The builder adds \`RO\` prefix automatically for VAT payers. |
| \`$address\` | \`AddressData\` | yes | — | Address of the party (Invoice\\AddressData) |
| \`$isVatPayer\` | \`bool\|string\` | **yes** | — | Whether the party is registered for VAT. **No default (since v3.0.0)**. Typed as a union so a mis-positioned string is rejected rather than silently coerced — the property itself is \`bool\`. See below |
| \`$registrationNumber\` | \`?string\` | no | \`null\` | ONRC trade register identifier (e.g. \`'J40/1234/2020'\`) |

## Critical Notes

- **\`$isVatPayer\` is REQUIRED and has no default (BREAKING in v3.0.0).** It used to default to \`false\`, which meant a caller who simply forgot it filed a VAT-registered company as *not subject to VAT* — and because the resulting document is internally consistent, ANAF accepts it. There was no error to notice. Declare it explicitly on **both** the supplier and the customer. Omitting it now raises \`ArgumentCountError\` on direct construction, \`CannotCreateData\` via \`::from()\`, and a validation error via \`::validate()\`/\`::validateAndCreate()\`. An explicit \`false\` is accepted normally.
  - The parameter ORDER also changed: \`$isVatPayer\` now precedes \`$registrationNumber\`. Use named arguments.
- **\`$isVatPayer\` affects XML output:** when \`true\`, the UBL builder emits a \`PartyTaxScheme\` block whose \`CompanyID\` is \`$companyId\` prefixed with the party's VAT country prefix (derived from \`$address->countryCode\`, uppercased, defaulting to \`RO\`) — so a Romanian address yields \`RO49296198\`. Pass the raw numeric CIF (without a prefix) and let the builder handle it.
  - The prefix is skipped when \`$companyId\` already carries a recognised VAT prefix. That prefix is not always the country code: Greece files under \`EL\` (country \`GR\`) and Northern Ireland under \`XI\` (country \`GB\`), and a party may hold a VAT id issued by another state. A supplied \`EL123456789\` stays \`EL123456789\` rather than becoming \`GREL123456789\` (fixed in v3.0.0).
  - \`PartyLegalEntity/CompanyID\` is emitted for **every** party, VAT payer or not, and carries \`$companyId\` passed through \`VatNumberValidator::stripPrefix()\`. That helper strips **only** a leading \`RO\` — a \`companyId\` of \`'EL123456789'\` or \`'XI123456789'\` reaches \`PartyLegalEntity/CompanyID\` unchanged. Supplying the bare national number remains the safe input.
- **\`$isVatPayer: false\` on the supplier forces VAT category "O" on every line.** A supplier not registered for VAT cannot charge it: every line must carry \`taxPercent: 0\` and \`taxAmount: 0.0\`, or the builder throws (BR-O-09). Category "O" has two further effects, both new in v3.0.0:
  - the line's \`ClassifiedTaxCategory\` omits \`cbc:Percent\` entirely (BR-O-05). The document-level \`TaxSubtotal/TaxCategory\` **still** carries \`<cbc:Percent>0.00</cbc:Percent>\` plus \`<cbc:TaxExemptionReasonCode>VATEX-EU-O</cbc:TaxExemptionReasonCode>\` — the suppression applies to the line only;
  - the **buyer's** \`PartyTaxScheme\` (BT-48) is suppressed too, per BR-O-02 — even when the customer's own \`isVatPayer\` is \`true\`. A non-VAT supplier therefore files a document with **zero** \`PartyTaxScheme\` blocks.
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
    isVatPayer: true,                // REQUIRED — builder writes <CompanyID>RO49296198</CompanyID>
    registrationNumber: 'J40/1234/2020',
);
\`\`\`

Named arguments make the v3.0.0 reorder a non-event. **Positional construction is the dangerous
case** — the 4th argument is now \`$isVatPayer\` and \`$registrationNumber\` moved to 5th, so a v2
positional call passes the ONRC string where the bool belongs:

\`\`\`php
// v2 positional call, unchanged:
new PartyData('Acme SRL', '49296198', $address, 'J40/1234/2020', true);
\`\`\`

| Calling file | Result |
|---|---|
| \`declare(strict_types=1);\` | \`InvalidArgumentException: PartyData::$isVatPayer must be a bool, received the string "J40/1234/2020"\` |
| **no** \`strict_types\` (the Laravel app default) | The same \`InvalidArgumentException\` |

Both are loud, and that took deliberate work. \`strict_types\` is **caller-scoped**, so a plain
\`bool\` parameter is only type-checked when the *calling* file declares it — which most Laravel app
files do not. Under coercive binding \`'J40/1234/2020'\` silently became \`isVatPayer = true\`, and
\`true\` became \`registrationNumber = '1'\`: no error, and since v2 defaulted the flag to \`false\`,
a non-VAT-payer supplier silently flipped to a VAT payer. Every line moved from category O to Z, the
party gained a BT-31 seller VAT id it does not hold, and the document stayed internally consistent —
so **ANAF accepted and filed it**.

\`$isVatPayer\` is therefore typed \`bool|string\` and rejects strings explicitly. A union matches a
string *exactly*, so PHP never coerces it, and the guard turns what was a silent mis-filing into an
exception in both modes. Only \`true\`, \`false\`, \`1\`, \`0\`, \`'1'\` and \`'0'\` are accepted —
exactly Laravel's \`boolean\` rule, so \`::from()\` and \`::validateAndCreate()\` payloads are
unaffected.

\`\`\`php
// v3 — correct
new PartyData('Acme SRL', '49296198', $address, true, 'J40/1234/2020');   // ✓
// v3 — better: immune to any future reorder
new PartyData(
    registrationName: 'Acme SRL',
    companyId: '49296198',
    address: $address,
    isVatPayer: true,
    registrationNumber: 'J40/1234/2020',
);
\`\`\`

Grep for positional \`new PartyData(\` before upgrading — most app files do not declare
\`strict_types\`, so the compiler will **not** catch this for you.
`,

  InvoiceAddressData: `# InvoiceAddressData (class name: AddressData)

**Actual PHP class name:** \`AddressData\`
**Full namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Invoice\\AddressData\`

Address information for a party (supplier or customer) in an invoice. Extends \`Spatie\\LaravelData\\Data\`.

Note: This class shares the simple name \`AddressData\` with \`BeeCoded\\EFacturaSdk\\Data\\Company\\AddressData\`. Always import with the full namespace or alias to avoid conflicts.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$street\` | \`string\` | yes | — | Street address |
| \`$city\` | \`string\` | yes | — | City name |
| \`$postalZone\` | \`?string\` | no | \`null\` | Postal/ZIP code |
| \`$county\` | \`?string\` | no | \`null\` | County/region code. **Required for Romanian addresses** (ISO 3166-2:RO codes, e.g. \`'RO-B'\` for Bucharest, \`'RO-CJ'\` for Cluj). |
| \`$countryCode\` | \`string\` | no | \`'RO'\` | ISO 3166-1 alpha-2 country code |

## Critical Notes

- **\`$county\` is required for Romanian addresses.** ANAF validation rejects invoices with \`countryCode = 'RO'\` and a missing county. Use ISO 3166-2:RO sub-region codes (format \`RO-XX\`).
- \`$countryCode\` defaults to \`'RO'\`. For foreign parties, set this to their country code (e.g. \`'DE'\`, \`'FR'\`).

## ISO 3166-2:RO county codes (common examples)

- \`RO-B\` — Municipiul Bucuresti
- \`RO-CJ\` — Cluj
- \`RO-TM\` — Timis
- \`RO-IS\` — Iasi
- \`RO-BV\` — Brasov

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
| \`$selfBilled\` | \`bool\` | no | \`false\` | Self-billed invoice (autofactura) — invoice issued by buyer on behalf of supplier |
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
| \`$accessToken\` | \`string\` | yes | — | OAuth access token |
| \`$refreshToken\` | \`string\` | yes | — | OAuth refresh token |
| \`$expiresAt\` | \`?CarbonInterface\` | no | \`null\` | Absolute expiry timestamp. Accepts any Carbon date; stored as \`?Carbon\` (see Date handling below) |
| \`$expiresIn\` | \`?int\` | no | \`null\` | Token lifetime in seconds (as returned by ANAF) |
| \`$tokenType\` | \`string\` | no | \`'Bearer'\` | Token type |

## Date handling (since v2.3.0)

\`$expiresAt\` accepts **any** \`Carbon\\CarbonInterface\` implementation, so apps that call
\`Date::use(CarbonImmutable::class)\` can pass an Eloquent \`datetime\` cast straight in:

\`\`\`php
new OAuthTokensData(
    accessToken: $token->access_token,
    refreshToken: $token->refresh_token,
    expiresAt: $token->expires_at,   // CarbonImmutable — accepted
);
\`\`\`

An immutable date is normalised to a mutable \`Carbon\`; a mutable \`Carbon\` is stored as-is (same
instance). The **\`$expiresAt\` property stays \`?Carbon\`**, so reading it always gives a mutable
\`Carbon\` regardless of what was passed in.

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
    $tokens = $authenticator->refreshAccessToken($tokens->refreshToken);
}
\`\`\`
`,

  AuthUrlSettingsData: `# AuthUrlSettingsData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Auth\\AuthUrlSettingsData\`

Settings for building the OAuth authorization URL. Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$state\` | \`array<string, mixed>\|null\` | no | \`null\` | State data to encode into the authorization URL (CSRF protection / round-trip data) |
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
| \`$cif\` | \`string\` | yes | — | Company fiscal identifier (CIF/CUI without \`RO\` prefix) |
| \`$days\` | \`int\` | yes | — | Number of days to look back (1–60). Validated with \`#[Between(1, 60)]\`. |
| \`$filter\` | \`?MessageFilter\` | no | \`null\` | Filter by message type (optional — returns all types if null) |

## Validation

- \`$days\` has a \`#[Between(1, 60)]\` attribute from \`Spatie\\LaravelData\\Attributes\\Validation\\Between\`. Values outside the 1–60 range will fail validation.

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
| \`$cif\` | \`string\` | yes | — | Company fiscal identifier (CIF/CUI without \`RO\` prefix) |
| \`$startTime\` | \`int\` | yes | — | Start of date range in **milliseconds** since Unix epoch |
| \`$endTime\` | \`int\` | yes | — | End of date range in **milliseconds** since Unix epoch |
| \`$page\` | \`int\` | no | \`1\` | Page number (1-indexed). Validated with \`#[Min(1)]\`. |
| \`$filter\` | \`?MessageFilter\` | no | \`null\` | Filter by message type |

## Validation

- \`$page\` has a \`#[Min(1)]\` attribute. Zero or negative values will fail validation.

## Static Factory Methods

### \`fromDateRange(string $cif, CarbonInterface $startDate, CarbonInterface $endDate, int $page = 1, ?MessageFilter $filter = null): self\`
Convenience constructor that accepts any Carbon dates — mutable or \`CarbonImmutable\` (since v2.3.0) — and converts them to millisecond timestamps via \`->getTimestampMs()\`.

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
| \`$executionStatus\` | \`ExecutionStatus\` | yes | — | Execution status (0 = Success, 1 = Error) |
| \`$dateResponse\` | \`?string\` | no | \`null\` | ANAF response timestamp |
| \`$indexIncarcare\` | \`?string\` | no | \`null\` | Upload/load index ID — only present on success; use this as the upload ID for status polling |
| \`$errors\` | \`string[]\|null\` | no | \`null\` | Error messages — only present on error |

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
| \`$idDescarcare\` | \`?string\` | no | \`null\` | Download ID — present for both \`ok\` and \`nok\` responses; use to download the result ZIP |
| \`$errors\` | \`string[]\|null\` | no | \`null\` | Error messages |

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
- \`$idDescarcare\` is available for both \`ok\` and \`nok\` results — for \`nok\` it points to an error report ZIP.

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
| \`$content\` | \`string\` | yes | — | Binary content of the ZIP file |
| \`$contentType\` | \`string\` | yes | — | Content-Type header value (e.g. \`'application/zip'\`) |
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
| \`$valid\` | \`bool\` | yes | — | Whether the document passed validation |
| \`$details\` | \`?string\` | no | \`null\` | Validation details/messages |
| \`$info\` | \`?string\` | no | \`null\` | Additional informational text |
| \`$errors\` | \`string[]\|null\` | no | \`null\` | Array of error messages |

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
| \`$mesaje\` | \`MessageDetailsData[]\|null\` | no | \`null\` | Array of messages (annotated with \`#[DataCollectionOf(MessageDetailsData::class)]\`) |
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
| \`$mesaje\` | \`MessageDetailsData[]\|null\` | no | \`null\` | Array of messages for the current page |
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
    $response = $client->getMessagesPaginated($params);

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
| \`$id\` | \`string\` | yes | — | Download ID for this message |
| \`$cif\` | \`string\` | yes | — | CIF number associated with the message |
| \`$dataCreare\` | \`string\` | yes | — | Creation date string (mapped from \`data_creare\` via \`#[MapInputName('data_creare')]\`) |
| \`$tip\` | \`string\` | yes | — | Message type (e.g. \`'FACTURA TRIMISA'\`, \`'FACTURA PRIMITA'\`) |
| \`$detalii\` | \`string\` | yes | — | Message details/description |
| \`$idSolicitare\` | \`string\` | yes | — | Request/upload ID (mapped from \`id_solicitare\` via \`#[MapInputName('id_solicitare')]\`) |

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
| \`$cui\` | \`string\` | yes | — | Company fiscal identification code (CUI/CIF) **without** RO prefix |
| \`$name\` | \`string\` | yes | — | Company name (denumire) |
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
| \`$isDeregistered\` | \`bool\` | no | \`false\` | Whether the company has been deregistered/struck off (radiat) |
| \`$deregistrationDate\` | \`?Carbon\` | no | \`null\` | Deregistration date |
| \`$registrationStatusRaw\` | \`?string\` | no | \`null\` | Raw trade-registry status string (\`date_generale.stare_inregistrare\`), e.g. \`'RADIERE din data 29.03.2024'\` |
| \`$registrationStatus\` | \`RegistrationStatus\` | no | \`Unknown\` | Parsed trade-registry status enum (Registered\\|Deregistered\\|Unknown) |
| \`$registrationStatusDate\` | \`?Carbon\` | no | \`null\` | Date parsed from the \`stare_inregistrare\` "din data dd.mm.yyyy" suffix |
| \`$registrationDate\` | \`?Carbon\` | no | \`null\` | Fiscal registration date (\`date_generale.data_inregistrare\`) |
| \`$isRegisteredInEFactura\` | \`bool\` | no | \`false\` | Whether enrolled in the RO e-Factura registry (\`date_generale.statusRO_e_Factura\`) |
| \`$eFacturaRegistrationDate\` | \`?Carbon\` | no | \`null\` | RO e-Factura registry enrollment date (\`data_inreg_Reg_RO_e_Factura\`) |
| \`$vatPeriods\` | \`VatPeriodData[]\` | no | \`[]\` | VAT registration periods (\`inregistrare_scop_Tva.perioade_TVA\`), in response order |
| \`$headquartersAddress\` | \`?AddressData\` | no | \`null\` | Headquarters address (Company\\AddressData) |
| \`$fiscalDomicileAddress\` | \`?AddressData\` | no | \`null\` | Fiscal domicile address (Company\\AddressData) |
| \`$rtvaiDetails\` | \`?VatRegistrationData\` | no | \`null\` | Detailed RTVAI registration data |
| \`$splitVatDetails\` | \`?SplitVatData\` | no | \`null\` | Detailed Split VAT registration data |
| \`$inactiveStatusDetails\` | \`?InactiveStatusData\` | no | \`null\` | Detailed inactive/deregistered status data |

## Static Factory Methods

### \`fromAnafResponse(array $data): self\`
Parses the full ANAF found company response structure. Processes nested keys: \`date_generale\`, \`inregistrare_scop_Tva\`, \`inregistrare_RTVAI\`, \`stare_inactiv\`, \`inregistrare_SplitTVA\`, \`adresa_sediu_social\`, \`adresa_domiciliu_fiscal\`.

**Deregistration derivation:** \`isDeregistered\` is \`true\` when \`stare_inactiv.dataRadiere\` parses to a valid date **OR** \`stare_inregistrare\` parses to \`RegistrationStatus::Deregistered\` (a "RADIERE ..." string). \`deregistrationDate\` prefers the \`stare_inactiv.dataRadiere\` value and falls back to the date parsed from the \`stare_inregistrare\` string. \`isActive()\` returns \`false\` for any deregistered company.

**VAT date derivation:** \`vatRegistrationDate\`/\`vatDeregistrationDate\` come from the legacy flat \`data_inceput_ScpTVA\`/\`data_sfarsit_ScpTVA\` keys when present, otherwise from the latest \`perioade_TVA\` entry (the one with the most recent \`startDate\`).

## Public Methods

### \`getVatNumber(): string\`
Returns \`'RO' . $this->cui\`.

### \`isActive(): bool\`
Returns \`true\` if the company is neither inactive nor deregistered.

### \`isRegistered(): bool\`
Returns \`true\` if \`registrationStatus === RegistrationStatus::Registered\` (a confirmed "INREGISTRAT" trade-registry status). \`Unknown\` returns \`false\` — inspect \`registrationStatusRaw\` for details.

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
| \`$success\` | \`bool\` | yes | — | Whether the lookup API call succeeded |
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
use BeeCoded\\EFacturaSdk\\Facades\\AnafDetails;

$result = AnafDetails::batchGetCompanyData(['12345678', '98765432']);

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

- This DTO is populated from ANAF lookup results — you will not typically construct it manually.
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

- \`stare_inactiv.dataRadiere\` is **only one of two** sources for \`CompanyData::$isDeregistered\`. The \`CompanyData\` builder sets \`isDeregistered = (inactiveStatusDetails->deregistrationDate !== null) || (registrationStatus === RegistrationStatus::Deregistered)\` — so a company struck off via the trade registry (\`stare_inregistrare\` = "RADIERE ...") is flagged deregistered even when this \`stare_inactiv\` block is empty.
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
`,

  VatPeriodData: `# VatPeriodData

**Namespace:** \`BeeCoded\\EFacturaSdk\\Data\\Company\\VatPeriodData\`

A single VAT registration period from ANAF's \`inregistrare_scop_Tva.perioade_TVA\` array (v9 response shape). Extends \`Spatie\\LaravelData\\Data\`.

## Constructor Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| \`$startDate\` | \`?Carbon\` | no | \`null\` | Period start date (data_inceput_ScpTVA) |
| \`$endDate\` | \`?Carbon\` | no | \`null\` | Period end date (data_sfarsit_ScpTVA); \`null\` for an open period |
| \`$cancellationDate\` | \`?Carbon\` | no | \`null\` | VAT registration cancellation date (data_anul_imp_ScpTVA) |
| \`$message\` | \`?string\` | no | \`null\` | ANAF's explanatory message for the period (mesaj_ScpTVA) |

## Static Factory Methods

### \`fromAnafResponse(array $data): self\`
Creates from a single \`perioade_TVA\` entry. Date strings are parsed with \`Carbon::parse()\`; empty/whitespace/invalid strings produce \`null\`. An empty/whitespace \`message\` becomes \`null\`.

## Usage Notes

- \`CompanyData\` collects every period into \`$vatPeriods\` (response order) and derives \`vatRegistrationDate\`/\`vatDeregistrationDate\` from the period with the most recent \`startDate\` — unless the legacy flat keys are present, which take precedence.

## Example

\`\`\`php
$company = CompanyData::fromAnafResponse($data);

foreach ($company->vatPeriods as $period) {
    echo $period->startDate?->format('Y-m-d') . ' → ' . ($period->endDate?->format('Y-m-d') ?? 'open');
}
\`\`\`
`,
};
