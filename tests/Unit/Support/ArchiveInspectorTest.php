<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Enums\ArchiveLimitReason;
use BeeCoded\EFacturaSdk\Exceptions\ArchiveException;
use BeeCoded\EFacturaSdk\Exceptions\ArchiveLimitException;
use BeeCoded\EFacturaSdk\Exceptions\ArchiveMismatchException;
use BeeCoded\EFacturaSdk\Exceptions\EFacturaException;
use BeeCoded\EFacturaSdk\Support\ArchiveInspector;
use BeeCoded\EFacturaSdk\Support\UblDocumentComparator;

const UBL_INVOICE = '<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>FCT-1</cbc:ID>
  <cbc:Note>Livrare &amp; montaj</cbc:Note>
  <cac:InvoiceLine>
    <cbc:ID>1</cbc:ID>
    <cbc:InvoicedQuantity unitCode="H87">2.50</cbc:InvoicedQuantity>
    <cbc:LineExtensionAmount currencyID="RON">250.00</cbc:LineExtensionAmount>
  </cac:InvoiceLine>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="RON">297.50</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>';

const XMLDSIG_SIGNATURE = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:SignedInfo/></ds:Signature>';

/**
 * The bytes of a ZIP holding $members (name => content); $encrypted members get AES-256.
 *
 * @param  array<string, string>  $members
 * @param  list<string>  $encrypted
 */
function zipOf(array $members, array $encrypted = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip-test-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($members as $name => $content) {
        $zip->addFromString($name, $content);
        if (in_array($name, $encrypted, true)) {
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256, 'secret');
        }
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

function archiveFailure(Closure $call): ArchiveException
{
    try {
        $call();
    } catch (ArchiveException $e) {
        return $e;
    }

    throw new RuntimeException('Expected an ArchiveException.');
}

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/archive-inspector-'.bin2hex(random_bytes(4));
    mkdir($this->tmp);
    $this->inspector = new ArchiveInspector($this->tmp);
});

afterEach(function () {
    array_map('unlink', glob($this->tmp.'/*') ?: []);
    rmdir($this->tmp);
});

describe('an archive that holds the upload', function () {
    it('passes with signatures beside it, in either mode, and leaves no temporary file', function () {
        $archive = zipOf(['5001234567.xml' => UBL_INVOICE, 'semnatura_5001234567.xml' => XMLDSIG_SIGNATURE]);

        $this->inspector->verify($archive, UBL_INVOICE);
        $this->inspector->verify($archive);

        expect(glob($this->tmp.'/*'))->toBe([]);
    });

    it('removes the temporary file when the check fails', function () {
        archiveFailure(fn () => $this->inspector->verify(zipOf(['a.txt' => 'not xml']), UBL_INVOICE));

        expect(glob($this->tmp.'/*'))->toBe([]);
    });

    it('passes with 32 members, the most it allows', function () {
        $signatures = array_fill_keys(array_map(fn (int $i): string => "semnatura_{$i}.xml", range(1, 31)), XMLDSIG_SIGNATURE);

        $this->inspector->verify(zipOf(['5001234567.xml' => UBL_INVOICE] + $signatures), UBL_INVOICE);
    })->throwsNoExceptions();

    it('reads no member in limits-only mode', function () {
        $this->inspector->verify(zipOf(['response.txt' => 'not a document']));
    })->throwsNoExceptions();
});

