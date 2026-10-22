<?php

namespace App\Actions\AiHub;

use App\Enums\AiPublicationStatus;
use App\Enums\AiResourceKind;
use App\Models\AiResource;
use App\Models\AiTopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Create or update a resources-shelf entry.
 *
 * A resource is the hub's only outward link, so the URL is checked before it is
 * stored — a shelf that renders a relative path as an external link is a shelf
 * that quietly sends readers somewhere other than the intended site. Like the
 * other hub types, a new resource is filed as a draft whatever the form
 * submitted; going public is a transition owned by `SyncAiPublicationStatus`.
 */
class SaveAiResource
{
    public function __construct(
        protected SyncAiPublicationStatus $syncStatus,
    ) {}

    /**
     * Persist the resource, creating it when none is given.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, array $data, ?AiResource $resource = null): AiResource
    {
        $resource ??= new AiResource;

        $isNew = ! $resource->exists;

        $requestedStatus = $this->resolveStatus($data['status'] ?? null) ?? ($isNew ? AiPublicationStatus::Draft : $resource->status);

        $currentStatus = $isNew ? AiPublicationStatus::Draft : $resource->status;

        $url = trim((string) ($data['url'] ?? $resource->url ?? ''));

        $this->assertUsableUrl($url);

        $resource->user_id ??= $author->id;

        $resource->fill([
            'ai_topic_id' => $this->resolveTopicId($data['ai_topic_id'] ?? $resource->ai_topic_id),
            'title' => trim((string) ($data['title'] ?? $resource->title ?? '')),
            'description' => array_key_exists('description', $data) ? $data['description'] : $resource->description,
            'url' => $url,
            'kind' => $this->resolveKind($data['kind'] ?? $resource->kind),
            'provider' => array_key_exists('provider', $data) ? $data['provider'] : $resource->provider,
            'is_featured' => (bool) ($data['is_featured'] ?? $resource->is_featured ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? $resource->sort_order ?? $this->nextSortOrder($resource)),
        ]);

        $resource->status = $currentStatus;

        $resource->save();

        if (! $isNew && $requestedStatus !== $currentStatus) {
            $this->syncStatus->handle($resource, $requestedStatus);
            $resource->save();
        }

        return $resource->refresh();
    }

    /**
     * Append to the end of the shelf when the operator did not name a position.
     */
    protected function nextSortOrder(AiResource $resource): int
    {
        $topicId = $resource->ai_topic_id;

        return (int) AiResource::query()
            ->where(fn (Builder $query) => $topicId === null
                ? $query->whereNull('ai_topic_id')
                : $query->where('ai_topic_id', $topicId))
            ->whereKeyNot($resource->getKey() ?? 0)
            ->max('sort_order') + 1;
    }

    /**
     * Reject anything that is not an absolute http(s) URL.
     */
    protected function assertUsableUrl(string $url): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('A resource needs a full link, including https://');
        }

        if (! in_array((string) parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Resources can only link to http or https addresses.');
        }
    }

    /**
     * Resolve the filed topic, discarding an id that matches no topic.
     */
    protected function resolveTopicId(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $id = (int) $id;

        if ($id <= 0 || ! AiTopic::query()->whereKey($id)->exists()) {
            throw new InvalidArgumentException('Unknown hub topic.');
        }

        return $id;
    }

    /**
     * Coerce a submitted kind to an enum case, defaulting to documentation.
     */
    protected function resolveKind(mixed $value): AiResourceKind
    {
        if ($value instanceof AiResourceKind) {
            return $value;
        }

        if ($value === null || trim((string) $value) === '') {
            return AiResourceKind::Documentation;
        }

        return AiResourceKind::tryFrom((string) $value) ?? AiResourceKind::Documentation;
    }

    /**
     * Coerce a submitted status to an enum case.
     */
    protected function resolveStatus(mixed $value): ?AiPublicationStatus
    {
        if ($value instanceof AiPublicationStatus) {
            return $value;
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $status = AiPublicationStatus::tryFrom((string) $value);

        if ($status !== null && ! $status->isContainerStatus()) {
            throw new InvalidArgumentException('Resources cannot be held in review.');
        }

        return $status;
    }
}
