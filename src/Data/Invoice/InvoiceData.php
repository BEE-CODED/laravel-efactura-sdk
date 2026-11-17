<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Support\Amount;
use BeeCoded\EFacturaSdk\Support\DateHelper;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Partials\Partial;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

/**
 * Complete invoice data for e-Factura submission.
 *
 * Maps to TypeScript InvoiceInput interface.
 */
class InvoiceData extends Data
{
    // Properties are declared here rather than promoted so that the date fields can accept any
    // CarbonInterface while storing a concrete Carbon. Two details are load-bearing:
    //
    //  - the declaration ORDER is the constructor's, because laravel-data builds its property
    //    list from ReflectionClass::getProperties(), which drives toArray()/toJson() key order;
    //  - each property carries the constructor's DEFAULT, because laravel-data reads a
    //    non-promoted property's default from the property itself (DataPropertyFactory) rather
    //    than from the constructor signature. Without them, defaulted fields would become
    //    "required" in getValidationRules() and validate() would reject payloads that omit them.
    //
    // Both are guarded by tests in tests/Unit/Data/Invoice/InvoiceDataTest.php.
    public string $invoiceNumber;

    /**
     * Invoice issue date.
     *
     * Any CarbonInterface implementation is accepted by the constructor, so apps using
     * Date::use(CarbonImmutable::class) can pass their datetime casts straight in.
     * Immutable dates are converted to a mutable Carbon; a Carbon that is already
     * mutable is stored as-is. A string is left untouched.
     *
     * Careful when changing this: the normalisation below only protects direct construction.
     * On laravel-data's ::from() path the constructor runs and is then OVERWRITTEN --
     * DataFromArrayResolver skips promoted properties but direct-writes un-promoted ones,
     * and these are un-promoted. Today that is harmless because laravel-data's cast resolves
     * the declared property type and produces a Carbon by itself, so the end state matches
     * and getIssueDateAsCarbon()'s `instanceof Carbon` check stays correct. Any further
     * normalisation added here would silently not apply via ::from().
     */
    public Carbon|string $issueDate;

    public PartyData $supplier;

    public PartyData $customer;

    /** @var InvoiceLineData[] */
    #[DataCollectionOf(InvoiceLineData::class)]
    public array $lines;

    /**
     * Payment due date. Normalised in the same way as $issueDate.
     */
    public Carbon|string|null $dueDate = null;

    public string $currency = 'RON';

    public ?string $paymentIban = null;

    public ?InvoiceTypeCode $invoiceTypeCode = null;

    /**
     * @deprecated Since 4.0; use $precedingInvoices. Still honoured: it is read as a single
     *             reference when $precedingInvoices is empty, and must name its only entry
     *             when both are set (see getPrecedingInvoices()).
     */
    public ?string $precedingInvoiceNumber = null;

    /**
     * Total VAT amount expressed in RON (BT-111), the tax accounting currency.
     *
     * REQUIRED when $currency is not RON, and rejected when it is:
     *
     *  - BR-RO-030 forces the VAT accounting currency (BT-6) to RON whenever the
     *    document currency (BT-5) is not RON, and BR-53 then requires a
     *    cac:TaxTotal/cbc:TaxAmount at @currencyID='RON' to exist. That figure is
     *    a statutory declaration of VAT owed to the Romanian state, and ANAF
     *    cannot verify the conversion — a wrong value is ACCEPTED and filed. It
     *    therefore has to come from the caller and cannot be inferred.
     *  - When BT-5 IS RON, BR-CO-15 asserts exactly one TaxTotal/TaxAmount in the
     *    document currency, so a second RON total cannot be emitted at all.
     *
     * This carries the converted AMOUNT rather than an exchange rate because the
     * rate is not part of the filed document: EN 16931 defines no business term
     * for it, and UBL-CR-490 ("A UBL invoice should not include the
     * TaxExchangeRate") warns against cac:TaxExchangeRate. Taking a rate would
     * also make this library's rounding authoritative over the caller's ledger,
     * which already holds the BNR-rate figure that must be declared.
     *
     * Supplied with the same sign as the per-line taxAmount values; the builder
     * sign-flips it for credit notes alongside them.
     */
    public float|string|null $taxAmountRon = null;

    /** Optional VAT applicability date (BT-7); never inferred by the SDK. */
    public Carbon|string|null $taxPointDate = null;

    /** Optional payable rounding (BT-114), in the same sign convention as the lines. */
    public float|string|null $payableRoundingAmount = null;