describe('limits', function () {
    it('throws the same retryable reason with or without the upload', function (Closure $archive, ArchiveLimitReason $reason) {
        foreach ([null, UBL_INVOICE] as $uploaded) {
            $e = archiveFailure(fn () => $this->inspector->verify($archive(), $uploaded));

            expect($e)->toBeInstanceOf(ArchiveLimitException::class)
                ->and($e)->toBeInstanceOf(EFacturaException::class)
                ->and($e->reason)->toBe($reason);
        }
    })->with([
        'archive over 20 MiB' => [fn () => str_repeat('A', 20 * 1024 * 1024 + 1), ArchiveLimitReason::ArchiveTooLarge],
        'members expanding over 20 MiB' => [fn () => zipOf(['a.xml' => str_repeat(' ', 7 << 20), 'b.xml' => str_repeat(' ', 7 << 20), 'c.xml' => str_repeat(' ', 7 << 20)]), ArchiveLimitReason::ArchiveTooLarge],
        'member over 10 MiB' => [fn () => zipOf(['a.xml' => str_repeat(' ', (10 << 20) + 1)]), ArchiveLimitReason::MemberTooLarge],
        // The unrelated first member would be a mismatch: limits are checked before any content.
        'encrypted member' => [fn () => zipOf(['note.txt' => 'unrelated', 'a.xml' => UBL_INVOICE], ['a.xml']), ArchiveLimitReason::Encrypted],
        '33 members' => [fn () => zipOf(array_fill_keys(array_map(fn (int $i): string => "m{$i}.xml", range(1, 33)), XMLDSIG_SIGNATURE)), ArchiveLimitReason::TooManyMembers],
        'no members' => [fn () => "PK\x05\x06".str_repeat("\0", 18), ArchiveLimitReason::Empty],
        'not a ZIP' => [fn () => '<html>Service unavailable</html>', ArchiveLimitReason::Unopenable],
        'no bytes' => [fn () => '', ArchiveLimitReason::Unopenable],
        'truncated ZIP' => [fn () => substr(zipOf(['a.xml' => UBL_INVOICE]), 0, -5), ArchiveLimitReason::Unopenable],
    ]);

    it('compares a member up to the member cap, which is also the comparator cap', function () {
        // Exactly 10 MiB: whitespace before the closing tag is ignored by the comparison.
        $large = str_replace('</Invoice>', str_repeat(' ', (10 << 20) - strlen(UBL_INVOICE)).'</Invoice>', UBL_INVOICE);

        $this->inspector->verify(zipOf(['a.xml' => $large, 'b.xml' => XMLDSIG_SIGNATURE]), $large);

        expect(fn () => (new UblDocumentComparator)->assertSameDocument($large.' ', $large.' '))
            ->toThrow(ArchiveMismatchException::class, 'too large');
    });

    it('compares a document holding exactly the markup cap', function () {
        $document = str_replace('</Invoice>', str_repeat('<b/>', UblDocumentComparator::MAX_MARKUP - substr_count(UBL_INVOICE, '<')).'</Invoice>', UBL_INVOICE);

        $this->inspector->verify(zipOf(['a.xml' => $document]), $document);
    })->throwsNoExceptions();

    it('refuses an upload it could never compare as a final mismatch, whatever the archive holds', function (Closure $uploaded) {
        $document = $uploaded();

        // The archive holds the upload itself: its limits would call it retryable forever.
        expect(archiveFailure(fn () => $this->inspector->verify(zipOf(['a.xml' => $document]), $document)))
            ->toBeInstanceOf(ArchiveMismatchException::class)
            ->and(archiveFailure(fn () => $this->inspector->verify('not a ZIP', $document)))
            ->toBeInstanceOf(ArchiveMismatchException::class);
    })->with([
        'one < over the markup cap' => [fn () => '<a>'.str_repeat('<b/>', UblDocumentComparator::MAX_MARKUP - 1).'</a>'],
        'one byte over the byte cap' => [fn () => str_replace('</Invoice>', str_repeat(' ', UblDocumentComparator::MAX_BYTES + 1 - strlen(UBL_INVOICE)).'</Invoice>', UBL_INVOICE)],
    ]);

    it('reports a temporary-file failure as Io, not as an unopenable archive', function () {
        $e = archiveFailure(fn () => (new ArchiveInspector($this->tmp.'/missing'))->verify(zipOf(['a.xml' => UBL_INVOICE]), UBL_INVOICE));

        expect($e)->toBeInstanceOf(ArchiveLimitException::class)
            ->and($e->reason)->toBe(ArchiveLimitReason::Io);
    });
});

