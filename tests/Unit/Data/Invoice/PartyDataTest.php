<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;

describe('PartyData construction', function () {
    it('has correct default values for optional fields', function () {
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
});

describe('AddressData construction', function () {
    it('has correct default values for optional fields', function () {
        $address = new AddressData(
            street: 'Str. Victoriei 10',
            city: 'Bucuresti',
            postalZone: '010101',
        );

        expect($address->county)->toBeNull();
        expect($address->countryCode)->toBe('RO');
    });
});
