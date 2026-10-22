<?php

use App\Enums\AccountPermission;
use App\Models\LabSnippet;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Developer Lab — public landing page.
 *
 * The page describes a module that exists. It is assembled rather than written as
 * one template: `sections()` returns a list of component names and their data, and
 * the loop below renders each through `<x-dynamic-component>`, which keeps the order
 * of the page, the wording of every panel and the audience rule in this file where
 * they can be read together, while the markup stays in `components/app/dev-lab/*`.
 *
 * What the lab is today, stated in the order a reader needs it: an editor with three
 * panes, a preview that runs the code inside a frame with no origin of its own, a
 * snippet library an account owns, and a public gallery. What it is not: a server-side
 * runner. HTML, CSS and JavaScript execute in the visitor's browser because that is
 * the only place this application can put arbitrary user code without a container, a
 * quota, and an abuse story it does not have yet. PHP is stored, listed, escaped and
 * copyable, and has no Run button.
 *
 * One panel on this page mounts a frame and runs code: the showcase, whose three panes
 * are the repository's own, held in `config('dev_lab.showcase')` and composed only when
 * a visitor presses the button. It is the same factory, the same shim and the same two
 * sandbox attributes the authenticated editor uses, which is the point — a stranger can
 * see what the boundary does before deciding whether to sign in, and demonstrating it
 * does not relax it. Nothing on this page executes on load.
 *
 * The boundary claim is repeated here rather than left to `docs/lab.md` because the
 * page is where a visitor forms the expectation. A reader who believes the lab will
 * run their PHP on a server will be disappointed by a button that is not there; a
 * reader who understands that their own browser is the runtime knows exactly what
 * they are agreeing to when they press Run on somebody else's HTML.
 *
 * No `#[Layout]` attribute: the page renders in the default public shell
 * (`layouts::guest.home`), the way the marketplace and hub pages do, because a guest
 * has no workspace chrome to be drawn inside.
 */
