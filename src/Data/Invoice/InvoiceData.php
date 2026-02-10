<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
use Carbon\Carbon;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * Complete invoice data for e-Factura submission.
 *
 * Maps to TypeScript InvoiceInput interface.
 */
class InvoiceData extends Data
{
    /**
     * @param  string  $invoiceNumber  Invoice number/identifier
     * @param  Carbon|string  $issueDate  Invoice issue date
     * @param  PartyData  $supplier  Supplier (seller) information
     * @param  PartyData  $customer  Customer (buyer) information
     * @param  InvoiceLineData[]  $lines  Invoice line items
     * @param  Carbon|string|null  $dueDate  Payment due date
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  string|null  $paymentIban  IBAN for payment
     * @param  InvoiceTypeCode|null  $invoiceTypeCode  Type of invoice (default: CommercialInvoice)
     * @param  string|null  $precedingInvoiceNumber  Preceding invoice number for credit notes (BT-25, used in BillingReference)
     */
    public function __construct(
        public string $invoiceNumber,
        public Carbon|string $issueDate,
        public PartyData $supplier,
        public PartyData $customer,
        #[DataCollectionOf(InvoiceLineData::class)]
        public array $lines,
        public Carbon|string|null $dueDate = null,
        public string $currency = 'RON',
        public ?string $paymentIban = null,
        public ?InvoiceTypeCode $invoiceTypeCode = null,
        public ?string $precedingInvoiceNumber = null,
    ) {}

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
     * Groups lines by tax rate first, then calculates tax on group totals.
     * This matches InvoiceBuilder's approach and ensures consistency with UBL XML output.
     */
    public function getTotalVat(): float
    {
        // Group line amounts by tax percentage
        // Round to 2 decimal places to match InvoiceBuilder::groupLinesByTax() and avoid
        // floating-point precision issues (e.g., 19.0 vs 19.00000001 producing different keys)
        $taxGroups = [];
        foreach ($this->lines as $line) {
            $key = (string) round($line->taxPercent, 2);
            if (! isset($taxGroups[$key])) {
                $taxGroups[$key] = [
                    'taxableAmount' => 0.0,
                    'taxPercent' => $line->taxPercent,
                ];
            }
            $taxGroups[$key]['taxableAmount'] += $line->getRawLineTotal();
        }

        // Calculate tax on group totals (single rounding per group)
        $totalTax = 0.0;
        foreach ($taxGroups as $group) {
            $taxableAmount = round($group['taxableAmount'], 2);
            $taxAmount = round($taxableAmount * ($group['taxPercent'] / 100), 2);
            $totalTax += $taxAmount;
        }

        return round($totalTax, 2);
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
