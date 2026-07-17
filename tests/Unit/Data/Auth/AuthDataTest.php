<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

describe('OAuthTokensData', function () {
    it('creates with required fields', function () {
        $tokens = new OAuthTokensData(
            accessToken: 'access_token_123',
            refreshToken: 'refresh_token_456',
        );

        expect($tokens->accessToken)->toBe('access_token_123');
        expect($tokens->refreshToken)->toBe('refresh_token_456');
        expect($tokens->tokenType)->toBe('Bearer');
        expect($tokens->expiresAt)->toBeNull();
        expect($tokens->expiresIn)->toBeNull();
    });

    it('creates with all fields', function () {
        $expiresAt = Carbon::create(2024, 12, 31, 23, 59, 59);

        $tokens = new OAuthTokensData(
            accessToken: 'access_token',
            refreshToken: 'refresh_token',
            expiresAt: $expiresAt,
            expiresIn: 3600,
            tokenType: 'Bearer',
        );

        expect($tokens->expiresAt)->toBe($expiresAt);
        expect($tokens->expiresIn)->toBe(3600);
    });

    it('accepts an immutable date for expiresAt and normalises it', function () {
        // Apps that call Date::use(CarbonImmutable) hydrate the token's expires_at as a
        // CarbonImmutable, which is NOT a Carbon subclass. The constructor must accept it.
        $expiresAt = CarbonImmutable::create(2024, 12, 31, 23, 59, 59);

        $tokens = new OAuthTokensData(
            accessToken: 'access_token',
            refreshToken: 'refresh_token',
            expiresAt: $expiresAt,
        );

        // Stored as a mutable Carbon, preserving the exact instant.
        expect($tokens->expiresAt)->toBeInstanceOf(Carbon::class)
            ->and($tokens->expiresAt->equalTo($expiresAt))->toBeTrue();
    });

    it('reports isExpired correctly for an immutable expiresAt', function () {
        // Pins the read path (->copy()->subSeconds()->isPast()) against a fixed clock,
        // so this asserts the ANSWER rather than merely that a bool came back.
        Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));

        // Inside the 120s default buffer -> already considered expired.
        $expiring = new OAuthTokensData(
            accessToken: 'a',
            refreshToken: 'r',
            expiresAt: CarbonImmutable::create(2024, 6, 15, 12, 0, 30),
        );

        // Comfortably in the future -> not expired.
        $valid = new OAuthTokensData(
            accessToken: 'a',
            refreshToken: 'r',
            expiresAt: CarbonImmutable::create(2024, 6, 15, 13, 0, 0),
        );

        expect($expiring->isExpired())->toBeTrue()
            ->and($valid->isExpired())->toBeFalse();

        Carbon::setTestNow();
    });

    it('preserves a mutable Carbon instance as-is', function () {
        // Guards the BC promise: existing callers passing a mutable Carbon must keep
        // getting back the very same instance, not a copy.
        $expiresAt = Carbon::create(2024, 12, 31, 23, 59, 59);

        $tokens = new OAuthTokensData(
            accessToken: 'access_token',
            refreshToken: 'refresh_token',
            expiresAt: $expiresAt,
        );

        expect($tokens->expiresAt)->toBe($expiresAt);
    });

    it('serialises keys in the declared order', function () {
        // The date properties are declared in the class body rather than promoted, and
        // spatie derives serialisation order from declaration order. Declaring them out
        // of constructor order would silently reorder every consumer's toArray()/toJson().
        $tokens = new OAuthTokensData(
            accessToken: 'access_token',
            refreshToken: 'refresh_token',
            expiresAt: Carbon::create(2024, 12, 31),
            expiresIn: 3600,
        );

        expect(array_keys($tokens->toArray()))
            ->toBe(['accessToken', 'refreshToken', 'expiresAt', 'expiresIn', 'tokenType']);
    });

    it('omits defaulted fields from validation rules', function () {
        // The properties are declared in the class body, and laravel-data reads a
        // non-promoted property's default from the property itself. Drop those defaults
        // and tokenType becomes "required", rejecting payloads that were valid before.
        expect(array_keys(OAuthTokensData::getValidationRules([])))
            ->toBe(['accessToken', 'refreshToken']);
    });

    it('accepts a payload omitting the defaulted fields', function () {
        // The assertion above only reads the rules; this proves the behaviour they drive.
        $tokens = OAuthTokensData::validate(['accessToken' => 'AT', 'refreshToken' => 'RT']);

        expect($tokens)->toBeArray();
    });

    describe('::from() round-trip', function () {
        // fromAnafResponse() is a laravel-data MAGIC creation method, so it intercepts
        // every array handed to ::from() -- including this DTO's own toArray() shape.
        // Without a shape check that lands on $response['access_token'] and fatals.

        it('round-trips its own toArray()', function () {
            $original = new OAuthTokensData(
                accessToken: 'AT',
                refreshToken: 'RT',
                expiresAt: Carbon::create(2024, 6, 15, 13, 0, 0),
                expiresIn: 3600,
                tokenType: 'Bearer',
            );

            $restored = OAuthTokensData::from($original->toArray());

            expect($restored->accessToken)->toBe('AT');
            expect($restored->refreshToken)->toBe('RT');
            expect($restored->expiresIn)->toBe(3600);
            expect($restored->tokenType)->toBe('Bearer');
            expect($restored->expiresAt)->toBeInstanceOf(Carbon::class);
            expect($restored->expiresAt->equalTo($original->expiresAt))->toBeTrue();
        });

        it('accepts a minimal camelCase payload', function () {
            $tokens = OAuthTokensData::from(['accessToken' => 'AT', 'refreshToken' => 'RT']);

            expect($tokens->accessToken)->toBe('AT');
            expect($tokens->refreshToken)->toBe('RT');
            expect($tokens->tokenType)->toBe('Bearer');
            expect($tokens->expiresAt)->toBeNull();
        });

        it('still routes an ANAF wire payload through fromAnafResponse', function () {
            // The snake_case wire shape must keep its expires_in -> expiresAt derivation.
            Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));

            $tokens = OAuthTokensData::from([
                'access_token' => 'access_123',
                'refresh_token' => 'refresh_456',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]);

            expect($tokens->accessToken)->toBe('access_123');
            expect($tokens->refreshToken)->toBe('refresh_456');
            expect($tokens->expiresIn)->toBe(3600);
            expect($tokens->expiresAt->format('Y-m-d H:i:s'))->toBe('2024-06-15 13:00:00');

            Carbon::setTestNow();
        });
    });

    describe('fromAnafResponse', function () {
        it('parses ANAF token response', function () {
            Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));

            $response = [
                'access_token' => 'access_123',
                'refresh_token' => 'refresh_456',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ];

            $tokens = OAuthTokensData::fromAnafResponse($response);

            expect($tokens->accessToken)->toBe('access_123');
            expect($tokens->refreshToken)->toBe('refresh_456');
            expect($tokens->expiresIn)->toBe(3600);
            expect($tokens->tokenType)->toBe('Bearer');
            expect($tokens->expiresAt)->toBeInstanceOf(Carbon::class);
            expect($tokens->expiresAt->format('Y-m-d H:i:s'))->toBe('2024-06-15 13:00:00');

            Carbon::setTestNow();
        });

        it('handles missing expires_in', function () {
            $response = [
                'access_token' => 'access_123',
                'refresh_token' => 'refresh_456',
            ];

            $tokens = OAuthTokensData::fromAnafResponse($response);

            expect($tokens->expiresAt)->toBeNull();
            expect($tokens->expiresIn)->toBeNull();
        });

        it('defaults token type to Bearer', function () {
            $response = [
                'access_token' => 'access_123',
                'refresh_token' => 'refresh_456',
            ];

            $tokens = OAuthTokensData::fromAnafResponse($response);

            expect($tokens->tokenType)->toBe('Bearer');
        });
    });

    describe('isExpired', function () {
        it('returns false when expiresAt is null', function () {
            $tokens = new OAuthTokensData(
                accessToken: 'access',
                refreshToken: 'refresh',
            );

            expect($tokens->isExpired())->toBeFalse();
        });

        it('returns true when token is expired', function () {
            Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));

            $tokens = new OAuthTokensData(
                accessToken: 'access',
                refreshToken: 'refresh',
                expiresAt: Carbon::create(2024, 6, 15, 11, 0, 0), // 1 hour ago
            );

            expect($tokens->isExpired())->toBeTrue();

            Carbon::setTestNow();
        });

        it('returns true when token expires within buffer', function () {
            Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));

            $tokens = new OAuthTokensData(
                accessToken: 'access',
                refreshToken: 'refresh',
                expiresAt: Carbon::create(2024, 6, 15, 12, 0, 20), // Expires in 20 seconds
            );

            // Default buffer is 30 seconds, so this should be "expired"
            expect($tokens->isExpired())->toBeTrue();

            Carbon::setTestNow();
        });

        it('returns false when token is not expired', function () {
            Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));

            $tokens = new OAuthTokensData(
                accessToken: 'access',
                refreshToken: 'refresh',
                expiresAt: Carbon::create(2024, 6, 15, 13, 0, 0), // 1 hour from now
            );

            expect($tokens->isExpired())->toBeFalse();

            Carbon::setTestNow();
        });

        it('accepts custom buffer seconds', function () {
            Carbon::setTestNow(Carbon::create(2024, 6, 15, 12, 0, 0));

            $tokens = new OAuthTokensData(
                accessToken: 'access',
                refreshToken: 'refresh',
                expiresAt: Carbon::create(2024, 6, 15, 12, 0, 45), // Expires in 45 seconds
            );

            // Token expires at 12:00:45
            // With 30 second buffer: 12:00:45 - 30s = 12:00:15 -> not past -> NOT expired
            // With 60 second buffer: 12:00:45 - 60s = 11:59:45 -> past -> expired
            // With 10 second buffer: 12:00:45 - 10s = 12:00:35 -> not past -> NOT expired

            // Token is NOT expired with 30 second buffer (12:00:15 is in the future)
            expect($tokens->isExpired(30))->toBeFalse();

            // Token IS expired with 60 second buffer (11:59:45 is in the past)
            expect($tokens->isExpired(60))->toBeTrue();

            // Token is NOT expired with 10 second buffer
            expect($tokens->isExpired(10))->toBeFalse();

            Carbon::setTestNow();
        });
    });
});

describe('AuthUrlSettingsData', function () {
    it('creates with default values', function () {
        $settings = new AuthUrlSettingsData;

        expect($settings->state)->toBeNull();
        expect($settings->scope)->toBeNull();
    });

    it('creates with state', function () {
        $state = ['user_id' => 123, 'redirect' => '/dashboard'];
        $settings = new AuthUrlSettingsData(state: $state);

        expect($settings->state)->toBe($state);
    });

    it('creates with scope', function () {
        $settings = new AuthUrlSettingsData(scope: 'read write');

        expect($settings->scope)->toBe('read write');
    });

    it('creates with all fields', function () {
        $state = ['key' => 'value'];
        $settings = new AuthUrlSettingsData(
            state: $state,
            scope: 'full_access',
        );

        expect($settings->state)->toBe($state);
        expect($settings->scope)->toBe('full_access');
    });
});
