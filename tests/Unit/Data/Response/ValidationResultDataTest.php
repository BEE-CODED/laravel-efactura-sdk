<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Response\ValidationResultData;

describe('ValidationResultData', function () {
    it('creates valid result', function () {
        $result = new ValidationResultData(
            valid: true,
            details: 'Document is valid',
        );

        expect($result->valid)->toBeTrue();
        expect($result->details)->toBe('Document is valid');
        expect($result->errors)->toBeNull();
    });

    it('creates invalid result with errors', function () {
        $result = new ValidationResultData(
            valid: false,
            errors: ['Error 1', 'Error 2'],
        );

        expect($result->valid)->toBeFalse();
        expect($result->errors)->toBe(['Error 1', 'Error 2']);
    });

    describe('fromAnafResponse', function () {
        it('parses valid response', function () {
            $result = ValidationResultData::fromAnafResponse([
                'valid' => true,
                'details' => 'XML is valid',
                'info' => 'Additional info',
            ]);

            expect($result->valid)->toBeTrue();
            expect($result->details)->toBe('XML is valid');
            expect($result->info)->toBe('Additional info');
        });

        it('parses invalid response with errors', function () {
            $result = ValidationResultData::fromAnafResponse([
                'valid' => false,
                'Errors' => ['Schema validation failed'],
            ]);

            expect($result->valid)->toBeFalse();
            expect($result->errors)->toBe(['Schema validation failed']);
        });

        it('defaults valid to false', function () {
            $result = ValidationResultData::fromAnafResponse([]);

            expect($result->valid)->toBeFalse();
        });
    });

    describe('success factory', function () {
        it('creates successful result', function () {
            $result = ValidationResultData::success('Validation passed');

            expect($result->valid)->toBeTrue();
            expect($result->details)->toBe('Validation passed');
        });

        it('creates successful result without details', function () {
            $result = ValidationResultData::success();

            expect($result->valid)->toBeTrue();
            expect($result->details)->toBeNull();
        });
    });

    describe('failure factory', function () {
        it('creates failed result with details', function () {
            $result = ValidationResultData::failure('Validation failed');

            expect($result->valid)->toBeFalse();
            expect($result->details)->toBe('Validation failed');
        });

        it('creates failed result with errors', function () {
            $result = ValidationResultData::failure(null, ['Error 1', 'Error 2']);

            expect($result->valid)->toBeFalse();
            expect($result->errors)->toBe(['Error 1', 'Error 2']);
        });
    });
});
