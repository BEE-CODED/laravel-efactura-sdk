<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Tests\Support\DocumentedSymbols;
use Illuminate\Support\Arr;

/**
 * Docs-drift guard: every symbol the documentation names must really exist.
 *
 * This complements the MCP suite rather than duplicating it. Those tests assert
 * documentation *contains* a string, which cannot fail when a symbol is deleted
 * — the docs are both subject and oracle. These tests fail for the right reason:
 * they ask PHP.
 *
 * Honest scope note: this catches symbols that vanish or get renamed. It cannot
 * catch documentation that is merely *wrong* (correct symbol, misleading prose).
 *
 * @see DocumentedSymbols for what is deliberately out of scope and why.
 */

/**
 * The canary that makes every other test in this file mean something.
 *
 * Every assertion here is of the form "nothing extracted was broken", which is
 * trivially true when nothing is extracted at all. Rename README.md, move the
 * MCP content, or land a regex typo, and the whole file would go green while
 * checking precisely nothing.
 *
 * The floors are deliberately far below current counts — this is a smoke alarm
 * for a dead extractor, not a coverage ratchet to bump on every doc edit.
 */
it('still extracts symbols from every documentation source', function () {
    $sources = DocumentedSymbols::sources();

    expect($sources)->toHaveCount(6);

    foreach ($sources as $label => $text) {
        expect(strlen($text))->toBeGreaterThan(500, "{$label} is empty or unreadable");
    }

    $total = fn (array $perSource): int => array_sum(array_map('count', $perSource));

    expect(count(DocumentedSymbols::realSymbols()))->toBeGreaterThan(50)
        ->and($total(DocumentedSymbols::fullyQualifiedClassNames()))->toBeGreaterThan(30)
        ->and($total(DocumentedSymbols::enumCaseReferences()))->toBeGreaterThan(10)
        ->and($total(DocumentedSymbols::configPaths()))->toBeGreaterThan(15)
        ->and($total(DocumentedSymbols::envVarReferences()))->toBeGreaterThan(10)
        ->and($total(DocumentedSymbols::staticMethodReferences()))->toBeGreaterThan(10)
        ->and(count(DocumentedSymbols::dtoMembers()))->toBeGreaterThan(50);
});

it('names only classes, interfaces, traits and enums that exist', function () {
    $real = DocumentedSymbols::realSymbols();
    $missing = [];

    foreach (DocumentedSymbols::fullyQualifiedClassNames() as $label => $names) {
        foreach ($names as $name) {
            if (! DocumentedSymbols::resolves($name, $real)) {
                $missing[] = "{$label}: {$name}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references symbols that do not exist: '.implode(', ', $missing));
});

it('keeps symbols the docs say never existed actually absent', function () {
    foreach (DocumentedSymbols::documentedAsAbsent() as $fqcn) {
        expect(class_exists($fqcn))->toBeFalse(
            "{$fqcn} exists, but the migration guide tells users it never did."
        );
    }
});

it('names only enum cases that exist', function () {
    $enums = DocumentedSymbols::enumsByShortName();
    $missing = [];

    foreach (DocumentedSymbols::enumCaseReferences() as $label => $refs) {
        foreach ($refs as $ref) {
            [$short, $case] = explode('::', $ref);

            if (! defined($enums[$short].'::'.$case)) {
                $missing[] = "{$label}: {$ref}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references enum cases that do not exist: '.implode(', ', $missing));
});

it('names only config keys that exist', function () {
    $config = DocumentedSymbols::realConfig();
    $missing = [];

    foreach (DocumentedSymbols::configPaths() as $label => $paths) {
        foreach ($paths as $path) {
            if (! Arr::has($config, $path)) {
                $missing[] = "{$label}: efactura-sdk.{$path}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references config keys that do not exist: '.implode(', ', $missing));
});

it('names only env vars the config actually reads', function () {
    $real = DocumentedSymbols::realEnvVars();
    $missing = [];

    foreach (DocumentedSymbols::envVarReferences() as $label => $vars) {
        foreach ($vars as $var) {
            if (! in_array($var, $real, true)) {
                $missing[] = "{$label}: {$var}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references env vars no config reads: '.implode(', ', $missing));
});

it('names only Class::method() references that resolve', function () {
    $missing = [];

    foreach (DocumentedSymbols::staticMethodReferences() as $label => $refs) {
        foreach ($refs as $ref) {
            if (! DocumentedSymbols::methodResolves($ref['class'], $ref['method'])) {
                $missing[] = "{$label}: {$ref['ref']}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation calls methods that do not exist: '.implode(', ', $missing));
});

it('documents only DTO members that exist', function () {
    $missing = [];

    foreach (DocumentedSymbols::dtoMembers() as $member) {
        if (! DocumentedSymbols::memberExists($member['class'], $member['member'])) {
            $missing[] = $member['ref'];
        }
    }

    expect($missing)->toBe([], 'DTO tables document members that do not exist: '.implode(', ', $missing));
});
