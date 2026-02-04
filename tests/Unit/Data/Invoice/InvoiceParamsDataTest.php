<?php

declare(strict_types=1);

use Beecoded\EFactura\Data\Invoice\ListMessagesParamsData;
use Beecoded\EFactura\Data\Invoice\PaginatedMessagesParamsData;
use Beecoded\EFactura\Data\Invoice\UploadOptionsData;
use Beecoded\EFactura\Enums\MessageFilter;
use Beecoded\EFactura\Enums\StandardType;
use Carbon\Carbon;

describe('ListMessagesParamsData', function () {
    it('creates with required fields', function () {
        $params = new ListMessagesParamsData(
            cif: '12345678',
            days: 30,
        );

        expect($params->cif)->toBe('12345678');
        expect($params->days)->toBe(30);
        expect($params->filter)->toBeNull();
    });

    it('creates with filter', function () {
        $params = new ListMessagesParamsData(
            cif: '12345678',
            days: 7,
            filter: MessageFilter::InvoiceSent,
        );

        expect($params->filter)->toBe(MessageFilter::InvoiceSent);
    });

    it('accepts different message filters', function () {
        $params1 = new ListMessagesParamsData(cif: '12345678', days: 5, filter: MessageFilter::InvoiceReceived);
        $params2 = new ListMessagesParamsData(cif: '12345678', days: 5, filter: MessageFilter::InvoiceErrors);
        $params3 = new ListMessagesParamsData(cif: '12345678', days: 5, filter: MessageFilter::BuyerMessage);

        expect($params1->filter)->toBe(MessageFilter::InvoiceReceived);
        expect($params2->filter)->toBe(MessageFilter::InvoiceErrors);
        expect($params3->filter)->toBe(MessageFilter::BuyerMessage);
    });
});

describe('PaginatedMessagesParamsData', function () {
    it('creates with required fields', function () {
        $params = new PaginatedMessagesParamsData(
            cif: '12345678',
            startTime: 1704067200000, // 2024-01-01 00:00:00 UTC
            endTime: 1704153600000, // 2024-01-02 00:00:00 UTC
        );

        expect($params->cif)->toBe('12345678');
        expect($params->startTime)->toBe(1704067200000);
        expect($params->endTime)->toBe(1704153600000);
        expect($params->page)->toBe(1);
        expect($params->filter)->toBeNull();
    });

    it('creates with all fields', function () {
        $params = new PaginatedMessagesParamsData(
            cif: '12345678',
            startTime: 1704067200000,
            endTime: 1704153600000,
            page: 5,
            filter: MessageFilter::InvoiceSent,
        );

        expect($params->page)->toBe(5);
        expect($params->filter)->toBe(MessageFilter::InvoiceSent);
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

    describe('getStartTimeAsCarbon', function () {
        it('converts start time to Carbon', function () {
            $params = new PaginatedMessagesParamsData(
                cif: '12345678',
                startTime: 1704067200000, // 2024-01-01 00:00:00 UTC
                endTime: 1704153600000,
            );

            $carbon = $params->getStartTimeAsCarbon();

            expect($carbon)->toBeInstanceOf(Carbon::class);
            expect($carbon->getTimestampMs())->toBe(1704067200000);
        });
    });

    describe('getEndTimeAsCarbon', function () {
        it('converts end time to Carbon', function () {
            $params = new PaginatedMessagesParamsData(
                cif: '12345678',
                startTime: 1704067200000,
                endTime: 1704153600000, // 2024-01-02 00:00:00 UTC
            );

            $carbon = $params->getEndTimeAsCarbon();

            expect($carbon)->toBeInstanceOf(Carbon::class);
            expect($carbon->getTimestampMs())->toBe(1704153600000);
        });
    });
});

describe('UploadOptionsData', function () {
    it('creates with default values', function () {
        $options = new UploadOptionsData;

        expect($options->standard)->toBeNull();
        expect($options->extern)->toBeFalse();
        expect($options->selfBilled)->toBeFalse();
        expect($options->executare)->toBeFalse();
    });

    it('creates with standard type', function () {
        $options = new UploadOptionsData(
            standard: StandardType::UBL,
        );

        expect($options->standard)->toBe(StandardType::UBL);
    });

    it('creates with all options', function () {
        $options = new UploadOptionsData(
            standard: StandardType::RASP,
            extern: true,
            selfBilled: true,
            executare: true,
        );

        expect($options->standard)->toBe(StandardType::RASP);
        expect($options->extern)->toBeTrue();
        expect($options->selfBilled)->toBeTrue();
        expect($options->executare)->toBeTrue();
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
