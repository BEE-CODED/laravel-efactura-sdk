<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use BeeCoded\EFacturaSdk\Support\DateHelper;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Partials\Partial;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

/**
 * Delivery information (BG-13): the actual delivery date (BT-72) and the deliver-to
 * address (BG-15), whose country is BT-80.
 *
 * An address needs a street, city and county or region for every country, and a Romanian
 * one is canonicalised like a party address (ISO 3166-2:RO county, SECTORn in Bucharest).
 * An intra-community supply (VAT category K) requires both the date and the address.
 */
class DeliveryData extends Data
{
    // Declared rather than promoted so the date accepts any CarbonInterface while storing a
    // concrete Carbon, exactly as InvoiceData does (order and defaults are load-bearing there).
    public Carbon|string|null $actualDeliveryDate = null;

    public ?AddressData $address = null;

    public function __construct(CarbonInterface|string|null $actualDeliveryDate = null, ?AddressData $address = null)
    {
        $this->actualDeliveryDate = $actualDeliveryDate instanceof CarbonInterface && ! $actualDeliveryDate instanceof Carbon
            ? Carbon::instance($actualDeliveryDate)
            : $actualDeliveryDate;
        $this->address = $address;
    }

    /**
     * Leave omitted values out of the serialized shape.
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
        foreach (['actualDeliveryDate', 'address'] as $property) {
            if ($this->{$property} === null) {
                $context->addExceptPartial(Partial::create($property));
            }
        }

        return parent::transform($context);
    }

    /**
     * Return the actual delivery date as a copy, or null if omitted.
     *
     * @throws \InvalidArgumentException If the date string is blank, unparseable or impossible (2024-02-30, 24:00)
     */
    public function getActualDeliveryDateAsCarbon(): ?Carbon
    {
        if ($this->actualDeliveryDate === null || $this->actualDeliveryDate instanceof Carbon) {
            return $this->actualDeliveryDate?->copy();
        }

        try {
            return DateHelper::parse($this->actualDeliveryDate);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid actual delivery date format: {$this->actualDeliveryDate}", 0, $e);
        }
    }
}
