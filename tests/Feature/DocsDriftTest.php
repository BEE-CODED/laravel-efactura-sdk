<?php

declare(strict_types=1);

use BeeCoded\EFacturaSdk\Tests\Support\DocumentedSymbols;
use Illuminate\Support\Arr;

/**
 * Docs-drift guard: every symbol README.md and CHANGELOG.md name must really exist.
 *
 * A test that asserts documentation contains a string cannot fail when a symbol
 * is deleted: the docs are both subject and oracle. These tests fail for the
 * right reason: they ask PHP. A symbol that 4.0 removed may be named only in
 * CHANGELOG.md, through DocumentedSymbols' removed list.
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
 * trivially true when nothing is extracted at all. Rename README.md or
 * CHANGELOG.md, or land a regex typo, and the whole file would go green while
 * checking precisely nothing.
 *
 * The floors are deliberately far below current counts: this is a smoke alarm
 * for a dead extractor, not a coverage ratchet to bump on every doc edit.
 */
it('still extracts symbols from every documentation source', function () {
    $sources = DocumentedSymbols::sources();

    expect(array_keys($sources))->toBe(['README.md', 'CHANGELOG.md']);

    foreach ($sources as $label => $text) {
        expect(strlen($text))->toBeGreaterThan(500, "{$label} is empty or unreadable");
    }

    $total = fn (array $perSource): int => array_sum(array_map('count', $perSource));

    expect(count(DocumentedSymbols::realSymbols()))->toBeGreaterThan(50)
        ->and($total(DocumentedSymbols::fullyQualifiedClassNames()))->toBeGreaterThan(30)
        ->and($total(DocumentedSymbols::enumCaseReferences()))->toBeGreaterThan(10)
        ->and($total(DocumentedSymbols::configPaths()))->toBeGreaterThan(1)
        ->and($total(DocumentedSymbols::envVarReferences()))->toBeGreaterThan(10)
        ->and($total(DocumentedSymbols::staticMethodReferences()))->toBeGreaterThan(10)
        ->and($total(DocumentedSymbols::constantReferences()))->toBeGreaterThan(2);
});

it('names only classes, interfaces, traits and enums that exist', function () {
    $real = DocumentedSymbols::realSymbols();
    $missing = [];

    foreach (DocumentedSymbols::fullyQualifiedClassNames() as $label => $names) {
        foreach ($names as $name) {
            if (! DocumentedSymbols::resolves($name, $real)
                && ! DocumentedSymbols::isDocumentedAsRemoved($label, 'class', $name)) {
                $missing[] = "{$label}: {$name}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references symbols that do not exist: '.implode(', ', $missing));
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

it('names only class constants that exist', function () {
    $missing = [];

    foreach (DocumentedSymbols::constantReferences() as $label => $refs) {
        foreach ($refs as $ref) {
            $symbol = $ref['class'].'::'.$ref['constant'];

            if (! defined($symbol) && ! DocumentedSymbols::isDocumentedAsRemoved($label, 'constant', $symbol)) {
                $missing[] = "{$label}: {$ref['ref']}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references class constants that do not exist: '.implode(', ', $missing));
});

it('names only config keys that exist', function () {
    $config = DocumentedSymbols::realConfig();
    $missing = [];

    foreach (DocumentedSymbols::configPaths() as $label => $paths) {
        foreach ($paths as $path) {
            if (! Arr::has($config, $path) && ! DocumentedSymbols::isDocumentedAsRemoved($label, 'config', $path)) {
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
            if (! in_array($var, $real, true) && ! DocumentedSymbols::isDocumentedAsRemoved($label, 'env', $var)) {
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
            if (! DocumentedSymbols::methodResolves($ref['class'], $ref['method'])
                && ! DocumentedSymbols::isDocumentedAsRemoved($label, 'method', $ref['class'].'::'.$ref['method'])) {
                $missing[] = "{$label}: {$ref['ref']}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation calls methods that do not exist: '.implode(', ', $missing));
});

it('accepts a removed symbol only in CHANGELOG.md', function () {
    foreach (DocumentedSymbols::documentedAsRemoved() as $kind => $symbols) {
        foreach ($symbols as $symbol) {
            expect(DocumentedSymbols::isDocumentedAsRemoved('CHANGELOG.md', $kind, $symbol))->toBeTrue("{$kind} {$symbol}")
                ->and(DocumentedSymbols::isDocumentedAsRemoved('README.md', $kind, $symbol))->toBeFalse("{$kind} {$symbol}");
        }
    }
});

it('lists as removed only symbols that are really gone', function () {
    $removed = DocumentedSymbols::documentedAsRemoved();
    $config = DocumentedSymbols::realConfig();
    $env = DocumentedSymbols::realEnvVars();
    $exists = fn (string $fqcn): bool => class_exists($fqcn) || interface_exists($fqcn)
        || trait_exists($fqcn) || enum_exists($fqcn);

    expect(array_keys($removed))->toBe(['method', 'class', 'constant', 'config', 'env']);

    foreach ($removed['method'] as $symbol) {
        [$fqcn, $method] = explode('::', $symbol);
        expect($exists($fqcn) && method_exists($fqcn, $method))->toBeFalse("{$symbol} still exists");
    }

    foreach ($removed['class'] as $fqcn) {
        expect($exists($fqcn))->toBeFalse("{$fqcn} still exists");
    }

    foreach ($removed['constant'] as $symbol) {
        expect(defined($symbol))->toBeFalse("{$symbol} still exists");
    }

    foreach ($removed['config'] as $path) {
        expect(Arr::has($config, $path))->toBeFalse("efactura-sdk.{$path} still exists");
    }

    foreach ($removed['env'] as $var) {
        expect(in_array($var, $env, true))->toBeFalse("{$var} is still read");
    }
});
