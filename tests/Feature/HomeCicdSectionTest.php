<?php

use App\Models\Post;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The CI/CD section is the landing page's technical argument, and it makes
 * claims a reader can check: eight stages, in an order, carrying specific
 * commands. These tests hold the markup to those claims — the graph itself is
 * aria-hidden decoration, so everything asserted here is text a screen reader
 * and a search engine actually receive.
 */

test('the pipeline section sits between the hero and the writing section', function () {
    Post::factory()->published()->create(['title' => 'Readable without an account']);

    $html = Livewire::test('pages::home')->html();

    $hero = Str::position($html, 'home-hero');
    $cicd = Str::position($html, 'id="cicd"');
    $writing = Str::position($html, 'writing-heading');

    expect($hero)->toBeGreaterThan(0)
        ->and($cicd)->toBeGreaterThan($hero)
        ->and($writing)->toBeGreaterThan($cicd);
});

test('the rail lists all eight stages in pipeline order', function () {
    $component = Livewire::test('pages::home');

    $component
        ->assertSeeInOrder([
            'Commit',
            'Prepare',
            'Static analysis',
            'Tests',
            'Build',
            'Release',
            'Deploy',
            'Verify',
        ])
        ->assertSee('aria-controls="cicd-stage-8"', false);

    expect(substr_count($component->html(), 'aria-controls="cicd-stage-'))->toBe(8);
});

test('every stage carries the Laravel command a reader can go and run', function () {
    Livewire::test('pages::home')
        ->assertSee('git push origin main')
        ->assertSee('composer install --prefer-dist')
        ->assertSee('pint --test')
        ->assertSee('phpstan analyse')
        ->assertSee('php artisan test --parallel')
        ->assertSee('npm run build')
        ->assertSee('releases/<sha>/')
        ->assertSee('migrate --force')
        ->assertSee('queue:restart')
        ->assertSee('GET /up');
});

test('the gates are named as gates and the parallel lanes are declared', function () {
    $html = Livewire::test('pages::home')->html();

    expect(substr_count($html, 'cicd-row__kind is-gate'))->toBe(3)
        ->and($html)->toContain('03–05 run concurrently');
});

test('the section is labelled by its heading for assistive technology', function () {
    Livewire::test('pages::home')
        ->assertSee('aria-labelledby="cicd-heading"', false)
        ->assertSee('id="cicd-heading"', false)
        ->assertSee('Every commit earns its deploy');
});
