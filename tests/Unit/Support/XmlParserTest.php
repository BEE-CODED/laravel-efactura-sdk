<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Enums\ExecutionStatus;
use BeeCoded\EFacturaSdk\Exceptions\XmlParsingException;
use BeeCoded\EFacturaSdk\Support\XmlParser;

describe('parseUploadResponse', function () {
    it('throws exception for empty XML', function () {
        XmlParser::parseUploadResponse('');
    })->throws(XmlParsingException::class);

    it('throws exception for invalid XML', function () {
        XmlParser::parseUploadResponse('not xml');
    })->throws(XmlParsingException::class);

    it('throws exception for unexpected structure', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<unexpected>content</unexpected>
XML;

        XmlParser::parseUploadResponse($xml);
    })->throws(XmlParsingException::class, 'Unknown or unexpected XML response structure');

    it('parses successful upload response with header element', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<header ExecutionStatus="0" index_incarcare="12345" dateResponse="202406151200"/>
XML;

        $result = XmlParser::parseUploadResponse($xml);

        expect($result['executionStatus'])->toBe(ExecutionStatus::Success->value);
        expect($result['indexIncarcare'])->toBe('12345');
        expect($result['dateResponse'])->toBe('202406151200');
        expect($result['errors'])->toBeNull();
    });

    it('parses upload response with error status', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<header ExecutionStatus="1" dateResponse="202406151200">
    <Errors errorMessage="Invalid document format"/>
</header>
XML;

        $result = XmlParser::parseUploadResponse($xml);

        expect($result['executionStatus'])->toBe(ExecutionStatus::Error->value);
        expect($result['indexIncarcare'])->toBeNull();
        // Assert the CONTENTS, not merely that it is an array: a single <Errors> node
        // must become a flat list of message strings, never the raw {"@":..., "_":...} node.
        expect($result['errors'])->toBe(['Invalid document format']);
    });

    it('handles uppercase Header element', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Header ExecutionStatus="0" index_incarcare="67890"/>
XML;

        $result = XmlParser::parseUploadResponse($xml);

        expect($result['executionStatus'])->toBe(0);
        expect($result['indexIncarcare'])->toBe('67890');
    });

    it('treats non-numeric ExecutionStatus as error', function () {
        // Malformed response with non-numeric ExecutionStatus should default to Error (1)
        // to avoid masking API failures
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<header ExecutionStatus="success" index_incarcare="12345"/>
XML;

        $result = XmlParser::parseUploadResponse($xml);

        expect($result['executionStatus'])->toBe(ExecutionStatus::Error->value);
        expect($result['indexIncarcare'])->toBe('12345');
    });

    it('treats empty ExecutionStatus as error', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<header ExecutionStatus="" index_incarcare="12345"/>
XML;

        $result = XmlParser::parseUploadResponse($xml);

        expect($result['executionStatus'])->toBe(ExecutionStatus::Error->value);
    });
});

