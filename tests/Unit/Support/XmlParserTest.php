<?php

declare(strict_types=1);

use BeeCoded\EFactura\Enums\ExecutionStatus;
use BeeCoded\EFactura\Exceptions\XmlParsingException;
use BeeCoded\EFactura\Support\XmlParser;

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
        expect($result['errors'])->toBeArray();
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
