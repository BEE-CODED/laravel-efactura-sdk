<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Builders;

use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Support\AddressSanitizer;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Carbon\Carbon;
use Sabre\Xml\Service as XmlService;
use Sabre\Xml\Writer;

/**
 * UBL 2.1 Invoice XML Builder for ANAF e-Factura.
 *
 * Generates compliant UBL 2.1 XML invoices following the Romanian CIUS-RO specification.
 * This builder handles all validation, tax grouping, and XML generation.
 */
class InvoiceBuilder
{
    /**
     * UBL 2.1 Invoice namespace.
     */
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    /**
     * UBL 2.1 Credit Note namespace.
     */
    private const NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    /**
     * Common Aggregate Components namespace.
     */
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    /**
     * Common Basic Components namespace.
     */
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * CIUS-RO Customization ID for Romanian e-Factura.
     */
    private const UBL_CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1';

    /**
     * PEPPOL Profile ID.
     */
    private const UBL_PROFILE_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    /**
     * Default currency code.
     */
    private const DEFAULT_CURRENCY = 'RON';

    /**
     * Default country code.
     */
    private const DEFAULT_COUNTRY_CODE = 'RO';

    /**
     * Default unit code (each).
     */
    private const DEFAULT_UNIT_CODE = 'EA';

    /**
     * VAT tax scheme ID.
     */
    private const VAT_SCHEME_ID = 'VAT';

    /**
     * UNTDID 4461 payment means code 30 — credit transfer (bank transfer).
     *
     * BR-61 requires the payee account identifier (BT-84) whenever the payment
     * means code is a credit transfer (30 or 58), so this code may only be used
     * when an IBAN is available to emit.
     */
    private const PAYMENT_MEANS_CREDIT_TRANSFER = '30';

    /**
     * UNTDID 4461 payment means code 1 — instrument not defined.
     *
     * Used for a payment instruction that exists only to carry the due date
     * (BT-9) on a credit note. BR-61 keys off the CODE rather than the presence
     * of an account, so any code outside {30, 58} satisfies it vacuously; code 1
     * is in the EN 16931 and CIUS-RO permitted subsets of UNTDID 4461 (BR-CL-16).
     */
    private const PAYMENT_MEANS_NOT_DEFINED = '1';

    /**
     * VAT identifier prefixes that differ from the party's ISO 3166-1 alpha-2
     * country code.
     *
     * Greece is the only EU member state whose VAT prefix is not its country
     * code: it registers VAT under "EL" (Elláda) while its country code is "GR".
     *
     * @var array<string, string>
     */
    private const VAT_PREFIX_OVERRIDES = [
        'GR' => 'EL',
    ];

    /**
     * Recognised VAT identifier prefixes.
     *
     * The EU-27 VAT prefixes (which use "EL" for Greece rather than "GR"), plus
     * "GB" and Northern Ireland's "XI", which remains in the EU VAT area for
     * goods under the Windsor Framework and appears on otherwise-"GB" addresses.
     *
     * @var list<string>
     */
    private const VAT_COUNTRY_PREFIXES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
        'GB', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    /**
     * Maximum decimals retained for quantities (BT-129/BT-130) and item net
     * prices (BT-146).
     *
     * Six covers the real-world cases that 2 decimals destroy — per-unit pricing
     * below a bani (telecom, energy, per-page/per-SMS tariffs) and fine-grained
     * quantities (grams billed in KGM, hours billed in fractions) — while still
     * capping binary floating-point artefacts, so a quantity of 1/3 renders as
     * "0.333333" rather than seventeen digits of noise.
     */
    private const QUANTITY_PRECISION = 6;

