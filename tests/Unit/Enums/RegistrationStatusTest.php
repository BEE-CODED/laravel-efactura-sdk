<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Enums\RegistrationStatus;

describe('RegistrationStatus', function () {
    describe('fromAnafStatus', function () {
        it('parses INREGISTRAT as Registered', function () {
            expect(RegistrationStatus::fromAnafStatus('INREGISTRAT din data 26.06.2019'))
                ->toBe(RegistrationStatus::Registered);
        });

        it('parses RADIERE as Deregistered', function () {
            expect(RegistrationStatus::fromAnafStatus('RADIERE din data 29.03.2024'))
                ->toBe(RegistrationStatus::Deregistered);
        });

        it('parses RADIAT variants as Deregistered', function () {
            expect(RegistrationStatus::fromAnafStatus('RADIATA din data 29.03.2024'))
                ->toBe(RegistrationStatus::Deregistered);
        });

        it('is case-insensitive and diacritic-tolerant', function () {
            expect(RegistrationStatus::fromAnafStatus('Radiere din data 29.03.2024'))
                ->toBe(RegistrationStatus::Deregistered);
            expect(RegistrationStatus::fromAnafStatus('înregistrat din data 26.06.2019'))
                ->toBe(RegistrationStatus::Registered);
        });

        it('maps null, empty, and whitespace to Unknown', function () {
            expect(RegistrationStatus::fromAnafStatus(null))->toBe(RegistrationStatus::Unknown);
            expect(RegistrationStatus::fromAnafStatus(''))->toBe(RegistrationStatus::Unknown);
            expect(RegistrationStatus::fromAnafStatus('   '))->toBe(RegistrationStatus::Unknown);
        });

        it('maps unrecognized statuses to Unknown', function () {
            expect(RegistrationStatus::fromAnafStatus('INTRERUPERE TEMPORARA DE ACTIVITATE din data 01.01.2020'))
                ->toBe(RegistrationStatus::Unknown);
        });
    });
});
