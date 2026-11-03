<?php

namespace App\Models;

use App\Enums\AiLinkKind;
use Database\Factories\AiArticleLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An external reference attached to a hub article.
 *
 * Links live in their own table rather than in the markdown because the hub is
 * accountable for what it recommends: an operator can review, re-label, and
 * remove a reference without editing the article's prose, and the article page
 * can render them as a grouped, labelled list instead of a wall of inline
 * anchors.
 *
 * @property int $id
 * @property int $ai_article_id
 * @property string $label
 * @property string $url
 * @property AiLinkKind $kind
 * @property string|null $note
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiArticle $article
 */
#[Fillable(['ai_article_id', 'label', 'url', 'kind', 'note', 'sort_order'])]
class AiArticleLink extends Model
{
    /** @use HasFactory<AiArticleLinkFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AiLinkKind::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the article the reference belongs to.
     *
     * @return BelongsTo<AiArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(AiArticle::class, 'ai_article_id');
    }

    /**
     * Get the host the reference resolves to, for the article's link rail.
     *
     * Same projection as `AiResource::host()`: the reader is shown where the
     * link actually goes rather than being asked to trust the label.
     */
    public function host(): string
    {
        return Str::lower((string) parse_url($this->url, PHP_URL_HOST));
    }
}
