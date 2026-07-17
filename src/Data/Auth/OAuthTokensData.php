<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Data\Auth;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * OAuth 2.0 token data from ANAF.
 */
class OAuthTokensData extends Data
{
    /**
     * Token expiration time.
     *
     * Any CarbonInterface implementation is accepted by the constructor, so apps using
     * Date::use(CarbonImmutable::class) can pass their datetime casts straight in.
     * Immutable dates are converted to a mutable Carbon; a Carbon that is already
     * mutable is stored as-is.
     */
    public ?Carbon $expiresAt;

    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        ?CarbonInterface $expiresAt = null,
        public ?int $expiresIn = null,
        public string $tokenType = 'Bearer',
    ) {
        $this->expiresAt = $expiresAt === null || $expiresAt instanceof Carbon
            ? $expiresAt
            : Carbon::instance($expiresAt);
    }

    /**
     * Create from ANAF token response.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fromAnafResponse(array $response): self
    {
        $expiresAt = null;
        if (isset($response['expires_in'])) {
            $expiresAt = Carbon::now()->addSeconds((int) $response['expires_in']);
        }

        return new self(
            accessToken: $response['access_token'],
            refreshToken: $response['refresh_token'],
            expiresAt: $expiresAt,
            expiresIn: isset($response['expires_in']) ? (int) $response['expires_in'] : null,
            tokenType: $response['token_type'] ?? 'Bearer',
        );
    }

    /**
     * Check if the token is expired or about to expire.
     *
     * @param  int  $bufferSeconds  Buffer time before actual expiration (default 120 seconds)
     */
    public function isExpired(int $bufferSeconds = 120): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt->copy()->subSeconds($bufferSeconds)->isPast();
    }
}
