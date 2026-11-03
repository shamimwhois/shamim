<?php

use App\Enums\AccountPermission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * AI Hub — Ideas shelf (placeholder).
 *
 * The route lives under the public hub prefix but is staff-only: it gates on
 * `ai-hub:manage` because the shelf's content model is not built yet. Rather
 * than render a broken listing to anyone, the page states the module's intent
 * through the shared module-placeholder component.
 *
 * Building the Ideas workspace did not build this. A shelf is a curated
 * selection made by an editor — hub-side rows deciding which ideas to surface
 * and in what order — whereas the Ideas bank holds one account's own thinking
 * behind its own visibility rule. The first is a curation model that does not
 * exist yet; the second exists and is not the same object.
 *
 * A placeholder still needs the component's own guard. `ai-hub:manage` on the
 * route decides who may open the page; a Livewire request does not pass through
 * it, so without `boot()` this is the one screen in the hub whose staff-only
 * rule is enforced in exactly one place.
 */
new #[Layout('layouts::app.dashboard')] #[Title('AI Hub — Ideas shelf')] class extends Component
{
    public function boot(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(AccountPermission::ManageAiHub) ?? false,
            403
        );
    }
};
?>

<div>
    <x-app.ui.module-placeholder
        :title="__('Ideas shelf')"
        :description="__('A curated shelf of ideas, plans and research notes, managed with the AI Knowledge Hub and surfaced alongside articles, topics, paths and resources.')"
        icon="light-bulb"
        :spec="'docs/AI Knowledge Hub & Package.md'"
        :alreadyWorks="__('The Ideas workspace is built: capture, the stage workflow, plans and tasks all exist behind ideas:use, and the module console at /admin/ideas reads across accounts. What is missing is the hub-side curation model, the rows that say which ideas this shelf has chosen and in what order, which is a different object from one account\'s own idea bank.')"
        :features="[
            __('Curate ideas from the Idea Bank workspace'),
            __('Promote promising ideas into hub articles and topics'),
            __('Organise entries by status and focus area'),
            __('Publish curated entries to the AI Knowledge Hub'),
        ]"
    />
</div>