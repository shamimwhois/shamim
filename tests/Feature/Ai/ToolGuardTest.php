<?php

use App\Ai\Tools\CommunityStats;
use App\Ai\Tools\RepositoryInsights;
use App\Ai\Tools\SearchCommits;
use App\Ai\Tools\SearchKnowledgeBase;
use App\Ai\Tools\SearchRepositoryHistory;
use Laravel\Ai\Tools\Request;

/**
 * The tools' own guards, tested without a model call.
 *
 * Every tool caps itself at two calls per reply and validates its arguments
 * before touching a query, so the whole guard layer is checkable as pure
 * logic: a fabricated {@see Request} in, a string verdict out. What these
 * tests deliberately do not do is reach the searches — the vector stores and
 * the GitHub queries behind them belong to integration, not to this file.
 */
it('exhausts a search budget after two calls', function (object $tool, array $arguments, string $message): void {
    $request = new Request($arguments);

    // The first two calls do the work (or refuse to); the third must be the
    // budget verdict, whatever the arguments were.
    foreach ([1, 2] as $attempt) {
        try {
            $tool->handle($request);
        } catch (Throwable) {
            // A search that cannot run still spent the attempt that made it.
        }
    }

    expect($tool->handle($request))->toBe($message);
})->with([
    'commits' => fn (): array => [new SearchCommits, ['query' => 'a'], 'Search budget exhausted. Answer using the evidence already retrieved.'],
    'knowledge base' => fn (): array => [new SearchKnowledgeBase, ['query' => ''], 'Search budget exhausted. Answer using the evidence already retrieved.'],
    'repository history' => fn (): array => [new SearchRepositoryHistory, ['query' => 'has "quotes"'], 'Repository history search budget exhausted. Answer using the evidence already retrieved.'],
    'community stats' => fn (): array => [new CommunityStats, ['metric' => 'nope', 'days' => 7], 'Statistics budget exhausted. Answer using the evidence already retrieved.'],
    'repository insights' => fn (): array => [new RepositoryInsights, ['metric' => 'nope'], 'Repository insights budget exhausted. Answer using the evidence already retrieved.'],
]);

it('refuses a repository metric the schema does not list', function (): void {
    $tool = new RepositoryInsights;

    expect($tool->handle(new Request(['metric' => 'nope'])))
        ->toBe('Unsupported repository metric. Choose one of the listed metrics.');
});

it('refuses a community metric or period the schema does not list', function (): void {
    $tool = new CommunityStats;

    expect($tool->handle(new Request(['metric' => 'nope', 'days' => 7])))
        ->toBe('Unsupported metric or period. Choose a listed metric and 7 or 30 days.')
        ->and($tool->handle(new Request(['metric' => 'new_members', 'days' => 90])))
        ->toBe('Unsupported metric or period. Choose a listed metric and 7 or 30 days.');
});

it('refuses a location term too short to mean a place', function (): void {
    $tool = new CommunityStats;

    expect($tool->handle(new Request(['metric' => 'location', 'days' => 7, 'term' => 'x'])))
        ->toBe('Provide one location name between 2 and 80 characters.');
});

it('refuses a language the profile matcher does not know', function (): void {
    $tool = new CommunityStats;

    expect($tool->handle(new Request(['metric' => 'bio_language', 'days' => 7, 'term' => 'klingon'])))
        ->toContain('Unsupported language. Choose one of:');
});

it('refuses combining a count with a profile-text filter', function (): void {
    $tool = new CommunityStats;

    expect($tool->handle(new Request(['metric' => 'new_members', 'days' => 7, 'term' => 'dhaka'])))
        ->toBe('Combined filters are not supported. Omit term for member and public-author counts.');
});

it('refuses a history query carrying search qualifiers', function (): void {
    $tool = new SearchRepositoryHistory;

    expect($tool->handle(new Request(['query' => 'bookmarks site:github.com'])))
        ->toBe('Use a short feature keyword or phrase (1-100 characters), without GitHub qualifiers, quotes or URLs.')
        ->and($tool->handle(new Request(['query' => str_repeat('word ', 30)])))
        ->toBe('Use a short feature keyword or phrase (1-100 characters), without GitHub qualifiers, quotes or URLs.');
});
