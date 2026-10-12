<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Developer Lab — placeholder.
 *
 * The route, the `dev-lab:use` permission and the sidebar entry all exist and
 * resolve. Only the module itself is unbuilt.
 *
 * A caution carried forward from the specification: "run arbitrary user code in
 * a browser" is the part that needs designing rather than porting. It implies a
 * sandbox boundary, resource limits and an abuse story that this application
 * does not currently provide, so the runner is an open question rather than a
 * settled requirement.
 */
new #[Layout('layouts::dashboard')] #[Title('Developer Lab')] class extends Component
{
    //
};
?>

<div>
    <x-module-placeholder
        :title="__('Developer Lab')"
        :description="__('A browser-based environment to write, run, inspect and debug code, with practice challenges and saved work.')"
        icon="beaker"
        :spec="'docs/lab.md'"
        :features="[
            __('Write and run code in the browser'),
            __('Inspect output and debug errors'),
            __('Work through guided programming challenges'),
            __('Save and revisit your work'),
        ]"
    />
</div>