    /**
     * Build a UBL 2.1 XML invoice or credit note from the provided invoice data.
     *
     * Automatically generates the correct document type based on InvoiceTypeCode:
     * - 381 (CreditNote) -> UBL CreditNote document
     * - 380, 384, 389, 751 -> UBL Invoice document
     *
     * @param  InvoiceData  $input  The invoice data to convert to XML
     * @return string The generated UBL 2.1 XML string
     *
     * @throws ValidationException If the invoice data is invalid
     */
    public function buildInvoiceXml(InvoiceData $input): string
    {
        $this->validateInvoiceInput($input);

        $currency = $input->currency ?: self::DEFAULT_CURRENCY;
        $isSupplierVatPayer = $input->supplier->isVatPayer;
        $isCreditNote = $input->getInvoiceTypeCode()->isCreditNote();

        // Group lines by tax percentage
        $taxGroups = $this->groupLinesByTax($input->lines, $isSupplierVatPayer, $isCreditNote);

        // Calculate totals
        $lineExtensionAmount = 0.0;
        $taxExclusiveAmount = 0.0;
        $taxInclusiveAmount = 0.0;
        $totalTaxAmount = 0.0;

        foreach ($input->lines as $line) {
            $lineExtensionAmount += $this->calculateLineExtension($line, $isCreditNote);
        }

        foreach ($taxGroups as $group) {
            $totalTaxAmount += $group['taxAmount'];
        }

        $taxExclusiveAmount = $lineExtensionAmount;
        $taxInclusiveAmount = $taxExclusiveAmount + $totalTaxAmount;

        // Determine root namespace based on document type
        $rootNamespace = $isCreditNote ? self::NS_CREDIT_NOTE : self::NS_INVOICE;
        $rootElement = $isCreditNote ? 'CreditNote' : 'Invoice';

        // Build XML using Sabre\Xml
        $service = new XmlService;
        $service->namespaceMap = [
            $rootNamespace => '',
            self::NS_CAC => 'cac',
            self::NS_CBC => 'cbc',
        ];

        $writer = $service->getWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        // Start root element (Invoice or CreditNote)
        $writer->startElement('{'.$rootNamespace.'}'.$rootElement);

        // Write header elements
        $this->writeElement($writer, self::NS_CBC, 'CustomizationID', self::UBL_CUSTOMIZATION_ID);
        $this->writeElement($writer, self::NS_CBC, 'ProfileID', self::UBL_PROFILE_ID);
        $this->writeElement($writer, self::NS_CBC, 'ID', $input->invoiceNumber);
        $this->writeElement($writer, self::NS_CBC, 'IssueDate', $input->getIssueDateAsCarbon()->format('Y-m-d'));

        // DueDate is only valid in Invoice schema, not in CreditNote
        if ($input->dueDate !== null && ! $isCreditNote) {
            /** @var \Carbon\Carbon $dueDate PHPStan: guaranteed non-null by guard clause above */
            $dueDate = $input->getDueDateAsCarbon();
            $this->writeElement($writer, self::NS_CBC, 'DueDate', $dueDate->format('Y-m-d'));
        }

        // Type code element name differs between Invoice and CreditNote
        $typeCodeElement = $isCreditNote ? 'CreditNoteTypeCode' : 'InvoiceTypeCode';
        $this->writeElement($writer, self::NS_CBC, $typeCodeElement, $input->getInvoiceTypeCode()->value);
        $this->writeElement($writer, self::NS_CBC, 'DocumentCurrencyCode', $currency);

        // BR-RO-030: If currency is not RON, TaxCurrencyCode must be RON
        if ($currency !== self::DEFAULT_CURRENCY) {
            $this->writeElement($writer, self::NS_CBC, 'TaxCurrencyCode', self::DEFAULT_CURRENCY);
        }

        // BillingReference for credit notes (BG-3: Preceding invoice reference)
        if ($input->precedingInvoiceNumber !== null && $input->precedingInvoiceNumber !== '') {
            $this->buildBillingReferenceXml($writer, $input->precedingInvoiceNumber);
        }

        // Write supplier party
        $this->buildPartyXml($writer, 'AccountingSupplierParty', $input->supplier, $isSupplierVatPayer);

        // Write customer party
        // BR-O-02: when the supplier is not a VAT payer every line carries VAT
        // category "O", which prohibits the buyer VAT identifier (BT-48) just as
        // it does the seller's (BT-31) — a fatal assert. The buyer's legal
        // registration id (BT-47, PartyLegalEntity/CompanyID) is unaffected.
        $this->buildPartyXml(
            $writer,
            'AccountingCustomerParty',
            $input->customer,
            $isSupplierVatPayer && $input->customer->isVatPayer
        );

        // BG-16 Payment instructions.
        //
        // A credit note states its due date (BT-9) here: UBL 2.1 CreditNoteType
        // has no cbc:DueDate element at all (that position in the sequence holds
        // cbc:TaxPointDate), so EN 16931 binds BT-9 on a credit note to
        // cac:PaymentMeans/cbc:PaymentDueDate. UBL-CR-412
        // ("not(cac:PaymentMeans/cbc:PaymentDueDate) or ../cn:CreditNote") exists
        // solely to carve this out for credit notes — an invoice must keep using
        // the root cbc:DueDate written above.
        //
        // The instruction is therefore emitted for a due date OR an IBAN, not for
        // an IBAN alone; otherwise a credit note's due date is silently dropped.
        $iban = $input->paymentIban !== null && $input->paymentIban !== '' ? $input->paymentIban : null;
        $paymentDueDate = $isCreditNote ? $input->getDueDateAsCarbon() : null;

        if ($iban !== null || $paymentDueDate !== null) {
            $this->buildPaymentMeansXml($writer, $iban, $paymentDueDate);
        }

        // Write tax total(s)
        // BR-RO-030: When currency is not RON, we need two TaxTotal elements:
        // 1. TaxAmount in document currency
        // 2. TaxAmount in RON (tax accounting currency)
        $this->buildTaxTotalXml($writer, $taxGroups, $totalTaxAmount, $currency);

        // Add second TaxTotal in RON for non-RON invoices (BR-53). Only for
        // non-RON: BR-CO-15 asserts exactly one TaxTotal in the document
        // currency, so a RON invoice must not get a second one.
        if ($currency !== self::DEFAULT_CURRENCY) {
            /** @var float $taxAmountRon PHPStan: guaranteed non-null by validateTaxAccountingCurrency() */
            $taxAmountRon = $input->taxAmountRon;

            // Sign-flipped for credit notes exactly as the per-line tax amounts
            // are, so BT-111 agrees with the document-currency total it converts.
            $this->buildTaxTotalInAccountingCurrency(
                $writer,
                $isCreditNote ? -$taxAmountRon : $taxAmountRon
            );
        }

        // Write monetary total
        $this->buildLegalMonetaryTotalXml(
            $writer,
            $lineExtensionAmount,
            $taxExclusiveAmount,
            $taxInclusiveAmount,
            $currency
        );

        // Write invoice/credit note lines
        $lineId = 1;
        foreach ($input->lines as $line) {
            $this->buildInvoiceLineXml($writer, $line, $lineId, $isSupplierVatPayer, $currency, $isCreditNote);
            $lineId++;
        }

        $writer->endElement(); // Invoice or CreditNote

        return $writer->outputMemory();
    }

    /**
     * Validate the invoice input data.
     *
     * @throws ValidationException If validation fails
     */
    private function validateInvoiceInput(InvoiceData $input): void
    {
        if (empty($input->invoiceNumber)) {
            throw new ValidationException(__('efactura-sdk::validation.invoice_number_required'));
        }

        // BR-RO-010: Invoice number must contain at least one digit
        if (! preg_match('/[0-9]/', $input->invoiceNumber)) {
            throw new ValidationException(__('efactura-sdk::validation.invoice_number_must_contain_digit'));
        }

        // BR-RO-L200: Invoice number max 200 characters
        if (mb_strlen($input->invoiceNumber) > 200) {
            throw new ValidationException(__('efactura-sdk::validation.invoice_number_max_length'));
        }

        if (empty($input->issueDate)) {
            throw new ValidationException(__('efactura-sdk::validation.issue_date_required'));
        }

        $this->validateParty($input->supplier, 'Supplier');
        $this->validateParty($input->customer, 'Customer');

        if (empty($input->lines)) {
            throw new ValidationException(__('efactura-sdk::validation.at_least_one_line_required'));
        }

        foreach ($input->lines as $index => $line) {
            $this->validateLine($line, $index, $input->supplier->isVatPayer);
        }

        // BR-RO-L200: Preceding invoice number max 200 characters
        if ($input->precedingInvoiceNumber !== null && mb_strlen($input->precedingInvoiceNumber) > 200) {
            throw new ValidationException(__('efactura-sdk::validation.preceding_invoice_number_max_length'));
        }

        $this->validateTaxAccountingCurrency($input);
    }

