<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Facades;

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for ANAF e-Factura OAuth authentication.
 *
 * Use this facade for the OAuth flow:
 * - Generate authorization URL for user redirect
 * - Exchange authorization code for tokens
 * - Refresh expired tokens
 * - Decode state parameter for CSRF validation
 *
 * For API operations, create an EFacturaClient with your stored tokens:
 *
 * ```php
 * use BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient;
 *
 * $client = EFacturaClient::fromTokens('12345678', $storedTokens);
 * $result = $client->uploadDocument($xml);
 * ```
 *
 * @method static string getAuthorizationUrl(?AuthUrlSettingsData $settings = null) Get OAuth authorization URL
 * @method static OAuthTokensData exchangeCodeForToken(string $code) Exchange auth code for tokens
 * @method static OAuthTokensData refreshAccessToken(string $refreshToken) Refresh an expired token
 * @method static array decodeState(string $encodedState) Decode state parameter for CSRF validation
 *
 * @see \BeeCoded\EFacturaSdk\Services\AnafAuthenticator
 */
final class EFactura extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AnafAuthenticatorInterface::class;
    }
}
