<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Partials\Partial;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

/**
 * Address information for a party (supplier or customer) or a delivery.
 *
 * Maps to TypeScript Address interface.
 *
 * A Romanian address is filed through AddressSanitizer::canonicalize(): the county as its
 * ISO 3166-2:RO code and, in Bucharest (RO-B), the city as SECTOR1-6. The sector comes from
 * $bucharestSector, else from the city or county text; a Bucharest address without one is
 * refused (BR-RO-100/101). Other countries are filed as given; a blank county is none (null).
 */
class AddressData extends Data
{
    public function __construct(
        public string $street,
        public string $city,
        public ?string $postalZone = null,
        public ?string $county = null,
        public string $countryCode = 'RO',
        /** Bucharest sector 1-6; only valid on an RO address whose county is Bucharest */
        public ?int $bucharestSector = null,
    ) {}

    /**
     * Preserve the legacy serialized shape when no sector was declared.
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
        if ($this->bucharestSector === null) {
            $context->addExceptPartial(Partial::create('bucharestSector'));
        }

        return parent::transform($context);
    }
}
