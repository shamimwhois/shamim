<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * AI Knowledge Hub — placeholder.
 *
 * The route, the `ai-hub:use` permission and the sidebar entry all exist and
 * resolve. Only the module itself is unbuilt.
 *
 * Note for when this is built: the specification assumes a single-author
 * knowledge base. This application has three account roles, and a hub with
 * enrolled readers will need its own permission additions rather than reusing
 * the publishing set.
 */
new #[Layout('layouts::dashboard')] #[Title('AI Knowledge Hub')] class extends Component
{
    //
};
?>

<div>
    <x-module-placeholder
        :title="__('AI Knowledge Hub')"
        :description="__('A structured knowledge base for AI concepts and practice, with published packages alongside it.')"
        icon="sparkles"
        :spec="'docs/AI Knowledge Hub & Package.md'"
        :features="[
            __('Browse a structured index of AI concepts'),
            __('Follow guided learning paths'),
            __('Publish and discover reusable packages'),
            __('Track what you have covered'),
        ]"
    />
</div>