    /**
     * Preceding invoice references (BG-3, at most 500): number (BT-25) and optional issue date (BT-26).
     *
     * @var list<PrecedingInvoiceData>
     */
    #[DataCollectionOf(PrecedingInvoiceData::class)]
    public array $precedingInvoices = [];

    /**
     * Invoice notes (BT-22): at most 20, each at most 300 characters.
     *
     * @var list<string>
     */
    public array $notes = [];

    /** VAT point date code (BT-8): "3", "35" or "432"; never together with $taxPointDate (BR-CO-03). */
    public ?string $vatPointDateCode = null;

    /** Delivery date (BT-72) and deliver-to address (BG-15); required for VAT category K. */
    public ?DeliveryData $delivery = null;

    /**
     * VAT exemption reasons (BT-120/BT-121) for categories E, G, K and AE, one per category.
     *
     * @var list<ExemptionReasonData>
     */
    #[DataCollectionOf(ExemptionReasonData::class)]
    public array $exemptionReasons = [];

    /**
     * @param  string  $invoiceNumber  Invoice number/identifier
     * @param  CarbonInterface|string  $issueDate  Invoice issue date
     * @param  PartyData  $supplier  Supplier (seller) information
     * @param  PartyData  $customer  Customer (buyer) information
     * @param  InvoiceLineData[]  $lines  Invoice line items
     * @param  CarbonInterface|string|null  $dueDate  Payment due date
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  string|null  $paymentIban  IBAN for payment
     * @param  InvoiceTypeCode|null  $invoiceTypeCode  Type of invoice (default: CommercialInvoice)
     * @param  string|null  $precedingInvoiceNumber  Deprecated alias of a single $precedingInvoices entry (BT-25)
     * @param  float|string|null  $taxAmountRon  Total VAT in RON (BT-111, at most 2 decimals); required when $currency is not RON, rejected when it is
     * @param  CarbonInterface|string|null  $taxPointDate  VAT applicability date (BT-7), omitted from XML and serialization when null
     * @param  float|string|null  $payableRoundingAmount  Payable adjustment (BT-114, at most 2 decimals); null is omitted from serialization
     * @param  list<PrecedingInvoiceData>  $precedingInvoices  Preceding invoice references (BG-3); empty is omitted from serialization
     * @param  list<string>  $notes  Invoice notes (BT-22); empty is omitted from serialization
     * @param  string|null  $vatPointDateCode  VAT point date code (BT-8); null is omitted from serialization
     * @param  DeliveryData|null  $delivery  Delivery date and address (BG-13); null is omitted from serialization
     * @param  list<ExemptionReasonData>  $exemptionReasons  VAT exemption reasons; empty is omitted from serialization
     */
    public function __construct(
        string $invoiceNumber,
        CarbonInterface|string $issueDate,
        PartyData $supplier,
        PartyData $customer,
        array $lines,
        CarbonInterface|string|null $dueDate = null,
        string $currency = 'RON',
        ?string $paymentIban = null,
        ?InvoiceTypeCode $invoiceTypeCode = null,
        ?string $precedingInvoiceNumber = null,
        float|string|null $taxAmountRon = null,
        CarbonInterface|string|null $taxPointDate = null,
        float|string|null $payableRoundingAmount = null,
        array $precedingInvoices = [],
        array $notes = [],
        ?string $vatPointDateCode = null,
        ?DeliveryData $delivery = null,
        array $exemptionReasons = [],
    ) {
        $this->invoiceNumber = $invoiceNumber;
        $this->issueDate = $issueDate instanceof CarbonInterface && ! $issueDate instanceof Carbon
            ? Carbon::instance($issueDate)
            : $issueDate;
        $this->supplier = $supplier;
        $this->customer = $customer;
        $this->lines = $lines;
        $this->dueDate = $dueDate instanceof CarbonInterface && ! $dueDate instanceof Carbon
            ? Carbon::instance($dueDate)
            : $dueDate;
        $this->currency = $currency;
        $this->paymentIban = $paymentIban;
        $this->invoiceTypeCode = $invoiceTypeCode;
        $this->precedingInvoiceNumber = $precedingInvoiceNumber;
        $this->taxAmountRon = $taxAmountRon;
        $this->taxPointDate = $taxPointDate instanceof CarbonInterface && ! $taxPointDate instanceof Carbon
            ? Carbon::instance($taxPointDate)
            : $taxPointDate;
        $this->payableRoundingAmount = $payableRoundingAmount;
        $this->precedingInvoices = $precedingInvoices;
        $this->notes = $notes;
        $this->vatPointDateCode = $vatPointDateCode;
        $this->delivery = $delivery;
        $this->exemptionReasons = $exemptionReasons;
    }

