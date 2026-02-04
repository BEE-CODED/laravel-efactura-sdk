<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Data\Invoice;

use Beecoded\EFactura\Enums\InvoiceTypeCode;
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
    ) {}

    /**
     * Get the issue date as a Carbon instance.
     * Returns a copy to prevent mutation of the original date.
     */
    public function getIssueDateAsCarbon(): Carbon
    {
        return $this->issueDate instanceof Carbon
            ? $this->issueDate->copy()
            : Carbon::parse($this->issueDate);
    }

    /**
     * Get the due date as a Carbon instance (or null if not set).
     * Returns a copy to prevent mutation of the original date.
     */
    public function getDueDateAsCarbon(): ?Carbon
    {
        if ($this->dueDate === null) {
            return null;
        }

        return $this->dueDate instanceof Carbon
            ? $this->dueDate->copy()
            : Carbon::parse($this->dueDate);
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
        $taxGroups = [];
        foreach ($this->lines as $line) {
            $key = (string) $line->taxPercent;
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
