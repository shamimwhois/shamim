<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ideas, Plans, Tasks & Projects — placeholder.
 *
 * The route, the `ideas:use` permission and the sidebar entry all exist and
 * resolve. Only the module itself is unbuilt.
 *
 * The specification also covers plan sharing between accounts. That will need
 * its own permission additions: the current `AccountPermission` set describes
 * what an account may do to its own content, not what it may share with, or
 * see of, another account.
 */
new #[Layout('layouts::dashboard')] #[Title('Ideas')] class extends Component
{
    //
};
?>

<div>
    <x-module-placeholder
        :title="__('Ideas, Plans & Projects')"
        :description="__('Capture ideas, shape them into plans, break plans into tasks, and track projects through to delivery.')"
        icon="light-bulb"
        :spec="'docs/Idea.md'"
        :features="[
            __('Capture ideas as they arrive'),
            __('Turn ideas into structured plans'),
            __('Break plans into trackable tasks'),
            __('Follow project progress to completion'),
        ]"
    />
</div>
