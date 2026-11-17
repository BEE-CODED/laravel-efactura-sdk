<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Builders\InvoiceBuilder;
use BeeCoded\EFacturaSdk\Data\Invoice\AddressData;
use BeeCoded\EFacturaSdk\Data\Invoice\DeliveryData;
use BeeCoded\EFacturaSdk\Data\Invoice\ExemptionReasonData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceData;
use BeeCoded\EFacturaSdk\Data\Invoice\InvoiceLineData;
use BeeCoded\EFacturaSdk\Data\Invoice\PartyData;
use BeeCoded\EFacturaSdk\Data\Invoice\PrecedingInvoiceData;
use BeeCoded\EFacturaSdk\Enums\InvoiceTypeCode;
use BeeCoded\EFacturaSdk\Enums\TaxCategoryId;
use BeeCoded\EFacturaSdk\Exceptions\ValidationException;
use Carbon\CarbonImmutable;

/*
 * Builder rules added for 4.0 (plan2 items S9-S19, X1-X7), exercised through the real
 * InvoiceBuilder::buildInvoiceXml() and asserted on the emitted XML.
 */

function rbAddress(array $overrides = []): AddressData
{
    return new AddressData(...array_merge([
        'street' => 'Str. Exemplu 1', 'city' => 'Cluj-Napoca', 'postalZone' => '400001', 'county' => 'Cluj', 'countryCode' => 'RO',
    ], $overrides));
}

function rbParty(array $overrides = []): PartyData
{
    return new PartyData(...array_merge([
        'registrationName' => 'Firma SRL', 'companyId' => 'RO12345678', 'address' => rbAddress(), 'isVatPayer' => true,
    ], $overrides));
}

function rbLine(array $overrides = []): InvoiceLineData
{
    return new InvoiceLineData(...array_merge([
        'name' => 'Item', 'quantity' => 1, 'unitPrice' => 100, 'taxAmount' => 19, 'taxPercent' => 19,
    ], $overrides));
}

function rbInvoice(array $overrides = []): InvoiceData
{
    return new InvoiceData(...array_merge([
        'invoiceNumber' => 'F-1', 'issueDate' => '2026-11-10',
        'supplier' => rbParty(), 'customer' => rbParty(['registrationName' => 'Client SRL', 'companyId' => 'RO87654321']),
        'lines' => [rbLine()], 'dueDate' => '2026-12-10',
    ], $overrides));
}

function rbXml(InvoiceData $invoice): string
{
    return (new InvoiceBuilder)->buildInvoiceXml($invoice);
}

/** @return list<string> text of every node matching $query */
function rbAll(string $xml, string $query, bool $names = false): array
{
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

    $values = [];
    foreach ($xpath->query($query) as $node) {
        $values[] = $names ? $node->localName : $node->textContent;
    }

    return $values;
}

/** @return list<string> local names of every node matching $query */
function rbNames(string $xml, string $query): array
{
    return rbAll($xml, $query, names: true);
}

function rbKInvoice(?DeliveryData $delivery, array $overrides = []): InvoiceData
{
    return rbInvoice([
        'customer' => rbParty(['registrationName' => 'Kunde GmbH', 'companyId' => 'DE123456789', 'address' => rbAddress(['city' => 'Berlin', 'county' => null, 'countryCode' => 'DE', 'postalZone' => '10115'])]),
        'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::IntraCommunitySupply])],
        'delivery' => $delivery,
        ...$overrides,
    ]);
}

describe('X6 exact amounts on the builder path', function () {
    it('files decimal strings verbatim, including 17 significant digits', function () {
        $xml = rbXml(rbInvoice([
            'lines' => [rbLine(['quantity' => '12345678901.234567', 'unitPrice' => '0.000001', 'taxAmount' => '2345.68', 'taxPercent' => '19'])],
            'currency' => 'EUR', 'taxAmountRon' => '11672.67', 'payableRoundingAmount' => '0.01',
        ]));

        expect(rbAll($xml, '//cac:InvoiceLine/cbc:InvoicedQuantity'))->toBe(['12345678901.234567'])
            ->and(rbAll($xml, '//cac:InvoiceLine/cac:Price/cbc:PriceAmount'))->toBe(['0.000001'])
            ->and(rbAll($xml, '//cac:InvoiceLine/cbc:LineExtensionAmount'))->toBe(['12345.68'])
            ->and(rbAll($xml, '//cac:ClassifiedTaxCategory/cbc:Percent'))->toBe(['19.00'])
            ->and(rbAll($xml, '/*/cac:TaxTotal/cbc:TaxAmount[@currencyID="RON"]'))->toBe(['11672.67'])
            ->and(rbAll($xml, '//cbc:PayableRoundingAmount'))->toBe(['0.01'])
            ->and(rbAll($xml, '//cbc:PayableAmount'))->toBe(['14691.37']);
    });

    it('reads floats with binary noise at the filed precision', function () {
        $xml = rbXml(rbInvoice(['lines' => [rbLine(['quantity' => 3, 'unitPrice' => 0.1 + 0.2, 'taxAmount' => 0.171, 'taxPercent' => 19.0])]]));

        expect(rbAll($xml, '//cac:Price/cbc:PriceAmount'))->toBe(['0.30'])
            ->and(rbAll($xml, '//cac:InvoiceLine/cbc:LineExtensionAmount'))->toBe(['0.90'])
            ->and(rbAll($xml, '/*/cac:TaxTotal/cbc:TaxAmount'))->toBe(['0.17']);
    });

    it('refuses excess precision instead of rounding it, naming the field', function (array $overrides, string $field) {
        expect(fn () => rbXml(rbInvoice($overrides)))->toThrow(ValidationException::class, $field.' must be a finite amount');
    })->with([
        'quantity' => [['lines' => [rbLine(['quantity' => '1.0000001'])]], 'lines.0.quantity'],
        'unit price' => [['lines' => [rbLine(['unitPrice' => 0.0000001])]], 'lines.0.unitPrice'],
        'line tax' => [['lines' => [rbLine(['taxAmount' => NAN])]], 'lines.0.taxAmount'],
        'rate' => [['lines' => [rbLine(['taxPercent' => '19.001'])]], 'lines.0.taxPercent'],
        'BT-111' => [['currency' => 'EUR', 'taxAmountRon' => 0.004], 'taxAmountRon'],
        'BT-114' => [['payableRoundingAmount' => '0.001'], 'payableRoundingAmount'],
    ]);
});

