<?php

namespace App\Actions\DevLab;

use App\Models\LabSnippet;

/**
 * Delete a snippet.
 *
 * Final, because the module has no trash column and a code archive nobody prunes is
 * a liability rather than a feature. Its URL stops resolving the moment it runs,
 * which is the honest outcome: a published page that links to nothing is worse than
 * a link that 404s.
 *
 * The runs this snippet produced are kept. They are facts about a session, they
 * carry their own label, and deleting one should not silently rewrite the history of
 * what the account did.
 */
class DeleteLabSnippet
{
    public function handle(LabSnippet $snippet): void
    {
        $snippet->delete();
    }
}
