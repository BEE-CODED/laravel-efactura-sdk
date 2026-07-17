<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

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
        $total = array_reduce(
            $this->lines,
            fn (float $total, InvoiceLineData $line) => $total + $line->getLineTotal(),
            0.0
        );

        return round($total, 2);
    }

    /**
     * Calculate the total VAT amount (BT-110).
     *
     * Sums the pre-computed per-line tax amounts ROUNDED ONCE PER TAX-RATE GROUP,
     * because that is what the filed XML sums: lines are grouped by rate into
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
        /** @var array<string, float> $groups */
        $groups = [];

        foreach ($this->lines as $line) {
            // Round the rate to 2 decimals so 19.0 and 19.00000001 share a group,
            // matching InvoiceBuilder::groupLinesByTax().
            $key = (string) round($line->taxPercent, 2);
            $groups[$key] = ($groups[$key] ?? 0.0) + $line->taxAmount;
        }

        $total = 0.0;
        foreach ($groups as $groupTaxAmount) {
            $total += round($groupTaxAmount, 2);
        }

        return round($total, 2);
    }

    /**
     * Calculate the total amount including VAT (BT-112 / BT-115).
     *
     * Matches cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount and cbc:PayableAmount
     * in the filed XML, which are likewise the sum of the two rounded totals.
     */
    public function getTotalIncludingVat(): float
    {
        return round($this->getTotalExcludingVat() + $this->getTotalVat(), 2);
    }
}
