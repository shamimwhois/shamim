<?php

use App\Data\TextCorrection;
use App\Enums\CorrectionType;

/*
|--------------------------------------------------------------------------
| Placement
|--------------------------------------------------------------------------
|
| A model will confidently report a correction with offsets that are simply
| wrong. Splicing at those offsets corrupts the document, so the DTO refuses
| to trust them: it verifies the span against the source and recomputes when
| they disagree. These tests pin that contract down.
|
*/

test('it trusts offsets that match the reported original text', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'teh',
        'replacement' => 'the',
        'type' => 'spelling',
        'explanation' => 'Transposed letters.',
        'start' => 0,
        'end' => 3,
    ], 'teh cat sat.');

    expect($correction)->not->toBeNull()
        ->and($correction->start)->toBe(0)
        ->and($correction->end)->toBe(3);
});

test('it repairs offsets that do not match the original text', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'cat',
        'replacement' => 'dog',
        'type' => 'style',
        'explanation' => 'Different animal.',
        'start' => 999,
        'end' => 1002,
    ], 'The cat sat.');

    expect($correction)->not->toBeNull()
        ->and($correction->start)->toBe(4)
        ->and($correction->end)->toBe(7);
});

test('it repairs offsets that land inside another word', function () {
    // Offsets 0..3 of "The cat sat." is "The", but the model claims the
    // original was "cat". Trusting the span would replace the wrong word.
    $correction = TextCorrection::fromArray([
        'original' => 'cat',
        'replacement' => 'dog',
        'type' => 'spelling',
        'explanation' => 'Wrong word.',
        'start' => 0,
        'end' => 3,
    ], 'The cat sat.');

    expect($correction->start)->toBe(4)
        ->and($correction->end)->toBe(7);
});

test('it repairs offsets that run past the end of the source', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'sat',
        'replacement' => 'stood',
        'type' => 'style',
        'explanation' => 'More precise.',
        'start' => 100,
        'end' => 200,
    ], 'The cat sat.');

    expect($correction->start)->toBe(8)
        ->and($correction->end)->toBe(11);
});

test('it repairs negative offsets', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'cat',
        'replacement' => 'dog',
        'type' => 'style',
        'explanation' => 'Different animal.',
        'start' => -5,
        'end' => -2,
    ], 'The cat sat.');

    expect($correction->start)->toBe(4);
});

test('it finds the first occurrence when a phrase appears more than once', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'cat',
        'replacement' => 'dog',
        'type' => 'style',
        'explanation' => 'Different animal.',
        'start' => 999,
        'end' => 1002,
    ], 'A cat, another cat, and a third cat.');

    // strpos gives the first match. Picking a later one would be arbitrary,
    // and the editor re-validates the span before applying anyway.
    expect($correction->start)->toBe(2);
});

test('it handles multi-byte text correctly', function () {
    // "café" contains a two-byte character, so byte offsets and character
    // offsets diverge. The DTO works in bytes, matching substr(), which is
    // what the editor splices with — the two must agree.
    $text = 'Le café est chaud.';

    $correction = TextCorrection::fromArray([
        'original' => 'chaud',
        'replacement' => 'brûlant',
        'type' => 'style',
        'explanation' => 'Stronger.',
        'start' => 999,
        'end' => 1004,
    ], $text);

    expect($correction)->not->toBeNull()
        ->and($correction->start)->toBe(strpos($text, 'chaud'))
        ->and(substr($text, $correction->start, $correction->end - $correction->start))->toBe('chaud');
});

test('it places an accented original correctly', function () {
    $text = 'Le café est chaud.';

    $correction = TextCorrection::fromArray([
        'original' => 'café',
        'replacement' => 'thé',
        'type' => 'style',
        'explanation' => 'Different drink.',
        'start' => 999,
        'end' => 1003,
    ], $text);

    expect($correction)->not->toBeNull()
        ->and(substr($text, $correction->start, $correction->end - $correction->start))->toBe('café');
});

/*
|--------------------------------------------------------------------------
| Rejection
|--------------------------------------------------------------------------
*/

