<?php

use App\Models\AiTopic;
use App\Services\AiHubContent;
use App\Support\Seo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The hub's topic index.
 *
 * Topics are the hub's navigation, and they are operator-managed: an editor can
 * add one, rename it, reorder it, or switch it off. This page therefore renders
 * whatever is currently active, in the current order, rather than a fixed list
 * compiled into the view. Deactivating a topic removes it from here and from
 * every shelf in the hub, which is why the grid is read through
 * `AiHubContent::paginateTopics()` instead of queried directly.
 *
 * A topic's count is the count of *published* articles filed under it. That rule
 * is the service's, not this component's, so a card can never promise more
 * reading than the topic page it links to can deliver.
 */
new #[Title('Topics — AI Knowledge Hub')] #[Layout('layouts::feed')] class extends Component
{
    use WithPagination;

    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Topics — AI Knowledge Hub',
            'description' => __('The subject map of the AI Knowledge Hub: every active topic, its subtopics, and how much published reading each one holds.'),
            'canonical' => route('ai-hub.topics'),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, AiTopic>
     */
    #[Computed]
    public function topics(): LengthAwarePaginator
    {
        return app(AiHubContent::class)->paginateTopics((int) config('ai_hub.per_page.topics'));
    }

    /**
     * Total number of active topics, roots and subtopics together.
     */
    #[Computed]
    public function subjectCount(): int
    {
        return app(AiHubContent::class)->topicChips()->count();
    }
}; ?>
<x-log.module
    active="ai-hub"
    data-test="ai-hub-topics"
    :title="__('Topics')"
    :meta="trans_choice(':count subject|:count subjects', $this->subjectCount, ['count' => $this->subjectCount])"
    :note="__('The subject map of the hub. A topic is maintained by hand, and a count on it is a count of published articles — so it always describes what you can actually read.')"
>
    @if ($this->topics->isEmpty())
        <div class="dn-module-empty" data-test="ai-hub-topics-empty">
            <p class="dn-empty-command">fatal: no topics are active</p>
            <p class="dn-empty-text">
                {{ __('Topics stay hidden until an editor activates them. Nothing here is broken.') }}
            </p>
        </div>
    @else
        <div class="dn-entries">
            @foreach ($this->topics as $topic)
                <x-log.entry
                    :href="route('ai-hub.topic', ['topic' => $topic->slug])"
                    :title="$topic->name"
                    :description="$topic->description"
                    :meta="[
                        trans_choice(':count article|:count articles', (int) $topic->articles_count, ['count' => (int) $topic->articles_count]),
                        $topic->parent_id !== null ? __('subtopic') : __('root topic'),
                    ]"
                    :glyph="mb_substr((string) $topic->name, 0, 2)"
                    :wire:key="'hub-topic-'.$topic->id"
                />
            @endforeach
        </div>

        <x-app.ui.pagination :paginator="$this->topics" label="{{ __('AI hub topic pagination') }}" class="dn-pagination" />
    @endif
</x-log.module>