    /**
     * Explicit rules for the float|string amounts, which laravel-data cannot infer from a union.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'taxAmountRon' => ['nullable', 'numeric'],
            'payableRoundingAmount' => ['nullable', 'numeric'],
        ];
    }

    /**
     * Preserve the legacy serialized key set when optional values are absent.
     * Spatie routes both toArray() and toJson() through this method. Filter each
     * transformation without retaining an exclusion that would hide a later date.
     *
     * @return array<string, mixed>
     */
    public function transform(
        TransformationContextFactory|TransformationContext|null $transformationContext = null,
    ): array {
        // Validate on the way out without assigning: the caller's value is serialized as given.
        $this->getPayableRoundingAmount();

        $context = match (true) {
            $transformationContext instanceof TransformationContext => clone $transformationContext,
            $transformationContext instanceof TransformationContextFactory => clone $transformationContext->get($this),
            default => clone TransformationContextFactory::create()->get($this),
        };
        // Visibility uses the original property name, before mapping or wrapping.
        // Cloning preserves the caller's partials and all other context settings.
        if ($this->taxPointDate === null) {
            $context->addExceptPartial(Partial::create('taxPointDate'));
        }
        foreach (['payableRoundingAmount', 'precedingInvoices', 'notes', 'vatPointDateCode', 'delivery', 'exemptionReasons'] as $property) {
            if ($this->{$property} === null || $this->{$property} === []) {
                $context->addExceptPartial(Partial::create($property));
            }
        }

        return parent::transform($context);
    }

