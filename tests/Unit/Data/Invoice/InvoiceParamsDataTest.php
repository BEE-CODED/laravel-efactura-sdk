<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Invoice\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\PaginatedMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Invoice\UploadOptionsData;
use BeeCoded\EFacturaSdk\Enums\MessageFilter;
use BeeCoded\EFacturaSdk\Enums\StandardType;
use Carbon\Carbon;

describe('ListMessagesParamsData', function () {
    it('has correct default values', function () {
        $params = new ListMessagesParamsData(
            cif: '12345678',
            days: 30,
        );

        expect($params->filter)->toBeNull();
    });
});

describe('PaginatedMessagesParamsData', function () {
    it('has correct default values', function () {
        $params = new PaginatedMessagesParamsData(
            cif: '12345678',
            startTime: 1704067200000,
            endTime: 1704153600000,
        );

        expect($params->page)->toBe(1);
        expect($params->filter)->toBeNull();
    });

    describe('fromDateRange', function () {
        it('creates from Carbon dates', function () {
            $startDate = Carbon::create(2024, 1, 1, 0, 0, 0, 'UTC');
            $endDate = Carbon::create(2024, 1, 2, 0, 0, 0, 'UTC');

            $params = PaginatedMessagesParamsData::fromDateRange(
                cif: '12345678',
                startDate: $startDate,
                endDate: $endDate,
            );

            expect($params->cif)->toBe('12345678');
            expect($params->startTime)->toBe($startDate->getTimestampMs());
            expect($params->endTime)->toBe($endDate->getTimestampMs());
        });

        it('accepts page and filter parameters', function () {
            $startDate = Carbon::create(2024, 1, 1);
            $endDate = Carbon::create(2024, 1, 2);

            $params = PaginatedMessagesParamsData::fromDateRange(
                cif: '12345678',
                startDate: $startDate,
                endDate: $endDate,
                page: 3,
                filter: MessageFilter::InvoiceReceived,
            );

            expect($params->page)->toBe(3);
            expect($params->filter)->toBe(MessageFilter::InvoiceReceived);
        });
    });
});

describe('UploadOptionsData', function () {
    it('has correct default values', function () {
        $options = new UploadOptionsData;

        expect($options->standard)->toBeNull();
        expect($options->extern)->toBeFalse();
        expect($options->selfBilled)->toBeFalse();
        expect($options->executare)->toBeFalse();
    });

    describe('getStandard', function () {
        it('returns specified standard', function () {
            $options = new UploadOptionsData(standard: StandardType::CN);

            expect($options->getStandard())->toBe(StandardType::CN);
        });

        it('defaults to UBL when standard is null', function () {
            $options = new UploadOptionsData;

            expect($options->getStandard())->toBe(StandardType::UBL);
        });
    });
});
