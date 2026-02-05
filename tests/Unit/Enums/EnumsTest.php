<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Enums\ExecutionStatus;
use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
use BeeCoded\EFacturaSdk\Enums\UploadStatusValue;

describe('ExecutionStatus', function () {
    it('can be created from value', function () {
        expect(ExecutionStatus::from(0))->toBe(ExecutionStatus::Success);
        expect(ExecutionStatus::from(1))->toBe(ExecutionStatus::Error);
    });

    it('returns null for invalid value using tryFrom', function () {
        expect(ExecutionStatus::tryFrom(2))->toBeNull();
    });
});

describe('UploadStatusValue', function () {
    it('can be created from value', function () {
        expect(UploadStatusValue::from('ok'))->toBe(UploadStatusValue::Ok);
        expect(UploadStatusValue::from('nok'))->toBe(UploadStatusValue::Failed);
        expect(UploadStatusValue::from('in prelucrare'))->toBe(UploadStatusValue::InProgress);
    });

    it('returns null for invalid value using tryFrom', function () {
        expect(UploadStatusValue::tryFrom('invalid'))->toBeNull();
    });
});

describe('InvoiceTypeCode', function () {
    it('has correct UNTDID 1001 values', function () {
        expect(InvoiceTypeCode::CommercialInvoice->value)->toBe('380');
        expect(InvoiceTypeCode::CreditNote->value)->toBe('381');
        expect(InvoiceTypeCode::CorrectedInvoice->value)->toBe('384');
        expect(InvoiceTypeCode::SelfBilledInvoice->value)->toBe('389');
        expect(InvoiceTypeCode::AccountingInvoice->value)->toBe('751');
    });

    it('identifies credit note type', function () {
        expect(InvoiceTypeCode::CreditNote->isCreditNote())->toBeTrue();
        expect(InvoiceTypeCode::CommercialInvoice->isCreditNote())->toBeFalse();
        expect(InvoiceTypeCode::CorrectedInvoice->isCreditNote())->toBeFalse();
        expect(InvoiceTypeCode::SelfBilledInvoice->isCreditNote())->toBeFalse();
        expect(InvoiceTypeCode::AccountingInvoice->isCreditNote())->toBeFalse();
    });

    it('identifies invoice types', function () {
        expect(InvoiceTypeCode::CommercialInvoice->isInvoice())->toBeTrue();
        expect(InvoiceTypeCode::CorrectedInvoice->isInvoice())->toBeTrue();
        expect(InvoiceTypeCode::SelfBilledInvoice->isInvoice())->toBeTrue();
        expect(InvoiceTypeCode::AccountingInvoice->isInvoice())->toBeTrue();
        expect(InvoiceTypeCode::CreditNote->isInvoice())->toBeFalse();
    });
});