describe('parseStatusResponse', function () {
    it('throws exception for empty XML', function () {
        XmlParser::parseStatusResponse('');
    })->throws(XmlParsingException::class);

    it('throws exception for unexpected structure', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<random>content</random>
XML;

        XmlParser::parseStatusResponse($xml);
    })->throws(XmlParsingException::class, 'Unknown or unexpected XML response structure');

    it('parses status response with header element', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<header stare="ok" id_descarcare="54321"/>
XML;

        $result = XmlParser::parseStatusResponse($xml);

        expect($result['stare'])->toBe('ok');
        expect($result['idDescarcare'])->toBe('54321');
        expect($result['errors'])->toBeNull();
    });

    it('parses status response with only stare attribute', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<header stare="in prelucrare"/>
XML;

        $result = XmlParser::parseStatusResponse($xml);

        expect($result['stare'])->toBe('in prelucrare');
        expect($result['idDescarcare'])->toBeNull();
    });

    it('parses status response with only id_descarcare attribute', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<header id_descarcare="99999"/>
XML;

        $result = XmlParser::parseStatusResponse($xml);

        expect($result['idDescarcare'])->toBe('99999');
    });

    it('parses Raspuns element structure', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Raspuns>
    <stare>ok</stare>
    <id_descarcare>11111</id_descarcare>
</Raspuns>
XML;

        $result = XmlParser::parseStatusResponse($xml);

        expect($result['stare'])->toBe('ok');
        expect($result['idDescarcare'])->toBe('11111');
    });

    it('parses error in Raspuns structure', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Raspuns>
    <Error>
        <mesaj>Document not found</mesaj>
    </Error>
</Raspuns>
XML;

        $result = XmlParser::parseStatusResponse($xml);

        expect($result['stare'])->toBe('nok');
        expect($result['errors'])->toContain('Document not found');
    });

    it('parses a single Errors element in the header (ANAF unknown id_incarcare)', function () {
        // Exactly what ANAF returns from /stareMesaj for an id it does not recognise:
        // a header carrying one <Errors errorMessage="..."/> and no stare attribute.
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1"><Errors errorMessage="Nu exista factura cu id_incarcare= 9999999999"/></header>
XML;

        $result = XmlParser::parseStatusResponse($xml);

        expect($result['stare'])->toBe('nok');
        expect($result['idDescarcare'])->toBeNull();
        // Must be a flat list of message strings - NOT the raw {"@":..., "_":...} node.
        expect($result['errors'])->toBe(['Nu exista factura cu id_incarcare= 9999999999']);
    });

    it('parses multiple Errors elements in the header as a flat message list', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<header><Errors errorMessage="First problem"/><Errors errorMessage="Second problem"/></header>
XML;

        $result = XmlParser::parseStatusResponse($xml);

        expect($result['stare'])->toBe('nok');
        expect($result['errors'])->toBe(['First problem', 'Second problem']);
    });
});

describe('getLastParseException', function () {
    it('returns null when no parse error has occurred', function () {
        // Parse valid XML first to reset state
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<header ExecutionStatus="0" index_incarcare="12345"/>
XML;

        XmlParser::parseUploadResponse($xml);

        expect(XmlParser::getLastParseException())->toBeNull();
    });

    it('captures parse exception for invalid XML', function () {
        $invalidXml = 'definitely not valid xml <<<<>>>';

        // This will fail to parse
        XmlParser::extractErrorMessage($invalidXml);

        $exception = XmlParser::getLastParseException();

        expect($exception)->toBeInstanceOf(Throwable::class);
    });

    it('passes previous exception to XmlParsingException', function () {
        $invalidXml = 'this is not XML at all <<<';

        try {
            XmlParser::parseUploadResponse($invalidXml);
        } catch (XmlParsingException $e) {
            // The previous exception should be set
            expect($e->getPrevious())->toBeInstanceOf(Throwable::class);
        }
    });

    it('resets last exception on successful parse', function () {
        // First cause a parse error
        XmlParser::extractErrorMessage('invalid xml <<<');
        expect(XmlParser::getLastParseException())->not->toBeNull();

        // Now parse valid XML
        $validXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<header ExecutionStatus="0" index_incarcare="12345"/>
XML;

        XmlParser::parseUploadResponse($validXml);

        // Exception should be cleared
        expect(XmlParser::getLastParseException())->toBeNull();
    });
});

describe('extractErrorMessage', function () {
    it('extracts errorMessage from attribute', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Error errorMessage="Something went wrong"/>
XML;

        expect(XmlParser::extractErrorMessage($xml))->toBe('Something went wrong');
    });

    it('extracts mesaj element', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Error>
    <mesaj>Romanian error message</mesaj>
</Error>
XML;

        expect(XmlParser::extractErrorMessage($xml))->toBe('Romanian error message');
    });

    it('returns null for XML without error', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Success>All good</Success>
XML;

        expect(XmlParser::extractErrorMessage($xml))->toBeNull();
    });

    it('returns null for empty XML', function () {
        expect(XmlParser::extractErrorMessage(''))->toBeNull();
    });

    it('returns null for invalid XML', function () {
        expect(XmlParser::extractErrorMessage('not xml at all'))->toBeNull();
    });

    it('returns null for empty extractErrorMessage when XML parses but has no error', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Data>
    <Value>Something</Value>
</Data>
XML;

        expect(XmlParser::extractErrorMessage($xml))->toBeNull();
    });

    it('extracts nested errorMessage', function () {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Details>
        <Error errorMessage="Nested error found"/>
    </Details>
</Response>
XML;

        expect(XmlParser::extractErrorMessage($xml))->toBe('Nested error found');
    });
});
