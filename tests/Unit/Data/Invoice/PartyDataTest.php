<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

function partyPayload(array $overrides = []): array
{
    return [
        'registrationName' => 'Test Company SRL',
        'companyId' => 'RO12345678',
        'address' => [
            'street' => 'Str. Test 1',
            'city' => 'Bucuresti',
            'postalZone' => '010101',
            'county' => 'Cluj',
        ],
        ...$overrides,
    ];
}

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
            isVatPayer: false,
        );

        expect($party->registrationNumber)->toBeNull();
    });
});

// isVatPayer drives InvoiceBuilder::getTaxCategory(): when false, EVERY line is
// filed under VAT category "O" (Not subject to VAT) and the party's VAT
// identifier (BT-31/BT-48) is dropped from the XML. A defaulted false therefore
// files a VAT-registered party as a non-payer, silently and successfully — ANAF
// accepts it. The flag must be impossible to forget rather than merely
// documented, so it carries no default.
describe('PartyData isVatPayer is a required declaration', function () {
    it('is rejected as missing by the validation rules', function () {
        PartyData::validate(partyPayload());
    })->throws(ValidationException::class);

    it('names isVatPayer as the missing field', function () {
        try {
            PartyData::validate(partyPayload());
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            expect($e->validator->errors()->keys())->toContain('isVatPayer');
        }
    });

    it('generates a required rule for isVatPayer', function () {
        $rules = PartyData::getValidationRules(partyPayload());

        expect($rules)->toHaveKey('isVatPayer');

        $names = array_map(
            fn ($rule) => is_object($rule) ? $rule::class : $rule,
            $rules['isVatPayer']
        );

        expect($names)->toContain('required');
    });

    // The crux of using `required` on a bool: Laravel's validateRequired() only
    // fails null / '' / empty countable / empty File and returns true otherwise,
    // so a legitimate `false` passes. If this ever regresses, every non-VAT-payer
    // party in the wild becomes unfileable.
    it('accepts an explicit false', function () {
        $party = PartyData::validateAndCreate(partyPayload(['isVatPayer' => false]));

        expect($party->isVatPayer)->toBeFalse();
    });

    it('accepts an explicit true', function () {
        $party = PartyData::validateAndCreate(partyPayload(['isVatPayer' => true]));

        expect($party->isVatPayer)->toBeTrue();
    });

    // ::from() bypasses validation, so it must fail loudly on its own rather than
    // silently materialising a party with a fabricated flag.
    it('cannot be hydrated by ::from() without the flag', function () {
        PartyData::from(partyPayload());
    })->throws(CannotCreateData::class);

    it('hydrates by ::from() when the flag is present', function () {
        expect(PartyData::from(partyPayload(['isVatPayer' => true]))->isVatPayer)->toBeTrue();
        expect(PartyData::from(partyPayload(['isVatPayer' => false]))->isVatPayer)->toBeFalse();
    });
});

// v2's signature was (registrationName, companyId, address, ?string $registrationNumber = null,
// bool $isVatPayer = false). v3 made isVatPayer required and moved it 5th -> 4th, so a v2
// POSITIONAL caller lands its registrationNumber string on isVatPayer. That shape is the
// idiomatic one — v2's README and test helper both pass registrationNumber.
//
// In COERCIVE mode (a caller file without declare(strict_types=1) — the Laravel application
// default) a non-empty string binds to `bool` as TRUE. So a micro-enterprise that relied on
// v2's `false` default silently flips to VAT payer: every line moves from VAT category O to
// Z and the party gains a BT-31 seller VAT id it does not hold. The document stays internally
// consistent, so the real schematron passes it (BR-Z-01/02/09/10, BR-48, BR-CO-17 all green)
// and ANAF accepts and files it. Nothing errors — which is precisely the failure the docblock
// claims to have made unrepresentable.
//
// Reproduction vector: reflection instantiation binds arguments COERCIVELY regardless of this
// file's strict_types (the call originates from internal code, not a userland strict file), so
// it models the app caller exactly while keeping the test in a strict file. A non-strict helper
// file would not survive pint, whose declare_strict_types rule would rewrite it and silently
// turn these into TypeError assertions that prove nothing.
function constructPartyCoercively(array $args): PartyData
{
    return (new ReflectionClass(PartyData::class))->newInstanceArgs($args);
}

describe('PartyData rejects the v2 positional argument order', function () {
    $address = fn () => new AddressData(
        street: 'Str. Test 1',
        city: 'Cluj-Napoca',
        postalZone: '400000',
        county: 'Cluj',
    );

    it('rejects a v2 4-arg positional call rather than flipping isVatPayer to true', function () use ($address) {
        constructPartyCoercively(['ACME', 'RO123', $address(), 'J40/1234/2020']);
    })->throws(InvalidArgumentException::class);

    it('rejects a v2 5-arg positional call rather than flipping isVatPayer to true', function () use ($address) {
        constructPartyCoercively(['ACME', 'RO123', $address(), 'J40/1234/2020', false]);
    })->throws(InvalidArgumentException::class);

    it('names isVatPayer and shows the offending value', function () use ($address) {
        try {
            constructPartyCoercively(['ACME', 'RO123', $address(), 'J40/1234/2020']);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('isVatPayer');
            expect($e->getMessage())->toContain('J40/1234/2020');
        }
    });

    // The guard must not swallow the real declarations: a coercive bool still binds.
    it('still accepts a coercively-bound true', function () use ($address) {
        $party = constructPartyCoercively(['ACME', 'RO123', $address(), true, 'J40/1234/2020']);

        expect($party->isVatPayer)->toBeTrue();
        expect($party->registrationNumber)->toBe('J40/1234/2020');
    });

    it('still accepts a coercively-bound false', function () use ($address) {
        $party = constructPartyCoercively(['ACME', 'RO123', $address(), false, 'J40/1234/2020']);

        expect($party->isVatPayer)->toBeFalse();
        expect($party->registrationNumber)->toBe('J40/1234/2020');
    });
});

// ::from() hands the RAW value to the constructor (laravel-data does not pre-cast; PHP's
// coercive binding in DataFromArrayResolver does the work), so the bool|string guard sees
// whatever the payload carried. Laravel's `boolean` rule accepts exactly true/false/1/0/'1'/'0',
// so the constructor must accept exactly those too — otherwise validateAndCreate() would pass
// validation and then explode on a payload the rule declared valid.
describe('PartyData accepts the boolean-ish payload values the validation rule accepts', function () {
    it('normalises the string and int forms that Laravel\'s boolean rule permits', function (mixed $input, bool $expected) {
        expect(PartyData::from(partyPayload(['isVatPayer' => $input]))->isVatPayer)->toBe($expected);
        expect(PartyData::validateAndCreate(partyPayload(['isVatPayer' => $input]))->isVatPayer)->toBe($expected);
    })->with([
        [1, true],
        [0, false],
        ['1', true],
        ['0', false],
    ]);

    // A value the boolean rule rejects must not silently become `true` on the unvalidated
    // ::from() path — that is the same silent-flip failure, reached by a different door.
    it('rejects a non-boolean string rather than coercing it to true', function () {
        PartyData::from(partyPayload(['isVatPayer' => 'J40/1234/2020']));
    })->throws(InvalidArgumentException::class);
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
