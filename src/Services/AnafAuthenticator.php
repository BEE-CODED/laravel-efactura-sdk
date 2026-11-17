<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services;

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Services\ApiClients\BaseApiClient;
use BeeCoded\EFacturaSdk\Support\AnafValue;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * ANAF OAuth 2.0 authentication service.
 *
 * Handles the complete OAuth 2.0 flow for authenticating with ANAF e-Factura system:
 * - Authorization URL generation with optional state encoding
 * - Token exchange via POST to token endpoint
 * - Token refresh flow
 *
 * This service is stateless and returns OAuthTokensData; the caller stores the tokens
 * (for example the laravel-efactura wrapper's TokenService).
 */
class AnafAuthenticator implements AnafAuthenticatorInterface
{
    private readonly string $clientId;

    private readonly string $clientSecret;

    private readonly string $redirectUri;

    private readonly string $authorizeUrl;

    private readonly string $tokenUrl;

    private readonly int $timeout;

    /** Whether a token request that provably never left may be sent once more (http.retry_times > 1). */
    private readonly bool $retryUnsent;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        array $config,
    ) {
        // Checked on first use, so resolving the service never throws and decodeState() needs no OAuth configuration.
        $this->clientId = $config['oauth']['client_id'] ?? '';
        $this->clientSecret = $config['oauth']['client_secret'] ?? '';
        $this->redirectUri = $config['oauth']['redirect_uri'] ?? '';
        $this->authorizeUrl = $config['endpoints']['oauth']['authorize'] ?? '';
        $this->tokenUrl = $config['endpoints']['oauth']['token'] ?? '';
        $this->retryUnsent = (int) ($config['http']['retry_times'] ?? 3) > 1;
        // env() yields a string; an empty or zero value would be Guzzle's "no timeout".
        $timeout = (float) ($config['http']['timeout'] ?? 30);
        $this->timeout = $timeout > 0 ? max(1, (int) ceil($timeout)) : 30;
    }

    /**
     * Build the OAuth authorization URL for redirecting users to ANAF login.
     *
     * @throws AuthenticationException When the OAuth configuration is missing (code 0)
     */
    public function getAuthorizationUrl(?AuthUrlSettingsData $settings = null): string
    {
        $this->validateConfig();

        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'token_content_type' => 'jwt',
        ];

        // Add optional scope
        if ($settings?->scope !== null) {
            $params['scope'] = $settings->scope;
        }

        // Encode state as base64 JSON if provided
        if ($settings?->state !== null) {
            $params['state'] = $this->encodeState($settings->state);
        }

        return $this->authorizeUrl.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange an authorization code for access and refresh tokens.
     *
     * @throws AuthenticationException When the code exchange fails or the OAuth configuration is missing (code 0)
     */
    public function exchangeCodeForToken(#[\SensitiveParameter] string $code): OAuthTokensData
    {
        $this->validateConfig();

        if (empty($code)) {
            throw new AuthenticationException('Authorization code is required');
        }

        $response = $this->postTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'token_content_type' => 'jwt',
        ]);

        return $this->parseTokenResponse($response, 'Token exchange failed');
    }

    /**
     * Refresh an expired access token using a refresh token.
     *
     * @throws AuthenticationException When the token refresh fails or the OAuth configuration is missing (code 0)
     */
    public function refreshAccessToken(#[\SensitiveParameter] string $refreshToken): OAuthTokensData
    {
        $this->validateConfig();

        if (empty($refreshToken)) {
            throw new AuthenticationException('Refresh token is required');
        }

        $response = $this->postTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'token_content_type' => 'jwt',
        ]);

        return $this->parseTokenResponse($response, 'Token refresh failed');
    }

    /**
     * Decode the state parameter returned from OAuth callback.
     *
     * See {@see AnafAuthenticatorInterface::decodeState()} for the CSRF check the caller must make.
     *
     * @param  string  $encodedState  The base64-encoded state from the callback
     * @return array<string, mixed> The decoded state data
     *
     * @throws AuthenticationException When the state cannot be decoded
     */
    public function decodeState(string $encodedState): array
    {
        if (empty($encodedState)) {
            throw new AuthenticationException('State parameter is required for CSRF protection');
        }

        $decoded = base64_decode($encodedState, true);

        if ($decoded === false) {
            throw new AuthenticationException('Invalid state parameter: base64 decoding failed');
        }

        try {
            $state = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($state)) {
                throw new AuthenticationException('Invalid state parameter: expected JSON object');
            }

            return $state;
        } catch (\JsonException $e) {
            throw new AuthenticationException(
                'Invalid state parameter: JSON decoding failed - '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Refuse an OAuth call when the configuration is missing.
     *
     * Code 0, not 401: a deployment mistake is not a refused grant, and re-authorising cannot fix it.
     *
     * @throws AuthenticationException When required configuration is missing
     */
    private function validateConfig(): void
    {
        foreach (['client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'redirect_uri' => $this->redirectUri] as $key => $value) {
            if (empty($value)) {
                throw new AuthenticationException(
                    "Missing required OAuth configuration: {$key}. Please check your efactura-sdk.php config.",
                    0
                );
            }
        }

        if (empty($this->authorizeUrl)) {
            throw new AuthenticationException(
                'Missing required OAuth authorize endpoint. Please check your efactura-sdk.php config.',
                0
            );
        }

        if (empty($this->tokenUrl)) {
            throw new AuthenticationException(
                'Missing required OAuth token endpoint. Please check your efactura-sdk.php config.',
                0
            );
        }
    }

    /**
     * Encode state data as base64 JSON for safe transport in URL.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws AuthenticationException When state data cannot be JSON encoded
     */
    private function encodeState(array $state): string
    {
        try {
            return base64_encode(json_encode($state, JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            throw new AuthenticationException(
                'Failed to encode state parameter: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Post a form-urlencoded request to the token endpoint.
     *
     * A request that provably never left (BaseApiClient::wasNotSent()) is sent once more at once,
     * when retry_times allows retries: ANAF never saw it, so the refresh token is not spent. No
     * sleep, so the refresh lock's TTL still covers it.
     *
     * @param  array<string, string>  $data
     *
     * @throws AuthenticationException When the HTTP request fails
     */
    private function postTokenRequest(#[\SensitiveParameter] array $data, bool $retried = false): Response
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->withHeaders([
                    'Accept' => 'application/json',
                ])
                ->post($this->tokenUrl, $data);

            return $response;
        } catch (\Throwable $e) {
            if (! $retried && $this->retryUnsent && BaseApiClient::wasNotSent($e)) {
                return $this->postTokenRequest($data, true);
            }

            $this->logError(
                'ANAF OAuth token request failed',
                [
                    'error' => $e->getMessage(),
                    'grant_type' => $data['grant_type'] ?? 'unknown',
                ]
            );

            throw new AuthenticationException(
                'Failed to communicate with ANAF OAuth server: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Parse the token response and return OAuthTokensData.
     *
     * @throws AuthenticationException When the response indicates an error or is invalid
     */
    private function parseTokenResponse(Response $response, string $errorPrefix): OAuthTokensData
    {
        $data = AnafValue::json($response->body());

        // A bare scalar body is as unusable as a non-JSON one.
        if (! is_array($data)) {
            $data = null;
        }

        // Check for HTTP errors first (takes precedence over JSON parsing issues)
        if ($response->failed()) {
            $errorMessage = $this->extractErrorMessage($data, $response->status());

            $this->logError(
                $errorPrefix,
                [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                ]
            );

            throw new AuthenticationException("{$errorPrefix}: {$errorMessage}", $response->status());
        }

        // Check for non-JSON response on successful HTTP status
        // (e.g., HTML maintenance page returned with 200 OK)
        if ($data === null) {
            $this->logError(
                $errorPrefix.': Non-JSON response from OAuth server',
                [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]
            );

            throw new AuthenticationException(
                "{$errorPrefix}: OAuth server returned non-JSON response",
                $response->status()
            );
        }

        // Validate required token fields
        if (! is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            $this->logError(
                $errorPrefix.': Missing access token in response'
            );

            throw new AuthenticationException(
                "{$errorPrefix}: Response did not contain an access token",
                $response->status()
            );
        }

        if (! is_string($data['refresh_token'] ?? null) || $data['refresh_token'] === '') {
            $this->logError(
                $errorPrefix.': Missing refresh token in response'
            );

            throw new AuthenticationException(
                "{$errorPrefix}: Response did not contain a refresh token",
                $response->status()
            );
        }

        return OAuthTokensData::fromAnafResponse($data);
    }

    /**
     * A failing log handler must never replace the AuthenticationException that follows it.
     *
     * @param  array<string, mixed>  $context
     */
    private function logError(string $message, array $context = []): void
    {
        try {
            Log::channel(config('efactura-sdk.logging.channel', 'efactura-sdk'))->error($message, $context);
        } catch (\Throwable) {
            // Swallowed on purpose: logging is never part of the call's outcome.
        }
    }

    /**
     * Extract error message from OAuth error response.
     *
     * @param  array<string, mixed>|null  $data
     */
    private function extractErrorMessage(?array $data, int $statusCode): string
    {
        if ($data === null) {
            return "HTTP {$statusCode} error";
        }

        // The API error extractor's rule: a blank, boolean or non-scalar field falls through.
        // OAuth 2.0 standard error response
        $error = BaseApiClient::errorText($data['error'] ?? null);
        if ($error !== null) {
            $description = BaseApiClient::errorText($data['error_description'] ?? null);

            return $description !== null ? "{$error}: {$description}" : $error;
        }

        // ANAF-specific error formats
        return BaseApiClient::errorText($data['eroare'] ?? null)
            ?? BaseApiClient::errorText($data['mesaj'] ?? null)
            ?? "HTTP {$statusCode} error";
    }
}