    /**
     * Validate the RON VAT total (BT-111) against the document currency.
     *
     * BR-RO-030 forces BT-6 to RON for a non-RON invoice and BR-53 then requires
     * a cac:TaxTotal/cbc:TaxAmount at @currencyID='RON'. ANAF has no way to check
     * the conversion, so an incorrect figure is accepted and filed as a true
     * statement of VAT owed — it must come from the caller.
     *
     * Conversely BR-CO-15 permits exactly one TaxTotal in the document currency,
     * so a RON invoice cannot carry a second RON total: accepting BT-111 there
     * and quietly discarding it would repeat the silent-wrong-figure failure this
     * guard exists to prevent.
     *
     * @throws ValidationException If validation fails
     */
    private function validateTaxAccountingCurrency(InvoiceData $input): void
    {
        $currency = $input->currency ?: self::DEFAULT_CURRENCY;

        if ($currency === self::DEFAULT_CURRENCY) {
            if ($input->taxAmountRon !== null) {
                throw new ValidationException(__('efactura-sdk::validation.tax_amount_ron_not_allowed_for_ron'));
            }

            return;
        }

        if ($input->taxAmountRon === null) {
            throw new ValidationException(
                __('efactura-sdk::validation.tax_amount_ron_required', ['currency' => $currency])
            );
        }

        // A converted total must agree in sign with the total it converts. This
        // cannot verify the RATE (that is the caller's BNR figure), but it does
        // catch a credited VAT filed as collected, and vice versa.
        if ((round($input->taxAmountRon, 2) <=> 0.0) !== ($input->getTotalVat() <=> 0.0)) {
            throw new ValidationException(__('efactura-sdk::validation.tax_amount_ron_sign_mismatch'));
        }
    }

    /**
     * Validate party (supplier or customer) data.
     *
     * @throws ValidationException If validation fails
     */
    private function validateParty(PartyData $party, string $role): void
    {
        if (empty($party->registrationName)) {
            throw new ValidationException(__('efactura-sdk::validation.party_registration_name_required', ['role' => $role]));
        }

        // BR-RO-L200: Registration name max 200 characters
        if (mb_strlen($party->registrationName) > 200) {
            throw new ValidationException(__('efactura-sdk::validation.party_registration_name_max_length', ['role' => $role]));
        }

        if (empty($party->companyId)) {
            throw new ValidationException(__('efactura-sdk::validation.party_company_id_required', ['role' => $role]));
        }

        $this->validateAddress($party->address, $role);
    }

    /**
     * Validate address data.
     *
     * @throws ValidationException If validation fails
     */
    private function validateAddress(AddressData $address, string $role): void
    {
        if (empty($address->street)) {
            throw new ValidationException(__('efactura-sdk::validation.party_street_required', ['role' => $role]));
        }

        // BR-RO-L150: Address line 1 max 150 characters
        if (mb_strlen($address->street) > 150) {
            throw new ValidationException(__('efactura-sdk::validation.party_street_max_length', ['role' => $role]));
        }

        if (empty($address->city)) {
            throw new ValidationException(__('efactura-sdk::validation.party_city_required', ['role' => $role]));
        }

        // BR-RO-L050: City name max 50 characters
        if (mb_strlen($address->city) > 50) {
            throw new ValidationException(__('efactura-sdk::validation.party_city_max_length', ['role' => $role]));
        }

        // BR-RO-L020: Postal code max 20 characters (optional field)
        if ($address->postalZone !== null && $address->postalZone !== '' && mb_strlen($address->postalZone) > 20) {
            throw new ValidationException(__('efactura-sdk::validation.party_postal_code_max_length', ['role' => $role]));
        }

        // BR-RO-110/111: Romanian addresses require CountrySubentity (county)
        if ($address->countryCode === 'RO' && empty($address->county)) {
            throw new ValidationException(__('efactura-sdk::validation.party_county_required_ro', ['role' => $role]));
        }
    }