describe('S9 BR-CO-25 due date for a positive amount due', function () {
    it('refuses an invoice with a positive payable and no due date', function (InvoiceTypeCode $type, ?string $dueDate) {
        expect(fn () => rbXml(rbInvoice(['dueDate' => $dueDate, 'invoiceTypeCode' => $type])))
            ->toThrow(ValidationException::class, 'BR-CO-25');
    })->with([InvoiceTypeCode::CommercialInvoice, InvoiceTypeCode::CorrectedInvoice])
        ->with(['missing' => null, 'empty' => '', 'blank' => '  ']);

    it('refuses a blank due date where BR-CO-25 does not apply, never filing today', function (array $overrides, string $dueDate) {
        expect(fn () => rbXml(rbInvoice(['dueDate' => $dueDate, ...$overrides])))
            ->toThrow(ValidationException::class, 'BT-9');
    })->with([
        'credit note' => [['invoiceTypeCode' => InvoiceTypeCode::CreditNote]],
        'zero payable' => [['lines' => [rbLine(['unitPrice' => 0, 'taxAmount' => 0])]]],
    ])->with(['empty' => '', 'blank' => ' ']);

    it('leaves credit notes and non-positive invoices alone', function (array $overrides) {
        expect(rbXml(rbInvoice(['dueDate' => null, ...$overrides])))->toContain('<cbc:PayableAmount');
    })->with([
        'credit note' => [['invoiceTypeCode' => InvoiceTypeCode::CreditNote]],
        'zero payable' => [['lines' => [rbLine(['unitPrice' => 0, 'taxAmount' => 0])]]],
        'negative payable' => [['lines' => [rbLine(['quantity' => -1, 'taxAmount' => -19])]]],
    ]);
});

describe('H header dates are real dates', function () {
    it('refuses an unparseable issue date as a translated error', function () {
        expect(fn () => rbXml(rbInvoice(['issueDate' => 'not a date'])))->toThrow(ValidationException::class, 'BT-2');
    });

    it('refuses a date that names no calendar day or clock time instead of filing the next one', function (Closure $overrides, string $key) {
        expect(fn () => rbXml(rbInvoice($overrides())))->toThrow(ValidationException::class, $key);
    })->with([
        'issue date' => [fn () => ['issueDate' => '2024-02-30'], 'BT-2'],
        'due date' => [fn () => ['dueDate' => '2024-04-31'], 'BT-9'],
        'tax point date' => [fn () => ['taxPointDate' => '2024-02-30'], 'BT-7'],
        'tax point time 24:00' => [fn () => ['taxPointDate' => '2024-01-01 24:00:00'], 'BT-7'],
        'delivery date' => [fn () => ['delivery' => new DeliveryData('2023-02-29')], 'BT-72'],
        'preceding invoice date' => [fn () => ['precedingInvoices' => [new PrecedingInvoiceData('F-1', '2024-02-30')]], 'BT-26'],
        'a month-first slash date' => [fn () => ['issueDate' => '05/03/2024'], 'BT-2'],
    ]);
});

