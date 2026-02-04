<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;

describe('PartyData construction', function () {
    it('creates party with required fields', function () {
        $address = new AddressData(
            street: 'Str. Test 1',
            city: 'Bucuresti',
            postalZone: '010101',
        );

        $party = new PartyData(
            registrationName: 'Test Company SRL',
            companyId: 'RO12345678',
            address: $address,
        );

        expect($party->registrationName)->toBe('Test Company SRL');
        expect($party->companyId)->toBe('RO12345678');
        expect($party->address)->toBeInstanceOf(AddressData::class);
    });

    it('has default values for optional fields', function () {
        $address = new AddressData(
            street: 'Str. Test 1',
            city: 'Bucuresti',
            postalZone: '010101',
        );

        $party = new PartyData(
            registrationName: 'Test Company SRL',
            companyId: 'RO12345678',
            address: $address,
        );

        expect($party->registrationNumber)->toBeNull();
        expect($party->isVatPayer)->toBeFalse();
    });

    it('accepts all optional fields', function () {
        $address = new AddressData(
            street: 'Str. Test 1',
            city: 'Bucuresti',
            postalZone: '010101',
        );

        $party = new PartyData(
            registrationName: 'Test Company SRL',
            companyId: 'RO12345678',
            address: $address,
            registrationNumber: 'J40/1234/2020',
            isVatPayer: true,
        );

        expect($party->registrationNumber)->toBe('J40/1234/2020');
        expect($party->isVatPayer)->toBeTrue();
    });
});

describe('AddressData construction', function () {
    it('creates address with required fields', function () {
        $address = new AddressData(
            street: 'Str. Victoriei 10',
            city: 'Bucuresti',
            postalZone: '010101',
        );

        expect($address->street)->toBe('Str. Victoriei 10');
        expect($address->city)->toBe('Bucuresti');
        expect($address->postalZone)->toBe('010101');
    });

    it('has default values for optional fields', function () {
        $address = new AddressData(
            street: 'Str. Victoriei 10',
            city: 'Bucuresti',
            postalZone: '010101',
        );

        expect($address->county)->toBeNull();
        expect($address->countryCode)->toBe('RO');
    });

    it('accepts all optional fields', function () {
        $address = new AddressData(
            street: 'Str. Victoriei 10',
            city: 'Bucuresti',
            postalZone: '010101',
            county: 'Sector 1',
            countryCode: 'RO',
        );

        expect($address->county)->toBe('Sector 1');
        expect($address->countryCode)->toBe('RO');
    });

    it('accepts different country codes', function () {
        $address = new AddressData(
            street: '123 Main Street',
            city: 'London',
            postalZone: 'SW1A 1AA',
            countryCode: 'GB',
        );

        expect($address->countryCode)->toBe('GB');
    });
});
