<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Services;

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
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
 * This service is stateless and returns OAuthTokensData to the caller.
 * Token storage is handled by a separate TokenRepository.
 */
class AnafAuthenticator implements AnafAuthenticatorInterface
{
    private readonly string $clientId;

    private readonly string $clientSecret;

    private readonly string $redirectUri;

    private readonly string $authorizeUrl;

    private readonly string $tokenUrl;

    private readonly int $timeout;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        array $config,
    ) {
        $this->validateConfig($config);

        $this->clientId = $config['oauth']['client_id'];
        $this->clientSecret = $config['oauth']['client_secret'];
        $this->redirectUri = $config['oauth']['redirect_uri'];
        $this->authorizeUrl = $config['endpoints']['oauth']['authorize'];
        $this->tokenUrl = $config['endpoints']['oauth']['token'];
        $this->timeout = $config['http']['timeout'] ?? 30;
    }

    /**
     * Build the OAuth authorization URL for redirecting users to ANAF login.
     */
    public function getAuthorizationUrl(?AuthUrlSettingsData $settings = null): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
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
     * @throws AuthenticationException When the code exchange fails
     */
    public function exchangeCodeForToken(string $code): OAuthTokensData
    {
        if (empty($code)) {
            throw new AuthenticationException('Authorization code is required');
        }

        $response = $this->postTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        return $this->parseTokenResponse($response, 'Token exchange failed');
    }

    /**
     * Refresh an expired access token using a refresh token.
     *
     * @throws AuthenticationException When the token refresh fails
     */
    public function refreshAccessToken(string $refreshToken): OAuthTokensData
    {
        if (empty($refreshToken)) {
            throw new AuthenticationException('Refresh token is required');
        }

        $response = $this->postTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        return $this->parseTokenResponse($response, 'Token refresh failed');
    }

    /**
     * Decode the state parameter returned from OAuth callback.
     *
     * IMPORTANT: For CSRF protection, you MUST validate the state parameter
     * in your OAuth callback handler. Compare the decoded state with what
     * you originally stored in the session before redirecting to ANAF.
     *
     * Example usage in callback:
     * ```php
     * public function handleCallback(Request $request)
     * {
     *     $state = $request->get('state');
     *     $code = $request->get('code');
     *
     *     // Decode and validate state
     *     $decodedState = EFacturaSdkAuth::decodeState($state);
     *
     *     // Verify against session-stored state (CSRF protection)
     *     if ($decodedState['csrf_token'] !== session('efactura_csrf_token')) {
     *         throw new \Exception('Invalid state parameter - possible CSRF attack');
     *     }
     *
     *     // Now safe to exchange code for tokens
     *     $tokens = EFacturaSdkAuth::exchangeCodeForToken($code);
     * }
     * ```
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
     * Validate the configuration array.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws AuthenticationException When required configuration is missing
     */
    private function validateConfig(array $config): void
    {
        $requiredOauthKeys = ['client_id', 'client_secret', 'redirect_uri'];

        foreach ($requiredOauthKeys as $key) {
            if (empty($config['oauth'][$key])) {
                throw new AuthenticationException(
                    "Missing required OAuth configuration: {$key}. Please check your efactura-sdk.php config."
                );
            }
        }

        if (empty($config['endpoints']['oauth']['authorize'])) {
            throw new AuthenticationException(
                'Missing required OAuth authorize endpoint. Please check your efactura-sdk.php config.'
            );
        }

        if (empty($config['endpoints']['oauth']['token'])) {
            throw new AuthenticationException(
                'Missing required OAuth token endpoint. Please check your efactura-sdk.php config.'
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
     * @param  array<string, string>  $data
     *
     * @throws AuthenticationException When the HTTP request fails
     */
    private function postTokenRequest(array $data): Response
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->asForm()
                ->withHeaders([
                    'Accept' => 'application/json',
                ])
                ->post($this->tokenUrl, $data);

            return $response;
        } catch (\Throwable $e) {
            Log::channel(config('efactura-sdk.logging.channel', 'efactura-sdk'))->error(
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
        $data = $response->json();

        // Check for HTTP errors first (takes precedence over JSON parsing issues)
        if ($response->failed()) {
            $errorMessage = $this->extractErrorMessage($data, $response->status());

            Log::channel(config('efactura-sdk.logging.channel', 'efactura-sdk'))->error(
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
            Log::channel(config('efactura-sdk.logging.channel', 'efactura-sdk'))->error(
                $errorPrefix.': Non-JSON response from OAuth server',
                [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]
            );

            throw new AuthenticationException(
                "{$errorPrefix}: OAuth server returned non-JSON response"
            );
        }

        // Validate required token fields
        if (empty($data['access_token'])) {
            Log::channel(config('efactura-sdk.logging.channel', 'efactura-sdk'))->error(
                $errorPrefix.': Missing access token in response'
            );

            throw new AuthenticationException(
                "{$errorPrefix}: Response did not contain an access token"
            );
        }

        if (empty($data['refresh_token'])) {
            Log::channel(config('efactura-sdk.logging.channel', 'efactura-sdk'))->error(
                $errorPrefix.': Missing refresh token in response'
            );

            throw new AuthenticationException(
                "{$errorPrefix}: Response did not contain a refresh token"
            );
        }

        return OAuthTokensData::fromAnafResponse($data);
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

        // OAuth 2.0 standard error response
        if (isset($data['error'])) {
            $message = $data['error'];

            if (isset($data['error_description'])) {
                $message .= ': '.$data['error_description'];
            }

            return $message;
        }

        // ANAF-specific error formats
        if (isset($data['eroare'])) {
            return $data['eroare'];
        }

        if (isset($data['mesaj'])) {
            return $data['mesaj'];
        }

        return "HTTP {$statusCode} error";
    }
}
