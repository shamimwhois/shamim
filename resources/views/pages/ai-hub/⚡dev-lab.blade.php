<?php

use App\Enums\AccountPermission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * AI Hub — Developer Lab shelf (placeholder).
 *
 * The route lives under the public hub prefix but is staff-only: it gates on
 * `ai-hub:manage` because the shelf's content model is not built yet. Rather
 * than render a broken listing to anyone, the page states the module's intent
 * through the shared module-placeholder component.
 *
 * Building the sandbox and the snippet library did not build this shelf either:
 * a shelf is an editor's selection of lab work worth reading about, which is a
 * hub-side curation row rather than another view of somebody's snippets.
 *
 * A placeholder still needs the component's own guard, for the same reason as
 * the Ideas shelf beside it: a Livewire request never passes through the
 * route's `permission:ai-hub:manage`.
 */
new #[Layout('layouts::app.dashboard')] #[Title('AI Hub — Developer Lab shelf')] class extends Component
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
        :title="__('Developer Lab shelf')"
        :description="__('A curated shelf of AI experiments and practical projects, managed with the AI Knowledge Hub and surfaced alongside articles, topics, paths and resources.')"
        icon="beaker"
        :spec="'docs/AI Knowledge Hub & Package.md'"
        :alreadyWorks="__('The Developer Lab is built: the three-pane sandbox runs HTML, CSS and JavaScript in a frame with no origin of its own, snippets save and publish, and the run history counts itself against a per-day allowance. What is missing is the hub-side curation model, the rows that say which snippets this shelf has chosen and in what order, which is a different object from one account\'s own library.')"
        :features="[
            __('Curate AI experiments and practical projects'),
            __('Feature completed work from the Developer Lab workspace'),
            __('Organise entries by difficulty and topic'),
            __('Publish curated entries to the AI Knowledge Hub'),
        ]"
    />
</div>