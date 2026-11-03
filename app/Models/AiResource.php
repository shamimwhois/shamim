<?php

namespace App\Models;

use App\Enums\AiPublicationStatus;
use App\Enums\AiResourceKind;
use Database\Factories\AiResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A link to something the hub does not own.
 *
 * Resources are kept out of article bodies on purpose. Third-party material
 * changes without the hub being told, and a link buried in prose is a link
 * nobody is looking at when it rots. As their own rows they can be reviewed,
 * re-labelled, hidden, and archived in one place, and the shelf that renders
 * them can say plainly that the destination is not the hub's page.
 *
 * @property int $id
 * @property int|null $ai_topic_id
 * @property int|null $user_id
 * @property string $title
 * @property string|null $description
 * @property string $url
 * @property AiResourceKind $kind
 * @property string|null $provider
 * @property AiPublicationStatus $status
 * @property bool $is_featured
 * @property int $sort_order
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiTopic|null $topic
 * @property-read User|null $user
 */
#[Fillable([
    'ai_topic_id',
    'user_id',
    'title',
    'description',
    'url',
    'kind',
    'provider',
    'status',
    'is_featured',
    'sort_order',
    'published_at',
])]
class AiResource extends Model
{
    /** @use HasFactory<AiResourceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AiResourceKind::class,
            'status' => AiPublicationStatus::class,
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Get the topic the resource is filed under.
     *
     * @return BelongsTo<AiTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(AiTopic::class, 'ai_topic_id');
    }

    /**
     * Get the operator who added the resource.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to resources visible on public surfaces.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', AiPublicationStatus::Published)
            ->whereNotNull('published_at');
    }

    /**
     * Scope to resources in operator order.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('title');
    }

    /**
     * Scope to resources filed under the given topic slug.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function inTopic(Builder $query, string $topicSlug): void
    {
        $query->whereHas('topic', fn (Builder $query) => $query->where('slug', $topicSlug));
    }

    /**
     * Scope to resources of the given kind.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function ofKind(Builder $query, AiResourceKind $kind): void
    {
        $query->where('kind', $kind);
    }

    /**
     * Scope to resources matching a free-text term.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            $query->where('title', 'like', $pattern)
                ->orWhere('description', 'like', $pattern)
                ->orWhere('provider', 'like', $pattern);
        });
    }

    /**
     * Determine whether the resource is publicly readable.
     */
    public function isPublished(): bool
    {
        return $this->status === AiPublicationStatus::Published && $this->published_at !== null;
    }

    /**
     * Get the hostname the link leads to, for display.
     *
     * Showing the destination's host next to the label is the cheapest possible
     * defence against a resource whose label promises one site and whose URL
     * says another.
     */
    public function host(): string
    {
        return Str::lower((string) parse_url($this->url, PHP_URL_HOST));
    }
}
