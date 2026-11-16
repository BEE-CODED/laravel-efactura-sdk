<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Builders;

use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\DeliveryData;
use BeeCoded\EFacturaSdk\Data\Invoice\ExemptionReasonData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Data\Invoice\PrecedingInvoiceData;
use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Support\AddressSanitizer;
use BeeCoded\EFacturaSdk\Support\Amount;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Sabre\Xml\Service as XmlService;
use Sabre\Xml\Writer;
use Spatie\LaravelData\Data;

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
     * Maximum decimals retained for quantities (BT-129/BT-130), item net prices
     * (BT-146) and the caller's per-line tax amount.
     *
     * Six covers the real-world cases that 2 decimals destroy — per-unit pricing
     * below a bani (telecom, energy, per-page/per-SMS tariffs) and fine-grained
     * quantities (grams billed in KGM, hours billed in fractions). A value with more
     * decimals is refused by Amount::parse() rather than rounded.
     */
    private const QUANTITY_PRECISION = 6;

    /**
     * Decimals of every monetary amount, rate, BT-111 and BT-114 (BR-DEC-*).
     */
    private const AMOUNT_PRECISION = 2;

    /**
     * VAT point date codes (BT-8, UNTDID 2005 subset): issue date, delivery date, payment date.
     */
    private const VAT_POINT_DATE_CODES = ['3', '35', '432'];

    /**
     * Exemption reason codes fixed by the category (BT-121); the caller may add only a text.
     *
     * @var array<string, string>
     */
    private const FIXED_EXEMPTION_CODES = [
        'AE' => 'VATEX-EU-AE',
        'G' => 'VATEX-EU-G',
        'K' => 'VATEX-EU-IC',
    ];

    /**
     * The CEF VATEX code list as ANAF's ro16931-ubl 1.0.9 bundle checks it (BR-CL-22).
     */
    private const VATEX_CODES = [
        'VATEX-EU-79-C',
        'VATEX-EU-132', 'VATEX-EU-132-1A', 'VATEX-EU-132-1B', 'VATEX-EU-132-1C', 'VATEX-EU-132-1D',
        'VATEX-EU-132-1E', 'VATEX-EU-132-1F', 'VATEX-EU-132-1G', 'VATEX-EU-132-1H', 'VATEX-EU-132-1I',
        'VATEX-EU-132-1J', 'VATEX-EU-132-1K', 'VATEX-EU-132-1L', 'VATEX-EU-132-1M', 'VATEX-EU-132-1N',
        'VATEX-EU-132-1O', 'VATEX-EU-132-1P', 'VATEX-EU-132-1Q',
        'VATEX-EU-143', 'VATEX-EU-143-1A', 'VATEX-EU-143-1B', 'VATEX-EU-143-1C', 'VATEX-EU-143-1D',
        'VATEX-EU-143-1E', 'VATEX-EU-143-1F', 'VATEX-EU-143-1FA', 'VATEX-EU-143-1G', 'VATEX-EU-143-1H',
        'VATEX-EU-143-1I', 'VATEX-EU-143-1J', 'VATEX-EU-143-1K', 'VATEX-EU-143-1L',
        'VATEX-EU-148', 'VATEX-EU-148-A', 'VATEX-EU-148-B', 'VATEX-EU-148-C', 'VATEX-EU-148-D',
        'VATEX-EU-148-E', 'VATEX-EU-148-F', 'VATEX-EU-148-G',
        'VATEX-EU-151', 'VATEX-EU-151-1A', 'VATEX-EU-151-1AA', 'VATEX-EU-151-1B', 'VATEX-EU-151-1C',
        'VATEX-EU-151-1D', 'VATEX-EU-151-1E',
        'VATEX-EU-309', 'VATEX-EU-AE', 'VATEX-EU-D', 'VATEX-EU-F', 'VATEX-EU-G', 'VATEX-EU-I',
        'VATEX-EU-IC', 'VATEX-EU-J', 'VATEX-EU-O',
    ];

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
        $payableRoundingAmount = Amount::parse($input->payableRoundingAmount ?? 0, self::AMOUNT_PRECISION, 'payableRoundingAmount');

        $currency = $input->currency;
        $isSupplierVatPayer = $input->supplier->isVatPayer;
        $isCreditNote = $input->getInvoiceTypeCode()->isCreditNote();

        // Group lines by resolved VAT category and normalized percentage
        $taxGroups = $this->groupLinesByTax($input->lines, $isSupplierVatPayer, $isCreditNote);

        // Calculate totals as exact decimal sums of the 2-decimal amounts the XML files
        $lineExtensionAmount = BigDecimal::zero();
        $totalTaxAmount = BigDecimal::zero();

        foreach ($input->lines as $line) {
            $lineExtensionAmount = $lineExtensionAmount->plus($this->calculateLineExtension($line, $isCreditNote));
        }

        foreach ($taxGroups as $group) {
            $totalTaxAmount = $totalTaxAmount->plus($group['taxAmount']);
        }

        $taxExclusiveAmount = $lineExtensionAmount;
        $taxInclusiveAmount = $taxExclusiveAmount->plus($totalTaxAmount);
        $payableRoundingAmount = $isCreditNote ? $payableRoundingAmount->negated() : $payableRoundingAmount;
        $payableAmount = $taxInclusiveAmount->plus($payableRoundingAmount);

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
            /** @var Carbon $dueDate PHPStan: guaranteed non-null by guard clause above */
            $dueDate = $input->getDueDateAsCarbon();
            $this->writeElement($writer, self::NS_CBC, 'DueDate', $dueDate->format('Y-m-d'));
        }

        // UBL CreditNote places BT-7 before its type code; Invoice places it after its notes.
        $taxPointDate = $input->getTaxPointDateAsCarbon();
        if ($isCreditNote && $taxPointDate !== null) {
            $this->writeElement($writer, self::NS_CBC, 'TaxPointDate', $taxPointDate->format('Y-m-d'));
        }

        // Type code element name differs between Invoice and CreditNote
        $typeCodeElement = $isCreditNote ? 'CreditNoteTypeCode' : 'InvoiceTypeCode';
        $this->writeElement($writer, self::NS_CBC, $typeCodeElement, $input->getInvoiceTypeCode()->value);

        // BT-22: both schemas place cbc:Note right after the type code.
        foreach ($input->notes as $note) {
            $this->writeElement($writer, self::NS_CBC, 'Note', $note);
        }

        if (! $isCreditNote && $taxPointDate !== null) {
            $this->writeElement($writer, self::NS_CBC, 'TaxPointDate', $taxPointDate->format('Y-m-d'));
        }
        $this->writeElement($writer, self::NS_CBC, 'DocumentCurrencyCode', $currency);

        // BR-RO-030: If currency is not RON, TaxCurrencyCode must be RON
        if ($currency !== self::DEFAULT_CURRENCY) {
            $this->writeElement($writer, self::NS_CBC, 'TaxCurrencyCode', self::DEFAULT_CURRENCY);
        }

        // BT-8 VAT point date code (BG-14 invoicing period)
        if ($input->vatPointDateCode !== null) {
            $writer->startElement('{'.self::NS_CAC.'}InvoicePeriod');
            $this->writeElement($writer, self::NS_CBC, 'DescriptionCode', $input->vatPointDateCode);
            $writer->endElement(); // InvoicePeriod
        }

        // BG-3: Preceding invoice references
        foreach ($input->getPrecedingInvoices() as $precedingInvoice) {
            $this->buildBillingReferenceXml($writer, $precedingInvoice);
        }

        // Write supplier party
        $this->buildPartyXml($writer, 'AccountingSupplierParty', $input->supplier, $isSupplierVatPayer, fileLegalForm: true);

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

        // BG-13 Delivery information
        if ($input->delivery !== null) {
            $this->buildDeliveryXml($writer, $input->delivery);
        }

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
        $this->buildTaxTotalXml($writer, $taxGroups, $totalTaxAmount, $currency, $this->exemptionReasonsByCategory($input));

        // Add second TaxTotal in RON for non-RON invoices (BR-53). Only for
        // non-RON: BR-CO-15 asserts exactly one TaxTotal in the document
        // currency, so a RON invoice must not get a second one.
        if ($currency !== self::DEFAULT_CURRENCY) {
            // Sign-flipped for credit notes exactly as the per-line tax amounts
            // are, so BT-111 agrees with the document-currency total it converts.
            // Non-null: guaranteed by validateTaxAccountingCurrency().
            $taxAmountRon = Amount::parse($input->taxAmountRon, self::AMOUNT_PRECISION, 'taxAmountRon');
            $this->buildTaxTotalInAccountingCurrency($writer, $isCreditNote ? $taxAmountRon->negated() : $taxAmountRon);
        }

        // Write monetary total
        $this->buildLegalMonetaryTotalXml(
            $writer,
            $lineExtensionAmount,
            $taxExclusiveAmount,
            $taxInclusiveAmount,
            $currency,
            $payableRoundingAmount,
            $payableAmount
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
        // S16: every string must be text XML can carry, before anything reads it.
        $this->validateXmlText($input, '');

        if (trim($input->invoiceNumber) === '') {
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

        if (is_string($input->issueDate) && trim($input->issueDate) === '') {
            throw new ValidationException(__('efactura-sdk::validation.issue_date_required'));
        }

        // BR-CL-04: ISO 4217 is uppercase; the list itself is ANAF's to check.
        if (preg_match('/^[A-Z]{3}$/D', $input->currency) !== 1) {
            throw new ValidationException(__('efactura-sdk::validation.currency_code_invalid'));
        }

        $this->validateParty($input->supplier, 'Supplier');
        $this->validateParty($input->customer, 'Customer');

        // BR-RO-L1000: seller additional legal information (BT-33)
        if ($input->supplier->registrationNumber !== null && mb_strlen($input->supplier->registrationNumber) > 1000) {
            throw new ValidationException(__('efactura-sdk::validation.party_registration_number_max_length', ['role' => 'Supplier']));
        }

        if ($input->lines === []) {
            throw new ValidationException(__('efactura-sdk::validation.at_least_one_line_required'));
        }

        // BR-RO-A999: at most 999 lines
        if (count($input->lines) > 999) {
            throw new ValidationException(__('efactura-sdk::validation.lines_max_count'));
        }

        foreach ($input->lines as $index => $line) {
            $this->validateLine($line, $index, $input->supplier->isVatPayer);
        }

        $categories = array_map(fn (InvoiceLineData $line): TaxCategoryId => $line->getTaxCategory($input->supplier->isVatPayer), $input->lines);
        $this->validateTaxCategories($input, $categories);
        $this->validatePrecedingInvoices($input);
        $this->validateNotesAndDelivery($input);
        $this->validateTaxAccountingCurrency($input, in_array(TaxCategoryId::Standard, $categories, true));

        // BR-CO-25 (fatal on the Invoice root only): a positive amount due needs BT-9; a blank one is none.
        $dueDateMissing = $input->dueDate === null || (is_string($input->dueDate) && trim($input->dueDate) === '');
        if (! $input->getInvoiceTypeCode()->isCreditNote() && $dueDateMissing && $input->getTotalIncludingVat() > 0) {
            throw new ValidationException(__('efactura-sdk::validation.due_date_required_for_positive_payable'));
        }
    }

    /**
     * Refuse invalid UTF-8 and characters outside the XML 1.0 Char production in any string
     * of the input, naming its path ("lines.0.name"). XMLWriter would otherwise drop text
     * after a NUL, replace C0 controls with U+FFFD, or emit a document that is not well-formed.
     *
     * @throws ValidationException
     */
    private function validateXmlText(mixed $value, string $path): void
    {
        if (is_string($value)) {
            if (preg_match('/^[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/uD', $value) !== 1) {
                throw new ValidationException(__('efactura-sdk::validation.xml_text_invalid', ['field' => $path]));
            }

            return;
        }

        if ($value instanceof Data || is_array($value)) {
            // get_object_vars() from this scope sees only the DTO's public properties.
            foreach ($value instanceof Data ? get_object_vars($value) : $value as $key => $item) {
                $this->validateXmlText($item, ltrim($path.'.'.$key, '.'));
            }
        }
    }

    /**
     * Identifier, exemption-reason and delivery requirements of the declared VAT categories.
     * This checks usable identifiers, not national checksums or legal eligibility.
     *
     * @param  array<TaxCategoryId>  $categories  The category of every line
     *
     * @throws ValidationException
     */
    private function validateTaxCategories(InvoiceData $input, array $categories): void
    {
        // AE and K need both VAT identifiers (BR-AE-02, BR-IC-02); E and G the seller's.
        $parties = [
            TaxCategoryId::ReverseCharge->value => ['Supplier' => $input->supplier, 'Customer' => $input->customer],
            TaxCategoryId::IntraCommunitySupply->value => ['Supplier' => $input->supplier, 'Customer' => $input->customer],
            TaxCategoryId::Exempt->value => ['Supplier' => $input->supplier],
            TaxCategoryId::Export->value => ['Supplier' => $input->supplier],
        ];
        foreach ($parties as $category => $roles) {
            if (! in_array(TaxCategoryId::from($category), $categories, true)) {
                continue;
            }

            foreach ($roles as $role => $party) {
                $vatNumber = VatNumberValidator::withCountryPrefix($party->companyId, $party->address->countryCode);
                if (! $party->isVatPayer || preg_match('/^[A-Z]{2}[A-Z0-9]+$/D', $vatNumber) !== 1) {
                    throw new ValidationException(__('efactura-sdk::validation.category_vat_identifier_required', ['role' => $role, 'category' => $category]));
                }
            }
        }

        $reasons = [];
        foreach ($input->exemptionReasons as $reason) {
            $category = $reason->category->value;
            $code = $this->filled($reason->code);
            $fixedCode = self::FIXED_EXEMPTION_CODES[$category] ?? null;

            if (! in_array($reason->category, [TaxCategoryId::Exempt, TaxCategoryId::Export, TaxCategoryId::IntraCommunitySupply, TaxCategoryId::ReverseCharge], true)) {
                throw new ValidationException(__('efactura-sdk::validation.exemption_reason_category_invalid', ['category' => $category]));
            }
            if (isset($reasons[$category])) {
                throw new ValidationException(__('efactura-sdk::validation.exemption_reason_duplicate', ['category' => $category]));
            }
            if (! in_array($reason->category, $categories, true)) {
                throw new ValidationException(__('efactura-sdk::validation.exemption_reason_without_lines', ['category' => $category]));
            }
            if ($fixedCode !== null && $code !== null && $code !== $fixedCode) {
                throw new ValidationException(__('efactura-sdk::validation.exemption_reason_code_fixed', ['category' => $category, 'code' => $fixedCode]));
            }
            if ($fixedCode === null && $code !== null && ! in_array($code, self::VATEX_CODES, true)) {
                throw new ValidationException(__('efactura-sdk::validation.exemption_reason_code_invalid', ['code' => $code]));
            }
            // BR-RO-L100
            if ($reason->text !== null && mb_strlen($reason->text) > 100) {
                throw new ValidationException(__('efactura-sdk::validation.exemption_reason_text_max_length', ['category' => $category]));
            }

            $reasons[$category] = $reason;
        }

        // BR-E-10: E has no fixed code, so the caller must state why the supply is exempt.
        $exempt = $reasons[TaxCategoryId::Exempt->value] ?? null;
        if (in_array(TaxCategoryId::Exempt, $categories, true) && ($exempt === null || ($this->filled($exempt->code) ?? $this->filled($exempt->text)) === null)) {
            throw new ValidationException(__('efactura-sdk::validation.exemption_reason_required'));
        }

        // BR-IC-11/12 accept an invoicing period or a country alone; this SDK deliberately
        // requires the actual delivery date and a full delivery address for K.
        if (in_array(TaxCategoryId::IntraCommunitySupply, $categories, true)
            && ($input->delivery?->actualDeliveryDate === null || $input->delivery->address === null)) {
            throw new ValidationException(__('efactura-sdk::validation.intra_community_delivery_required'));
        }
    }

    /**
     * BG-3: at most 500 references (BR-RO-A500), each with a non-blank number (BR-55) of at
     * most 200 characters (BR-RO-L200) and a parseable optional date.
     *
     * @throws ValidationException
     */
    private function validatePrecedingInvoices(InvoiceData $input): void
    {
        $precedingInvoices = $input->getPrecedingInvoices();
        if (count($precedingInvoices) > 500) {
            throw new ValidationException(__('efactura-sdk::validation.preceding_invoices_max_count'));
        }

        foreach ($precedingInvoices as $precedingInvoice) {
            if (trim($precedingInvoice->number) === '') {
                throw new ValidationException(__('efactura-sdk::validation.preceding_invoice_number_required'));
            }
            if (mb_strlen($precedingInvoice->number) > 200) {
                throw new ValidationException(__('efactura-sdk::validation.preceding_invoice_number_max_length'));
            }
            try {
                $precedingInvoice->getIssueDateAsCarbon();
            } catch (\InvalidArgumentException $e) {
                throw new ValidationException(__('efactura-sdk::validation.preceding_invoice_issue_date_invalid', ['date' => (string) $precedingInvoice->issueDate]), previous: $e);
            }
        }
    }

    /**
     * The actual delivery date (BT-72), or null when there is none.
     *
     * @throws ValidationException When it is blank or not a date
     */
    private function actualDeliveryDate(InvoiceData $input): ?Carbon
    {
        try {
            return $input->delivery?->getActualDeliveryDateAsCarbon();
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(__('efactura-sdk::validation.actual_delivery_date_invalid', ['date' => (string) $input->delivery->actualDeliveryDate]), previous: $e);
        }
    }

    /**
     * Notes (BT-22), the VAT point date code (BT-8) and the delivery (BG-13).
     *
     * @throws ValidationException
     */
    private function validateNotesAndDelivery(InvoiceData $input): void
    {
        if (count($input->notes) > 20) {
            throw new ValidationException(__('efactura-sdk::validation.notes_max_count'));
        }

        foreach ($input->notes as $index => $note) {
            if (trim($note) === '') {
                throw new ValidationException(__('efactura-sdk::validation.note_required', ['index' => $index + 1]));
            }
            // BR-RO-L300
            if (mb_strlen($note) > 300) {
                throw new ValidationException(__('efactura-sdk::validation.note_max_length', ['index' => $index + 1]));
            }
        }

        if ($input->vatPointDateCode !== null) {
            if (! in_array($input->vatPointDateCode, self::VAT_POINT_DATE_CODES, true)) {
                throw new ValidationException(__('efactura-sdk::validation.vat_point_date_code_invalid'));
            }
            if ($input->taxPointDate !== null) {
                throw new ValidationException(__('efactura-sdk::validation.vat_point_date_code_with_tax_point_date'));
            }
        }

        $this->actualDeliveryDate($input);
        if ($input->delivery?->address !== null) {
            // A deliver-to address needs a street, city and county for every country.
            $this->validateAddress($input->delivery->address, 'Delivery', requireCounty: true);
        }
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
     * Without a standard-rated line every breakdown files zero VAT (BR-Z/O/E/G/IC/AE-09),
     * so BT-111 must be exactly zero too.
     *
     * @throws ValidationException If validation fails
     */
    private function validateTaxAccountingCurrency(InvoiceData $input, bool $hasStandard): void
    {
        if ($input->currency === self::DEFAULT_CURRENCY) {
            if ($input->taxAmountRon !== null) {
                throw new ValidationException(__('efactura-sdk::validation.tax_amount_ron_not_allowed_for_ron'));
            }

            return;
        }

        if ($input->taxAmountRon === null) {
            throw new ValidationException(
                __('efactura-sdk::validation.tax_amount_ron_required', ['currency' => $input->currency])
            );
        }

        $taxAmountRon = Amount::parse($input->taxAmountRon, self::AMOUNT_PRECISION, 'taxAmountRon');
        if (! $hasStandard && ! $taxAmountRon->isZero()) {
            throw new ValidationException(__('efactura-sdk::validation.tax_amount_ron_must_be_zero'));
        }

        // A converted total must agree in sign with the total it converts. This
        // cannot verify the RATE (that is the caller's BNR figure), but it does
        // catch a credited VAT filed as collected, and vice versa.
        // Either total can round to zero independently when the caller converts
        // the taxable base before rounding VAT in the accounting currency.
        $ronSign = $taxAmountRon->getSign();
        $documentSign = $input->getTotalVat() <=> 0.0;
        if ($ronSign !== 0 && $documentSign !== 0 && $ronSign !== $documentSign) {
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
        if (trim($party->registrationName) === '') {
            throw new ValidationException(__('efactura-sdk::validation.party_registration_name_required', ['role' => $role]));
        }

        // BR-RO-L200: Registration name max 200 characters
        if (mb_strlen($party->registrationName) > 200) {
            throw new ValidationException(__('efactura-sdk::validation.party_registration_name_max_length', ['role' => $role]));
        }

        if (trim($party->companyId) === '') {
            throw new ValidationException(__('efactura-sdk::validation.party_company_id_required', ['role' => $role]));
        }

        $this->validateAddress($party->address, $role);
    }

    /**
     * Validate address data.
     *
     * @param  bool  $requireCounty  Require a county for every country, not only RO
     *
     * @throws ValidationException If validation fails
     */
    private function validateAddress(AddressData $address, string $role, bool $requireCounty = false): void
    {
        if (trim($address->street) === '') {
            throw new ValidationException(__('efactura-sdk::validation.party_street_required', ['role' => $role]));
        }

        // BR-RO-L150: Address line 1 max 150 characters
        if (mb_strlen($address->street) > 150) {
            throw new ValidationException(__('efactura-sdk::validation.party_street_max_length', ['role' => $role]));
        }

        if (trim($address->city) === '') {
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

        // BR-CL-14: ISO 3166-1 alpha-2 is uppercase; the list itself is ANAF's to check.
        if (preg_match('/^[A-Z]{2}$/D', $address->countryCode) !== 1) {
            throw new ValidationException(__('efactura-sdk::validation.country_code_invalid', ['role' => $role]));
        }

        if (trim($address->county ?? '') === '') {
            // BR-RO-110/111: Romanian addresses require CountrySubentity (county)
            if ($requireCounty) {
                throw new ValidationException(__('efactura-sdk::validation.delivery_county_required'));
            }
            if ($address->countryCode === self::DEFAULT_COUNTRY_CODE) {
                throw new ValidationException(__('efactura-sdk::validation.party_county_required_ro', ['role' => $role]));
            }
        }
    }

    /**
     * Validate invoice line data.
     *
     * Every amount is parsed first, at the precision it files with, so a value that cannot be
     * filed exactly is refused with its path ("lines.0.quantity") before anything uses it.
     *
     * @param  bool  $isSupplierVatPayer  Whether the supplier is a VAT payer
     *
     * @throws ValidationException If validation fails
     */
    private function validateLine(InvoiceLineData $line, int $index, bool $isSupplierVatPayer = true): void
    {
        $lineNum = $index + 1;
        $quantity = Amount::parse($line->quantity, self::QUANTITY_PRECISION, "lines.$index.quantity");
        $unitPrice = Amount::parse($line->unitPrice, self::QUANTITY_PRECISION, "lines.$index.unitPrice");
        $taxAmount = Amount::parse($line->taxAmount, self::QUANTITY_PRECISION, "lines.$index.taxAmount");
        $taxPercent = Amount::parse($line->taxPercent, self::AMOUNT_PRECISION, "lines.$index.taxPercent");
        $line->getTaxCategory($isSupplierVatPayer);

        if (trim($line->name) === '') {
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
        if ($quantity->isZero()) {
            throw new ValidationException(__('efactura-sdk::validation.line_quantity_cannot_be_zero', ['lineNum' => $lineNum]));
        }

        if ($unitPrice->isNegative()) {
            throw new ValidationException(__('efactura-sdk::validation.line_unit_price_not_negative', ['lineNum' => $lineNum]));
        }

        if ($taxPercent->isNegative() || $taxPercent->isGreaterThan(100)) {
            throw new ValidationException(__('efactura-sdk::validation.line_tax_percent_range', ['lineNum' => $lineNum]));
        }

        // BR-O-09: a non-VAT-payer supplier issues every line under VAT category
        // "O", where the VAT category tax amount (BT-117) must be exactly 0 — the
        // schematron tests xs:decimal(cbc:TaxAmount) = 0 with no tolerance and
        // fails fatally otherwise. A supplier not registered for VAT cannot
        // legally charge it, so this is caller error rather than a lost rounding
        // penny: fail fast here instead of letting ANAF reject the submission.
        // The tax amount is compared as it will be emitted (rounded to 2 decimals).
        $emitsTax = ! $taxAmount->toScale(self::AMOUNT_PRECISION, RoundingMode::HalfUp)->isZero();
        if (! $isSupplierVatPayer && (! $taxPercent->isZero() || $emitsTax)) {
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
        // submission. A sub-cent residue that files as 0.00 is not rejected here; the group
        // check in groupLinesByTax() catches residues that add up to a cent.
        if ($taxPercent->isZero() && $emitsTax) {
            throw new ValidationException(
                __('efactura-sdk::validation.line_tax_amount_must_be_zero_for_zero_rate', ['lineNum' => $lineNum])
            );
        }
    }

    /**
     * Group invoice lines by VAT category and normalized percentage for tax subtotals.
     *
     * Tax calculation approach: the group's taxable amount is the sum of the per-line net
     * amounts ROUNDED INDIVIDUALLY by calculateLineExtension(); the group's tax amount is the
     * sum of the per-line tax amounts the CALLER supplied, accumulated raw and rounded half-up
     * once at the end. Both sums are exact decimals of the amounts read at 6 decimals, so a
     * half-cent sum (1285.8489 + 130.7061) files as 1416.56. Tax is never recalculated from
     * the group total.
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
     * amount — so it exists only to be accumulated here. Outside S (and AE, whose lines are
     * exactly zero) every breakdown must file exactly zero VAT (BR-Z/O/E/G/IC-09), so sub-cent
     * residues that add up to a cent there are refused.
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
     * @return array<int, array{taxPercent: BigDecimal, taxCategoryId: TaxCategoryId, taxableAmount: BigDecimal, taxAmount: BigDecimal}> Grouped tax data
     *
     * @throws ValidationException If a zero-VAT breakdown sums to a non-zero amount
     */
    private function groupLinesByTax(array $lines, bool $isSupplierVatPayer, bool $isCreditNote = false): array
    {
        /** @var array<string, array{taxPercent: BigDecimal, taxCategoryId: TaxCategoryId, taxableAmount: BigDecimal, taxAmount: BigDecimal}> $groups */
        $groups = [];

        foreach ($lines as $line) {
            $key = $line->getTaxGroupKey($isSupplierVatPayer);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'taxPercent' => Amount::parse($line->taxPercent, self::AMOUNT_PRECISION, 'taxPercent'),
                    'taxCategoryId' => $line->getTaxCategory($isSupplierVatPayer),
                    'taxableAmount' => BigDecimal::zero(),
                    'taxAmount' => BigDecimal::zero(),
                ];
            }

            // Accumulate the line's net amount as EMITTED — calculateLineExtension() rounds it
            // to 2 decimals, which BR-S-08 requires (see the docblock).
            $lineAmount = $this->calculateLineExtension($line, $isCreditNote);
            $groups[$key]['taxableAmount'] = $groups[$key]['taxableAmount']->plus($lineAmount);

            // Accumulate pre-computed per-line tax (negate for credit notes, matching quantity sign handling)
            $lineTax = Amount::parse($line->taxAmount, self::QUANTITY_PRECISION, 'taxAmount');
            $groups[$key]['taxAmount'] = $groups[$key]['taxAmount']->plus($isCreditNote ? $lineTax->negated() : $lineTax);
        }

        // Round each group's tax once at the end; the taxable sum of 2-decimal nets is already exact
        foreach ($groups as &$group) {
            // Use accumulated per-line tax amounts instead of recalculating from group totals
            $group['taxAmount'] = $group['taxAmount']->toScale(self::AMOUNT_PRECISION, RoundingMode::HalfUp);

            if ($group['taxCategoryId'] !== TaxCategoryId::Standard && ! $group['taxAmount']->isZero()) {
                throw new ValidationException(__('efactura-sdk::validation.zero_vat_group_tax_amount_must_be_zero', ['category' => $group['taxCategoryId']->value]));
            }
        }

        return array_values($groups);
    }

    /**
     * Calculate line extension amount (quantity * unit price).
     *
     * Exact decimal: quantity and unit price are read at the 6 decimals the line files
     * (BT-129, BT-146) and the product is rounded half-up once, so the filed amount always
     * reconciles with them — 3 x 1.005 files 3.02 (a float product, 3.0149999..., gave 3.01).
     *
     * For credit notes, quantities are negated (sign-flipped) because ANAF treats
     * credit notes as inherently negative. This correctly handles mixed signs:
     * - Normal lines (qty=-1) → become +1 (ANAF credits it)
     * - Discount lines (qty=+1) → become -1 (ANAF debits the discount back)
     * Half-up rounds ties away from zero, so negating the rounded product is the same.
     */
    private function calculateLineExtension(InvoiceLineData $line, bool $isCreditNote = false): BigDecimal
    {
        $amount = Amount::parse($line->quantity, self::QUANTITY_PRECISION, 'quantity')
            ->multipliedBy(Amount::parse($line->unitPrice, self::QUANTITY_PRECISION, 'unitPrice'))
            ->toScale(self::AMOUNT_PRECISION, RoundingMode::HalfUp);

        return $isCreditNote ? $amount->negated() : $amount;
    }

    /** The value, or null when it is null or blank. */
    private function filled(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    /**
     * @return array<string, ExemptionReasonData> keyed by category code (validated unique)
     */
    private function exemptionReasonsByCategory(InvoiceData $input): array
    {
        $reasons = [];
        foreach ($input->exemptionReasons as $reason) {
            $reasons[$reason->category->value] = $reason;
        }

        return $reasons;
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
     *
     * @param  bool  $fileLegalForm  File the registration number as BT-33 (seller only)
     */
    private function buildPartyXml(Writer $writer, string $tagName, PartyData $party, bool $isVatPayer, bool $fileLegalForm = false): void
    {
        $writer->startElement('{'.self::NS_CAC.'}'.$tagName);
        $writer->startElement('{'.self::NS_CAC.'}Party');

        // Postal Address
        $this->buildPostalAddressXml($writer, $party->address);

        // Party Tax Scheme (VAT identification) — only for VAT payers per CIUS-RO
        if ($isVatPayer) {
            $writer->startElement('{'.self::NS_CAC.'}PartyTaxScheme');

            $companyId = VatNumberValidator::withCountryPrefix($party->companyId, $party->address->countryCode);
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

        // BT-33 seller additional legal information (e.g. the trade register number). The
        // buyer has no such term: UBL-CR-244 flags a buyer CompanyLegalForm.
        if ($fileLegalForm && $this->filled($party->registrationNumber) !== null) {
            $this->writeElement($writer, self::NS_CBC, 'CompanyLegalForm', (string) $party->registrationNumber);
        }
        $writer->endElement(); // PartyLegalEntity

        $writer->endElement(); // Party
        $writer->endElement(); // AccountingSupplierParty/AccountingCustomerParty
    }

    /**
     * Build a postal address (cac:PostalAddress, or cac:Address inside a delivery location).
     *
     * The county and city are filed as AddressSanitizer::canonicalize() returns them:
     * BR-RO-100/101/110/111 (and BR-RO-200/210 for a delivery) bind only to RO addresses.
     */
    private function buildPostalAddressXml(Writer $writer, AddressData $address, string $element = 'PostalAddress'): void
    {
        ['county' => $county, 'city' => $city] = AddressSanitizer::canonicalize($address);

        $writer->startElement('{'.self::NS_CAC.'}'.$element);

        $this->writeElement($writer, self::NS_CBC, 'StreetName', $address->street);
        $this->writeElement($writer, self::NS_CBC, 'CityName', $city);

        if ($address->postalZone !== null && $address->postalZone !== '') {
            $this->writeElement($writer, self::NS_CBC, 'PostalZone', $address->postalZone);
        }

        if ($county !== null) {
            $this->writeElement($writer, self::NS_CBC, 'CountrySubentity', $county);
        }

        $writer->startElement('{'.self::NS_CAC.'}Country');
        $this->writeElement($writer, self::NS_CBC, 'IdentificationCode', $address->countryCode);
        $writer->endElement(); // Country

        $writer->endElement(); // PostalAddress or Address
    }

    /**
     * Build BillingReference XML (BG-3: preceding invoice reference).
     */
    private function buildBillingReferenceXml(Writer $writer, PrecedingInvoiceData $precedingInvoice): void
    {
        $writer->startElement('{'.self::NS_CAC.'}BillingReference');
        $writer->startElement('{'.self::NS_CAC.'}InvoiceDocumentReference');
        $this->writeElement($writer, self::NS_CBC, 'ID', $precedingInvoice->number);

        $issueDate = $precedingInvoice->getIssueDateAsCarbon();
        if ($issueDate !== null) {
            $this->writeElement($writer, self::NS_CBC, 'IssueDate', $issueDate->format('Y-m-d'));
        }
        $writer->endElement(); // InvoiceDocumentReference
        $writer->endElement(); // BillingReference
    }

    /**
     * Build Delivery XML (BG-13): cbc:ActualDeliveryDate (BT-72), then
     * cac:DeliveryLocation/cac:Address (BG-15), whose country is BT-80.
     */
    private function buildDeliveryXml(Writer $writer, DeliveryData $delivery): void
    {
        $date = $delivery->getActualDeliveryDateAsCarbon();
        if ($date === null && $delivery->address === null) {
            return;
        }

        $writer->startElement('{'.self::NS_CAC.'}Delivery');

        if ($date !== null) {
            $this->writeElement($writer, self::NS_CBC, 'ActualDeliveryDate', $date->format('Y-m-d'));
        }

        if ($delivery->address !== null) {
            $writer->startElement('{'.self::NS_CAC.'}DeliveryLocation');
            $this->buildPostalAddressXml($writer, $delivery->address, 'Address');
            $writer->endElement(); // DeliveryLocation
        }

        $writer->endElement(); // Delivery
    }

    /**
     * Build payment means XML (BG-16).
     *
     * Children follow the UBL 2.1 PaymentMeansType sequence — cbc:PaymentMeansCode,
     * cbc:PaymentDueDate, ..., cac:PayeeFinancialAccount — which is order-enforced
     * by the schema.
     *
     * @param  string|null  $iban  Payee account (BT-84), or null when unknown
     * @param  Carbon|null  $paymentDueDate  BT-9, credit notes only
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
     * @param  array<int, array{taxPercent: BigDecimal, taxCategoryId: TaxCategoryId, taxableAmount: BigDecimal, taxAmount: BigDecimal}>  $taxGroups
     * @param  array<string, ExemptionReasonData>  $reasons  Exemption reasons keyed by category code
     */
    private function buildTaxTotalXml(Writer $writer, array $taxGroups, BigDecimal $totalTaxAmount, string $currency, array $reasons): void
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

            $category = $group['taxCategoryId'];
            $writer->startElement('{'.self::NS_CAC.'}TaxCategory');
            $this->writeElement($writer, self::NS_CBC, 'ID', $category->value);
            // A rate is not money: format it as the group key reads it, so lines and group agree.
            $this->writeElement($writer, self::NS_CBC, 'Percent', (string) $group['taxPercent']);

            // BT-121 / BT-120. Z must carry neither (BR-Z-10); E carries the caller's (BR-E-10).
            $reason = $reasons[$category->value] ?? null;
            $text = $this->filled($reason?->text);
            [$code, $text] = match ($category) {
                TaxCategoryId::NotSubject => ['VATEX-EU-O', null],
                TaxCategoryId::ReverseCharge => ['VATEX-EU-AE', $text ?? 'Taxare inversa'],
                TaxCategoryId::Export, TaxCategoryId::IntraCommunitySupply => [self::FIXED_EXEMPTION_CODES[$category->value], $text],
                TaxCategoryId::Exempt => [$this->filled($reason?->code), $text],
                default => [null, null],
            };
            if ($code !== null) {
                $this->writeElement($writer, self::NS_CBC, 'TaxExemptionReasonCode', $code);
            }
            if ($text !== null) {
                $this->writeElement($writer, self::NS_CBC, 'TaxExemptionReason', $text);
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
     * BR-DEC-RO-15 caps BT-111 at 2 decimals, which Amount::parse() already enforces.
     */
    private function buildTaxTotalInAccountingCurrency(Writer $writer, BigDecimal $taxAmount): void
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
        BigDecimal $lineExtensionAmount,
        BigDecimal $taxExclusiveAmount,
        BigDecimal $taxInclusiveAmount,
        string $currency,
        BigDecimal $payableRoundingAmount,
        BigDecimal $payableAmount
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

        if (! $payableRoundingAmount->isZero()) {
            $this->writeElementWithAttributes(
                $writer,
                self::NS_CBC,
                'PayableRoundingAmount',
                $this->formatAmount($payableRoundingAmount),
                ['currencyID' => $currency]
            );
        }

        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            'PayableAmount',
            $this->formatAmount($payableAmount),
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
        $quantity = Amount::parse($line->quantity, self::QUANTITY_PRECISION, 'quantity');
        $unitCode = $line->unitCode ?: self::DEFAULT_UNIT_CODE;
        $this->writeElementWithAttributes(
            $writer,
            self::NS_CBC,
            $quantityElement,
            $this->formatQuantity($isCreditNote ? $quantity->negated() : $quantity),
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
        $taxCategory = $line->getTaxCategory($isSupplierVatPayer);
        $this->writeElement($writer, self::NS_CBC, 'ID', $taxCategory->value);

        // BR-O-05: an invoice line with VAT category "O" (Not subject to VAT) must
        // not carry an invoiced item VAT rate (BT-152). The schematron asserts
        // not(cbc:Percent) on such a line and fails fatally otherwise, which
        // rejects every invoice issued by a non-VAT-payer supplier.
        if ($taxCategory !== TaxCategoryId::NotSubject) {
            $this->writeElement($writer, self::NS_CBC, 'Percent', (string) Amount::parse($line->taxPercent, self::AMOUNT_PRECISION, 'taxPercent'));
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
            $this->formatQuantity(Amount::parse($line->unitPrice, self::QUANTITY_PRECISION, 'unitPrice')),
            ['currencyID' => $currency]
        );
        $writer->endElement(); // Price

        $writer->endElement(); // InvoiceLine or CreditNoteLine
    }

    /**
     * Format a monetary amount with 2 decimal places.
     *
     * The EN 16931 BR-DEC-* rules cap the number of decimals at 2 for amount
     * fields (line net amount, VAT amounts, document totals). Computed amounts
     * already carry exactly 2.
     */
    private function formatAmount(BigDecimal $amount): string
    {
        return (string) $amount->toScale(self::AMOUNT_PRECISION, RoundingMode::HalfUp);
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
     * while fractional ones keep their precision ("1.375", "0.0075"). A decimal
     * string never uses scientific notation, which is not a valid xsd:decimal.
     */
    private function formatQuantity(BigDecimal $value): string
    {
        // "1.375000" -> "1.375", "5.000000" -> "5." (the '.' stops the trim)
        $parts = explode('.', rtrim((string) $value->toScale(self::QUANTITY_PRECISION), '0'), 2);

        // Pad back to a minimum of 2 decimals: "5." -> "5.00"
        return $parts[0].'.'.str_pad($parts[1] ?? '', 2, '0');
    }
}
