<?php

use App\Support\FeedCorpus;
use App\Support\FeedPersonas;

/*
|--------------------------------------------------------------------------
| The feed's author registry
|--------------------------------------------------------------------------
|
| This class exists because the same ten identities were written out in four
| places and had already disagreed with each other — the corpus said `Kenji
| Sato` while the seeder's persona list said `Kenji Watanabe`, and `@danielo`
| was seeded as `@daniel`. A card that renders one spelling and a directory
| that seeds another is the bug, so the pin is not "the registry has ten
| entries" but "nothing the feed prints says something else".
|
| Every assertion here is a comparison between two places the same fact is
| written. Neither is checked against a hard-coded copy of itself.
|
*/

it('says the same thing about an author in the corpus as the registry does', function (): void {
    foreach (FeedCorpus::posts() as $post) {
        $persona = FeedPersonas::findByHandle($post['handle']);

        expect($persona)->not->toBeNull()
            ->and($persona['name'])->toBe($post['author'])
            ->and($persona['avatar'])->toBe($post['avatar'])
            ->and($persona['role'])->toBe($post['role'])
            ->and(FeedPersonas::displayedHandle($persona['handle']))->toBe($post['handle']);
    }
});

it('derives every recorded avatar from the name it is printed beside', function (): void {
    foreach (FeedPersonas::all() as $persona) {
        $words = explode(' ', $persona['name']);

        expect($persona['avatar'])->toBe(
            mb_strtoupper(mb_substr($words[0], 0, 1)).mb_strtoupper(mb_substr($words[1], 0, 1))
        );
    }
});

it('keys the registry by the bare handle the column stores', function (): void {
    foreach (FeedPersonas::all() as $key => $persona) {
        expect($key)->toBe($persona['handle'])
            ->and($persona['handle'])->not->toStartWith('@');
    }
});

it('resolves the spelling a card writes as well as the spelling a column holds', function (): void {
    expect(FeedPersonas::findByHandle('@kenji'))->toBe(FeedPersonas::findByHandle('kenji'))
        ->and(FeedPersonas::findByHandle('  @aisha  '))->toBe(FeedPersonas::findByHandle('aisha'))
        ->and(FeedPersonas::findByHandle('@danielo')['name'])->toBe('Daniel Okafor');
});

it('finds nobody for a handle that is not a persona', function (): void {
    expect(FeedPersonas::findByHandle('nobody'))->toBeNull()
        ->and(FeedPersonas::findByHandle(''))->toBeNull()
        ->and(FeedPersonas::findByHandle('   '))->toBeNull()
        ->and(FeedPersonas::findByHandle('@'))->toBeNull()
        ->and(FeedPersonas::findByHandle(null))->toBeNull();
});

it('finds a persona by the address its account is seeded with, whichever way it is typed', function (): void {
    expect(FeedPersonas::findByEmail('priya@example.com'))->toBe(FeedPersonas::findByHandle('priya'))
        ->and(FeedPersonas::findByEmail('  PRIYA@EXAMPLE.COM '))->toBe(FeedPersonas::findByHandle('priya'));
});

it('has one account per persona, so no two authors share a login', function (): void {
    $emails = array_column(array_values(FeedPersonas::all()), 'email');
    $handles = array_column(array_values(FeedPersonas::all()), 'handle');

    expect(count($emails))->toBe(10)
        ->and(count(array_unique($emails)))->toBe(count($emails))
        ->and(count(array_unique($handles)))->toBe(count($handles));
});

it('prefixes a handle exactly once, whichever spelling it is handed', function (): void {
    expect(FeedPersonas::displayedHandle('shamim'))->toBe('@shamim')
        ->and(FeedPersonas::displayedHandle('@shamim'))->toBe('@shamim');
});
