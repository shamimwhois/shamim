<?php

namespace App\Actions\DevLab;

use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Models\LabWorkspace;
use App\Models\User;
use InvalidArgumentException;

/**
 * Open a published snippet in your own sandbox, as a workspace you can edit.
 *
 * A snippet is one code block in one language; a workspace is the three panes the
 * editor composes into a frame. This action is the seam between those two shapes:
 * the snippet's code goes into the pane its language names, and the other two are
 * emptied, so what the frame runs is the snippet and not a remix of it with
 * whatever was in the buffer before.
 *
 * ## Why it writes through {@see SaveLabWorkspace}
 *
 * That class is the module's only writer of a workspace: the name rule, the byte
 * ceiling and the transaction live there, and a second writer would be a second
 * opinion about them. Cloning is not exempt from the limits that apply to typing the
 * same bytes in by hand.
 *
 * ## Why it updates rather than accumulates
 *
 * The buffer is found by the name this action derives from the snippet's title, so
 * clicking twice on the same snippet re-opens the one workspace instead of stacking
 * identical copies on the shelf — the complaint {@see SaveLabWorkspace} already
 * answers for Save. A workspace the author has since renamed is a different name, so
 * the next click opens a fresh buffer rather than overwriting work somebody filed
 * under another label.
 *
 * ## What cannot be cloned
 *
 * PHP. There is no PHP pane, so there is no workspace a PHP snippet could become,
 * and the refusal is here rather than only in the template that hides the button —
 * the interface is a suggestion and the request is not.
 */
class CloneSnippetToWorkspace
{
    public function __construct(
        protected SaveLabWorkspace $save,
    ) {}

    /**
     * Copy the snippet into the caller's workspace and return it.
     */
    public function handle(User $author, LabSnippet $snippet): LabWorkspace
    {
        $name = $this->workspaceName($snippet);

        $existing = LabWorkspace::query()
            ->authoredBy($author)
            ->where('name', $name)
            ->first();

        return $this->save->handle($author, ['name' => $name] + $this->panes($snippet), $existing);
    }

    /**
     * The shelf label a clone of this snippet gets.
     *
     * Derived from the title rather than chosen at random, because the name is both
     * the label the author reads and the key a second click looks the buffer up by.
     */
    public function workspaceName(LabSnippet $snippet): string
    {
        return mb_substr(__('From :title', ['title' => $snippet->title]), 0, LabWorkspace::NAME_MAX);
    }

    /**
     * Place the snippet's code in the pane its language names, emptying the others.
     *
     * @return array{html: string, css: string, js: string}
     */
    protected function panes(LabSnippet $snippet): array
    {
        $code = (string) $snippet->code;

        return match ($snippet->language) {
            LabLanguage::Html => ['html' => $code, 'css' => '', 'js' => ''],
            LabLanguage::Css => ['html' => '', 'css' => $code, 'js' => ''],
            LabLanguage::Javascript => ['html' => '', 'css' => '', 'js' => $code],
            LabLanguage::Php => throw new InvalidArgumentException(
                __('PHP has no pane in the sandbox, so there is nothing to open. Copy it and run it where a PHP interpreter lives.')
            ),
        };
    }
}
