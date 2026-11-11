# Changelog

This changelog starts with the changes since v3.1.1; earlier releases are described in the README
and the git history.

## [4.0.0] - Unreleased

### Breaking (4.0): summary

Every change below can break an application or a subclass written for 3.x. Each is explained in
the section named after it.

- Platform: the `dom` and `zip` PHP extensions are now required (`ext-dom`, `ext-zip`; "Added:
  response archive verification").
- Platform: Laravel 11 is no longer supported (`illuminate/*` is `^12.0|^13.0`; Laravel 12 needs
  12.27.1 or later), and the new `brick/math` constraint (0.14.4 up to 0.19) excludes 0.20 and
  1.x, so an application already on those is refused or quietly kept on 3.x ("Compatibility").
- Subclasses that override `EFacturaClient::getStatusMessage()`, `RateLimiter::checkStatusQuery()`
  or `RateLimiter::getRemainingQuota()` must add the `$useProviderLimit` parameter, or PHP raises a
  fatal signature error ("Compatibility").
- `RateLimitStore::clear()` was removed ("Removed").
- A test double of `RateLimiter` must allow `ensureAvailable()` ("Fixed: a refused call spends no
  quota").
- Amounts: the amount properties of `InvoiceLineData` and `InvoiceData` are typed `float|string`
  and are refused with `ValidationException`, never rounded, when they carry more decimals than
  they are filed with (6 for `quantity`, `unitPrice` and `taxAmount`; 2 for `taxPercent`,
  `taxAmountRon` and `payableRoundingAmount`), are not finite, or are floats too large to hold
  that precision. Large accumulated float sums may therefore be refused: pass decimal strings.
  `payableRoundingAmount` is no longer normalised or checked at construction; some amounts move by
  one cent ("Breaking (4.0): amounts are exact or refused", "Changed: money is exact decimal",
  "Compatibility").
- `InvoiceLineData::getRawLineTotal()` was removed ("Removed").
- `UblBuilderInterface` has a new method, `validateInvoice(InvoiceData): void`; a class that
  implements the interface must add it ("Added: validate without generating").
- The builder refuses documents ANAF rejects or would file wrongly: a positive invoice without a due
  date (BR-CO-25), a Bucharest address without a sector, a non-zero `taxAmountRon` without
  standard-rated lines, a non-zero VAT amount in a Z/O/E/G/K breakdown, malformed currency or
  country codes, text XML cannot carry, and required fields that are blank after trimming
  ("Breaking (4.0): the builder refuses documents ANAF rejects").
- `TaxCategoryId` has three new cases (`Exempt`, `Export`, `IntraCommunitySupply`), so an exhaustive
  `match` over it needs new arms ("Added: VAT categories E, G and K").
- `InvoiceData::$precedingInvoiceNumber` is deprecated in favour of `$precedingInvoices`; setting
  both to different references is refused ("Added: preceding invoices, notes, VAT point date code
  and delivery").
- Translation keys renamed, for applications that publish or override them:
  `reverse_charge_supplier_must_be_vat_payer` → `explicit_category_supplier_must_be_vat_payer`,
  `reverse_charge_tax_must_be_zero` → `explicit_category_tax_must_be_zero`,
  `reverse_charge_vat_identifier_required` → `category_vat_identifier_required`,
  `reverse_charge_tax_amount_ron_must_be_zero` → `tax_amount_ron_must_be_zero`, and
  `payable_rounding_amount_invalid` → `amount_invalid` (it names the field).
- `UploadResponseData::$executionStatus` can be `null`, and `! isSuccessful()` no longer means
  "failed" ("an unrecognised upload status is `null`").
- ANAF values are normalised: ids become strings, `MessageDetailsData::$id` can be `null`, counts
  are no longer cast to `0`, and an unparseable 2xx body throws `ApiException`, including from the
  message list calls ("ANAF values are normalised before they reach a DTO").
- An XML status answer with only `Errors` has `stare` `null` and `hasAnafError()`, not `nok`
  ("an XML status answer with only `Errors` has no `stare`").
- `convertXmlToPdf()` throws `ApiException` for a 2xx body that is not a PDF ("`convertXmlToPdf()`
  returns only a PDF").
- `getMessages()` and `getMessagesPaginated()` throw `ValidationException` for a blank CIF, where
  the rate limiter used to throw `InvalidArgumentException` ("message lists refuse a blank CIF").
- `BaseApiClient::request()` sends a body only through `callRaw()` with a content type: the
  multipart, form and JSON-array POST branches were removed, and a non-GET call with array data now
  throws `LogicException` ("Removed: unused `request()` body branches"). Affects subclasses only.
- A company lookup that ANAF answers with HTTP 429 now throws `RateLimitExceededException`
  (`fromProvider: true`) instead of returning `CompanyLookupResultData::failure()` ("Changed: ANAF's
  429 on a company lookup is a rate limit").
- The company-lookup limit is counted over a 2-second window: `retryAfterSeconds` can be 2, and on
  Redis lookups are at most one every 2 s ("Fixed: company lookups at least a second apart").
- `AuthenticationException` for missing OAuth configuration has code `0` (was `401`), and the
  latched "Token refresh previously failed" exception carries the original failure's code instead of
  always `401` ("Fixed: configuration errors are not refused grants").
- An upload that fails with a connect-phase timeout is now retried like DNS/connect/TLS failures, and
  every provably unsent failure arrives as `RequestNotSentException` (still an `ApiException` with
  `statusCode` 500) ("Added: `RequestNotSentException`").
- A failing cache store no longer reaches the caller as its own exception before a request is
  sent: in the SDK's rate-limit metering it is `RequestNotSentException`, and behind the
  token-refresh lock it is `AuthenticationException` with code 0, each with the store's exception as
  `previous`. A `catch` for the raw store exception (a `RedisException`, say) no longer sees it
  ("Added: `RequestNotSentException`").
- `batchGetCompanyData()` sends each CUI once: a CUI given twice (with or without `RO`) is asked for,
  and reported, once ("Added: strict company lookups").
- `verifySignature(string $xml, string $signature)` takes the invoice and its signature file and
  posts both to ANAF's documented signature service; `EFacturaClientInterface` changed with it
  ("Breaking (4.0): `verifySignature()` follows ANAF's signature service").

### Breaking (4.0): amounts are exact or refused

- `InvoiceLineData::$quantity`, `$unitPrice`, `$taxAmount` and `$taxPercent`, and
  `InvoiceData::$taxAmountRon` and `$payableRoundingAmount`, are typed `float|string` (nullable on
  `InvoiceData`). The value is kept as given, also in `toArray()`/`toJson()`, and read as an exact
  decimal wherever it is used, through the new `BeeCoded\EFacturaSdk\Support\Amount::parse(mixed
  $value, int $scale, string $field): BigDecimal`. `quantity`, `unitPrice` and `taxAmount` are read
  at 6 decimals; `taxPercent`, `taxAmountRon` and `payableRoundingAmount` at 2.
- A string or `BigDecimal` with more decimals than that is refused (trailing zeros are fine). A
  float is accepted when it is within binary noise of its rounded rendering —
  `|f − round(f)| ≤ min(10^-scale / 10, max(1e-9, |f| · 2 · PHP_FLOAT_EPSILON))` — and below
  2^46 at 2 decimals or 2^33 at 6, where a double still separates adjacent steps. `INF`, `NAN`,
  non-numeric strings and other types are refused. Each refusal is a `ValidationException` naming
  the field (`lines.0.quantity` from the builder, `quantity` from a line helper).
- Before, inputs were rounded to 6 decimals (`1/3` filed as `0.333333`), a rate was rounded to 2
  when grouping (`19.001` joined the 19% group), `taxAmountRon` was rounded to 2 (`944.3049` filed
  `944.30`), and floats above about 2^32 were read at whatever the double held. All of these now
  throw. Large accumulated float sums may therefore be refused: pass decimal strings, which are
  filed verbatim.
- `payableRoundingAmount` is no longer normalised in the constructor or by `toArray()`:
  `toArray()` returns the caller's value (`0.1 + 0.2` stays `0.30000000000000004`), and the check
  happens where the value is read (`getPayableRoundingAmount()`, `getTotalIncludingVat()`,
  `toArray()`, the builder), so `new InvoiceData(...)` and `::from()` no longer throw for it.
- Explicit validation rules replace the inferred ones: `taxPercent` `sometimes|numeric|min:0`;
  `quantity`, `unitPrice`, `taxAmount` `required|numeric`; `taxAmountRon`,
  `payableRoundingAmount` `nullable|numeric`. A property with a default is still skipped when
  absent from the payload. `::validateAndCreate()` accepts ints, floats and numeric strings; the
  precision is checked when the amount is used.
- `InvoiceLineData::getTaxGroupKey()` reads the rate at 2 decimals (`S:19`, `Z:0`).
- Upgrade: pass amounts as decimal strings where you hold them exactly, or round them in your
  ledger before passing floats. Code that reads these properties must accept a string.

### Breaking (4.0): the builder refuses documents ANAF rejects

Each of these used to produce XML that ANAF rejected, or filed a wrong figure. They now throw
`ValidationException` (also through `validateInvoice()`):

- An Invoice (380, 384, 389, 751) with a positive amount due and no `dueDate` (BR-CO-25, fatal
  in ANAF's ro16931-ubl 1.0.9 bundle). Credit notes are not checked, as ANAF does not check them.
- A Romanian address whose county is Bucharest but whose sector cannot be found in
  `bucharestSector`, the city or the county (BR-RO-100/101). The city used to be filed as given,
  which ANAF rejects.
- A non-zero `taxAmountRon` on a document without standard-rated lines (Z, O, E, G, K or AE only).
  Only AE documents were checked before; a Z-only EUR invoice filed any RON VAT figure.
- A VAT breakdown in category Z, O, E, G or K whose summed line tax rounds to a non-zero amount,
  for example three Z lines of `0.004` (BR-Z-09, BR-O-09 and siblings). Each line still tolerates
  a residue that files as `0.00`.
- A currency that is not three uppercase letters, or a country code that is not two, including an
  empty one (BR-CL-04, BR-CL-14). An empty currency used to fall back to RON and an empty country
  to RO; lowercase codes were filed as given.
- Text that is not valid UTF-8 or contains a character outside XML 1.0 (control characters other
  than tab, line feed and carriage return; U+FFFE, U+FFFF), in any string of the input. XMLWriter
  silently truncated text at a NUL, replaced other controls with U+FFFD, or wrote a document that
  was not well-formed. The message names the field (`lines.0.name`, `supplier.address.street`).
- A required field that is only whitespace: invoice number, issue date, registration names,
  company ids, street, city, line names and the county of a Romanian address. (`'0'` as an
  invoice number, which `empty()` refused, is now accepted.)

### Fixed: foreign addresses are filed as given

- The Bucharest rewriting (county `RO-B`, city `SECTORn`) applied to any country, so a Barcelona
  county `B`, a Berlin `B` or an Italian `Sector 3` became `RO-B`, and the foreign city became
  `SECTOR3`. ANAF accepts that, so it filed a wrong address. Only `countryCode: 'RO'` addresses are
  rewritten now, for parties and deliveries.

### Added: VAT categories E, G and K, and exemption reasons

- `TaxCategoryId::Exempt` (`E`), `Export` (`G`) and `IntraCommunitySupply` (`K`). Like AE they are
  opt-in through `InvoiceLineData::$taxCategoryId` and never inferred (a 0% line without a category
  is still `Z`). They need a VAT-registered supplier and an exactly zero rate and tax, and file
  `Percent` `0.00`.
- `InvoiceData::$exemptionReasons`: `list<ExemptionReasonData{TaxCategoryId $category, ?string
  $code, ?string $text}>`, one per category, for E, G, K and AE only. G, K and AE file their fixed
  codes (`VATEX-EU-G`, `VATEX-EU-IC`, `VATEX-EU-AE`) and take an optional text; AE's text defaults to
  `Taxare inversa`. E needs a VATEX code (BR-CL-22) and/or a text (BR-E-10). Texts are at most 100
  characters (BR-RO-L100). O keeps its fixed `VATEX-EU-O`. A reason for S, Z or O, a duplicate, a
  reason for a category without lines, or a different code for G/K/AE is refused.
- K needs VAT identifiers for both parties (BR-IC-02) and a `delivery` with an actual delivery date
  and a delivery address (stricter than ANAF's BR-IC-11/12, deliberately). E and G need the
  supplier's VAT identifier.

### Added: preceding invoices, notes, VAT point date code and delivery

- `InvoiceData::$precedingInvoices`: `list<PrecedingInvoiceData{string $number, Carbon|string|null
  $issueDate}>` (BG-3), at most 500 (BR-RO-A500), each with a non-blank number (BR-55) of at most
  200 characters. Each is filed as a `BillingReference`, with `IssueDate` (BT-26) when given.
  `$precedingInvoiceNumber` is deprecated and still honoured: alone it is one reference; together
  with `$precedingInvoices` it must name its only entry. `InvoiceData::getPrecedingInvoices()`
  returns the resolved list.
- `InvoiceData::$notes` (`list<string>`, BT-22, at most 20 × 300 characters), filed after the type
  code; `$vatPointDateCode` (BT-8: `3`, `35` or `432`, never with `taxPointDate`, BR-CO-03), filed
  as `InvoicePeriod/DescriptionCode`; `$delivery` (`DeliveryData{Carbon|string|null
  $actualDeliveryDate, ?AddressData $address}`, BG-13), filed before `PaymentMeans` as
  `ActualDeliveryDate` and `DeliveryLocation/Address`. A delivery address needs a street, a city and
  a county or region in every country; a Romanian one is filed like a party address.
- A blank or unparseable `PrecedingInvoiceData::$issueDate` or `DeliveryData::$actualDeliveryDate`
  is refused with `ValidationException` (new keys `preceding_invoice_issue_date_invalid` and
  `actual_delivery_date_invalid`), also when `InvoiceBuilder` is called directly, including a
  blank delivery date on a VAT category K document. The DTOs' own `get...AsCarbon()` helpers still
  throw `InvalidArgumentException`.
- All are appended to the constructor. Empty or `null` values are left out of `toArray()`/`toJson()`
  and of the XML, so existing payloads and XML are unchanged.

### Added: seller registration number as BT-33

- `PartyData::$registrationNumber` of the supplier, for example the trade register number, is filed
  as `PartyLegalEntity/CompanyLegalForm` (BT-33, at most 1000 characters, BR-RO-L1000). A blank one
  is omitted. The buyer's is still not filed; EN 16931 has no such term for the buyer.

### Added: validate without generating

- `UblBuilderInterface::validateInvoice(InvoiceData $invoiceData): void`, also on the `UblBuilder`
  facade, builds the XML and discards it, so its checks are exactly those of
  `generateInvoiceXml()`.
- `VatNumberValidator::withCountryPrefix(string $id, ?string $country): string` returns the VAT
  identifier exactly as the builder files BT-31/BT-48 (it moved out of the builder).
- `AddressData::$bucharestSector` (`?int`, 1–6, only on a Bucharest RO address; omitted from
  `toArray()` when `null`) and `AddressSanitizer::canonicalize(AddressData): array{county: ?string,
  city: string}`, which returns the county and city exactly as the builder files them.

### Added: `UploadOutcome`, one reading of an upload attempt

- `BeeCoded\EFacturaSdk\Support\UploadOutcome` classifies what one `uploadDocument()` /
  `uploadB2CDocument()` call means for the document: `kind` (`UploadOutcomeKind`), `reason`
  (`UploadOutcomeReason`), `index`, `retryAfterSeconds` and `providerText` (ANAF's own words,
  untranslated).
- `UploadOutcome::fromResponse($response)`: an index of 1–64 digits → `Accepted` (whatever the
  status says); an explicit `ExecutionStatus` 1 without an index → `Refused` /
  `FailedWithoutIndex`, `providerText` = ANAF's first error; anything else → `Uncertain`.
- `UploadOutcome::fromThrowable($e)`, for throwables raised by the upload call itself:
  `RequestNotSentException` → `NotSent` / `Transport`; the SDK's `RateLimitExceededException` →
  `NotSent` / `RateLimited` with its `retryAfterSeconds`; `ApiException` 429 → `NotSent` /
  `RateLimited` (no wait known); any other `ApiException` 4xx except 408 → `Refused` / `Rejected`
  with ANAF's message as `providerText` (none when ANAF sent none); `AuthenticationException` →
  `NotSent`, `GrantRefused` for codes 400/401 and `Transient` for every other code; the SDK's
  `ValidationException` (including every builder refusal above) → `Refused` / `LocalRefusal`;
  `ApiException` 408, 5xx, an unreadable 2xx and anything unknown → `Uncertain`.
- Classify your own earlier stages (building the XML, storing it, picking the company) yourself:
  `fromThrowable()` cannot tell them from the upload.
- A `tokenPersister` exception is your own stage too. It reaches you unchanged and `fromThrowable()`
  reads it as `Uncertain`, but it is thrown before the upload request is made, so the document was
  not sent. The refresh token has already rotated: the new pair lives only in the client
  (`getTokens()`, `wasTokenRefreshed()` is true) and the one in your store is spent. Save
  `getTokens()` and treat the document as not sent; if you cannot store them, the company has to be
  authorised again (the next refresh is refused with 400/401). Neither a silent resend nor
  reconciling a "possibly filed" upload fixes that, so the SDK does not reclassify the exception.
- 408 is `Uncertain`: a timeout does not prove ANAF did not file the document.

### Added: response archive verification

- `BeeCoded\EFacturaSdk\Support\ArchiveInspector::verify(string $archive, ?string $uploadedXml = null): void`
  checks an archive from `downloadDocument()`. Limits, always checked first, before any member is
  read: at most 20 MiB, a readable ZIP of 1 to 32 unencrypted members, each expanding to at most
  10 MiB and all together to at most 20 MiB. With the uploaded XML it also reads each member (capped
  at 10 MiB) and requires exactly one Invoice/CreditNote equal to the upload and otherwise only
  xmldsig `Signature` documents; without it (limits only, for example a rejection archive) no member
  is read. `new ArchiveInspector($directory)` writes the temporary ZIP file there instead of
  `sys_get_temp_dir()`.
- `ArchiveLimitException` (`public readonly ArchiveLimitReason $reason`: `ArchiveTooLarge`,
  `MemberTooLarge`, `Encrypted`, `TooManyMembers`, `Empty`, `Unopenable`, `Io`) says nothing about
  the content: download again later. `Io` is a local failure (temporary file, ZipArchive
  `ER_OPEN`/`ER_READ`/`ER_TMPOPEN`/`ER_MEMORY`); `Unopenable` is any other ZIP open failure,
  including an empty body. With the uploaded XML, a member holding more than
  `UblDocumentComparator::MAX_MARKUP` (1 000 000) `<` is `MemberTooLarge` before it is parsed.
- `ArchiveMismatchException` is final: an uploaded XML over `MAX_BYTES` or `MAX_MARKUP` (it can
  never be compared, so it is refused before the archive is looked at), a DOCTYPE or entity
  declaration, an empty member, a member that is not XML or not its declared size, a comment or
  processing instruction inside the document, an unrelated member, a second copy, no copy, or any
  content difference. Both extend `ArchiveException extends EFacturaException`.
- One byte cap for a document: `UblDocumentComparator::MAX_BYTES` (10 MiB, 10 485 760 bytes) is
  both the archive's per-member cap and the comparator's own, so a member the limits admit is never
  a mismatch for its size. A document compared directly above it is `ArchiveMismatchException`.
- `UblDocumentComparator::assertSameDocument(string $candidate, string $expected): void` compares
  two UBL documents as element trees: the XML declaration, namespace prefixes, whitespace-only text,
  anything outside the root and trailing decimal zeroes in the UBL amount, quantity and percent
  elements the builder writes may differ, and CDATA counts as text. Anything else throws
  `ArchiveMismatchException`.
- `composer.json` now requires `ext-dom` and `ext-zip`.

### Added: status verdicts and poll cadence

- `StatusResponseData::verdict(): StatusVerdict` reads a status answer as one of `InProgress`,
  `Ready`, `Rejected`, `ReadyWithoutDownloadId`, `RejectedWithoutDownloadId`, `NotProcessed`,
  `AnafError` (`hasAnafError()`) or `Unknown` (no status ANAF defines and no error). A download id
  that is not 1 to 64 digits counts as none, so `ok`/`nok` with it is `...WithoutDownloadId`: no
  verdict on the document.
- `PollSchedule::status(int $ageSeconds): int` is the wait before the next status read by the age
  of the upload: 60 s under 10 minutes, 300 s under an hour, 900 s under three hours, then 3600 s:
  49 reads in the first day, one under the SDK's default `status_per_day_message` of 50, so a retry
  or a manual check that day can meet the SDK's limit (reschedule on `RateLimitExceededException`).
  `PollSchedule::archive(int $attempt, int $first = 300, int $max = 86400): int` is the wait after
  the `$attempt`-th failed archive download, doubling from `$first` up to `$max`. Neither adds
  slack for a scheduler's tick.

### Added: `RequestNotSentException`

- `BeeCoded\EFacturaSdk\Exceptions\RequestNotSentException extends ApiException` (`statusCode`
  500) is thrown, once the retries are spent, for a transport failure that provably happened before
  the request reached ANAF: cURL errno 5, 6, 7 or 35, or errno 28 when the handler context shows the
  connect phase never finished (`pretransfer_time` 0 and `request_size` 0), and only when no
  earlier attempt of the call may have reached ANAF. Every other transport failure stays a plain
  `ApiException(500)`: the request may have been delivered. (An upload is never retried after an
  attempt that may have been delivered, so this matters only for reads.)
- A connect-phase timeout is now retried for uploads too, like the other pre-send errors. In an
  outage an upload can take up to `retry_times` × (connect timeout + `retry_delay`) before it throws.
- Existing `catch (ApiException $e)` blocks keep working; catch `RequestNotSentException` first to
  resend safely.
- A failure of the SDK's own rate-limit metering (an exception from the cache store behind the
  limiter, for example Redis refusing the connection) is also `RequestNotSentException`, with the
  store's exception as `previous`: metering runs before the request or retry it counts, so nothing
  was sent, and `UploadOutcome::fromThrowable()` reads it as `NotSent` / `Transport`. Before, the raw
  store exception reached the caller and read as `Uncertain`. `RateLimitExceededException` and
  programming errors (`LogicException`) still reach you as themselves. When a read's retry fails
  this way after an earlier attempt reached ANAF (a 5xx, or a transport failure that may have been
  delivered), the call was sent, so the store's exception reaches you as itself.
- A failure of the cache store behind the token-refresh lock (for example Redis refusing the
  connection while the access token needs a refresh) is `AuthenticationException` with code 0 and
  the store's exception as `previous`, like a lock timeout: nothing was sent, and
  `UploadOutcome::fromThrowable()` reads it as `NotSent` / `Transient`. Before, the raw store
  exception reached the caller and read as `Uncertain`.

### Added: strict company lookups

- `new AnafDetailsClient(strict: true, tries: 1, timeoutSeconds: 5)`. `tries` and `timeoutSeconds`
  override `http.retry_times` and `http.timeout` for that instance (the timeout is the instance's,
  used by `request()`).
- In strict mode a lookup succeeds only when ANAF's answer confirms the request: `found` and
  `notFound` are both lists (the legacy lowercase `notfound` is refused), every requested CUI is
  answered exactly once as an integer, each found entry echoes the request date, has a non-empty
  `denumire`, a known `stare_inregistrare` (INREGISTRAT, RADIERE, RADIAT, TRANSFER(SOSIRE|PLECARE),
  optionally "din data" + a valid date), a boolean `statusInactivi`, a `dataRadiere` key and valid
  dates. A 404 counts only as not-found evidence. Anything else is a failure, never a found company.
  Strict checks work for batches.
- `CompanyLookupResultData::$failureKind` (`?CompanyLookupFailureKind`) says why a sent lookup
  failed: `Transport` (ANAF error status, transport failure, unexpected exception) or
  `InvalidResponse` (unreadable answer, unexpected structure, a strict check). It is `null` on
  success and on input the SDK refused before sending (no codes, only invalid codes, more than 100,
  an unencodable payload). `failure()` takes it as an optional third argument.
- Requested CUIs are de-duplicated after the `RO` prefix is removed (in every mode).

### Changed: ANAF's 429 on a company lookup is a rate limit

- An HTTP 429 from the company-lookup service throws `RateLimitExceededException` with scope
  `RateLimitScope::CompanyLookup`, `retryAfterSeconds` 2 and the new `fromProvider: true`, instead of
  a `failure()` result. ANAF sends no usable `Retry-After`, so the 2 s is the SDK's estimate.
- `RateLimitExceededException::$fromProvider` (new last constructor parameter, default `false`) is
  true when ANAF refused the call rather than the SDK's own limiter; the request reached ANAF. Code
  that retries local limiter refusals should not retry provider ones blindly.

### Changed: validate, verify and PDF use the shared transport

- `validateXml()`, `verifySignature()` (as multipart, see below) and `convertXmlToPdf()` go through
  `callRaw()`; `BaseApiClient::getRequestUrl()` keeps an absolute `http(s)://` route as the URL.
  Retries and the 401 gate are unchanged. With `logging.debug` the posted XML and the response body (a PDF for
  `convertXmlToPdf()`) are now logged, like uploads and downloads, and their failures can be
  `RequestNotSentException`.

### Removed: unused `request()` body branches

- `BaseApiClient::request()` keeps its six parameters but no longer sends multipart, form-encoded or
  JSON-array POST bodies; nothing in the SDK used them. A non-GET request with array data throws
  `LogicException`; send a body through `callRaw()` with an explicit content type. GET with query
  data is unchanged. Private helpers lost unused parameters (`authenticatedRequest()`'s `$data`,
  `authenticatedXmlRequest()`'s `$expectBinary`) and the duplicate `requestToFullUrl()` was deleted.

### Breaking (4.0): `verifySignature()` follows ANAF's signature service

- `EFacturaClient::verifySignature(string $xml, string $signature): ValidationResultData` (and
  `EFacturaClientInterface`) takes the invoice XML and the XML holding its signature, both from the
  archive `downloadDocument()` returns. ANAF's documentation (section 7, "Validarea semnăturii")
  defines `POST https://webservicesp.anaf.ro/api/validate/signature` (or
  `https://api.anaf.ro/api/validate/signature` with OAuth) with two multipart files, `file` and
  `signature`; the SDK now sends exactly that. 3.x posted the invoice alone as `text/plain` to
  `/prod/FCTEL/rest/verificare-semnatura`, an endpoint ANAF does not document, and could not send
  the signature at all.
- The default `endpoints.services.verify_signature` is now
  `https://webservicesp.anaf.ro/api/validate/signature`. A published config that still holds the
  old URL must be updated.
- Both arguments are refused with `ValidationException` when blank, before any quota is spent.
- ANAF does not document the answer. It is read with the same `ValidationResultData` parser as
  before (`valid` from `valid` or `stare: ok`), unverified against a live answer: an answer in
  another shape reads as `valid: false`, so do not treat `false` alone as proof of a bad signature.
  When nothing in the answer is read, `details` holds its first 500 bytes (cut on a UTF-8 character
  boundary), so ANAF's own text is not lost.
- An implementation of `EFacturaClientInterface`, or a subclass overriding `verifySignature()`,
  must take the new parameter.

### Fixed: a failing log write never costs an ANAF answer

- The SDK's clients log through a wrapper that swallows a failing log handler (an unwritable daily
  file, a full disk). The rate limiter's warning about an `array` or `null` cache store does the
  same, so a failing log channel no longer stops a client from being built. Before, such a failure after ANAF answered lost an upload's index (reported as
  `ApiException(500)` or a raw `UnexpectedValueException`) and could re-send a read.

### Fixed: the SDK log channel is defined when the host did not define it

- When `config('logging.channels.'.<efactura-sdk.logging.channel>)` is missing, the provider defines
  it at boot as a `stack` over the host's default channel. Before, every `Log::channel()` call built
  Laravel's emergency logger and wrote an emergency entry. A host channel is never replaced, and no
  stack is defined over itself (when the default channel has the same name).

### Fixed: company lookups at least a second apart

- The company-lookup limit (still 1, ANAF's cap) is counted over a 2-second window. Stores that
  expire keys at whole seconds (database, file, memcached) let a lookup at x.999 be followed by
  another at x+1.001. The gap is now always at least 1 s; on Redis it is 2 s.
  A `company_lookup_per_second` of N above 1 allows N lookups per 2 s, not per second.
  `retryAfterSeconds` is 1–2, and the refusal reads "Company lookup rate limit exceeded (1 per 2
  seconds)" (was "(1/second)").

### Fixed: configuration errors are not refused grants

- Missing OAuth configuration (`client_id`, `client_secret`, `redirect_uri`, the authorize or token
  endpoint) throws `AuthenticationException` with code `0`, like a transport failure, instead of
  `401`. A host that starts a "re-authorise" cooldown on 400/401 no longer blocks every company for a
  deployment mistake that re-authorising cannot fix.
- After a failed token refresh, the client's fail-fast "Token refresh previously failed" exception
  has the original failure's code and the original as `previous`, instead of always `401`.

### Breaking (4.0): an unrecognised upload status is `null`

- `UploadResponseData::$executionStatus` is now `?ExecutionStatus`. It is `null` when ANAF's
  `ExecutionStatus` is missing or not exactly `0`/`1` (int, or the strings `"0"`/`"1"`): the
  status was not recognised, so the outcome of the upload is unknown. Before, these became
  `ExecutionStatus::Error`, and on the JSON path a non-numeric string such as `"abc"` was cast to
  `0` and reported as `Success`.
- `isSuccessful()` is true only for an explicit `0` and `isFailed()` only for an explicit `1`.
  Both are false when the status is `null`, so `! isSuccessful()` no longer means "failed".
- An XML `<header>` with `index_incarcare` (even empty) but no `ExecutionStatus` now returns a
  DTO with that index and a `null` status, instead of throwing
  `ApiException('Unable to parse upload response')`. A header with neither still throws.
  `XmlParser::parseUploadResponse()` returns `executionStatus` as `int|null`, and keeps extracting
  `Errors` whenever ANAF did not report success.
- Why: ANAF assigns an `index_incarcare` only to an upload it accepted, and an unreadable status
  does not prove a rejection. Treating these answers as errors let callers re-upload an invoice
  ANAF may already have filed.
- Upgrade: handle the third case. If `executionStatus` is `null`, do not re-upload blindly; when
  `indexIncarcare` is set, poll its status. Code that reads `$executionStatus->value` or passes it
  to a non-nullable `ExecutionStatus` parameter must handle `null`.

### Fixed: a validation answer of `"valid": "false"` read as valid

- `validateXml()` and `ValidationResultData::fromAnafResponse()` treat only an explicit yes
  (`true`, `1`, `"1"`, `"true"`) as valid. Before, a plain `(bool)` cast read the string `"false"`
  as valid. `"stare": "ok"` still means valid.

### Breaking (4.0): ANAF values are normalised before they reach a DTO

- Every DTO built from ANAF data normalises the values it receives instead of taking them
  verbatim: `UploadResponseData`, `StatusResponseData`, `ListMessagesResponseData`,
  `PaginatedMessagesResponseData`, `MessageDetailsData`, `ValidationResultData`, `CompanyData`
  and its nested company DTOs, and `OAuthTokensData::fromAnafResponse()`.
  - Ids (`index_incarcare`, `dateResponse`, `id_descarcare`, `cui`, `serial`, message ids) sent
    as numbers become strings. Strings are trimmed, and an empty string becomes `null`. A float,
    bool or array becomes `null`, because a float id has already lost digits.
  - Text fields become strings when ANAF sends a scalar and `null` otherwise.
  - Counts (paginated list) and `expires_in` accept an int, a whole float or a digit string.
    Anything else is `null` (it used to be cast to `0`).
  - `Errors` (and the status `eroare`) may be a string, a single `{errorMessage}` object or a list
    of strings and objects. It always comes back as a list of non-empty strings, or `null`.
  - `StatusResponseData`: a `stare` that is not a string, for example a number, is `null`
    (unknown) instead of a `TypeError`. `eroare` becomes `errors` whenever `Errors` holds nothing
    usable, including an empty list.
- `MessageDetailsData::$id` is now `?string`. It is `null` when ANAF sent no usable id (missing,
  empty, or not an id), where it used to be `''`. The other fields still fall back to `''`, but
  an array no longer raises "Array to string conversion".
- The SDK decodes ANAF JSON with `JSON_BIGINT_AS_STRING`, so an upload index or download id too
  big for a PHP int keeps every digit. A leading UTF-8 BOM is ignored on every ANAF body the SDK
  reads: JSON answers, error bodies (including the company lookup's 404 not-found answer), the
  token answer, and XML upload and status answers.
- A 2xx body that is not a JSON object, for example a bare number, now throws `ApiException`
  carrying ANAF's status code: `Unable to parse upload response`, `Unable to parse status response`,
  `Unable to parse validation response`, `PDF conversion failed with invalid JSON response`, and
  the new `Unable to parse message list response` and
  `Unable to parse paginated message list response`. `getMessages()` and `getMessagesPaginated()`
  used to return an empty response for an unparseable body. `AnafDetailsClient` reports such a body
  as a failed lookup, `AnafAuthenticator` as a non-JSON response, and a found-company entry that
  is not an object is skipped.
- Error messages taken from a response (`message`, `eroare`, `error`, OAuth `error` and
  `error_description`) are used only when they are scalar. A nested value falls through to the
  next key instead of raising a `TypeError`.
- Why: ANAF does not keep its scalar types stable. Under `strict_types`, a number where a string
  was declared was a `TypeError` that lost an upload index or crashed a sync. A missing message id
  became `''`, so distinct messages could be merged under one key.
- Upgrade: skip a message whose `$id` is `null`, and handle `ApiException` from the message list
  calls. Code that relied on a count cast to `0`, or on `''` for a missing message id, must handle
  `null`.

### Breaking (4.0): an XML status answer with only `Errors` has no `stare`

- `getStatusMessage()` reads an XML `<header>` that carries only `<Errors errorMessage="..."/>`
  (an upload id ANAF does not recognise, "Nu aveti dreptul") as `stare` `null` with ANAF's
  messages in `errors`, so `hasAnafError()` is true and `isFailed()` is false. It used to be
  `nok` (`isFailed()`) with no `idDescarcare`. A `<Raspuns>` carrying only `Error`/`eroare` is read
  the same way. `XmlParser::parseStatusResponse()` returns `stare` `null` for both. An error with
  an empty or blank message reads as `Operation failed`, so it is still an ANAF error.
- Why: the JSON answer for the same case (`{"eroare": "..."}`) already gave `hasAnafError()`.
  ANAF said nothing about the document, so the XML answer must not read as a rejection.
- Upgrade: handle `hasAnafError()` for XML answers too; a check for `isFailed() && idDescarcare ===
  null` no longer sees these answers.

### Breaking (4.0): `convertXmlToPdf()` returns only a PDF

- A 2xx answer whose body does not start with `%PDF` throws `ApiException` carrying ANAF's status
  code (`ANAF did not return a PDF (content-type: ...)`), the same guard `downloadDocument()` has
  for ZIP archives. Before, an HTML maintenance page or plain text came back as the PDF.
- A JSON error is recognised by its content type or by a body starting with `{` (after any UTF-8
  BOM or whitespace), so a JSON error sent without a JSON content type throws ANAF's message
  instead of being returned as the PDF.
- Upgrade: catch `ApiException` around `convertXmlToPdf()`.

### Changed (4.0): message lists refuse a blank CIF

- `getMessages()` and `getMessagesPaginated()` throw `ValidationException('CIF cannot be empty')`
  for an empty or blank `cif`, before any quota is counted and before any request. With rate
  limiting on, this used to be `InvalidArgumentException` from the rate limiter; with it off, the
  request went to ANAF.

### Changed: one parser for validation answers

- `ValidationResultData::fromAnafResponse()` is now the only parser for ANAF's validare and
  verificare answers; `validateXml()`, `verifySignature()` and `convertXmlToPdf()` use it. Valid
  means an explicit yes in `valid` or `"stare": "ok"`. `details` is `mesaj`, then `detalii`,
  `details`, `message`. `info` is `info`, then `trace_id`. `errors` is `Messages[].message`, then
  `Errors`, then `eroare`, as one list, or `null` when empty.
- Before, the DTO read only `valid`, `details`, `info` and `Errors`, so calling it on an ANAF answer
  reported `{"stare": "ok"}` as invalid and dropped `Messages`, `mesaj` and `trace_id`; only the
  client's own parser read them.
- The client's `Errors` and `Messages` also accept every shape `AnafValue` normalises (a single
  string or object). A `true`/`false` entry is no longer read as a message.

### Fixed: a response DTO reads its own `toArray()` back

- `UploadResponseData`, `StatusResponseData`, `MessageDetailsData`, `ListMessagesResponseData`,
  `PaginatedMessagesResponseData` and `ValidationResultData` rebuild an equal DTO from their own
  `toArray()` output: `StatusResponseData::from($status->toArray())`, including a `null`
  `executionStatus` and nested messages.
- Why: laravel-data sends every array given to `::from()` through `fromAnafResponse()`, which read
  only ANAF's keys, so a DTO cached or queued as an array came back empty (`indexIncarcare`,
  `idDescarcare`, counts and errors `null`, message dates `''`). Each DTO now recognises its own
  shape by a key ANAF never sends (`executionStatus`, `idDescarcare`, `dataCreare`,
  `downloadError`, `currentPage`), the way `OAuthTokensData` already did; `ValidationResultData`
  shares its other keys with ANAF and also reads its own `errors`. The same values are normalised
  either way. The arrays `XmlParser::parseUploadResponse()` and
  `parseStatusResponse()` return use these keys too, so they are read correctly as well.
- This holds for laravel-data's default (camelCase) output naming only. An application that maps
  output names to snake_case (`name_mapping_strategy.output` in `config/data.php`) gets ANAF-like
  keys from `toArray()`, which are read as an ANAF answer and lose fields such as `errors`.

### Fixed: rate limits hold under concurrent calls

- `RateLimitStore::attemptOrFail()` increments the counter first and refuses when the result is
  over the limit. It used `RateLimiter::attempt()`, which reads the counter and then increments it,
  so two workers could both take the last unit and pass the limit. A refused call stays counted
  in its already-full window; the increment does not move the window's expiry, so the window
  still ends on time and the next one allows the full limit. Per-CUI and per-message checks peek
  first, so a status read refused at the SDK's 50/day counts nothing against ANAF's 100. The
  count is atomic on a cache store with an atomic increment (redis, memcached, database).

### Fixed: a string `EFACTURA_TIMEOUT`

- `EFacturaClient::getTimeoutDuration()`, `AnafDetailsClient::getTimeoutDuration()` and
  `AnafAuthenticator` read `http.timeout` as a number, falling back to 30 when it is empty, `0` or
  negative. A fraction such as `0.5` is kept for API requests; `AnafAuthenticator` rounds it up to
  whole seconds. `env()` returns a string, which was a `TypeError` under `strict_types`; an empty
  value would have meant no timeout at all.

### Fixed: the bearer token is not logged

- With `logging.debug` on, the logged request headers show `Authorization` (any case) as
  `[redacted]` instead of the access token.

### Fixed: the error message of an XML error body

- A non-2xx answer with an XML body such as `<Errors errorMessage="..."/>` throws `ApiException`
  with ANAF's message instead of `No error message in API response`. JSON `message`, `eroare` and
  `error` still come first. Each of them, and the XML message, counts only when it is non-blank
  text: a blank, boolean or non-scalar value falls through to the next one (so
  `{"message":"","eroare":"X"}` reads `X`, and a lone blank one reads as
  `No error message in API response`). A literal `0` is kept as the message.

### Fixed: message lists stay lists

- `ListMessagesResponseData::$mesaje` and `PaginatedMessagesResponseData::$mesaje` are re-indexed
  after skipping entries that are not objects, so `$mesaje[0]` is the first message.

### Fixed: the token refresh lock outlives the refresh

- The token refresh lock is held for the HTTP timeout plus 30 seconds (it was 10 seconds, less
  than the default 30-second timeout), so it cannot expire while the refresh request is in flight
  and let another worker spend the same refresh token. Concurrent callers still wait at most 15
  seconds for it: while a slow refresh, reloader or persister holds the lock (or a crashed holder
  leaves it until it expires, now up to the timeout plus 30 seconds instead of 10), they throw
  `AuthenticationException('Token refresh lock timeout…')` with code 0, as before.

### Fixed: the code of an unreadable token answer

- A 2xx token answer that is not JSON, or lacks the access or refresh token, throws
  `AuthenticationException` with the HTTP status as its code (for example 200). It used to carry
  the class default 401, which read as ANAF refusing the grant. A non-2xx answer still carries its
  status, a transport failure 0.

### Added: `tokenPersister`

- `EFacturaClient::__construct()` and `::fromTokens()` accept an optional last parameter
  `?Closure $tokenPersister = null`. It is called with the new `OAuthTokensData` inside the refresh
  lock, right after a successful refresh and before the lock is released, so no other worker can
  spend the rotated refresh token before it is stored. An exception it throws reaches the caller
  unchanged, including a `LockTimeoutException` from a lock of its own; the client keeps the new
  tokens and `wasTokenRefreshed()` is still true. A `tokenReloader` exception, as before, is
  caught and logged as a warning, and the client keeps its in-memory tokens.

### Removed

- `RateLimitStore::clear()`, which nothing in the SDK called. Use
  `Illuminate\Support\Facades\RateLimiter::clear()` with the full key (`efactura-sdk:{window}:{key}`).
- `InvoiceLineData::getRawLineTotal()`, the float `quantity × unitPrice` that the invoice never
  files. Use `getLineTotal()`, the line net exactly as `LineExtensionAmount` files it.

### Added: `RateLimitExceededException::$scope`

- `RateLimitExceededException` has a new `public readonly ?RateLimitScope $scope`, the last
  constructor parameter (default `null`), so existing positional and named calls keep working.
  The new `BeeCoded\EFacturaSdk\Enums\RateLimitScope` has the cases `Global`, `CompanyLookup`,
  `RaspUpload`, `Status`, `SimpleList`, `PaginatedList` and `Download`. Their values match
  `RateLimiter::getRemainingQuota()`'s types.
- `RateLimiter` always sets the scope. A batch can tell a per-message status limit
  (`RateLimitScope::Status`: skip that upload, keep polling others) from the global limit
  (`RateLimitScope::Global`: stop the batch). `null` means the exception was thrown outside the
  SDK's limiter.

### Fixed: a refused call spends no quota

- A call with a per-CUI or per-message limit (status, download, message lists, RASP upload) used to
  count its global unit before checking that limit, so a call the per-message limit refused still
  spent a global unit. `EFacturaClient` now checks that limit first without counting, through the
  new `RateLimiter::ensureAvailable(RateLimitScope $scope, string $identifier, bool
  $useProviderLimit = false)`, then counts the global unit and the per-message unit. A refused call
  or retry counts nothing, unless a concurrent call takes the last per-message unit between that
  check and the count: then this call's global unit is spent and its refused per-message unit stays
  counted in the already-full window. The global limit still refuses before the per-message unit is
  counted; an allowed call counts one unit of each, as before.
- Upgrade: a test double of `RateLimiter` must allow `ensureAvailable()` (with Mockery,
  `shouldReceive('ensureAvailable')`, or `shouldIgnoreMissing()`).

### Changed: money is exact decimal

- Amounts are computed with exact decimals (brick/math) instead of floats, read at the precision
  they are filed with (see "Breaking (4.0): amounts are exact or refused"). Each line net
  (`quantity × unitPrice`) and each VAT group's tax sum is rounded half-up exactly once, and
  document totals are exact sums.
- Why: PHP 8.4 removed `round()`'s "pre-rounding", so a float product or sum that is a true
  half-cent tie rounded down on every PHP version this SDK installs on. `3 × 1.005` filed `3.01`
  instead of `3.02`, and per-line taxes `1285.8489 + 130.7061` filed a VAT group of `1416.55`
  instead of `1416.56`. ANAF accepts either figure, so the XML silently disagreed with an exact
  ledger. Large documents also drifted: 200 lines of `999999999999.99` totalled
  `199999999999998.25` instead of `199999999999998.00`.
- The amount helpers `getLineTotal()`, `getTaxAmount()`, `getLineTotalWithTax()`,
  `getTotalExcludingVat()`, `getTotalVat()`, `getTotalIncludingVatBeforeRounding()` and
  `getTotalIncludingVat()` keep their signatures and `float` return types. `getLineTotal()` and the
  four document totals return the amounts the XML files. The XML has no per-line tax amount (VAT
  is filed per group), so `getTaxAmount()` and `getLineTotalWithTax()` return the line's own
  amounts rounded to cents; they now agree with the exact amounts the XML's line nets and VAT
  groups are built from. `getRawLineTotal()` was removed ("Removed").

### Added

- Optional payable rounding (BT-114): `InvoiceData` accepts `float|string|null
  $payableRoundingAmount = null`, a signed amount with at most 2 decimals filed as
  `PayableRoundingAmount` and included in `PayableAmount`. `getTotalIncludingVatBeforeRounding()` returns net plus VAT without it.
- Optional VAT applicability date (BT-7): `InvoiceData` accepts
  `CarbonInterface|string|null $taxPointDate = null`, filed in the header position each document
  type requires. Omitting either new field keeps the previous serialized shape.
- The document VAT and its RON conversion (BT-111) may each round to zero on their own, for small
  converted amounts; opposite nonzero signs are still rejected.
- Reverse charge (VAT category AE): `InvoiceLineData` accepts an optional `taxCategoryId`; `null`
  keeps the previous category inference and serialized shape. VAT breakdowns are grouped by
  resolved category and normalized rate, identically in the builder and in the totals. AE requires
  a VAT-registered supplier, usable VAT identifiers for both parties (BR-AE-02), a zero rate and
  amount (BR-AE-05, BR-AE-09), and a zero `taxAmountRon` when no standard-rated line is present.
- `UploadStatusValue::NotProcessed` and `StatusResponseData::isNotProcessed()` recognise ANAF's
  final "XML cu erori nepreluat de sistem" status: the file was not taken in and no download id
  exists. `isFailed()` keeps its meaning of a rejection with a response archive.
- Status reads at ANAF's own limit: `EFacturaClient::getStatusMessage()`,
  `RateLimiter::checkStatusQuery()` and `RateLimiter::getRemainingQuota()` accept an optional
  `bool $useProviderLimit = false`. When true, the same per-message `status` counter is checked, on
  the first attempt and on every retry, and reported against the new
  `rate_limits.status_per_day_message_provider` (`EFACTURA_RATE_LIMIT_STATUS_PROVIDER`, default
  100, ANAF's ceiling) instead of `status_per_day_message` (default 50). It is meant for a check a
  user explicitly asked for; reads it spends still count against automatic polling, which keeps
  stopping at 50. `getRemainingQuota()` ignores the flag for other types. A published config
  without the new key uses 100. `EFacturaClientInterface` is unchanged, and calls without the new
  argument behave as before.

### Added: extension points

- New public helpers: `InvoiceLineData::getTaxCategory(bool $isSupplierVatPayer)` and
  `getTaxGroupKey(bool $isSupplierVatPayer)`, `InvoiceData::getTaxPointDateAsCarbon()` and
  `getPayableRoundingAmount()`, and the enum case `TaxCategoryId::ReverseCharge` (`AE`).
- New public constant `BaseApiClient::NO_ERROR_MESSAGE` (`'No error message in API response'`),
  the message of an `ApiException` for an error answer that carried no message of its own.
  `UploadOutcome` uses it to leave `providerText` empty; compare against it instead of the text.
- New protected `BaseApiClient::getRequestTimeout(): float|int`, the timeout `request()` uses for
  this instance. It defaults to the static `getTimeoutDuration()`; `AnafDetailsClient` overrides
  it for `timeoutSeconds`, and a subclass may too.
- New protected `BaseApiClient::decodeJson(Response $response): mixed`, the JSON decoder every
  client uses (strips a BOM, keeps big integers as strings). Every `Response` body a client
  decodes goes through `static::decodeJson()`, so a subclass can call or override it. The company
  lookup's 404 not-found body (a string taken from the exception) and the OAuth token answer
  (`AnafAuthenticator` is not a `BaseApiClient`) use the same decoding rule directly, so an
  override does not apply to them.

### Compatibility

- Amounts change only where the float result was not the exact half-up of the 6-decimal inputs,
  in the XML and in the helpers alike:
  - exact half-cent ties now round away from zero, one cent further from zero than the float
    result on PHP 8.4+: `3 × 1.005` files `3.02` (was `3.01`);
  - near ties can move by one cent in either direction, mainly on line nets above about 1,000:
    `30.176963 × 331.005973` is exactly `9988.754999999999` and now files `9988.75` (was `9988.76`);
  - documents whose totals reach about `1e13` or more, where float sums drifted by cents: the
    totals and every helper are now exact (pass such amounts as strings; see below).
- Inputs that cannot be read exactly at their filed precision are refused instead of rounded (see
  "Breaking (4.0): amounts are exact or refused"): a quantity of `1/3`, a rate of `19.001`, a
  `taxAmountRon` of `944.3049`, and floats at or above 2^33 (6-decimal fields) or 2^46 (2-decimal
  fields), such as `999999999999.99` as a unit price or a large accumulated float sum.
- The same read can reject input that used to pass. A tax amount just under half a cent
  (`0.0049999999`, read as `0.005000`) on a 0% or non-VAT-payer line now fails BR-Z-09 or
  BR-O-09 with `ValidationException` (it filed `0.00`).
- Whole quantities with 2-decimal prices, and per-line VAT already rounded to cents, produce
  identical amounts and identical XML for document totals below about `1e13`.
- A zero result is now `0.0`, never `-0.0`: the per-line helpers `getLineTotal()`,
  `getTaxAmount()` and `getLineTotalWithTax()` returned `-0.0` for, say, a negative line at 0% VAT.
  The values are equal under `==` and `===`, and the XML is unchanged.
- Non-finite amounts (`INF`, `NAN`) in any amount, `taxPercent` included, are no longer written
  into the XML as `inf`/`nan`, which ANAF rejects. The builder (directly or through the
  `UblBuilder` facade or service) and the amount helpers, which used to return `INF`/`NAN`, throw
  `ValidationException` naming the field.
- New direct dependency: `brick/math` `^0.14.4 || ^0.15 || ^0.16 || ^0.17 || ^0.18 || ^0.19`.
  - The floor is 0.14.4 because the SDK calls `BigDecimal::strippedOfTrailingZeros()`, which
    brick/math added in 0.14.4 (its predecessor `stripTrailingZeros()` was removed in 0.15). An
    application locked to brick/math 0.14.2 or 0.14.3 must update it (`composer update brick/math`).
  - Laravel 12 needs laravel/framework 12.27.1 or later; earlier 12.x releases cap brick/math
    below 0.14.
  - A Laravel 13 application already on brick/math 0.20 or 1.x is not covered by this release:
    Composer refuses it, or quietly keeps the previous SDK version, unless brick/math is
    downgraded.
- Laravel 11 is no longer supported: `illuminate/*` is now `^12.0|^13.0`. CI also runs PHP 8.5.
- A subclass that overrides `EFacturaClient::getStatusMessage()`, `RateLimiter::checkStatusQuery()`
  or `RateLimiter::getRemainingQuota()` must add the optional `$useProviderLimit` parameter to its
  signature. An ordinary status read still calls `checkStatusQuery($uploadId)` with one argument.

**Note for applications that compute their own invoice totals:** Safe to upgrade: whole
quantities, 2-decimal prices and per-line VAT already rounded to cents produce identical amounts
for totals below about 1e13.
If you accept fractional quantities, prices with more than 2 decimals or unrounded per-line VAT,
compute your own totals with the same exact half-up rule (or the SDK's line/total helpers);
otherwise your PDF and XML can differ by 0.01.
