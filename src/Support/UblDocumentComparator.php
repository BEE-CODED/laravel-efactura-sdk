<?php

declare(strict_types=1);

namespace BeeCoded\EFacturaSdk\Support;

use BeeCoded\EFacturaSdk\Exceptions\ArchiveMismatchException;
use Brick\Math\BigDecimal;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Compares two UBL documents as element trees.
 *
 * The XML declaration, namespace prefixes and declarations, whitespace-only text,
 * anything outside the root element and trailing decimal zeroes in the UBL amount,
 * quantity and percent elements may differ; CDATA counts as text. Any other difference,
 * a DOCTYPE or entity declaration, unparseable XML, a comment or processing instruction
 * inside the root, or text mixed with child elements throws ArchiveMismatchException.
 */
final class UblDocumentComparator
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const NUMERIC_ELEMENTS = ['InvoicedQuantity', 'CreditedQuantity', 'PriceAmount', 'LineExtensionAmount', 'TaxExclusiveAmount', 'TaxInclusiveAmount', 'PayableAmount', 'PayableRoundingAmount', 'TaxableAmount', 'TaxAmount', 'Percent'];

    /** Bytes a document may hold (10 MiB): also ArchiveInspector's per-member cap, so a member it admits is compared. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Markup tokens ('<') a document may hold: bounds the parsed tree's memory far above any real invoice. */
    public const MAX_MARKUP = 1_000_000;

    /**
     * @throws ArchiveMismatchException when $candidate is not the same document as $expected
     */
    public function assertSameDocument(string $candidate, string $expected): void
    {
        if (! $this->same($this->root($candidate), $this->root($expected))) {
            throw new ArchiveMismatchException('The document differs from the expected one.');
        }
    }

    private function root(string $xml): DOMElement
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES || substr_count($xml, '<') > self::MAX_MARKUP || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new ArchiveMismatchException('The document is empty, declares a DOCTYPE or entity, or is too large.');
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $document->doctype !== null || ! $document->documentElement instanceof DOMElement) {
            throw new ArchiveMismatchException('The document is not well-formed XML.');
        }

        return $document->documentElement;
    }

    /**
     * Walks both trees in step and stops at the first difference, so memory stays at the
     * parsed documents (libxml caps nesting depth, which bounds the recursion).
     */
    private function same(DOMElement $a, DOMElement $b): bool
    {
        if ($a->namespaceURI !== $b->namespaceURI || $a->localName !== $b->localName || $this->attributes($a) !== $this->attributes($b)) {
            return false;
        }

        $textA = '';
        $textB = '';
        $x = $this->nextElement($a->firstChild, $textA);
        $y = $this->nextElement($b->firstChild, $textB);
        $hasChildren = $x !== null || $y !== null;
        while ($x !== null && $y !== null) {
            if (! $this->same($x, $y)) {
                return false;
            }
            $x = $this->nextElement($x->nextSibling, $textA);
            $y = $this->nextElement($y->nextSibling, $textB);
        }
        if ($x !== null || $y !== null) {
            return false;
        }

        if ($hasChildren) {
            if (trim($textA) !== '' || trim($textB) !== '') {
                throw new ArchiveMismatchException('The document mixes text with child elements.');
            }

            return true;
        }

        return $this->leafText($a, $textA) === $this->leafText($b, $textB);
    }

    /** The next element sibling from $node on, collecting text and refusing comments and processing instructions. */
    private function nextElement(?DOMNode $node, string &$text): ?DOMElement
    {
        for (; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof DOMElement) {
                return $node;
            }
            if (! in_array($node->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                throw new ArchiveMismatchException('The document holds a comment or processing instruction.');
            }
            $text .= $node->textContent;
        }

        return null;
    }

    /** @return array<string, string> */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            if ($attribute->namespaceURI !== 'http://www.w3.org/2000/xmlns/') {
                $attributes['{'.$attribute->namespaceURI.'}'.$attribute->localName] = $attribute->value;
            }
        }
        ksort($attributes);

        return $attributes;
    }

    private function leafText(DOMElement $element, string $text): string
    {
        if ($element->namespaceURI !== self::CBC || ! in_array($element->localName, self::NUMERIC_ELEMENTS, true)) {
            return $text;
        }
        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $text) !== 1) {
            throw new ArchiveMismatchException('The document holds a malformed number.');
        }

        return (string) BigDecimal::of($text)->strippedOfTrailingZeros();
    }
}
