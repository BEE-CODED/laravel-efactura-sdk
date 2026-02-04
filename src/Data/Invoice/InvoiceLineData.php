<?php

declare(strict_types=1);

namespace Beecoded\EFactura\Data\Invoice;

use Spatie\LaravelData\Data;

/**
 * Invoice line item data.
 *
 * Maps to TypeScript InvoiceLine interface.
 */
class InvoiceLineData extends Data
{
    public function __construct(
        /** Product or service name */
        public string $name,
        /** Quantity of items */
        public float $quantity,
        /** Unit price (excluding VAT) */
        public float $unitPrice,
        /** Line item identifier (auto-generated if not provided) */
        public string|int|null $id = null,
        /** Additional description */
        public ?string $description = null,
        /** Unit of measure code (UN/ECE rec 20, e.g., 'EA' for each, 'KGM' for kilogram) */
        public string $unitCode = 'EA',
        /** VAT percentage (e.g., 19 for 19%) */
        public float $taxPercent = 0,
    ) {}

    /**
     * Calculate the line total (quantity * unitPrice).
     * Rounded to 2 decimal places for consistency with UBL XML output.
     */
    public function getLineTotal(): float
    {
        return round($this->quantity * $this->unitPrice, 2);
    }

    /**
     * Calculate the tax amount for this line (for display purposes).
     *
     * Note: For invoice totals, use InvoiceData::getTotalVat() which groups
     * lines by tax rate before calculating to match UBL XML output.
     */
    public function getTaxAmount(): float
    {
        return round($this->getLineTotal() * ($this->taxPercent / 100), 2);
    }

    /**
     * Get the raw (unrounded) line extension amount.
     * Used internally for tax grouping calculations.
     */
    public function getRawLineTotal(): float
    {
        return $this->quantity * $this->unitPrice;
    }

    /**
     * Calculate the line total including tax.
     * Rounded to 2 decimal places for consistency with UBL XML output.
     */
    public function getLineTotalWithTax(): float
    {
        return round($this->getLineTotal() + $this->getTaxAmount(), 2);
    }
}
