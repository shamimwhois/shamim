<?php

namespace App\Actions\Ideas;

use App\Concerns\ResolvesUniqueSlug;
use App\Enums\ContentVisibility;
use App\Enums\IdeaPotential;
use App\Enums\IdeaStatus;
use App\Enums\WorkPriority;
use App\Models\Idea;
use App\Models\IdeaCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create or update an idea.
 *
 * One entry point owns the write, so the workspace's quick-capture form, the edit
 * screen and the admin console cannot disagree about what a saved idea means.
 *
 * Three things are deliberately not taken from the request. The `slug` is resolved,
 * because it carries a UNIQUE index. `visibility` and `published_at` belong to
 * {@see SyncIdeaVisibility}, the only writer of that pair, so an idea cannot reach a
 * reader through some form that forgot the publish rule. The `user_id` is set once,
 * on create, so no edit can hand an idea to a different account.
 *
 * Status is written here, guarded by {@see IdeaStatus::canTransitionTo()}. The gate
 * belongs on the write path rather than in the select box, because a console can be
 * driven by a request the browser never built.
 */
class SaveIdea
{
    use ResolvesUniqueSlug;

    public function __construct(
        protected SyncIdeaVisibility $syncVisibility,
        protected SyncIdeaTopics $syncTopics,
    ) {}

    /**
     * Persist the idea, creating it when none is given.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, array $data, ?Idea $idea = null): Idea
    {
        return DB::transaction(function () use ($author, $data, $idea): Idea {
            $idea ??= new Idea;

            $isNew = ! $idea->exists;
            $title = trim((string) ($data['title'] ?? $idea->title ?? ''));

            if ($title === '') {
                throw new InvalidArgumentException(__('An idea needs a title before it can be saved.'));
            }

            $currentStatus = $isNew ? IdeaStatus::Inbox : $idea->status;
            $requestedStatus = $this->resolveStatus($data['status'] ?? null) ?? $currentStatus;

            if (! $currentStatus->canTransitionTo($requestedStatus)) {
                throw new InvalidArgumentException(
                    __('An idea cannot move from :current to :target.', [
                        'current' => $currentStatus->label(),
                        'target' => $requestedStatus->label(),
                    ])
                );
            }

            $idea->user_id ??= $author->id;
            $idea->fill([
                'title' => $title,
                'summary' => array_key_exists('summary', $data) ? $data['summary'] : $idea->summary,
                'content' => array_key_exists('content', $data) ? $data['content'] : $idea->content,
                'priority' => $this->resolvePriority($data['priority'] ?? $idea->priority),
                'potential' => $this->resolvePotential($data['potential'] ?? $idea->potential),
            ]);

            $idea->idea_category_id = $this->resolveCategoryId($author, $data, $idea);

            $idea->slug = $this->resolveUniqueSlug($idea, $data, $title);

            $idea->forceFill(['status' => $requestedStatus]);

            $idea->save();

            if (array_key_exists('visibility', $data)) {
                $this->syncVisibility->handle($idea, $this->resolveVisibility($data['visibility']));
            }

            // Absent means "this caller does not file by topic", which is not the
            // same instruction as "clear the topics". A console row that edits a
            // status must not silently strip the subjects an author chose.
            if (array_key_exists('topics', $data) && is_array($data['topics'])) {
                $this->syncTopics->handle($idea, $data['topics']);
            }

            return $idea->refresh();
        });
    }

    /**
     * Resolve the submitted category, refusing one that belongs to someone else.
     *
     * Keeping the previous category silently would be worse than refusing: the
     * caller would believe the move had succeeded.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveCategoryId(User $author, array $data, Idea $idea): ?int
    {
        if (! array_key_exists('idea_category_id', $data)) {
            return $idea->idea_category_id;
        }

        $id = $data['idea_category_id'];

        if ($id === null || $id === '' || (string) $id === '0') {
            return null;
        }

        $category = IdeaCategory::query()
            ->whereKey($id)
            ->where('user_id', $author->id)
            ->first();

        if ($category === null) {
            throw new InvalidArgumentException(__('That category does not belong to this account.'));
        }

        return $category->id;
    }

    /**
     * Coerce a submitted status.
     */
    protected function resolveStatus(mixed $value): ?IdeaStatus
    {
        if ($value instanceof IdeaStatus) {
            return $value;
        }

        return $value === null || $value === '' ? null : IdeaStatus::tryFrom((string) $value);
    }

    /**
     * Coerce a submitted priority.
     */
    protected function resolvePriority(mixed $value): ?WorkPriority
    {
        if ($value instanceof WorkPriority) {
            return $value;
        }

        return $value === null || $value === '' ? null : WorkPriority::tryFrom((string) $value);
    }

    /**
     * Coerce a submitted potential.
     */
    protected function resolvePotential(mixed $value): ?IdeaPotential
    {
        if ($value instanceof IdeaPotential) {
            return $value;
        }

        return $value === null || $value === '' ? null : IdeaPotential::tryFrom((string) $value);
    }

    /**
     * Coerce a submitted visibility, treating an unusable value as private.
     */
    protected function resolveVisibility(mixed $value): ContentVisibility
    {
        if ($value instanceof ContentVisibility) {
            return $value;
        }

        return ContentVisibility::tryFrom((string) ($value ?? '')) ?? ContentVisibility::Private;
    }
}
