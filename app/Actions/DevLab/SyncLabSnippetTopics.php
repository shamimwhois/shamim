<?php

namespace App\Actions\DevLab;

use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Models\Topic;

/**
 * File a snippet under the public topics its author chose, in the chosen order.
 *
 * The snippet already has one subject nobody had to decide, its
 * {@see LabLanguage}. Topics are the other axis, what the code is for
 * rather than what it is written in, and they are what the gallery filters on once
 * the language chips have narrowed the shelf.
 *
 * The same rule the ideas side uses lives in {@see Topic::syncRows()}, including the
 * decision to drop an id the taxonomy does not accept instead of failing the save.
 */
class SyncLabSnippetTopics
{
    /**
     * Replace the snippet's topic set.
     *
     * @param  array<int, int|string>  $topicIds  Topic ids, primary first.
     */
    public function handle(LabSnippet $snippet, array $topicIds): void
    {
        $snippet->topics()->sync(Topic::syncRows($topicIds));
        $snippet->unsetRelation('topics');
    }
}
