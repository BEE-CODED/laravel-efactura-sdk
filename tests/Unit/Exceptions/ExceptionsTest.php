<?php

declare(strict_types=1);

use BeeCoded\EFactura\Exceptions\ApiException;
use BeeCoded\EFactura\Exceptions\AuthenticationException;
use BeeCoded\EFactura\Exceptions\EFacturaException;
use BeeCoded\EFactura\Exceptions\NotFoundException;
use BeeCoded\EFactura\Exceptions\RateLimitExceededException;
use BeeCoded\EFactura\Exceptions\ValidationException;
use BeeCoded\EFactura\Exceptions\XmlParsingException;

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

describe('ApiException', function () {
    it('stores HTTP status code', function () {
        $exception = new ApiException('API Error', 500);

        expect($exception->getMessage())->toBe('API Error');
        expect($exception->statusCode)->toBe(500);
        expect($exception->getCode())->toBe(500);
    });

    it('stores optional details', function () {
        $exception = new ApiException('API Error', 400, 'Invalid request body');

        expect($exception->details)->toBe('Invalid request body');
    });

    it('can include context', function () {
        $exception = new ApiException('Error', 500, null, null, ['endpoint' => '/test']);

        expect($exception->context)->toBe(['endpoint' => '/test']);
    });

    it('extends EFacturaException', function () {
        $exception = new ApiException('Error', 500);

        expect($exception)->toBeInstanceOf(EFacturaException::class);
    });
});

describe('AuthenticationException', function () {
    it('is an EFacturaException', function () {
        $exception = new AuthenticationException('Auth failed');

        expect($exception)->toBeInstanceOf(EFacturaException::class);
        expect($exception->getMessage())->toBe('Auth failed');
    });
});

describe('ValidationException', function () {
    it('is an EFacturaException', function () {
        $exception = new ValidationException('Invalid data');

        expect($exception)->toBeInstanceOf(EFacturaException::class);
        expect($exception->getMessage())->toBe('Invalid data');
    });
});

describe('NotFoundException', function () {
    it('is an EFacturaException', function () {
        $exception = new NotFoundException('Resource not found');

        expect($exception)->toBeInstanceOf(EFacturaException::class);
        expect($exception->getMessage())->toBe('Resource not found');
    });
});

describe('XmlParsingException', function () {
    it('is an EFacturaException', function () {
        $exception = new XmlParsingException('Invalid XML');

        expect($exception)->toBeInstanceOf(EFacturaException::class);
        expect($exception->getMessage())->toBe('Invalid XML');
    });

    it('stores raw response', function () {
        $exception = new XmlParsingException('Parse error', '<invalid>');

        expect($exception->rawResponse)->toBe('<invalid>');
    });

    it('has default 500 code', function () {
        $exception = new XmlParsingException('Parse error');

        expect($exception->getCode())->toBe(500);
    });

    it('can include context', function () {
        $exception = new XmlParsingException(
            'Parse error',
            '<xml>',
            500,
            null,
            ['source' => 'upload']
        );

        expect($exception->context)->toBe(['source' => 'upload']);
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

    it('extends EFacturaException', function () {
        $exception = new RateLimitExceededException('Error');

        expect($exception)->toBeInstanceOf(EFacturaException::class);
    });
});
