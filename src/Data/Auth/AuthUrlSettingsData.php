<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Auth;

use Spatie\LaravelData\Data;

/**
 * Settings for building OAuth authorization URL.
 */
class AuthUrlSettingsData extends Data
{
    /**
     * @param  array<string, mixed>|null  $state  Encoded into the authorization URL; readable and editable by the user.
     *                                            Put only a random CSRF token here.
     * @param  string|null  $scope  OAuth scope
     */
    public function __construct(
        public ?array $state = null,
        public ?string $scope = null,
    ) {}
}
