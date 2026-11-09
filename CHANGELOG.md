# Changelog

This changelog starts with the changes since v3.1.1; earlier releases are described in the README
and the git history.

## Unreleased

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
  big for a PHP int keeps every digit.
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
  or retry counts nothing; the global limit still refuses before the per-message unit is counted;
  an allowed call counts one unit of each, as before.
- Upgrade: a test double of `RateLimiter` must allow `ensureAvailable()` (with Mockery,
  `shouldReceive('ensureAvailable')`, or `shouldIgnoreMissing()`).

### Changed: money is exact decimal

- Amounts are computed with exact decimals (brick/math) instead of floats. `quantity`,
  `unitPrice`, `taxAmount` and `taxAmountRon` are read at 6 decimals, the precision quantities and
  prices are filed with. Each line net (`quantity × unitPrice`) and each VAT group's tax sum is
  rounded half-up exactly once, and document totals are exact sums. VAT rates (`taxPercent`) are
  not money and are formatted as before.
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
  groups are built from. `getRawLineTotal()` is unchanged: it still returns the raw float product.

### Added

- Optional payable rounding (BT-114): `InvoiceData` accepts `?float $payableRoundingAmount = null`,
  a signed, cent-representable amount filed as `PayableRoundingAmount` and included in
  `PayableAmount`. `getTotalIncludingVatBeforeRounding()` returns net plus VAT without it.
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

### Compatibility

- Amounts change only where the float result was not the exact half-up of the 6-decimal inputs,
  in the XML and in the helpers alike:
  - exact half-cent ties now round away from zero, one cent further from zero than the float
    result on PHP 8.4+: `3 × 1.005` files `3.02` (was `3.01`);
  - near ties can move by one cent in either direction, mainly on line nets above about 1,000:
    `30.176963 × 331.005973` is exactly `9988.754999999999` and now files `9988.75` (was `9988.76`);
  - inputs with more than 6 decimals are read at 6: a quantity of `1/3` files as `0.333333`, so
    `1/3 × 30000.00` gives `9999.99` (was `10000.00`);
  - inputs above about `4·10⁹` (2³²), which a float cannot hold to 6 decimals, are read as the
    float's own value at 6 decimals: `0.5 × 999999999999.99` gives `499999999999.99` (was
    `500000000000.00`), consistent with the `PriceAmount` the XML already filed
    (`999999999999.98999`);
  - documents whose totals reach about `1e13` or more, where float sums drifted by cents: the
    totals and every helper are now exact.
- The same read can reject input that used to pass. A tax amount just under half a cent
  (`0.0049999999`, read as `0.005000`) on a 0% or non-VAT-payer line now fails BR-Z-09 or
  BR-O-09 with `ValidationException` (it filed `0.00`); as `taxAmountRon` against a negative
  document VAT it now fails the sign check (`tax_amount_ron_sign_mismatch`).
- Whole quantities with 2-decimal prices, and per-line VAT already rounded to cents, produce
  identical amounts and identical XML for document totals below about `1e13`.
- A zero result is now `0.0`, never `-0.0`: the per-line helpers `getLineTotal()`,
  `getTaxAmount()` and `getLineTotalWithTax()` returned `-0.0` for, say, a negative line at 0% VAT.
  The values are equal under `==` and `===`, and the XML is unchanged.
- Non-finite amounts (`INF`, `NAN`) in `quantity`, `unitPrice`, `taxAmount` or `taxAmountRon` are
  no longer written into the XML as `inf`/`nan`, which ANAF rejects. Through the `UblBuilder`
  facade or service they now fail with `ValidationException`; a direct
  `InvoiceBuilder::buildInvoiceXml()` call, and the amount helpers (which used to return
  `INF`/`NAN`), throw `Brick\Math\Exception\NumberFormatException`. The BR-O-09 and BR-Z-09 line
  checks still throw `ValidationException` for a non-finite `taxAmount`, as before. `taxPercent` is
  not read as money and behaves as before: `INF` fails the 0–100 check with `ValidationException`,
  and `NAN` is still written as `nan`.
- New direct dependency: `brick/math` `^0.14.2 || ^0.15 || ^0.16 || ^0.17 || ^0.18 || ^0.19`.
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
