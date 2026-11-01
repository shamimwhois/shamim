<?php

namespace App\Actions\DevLab;

use App\Models\LabWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create or update a workspace, which is what Save means in the sandbox.
 *
 * One entry point owns the write, so the editor's Save button and any later screen
 * that stores panes cannot disagree about the limits. A workspace is the row the
 * editor is sitting on, so saving twice on the same row updates it rather than
 * stacking copies: the author would otherwise find six versions of the same
 * experiment on the shelf and no way to tell which one was the last.
 *
 * The byte ceiling is enforced here rather than in the form. The panes are
 * `longText` columns, so the database would accept a megabyte of pasted markup
 * without complaint, and the thing that actually breaks at that size is the browser
 * tab holding the frame. A refusal the caller can read beats a page that stops
 * responding.
 */
class SaveLabWorkspace
{
    /**
     * Persist the panes, creating the workspace when none is given.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, array $data, ?LabWorkspace $workspace = null): LabWorkspace
    {
        return DB::transaction(function () use ($author, $data, $workspace): LabWorkspace {
            $workspace ??= new LabWorkspace;

            $name = trim((string) ($data['name'] ?? $workspace->name ?? ''));

            if ($name === '') {
                throw new InvalidArgumentException(__('A workspace needs a name before it can be saved.'));
            }

            $panes = [
                'html' => $this->pane($data, $workspace, 'html'),
                'css' => $this->pane($data, $workspace, 'css'),
                'js' => $this->pane($data, $workspace, 'js'),
            ];

            foreach ($panes as $field => $code) {
                $this->assertWithinLimit($field, $code);
            }

            $workspace->user_id ??= $author->id;
            $workspace->fill(['name' => mb_substr($name, 0, LabWorkspace::NAME_MAX)] + $panes);
            $workspace->save();

            return $workspace->refresh();
        });
    }

    /**
     * Read a pane from the request, keeping the stored value when the key is absent.
     *
     * An absent key is not an empty one. The editor sends all three panes; a caller
     * updating only the name must not find the code wiped by the omission.
     *
     * @param  array<string, mixed>  $data
     */
    protected function pane(array $data, LabWorkspace $workspace, string $field): ?string
    {
        if (! array_key_exists($field, $data)) {
            return $workspace->getAttribute($field);
        }

        $value = $data[$field];

        return $value === null ? null : (string) $value;
    }

    /**
     * Refuse a pane the sandbox cannot hold open.
     */
    protected function assertWithinLimit(string $field, ?string $code): void
    {
        $limit = (int) config('dev_lab.max_code_bytes');

        if (strlen((string) $code) > $limit) {
            throw new InvalidArgumentException(
                __('The :pane is :size bytes, and the sandbox stores at most :limit. Split the experiment into two workspaces, or keep the large file in your editor where it belongs.', [
                    'pane' => $field,
                    'size' => number_format((float) strlen((string) $code)),
                    'limit' => number_format($limit),
                ])
            );
        }
    }
}
