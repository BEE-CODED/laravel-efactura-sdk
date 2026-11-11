<?php

declare(strict_types=1);

it('allows no brick/math version without strippedOfTrailingZeros()', function () {
    $constraint = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true)['require']['brick/math'];

    // brick/math added strippedOfTrailingZeros() in 0.14.4 (and removed stripTrailingZeros() in 0.15).
    // The suite runs on one installed version; this guards the floor the constraint admits.
    foreach (explode('||', $constraint) as $alternative) {
        expect(trim($alternative))->toMatch('/^\^\d+(\.\d+)+$/')
            ->and(version_compare(ltrim(trim($alternative), '^'), '0.14.4', '>='))->toBeTrue("{$alternative} admits a version before 0.14.4");
    }
});
