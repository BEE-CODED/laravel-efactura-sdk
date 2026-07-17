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
    // Properties are declared here rather than promoted so that $expiresAt can accept any
    // CarbonInterface while storing a concrete Carbon. Two details are load-bearing:
    //
    //  - the declaration ORDER is the constructor's, because laravel-data builds its property
    //    list from ReflectionClass::getProperties(), which drives toArray()/toJson() key order;
    //  - each property carries the constructor's DEFAULT, because laravel-data reads a
    //    non-promoted property's default from the property itself (DataPropertyFactory) rather
    //    than from the constructor signature. Without them, defaulted fields would become
    //    "required" in getValidationRules() and validate() would reject payloads that omit them.
    //
    // Both are guarded by tests in tests/Unit/Data/Auth/AuthDataTest.php.
    public string $accessToken;

    public string $refreshToken;

    /**
     * Token expiration time.
     *
     * Any CarbonInterface implementation is accepted by the constructor, so apps using
     * Date::use(CarbonImmutable::class) can pass their datetime casts straight in.
     * Immutable dates are converted to a mutable Carbon; a Carbon that is already
     * mutable is stored as-is.
     *
     * Careful when changing this. ::from() takes two different routes here:
     *  - with an array payload, fromAnafResponse() below is a laravel-data magic creation
     *    method, so it intercepts and the constructor's normalisation applies normally;
     *  - with any other payload (an object, Model or Request), the pipeline runs instead:
     *    the constructor runs and is then OVERWRITTEN, because DataFromArrayResolver skips
     *    promoted properties but direct-writes un-promoted ones, and these are un-promoted.
     * That second route is harmless today only because laravel-data's cast resolves the
     * declared property type and produces a Carbon by itself, so the end state matches.
     * Normalisation added here would not apply on it.
     */
    public ?Carbon $expiresAt = null;

    public ?int $expiresIn = null;

    public string $tokenType = 'Bearer';

    public function __construct(
        string $accessToken,
        string $refreshToken,
        ?CarbonInterface $expiresAt = null,
        ?int $expiresIn = null,
        string $tokenType = 'Bearer',
    ) {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = $expiresAt === null || $expiresAt instanceof Carbon
            ? $expiresAt
            : Carbon::instance($expiresAt);
        $this->expiresIn = $expiresIn;
        $this->tokenType = $tokenType;
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
