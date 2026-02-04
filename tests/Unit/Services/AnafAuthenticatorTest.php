<?php

declare(strict_types=1);

use BeeCoded\EFactura\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFactura\Exceptions\AuthenticationException;
use BeeCoded\EFactura\Services\AnafAuthenticator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

function createAuthenticator(?HttpFactory $http = null): AnafAuthenticator
{
    return new AnafAuthenticator(
        http: $http ?? new HttpFactory,
        config: [
            'oauth' => [
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
                'redirect_uri' => 'https://example.com/callback',
            ],
            'endpoints' => [
                'oauth' => [
                    'authorize' => 'https://logincert.anaf.ro/anaf-oauth2/v1/authorize',
                    'token' => 'https://logincert.anaf.ro/anaf-oauth2/v1/token',
                ],
            ],
            'http' => [
                'timeout' => 30,
            ],
        ]
    );
}

describe('AnafAuthenticator', function () {
    describe('constructor', function () {
        it('validates required OAuth config', function () {
            new AnafAuthenticator(
                http: new HttpFactory,
                config: [
                    'oauth' => [
                        'client_id' => '',
                        'client_secret' => 'secret',
                        'redirect_uri' => 'https://example.com/callback',
                    ],
                    'endpoints' => [
                        'oauth' => [
                            'authorize' => 'https://example.com/auth',
                            'token' => 'https://example.com/token',
                        ],
                    ],
                ]
            );
        })->throws(AuthenticationException::class, 'Missing required OAuth configuration: client_id');

        it('validates authorize endpoint', function () {
            new AnafAuthenticator(
                http: new HttpFactory,
                config: [
                    'oauth' => [
                        'client_id' => 'id',
                        'client_secret' => 'secret',
                        'redirect_uri' => 'https://example.com/callback',
                    ],
                    'endpoints' => [
                        'oauth' => [
                            'authorize' => '',
                            'token' => 'https://example.com/token',
                        ],
                    ],
                ]
            );
        })->throws(AuthenticationException::class, 'Missing required OAuth authorize endpoint');

        it('validates token endpoint', function () {
            new AnafAuthenticator(
                http: new HttpFactory,
                config: [
                    'oauth' => [
                        'client_id' => 'id',
                        'client_secret' => 'secret',
                        'redirect_uri' => 'https://example.com/callback',
                    ],
                    'endpoints' => [
                        'oauth' => [
                            'authorize' => 'https://example.com/auth',
                            'token' => '',
                        ],
                    ],
                ]
            );
        })->throws(AuthenticationException::class, 'Missing required OAuth token endpoint');
    });

    describe('getAuthorizationUrl', function () {
        it('builds basic authorization URL', function () {
            $auth = createAuthenticator();
            $url = $auth->getAuthorizationUrl();

            expect($url)->toContain('https://logincert.anaf.ro/anaf-oauth2/v1/authorize');
            expect($url)->toContain('response_type=code');
            expect($url)->toContain('client_id=test-client-id');
            expect($url)->toContain('redirect_uri=');
        });

        it('includes scope when provided', function () {
            $auth = createAuthenticator();
            $settings = new AuthUrlSettingsData(scope: 'read write');
            $url = $auth->getAuthorizationUrl($settings);

            expect($url)->toContain('scope=read%20write');
        });

        it('encodes state as base64 JSON', function () {
            $auth = createAuthenticator();
            $state = ['csrf_token' => 'abc123', 'redirect' => '/dashboard'];
            $settings = new AuthUrlSettingsData(state: $state);
            $url = $auth->getAuthorizationUrl($settings);

            expect($url)->toContain('state=');

            // Extract and decode the state
            preg_match('/state=([^&]+)/', $url, $matches);
            $encodedState = urldecode($matches[1]);
            $decoded = json_decode(base64_decode($encodedState), true);

            expect($decoded)->toBe($state);
        });
    });

    describe('exchangeCodeForToken', function () {
        it('throws exception for empty code', function () {
            $auth = createAuthenticator();
            $auth->exchangeCodeForToken('');
        })->throws(AuthenticationException::class, 'Authorization code is required');

        it('exchanges code for tokens successfully', function () {
            Http::fake([
                '*' => Http::response([
                    'access_token' => 'new-access-token',
                    'refresh_token' => 'new-refresh-token',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ], 200),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());
            $tokens = $auth->exchangeCodeForToken('auth-code-123');

            expect($tokens->accessToken)->toBe('new-access-token');
            expect($tokens->refreshToken)->toBe('new-refresh-token');
            expect($tokens->expiresIn)->toBe(3600);
        });

        it('throws exception on HTTP error', function () {
            Http::fake([
                '*' => Http::response([
                    'error' => 'invalid_grant',
                    'error_description' => 'Code expired',
                ], 400),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());
            $auth->exchangeCodeForToken('expired-code');
        })->throws(AuthenticationException::class, 'Token exchange failed');

        it('throws exception when access token missing', function () {
            Http::fake([
                '*' => Http::response([
                    'refresh_token' => 'refresh-only',
                ], 200),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());
            $auth->exchangeCodeForToken('code');
        })->throws(AuthenticationException::class, 'Response did not contain an access token');

        it('throws exception when refresh token missing', function () {
            Http::fake([
                '*' => Http::response([
                    'access_token' => 'access-only',
                ], 200),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());
            $auth->exchangeCodeForToken('code');
        })->throws(AuthenticationException::class, 'Response did not contain a refresh token');
    });

    describe('refreshAccessToken', function () {
        it('throws exception for empty refresh token', function () {
            $auth = createAuthenticator();
            $auth->refreshAccessToken('');
        })->throws(AuthenticationException::class, 'Refresh token is required');

        it('refreshes tokens successfully', function () {
            Http::fake([
                '*' => Http::response([
                    'access_token' => 'refreshed-access-token',
                    'refresh_token' => 'new-refresh-token',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ], 200),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());
            $tokens = $auth->refreshAccessToken('old-refresh-token');

            expect($tokens->accessToken)->toBe('refreshed-access-token');
            expect($tokens->refreshToken)->toBe('new-refresh-token');
        });

        it('throws exception on refresh error', function () {
            Http::fake([
                '*' => Http::response([
                    'error' => 'invalid_grant',
                    'error_description' => 'Refresh token expired',
                ], 400),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());
            $auth->refreshAccessToken('expired-refresh-token');
        })->throws(AuthenticationException::class, 'Token refresh failed');
    });

    describe('decodeState', function () {
        it('throws exception for empty state', function () {
            $auth = createAuthenticator();
            $auth->decodeState('');
        })->throws(AuthenticationException::class, 'State parameter is required');

        it('decodes valid state', function () {
            $auth = createAuthenticator();
            $original = ['csrf_token' => 'test123', 'user_id' => 42];
            $encoded = base64_encode(json_encode($original));

            $decoded = $auth->decodeState($encoded);

            expect($decoded)->toBe($original);
        });

        it('throws exception for invalid base64', function () {
            $auth = createAuthenticator();
            $auth->decodeState('not-valid-base64!!!');
        })->throws(AuthenticationException::class, 'base64 decoding failed');

        it('throws exception for non-JSON content', function () {
            $auth = createAuthenticator();
            $encoded = base64_encode('not json');

            $auth->decodeState($encoded);
        })->throws(AuthenticationException::class, 'JSON decoding failed');

        it('throws exception for non-object JSON', function () {
            $auth = createAuthenticator();
            $encoded = base64_encode('"just a string"');

            $auth->decodeState($encoded);
        })->throws(AuthenticationException::class, 'expected JSON object');
    });

    describe('error message extraction', function () {
        it('extracts OAuth standard error format', function () {
            Http::fake([
                '*' => Http::response([
                    'error' => 'invalid_client',
                    'error_description' => 'Client authentication failed',
                ], 401),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());

            try {
                $auth->exchangeCodeForToken('code');
            } catch (AuthenticationException $e) {
                expect($e->getMessage())->toContain('invalid_client');
                expect($e->getMessage())->toContain('Client authentication failed');
            }
        });

        it('extracts ANAF eroare format', function () {
            Http::fake([
                '*' => Http::response([
                    'eroare' => 'Cod de autorizare invalid',
                ], 400),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());

            try {
                $auth->exchangeCodeForToken('code');
            } catch (AuthenticationException $e) {
                expect($e->getMessage())->toContain('Cod de autorizare invalid');
            }
        });

        it('extracts ANAF mesaj format', function () {
            Http::fake([
                '*' => Http::response([
                    'mesaj' => 'Operatiune esuata',
                ], 400),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());

            try {
                $auth->exchangeCodeForToken('code');
            } catch (AuthenticationException $e) {
                expect($e->getMessage())->toContain('Operatiune esuata');
            }
        });

        it('falls back to HTTP status code', function () {
            Http::fake([
                '*' => Http::response(null, 500),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());

            try {
                $auth->exchangeCodeForToken('code');
            } catch (AuthenticationException $e) {
                expect($e->getMessage())->toContain('HTTP 500');
            }
        });
    });
});
