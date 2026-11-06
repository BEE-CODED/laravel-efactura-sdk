<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
     * Calculate the line total (quantity * unitPrice), exactly as cbc:LineExtensionAmount files it:
     * both read at 6 decimals and multiplied as decimals, rounded half-up once.
     */
    public function getLineTotal(): float
    {
        return $this->exactLineTotal()->toFloat();
    }

    /**
     * Get the pre-computed tax amount for this line.
     * Returns the value passed at construction, read at 6 decimals and rounded half-up to 2 decimals.
     */
    public function getTaxAmount(): float
    {
        return $this->exactTaxAmount()->toFloat();
    }

    private function exactLineTotal(): BigDecimal
    {
        return self::decimal($this->quantity)->multipliedBy(self::decimal($this->unitPrice))->toScale(2, RoundingMode::HalfUp);
    }

    private function exactTaxAmount(): BigDecimal
    {
        return self::decimal($this->taxAmount)->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Read an amount at the 6 decimals the XML files quantities and prices with.
     *
     * Float products and sums misplace half-cent ties (3 x 1.005 = 3.0149999...), which PHP 8.4+
     * round() no longer hides. number_format() is ini-independent and never emits exponents.
     */
    private static function decimal(float $value): BigDecimal
    {
        return BigDecimal::of(number_format($value, 6, '.', ''));
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
     * Calculate the line total including tax: getLineTotal() + getTaxAmount(), summed exactly.
     */
    public function getLineTotalWithTax(): float
    {
        return $this->exactLineTotal()->plus($this->exactTaxAmount())->toFloat();
    }
}
