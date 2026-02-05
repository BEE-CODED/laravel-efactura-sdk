<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;
use BeeCoded\EFacturaSdk\Exceptions\EFacturaException;
use BeeCoded\EFacturaSdk\Exceptions\RateLimitExceededException;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use BeeCoded\EFacturaSdk\Exceptions\XmlParsingException;

describe('EFacturaException', function () {
    it('can be instantiated with message', function () {
        $exception = new EFacturaException('Test error');

        expect($exception->getMessage())->toBe('Test error');
        expect($exception->getCode())->toBe(0);
        expect($exception->context)->toBe([]);
    });

    it('can include context array', function () {
        $context = ['field' => 'value', 'code' => 123];
        $exception = new EFacturaException('Test error', 500, null, $context);

        expect($exception->context)->toBe($context);
    });

    it('can chain previous exception', function () {
        $previous = new RuntimeException('Previous error');
        $exception = new EFacturaException('Test error', 0, $previous);

        expect($exception->getPrevious())->toBe($previous);
    });
});

describe('Exception hierarchy', function () {
    it('all custom exceptions extend EFacturaException', function () {
        expect(new ApiException('Test', 500))->toBeInstanceOf(EFacturaException::class);
        expect(new AuthenticationException('Test'))->toBeInstanceOf(EFacturaException::class);
        expect(new ValidationException('Test'))->toBeInstanceOf(EFacturaException::class);
        expect(new XmlParsingException('Test'))->toBeInstanceOf(EFacturaException::class);
        expect(new RateLimitExceededException('Test'))->toBeInstanceOf(EFacturaException::class);
    });
});

describe('ApiException', function () {
    it('stores HTTP status code', function () {
        $exception = new ApiException('API Error', 500);

        expect($exception->statusCode)->toBe(500);
        expect($exception->getCode())->toBe(500);
    });

    it('stores optional details', function () {
        $exception = new ApiException('API Error', 400, 'Invalid request body');

        expect($exception->details)->toBe('Invalid request body');
    });
});

describe('XmlParsingException', function () {
    it('stores raw response', function () {
        $exception = new XmlParsingException('Parse error', '<invalid>');

        expect($exception->rawResponse)->toBe('<invalid>');
    });

    it('has default 500 code', function () {
        $exception = new XmlParsingException('Parse error');

        expect($exception->getCode())->toBe(500);
    });
});

describe('RateLimitExceededException', function () {
    it('has 429 status code by default', function () {
        $exception = new RateLimitExceededException('Rate limit exceeded');

        expect($exception->getCode())->toBe(429);
    });

    it('stores remaining count', function () {
        $exception = new RateLimitExceededException('Rate limit exceeded', 0);

        expect($exception->remaining)->toBe(0);
    });

    it('stores retry after seconds', function () {
        $exception = new RateLimitExceededException('Rate limit exceeded', 0, 120);

        expect($exception->retryAfterSeconds)->toBe(120);
    });

    it('has default retry after of 60 seconds', function () {
        $exception = new RateLimitExceededException('Rate limit exceeded');

        expect($exception->retryAfterSeconds)->toBe(60);
    });
});
