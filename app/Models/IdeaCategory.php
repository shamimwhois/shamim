<?php

namespace App\Models;

use App\Concerns\HasSlug;
use Database\Factories\IdeaCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A folder one account files its own ideas into.
 *
 * Categories belong to the account that made them. The blog's `categories` and
 * `tags` are deliberately not reused: those drive the public site's navigation
 * and post filters, and letting a workspace write into them would put other
 * people's taxonomies in front of readers who never asked for them.
 *
 * The `slug` index is per-account rather than global, because a category is never
 * addressed by URL: two accounts that both think they need a "security" folder both
 * get `security`, and nothing breaks. It is also why the public ideas landing
 * filters by search and promotion rather than by category: a browse-by-category
 * control would be offering a shared taxonomy that does not exist.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Idea> $ideas
 */
#[Fillable(['user_id', 'name', 'slug', 'description'])]
class IdeaCategory extends Model
{
    /** @use HasFactory<IdeaCategoryFactory> */
    /** @use HasFactory<IdeaCategoryFactory> */
    use HasFactory, HasSlug;

    /**
     * Get the ideas filed under this category.
     *
     * @return HasMany<Idea, $this>
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(Idea::class);
    }

    /**
     * Get the account that owns the category.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to the categories one account owns, alphabetically.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function authoredBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)->orderBy('name');
    }
}
