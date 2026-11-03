<?php

namespace App\Actions\DevLab;

use App\Concerns\ResolvesUniqueSlug;
use App\Enums\ContentVisibility;
use App\Enums\LabLanguage;
use App\Models\LabSnippet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create or update a snippet, including the decision to show it to strangers.
 *
 * This class is the only writer of `visibility` and `published_at` in the module,
 * which is what makes the publish pair a rule instead of a habit. An idea needs a
 * stage before it can go public; a snippet needs something to read, and that is the
 * whole difference between the two. A snippet with no code in it is a blank page
 * with a URL, so the pair refuses to complete rather than publishing the emptiness.
 *
 * The `slug` is resolved here and never taken from the request as-is, because it
 * carries a UNIQUE index. `user_id` is set once, on create, so no edit can hand a
 * snippet to a different account.
 *
 * The byte ceiling applies to the code, for the same reason it applies to the panes
 * of a workspace: the column would store more than a browser tab can display, and
 * the failure would arrive as a frozen page rather than as an error message.
 */
class SaveLabSnippet
{
    use ResolvesUniqueSlug;

    public function __construct(
        protected SyncLabSnippetTopics $syncTopics,
    ) {}

    /**
     * Persist the snippet, creating it when none is given.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $author, array $data, ?LabSnippet $snippet = null): LabSnippet
    {
        return DB::transaction(function () use ($author, $data, $snippet): LabSnippet {
            $snippet ??= new LabSnippet;

            $title = trim((string) ($data['title'] ?? $snippet->title ?? ''));

            if ($title === '') {
                throw new InvalidArgumentException(__('A snippet needs a title before it can be saved.'));
            }

            $language = $this->resolveLanguage($data['language'] ?? $snippet->language);
            $code = array_key_exists('code', $data) ? (string) $data['code'] : (string) $snippet->code;

            $this->assertCodeFits($code);

            $snippet->user_id ??= $author->id;
            $snippet->fill([
                'title' => mb_substr($title, 0, LabSnippet::TITLE_MAX),
                'code' => $code,
                'description' => array_key_exists('description', $data)
                    ? $this->nullable($data['description'])
                    : $snippet->description,
            ]);

            $snippet->language = $language;
            $snippet->slug = $this->resolveUniqueSlug($snippet, $data, $title);

            if (array_key_exists('visibility', $data)) {
                $visibility = $this->resolveVisibility($data['visibility']);

                if ($visibility->isPublic()) {
                    $this->assertWorthPublishing($code);

                    $snippet->published_at ??= Carbon::now();
                }

                $snippet->visibility = $visibility;
            }

            $snippet->save();

            // Absent means "this caller does not file by topic", which is not the
            // same instruction as "clear the topics". The sandbox's save-from-the-
            // pane path knows nothing about the taxonomy and must not wipe it.
            if (array_key_exists('topics', $data) && is_array($data['topics'])) {
                $this->syncTopics->handle($snippet, $data['topics']);
            }

            return $snippet->refresh();
        });
    }

    /**
     * Coerce a submitted language, refusing one the lab does not know.
     *
     * Silently storing a typo as HTML would put a PHP snippet in the HTML shelf,
     * where the only thing that notices is the reader.
     */
    protected function resolveLanguage(mixed $value): LabLanguage
    {
        if ($value instanceof LabLanguage) {
            return $value;
        }

        $language = LabLanguage::tryFrom((string) ($value ?? ''));

        if ($language === null) {
            throw new InvalidArgumentException(__('The lab does not know that language. Choose HTML, CSS, JavaScript or PHP.'));
        }

        return $language;
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

    /**
     * Refuse code the sandbox cannot hold open.
     */
    protected function assertCodeFits(string $code): void
    {
        $limit = (int) config('dev_lab.max_code_bytes');

        if (strlen($code) > $limit) {
            throw new InvalidArgumentException(
                __('That snippet is :size bytes, and the lab stores at most :limit. Two snippets read better than one that has to be scrolled.', [
                    'size' => number_format((float) strlen($code)),
                    'limit' => number_format($limit),
                ])
            );
        }
    }

    /**
     * Refuse to publish a snippet with nothing in it.
     *
     * The check runs against the code about to be stored rather than the code
     * already on the row, so saving a snippet and publishing it in one request is
     * still refused on the first request rather than the second.
     */
    protected function assertWorthPublishing(string $code): void
    {
        if (trim($code) === '') {
            throw new InvalidArgumentException(__('There is no code in this snippet yet. A public page with an empty block on it is not something anybody should be sent to.'));
        }
    }

    /**
     * Treat an empty string as no value, so a cleared field is really cleared.
     */
    protected function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, LabSnippet::DESCRIPTION_MAX);
    }
}
