<?php

declare(strict_types=1);

use BeeCoded\EFactura\Data\Auth\AuthUrlSettingsData;
use BeeCoded\EFactura\Data\Auth\OAuthTokensData;
use Carbon\Carbon;

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