    /**
     * Return the caller's VAT applicability date as a copy, or null if omitted.
     *
     * @throws \InvalidArgumentException If the date string is blank, unparseable or impossible (2024-02-30, 24:00)
     */
    public function getTaxPointDateAsCarbon(): ?Carbon
    {
        if ($this->taxPointDate === null) {
            return null;
        }

        if ($this->taxPointDate instanceof Carbon) {
            return $this->taxPointDate->copy();
        }

        try {
            return DateHelper::parse($this->taxPointDate);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Invalid tax point date format: {$this->taxPointDate}",
                0,
                $e
            );
        }
    }

    /**
     * Get the issue date as a Carbon instance.
     * Returns a copy to prevent mutation of the original date.
     *
     * @throws \InvalidArgumentException If the date string is blank, unparseable or impossible (2024-02-30, 24:00)
     */
    public function getIssueDateAsCarbon(): Carbon
    {
        if ($this->issueDate instanceof Carbon) {
            return $this->issueDate->copy();
        }

        try {
            return DateHelper::parse($this->issueDate);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Invalid issue date format: {$this->issueDate}",
                0,
                $e
            );
        }
    }

    /**
     * Get the due date as a Carbon instance (or null if not set).
     * Returns a copy to prevent mutation of the original date.
     *
     * @throws \InvalidArgumentException If the date string is blank, unparseable or impossible (2024-02-30, 24:00)
     */
    public function getDueDateAsCarbon(): ?Carbon
    {
        if ($this->dueDate === null) {
            return null;
        }

        if ($this->dueDate instanceof Carbon) {
            return $this->dueDate->copy();
        }

        try {
            return DateHelper::parse($this->dueDate);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Invalid due date format: {$this->dueDate}",
                0,
                $e
            );
        }
    }

    /**
     * The preceding invoice references to file, resolving the deprecated $precedingInvoiceNumber.
     *
     * An alias alone becomes one reference; when $precedingInvoices is also set, the alias must
     * name its only entry.
     *
     * @return list<PrecedingInvoiceData>
     *
     * @throws ValidationException If the alias and the list disagree
     */
    public function getPrecedingInvoices(): array
    {
        $alias = $this->precedingInvoiceNumber;
        if ($alias === null || $alias === '') {
            return $this->precedingInvoices;
        }

        if ($this->precedingInvoices === []) {
            return [new PrecedingInvoiceData($alias)];
        }

        if (count($this->precedingInvoices) !== 1 || $this->precedingInvoices[0]->number !== $alias) {
            throw new ValidationException(__('efactura-sdk::validation.preceding_invoice_conflict'));
        }

        return $this->precedingInvoices;
    }

    /**
     * Get the invoice type code, defaulting to CommercialInvoice.
     */
    public function getInvoiceTypeCode(): InvoiceTypeCode
    {
        return $this->invoiceTypeCode ?? InvoiceTypeCode::CommercialInvoice;
    }

    /**
     * Calculate the total amount excluding VAT (BT-106 / BT-109).
     *
     * Sums the per-line ROUNDED net amounts, because that is what the filed XML
     * sums: every line carries its own cbc:LineExtensionAmount capped at 2
     * decimals, and cac:LegalMonetaryTotal adds those up. Rounding a raw sum once
     * at the end instead loses a bani per pair of sub-cent lines — two lines of
     * 0.5 x 0.01 file as 0.01 + 0.01 = 0.02, while the raw sum rounds to 0.01.
     * Guarded by tests/Unit/Builders/InvoiceBuilderTest.php, which compares this
     * against generated XML.
     *
     * Note on sign: this reports the total in the same positive sense the lines
     * are supplied in. For a credit note the builder sign-flips every line, so
     * the filed document states the negation of this value.
     */
    public function getTotalExcludingVat(): float
    {
        return $this->exactTotalExcludingVat()->toFloat();
    }

    /**
     * Calculate the total VAT amount (BT-110).
     *
     * Sums the pre-computed per-line tax amounts ROUNDED ONCE PER CATEGORY/RATE GROUP,
     * because that is what the filed XML sums: lines are grouped by category and rate into
     * cac:TaxSubtotal elements, each subtotal's cbc:TaxAmount is rounded to 2
     * decimals, and BT-110 is the sum of those subtotals.
     *
     * The grouping is load-bearing and neither coarser nor finer rounding matches:
     *  - rounding the raw all-lines sum once understates as soon as two rate
     *    groups each carry a sub-cent residue (0.005 @19% + 0.005 @5% files as
     *    0.02, not 0.01);
     *  - rounding per LINE overstates within a group (0.005 + 0.005 at the same
     *    rate files as 0.01, not 0.02).
     *
     * The group key mirrors InvoiceBuilder::groupLinesByTax() exactly; both are
     * pinned against generated XML in tests/Unit/Builders/InvoiceBuilderTest.php.
     *
     * Note on sign: as with getTotalExcludingVat(), a credit note files the
     * negation of this value.
     */
    public function getTotalVat(): float
    {
        return $this->exactTotalVat()->toFloat();
    }

    /**
     * Return the payable rounding (BT-114) read at 2 decimals, validating every consuming path.
     *
     * The property keeps the caller's float or string; it is parsed wherever it is used, so
     * hydration and later assignment cannot bypass the check. A meaningful sub-cent value is
     * refused, never rounded (see Amount::parse()).
     *
     * @throws ValidationException
     */
    public function getPayableRoundingAmount(): ?float
    {
        return $this->payableRoundingAmount === null ? null : $this->exactPayableRounding()->toFloat();
    }

    /**
     * Calculate net plus VAT before payable rounding (BT-112).
     *
     * Matches TaxInclusiveAmount in caller sign; credit-note XML negates it.
     */
    public function getTotalIncludingVatBeforeRounding(): float
    {
        return $this->exactTotalExcludingVat()->plus($this->exactTotalVat())->toFloat();
    }

    /**
     * Calculate the amount payable including optional rounding (BT-115).
     *
     * Matches PayableAmount in caller sign; credit-note XML negates it. Consumers
     * needing net plus VAT alone should use getTotalIncludingVatBeforeRounding().
     */
    public function getTotalIncludingVat(): float
    {
        return $this->exactTotalExcludingVat()->plus($this->exactTotalVat())->plus($this->exactPayableRounding())->toFloat();
    }

    private function exactPayableRounding(): BigDecimal
    {
        return Amount::parse($this->payableRoundingAmount ?? 0, 2, 'payableRoundingAmount');
    }

    /** The per-line nets as getLineTotal() and the XML compute them, summed exactly. */
    private function exactTotalExcludingVat(): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($this->lines as $line) {
            $total = $total->plus($line->exactLineTotal());
        }

        return $total;
    }

    /** Each VAT group's raw tax summed exactly and rounded half-up once, then summed. */
    private function exactTotalVat(): BigDecimal
    {
        /** @var array<string, BigDecimal> $groups */
        $groups = [];

        foreach ($this->lines as $line) {
            $key = $line->getTaxGroupKey($this->supplier->isVatPayer);
            $groups[$key] = ($groups[$key] ?? BigDecimal::zero())->plus(Amount::parse($line->taxAmount, 6, 'taxAmount'));
        }

        $total = BigDecimal::zero();
        foreach ($groups as $groupTaxAmount) {
            $total = $total->plus($groupTaxAmount->toScale(2, RoundingMode::HalfUp));
        }

        return $total;
    }
}
