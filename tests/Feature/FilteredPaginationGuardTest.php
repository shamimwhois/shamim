<?php

/**
 * Every listing page carries its filters into page two.
 *
 * A page link on this site is a real `<a href>` with `wire:navigate` (see
 * `resources/views/vendor/pagination/devfolio.blade.php`), so turning the page is a
 * navigation that re-mounts the component from the URL rather than a Livewire
 * round-trip that could read the filter off the component's own state. Whatever the
 * address bar says is what the next screen runs. A paginator built without the query
 * string emits links carrying only the path and the page number, so `?topic=`, `?q=`,
 * `?author=`, `?sort=`, `?lang=` and `?tab=` all quietly vanish: the second screen of
 * a narrowed list becomes the second screen of everything, which is a different list
 * wearing the same heading.
 *
 * `FilteredPaginationTest` proves the contract by asking for a page and reading the
 * href back. This file proves it for every screen at once, because fixtures there
 * would need a full set of rows for each of the twenty-six listings — including the
 * admin consoles — to be worth anyone's time, and a twenty-seventh added tomorrow
 * would not be covered by them either. The rule is syntactic: a `paginate()` chain
 * that does not reach `withQueryString()` is wrong on any page, so it is read out of
 * the source. Scanning source rather than behaviour is deliberate here and nowhere
 * else; it is the one thing a feature test cannot see without a database of filler,
 * and it names the file to fix.
 */
function filteredPagination_listings(): array
{
    $directory = new RecursiveDirectoryIterator(__DIR__.'/../../resources/views/pages');
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(__DIR__.'/../../')));
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match_all('/->paginate\(/', $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            continue;
        }

        foreach ($matches[0] as $match) {
            $files[] = [
                'file' => $relative,
                'line' => substr_count(substr($source, 0, (int) $match[1]), "\n") + 1,
                'chain' => strtok(substr($source, (int) $match[1]) ?: '', ';') ?: '',
            ];
        }
    }

    return $files;
}

test('every listing on the site appends its query string to the page links', function () {
    $offenders = [];

    foreach (filteredPagination_listings() as $listing) {
        if (! str_contains($listing['chain'], '->withQueryString()')) {
            $offenders[] = $listing['file'].':'.$listing['line'];
        }
    }

    expect($offenders)
        // Each entry is a file and a line, so the failure names the listing to fix.
        ->toBe([]);
});

test('the guard looks at the listings it claims to cover', function () {
    // A guard that silently matches nothing is worse than no guard. The floor is the
    // count of screens the fix touched, so a new listing that changes the shape moves
    // this test rather than passing on an empty scan.
    $listings = filteredPagination_listings();
    $files = array_unique(array_column($listings, 'file'));

    expect($files)->toHaveCount(24)->and($listings)->toHaveCount(30);
});