describe('content', function () {
    it('throws a final mismatch', function (Closure $members) {
        expect(archiveFailure(fn () => $this->inspector->verify(zipOf($members()), UBL_INVOICE)))
            ->toBeInstanceOf(ArchiveMismatchException::class);
    })->with([
        'a DOCTYPE member' => [fn () => ['a.xml' => '<!DOCTYPE Signature [<!ENTITY x "y">]>'.XMLDSIG_SIGNATURE, 'b.xml' => UBL_INVOICE]],
        'an unparseable member' => [fn () => ['a.xml' => UBL_INVOICE, 'raspuns.txt' => 'not xml']],
        'a comment inside the document' => [fn () => ['a.xml' => str_replace('<cac:InvoiceLine>', '<!-- x --><cac:InvoiceLine>', UBL_INVOICE)]],
        'an unrelated document' => [fn () => ['a.xml' => UBL_INVOICE, 'b.xml' => '<Signature/>']],
        'a second copy' => [fn () => ['a.xml' => UBL_INVOICE, 'b.xml' => UBL_INVOICE]],
        'signatures only' => [fn () => ['a.xml' => XMLDSIG_SIGNATURE]],
        'a different document' => [fn () => ['a.xml' => str_replace('297.50', '297.51', UBL_INVOICE)]],
        'an empty member' => [fn () => ['a.xml' => UBL_INVOICE, 'b.xml' => '']],
        'a UTF-16 signature with a DOCTYPE' => [fn () => ['a.xml' => UBL_INVOICE, 'b.xml' => "\xFE\xFF".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE ds:Signature [<!ENTITY x "y">]>'.XMLDSIG_SIGNATURE, 'UTF-16BE', 'UTF-8')]],
    ]);

    it('refuses a member that parses into too many nodes as a retryable limit', function () {
        $e = archiveFailure(fn () => $this->inspector->verify(zipOf(['a.xml' => '<a>'.str_repeat('<b/>', UblDocumentComparator::MAX_MARKUP - 1).'</a>']), UBL_INVOICE));

        expect($e)->toBeInstanceOf(ArchiveLimitException::class)
            ->and($e->reason)->toBe(ArchiveLimitReason::MemberTooLarge);
    });
});

describe('UblDocumentComparator', function () {
    it('accepts the same document written differently', function (string $candidate) {
        (new UblDocumentComparator)->assertSameDocument($candidate, UBL_INVOICE);
    })->with([
        'other prefixes, no declaration, no indentation' => [str_replace(['cbc:', 'xmlns:cbc=', "\n", '  ', '<?xml version="1.0" encoding="UTF-8"?>'], ['b:', 'xmlns:b=', '', '', ''], UBL_INVOICE)],
        'trailing decimal zeroes' => [str_replace(['>2.50<', '>297.50<'], ['>2.5000<', '>297.5<'], UBL_INVOICE)],
        'a comment outside the root' => [UBL_INVOICE.'<!-- archived copy -->'],
        'CDATA as text' => [str_replace('Livrare &amp; montaj', '<![CDATA[Livrare & montaj]]>', UBL_INVOICE)],
    ])->throwsNoExceptions();

    it('rejects any other difference', function (string $candidate) {
        expect(fn () => (new UblDocumentComparator)->assertSameDocument($candidate, UBL_INVOICE))
            ->toThrow(ArchiveMismatchException::class);
    })->with([
        'a DOCTYPE' => ['<!DOCTYPE Invoice SYSTEM "https://example.invalid/evil.dtd">'.UBL_INVOICE],
        'unparseable XML' => ['<Invoice>'],
        'an empty document' => [''],
        'a missing element' => [str_replace('<cbc:Note>Livrare &amp; montaj</cbc:Note>', '', UBL_INVOICE)],
        'elements where text is expected' => [str_replace('<cbc:ID>FCT-1</cbc:ID>', '<cbc:ID><cbc:Note>FCT-1</cbc:Note></cbc:ID>', UBL_INVOICE)],
        'a processing instruction inside' => [str_replace('<cbc:ID>FCT-1', '<?pi x?><cbc:ID>FCT-1', UBL_INVOICE)],
        'text beside elements' => [str_replace('<cac:InvoiceLine>', 'loose<cac:InvoiceLine>', UBL_INVOICE)],
        'a changed amount' => [str_replace('297.50', '297.51', UBL_INVOICE)],
        'a changed attribute' => [str_replace('currencyID="RON">250', 'currencyID="EUR">250', UBL_INVOICE)],
        'an added element' => [str_replace('</cac:LegalMonetaryTotal>', '<cbc:PrepaidAmount currencyID="RON">0</cbc:PrepaidAmount></cac:LegalMonetaryTotal>', UBL_INVOICE)],
        'zeroes outside the numeric elements' => [str_replace('<cbc:ID>1</cbc:ID>', '<cbc:ID>1.0</cbc:ID>', UBL_INVOICE)],
        'a non-decimal number' => [str_replace('>297.50<', '>2.975e2<', UBL_INVOICE)],
        'an element moved to another namespace' => [str_replace('<cbc:ID>FCT-1</cbc:ID>', '<x:ID xmlns:x="urn:other">FCT-1</x:ID>', UBL_INVOICE)],
    ]);

    it('ignores trailing zeroes in every UBL amount, quantity and percent element', function (string $element) {
        $document = fn (string $value): string => '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            ."<cbc:{$element}>{$value}</cbc:{$element}></Invoice>";

        (new UblDocumentComparator)->assertSameDocument($document('19.5'), $document('19.50'));
    })->with(['InvoicedQuantity', 'CreditedQuantity', 'PriceAmount', 'LineExtensionAmount', 'TaxExclusiveAmount', 'TaxInclusiveAmount', 'PayableAmount', 'PayableRoundingAmount', 'TaxableAmount', 'TaxAmount', 'Percent'])
        ->throwsNoExceptions();

    it('compares the same element name outside the UBL namespace as text', function () {
        $document = fn (string $value): string => '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:x="urn:other">'
            ."<x:PayableAmount>{$value}</x:PayableAmount></Invoice>";

        expect(fn () => (new UblDocumentComparator)->assertSameDocument($document('297.5'), $document('297.50')))
            ->toThrow(ArchiveMismatchException::class);
    });

    it('refuses a document with too much markup on its own, before parsing it', function () {
        $document = '<a>'.str_repeat('<b/>', UblDocumentComparator::MAX_MARKUP - 1).'</a>';

        expect(fn () => (new UblDocumentComparator)->assertSameDocument($document, $document))
            ->toThrow(ArchiveMismatchException::class, 'too large');
    });

    it('compares a document holding exactly the markup cap on its own', function () {
        $document = '<a>'.str_repeat('<b/>', UblDocumentComparator::MAX_MARKUP - 2).'</a>';

        (new UblDocumentComparator)->assertSameDocument($document, $document);
    })->throwsNoExceptions();
});
