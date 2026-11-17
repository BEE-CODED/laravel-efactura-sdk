<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Support\Amount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
 *
 * Amounts are float|string and kept exactly as given. They are read with
 * Amount::parse() wherever they are used — quantity, unit price and tax at 6 decimals,
 * the rate at 2 — and a value carrying more precision than that is refused, never rounded.
 * Pass decimal strings ("1.005") when exactness matters; a float is accepted only when it
 * differs from its decimal rendering by binary representation noise.
 */
class InvoiceLineData extends Data
{
    /** Categories a caller must declare explicitly; each files rate 0 and tax 0. */
    private const EXPLICIT_ZERO_CATEGORIES = [
        TaxCategoryId::ReverseCharge,
        TaxCategoryId::Exempt,
        TaxCategoryId::Export,
        TaxCategoryId::IntraCommunitySupply,
    ];

    public function __construct(
        /** Product or service name */
        public string $name,
        /** Quantity of items (at most 6 decimals) */
        public float|string $quantity,
        /** Unit price excluding VAT (at most 6 decimals) */
        public float|string $unitPrice,
        /** Pre-computed tax amount for this line (at most 6 decimals). The builder sums
         *  per-line tax amounts instead of recalculating from taxableAmount × rate.
         *  Sign should follow quantity (negative for negative-qty lines). */
        public float|string $taxAmount,
        /** Line item identifier (auto-generated if not provided) */
        public string|int|null $id = null,
        /** Additional description */
        public ?string $description = null,
        /** Unit of measure code (UN/ECE rec 20, e.g., 'EA' for each, 'KGM' for kilogram) */
        public string $unitCode = 'EA',
        /** VAT percentage (e.g., 19 for 19%, at most 2 decimals). Must be non-negative. */
        public float|string $taxPercent = 0.0,
        /** Explicit VAT category; null retains legacy supplier/rate inference. */
        public ?TaxCategoryId $taxCategoryId = null,
    ) {}

    /**
     * Explicit rules for the float|string amounts, which laravel-data cannot infer from a union.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric'],
            'unitPrice' => ['required', 'numeric'],
            'taxAmount' => ['required', 'numeric'],
            'taxPercent' => ['sometimes', 'numeric', 'min:0'],
        ];
    }

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
     * public-property mutation must not bypass the zero-tax AE/E/G/K contract.
     *
     * @throws ValidationException
     */
    public function getTaxCategory(bool $isSupplierVatPayer): TaxCategoryId
    {
        $percent = Amount::parse($this->taxPercent, 2, 'taxPercent');

        if (in_array($this->taxCategoryId, self::EXPLICIT_ZERO_CATEGORIES, true)) {
            $category = ['category' => $this->taxCategoryId->value];
            if (! $isSupplierVatPayer) {
                throw new ValidationException(__('efactura-sdk::validation.explicit_category_supplier_must_be_vat_payer', $category));
            }
            if (! $percent->isZero() || ! Amount::parse($this->taxAmount, 6, 'taxAmount')->isZero()) {
                throw new ValidationException(__('efactura-sdk::validation.explicit_category_tax_must_be_zero', $category));
            }

            return $this->taxCategoryId;
        }

        $inferred = match (true) {
            ! $isSupplierVatPayer => TaxCategoryId::NotSubject,
            $percent->isZero() => TaxCategoryId::ZeroRated,
            default => TaxCategoryId::Standard,
        };
        if ($this->taxCategoryId !== null && $this->taxCategoryId !== $inferred) {
            throw new ValidationException(__('efactura-sdk::validation.tax_category_inconsistent'));
        }

        return $inferred;
    }

    /** Category and the rate at 2 decimals jointly identify a VAT breakdown ("S:19", "Z:0"). */
    public function getTaxGroupKey(bool $isSupplierVatPayer): string
    {
        $category = $this->getTaxCategory($isSupplierVatPayer);

        return $category->value.':'.Amount::parse($this->taxPercent, 2, 'taxPercent')->strippedOfTrailingZeros();
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

    /**
     * Calculate the line total including tax: getLineTotal() + getTaxAmount(), summed exactly.
     */
    public function getLineTotalWithTax(): float
    {
        return $this->exactLineTotal()->plus($this->exactTaxAmount())->toFloat();
    }

    /** @internal The line net exactly as cbc:LineExtensionAmount files it. */
    public function exactLineTotal(): BigDecimal
    {
        return Amount::parse($this->quantity, 6, 'quantity')
            ->multipliedBy(Amount::parse($this->unitPrice, 6, 'unitPrice'))
            ->toScale(2, RoundingMode::HalfUp);
    }

    private function exactTaxAmount(): BigDecimal
    {
        return Amount::parse($this->taxAmount, 6, 'taxAmount')->toScale(2, RoundingMode::HalfUp);
    }
}