    /**
     * Validate invoice line data.
     *
     * @param  bool  $isSupplierVatPayer  Whether the supplier is a VAT payer
     *
     * @throws ValidationException If validation fails
     */
    private function validateLine(InvoiceLineData $line, int $index, bool $isSupplierVatPayer = true): void
    {
        $lineNum = $index + 1;

        if (empty($line->name)) {
            throw new ValidationException(__('efactura-sdk::validation.line_item_name_required', ['lineNum' => $lineNum]));
        }

        // BR-RO-L100: Item name max 100 characters
        if (mb_strlen($line->name) > 100) {
            throw new ValidationException(__('efactura-sdk::validation.line_item_name_max_length', ['lineNum' => $lineNum]));
        }

        // BR-RO-L200: Item description max 200 characters
        if ($line->description !== null && mb_strlen($line->description) > 200) {
            throw new ValidationException(__('efactura-sdk::validation.line_item_description_max_length', ['lineNum' => $lineNum]));
        }

        // Allow negative quantities for credit notes, but not zero
        if ($line->quantity == 0) {
            throw new ValidationException(__('efactura-sdk::validation.line_quantity_cannot_be_zero', ['lineNum' => $lineNum]));
        }

        if ($line->unitPrice < 0) {
            throw new ValidationException(__('efactura-sdk::validation.line_unit_price_not_negative', ['lineNum' => $lineNum]));
        }

        if ($line->taxPercent < 0 || $line->taxPercent > 100) {
            throw new ValidationException(__('efactura-sdk::validation.line_tax_percent_range', ['lineNum' => $lineNum]));
        }

        // BR-O-09: a non-VAT-payer supplier issues every line under VAT category
        // "O", where the VAT category tax amount (BT-117) must be exactly 0 — the
        // schematron tests xs:decimal(cbc:TaxAmount) = 0 with no tolerance and
        // fails fatally otherwise. A supplier not registered for VAT cannot
        // legally charge it, so this is caller error rather than a lost rounding
        // penny: fail fast here instead of letting ANAF reject the submission.
        // The epsilon mirrors getTaxCategory(); the tax amount is compared as it
        // will be emitted (rounded to 2 decimals).
        if (! $isSupplierVatPayer && (abs($line->taxPercent) >= 0.01 || round($line->taxAmount, 2) != 0.0)) {
            throw new ValidationException(
                __('efactura-sdk::validation.line_tax_not_allowed_for_non_vat_payer', ['lineNum' => $lineNum])
            );
        }

        // BR-Z-09: the BR-O-09 guard above only covers the non-VAT-payer half of the same
        // requirement. A VAT-PAYER supplier declaring a 0% rate files its lines under VAT
        // category "Z", where the category tax amount (BT-117) must likewise be exactly 0 —
        // BR-Z-09 tests xs:decimal(cbc:TaxAmount) = 0 with no tolerance, and BR-CO-17 fails
        // alongside it because the amount cannot match TaxableAmount x 0%. Both are fatal, so
        // this fails fast for the same reason BR-O-09 does rather than letting ANAF reject the
        // submission. The condition mirrors getTaxCategory()'s epsilon so it binds exactly when
        // category Z (or O) is emitted, and compares the tax amount as it will be emitted
        // (rounded to 2 decimals) so a sub-cent residue that files as 0.00 is not rejected.
        if (abs($line->taxPercent) < 0.01 && round($line->taxAmount, 2) != 0.0) {
            throw new ValidationException(
                __('efactura-sdk::validation.line_tax_amount_must_be_zero_for_zero_rate', ['lineNum' => $lineNum])
            );
        }
    }

    /**
     * Group invoice lines by tax percentage for tax subtotals.
     *
     * Tax calculation approach: the group's taxable amount is the sum of the per-line net
     * amounts ROUNDED INDIVIDUALLY by calculateLineExtension(); the group's tax amount is the
     * sum of the per-line tax amounts the CALLER supplied, accumulated raw. Both sums are
     * rounded once more at the end. Tax is never recalculated from the group total.
     *
     * The per-line rounding is required rather than a compromise: BR-S-08 compares the VAT
     * category taxable amount (BT-116) against the sum of the EMITTED
     * cac:InvoiceLine/cbc:LineExtensionAmount values, each of which is itself capped at 2
     * decimals — accumulating unrounded amounts would leave BT-116 disagreeing with the very
     * lines the document carries. InvoiceData::getTotalExcludingVat() mirrors this, and
     * getTotalVat() mirrors the once-per-group tax rounding below; both are pinned against
     * generated XML in tests/Unit/Builders/InvoiceBuilderTest.php.
     *
     * The per-line tax amount is not itself emitted anywhere — EN 16931 defines no per-line VAT
     * amount — so it exists only to be accumulated here.
     *
     * Known consequence, deliberately not guarded: the group tax amount is never checked
     * against TaxableAmount x Percent. BR-CO-17/BR-S-09 tolerate a drift of strictly less than
     * 1 currency unit between the two, and a caller that pre-rounds each line's tax can
     * accumulate past that on a long enough document — 250 lines of 0.05 @19% drift by 0.125
     * (accepted), while 2000 such lines drift by exactly 1.00 and are rejected fatally. It is
     * left unguarded because the remedy is entirely the caller's and this method already
     * accommodates it: the raw per-line figures are summed and only the GROUP is rounded, so a
     * caller supplying unrounded per-line tax (0.0095 rather than 0.01) reconciles exactly, at
     * drift 0.00. A guard here would also have to reject every other group whose supplied tax
     * disagrees with rate x base by a unit or more, which is a wider change to the caller
     * contract than this grouping decision and wants its own validation step and tests.
     *
     * @param  InvoiceLineData[]  $lines  The invoice lines
     * @param  bool  $isSupplierVatPayer  Whether the supplier is a VAT payer
     * @return array<int, array{taxPercent: float, taxCategoryId: TaxCategoryId, taxableAmount: float, taxAmount: float}> Grouped tax data
     */
    private function groupLinesByTax(array $lines, bool $isSupplierVatPayer, bool $isCreditNote = false): array
    {
        /** @var array<string, array{taxPercent: float, taxCategoryId: TaxCategoryId, taxableAmount: float, taxAmount: float}> $groups */
        $groups = [];

        foreach ($lines as $line) {
            $taxPercent = $line->taxPercent;
            // Round to 2 decimal places to avoid floating-point precision issues
            // e.g., 19.0 vs 19.00000001 producing different keys
            $key = (string) round($taxPercent, 2);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'taxPercent' => $taxPercent,
                    'taxCategoryId' => $this->getTaxCategory($taxPercent, $isSupplierVatPayer),
                    'taxableAmount' => 0.0,
                    'taxAmount' => 0.0,
                ];
            }

            // Accumulate the line's net amount as EMITTED — calculateLineExtension() rounds it
            // to 2 decimals, which BR-S-08 requires (see the docblock).
            $lineAmount = $this->calculateLineExtension($line, $isCreditNote);
            $groups[$key]['taxableAmount'] += $lineAmount;