new #[Title('Developer Lab')] class extends Component
{
    public function mount(): void
    {
        app(Seo::class)->set([
            'title' => 'Developer Lab',
            'description' => __('Write HTML, CSS and JavaScript in the browser, run it in a frame that cannot reach this site, and keep what is worth keeping as a snippet.'),
            'canonical' => route('dev-lab.index'),
        ]);
    }

    /**
     * The panels this page is built from, in the order a reader meets them.
     *
     * @return list<array{component: string, data: array<string, mixed>}>
     */
    public function sections(): array
    {
        return [
            [
                'component' => 'app.dev-lab.overview',
                'data' => [
                    'title' => __('Developer Lab'),
                    'description' => __('An editor for HTML, CSS and JavaScript, a preview that runs your own code inside a sandboxed frame, and a snippet library you can publish a page from. PHP is stored and shared, and is not run anywhere.'),
                    'icon' => 'beaker',
                    'status' => __('Runs in your browser'),
                    'statusTone' => 'success',
                ],
            ],
            [
                'component' => 'app.dev-lab.capabilities',
                'data' => ['groups' => $this->capabilities()],
            ],
            [
                'component' => 'app.dev-lab.showcase',
                'data' => ['panes' => config('dev_lab.showcase')],
            ],
            [
                'component' => 'app.dev-lab.status',
                'data' => [
                    'title' => __('What the lab will not do'),
                    'icon' => 'shield-check',
                    'description' => __('There is no server-side runner. Nothing this lab stores is executed by the application, which means no CPU quota, no container, and no PHP. The four limits that follow are the design, not oversights:'),
                    'points' => [
                        __('A PHP snippet is text. It is listed, escaped on every page, and copyable. The Run button does not appear for it, and the action that records a run refuses the language on the server as well as in the interface, so the absence is not a hidden control.'),
                        __('The preview has no origin. The frame is sandboxed without same-origin permission, so a page running in it cannot read this site’s cookies, its storage, or the document around it, and cannot call back into the application with your session attached.'),
                        __('An infinite loop hangs your tab. Run rebuilds the frame, so pressing Run again replaces a looping document with a fresh one, and the daily allowance limits how often the button may be pressed. There is no timeout, because a browser does not give the page one to enforce.'),
                        __('`alert()`, `confirm()` and `prompt()` do not open. A modal from a sandboxed frame would be the frame speaking to your browser rather than to itself, so the console pane says what was called instead.'),
                    ],
                    'spec' => 'docs/lab.md',
                ],
            ],
            [
                'component' => 'app.dev-lab.access',
                'data' => $this->access(),
            ],
        ];
    }

    /**
     * The capabilities that are true today, grouped by the step of the workflow they
     * serve, in the order a reader works: write, run, keep.
     *
     * Deferred entries from `docs/lab.md` are not listed as though they were pending
     * on this page. A challenge system, a step-through debugger, autopairing and
     * in-editor syntax highlighting are each a separate decision about dependencies,
     * and saying so in the specification is where they belong; this list is the
     * inventory of what is in front of you.
     *
     * @return list<array{label: string, icon: string, items: list<string>}>
     */
    private function capabilities(): array
    {
        return [
            [
                'label' => __('Write'),
                'icon' => 'code-bracket',
                'items' => [
                    __('Three panes — HTML, CSS, JavaScript — with a byte counter on each'),
                    __('A monospace editor that handles Tab, and says plainly that it does not colour your syntax'),
                    __('Named workspaces that save, re-open and stay yours'),
                ],
            ],
            [
                'label' => __('Run and inspect'),
                'icon' => 'play',
                'items' => [
                    __('Run composes the three panes into one document and shows it beside your code'),
                    __('console.log, warnings, errors and unhandled rejections arrive in a console pane'),
                    __('Nothing rebuilds while you type. The frame only changes when you ask it to'),
                ],
            ],
            [
                'label' => __('Keep and publish'),
                'icon' => 'circle-stack',
                'items' => [
                    __('Save any pane as a snippet, in HTML, CSS, JavaScript or PHP'),
                    __('Publish one and it gets a public page: escaped code, a copy button, an opt-in preview'),
                    __('A history of your runs, and the number that remain today'),
                ],
            ],
        ];
    }

    /**
     * The panel that answers "so what can I do right now?", by audience.
     *
     * Three states, all reachable. A guest can read every published snippet and can
     * preview them in the same frame, so signing in is no longer the only door. An
     * account holding `dev-lab:use` has the editor and is sent there. One that does
     * not holds a permission the module needs, and should be told that rather than
     * handed a form that will 403.
     *
     * @return array{heading: string, description: string, icon: string, tone: string, action: ?array{label: string, href: string, icon: string}, secondary?: array{label: string, href: string, icon: string}}
     */
    private function access(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [
                'heading' => __('Read the gallery first'),
                'description' => __('Every published snippet is public, including its preview: the code is escaped as text, and the frame it runs in belongs to the visitor who asked for it rather than to this site. Saving your own work is the part that needs an account.'),
                'icon' => 'eye',
                'tone' => 'default',
                'action' => [
                    'label' => __('Open the gallery'),
                    'href' => route('dev-lab.gallery'),
                    'icon' => 'square-3-stack-3d',
                ],
                'secondary' => [
                    'label' => __('Sign in and open the sandbox'),
                    'href' => route('home', ['signin' => 'required']),
                    'icon' => 'arrow-long-right',
                ],
            ];
        }

        if ($user->hasPermission(AccountPermission::UseDeveloperLab)) {
            return [
                'heading' => __('The editor is open'),
                'description' => __('Your role holds the Developer Lab permission, so the sandbox, your workspaces and your snippets are reachable from your workspace navigation. Nothing needs enabling and nothing needs asking.'),
                'icon' => 'command-line',
                'tone' => 'default',
                'action' => [
                    'label' => __('Open the sandbox'),
                    'href' => route('dev-lab.sandbox'),
                    'icon' => 'play',
                ],
                'secondary' => [
                    'label' => __('Your snippets'),
                    'href' => route('dev-lab.snippets'),
                    'icon' => 'circle-stack',
                ],
            ];
        }

        return [
            'heading' => __('Not part of your plan'),
            'description' => __('This page is public, so you can read the gallery and preview what is in it. Writing needs the Developer Lab permission, which is why the workspace has no entry for you.'),
            'icon' => 'lock-closed',
            'tone' => 'attention',
            'action' => [
                'label' => __('Open the gallery'),
                'href' => route('dev-lab.gallery'),
                'icon' => 'square-3-stack-3d',
            ],
        ];
    }

    /**
     * The most recently published snippets, so the landing page shows the module’s
     * output rather than only its intentions.
     *
     * Three rows and no pagination: this is a door, not a second gallery, and the
     * filterable list one link away is the place that belongs to a reader who wants
     * more than a glance.
     *
     * @return Collection<int, LabSnippet>
     */
    #[Computed]
    public function featured(): Collection
    {
        return LabSnippet::query()
            ->published()
            ->with('user:id,name')
            ->recentlyPublished()
            ->take(3)
            ->get();
    }

    /**
     * How many snippets are public, for the line that introduces the featured strip.
     */
    #[Computed]
    public function publishedCount(): int
    {
        return LabSnippet::query()->published()->count();
    }
}; ?>

<div data-test="dev-lab-landing">
    <x-app.ui.header
        :title="__('Developer Lab')"
        :description="__('An editor, a sandboxed preview, and a shelf for the code worth keeping. What runs, what is only stored, and why.')"
        :breadcrumbs="[['label' => __('Home')], ['label' => __('Developer Lab')]]"
    />

    <x-app.ui.body max="prose">
        @foreach ($this->sections() as $section)
            <x-dynamic-component :component="$section['component']" :data="$section['data']" />
        @endforeach

        @if ($this->featured->isNotEmpty())
            <x-app.ui.section
                :title="__('Published this week and before')"
                icon="globe-alt"
            >
                <p class="text-sm leading-normal text-ink-500">
                    {{ trans_choice(
                        'There is :count published snippet on the gallery.|There are :count published snippets on the gallery.',
                        $this->publishedCount,
                        ['count' => $this->publishedCount],
                    ) }}
                </p>

                <ul class="mt-4 space-y-3">
                    @foreach ($this->featured as $snippet)
                        <li wire:key="dev-lab-featured-{{ $snippet->id }}">
                            <x-app.dev-lab.snippet-card :snippet="$snippet" compact />
                        </li>
                    @endforeach
                </ul>

                <flux:button
                    :href="route('dev-lab.gallery')"
                    wire:navigate
                    variant="ghost"
                    size="sm"
                    icon="arrow-long-right"
                    class="mt-4"
                >
                    {{ __('Browse the gallery') }}
                </flux:button>
            </x-app.ui.section>
        @endif
    </x-app.ui.body>
</div>
