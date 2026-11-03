<?php

namespace App\Enums;

/**
 * What the Developer Lab stores, and what it will actually execute.
 *
 * This enum is the execution policy for the whole module. One method decides it —
 * {@see self::isBrowserRunnable()} — and that method is read by the editor to
 * decide whether to draw a Run button, by the action to decide whether to accept
 * a run, and by the gallery to decide whether to offer a preview. It is checked
 * server-side as well as in the interface because the interface is a suggestion
 * and the request is not.
 *
 * The distinction is physical rather than stylistic. HTML, CSS and JavaScript are
 * assembled into a document and executed by the visitor's own browser inside a
 * sandboxed frame, so a run costs the platform nothing and cannot reach anything
 * it should not. PHP would need a server-side runner: a process, a filesystem, a
 * time budget and an abuse story this application does not have. So PHP is stored,
 * listed, highlighted on the published page and copied, and it is never run. The
 * fourth case exists because refusing to store somebody's PHP would be a worse
 * product than being honest about what it can do here.
 */
enum LabLanguage: string
{
    case Html = 'html';
    case Css = 'css';
    case Javascript = 'javascript';
    case Php = 'php';

    /**
     * Get the display label for the language.
     */
    public function label(): string
    {
        return match ($this) {
            self::Html => 'HTML',
            self::Css => 'CSS',
            self::Javascript => 'JavaScript',
            self::Php => 'PHP',
        };
    }

    /**
     * Get the Flux badge colour token for the language.
     *
     * Four values against one accent scale: the colours are the familiar file
     * associations, chosen so a gallery row reads its language at a glance rather
     * than so the page looks varied.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Html => 'orange',
            self::Css => 'blue',
            self::Javascript => 'yellow',
            self::Php => 'violet',
        };
    }

    /**
     * Get the file extension a saved copy of this language would use.
     */
    public function fileExtension(): string
    {
        return match ($this) {
            self::Html => 'html',
            self::Css => 'css',
            self::Javascript => 'js',
            self::Php => 'php',
        };
    }

    /**
     * Determine whether the browser can execute this language on its own.
     *
     * The one method the module's safety story rests on. The three that answer
     * true are composed into a single document that runs in a frame with an
     * opaque origin; the one that answers false has no runner and is told so in
     * plain words wherever it appears.
     */
    public function isBrowserRunnable(): bool
    {
        return $this !== self::Php;
    }

    /**
     * Explain, in visitor-facing copy, why a language does or does not run here.
     *
     * The editor and the published page both say this rather than each inventing
     * a sentence, so the answer a reader gets twice is the same answer.
     */
    public function runExplanation(): string
    {
        return $this->isBrowserRunnable()
            ? __('Runs in your browser, inside a sandboxed frame.')
            : __('Saved, listed and copyable. PHP needs a server-side runner, which this lab does not have, so there is no Run button.');
    }

    /**
     * Get every language as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $language) => ['value' => $language->value, 'label' => $language->label()])
            ->values()
            ->toArray();
    }

    /**
     * Get the languages the sandbox will execute.
     *
     * @return array<int, self>
     */
    public static function runnableCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $language) => $language->isBrowserRunnable()
        ));
    }
}
