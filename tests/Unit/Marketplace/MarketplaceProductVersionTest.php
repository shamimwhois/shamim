<?php

use App\Models\MarketplaceProductVersion;

/*
|--------------------------------------------------------------------------
| Release notes
|--------------------------------------------------------------------------
|
| Release notes are authored as a loose bullet list in a textarea, so the
| parser has to survive dashes, asterisks, blank lines and stray indentation
| without emitting empty bullets.
|
*/

test('release notes are split into clean bullet lines', function () {
    $version = new MarketplaceProductVersion([
        'version' => '1.2.0',
        'release_notes' => "- Adds CSV export.\n* Fixes a memory leak.\n\n  Plain line  ",
    ]);

    expect($version->notes())->toBe([
        'Adds CSV export.',
        'Fixes a memory leak.',
        'Plain line',
    ]);
});

test('a release without notes yields an empty list', function () {
    expect((new MarketplaceProductVersion(['release_notes' => null]))->notes())->toBe([])
        ->and((new MarketplaceProductVersion(['release_notes' => "  \n\n "]))->notes())->toBe([]);
});

test('a release casts its flags and date', function () {
    $version = new MarketplaceProductVersion([
        'version' => '2.0.0',
        'is_current' => 1,
        'released_at' => '2026-09-01 10:00:00',
    ]);

    expect($version->is_current)->toBeTrue()
        ->and($version->released_at->toDateString())->toBe('2026-09-01');
});
