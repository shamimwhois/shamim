<?php

use App\Enums\LabLanguage;

/*
|--------------------------------------------------------------------------
| Which languages the sandbox is allowed to execute
|--------------------------------------------------------------------------
|
| `isBrowserRunnable()` is the whole execution policy of the Developer Lab, and it
| is a property of the language rather than a conditional scattered across screens:
| HTML, CSS and JavaScript are things a browser already knows how to run, and PHP
| is not. A test that only checked the UI would let a hand-crafted request through,
| which is why the same flag is re-read inside `RecordLabExecution`.
|
| Kept in tests/Unit because nothing here touches the database or the HTTP layer.
|
*/

test('the three browser languages are runnable and php is not', function (LabLanguage $language, bool $runnable) {
    expect($language->isBrowserRunnable())->toBe($runnable);
})->with([
    [LabLanguage::Html, true],
    [LabLanguage::Css, true],
    [LabLanguage::Javascript, true],
    [LabLanguage::Php, false],
]);

test('the runnable set is exactly the languages the sandbox will execute', function () {
    expect(LabLanguage::runnableCases())
        ->toHaveCount(3)
        ->and(LabLanguage::runnableCases())->not->toContain(LabLanguage::Php);
});

test('php explains the absence of a run button instead of leaving a blank space', function () {
    $explanation = LabLanguage::Php->runExplanation();

    expect($explanation)->toContain('no Run button')
        ->and(LabLanguage::Html->runExplanation())->toContain('sandboxed frame');
});

test('every language has a label, a badge colour and a file extension', function (LabLanguage $language) {
    expect($language->label())->toBeString()->not->toBeEmpty()
        ->and($language->badgeColor())->toBeString()->not->toBeEmpty()
        ->and($language->fileExtension())->toBeString()->not->toBeEmpty();
})->with(LabLanguage::cases());

test('the picker options cover every case with a value and a label', function () {
    $options = LabLanguage::options();

    expect($options)->toHaveCount(count(LabLanguage::cases()));

    foreach ($options as $option) {
        expect($option)->toHaveKeys(['value', 'label'])
            ->and(LabLanguage::from($option['value']))->toBeInstanceOf(LabLanguage::class);
    }
});
