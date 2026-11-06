<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
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
     * Supplied in the same positive sense as the per-line tax amounts; the
     * builder sign-flips it for credit notes alongside them.
     */
    public ?float $taxAmountRon = null;

    /** Optional VAT applicability date (BT-7); never inferred by the SDK. */
    public Carbon|string|null $taxPointDate = null;

    /** Optional payable rounding (BT-114), in the same sign convention as the lines. */
    public ?float $payableRoundingAmount = null;

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
     * @param  string|null  $precedingInvoiceNumber  Preceding invoice number for credit notes (BT-25, used in BillingReference)
     * @param  float|null  $taxAmountRon  Total VAT in RON (BT-111); required when $currency is not RON, rejected when it is
     * @param  CarbonInterface|string|null  $taxPointDate  VAT applicability date (BT-7), omitted from XML and serialization when null
     * @param  float|null  $payableRoundingAmount  Finite cent-precision payable adjustment (BT-114); null is omitted from serialization
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
        ?float $taxAmountRon = null,
        CarbonInterface|string|null $taxPointDate = null,
        ?float $payableRoundingAmount = null,
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
        $this->payableRoundingAmount = $this->getPayableRoundingAmount();
    }

    /**
     * Preserve the legacy serialized key set when optional date/rounding is absent.
     * Spatie routes both toArray() and toJson() through this method. Filter each
     * transformation without retaining an exclusion that would hide a later date.
     *
     * @return array<string, mixed>
     */
    public function transform(
        TransformationContextFactory|TransformationContext|null $transformationContext = null,
    ): array {
        $this->payableRoundingAmount = $this->getPayableRoundingAmount();

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
        if ($this->payableRoundingAmount === null) {
            $context->addExceptPartial(Partial::create('payableRoundingAmount'));
        }

        return parent::transform($context);
    }

    /**
     * Return the caller's VAT applicability date as a copy, or null if omitted.
     *
     * @throws \InvalidArgumentException If the date string cannot be parsed
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
            if (trim($this->taxPointDate) === '') {
                throw new \InvalidArgumentException('A tax point date cannot be empty.');
            }

            return Carbon::parse($this->taxPointDate);
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
     * @throws \InvalidArgumentException If the date string cannot be parsed
     */
    public function getIssueDateAsCarbon(): Carbon
    {
        if ($this->issueDate instanceof Carbon) {
            return $this->issueDate->copy();
        }

        try {
            return Carbon::parse($this->issueDate);
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
     * @throws \InvalidArgumentException If the date string cannot be parsed
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
            return Carbon::parse($this->dueDate);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Invalid due date format: {$this->dueDate}",
                0,
                $e
            );
        }
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
     * Return normalized payable rounding (BT-114), validating every consuming path.
     *
     * Spatie hydration and later public-property assignment can overwrite constructor
     * normalization. Never silently round a meaningful sub-cent adjustment. The range
     * keeps adjacent cents distinguishable in a double; the tolerance only admits
     * binary representation noise, and is capped below meaningful sub-cent precision.
     *
     * @throws ValidationException
     */
    public function getPayableRoundingAmount(): ?float
    {
        $amount = $this->payableRoundingAmount;
        if ($amount === null) {
            return null;
        }

        if (! is_finite($amount) || abs($amount) >= 2 ** 46) {
            throw new ValidationException(__('efactura-sdk::validation.payable_rounding_amount_invalid'));
        }

        $rounded = round($amount, 2);
        $tolerance = min(0.000001, max(0.000000001, abs($amount) * PHP_FLOAT_EPSILON * 2));
        if (abs($amount - $rounded) > $tolerance) {
            throw new ValidationException(__('efactura-sdk::validation.payable_rounding_amount_invalid'));
        }

        return $rounded == 0.0 ? 0.0 : $rounded;
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
        $rounding = self::decimal($this->getPayableRoundingAmount() ?? 0.0)->toScale(2, RoundingMode::HalfUp);

        return $this->exactTotalExcludingVat()->plus($this->exactTotalVat())->plus($rounding)->toFloat();
    }

    /** The per-line nets as getLineTotal() and the XML compute them, summed exactly. */
    private function exactTotalExcludingVat(): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($this->lines as $line) {
            $total = $total->plus(self::decimal($line->quantity)->multipliedBy(self::decimal($line->unitPrice))->toScale(2, RoundingMode::HalfUp));
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
            $groups[$key] = ($groups[$key] ?? BigDecimal::zero())->plus(self::decimal($line->taxAmount));
        }

        $total = BigDecimal::zero();
        foreach ($groups as $groupTaxAmount) {
            $total = $total->plus($groupTaxAmount->toScale(2, RoundingMode::HalfUp));
        }

        return $total;
    }

    /** Read an amount at the 6 decimals the XML files, as InvoiceLineData and InvoiceBuilder do. */
    private static function decimal(float $value): BigDecimal
    {
        return BigDecimal::of(number_format($value, 6, '.', ''));
    }
}
