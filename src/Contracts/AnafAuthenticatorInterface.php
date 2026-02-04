<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Contracts;

use BeeCoded\EFactura\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFactura\Data\Auth\OAuthTokensData;
use BeeCoded\EFactura\Exceptions\AuthenticationException;

/**
 * Interface for ANAF OAuth 2.0 authentication.
 *
 * Handles the OAuth flow for ANAF e-Factura system, including:
 * - Building authorization URLs
 * - Exchanging authorization codes for tokens
 * - Refreshing access tokens
 */
interface AnafAuthenticatorInterface
{
    /**
     * Build the OAuth authorization URL for redirecting users to ANAF login.
     *
     * @param  AuthUrlSettingsData|null  $settings  Optional settings including scope and state data
     * @return string The full authorization URL
     */
    public function getAuthorizationUrl(?AuthUrlSettingsData $settings = null): string;

    /**
     * Exchange an authorization code for access and refresh tokens.
     *
     * @param  string  $code  The authorization code received from ANAF callback
     * @return OAuthTokensData The token data containing access and refresh tokens
     *
     * @throws AuthenticationException When the code exchange fails
     */
    public function exchangeCodeForToken(string $code): OAuthTokensData;

    /**
     * Refresh an expired access token using a refresh token.
     *
     * @param  string  $refreshToken  The refresh token to use
     * @return OAuthTokensData New token data with refreshed access token
     *
     * @throws AuthenticationException When the token refresh fails
     */
    public function refreshAccessToken(string $refreshToken): OAuthTokensData;

    /**
     * Decode the state parameter returned from OAuth callback.
     *
     * IMPORTANT: For CSRF protection, you MUST validate the state parameter
     * in your OAuth callback handler. The decoded state should match
     * what you originally provided to getAuthorizationUrl().
     *
     * @param  string  $encodedState  The base64-encoded state from the callback
     * @return array<string, mixed> The decoded state data
     *
     * @throws AuthenticationException When the state cannot be decoded
     */
    public function decodeState(string $encodedState): array;
}