            // Accumulate pre-computed per-line tax (negate for credit notes, matching quantity sign handling)
            $lineTax = $isCreditNote ? -$line->taxAmount : $line->taxAmount;
            $groups[$key]['taxAmount'] += $lineTax;
        }

        // Round group totals once at the end
        foreach ($groups as &$group) {
            $group['taxableAmount'] = round($group['taxableAmount'], 2);
            // Use accumulated per-line tax amounts instead of recalculating from group totals
            $group['taxAmount'] = round($group['taxAmount'], 2);
        }

        return array_values($groups);
    }

    /**
     * Determine the tax category based on tax percent and VAT payer status.
     *
     * @param  float  $taxPercent  The tax percentage
     * @param  bool  $isVatPayer  Whether the party is a VAT payer
     * @return TaxCategoryId The determined tax category
     */
    private function getTaxCategory(float $taxPercent, bool $isVatPayer): TaxCategoryId
    {
        // If not a VAT payer, the tax category is "Not subject" (O)
        if (! $isVatPayer) {
            return TaxCategoryId::NotSubject;
        }

        // For VAT payers:
        // - Zero percent -> Zero-rated (Z)
        // - Greater than zero -> Standard (S)
        // Use epsilon comparison for floating-point safety (less than 0.01%)
        if (abs($taxPercent) < 0.01) {
            return TaxCategoryId::ZeroRated;
        }

        return TaxCategoryId::Standard;
    }

    /**
     * Calculate line extension amount (quantity * unit price).
     *
     * For credit notes, quantities are negated (sign-flipped) because ANAF treats
     * credit notes as inherently negative. This correctly handles mixed signs:
     * - Normal lines (qty=-1) → become +1 (ANAF credits it)
     * - Discount lines (qty=+1) → become -1 (ANAF debits the discount back)
     */
    private function calculateLineExtension(InvoiceLineData $line, bool $isCreditNote = false): float
    {
        $quantity = $isCreditNote ? -$line->quantity : $line->quantity;

        return round($quantity * $line->unitPrice, 2);
    }

    /**
     * Write a simple element with a namespace.
     */
    private function writeElement(Writer $writer, string $namespace, string $localName, string $value): void
    {
        $writer->writeElement('{'.$namespace.'}'.$localName, $value);
    }

    /**
     * Write an element with attributes.
     *
     * @param  array<string, string>  $attributes  Element attributes
     */
    private function writeElementWithAttributes(
        Writer $writer,
        string $namespace,
        string $localName,
        string $value,
        array $attributes
    ): void {
        $writer->startElement('{'.$namespace.'}'.$localName);
        foreach ($attributes as $attrName => $attrValue) {
            $writer->writeAttribute($attrName, $attrValue);
        }
        $writer->text($value);
        $writer->endElement();
    }

    /**
     * Build XML for a party (supplier or customer).
     */
    private function buildPartyXml(Writer $writer, string $tagName, PartyData $party, bool $isVatPayer): void
    {
        $writer->startElement('{'.self::NS_CAC.'}'.$tagName);
        $writer->startElement('{'.self::NS_CAC.'}Party');

        // Postal Address
        $this->buildPostalAddressXml($writer, $party->address);

        // Party Tax Scheme (VAT identification) — only for VAT payers per CIUS-RO
        if ($isVatPayer) {
            $writer->startElement('{'.self::NS_CAC.'}PartyTaxScheme');

            $companyId = $this->normalizeVatNumber($party->companyId, $party->address->countryCode);
            $this->writeElement($writer, self::NS_CBC, 'CompanyID', $companyId);

            $writer->startElement('{'.self::NS_CAC.'}TaxScheme');
            $this->writeElement($writer, self::NS_CBC, 'ID', self::VAT_SCHEME_ID);
            $writer->endElement(); // TaxScheme

            $writer->endElement(); // PartyTaxScheme
        }

        // Party Legal Entity — always use raw CUI without country prefix
        $writer->startElement('{'.self::NS_CAC.'}PartyLegalEntity');
        $this->writeElement($writer, self::NS_CBC, 'RegistrationName', $party->registrationName);
        $this->writeElement($writer, self::NS_CBC, 'CompanyID', VatNumberValidator::stripPrefix($party->companyId));
        $writer->endElement(); // PartyLegalEntity

        $writer->endElement(); // Party
        $writer->endElement(); // AccountingSupplierParty/AccountingCustomerParty
    }

    /**
     * Build postal address XML.
     */
    private function buildPostalAddressXml(Writer $writer, AddressData $address): void
    {
        $writer->startElement('{'.self::NS_CAC.'}PostalAddress');

        $this->writeElement($writer, self::NS_CBC, 'StreetName', $address->street);

        // Handle county/subdivision - sanitize for Romanian addresses
        $countrySubdivision = $this->sanitizeCountyOrSector($address);

        // BR-RO-100/101: For Bucharest (RO-B), city must be SECTOR1-6
        $cityName = $this->sanitizeCityName($address, $countrySubdivision);
        $this->writeElement($writer, self::NS_CBC, 'CityName', $cityName);

        if ($address->postalZone !== null && $address->postalZone !== '') {
            $this->writeElement($writer, self::NS_CBC, 'PostalZone', $address->postalZone);
        }

        if ($countrySubdivision !== null) {
            $this->writeElement($writer, self::NS_CBC, 'CountrySubentity', $countrySubdivision);
        }

        // Country
        $countryCode = $address->countryCode ?: self::DEFAULT_COUNTRY_CODE;
        $writer->startElement('{'.self::NS_CAC.'}Country');
        $this->writeElement($writer, self::NS_CBC, 'IdentificationCode', $countryCode);
        $writer->endElement(); // Country

        $writer->endElement(); // PostalAddress
    }

    /**
     * Sanitize city name for UBL output.
     *
     * BR-RO-100/101: For Bucharest (RO-B), city must be SECTOR1-6.
     */
    private function sanitizeCityName(AddressData $address, ?string $countrySubdivision): string
    {
        // Only apply sector formatting for Bucharest addresses
        if ($countrySubdivision !== 'RO-B') {
            return $address->city;
        }

        // Try to extract sector number from city or county
        $sectorNumber = AddressSanitizer::extractBucharestSectorNumber($address->city)
            ?? ($address->county !== null ? AddressSanitizer::extractBucharestSectorNumber($address->county) : null);

        if ($sectorNumber !== null) {
            return 'SECTOR'.$sectorNumber;
        }

        // If we can't determine sector, return city as-is (will fail ANAF validation)
        // This is intentional - user needs to provide valid sector info
        return $address->city;
    }

    /**
     * Sanitize county or extract Bucharest sector.
     */
    private function sanitizeCountyOrSector(AddressData $address): ?string
    {
        // Check if this is a Bucharest address - all Bucharest addresses use RO-B
        // (Bucharest sectors are NOT part of ISO 3166-2:RO, so all map to RO-B)
        if ($address->county !== null && AddressSanitizer::isBucharest($address->county)) {
            return 'RO-B';
        }

        // For non-Bucharest Romanian addresses, sanitize county to ISO 3166-2:RO format
        // ANAF requires ISO codes (BR-RO-111 rule) - non-compliant values cause validation errors
        if ($address->county !== null && $address->countryCode === self::DEFAULT_COUNTRY_CODE) {
            $sanitized = AddressSanitizer::sanitizeCounty($address->county);
            if ($sanitized !== null) {
                return $sanitized;
            }

            // For Romanian addresses, fail fast if county cannot be mapped to ISO code
            // This prevents ANAF BR-RO-111 validation errors at submission time
            throw new ValidationException(
                __('efactura-sdk::validation.county_invalid_iso_code', ['county' => $address->county])
            );
        }

        // For non-Romanian addresses, return county as-is (ANAF doesn't enforce ISO codes for foreign countries)
        return $address->county;
    }

    /**
     * Normalize VAT number with country prefix.
     *
     * BT-31/BT-48 (seller/buyer VAT identifier) must carry the prefix of the
     * state that ISSUED the registration. Two things make "prefix with the
     * address country code unless it already starts with it" wrong:
     *
     *  - the prefix is not always the ISO 3166-1 country code (Greece files
     *    under "EL", not "GR"), so an already-prefixed Greek id is not
     *    recognised and gets prefixed again: "GR"."EL123456789" =
     *    "GREL123456789";
     *  - a party may hold a VAT identifier issued by a state other than the one
     *    its address is in (a foreign VAT registration, or Northern Ireland's
     *    "XI" on an otherwise "GB" address), which is likewise re-prefixed.
     *
     * Either way the result is a syntactically invalid VAT identifier, so the
     * prefix is only added when the id does not already carry a recognised one.
     *
     * The mirror image of the first case is corrected rather than rejected: a caller who
     * prefixes the id with Greece's COUNTRY code ("GR123456789") instead of its VAT prefix
     * would otherwise be handed "ELGR123456789". Those country codes are deliberately absent
     * from VAT_COUNTRY_PREFIXES, precisely because no VAT id legitimately carries one, and
     * this method's job is to normalise exactly this kind of input (it already leaves malformed
     * "RO 12345678" intact rather than doubling its prefix).
     *
     * PASS VAT IDENTIFIERS WITH THEIR VAT PREFIX. One input is genuinely ambiguous, and no
     * logic here can resolve it. A French VAT is "FR" + a two-character key + 9 digits, and
     * that key MAY BE TWO LETTERS — HMRC documents "XX123456789", and France is the only
     * member state where this occurs. So a bare French id whose key happens to be "GR" is
     * indistinguishable from a Greek id carrying its country code: same 11 characters, same
     * shape, same everything.
     *
     *   normalizeVatNumber('GR123456789', 'RO')  Greek party, foreign address -> EL123456789
     *   normalizeVatNumber('GR123456789', 'FR')  bare French id, key "GR"     -> EL123456789 (wrong)
     *
     * This resolves toward the first reading: an explicit prefix is better evidence of the
     * issuing state than an address is, and a foreign VAT registration is far commoner than an
     * unprefixed French id with a letter-letter key. Gating the correction on the country code
     * would not fix the ambiguity — it would only invert which of the two we corrupt, breaking
     * the commoner case to serve the rarer one. A prefixed "FRGR123456789" is unambiguous and
     * passes through untouched, which is why the rule above is the actual fix.
     */
    private function normalizeVatNumber(string $vatNumber, ?string $countryCode): string
    {
        $vatNumber = strtoupper(trim($vatNumber));
        $countryCode = strtoupper($countryCode ?? self::DEFAULT_COUNTRY_CODE);

        $expectedPrefix = self::VAT_PREFIX_OVERRIDES[$countryCode] ?? $countryCode;

        if ($this->hasVatCountryPrefix($vatNumber, $expectedPrefix)) {
            return $vatNumber;
        }

        // Swap a country code that differs from its VAT prefix for the prefix the state
        // actually files under, rather than prefixing on top of it.
        foreach (self::VAT_PREFIX_OVERRIDES as $isoCode => $vatPrefix) {
            $remainder = substr($vatNumber, strlen($isoCode));

            if (str_starts_with($vatNumber, $isoCode) && preg_match('/^[0-9A-Z]/', $remainder) === 1) {
                return $vatPrefix.$remainder;
            }
        }

        return $expectedPrefix.$vatNumber;
    }

    /**
     * Whether a VAT identifier already carries a country prefix.
     */
    private function hasVatCountryPrefix(string $vatNumber, string $expectedPrefix): bool
    {
        // Already carries the prefix this party files under. A plain
        // str_starts_with keeps malformed-but-clearly-prefixed input intact —
        // "RO 12345678" is left alone rather than turned into "RORO 12345678".
        if (str_starts_with($vatNumber, $expectedPrefix)) {
            return true;
        }

        // Carries some OTHER recognised VAT prefix. A party may hold a VAT
        // identifier issued by a state other than the one its address is in, and
        // an explicit prefix from the caller is better evidence of the issuing
        // state than the address is; prefixing again would corrupt it.
        //
        // Requiring an alphanumeric character after the prefix keeps this branch
        // narrow. It is not airtight, and cannot be: a French VAT is "FR" + a
        // two-character key + 9 digits, and that key may be two LETTERS (HMRC
        // documents "XX123456789"; France is the only member state where this
        // happens). So a bare French id with a key of "EL" or "IT" reads as
        // already-prefixed here and is filed without its "FR".
        //
        // Resolving that needs information this method does not have — see
        // normalizeVatNumber(): pass VAT identifiers WITH their prefix, and the
        // ambiguity disappears.
        return preg_match('/^([A-Z]{2})[0-9A-Z]/', $vatNumber, $matches) === 1
            && in_array($matches[1], self::VAT_COUNTRY_PREFIXES, true);
    }

    /**
     * Build BillingReference XML for credit notes (BG-3: Preceding invoice reference).
     */
    private function buildBillingReferenceXml(Writer $writer, string $invoiceNumber): void
    {
        $writer->startElement('{'.self::NS_CAC.'}BillingReference');
        $writer->startElement('{'.self::NS_CAC.'}InvoiceDocumentReference');
        $this->writeElement($writer, self::NS_CBC, 'ID', $invoiceNumber);
        $writer->endElement(); // InvoiceDocumentReference
        $writer->endElement(); // BillingReference
    }

    /**
     * Build payment means XML (BG-16).
     *
     * Children follow the UBL 2.1 PaymentMeansType sequence — cbc:PaymentMeansCode,
     * cbc:PaymentDueDate, ..., cac:PayeeFinancialAccount — which is order-enforced
     * by the schema.
     *
     * @param  string|null  $iban  Payee account (BT-84), or null when unknown
     * @param  \Carbon\Carbon|null  $paymentDueDate  BT-9, credit notes only
     */
    private function buildPaymentMeansXml(Writer $writer, ?string $iban, ?Carbon $paymentDueDate): void
    {
        $writer->startElement('{'.self::NS_CAC.'}PaymentMeans');

        // BT-81 is mandatory in the schema (minOccurs=1) and by BR-49. Code 30
        // (credit transfer) drags in BR-61, which demands the payee account id —
        // so an instruction carrying only a due date declares code 1 instead.
        $this->writeElement(
            $writer,
            self::NS_CBC,
            'PaymentMeansCode',
            $iban !== null ? self::PAYMENT_MEANS_CREDIT_TRANSFER : self::PAYMENT_MEANS_NOT_DEFINED
        );

        if ($paymentDueDate !== null) {
            $this->writeElement($writer, self::NS_CBC, 'PaymentDueDate', $paymentDueDate->format('Y-m-d'));
        }

        if ($iban !== null) {
            $writer->startElement('{'.self::NS_CAC.'}PayeeFinancialAccount');
            $this->writeElement($writer, self::NS_CBC, 'ID', $iban);
            $writer->endElement(); // PayeeFinancialAccount
        }

        $writer->endElement(); // PaymentMeans
    }

    /**
     * Build tax total XML with subtotals.
     *
     * @param  array<int, array{taxPercent: float, taxCategoryId: TaxCategoryId, taxableAmount: float, taxAmount: float}>  $taxGroups
     */
    private function buildTaxTotalXml(Writer $writer, array $taxGroups, float $totalTaxAmount, string $currency): void
    {
        $writer->startElement('{'.self::NS_CAC.'}TaxTotal');

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'TaxAmount',
            $this->formatAmount($totalTaxAmount),
            ['currencyID' => $currency]
        );

        // Tax subtotals by tax rate
        foreach ($taxGroups as $group) {
            $writer->startElement('{'.self::NS_CAC.'}TaxSubtotal');

            $this->writeElementWithAttributes(
                $writer,
                self::NS_CBC,
                'TaxableAmount',
                $this->formatAmount($group['taxableAmount']),
                ['currencyID' => $currency]
            );

            $this->writeElementWithAttributes(
                $writer,
                self::NS_CBC,
                'TaxAmount',
                $this->formatAmount($group['taxAmount']),
                ['currencyID' => $currency]
            );

            $writer->startElement('{'.self::NS_CAC.'}TaxCategory');
            $this->writeElement($writer, self::NS_CBC, 'ID', $group['taxCategoryId']->value);
            $this->writeElement($writer, self::NS_CBC, 'Percent', $this->formatAmount($group['taxPercent']));

            if ($group['taxCategoryId'] === TaxCategoryId::NotSubject) {
                $this->writeElement($writer, self::NS_CBC, 'TaxExemptionReasonCode', 'VATEX-EU-O');
            }

            $writer->startElement('{'.self::NS_CAC.'}TaxScheme');
            $this->writeElement($writer, self::NS_CBC, 'ID', self::VAT_SCHEME_ID);
            $writer->endElement(); // TaxScheme

            $writer->endElement(); // TaxCategory
            $writer->endElement(); // TaxSubtotal
        }

        $writer->endElement(); // TaxTotal
    }

    /**
     * Build tax total XML in the accounting currency (RON) for non-RON invoices.
     *
     * Carries cbc:TaxAmount (BT-111) and nothing else, which is both required and
     * the only shape that validates:
     *  - BR-CO-14 permits a TaxTotal with no breakdown via its `or
     *    not(cac:TaxSubtotal)` clause, which is what makes a bare TaxTotal legal;
     *  - a breakdown here would instead be fatal, because the per-category rules
     *    (BR-Z-01, BR-O-01, ...) count cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory
     *    across the WHOLE document and assert `= 1`, and BR-S-08 would compare a
     *    RON-converted TaxableAmount against line sums in the document currency.
     *
     * BR-DEC-RO-15 caps BT-111 at 2 decimals, which formatAmount() enforces.
     */
    private function buildTaxTotalInAccountingCurrency(Writer $writer, float $taxAmount): void
    {
        $writer->startElement('{'.self::NS_CAC.'}TaxTotal');

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'TaxAmount',
            $this->formatAmount($taxAmount),
            ['currencyID' => self::DEFAULT_CURRENCY]
        );

        $writer->endElement(); // TaxTotal
    }

    /**
     * Build legal monetary total XML.
     */
    private function buildLegalMonetaryTotalXml(
        Writer $writer,
        float $lineExtensionAmount,
        float $taxExclusiveAmount,
        float $taxInclusiveAmount,
        string $currency
    ): void {
        $writer->startElement('{'.self::NS_CAC.'}LegalMonetaryTotal');

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'LineExtensionAmount',
            $this->formatAmount($lineExtensionAmount),
            ['currencyID' => $currency]
        );

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'TaxExclusiveAmount',
            $this->formatAmount($taxExclusiveAmount),
            ['currencyID' => $currency]
        );

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'TaxInclusiveAmount',
            $this->formatAmount($taxInclusiveAmount),
            ['currencyID' => $currency]
        );

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'PayableAmount',
            $this->formatAmount($taxInclusiveAmount),
            ['currencyID' => $currency]
        );

        $writer->endElement(); // LegalMonetaryTotal
    }

    /**
     * Build invoice or credit note line XML.
     */
    private function buildInvoiceLineXml(
        Writer $writer,
        InvoiceLineData $line,
        int $lineId,
        bool $isSupplierVatPayer,
        string $currency,
        bool $isCreditNote = false
    ): void {
        // Element names differ between Invoice and CreditNote
        $lineElement = $isCreditNote ? 'CreditNoteLine' : 'InvoiceLine';
        $quantityElement = $isCreditNote ? 'CreditedQuantity' : 'InvoicedQuantity';

        $writer->startElement('{'.self::NS_CAC.'}'.$lineElement);

        // Line ID
        $this->writeElement($writer, self::NS_CBC, 'ID', (string) ($line->id ?? $lineId));

        // Invoiced/Credited quantity — negate for credit notes (ANAF treats CN as inherently negative)
        $quantity = $isCreditNote ? -$line->quantity : $line->quantity;
        $unitCode = $line->unitCode ?: self::DEFAULT_UNIT_CODE;
        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            $quantityElement,
            $this->formatQuantity($quantity),
            ['unitCode' => $unitCode]
        );

        // Line extension amount
        $lineAmount = $this->calculateLineExtension($line, $isCreditNote);
        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'LineExtensionAmount',
            $this->formatAmount($lineAmount),
            ['currencyID' => $currency]
        );

        // Item
        $writer->startElement('{'.self::NS_CAC.'}Item');

        if ($line->description !== null && $line->description !== '') {
            $this->writeElement($writer, self::NS_CBC, 'Description', $line->description);
        }

        $this->writeElement($writer, self::NS_CBC, 'Name', $line->name);

        // Classified tax category
        $writer->startElement('{'.self::NS_CAC.'}ClassifiedTaxCategory');
        $taxCategory = $this->getTaxCategory($line->taxPercent, $isSupplierVatPayer);
        $this->writeElement($writer, self::NS_CBC, 'ID', $taxCategory->value);

        // BR-O-05: an invoice line with VAT category "O" (Not subject to VAT) must
        // not carry an invoiced item VAT rate (BT-152). The schematron asserts
        // not(cbc:Percent) on such a line and fails fatally otherwise, which
        // rejects every invoice issued by a non-VAT-payer supplier.
        if ($taxCategory !== TaxCategoryId::NotSubject) {
            $this->writeElement($writer, self::NS_CBC, 'Percent', $this->formatAmount($line->taxPercent));
        }

        $writer->startElement('{'.self::NS_CAC.'}TaxScheme');
        $this->writeElement($writer, self::NS_CBC, 'ID', self::VAT_SCHEME_ID);
        $writer->endElement(); // TaxScheme

        $writer->endElement(); // ClassifiedTaxCategory
        $writer->endElement(); // Item

        // Price
        $writer->startElement('{'.self::NS_CAC.'}Price');
        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'PriceAmount',
            $this->formatQuantity($line->unitPrice),
            ['currencyID' => $currency]
        );
        $writer->endElement(); // Price

        $writer->endElement(); // InvoiceLine or CreditNoteLine
    }

    /**
     * Format a monetary amount with 2 decimal places.
     *
     * The EN 16931 BR-DEC-* rules cap the number of decimals at 2 for amount
     * fields (line net amount, VAT amounts, document totals).
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Format a quantity (BT-129/BT-130) or item net price (BT-146).
     *
     * These are NOT amount fields: the BR-DEC-* rules cap decimals for monetary
     * amounts only, and EN 16931 explicitly allows quantities and unit prices to
     * carry more precision. Formatting them as money corrupts the document —
     * a quantity of 1.375 rendered as "1.38" no longer reconciles with a line
     * net amount computed from the true 1.375, and a unit price of 0.0075
     * rendered as "0.01" overstates the line by 33%.
     *
     * Keeps up to self::QUANTITY_PRECISION decimals and trims trailing zeros
     * beyond the second, so whole values still render conventionally ("5.00")
     * while fractional ones keep their precision ("1.375", "0.0075").
     *
     * number_format() is used rather than a string cast because casting relies
     * on precision ini settings and emits scientific notation for small values
     * ((string) 0.0000075 === "7.5E-6"), which is not a valid xsd:decimal.
     */
    private function formatQuantity(float $value): string
    {
        $formatted = number_format($value, self::QUANTITY_PRECISION, '.', '');

        // "1.375000" -> "1.375", "5.000000" -> "5." (the '.' stops the trim)
        $formatted = rtrim($formatted, '0');

        $parts = explode('.', $formatted, 2);

        // Pad back to a minimum of 2 decimals: "5." -> "5.00"
        return $parts[0].'.'.str_pad($parts[1] ?? '', 2, '0');
    }
}
