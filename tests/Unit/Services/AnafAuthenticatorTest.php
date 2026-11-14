<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Contracts\AnafAuthenticatorInterface;
use BeeCoded\EFacturaSdk\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Services\AnafAuthenticator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

function createAuthenticator(?HttpFactory $http = null): AnafAuthenticator
{
    return new AnafAuthenticator(
        http: $http ?? Http::getFacadeRoot(),
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
                http: Http::getFacadeRoot(),
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
                http: Http::getFacadeRoot(),
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
                http: Http::getFacadeRoot(),
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

    describe('missing configuration', function () {
        // Code 0, like a transport failure: a host that keys a "re-authorise" cooldown on
        // 400/401 must not block every company for a deployment mistake.
        it('is reported with code 0, not as a refused grant', function (string $key, ?string $value) {
            $config = config('efactura-sdk');
            $config['oauth'] = ['client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://example.com/callback'];
            data_set($config, $key, $value);
            config()->set('efactura-sdk', $config);

            expect(fn () => app(AnafAuthenticatorInterface::class))
                ->toThrow(fn (AuthenticationException $e) => expect($e->getCode())->toBe(0));
        })->with([
            'client id' => ['oauth.client_id', null],
            'client secret' => ['oauth.client_secret', ''],
            'redirect uri' => ['oauth.redirect_uri', null],
            'authorize endpoint' => ['endpoints.oauth.authorize', ''],
            'token endpoint' => ['endpoints.oauth.token', null],
        ]);
    });

    describe('timeout config', function () {
        // env() hands EFACTURA_TIMEOUT over as a string; under strict_types an int property
        // rejected it with a TypeError, and an empty value must not become Guzzle's 0 (no timeout).
        it('accepts a string timeout and falls back to 30 when it is empty', function (mixed $timeout, int $expected) {
            Http::fake(['*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600])]);

            $auth = new AnafAuthenticator(
                http: Http::getFacadeRoot(),
                config: [
                    'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://example.com/callback'],
                    'endpoints' => ['oauth' => ['authorize' => 'https://example.com/auth', 'token' => 'https://example.com/token']],
                    'http' => ['timeout' => $timeout],
                ]
            );

            $auth->refreshAccessToken('refresh-token');

            expect((fn () => $this->timeout)->call($auth))->toBe($expected);
        })->with([
            'numeric string' => ['60', 60],
            'sub-second float rounds up' => [0.5, 1],
            'fractional string rounds up' => ['2.5', 3],
            'empty string' => ['', 30],
            'zero string' => ['0', 30],
            'null' => [null, 30],
        ]);
    });

    describe('getAuthorizationUrl', function () {
        it('builds basic authorization URL', function () {
            $auth = createAuthenticator();
            $url = $auth->getAuthorizationUrl();

            expect($url)->toContain('https://logincert.anaf.ro/anaf-oauth2/v1/authorize');
            expect($url)->toContain('response_type=code');
            expect($url)->toContain('client_id=test-client-id');
            expect($url)->toContain('redirect_uri=');
            expect($url)->toContain('token_content_type=jwt');
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

        it('reads a BOM-prefixed token answer', function () {
            Http::fake(['*' => Http::response("\u{FEFF}".json_encode([
                'access_token' => 'bom-access-token',
                'refresh_token' => 'bom-refresh-token',
                'expires_in' => 3600,
            ]), 200)]);

            $tokens = createAuthenticator(Http::getFacadeRoot())->refreshAccessToken('old-refresh-token');

            expect($tokens->accessToken)->toBe('bom-access-token')
                ->and($tokens->refreshToken)->toBe('bom-refresh-token');
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

    describe('encodeState exception handling', function () {
        it('throws AuthenticationException when state contains non-UTF8 data', function () {
            $auth = createAuthenticator();

            // Create state with invalid UTF-8 sequence that will fail json_encode
            $invalidUtf8 = "\xB1\x31"; // Invalid UTF-8
            $state = ['data' => $invalidUtf8];

            // This should throw AuthenticationException, not raw JsonException
            $settings = new AuthUrlSettingsData(state: $state);
            $auth->getAuthorizationUrl($settings);
        })->throws(AuthenticationException::class, 'Failed to encode state parameter');

        it('successfully encodes valid state data', function () {
            $auth = createAuthenticator();
            $state = ['csrf_token' => 'abc123', 'user_id' => 456, 'nested' => ['key' => 'value']];
            $settings = new AuthUrlSettingsData(state: $state);

            $url = $auth->getAuthorizationUrl($settings);

            expect($url)->toContain('state=');
            // Extract and decode the state to verify it was encoded correctly
            preg_match('/state=([^&]+)/', $url, $matches);
            $encodedState = urldecode($matches[1]);
            $decodedState = json_decode(base64_decode($encodedState), true);

            expect($decodedState)->toBe($state);
        });
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
                $this->fail('Expected AuthenticationException');
            } catch (AuthenticationException $e) {
                expect($e->getMessage())->toContain('invalid_client')
                    ->and($e->getMessage())->toContain('Client authentication failed')
                    // Kept on purpose: invalid_client refuses the app itself, so retrying cannot help.
                    ->and($e->getCode())->toBe(401);
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
                $this->fail('Expected AuthenticationException');
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
                $this->fail('Expected AuthenticationException');
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
                $this->fail('Expected AuthenticationException');
            } catch (AuthenticationException $e) {
                expect($e->getMessage())->toContain('HTTP 500');
            }
        });
    });

    describe('non-JSON response handling', function () {
        it('throws exception when OAuth server returns HTML on successful status', function () {
            // Simulates maintenance page or misconfigured server returning 200 with HTML
            Http::fake([
                '*' => Http::response('<html><body>Maintenance</body></html>', 200, [
                    'Content-Type' => 'text/html',
                ]),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());
            $auth->exchangeCodeForToken('code');
        })->throws(AuthenticationException::class, 'OAuth server returned non-JSON response');

        it('throws exception when refresh returns non-JSON response', function () {
            Http::fake([
                '*' => Http::response('Internal Server Error', 200),
            ]);

            $auth = createAuthenticator(Http::getFacadeRoot());
            $auth->refreshAccessToken('refresh-token');
        })->throws(AuthenticationException::class, 'OAuth server returned non-JSON response');

        it('carries the real HTTP status, not 401, for an unreadable 2xx token answer', function (mixed $body) {
            Http::fake(['*' => Http::response($body, 200)]);

            $auth = createAuthenticator(Http::getFacadeRoot());

            try {
                $auth->refreshAccessToken('refresh-token');
                $this->fail('Expected AuthenticationException');
            } catch (AuthenticationException $e) {
                // 401 would read as ANAF refusing the grant.
                expect($e->getCode())->toBe(200);
            }
        })->with([
            'html' => ['<html><body>Maintenance</body></html>'],
            'no access token' => [['refresh_token' => 'r']],
            'no refresh token' => [['access_token' => 'a']],
        ]);
    });
});
