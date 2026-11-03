<?php

namespace App\Actions\DevLab;

use App\Models\LabWorkspace;

/**
 * Delete a workspace.
 *
 * The row has no trash column, so this is the only kind of removal the module
 * offers, and the screen makes it a two-step confirm rather than a modal. Nothing
 * cascades: a workspace is not referenced by anything, which is the trade it makes
 * for being the cheapest thing in the lab to create.
 *
 * Deleting a workspace does not delete the runs it produced. Those rows carry their
 * own label precisely so a history can outlive the buffer it came from.
 */
class DeleteLabWorkspace
{
    public function handle(LabWorkspace $workspace): void
    {
        $workspace->delete();
    }
}
