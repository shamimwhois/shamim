<?php

namespace App\Models;

use App\Support\FeedAttachmentTypes;
use Database\Factories\FeedAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * One file attached to a community post.
 *
 * The bytes live on a private disk and are never reachable by path: the only way
 * to read a file is through the signed `feed.attachments.show` route, which
 * re-runs the post's visibility rule before it opens the stream. Everything the
 * record knows about the file — mime, size, dimensions — was read back from the
 * disk after the move, so a forged Content-Type in the upload never becomes the
 * truth the browser is served.
 *
 * **Two kinds, one row.** An image is painted inline; everything else is a
 * download. Which one a row is comes from {@see isImage()}, which reads the
 * mime the disk reported — the same question the serving controller asks, so a
 * row can never be drawn as a picture by a page that would be served as a
 * download.
 *
 * A row with a `temp_key` and no post is an upload in flight: staged by the
 * composer, owned by the uploader, and reclaimed by `feed:prune-media` if the
 * composer is abandoned.
 *
 * @property int $id
 * @property int|null $feed_post_id
 * @property int $user_id
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property int $sort_order
 * @property string|null $temp_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'feed_post_id',
    'user_id',
    'disk',
    'path',
    'mime',
    'size',
    'width',
    'height',
    'alt_text',
    'sort_order',
    'temp_key',
])]
class FeedAttachment extends Model
{
    /** @use HasFactory<FeedAttachmentFactory> */
    use HasFactory;

    /**
     * How long a signed media URL stays valid.
     *
     * Short on purpose: it is a stream permission, not a link a reader saves.
     */
    public const URL_TTL_MINUTES = 10;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The post this media belongs to, null while the upload is still a draft.
     *
     * @return BelongsTo<FeedPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedPost::class, 'feed_post_id');
    }

    /**
     * The account that uploaded the file.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope to uploads that have been claimed by a post.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function attached(Builder $query): void
    {
        $query->whereNotNull('feed_post_id');
    }

    /**
     * Scope to staged uploads no composer has finished with.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unattached(Builder $query): void
    {
        $query->whereNull('feed_post_id')->whereNotNull('temp_key');
    }

    /**
     * The signed URL a viewer may load the file from.
     */
    public function url(): string
    {
        return URL::temporarySignedRoute(
            'feed.attachments.show',
            now()->addMinutes(self::URL_TTL_MINUTES),
            ['attachment' => $this->id],
        );
    }

    /**
     * The rendered alt text, never blank in a way that strands a screen reader.
     *
     * The fallback is kind-aware. It said "Image attached to a community post"
     * while images were all the disk took; said of a spreadsheet, a screen
     * reader would describe the wrong thing entirely. An image with no alt is
     * still an image, so the two branches keep the specific word where it is
     * true and fall back to a neutral one where it is not.
     */
    public function alt(): string
    {
        if ($this->alt_text !== null && trim($this->alt_text) !== '') {
            return $this->alt_text;
        }

        return $this->isImage()
            ? __('Image attached to a community post')
            : __('File attached to a community post');
    }

    /**
     * Whether the row is painted inline rather than offered as a download.
     *
     * Delegated to the shared type list because the serving controller asks the
     * same question to choose a `Content-Disposition`. Two implementations of
     * it would eventually disagree, and the disagreement would be a page
     * painting a file the route refuses to serve inline.
     */
    public function isImage(): bool
    {
        return FeedAttachmentTypes::isImage($this->mime);
    }

    /**
     * The stored extension, lowercased, or an empty string.
     *
     * The second half of "what kind of file is this". A `.docx` and a `.zip`
     * have the same mime and the same bytes, so the extension is the only thing
     * that separates them — and the stored path carries it precisely because
     * {@see AttachFeedMedia} reconciles it against the content.
     */
    public function extension(): string
    {
        return Str::lower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    /**
     * The word a chip shows for what kind of file this is.
     */
    public function kindLabel(): string
    {
        return FeedAttachmentTypes::kind($this->mime, $this->extension());
    }

    /**
     * A name a reader recognises for the download.
     *
     * The stored path is a UUID precisely so that an attacker-chosen filename
     * never reaches the filesystem — which means there is no original name left
     * to show. So the name is rebuilt from what the bytes are: a slug of the
     * kind plus the id, and the stored extension. `report-3f9a.pdf` is enough
     * for a reader to know what they are about to open and for the operating
     * system to pick the right application.
     */
    public function displayName(): string
    {
        $extension = pathinfo($this->path, PATHINFO_EXTENSION);
        $stem = Str::slug($this->kindLabel()) ?: 'file';
        $suffix = $extension !== '' ? ".{$extension}" : '';

        return "{$stem}-{$this->id}{$suffix}";
    }

    /**
     * The file size formatted for the composer's upload list.
     */
    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max(1, (int) $this->size);
        $power = min(count($units) - 1, (int) floor(log($bytes, 1024)));

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }
}