describe('S10/S11 Bucharest rewriting only for RO, and only with a sector', function () {
    it('keeps foreign counties and cities that look like Bucharest or a sector', function (string $country, string $city, string $county) {
        $xml = rbXml(rbInvoice(['customer' => rbParty(['companyId' => 'X1', 'address' => rbAddress(['countryCode' => $country, 'city' => $city, 'county' => $county])])]));

        expect(rbAll($xml, '//cac:AccountingCustomerParty//cac:PostalAddress/cbc:CountrySubentity'))->toBe([$county])
            ->and(rbAll($xml, '//cac:AccountingCustomerParty//cac:PostalAddress/cbc:CityName'))->toBe([$city]);
    })->with([
        'Barcelona' => ['ES', 'Barcelona', 'B'],
        'Italian sector' => ['IT', 'Roma', 'Sector 3'],
        'explicit foreign RO-B' => ['FR', 'Paris', 'RO-B'],
    ]);

    it('files no CountrySubentity for a blank foreign county', function (?string $county) {
        $xml = rbXml(rbInvoice(['customer' => rbParty(['registrationName' => 'Kunde GmbH', 'companyId' => 'DE123456789', 'address' => rbAddress(['city' => 'Berlin', 'county' => $county, 'countryCode' => 'DE', 'postalZone' => '10115'])])]));

        expect(rbAll($xml, '//cac:AccountingCustomerParty//cac:PostalAddress/cbc:CountrySubentity'))->toBe([]);
    })->with(['null' => [null], 'empty' => [''], 'blank' => ['  ']]);

    it('refuses an RO Bucharest address without a resolvable sector', function (string $city, string $county) {
        expect(fn () => rbXml(rbInvoice(['customer' => rbParty(['companyId' => 'RO87654321', 'address' => rbAddress(['city' => $city, 'county' => $county])])])))
            ->toThrow(ValidationException::class, 'needs its sector');
    })->with([
        ['Bucuresti', 'Bucuresti'],
        ['Popesti-Leordeni', 'B'],
        ['Sect 7', 'B'],
    ]);

    it('files the sector from bucharestSector, the city or the county', function (array $address, string $expected) {
        $xml = rbXml(rbInvoice(['customer' => rbParty(['companyId' => 'RO87654321', 'address' => rbAddress($address)])]));

        expect(rbAll($xml, '//cac:AccountingCustomerParty//cbc:CityName'))->toBe([$expected])
            ->and(rbAll($xml, '//cac:AccountingCustomerParty//cbc:CountrySubentity'))->toBe(['RO-B']);
    })->with([
        'explicit sector' => [['city' => 'Bucuresti', 'county' => 'Municipiul Bucuresti', 'bucharestSector' => 4], 'SECTOR4'],
        'sector in city' => [['city' => 'Sector 2', 'county' => 'B'], 'SECTOR2'],
        'sector in county' => [['city' => 'Bucuresti', 'county' => 'Sector 6'], 'SECTOR6'],
    ]);

    it('checks the city a Bucharest address files, not the one given', function (string $city) {
        $xml = rbXml(rbInvoice(['customer' => rbParty(['companyId' => 'RO87654321', 'address' => rbAddress(['county' => 'Bucuresti', 'bucharestSector' => 3, 'city' => $city])])]));

        expect(rbAll($xml, '//cac:AccountingCustomerParty//cbc:CityName'))->toBe(['SECTOR3']);
    })->with(['60 characters' => str_repeat('C', 60), 'empty' => '']);
});

