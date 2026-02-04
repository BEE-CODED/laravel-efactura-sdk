<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Data\Auth;

use Spatie\LaravelData\Data;

/**
 * Settings for building OAuth authorization URL.
 */
class AuthUrlSettingsData extends Data
{
    /**
     * @param  array<string, mixed>|null  $state  State data to encode in the authorization URL
     * @param  string|null  $scope  OAuth scope
     */
    public function __construct(
        public ?array $state = null,
        public ?string $scope = null,
    ) {}
}
