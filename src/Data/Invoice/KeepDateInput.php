<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Invoice;

use Carbon\Carbon;
use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

/**
 * Gives an invoice date on ::from() and toArray() the constructor's contract: a string is kept
 * as given and a date becomes a Carbon, so data.date_format and data.date_timezone never shift
 * the filed day.
 *
 * @internal
 */
final class KeepDateInput implements Cast, Transformer
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  CreationContext<Data>  $context
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): mixed
    {
        // Never Uncastable: that would hand the value to laravel-data's global date cast.
        return $value instanceof \DateTimeInterface ? Carbon::instance($value) : $value;
    }

    public function transform(DataProperty $property, mixed $value, TransformationContext $context): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
    }
}