describe('S12 BT-111 is zero without standard-rated lines', function () {
    it('refuses a non-zero RON VAT on a document without S lines', function (array $lines, bool $supplierVat) {
        expect(fn () => rbXml(rbInvoice([
            'supplier' => rbParty(['isVatPayer' => $supplierVat]),
            'lines' => $lines, 'currency' => 'EUR', 'taxAmountRon' => '123.45',
        ])))->toThrow(ValidationException::class, 'exactly zero taxAmountRon');
    })->with([
        'Z only' => [[rbLine(['taxAmount' => 0, 'taxPercent' => 0])], true],
        'O only' => [[rbLine(['taxAmount' => 0, 'taxPercent' => 0])], false],
    ]);

    it('accepts zero on Z-only and any figure once an S line exists', function () {
        $z = rbXml(rbInvoice(['lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0])], 'currency' => 'EUR', 'taxAmountRon' => 0]));
        $s = rbXml(rbInvoice(['currency' => 'EUR', 'taxAmountRon' => '94.50']));

        expect(rbAll($z, '/*/cac:TaxTotal/cbc:TaxAmount[@currencyID="RON"]'))->toBe(['0.00'])
            ->and(rbAll($s, '/*/cac:TaxTotal/cbc:TaxAmount[@currencyID="RON"]'))->toBe(['94.50']);
    });
});

describe('S13/X7 VAT categories E, G and K with exemption reasons', function () {
    it('files E with the caller reason, rate 0 and tax 0', function () {
        $xml = rbXml(rbInvoice([
            'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::Exempt])],
            'exemptionReasons' => [new ExemptionReasonData(TaxCategoryId::Exempt, 'VATEX-EU-132-1I', 'Scutit conform art. 292')],
        ]));

        expect(rbAll($xml, '//cac:TaxSubtotal/cac:TaxCategory/cbc:ID'))->toBe(['E'])
            ->and(rbAll($xml, '//cac:TaxSubtotal/cac:TaxCategory/cbc:Percent'))->toBe(['0.00'])
            ->and(rbAll($xml, '//cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode'))->toBe(['VATEX-EU-132-1I'])
            ->and(rbAll($xml, '//cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReason'))->toBe(['Scutit conform art. 292'])
            ->and(rbAll($xml, '//cac:ClassifiedTaxCategory/cbc:Percent'))->toBe(['0.00'])
            ->and(rbAll($xml, '/*/cac:TaxTotal/cbc:TaxAmount'))->toBe(['0.00']);
    });

    it('files fixed codes for G, K and AE with optional caller text', function () {
        $g = rbXml(rbInvoice(['lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::Export])]]));
        $k = rbXml(rbKInvoice(new DeliveryData('2026-11-05', rbAddress(['countryCode' => 'DE', 'city' => 'Berlin', 'county' => 'Berlin'])), [
            'exemptionReasons' => [new ExemptionReasonData(TaxCategoryId::IntraCommunitySupply, text: 'Livrare intracomunitara')],
        ]));
        $ae = rbXml(rbInvoice([
            'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::ReverseCharge])],
            'exemptionReasons' => [new ExemptionReasonData(TaxCategoryId::ReverseCharge, 'VATEX-EU-AE', 'Taxare inversa conform art. 331')],
        ]));
        $aeDefault = rbXml(rbInvoice(['lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::ReverseCharge])]]));

        expect(rbAll($g, '//cac:TaxSubtotal//cbc:TaxExemptionReasonCode'))->toBe(['VATEX-EU-G'])
            ->and(rbAll($g, '//cac:TaxSubtotal//cbc:TaxExemptionReason'))->toBe([])
            ->and(rbAll($k, '//cac:TaxSubtotal//cbc:TaxExemptionReasonCode'))->toBe(['VATEX-EU-IC'])
            ->and(rbAll($k, '//cac:TaxSubtotal//cbc:TaxExemptionReason'))->toBe(['Livrare intracomunitara'])
            ->and(rbAll($ae, '//cac:TaxSubtotal//cbc:TaxExemptionReason'))->toBe(['Taxare inversa conform art. 331'])
            ->and(rbAll($aeDefault, '//cac:TaxSubtotal//cbc:TaxExemptionReasonCode'))->toBe(['VATEX-EU-AE'])
            ->and(rbAll($aeDefault, '//cac:TaxSubtotal//cbc:TaxExemptionReason'))->toBe(['Taxare inversa']);
    });

    it('keeps a 0% line without a category as Z with no reason', function () {
        $xml = rbXml(rbInvoice(['lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0])]]));

        expect(rbAll($xml, '//cac:TaxSubtotal/cac:TaxCategory/cbc:ID'))->toBe(['Z'])
            ->and(rbAll($xml, '//cac:TaxSubtotal//cbc:TaxExemptionReasonCode'))->toBe([]);
    });

    it('refuses invalid exemption reasons and missing requirements', function (Closure $invoice, string $message) {
        expect(fn () => rbXml($invoice()))->toThrow(ValidationException::class, $message);
    })->with([
        'E without a reason' => [fn () => rbInvoice(['lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::Exempt])]]), 'BR-E-10'],
        'E with an unknown code' => [fn () => rbInvoice([
            'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::Exempt])],
            'exemptionReasons' => [new ExemptionReasonData(TaxCategoryId::Exempt, 'VATEX-EU-999')],
        ]), 'BR-CL-22'],
        'reason for Z' => [fn () => rbInvoice([
            'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0])],
            'exemptionReasons' => [new ExemptionReasonData(TaxCategoryId::ZeroRated, text: 'x')],
        ]), 'not Z'],
        'duplicate category' => [fn () => rbInvoice([
            'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::Export])],
            'exemptionReasons' => [new ExemptionReasonData(TaxCategoryId::Export), new ExemptionReasonData(TaxCategoryId::Export)],
        ]), 'more than one'],
        'category without lines' => [fn () => rbInvoice([
            'exemptionReasons' => [new ExemptionReasonData(TaxCategoryId::Export)],
        ]), 'no line uses'],
        'wrong fixed code' => [fn () => rbInvoice([
            'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::Export])],
            'exemptionReasons' => [new ExemptionReasonData(TaxCategoryId::Export, 'VATEX-EU-IC')],
        ]), 'always VATEX-EU-G'],
        'text over 100' => [fn () => rbInvoice([
            'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::Exempt])],
            'exemptionReasons' => [new ExemptionReasonData(TaxCategoryId::Exempt, text: str_repeat('a', 101))],
        ]), 'BR-RO-L100'],
        'E with a VAT rate' => [fn () => rbInvoice(['lines' => [rbLine(['taxCategoryId' => TaxCategoryId::Exempt])]]), 'exactly zero raw'],
        'G for a non-VAT supplier' => [fn () => rbInvoice([
            'supplier' => rbParty(['isVatPayer' => false]),
            'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::Export])],
        ]), 'require a supplier registered for VAT'],
        'E without a usable seller VAT id' => [fn () => rbInvoice([
            'supplier' => rbParty(['companyId' => '1234-5678']),
            'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::Exempt])],
            'exemptionReasons' => [new ExemptionReasonData(TaxCategoryId::Exempt, text: 'Scutit')],
        ]), 'Supplier must be registered for VAT and supply a usable VAT identifier for VAT category E'],
        'G without a usable seller VAT id' => [fn () => rbInvoice([
            'supplier' => rbParty(['companyId' => '1234-5678']),
            'lines' => [rbLine(['taxAmount' => 0, 'taxPercent' => 0, 'taxCategoryId' => TaxCategoryId::Export])],
        ]), 'Supplier must be registered for VAT and supply a usable VAT identifier for VAT category G'],
        'K buyer not a VAT payer' => [fn () => rbKInvoice(new DeliveryData('2026-11-05', rbAddress(['countryCode' => 'DE', 'city' => 'Berlin', 'county' => 'Berlin'])), [
            'customer' => rbParty(['companyId' => 'DE123456789', 'isVatPayer' => false, 'address' => rbAddress(['countryCode' => 'DE', 'city' => 'Berlin', 'county' => null])]),
        ]), 'Customer must be registered for VAT and supply a usable VAT identifier for VAT category K'],
        'K without delivery' => [fn () => rbKInvoice(null), 'BR-IC-11'],
        'K without delivery date' => [fn () => rbKInvoice(new DeliveryData(address: rbAddress(['countryCode' => 'DE', 'city' => 'Berlin', 'county' => 'Berlin']))), 'BR-IC-11'],
        'K without delivery address' => [fn () => rbKInvoice(new DeliveryData('2026-11-05')), 'BR-IC-12'],
        'K with a blank delivery date' => [fn () => rbKInvoice(new DeliveryData(' ', rbAddress(['countryCode' => 'DE', 'city' => 'Berlin', 'county' => 'Berlin']))), 'BT-72'],
    ]);
});

describe('S14 VAT breakdown amount is exactly zero outside S and AE', function () {
    it('refuses sub-cent residues that sum to a cent in a zero-rate group', function (bool $supplierVat) {
        $lines = array_fill(0, 3, rbLine(['taxAmount' => '0.004', 'taxPercent' => 0]));

        expect(fn () => rbXml(rbInvoice(['supplier' => rbParty(['isVatPayer' => $supplierVat]), 'lines' => $lines])))
            ->toThrow(ValidationException::class, 'must be exactly zero once its lines are summed');
    })->with(['Z' => true, 'O' => false]);

    it('still accepts residues that cancel out', function () {
        $xml = rbXml(rbInvoice(['lines' => [rbLine(['taxAmount' => '0.004', 'taxPercent' => 0]), rbLine(['taxAmount' => '-0.004', 'taxPercent' => 0])]]));

        expect(rbAll($xml, '//cac:TaxSubtotal/cbc:TaxAmount'))->toBe(['0.00']);
    });
});

describe('line VAT sign follows the quantity', function () {
    it('refuses a line whose VAT sign is opposite to its quantity', function (Closure $invoice) {
        expect(fn () => rbXml($invoice()))->toThrow(ValidationException::class, 'same sign as the quantity');
    })->with([
        'credit note, tax not negated' => [fn () => rbInvoice(['invoiceTypeCode' => InvoiceTypeCode::CreditNote, 'lines' => [rbLine(['quantity' => -2, 'taxAmount' => 38, 'taxPercent' => 19])]])],
        'invoice with negative tax' => [fn () => rbInvoice(['lines' => [rbLine(['taxAmount' => -19])]])],
    ]);
});

describe('S15 currency and country codes', function () {
    it('refuses malformed currency codes', function (string $currency) {
        expect(fn () => rbXml(rbInvoice(['currency' => $currency])))->toThrow(ValidationException::class, 'BR-CL-04');
    })->with(['', 'ron', 'Eur', 'EURO', 'RON ']);

    it('refuses malformed country codes', function (string $country) {
        expect(fn () => rbXml(rbInvoice(['customer' => rbParty(['address' => rbAddress(['countryCode' => $country])])])))
            ->toThrow(ValidationException::class, 'Customer country code must be two uppercase letters');
    })->with(['', 'ro', 'ROU', ' RO']);
});

describe('S16 text that XML cannot carry', function () {
    it('refuses invalid UTF-8 and non-XML characters, naming the field path', function (Closure $invoice, string $path) {
        expect(fn () => rbXml($invoice()))->toThrow(ValidationException::class, $path.' contains invalid UTF-8');
    })->with([
        'vertical tab in a line name' => [fn () => rbInvoice(['lines' => [rbLine(['name' => "a\x0Bb"])]]), 'lines.0.name'],
        'NUL in the street' => [fn () => rbInvoice(['supplier' => rbParty(['address' => rbAddress(['street' => "Str\x00 1"])])]), 'supplier.address.street'],
        'U+FFFE in a description' => [fn () => rbInvoice(['lines' => [rbLine(['description' => "x\u{FFFE}"])]]), 'lines.0.description'],
        'Latin-1 byte in the customer name' => [fn () => rbInvoice(['customer' => rbParty(['registrationName' => "Caf\xE9"])]), 'customer.registrationName'],
        'lone continuation byte in a note' => [fn () => rbInvoice(['notes' => ['ok', "\x80"]]), 'notes.1'],
    ]);

    it('keeps tab, line breaks, DEL, U+0085 and markup characters', function () {
        $name = "a\tb\r\nc\x7F\u{85}<&>\"'";
        $xml = rbXml(rbInvoice(['lines' => [rbLine(['name' => $name])]]));

        expect(rbAll($xml, '//cac:Item/cbc:Name'))->toBe([$name]);
    });
});

describe('S17 seller registration number as BT-33', function () {
    it('files the seller registration number as CompanyLegalForm and never the buyer one', function () {
        $xml = rbXml(rbInvoice([
            'supplier' => rbParty(['registrationNumber' => 'J40/1234/2020']),
            'customer' => rbParty(['companyId' => 'RO87654321', 'registrationNumber' => 'J12/1/2001']),
        ]));

        expect(rbAll($xml, '//cac:AccountingSupplierParty//cac:PartyLegalEntity/*'))->toBe(['Firma SRL', '12345678', 'J40/1234/2020'])
            ->and(rbAll($xml, '//cac:AccountingCustomerParty//cbc:CompanyLegalForm'))->toBe([]);
    });

    it('omits a blank registration number and refuses one over 1000 characters', function () {
        $xml = rbXml(rbInvoice(['supplier' => rbParty(['registrationNumber' => '  '])]));

        expect(rbAll($xml, '//cbc:CompanyLegalForm'))->toBe([])
            ->and(fn () => rbXml(rbInvoice(['supplier' => rbParty(['registrationNumber' => str_repeat('J', 1001)])])))
            ->toThrow(ValidationException::class, 'BR-RO-L1000');
    });
});

describe('S19 required fields are checked after trimming', function () {
    it('refuses whitespace-only required fields', function (Closure $invoice, string $message) {
        expect(fn () => rbXml($invoice()))->toThrow(ValidationException::class, $message);
    })->with([
        'invoice number' => [fn () => rbInvoice(['invoiceNumber' => '  ']), 'Invoice number is required'],
        'issue date' => [fn () => rbInvoice(['issueDate' => ' ']), 'Issue date is required'],
        'registration name' => [fn () => rbInvoice(['supplier' => rbParty(['registrationName' => ' '])]), 'Supplier registration name is required'],
        'company id' => [fn () => rbInvoice(['customer' => rbParty(['companyId' => "\t"])]), 'Customer company ID'],
        'street' => [fn () => rbInvoice(['supplier' => rbParty(['address' => rbAddress(['street' => ' '])])]), 'Supplier street address is required'],
        'city' => [fn () => rbInvoice(['supplier' => rbParty(['address' => rbAddress(['city' => ' '])])]), 'Supplier city is required'],
        'RO county' => [fn () => rbInvoice(['supplier' => rbParty(['address' => rbAddress(['county' => ' '])])]), 'BR-RO-110'],
        'line name' => [fn () => rbInvoice(['lines' => [rbLine(['name' => ' '])]]), 'Item name is required'],
    ]);

    it('accepts an invoice number of "0", which empty() used to refuse', function () {
        expect(rbAll(rbXml(rbInvoice(['invoiceNumber' => '0'])), '/*/cbc:ID'))->toBe(['0']);
    });
});

describe('X1 preceding invoices (BG-3)', function () {
    it('files every preceding invoice with its optional issue date', function () {
        $xml = rbXml(rbInvoice([
            'invoiceTypeCode' => InvoiceTypeCode::CreditNote,
            'precedingInvoices' => [new PrecedingInvoiceData('F-0001', CarbonImmutable::parse('2026-10-01')), new PrecedingInvoiceData('F-0002')],
        ]));

        expect(rbAll($xml, '/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'))->toBe(['F-0001', 'F-0002'])
            ->and(rbAll($xml, '/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:IssueDate'))->toBe(['2026-10-01']);
    });

    it('resolves the deprecated precedingInvoiceNumber alias', function () {
        $alias = rbInvoice(['precedingInvoiceNumber' => 'F-0001']);
        $both = rbInvoice(['precedingInvoiceNumber' => 'F-0001', 'precedingInvoices' => [new PrecedingInvoiceData('F-0001', '2026-10-01')]]);

        expect(rbAll(rbXml($alias), '//cac:InvoiceDocumentReference/cbc:ID'))->toBe(['F-0001'])
            ->and($alias->getPrecedingInvoices()[0]->number)->toBe('F-0001')
            ->and(rbAll(rbXml($both), '//cac:InvoiceDocumentReference/cbc:IssueDate'))->toBe(['2026-10-01']);
    });

    it('refuses conflicting, blank, long or too many references', function (Closure $overrides, string $message) {
        expect(fn () => rbXml(rbInvoice($overrides())))->toThrow(ValidationException::class, $message);
    })->with([
        'alias differs' => [fn () => ['precedingInvoiceNumber' => 'F-9', 'precedingInvoices' => [new PrecedingInvoiceData('F-1')]], 'deprecated'],
        'alias with two entries' => [fn () => ['precedingInvoiceNumber' => 'F-1', 'precedingInvoices' => [new PrecedingInvoiceData('F-1'), new PrecedingInvoiceData('F-2')]], 'deprecated'],
        'blank number' => [fn () => ['precedingInvoices' => [new PrecedingInvoiceData(' ')]], 'BR-55'],
        'number over 200' => [fn () => ['precedingInvoices' => [new PrecedingInvoiceData(str_repeat('1', 201))]], 'BR-RO-L200'],
        'more than 500' => [fn () => ['precedingInvoices' => array_map(fn (int $i) => new PrecedingInvoiceData("F-$i"), range(1, 501))], 'BR-RO-A500'],
        'unparseable issue date' => [fn () => ['precedingInvoices' => [new PrecedingInvoiceData('F-1', 'not a date')]], 'BT-26'],
        'blank issue date' => [fn () => ['precedingInvoices' => [new PrecedingInvoiceData('F-1', ' ')]], 'BT-26'],
    ]);

    it('files 1000 lines; ANAF removed BR-RO-A999 in schematron 1.0.8', function () {
        expect(rbAll(rbXml(rbInvoice(['lines' => array_fill(0, 1000, rbLine())])), '//cac:InvoiceLine'))->toHaveCount(1000);
    });
});

describe('X2 notes, VAT point date code and delivery', function () {
    it('writes the full header sequence for an invoice and a credit note', function (InvoiceTypeCode $type) {
        $xml = rbXml(rbInvoice([
            'invoiceTypeCode' => $type, 'currency' => 'EUR', 'taxAmountRon' => '94.50',
            'notes' => ['Prima nota', 'A doua nota'], 'vatPointDateCode' => '432',
            'precedingInvoices' => [new PrecedingInvoiceData('F-0001')], 'paymentIban' => 'RO49AAAA1B31007593840000',
            'delivery' => new DeliveryData('2026-11-05', rbAddress(['bucharestSector' => 3, 'city' => 'Bucuresti', 'county' => 'Bucuresti'])),
        ]));
        $credit = $type === InvoiceTypeCode::CreditNote;

        expect(rbNames($xml, '/*/*'))->toBe([
            'CustomizationID', 'ProfileID', 'ID', 'IssueDate', ...($credit ? [] : ['DueDate']),
            $credit ? 'CreditNoteTypeCode' : 'InvoiceTypeCode', 'Note', 'Note', 'DocumentCurrencyCode', 'TaxCurrencyCode',
            'InvoicePeriod', 'BillingReference', 'AccountingSupplierParty', 'AccountingCustomerParty', 'Delivery', 'PaymentMeans',
            'TaxTotal', 'TaxTotal', 'LegalMonetaryTotal', $credit ? 'CreditNoteLine' : 'InvoiceLine',
        ])
            ->and(rbAll($xml, '/*/cac:InvoicePeriod/cbc:DescriptionCode'))->toBe(['432'])
            ->and(rbAll($xml, '/*/cbc:Note'))->toBe(['Prima nota', 'A doua nota'])
            ->and(rbNames($xml, '/*/cac:Delivery/*'))->toBe(['ActualDeliveryDate', 'DeliveryLocation'])
            ->and(rbAll($xml, '/*/cac:Delivery/cbc:ActualDeliveryDate'))->toBe(['2026-11-05'])
            ->and(rbNames($xml, '/*/cac:Delivery/cac:DeliveryLocation/cac:Address/*'))->toBe(['StreetName', 'CityName', 'PostalZone', 'CountrySubentity', 'Country'])
            ->and(rbAll($xml, '/*/cac:Delivery/cac:DeliveryLocation/cac:Address/cbc:*'))->toBe(['Str. Exemplu 1', 'SECTOR3', '400001', 'RO-B'])
            ->and(rbAll($xml, '/*/cac:Delivery//cac:Country/cbc:IdentificationCode'))->toBe(['RO']);
    })->with([InvoiceTypeCode::CommercialInvoice, InvoiceTypeCode::CreditNote]);

    it('files K to Germany with and without a county, and a date-only delivery without an Address', function () {
        $withCounty = rbXml(rbKInvoice(new DeliveryData('2026-11-05', rbAddress(['countryCode' => 'DE', 'city' => 'Berlin', 'county' => 'Berlin', 'postalZone' => '10115']))));
        $dateOnly = rbXml(rbInvoice(['delivery' => new DeliveryData('2026-11-05')]));

        expect(rbAll($withCounty, '/*/cac:Delivery/cac:DeliveryLocation/cac:Address/cbc:*'))->toBe(['Str. Exemplu 1', 'Berlin', '10115', 'Berlin'])
            ->and(rbAll($withCounty, '/*/cac:Delivery//cbc:IdentificationCode'))->toBe(['DE'])
            ->and(rbNames($dateOnly, '/*/cac:Delivery/*'))->toBe(['ActualDeliveryDate'])
            ->and(fn () => rbXml(rbKInvoice(new DeliveryData('2026-11-05', rbAddress(['countryCode' => 'DE', 'city' => 'Berlin', 'county' => null])))))
            ->toThrow(ValidationException::class, 'delivery address needs a county');
    });

    it('refuses invalid notes, codes and delivery addresses', function (Closure $overrides, string $message) {
        expect(fn () => rbXml(rbInvoice($overrides())))->toThrow(ValidationException::class, $message);
    })->with([
        'too many notes' => [fn () => ['notes' => array_fill(0, 21, 'n')], 'at most 20 notes'],
        'long note' => [fn () => ['notes' => [str_repeat('n', 301)]], 'BR-RO-L300'],
        'blank note' => [fn () => ['notes' => ['ok', ' ']], 'Note 2 must not be empty'],
        'unknown code' => [fn () => ['vatPointDateCode' => '5'], 'BT-8'],
        'code with BT-7' => [fn () => ['vatPointDateCode' => '3', 'taxPointDate' => '2026-11-01'], 'BR-CO-03'],
        'delivery without street' => [fn () => ['delivery' => new DeliveryData(address: rbAddress(['street' => ' ']))], 'Delivery street address is required'],
        'delivery RO-B without sector' => [fn () => ['delivery' => new DeliveryData(address: rbAddress(['city' => 'Bucuresti', 'county' => 'B']))], 'needs its sector'],
        'unparseable delivery date' => [fn () => ['delivery' => new DeliveryData('not a date')], 'BT-72'],
        'blank delivery date' => [fn () => ['delivery' => new DeliveryData('')], 'BT-72'],
    ]);
});

describe('J no element is filed blank', function () {
    it('refuses an element it would file blank, naming it', function (Closure $invoice, string $message) {
        expect(fn () => rbXml($invoice()))->toThrow(ValidationException::class, $message);
    })->with([
        'empty line id' => [fn () => rbInvoice(['lines' => [rbLine(['id' => ''])]]), 'The ID element would be filed blank'],
        'blank line id' => [fn () => rbInvoice(['lines' => [rbLine(['id' => '  '])]]), 'The ID element would be filed blank'],
        'customer id RO' => [fn () => rbInvoice(['customer' => rbParty(['registrationName' => 'Client SRL', 'companyId' => 'RO'])]), 'The CompanyID element would be filed blank'],
        'supplier id ro' => [fn () => rbInvoice(['supplier' => rbParty(['companyId' => 'ro'])]), 'The CompanyID element would be filed blank'],
    ]);

    it('names the blank element in Romanian under the ro locale', function () {
        app()->setLocale('ro');

        expect(fn () => rbXml(rbInvoice(['lines' => [rbLine(['id' => ''])]])))->toThrow(ValidationException::class, 'Elementul ID ar fi transmis gol');
    });

    it('omits a whitespace-only description, postal zone or IBAN', function () {
        expect(rbAll(rbXml(rbInvoice(['lines' => [rbLine(['description' => '  '])]])), '//cbc:Description'))->toBe([])
            ->and(rbAll(rbXml(rbInvoice(['supplier' => rbParty(['address' => rbAddress(['postalZone' => '  '])])])), '//cac:AccountingSupplierParty//cbc:PostalZone'))->toBe([])
            ->and(rbAll(rbXml(rbInvoice(['paymentIban' => '  '])), '//cac:PayeeFinancialAccount'))->toBe([]);
    });

    it('files a line id of 0', function () {
        expect(rbAll(rbXml(rbInvoice(['lines' => [rbLine(['id' => 0])]])), '//cac:InvoiceLine/cbc:ID'))->toBe(['0']);
    });

    it('refuses a unit code that is not a UN/ECE code, naming the line', function (string $unitCode) {
        expect(fn () => rbXml(rbInvoice(['lines' => [rbLine(), rbLine(['unitCode' => $unitCode])]])))->toThrow(ValidationException::class, 'Line 2:');
    })->with(['lowercase' => 'h87', 'inner space' => 'H 87', 'four characters' => 'KGMX', 'blank' => '  ', 'empty' => '']);

    it('files a UN/ECE unit code as given', function (string $unitCode, string $filed) {
        expect(rbAll(rbXml(rbInvoice(['lines' => [rbLine(['unitCode' => $unitCode])]])), '//cbc:InvoicedQuantity/@unitCode'))->toBe([$filed]);
    })->with(['H87' => ['H87', 'H87'], 'XPP' => ['XPP', 'XPP']]);
});

describe('messages name the line and the party', function () {
    it('names the line of a VAT category error', function () {
        expect(fn () => rbXml(rbInvoice(['lines' => [rbLine(), rbLine(['taxPercent' => 0, 'taxAmount' => 0, 'taxCategoryId' => TaxCategoryId::Standard])]])))
            ->toThrow(ValidationException::class, 'Line 2: The explicit VAT category must agree');
    });

    it('names the party in Romanian under the ro locale', function () {
        app()->setLocale('ro');

        expect(fn () => rbXml(rbInvoice(['supplier' => rbParty(['registrationName' => ' '])])))->toThrow(ValidationException::class, 'Denumirea furnizorului este obligatorie')
            ->and(fn () => rbXml(rbInvoice(['delivery' => new DeliveryData(address: rbAddress(['street' => ' ']))])))->toThrow(ValidationException::class, 'Adresa stradală a livrării este obligatorie');
    });
});
