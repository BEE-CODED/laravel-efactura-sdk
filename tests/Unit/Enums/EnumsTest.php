<?php

declare(strict_types=1);

use Beecoded\EFactura\Enums\DocumentStandardType;
use Beecoded\EFactura\Enums\ExecutionStatus;
use Beecoded\EFactura\Enums\InvoiceTypeCode;
use Beecoded\EFactura\Enums\MessageFilter;
use Beecoded\EFactura\Enums\StandardType;
use Beecoded\EFactura\Enums\TaxCategoryId;
use Beecoded\EFactura\Enums\UploadStatusValue;

describe('ExecutionStatus', function () {
    it('has Success case with value 0', function () {
        expect(ExecutionStatus::Success->value)->toBe(0);
    });

    it('has Error case with value 1', function () {
        expect(ExecutionStatus::Error->value)->toBe(1);
    });

    it('can be created from value', function () {
        expect(ExecutionStatus::from(0))->toBe(ExecutionStatus::Success);
        expect(ExecutionStatus::from(1))->toBe(ExecutionStatus::Error);
    });

    it('returns null for invalid value using tryFrom', function () {
        expect(ExecutionStatus::tryFrom(2))->toBeNull();
    });
});

describe('UploadStatusValue', function () {
    it('has Ok case', function () {
        expect(UploadStatusValue::Ok->value)->toBe('ok');
    });

    it('has Failed case', function () {
        expect(UploadStatusValue::Failed->value)->toBe('nok');
    });

    it('has InProgress case', function () {
        expect(UploadStatusValue::InProgress->value)->toBe('in prelucrare');
    });

    it('can be created from value', function () {
        expect(UploadStatusValue::from('ok'))->toBe(UploadStatusValue::Ok);
        expect(UploadStatusValue::from('nok'))->toBe(UploadStatusValue::Failed);
        expect(UploadStatusValue::from('in prelucrare'))->toBe(UploadStatusValue::InProgress);
    });
});

describe('MessageFilter', function () {
    it('has InvoiceSent case', function () {
        expect(MessageFilter::InvoiceSent->value)->toBe('T');
    });

    it('has InvoiceReceived case', function () {
        expect(MessageFilter::InvoiceReceived->value)->toBe('P');
    });

    it('has InvoiceErrors case', function () {
        expect(MessageFilter::InvoiceErrors->value)->toBe('E');
    });

    it('has BuyerMessage case', function () {
        expect(MessageFilter::BuyerMessage->value)->toBe('R');
    });
});

describe('InvoiceTypeCode', function () {
    it('has CommercialInvoice case', function () {
        expect(InvoiceTypeCode::CommercialInvoice->value)->toBe('380');
    });

    it('has InvoiceInformationForAccountingPurposes case', function () {
        expect(InvoiceTypeCode::InvoiceInformationForAccountingPurposes->value)->toBe('751');
    });

    it('has CreditNote case', function () {
        expect(InvoiceTypeCode::CreditNote->value)->toBe('381');
    });
});

describe('TaxCategoryId', function () {
    it('has NotSubject case', function () {
        expect(TaxCategoryId::NotSubject->value)->toBe('O');
    });

    it('has Standard case', function () {
        expect(TaxCategoryId::Standard->value)->toBe('S');
    });

    it('has ZeroRated case', function () {
        expect(TaxCategoryId::ZeroRated->value)->toBe('Z');
    });
});

describe('StandardType', function () {
    it('has UBL case', function () {
        expect(StandardType::UBL->value)->toBe('UBL');
    });

    it('has CN case', function () {
        expect(StandardType::CN->value)->toBe('CN');
    });

    it('has CII case', function () {
        expect(StandardType::CII->value)->toBe('CII');
    });

    it('has RASP case', function () {
        expect(StandardType::RASP->value)->toBe('RASP');
    });
});

describe('DocumentStandardType', function () {
    it('has FACT1 case', function () {
        expect(DocumentStandardType::FACT1->value)->toBe('FACT1');
    });

    it('has FCN case', function () {
        expect(DocumentStandardType::FCN->value)->toBe('FCN');
    });
});
