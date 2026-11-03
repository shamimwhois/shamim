<?php

namespace App\Actions\AiHub;

use App\Enums\AiLinkKind;
use App\Models\AiArticle;
use App\Models\AiArticleLink;

/**
 * Replace an article's reference links with the rows the operator submitted.
 *
 * Links live in their own table rather than in the article body so a dead
 * reference can be found, relabelled, or dropped without rewriting prose. That
 * only holds if the set is replaced wholesale on every save, which is why rows
 * missing from the submitted list are deleted instead of left orphaned.
 */
class SyncAiArticleLinks
{
    /**
     * @param  array<int, array<string, mixed>>  $links
     */
    public function handle(AiArticle $article, array $links): void
    {
        $keep = [];
        $order = 0;

        foreach ($links as $link) {
            $url = trim((string) ($link['url'] ?? ''));

            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $attributes = [
                'label' => trim((string) ($link['label'] ?? '')) ?: $this->fallbackLabel($url),
                'url' => $url,
                'kind' => $this->resolveKind($link['kind'] ?? null),
                'note' => filled($link['note'] ?? null) ? trim((string) $link['note']) : null,
                'sort_order' => $order++,
            ];

            $existing = isset($link['id'])
                ? AiArticleLink::query()->whereKey($link['id'])->where('ai_article_id', $article->id)->first()
                : null;

            $row = $existing ?? new AiArticleLink(['ai_article_id' => $article->id]);
            $row->fill($attributes);
            $row->save();

            $keep[] = $row->id;
        }

        $article->links()
            ->when($keep !== [], fn ($query) => $query->whereKeyNot($keep))
            ->delete();
        $article->unsetRelation('links');
    }

    /**
     * Derive a label from the URL when the operator left the field empty.
     */
    protected function fallbackLabel(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }

    /**
     * Coerce the submitted kind, falling back to a plain reference.
     */
    protected function resolveKind(mixed $kind): AiLinkKind
    {
        if ($kind instanceof AiLinkKind) {
            return $kind;
        }

        return AiLinkKind::tryFrom((string) $kind) ?? AiLinkKind::Reference;
    }
}