test('it drops a correction whose text is absent from the source', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'elephant',
        'replacement' => 'rhino',
        'type' => 'spelling',
        'explanation' => 'Hallucinated.',
        'start' => 0,
        'end' => 8,
    ], 'The cat sat.');

    expect($correction)->toBeNull();
});

test('it drops a no-op correction', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'cat',
        'replacement' => 'cat',
        'type' => 'spelling',
        'explanation' => 'Pointless.',
        'start' => 4,
        'end' => 7,
    ], 'The cat sat.');

    expect($correction)->toBeNull();
});

test('it drops a correction with an unknown type', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'cat',
        'replacement' => 'dog',
        'type' => 'vibes',
        'explanation' => 'Not a real type.',
        'start' => 4,
        'end' => 7,
    ], 'The cat sat.');

    expect($correction)->toBeNull();
});

test('it drops a correction missing required keys', function () {
    expect(TextCorrection::fromArray(['replacement' => 'dog'], 'The cat sat.'))->toBeNull()
        ->and(TextCorrection::fromArray([], 'The cat sat.'))->toBeNull();
});

test('it drops a correction with an empty original', function () {
    $correction = TextCorrection::fromArray([
        'original' => '',
        'replacement' => 'dog',
        'type' => 'spelling',
        'explanation' => 'Nothing to replace.',
        'start' => 0,
        'end' => 0,
    ], 'The cat sat.');

    expect($correction)->toBeNull();
});

test('it tolerates non-integer offsets by recomputing', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'cat',
        'replacement' => 'dog',
        'type' => 'style',
        'explanation' => 'Different animal.',
        'start' => 'nonsense',
        'end' => null,
    ], 'The cat sat.');

    expect($correction)->not->toBeNull()
        ->and($correction->start)->toBe(4)
        ->and($correction->end)->toBe(7);
});

/*
|--------------------------------------------------------------------------
| Derived state
|--------------------------------------------------------------------------
*/

test('it reports whether a correction is mechanical', function () {
    $spelling = TextCorrection::fromArray([
        'original' => 'teh', 'replacement' => 'the', 'type' => 'spelling',
        'explanation' => 'Typo.', 'start' => 0, 'end' => 3,
    ], 'teh cat');

    $style = TextCorrection::fromArray([
        'original' => 'cat', 'replacement' => 'feline', 'type' => 'style',
        'explanation' => 'Tone.', 'start' => 4, 'end' => 7,
    ], 'the cat');

    expect($spelling->isMechanical())->toBeTrue()
        ->and($style->isMechanical())->toBeFalse();
});

test('it produces a stable id for the same span and replacement', function () {
    $data = [
        'original' => 'teh', 'replacement' => 'the', 'type' => 'spelling',
        'explanation' => 'Typo.', 'start' => 0, 'end' => 3,
    ];

    $a = TextCorrection::fromArray($data, 'teh cat');
    $b = TextCorrection::fromArray($data, 'teh cat');

    // The id is what the editor keys its dismissal set on, so it has to be
    // deterministic across re-renders and page loads.
    expect($a->id())->toBe($b->id())
        ->and($a->id())->toHaveLength(16);
});

test('it exposes the correction type as an enum', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'teh', 'replacement' => 'the', 'type' => 'grammar',
        'explanation' => 'Agreement.', 'start' => 0, 'end' => 3,
    ], 'teh cat');

    expect($correction->type)->toBe(CorrectionType::Grammar);
});

test('it serialises to the shape the editor expects', function () {
    $correction = TextCorrection::fromArray([
        'original' => 'teh',
        'replacement' => 'the',
        'type' => 'spelling',
        'explanation' => 'Typo.',
        'start' => 0,
        'end' => 3,
    ], 'teh cat');

    $array = $correction->toArray();

    expect($array)->toHaveKeys(['id', 'original', 'replacement', 'type', 'explanation', 'start', 'end'])
        ->and($array['type'])->toBe('spelling')
        ->and($array['id'])->toBe($correction->id());
});
