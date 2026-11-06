<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Partials\Partial;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

/**
 * Invoice line item data.
 *
 * Maps to TypeScript InvoiceLine interface.
 *
 * Note: Quantity can be negative for credit notes/corrective invoices.
 * Tax percent must be non-negative (0% or higher).
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
        /** Pre-computed tax amount for this line. The builder sums per-line tax
         *  amounts instead of recalculating from taxableAmount × rate.
         *  Sign should follow quantity (negative for negative-qty lines). */
        public float $taxAmount,
        /** Line item identifier (auto-generated if not provided) */
        public string|int|null $id = null,
        /** Additional description */
        public ?string $description = null,
        /** Unit of measure code (UN/ECE rec 20, e.g., 'EA' for each, 'KGM' for kilogram) */
        public string $unitCode = 'EA',
        /** VAT percentage (e.g., 19 for 19%). Must be non-negative. */
        #[Min(0)]
        public float $taxPercent = 0,
        /** Explicit VAT category; null retains legacy supplier/rate inference. */
        public ?TaxCategoryId $taxCategoryId = null,
    ) {}

    /**
     * Preserve the legacy serialized shape when no category was declared.
     * Clone the live context so null exclusions never hide a later assignment.
     *
     * @return array<string, mixed>
     */
    public function transform(
        TransformationContextFactory|TransformationContext|null $transformationContext = null,
    ): array {
        $context = match (true) {
            $transformationContext instanceof TransformationContext => clone $transformationContext,
            $transformationContext instanceof TransformationContextFactory => clone $transformationContext->get($this),
            default => clone TransformationContextFactory::create()->get($this),
        };
        if ($this->taxCategoryId === null) {
            $context->addExceptPartial(Partial::create('taxCategoryId'));
        }

        return parent::transform($context);
    }

    /**
     * Resolve the category identically for DTO totals and the real serializer.
     * Validate explicit declarations on consumption: hydration and subsequent
     * public-property mutation must not bypass the zero-tax AE contract.
     *
     * @throws ValidationException
     */
    public function getTaxCategory(bool $isSupplierVatPayer): TaxCategoryId
    {
        if ($this->taxCategoryId === TaxCategoryId::ReverseCharge) {
            if (! $isSupplierVatPayer) {
                throw new ValidationException(__('efactura-sdk::validation.reverse_charge_supplier_must_be_vat_payer'));
            }
            if ($this->taxPercent !== 0.0 || $this->taxAmount !== 0.0) {
                throw new ValidationException(__('efactura-sdk::validation.reverse_charge_tax_must_be_zero'));
            }

            return TaxCategoryId::ReverseCharge;
        }

        $inferred = match (true) {
            ! $isSupplierVatPayer => TaxCategoryId::NotSubject,
            abs($this->taxPercent) < 0.01 => TaxCategoryId::ZeroRated,
            default => TaxCategoryId::Standard,
        };
        if ($this->taxCategoryId !== null && $this->taxCategoryId !== $inferred) {
            throw new ValidationException(__('efactura-sdk::validation.tax_category_inconsistent'));
        }

        return $inferred;
    }

    /** Category and normalized percentage jointly identify a VAT breakdown. */
    public function getTaxGroupKey(bool $isSupplierVatPayer): string
    {
        $category = $this->getTaxCategory($isSupplierVatPayer);
        $taxPercent = $category === TaxCategoryId::ReverseCharge ? 0.0 : round($this->taxPercent, 2);

        return $category->value.':'.(string) $taxPercent;
    }

    /**
     * Calculate the line total (quantity * unitPrice).
     * Rounded to 2 decimal places for consistency with UBL XML output.
     */
    public function getLineTotal(): float
    {
        return round($this->quantity * $this->unitPrice, 2);
    }

    /**
     * Get the pre-computed tax amount for this line.
     * Returns the value passed at construction, rounded to 2 decimal places.
     */
    public function getTaxAmount(): float
    {
        return round($this->taxAmount, 2);
    }

    /**
     * Get the raw (unrounded) line extension amount.
     *
     * Exposed for callers that need the pre-rounding value. NOT what the invoice
     * files: cbc:LineExtensionAmount is capped at 2 decimals, so getLineTotal()
     * is the figure that reaches ANAF and the one the document totals are built
     * from. Summing this across lines and rounding once at the end does NOT
     * reproduce the filed total.
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
