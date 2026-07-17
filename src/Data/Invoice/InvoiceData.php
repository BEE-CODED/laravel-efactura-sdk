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
     * Calculate the total amount excluding VAT.
     * Uses raw line totals and rounds once at the end for consistency with UBL XML output.
     */
    public function getTotalExcludingVat(): float
    {
        $total = array_reduce(
            $this->lines,
            fn (float $total, InvoiceLineData $line) => $total + $line->getRawLineTotal(),
            0.0
        );

        return round($total, 2);
    }

    /**
     * Calculate the total VAT amount.
     *
     * Sums pre-computed per-line tax amounts. This matches the values passed
     * by the application and avoids recalculation discrepancies.
     */
    public function getTotalVat(): float
    {
        return round(array_sum(array_map(fn (InvoiceLineData $line) => $line->taxAmount, $this->lines)), 2);
    }

    /**
     * Calculate the total amount including VAT.
     * Rounded to 2 decimal places for consistency with UBL XML output.
     */
    public function getTotalIncludingVat(): float
    {
        return round($this->getTotalExcludingVat() + $this->getTotalVat(), 2);
    }
}
