<?php

use App\Data\AuthorProfile;
use App\Data\PostMetrics;
use App\Enums\AboutSectionType;
use App\Models\Post;
use App\Services\AboutContent;
use App\Services\ProjectCatalog;
use App\Support\Seo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The about page.
 *
 * Deliberately not a résumé. A CV lists what you did; this page explains how you
 * think, which is what a hiring manager or a peer actually wants to know before
 * deciding whether to read the writing or send an email.
 *
 * Structure:
 *   01 The short version  — the bio, unadorned; renders no section marker
 *   02 Why hire me        — the pitch, as claims you can hold the author to
 *   03 AI Workflow        — the autonomous-delivery dashboard band
 *   04 How I work         — the principles that show up in the decisions
 *   05 Expertise          — where the years went
 *   06 Experience         — the career timeline, newest employer first
 *   07 Selected work      — the same outcome-first summaries as the landing page
 *   08 Recent writing     — proof the thinking happens in public
 *   09 Get in touch       — one action
 */
new #[Title('About')] class extends Component
{
    /**
     * Per-request memo for the content presenter.
     */
    private ?AboutContent $about = null;

    public function mount(): void
    {
        $profile = $this->about()->profile();

        app(Seo::class)->set([
            'title' => 'About '.$profile->name,
            'description' => $profile->bio,
            'canonical' => route('about'),
            'type' => 'profile',
            'authorName' => $profile->name,
        ]);
    }

    /**
     * The content presenter: database rows where they exist, config and
     * built-in defaults everywhere else.
     */
    public function about(): AboutContent
    {
        return $this->about ??= app(AboutContent::class);
    }

    /**
     * A section's stored label, falling back to the built-in wording.
     */
    public function sectionLabel(string $type, string $fallback): string
    {
        return $this->about()->labelFor(AboutSectionType::from($type), $fallback);
    }

    /**
     * A section's stored heading, falling back to the built-in wording.
     */
    public function sectionHeading(string $type, string $fallback): string
    {
        return $this->about()->headingFor(AboutSectionType::from($type), $fallback);
    }

    /**
     * A section's stored lead paragraph, falling back to the built-in wording.
     */
    public function sectionLead(string $type, ?string $fallback = null): ?string
    {
        return $this->about()->leadFor(AboutSectionType::from($type), $fallback);
    }

    /**
     * The author profile, database-overlaid config.
     */
    public function profile(): AuthorProfile
    {
        return $this->about()->profile();
    }

    /**
     * How I work — the operating principles.
     *
     * Database-managed rows when the section has them; the built-in prose
     * otherwise, held by {@see AboutContent} so the fallback lives next to
     * every other About default.
     *
     * @return array<int, array{title: string, body: string}>
     */
    public function principles(): array
    {
        return $this->about()->principles();
    }

    /**
     * Why hire me — the value claims behind the section of the same name.
     *
     * @return array<int, array{title: string, body: string}>
     */
    public function reasons(): array
    {
        return $this->about()->reasons();
    }

    /**
     * Current focus areas.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function focus(): array
    {
        return $this->about()->focus();
    }

    /**
     * AI Workflow dashboard payload — Kanban tasks, agent graph nodes and
     * pipeline metrics, database rows overlaid on the built-in demo.
     *
     * @return array{eyebrow: string, heading: string, lead: string, cta: array{title: string, text: string}, tasks: array<int, array<string, mixed>>, agents: array<int, array{name: string, description: string}>, metrics: array{header: array<int, array{label: string, value: string, tone: string}>, telemetry: array<int, array{label: string, value: string, tone: string}>}}
     */
    public function workflow(): array
    {
        return $this->about()->aiWorkflow();
    }

    /**
     * Memo for stackMap(); heights and rows are deterministic per config.
     *
     * @var array<int, array{title: string, tags: array<int, string>, x: int, y: int, width: int, height: int, tapX: int, wireY: int, wireD: string}>|null
     */
    private ?array $skillsBlocks = null;

    /**
     * Mind-map geometry for section 04's SVG, derived from the expertise
     * config so the diagram can never drift from the cards. Mirrors the
     * reference map: the platform root anchored mid-left, with the six
     * domains flowing to its right in two stacked columns — each node a
     * titled panel carrying the full tag list, exactly like the cards.
     * Nodes are paired row by row (01+02, 03+04, 05+06) and each row
     * parks on its tallest member; the wire `d` is precomputed in PHP
     * because the drawing + the travelling pulse must follow the
     * identical path.
     *
     * @return array<int, array{title: string, tags: array<int, string>, x: int, y: int, width: int, height: int, tapX: int, wireY: int, wireD: string}>
     */
    public function stackMap(): array
    {
        if ($this->skillsBlocks !== null) {
            return $this->skillsBlocks;
        }

        $columns = [360, 740];
        $topMargin = 16;
        $rowGap = 26;
        $headerZone = 62;
        $lineHeight = 21;
        $bottomPad = 17;

        $blocks = [];
        foreach ($this->profile()->expertise as $index => $item) {
            $blockTags = array_values($item['tags']);

            $blocks[$index] = [
                'title' => $item['title'],
                'tags' => $blockTags,
                'column' => $index % 2,
                'row' => intdiv($index, 2),
                'height' => $headerZone + count($blockTags) * $lineHeight + $bottomPad,
            ];
        }

        $rowY = [];
        $cursor = $topMargin;
        foreach (array_chunk($blocks, 2) as $pair) {
            $rowY[$pair[0]['row']] ??= $cursor;
            // An odd domain count leaves the final pair with a single node, so
            // the heights go to max() as an array rather than spread — a lone
            // int is not a valid max() argument.
            $cursor += max(array_column($pair, 'height')) + $rowGap;
        }

        foreach ($blocks as $index => $block) {
            $x = $columns[$block['column']];
            $y = $rowY[$block['row']];
            $wireY = $y + 22;

            $blocks[$index] = [
                'title' => $block['title'],
                'tags' => $block['tags'],
                'x' => $x,
                'y' => $y,
                'width' => 340,
                'height' => $block['height'],
                'tapX' => $x,
                'wireY' => $wireY,
                'wireD' => $block['column'] === 0
                    ? sprintf('M 272 276 C 320 276, 300 %d, %d %d', $wireY, $x, $wireY)
                    : sprintf('M 272 276 C 380 276, 640 %d, %d %d', $wireY, $x, $wireY),
            ];
        }

        return $this->skillsBlocks = $blocks;
    }

    /**
     * ViewBox height of the generated map: the lowest node's bottom edge
     * plus the outer margin, so the canvas hugs the drawing whatever the
     * expertise config holds.
     */
    public function stackHeight(): int
    {
        $bottoms = array_map(
            fn (array $block): int => $block['y'] + $block['height'],
            $this->stackMap(),
        );

        return (int) (($bottoms === [] ? 0 : max($bottoms)) + 16);
    }

    /**
     * Selected work, with the site's own dossier appended.
     *
     * The catalogue derives the URL slug and the documentation key once, so the
     * card and its `/work/{project}` page cannot disagree. Database-managed work
     * rows still win over the configured projects; the site's card is the one
     * permanent entry, because its docs are this repository.
     *
     * @return array<int, \App\Data\Project>
     */
    public function projects(): array
    {
        return app(ProjectCatalog::class)->all();
    }

    /**
     * Recent published posts, to close the loop between the bio and the work.
     *
     * Six so the writing carousel fills the reference's 3x2 grid.
     *
     * @return Collection<int, Post>
     */
    #[Computed]
    public function recentPosts(): Collection
    {
        return Post::query()
            ->published()
            ->recent()
            ->with(['category', 'tags'])
            ->limit(6)
            ->get();
    }

    /**
     * Site-wide content metrics.
     */
    #[Computed]
    public function metrics(): PostMetrics
    {
        return PostMetrics::forSite();
    }
}; ?>

<div>
    {{-- ================================================================== --
     | 00 — Section nav: the scrollspy rail pinned to the viewport's right
     |      edge and vertically centred (the reference implementation's
     |      idiom: icon + label rows, tinted pill on the active link,
     |      hidden below xl). Each link carries a data-toc-target; app.js's
     |      existing [data-toc] observer flips data-active / aria-current
     |      as its section crosses the reading band, and the data-toc-reveal
     |      hook slides the rail in only once the hero is scrolled past.
     |
     |      The rail rests collapsed to a narrow column of icons so it never
     |      fights the hero art for attention; the chevron above the column
     |      is always visible, the labels open on hover, and clicking the
     |      chevron pins the rail open (or shuts it, or hands it back to
     |      the pointer) so the reader's choice survives. app.js owns that
     |      state through the data-pinned attribute it mirrors onto the
     |      button's aria-expanded.
     |
     |      Plain anchors and no rail under JS-less rendering.
     --}}
    <nav
        aria-label="On this page"
        data-toc
        data-toc-reveal
        data-visible="false"
        class="about-toc"
    >
        <button
            type="button"
            class="about-toc__toggle focus-ring"
            data-toc-toggle
            aria-expanded="false"
            aria-controls="about-toc-list"
        >
            <svg
                aria-hidden="true"
                fill="none"
                stroke="currentColor"
                stroke-width="1.75"
                stroke-linecap="round"
                stroke-linejoin="round"
                viewBox="0 0 24 24"
                class="about-toc__toggle-icon size-4"
            >
                <path d="M14.5 5.5L8 12l6.5 6.5" />
            </svg>

            <span class="sr-only">Toggle section navigation</span>
        </button>

        <ul id="about-toc-list" class="about-toc__list space-y-1">
            @foreach ([
                ['id' => 'about-intro', 'label' => 'Intro', 'type' => 'hero', 'icon' => 'M3 11.5l9-8 9 8M5.5 10v9.5h13V10'],
                ['id' => 'about-why', 'label' => 'Why me', 'type' => 'reasons', 'icon' => 'M12 15a4.5 4.5 0 100-9 4.5 4.5 0 000 9zM8.5 13.5L7 21l5-2.5L17 21l-1.5-7.5'],
                ['id' => 'ai-workflow', 'label' => 'AI Workflow', 'type' => 'ai_workflow', 'icon' => 'M4 5.5h4v4H4zM16 5.5h4v4h-4zM4 14.5h4v4H4zM16 14.5h4v4h-4zM8 7.5h8M8 16.5h8M6 9.5v5M18 9.5v5'],
                ['id' => 'about-principles', 'label' => 'How I work', 'type' => 'principles', 'icon' => 'M9 18h6M10 21h4M12 3a6 6 0 00-3.5 10.9c.6.5 1 1.3 1 2.1h5c0-.8.4-1.6 1-2.1A6 6 0 0012 3z'],
                ['id' => 'about-capabilities', 'label' => 'Capabilities', 'type' => 'expertise', 'icon' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z'],
                ['id' => 'about-experience', 'label' => 'Experience', 'type' => 'experience', 'icon' => 'M3.5 8.5h17v11h-17zM9 8.5V6a2 2 0 012-2h2a2 2 0 012 2v2.5M3.5 13h17'],
                ['id' => 'about-work', 'label' => 'Work', 'type' => 'work', 'icon' => 'M3.75 17.5v-11a1.5 1.5 0 011.5-1.5h4l2 2.5h7a1.5 1.5 0 011.5 1.5v7.5a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5z'],
                ['id' => 'about-writing', 'label' => 'Writing', 'type' => null, 'icon' => 'M4.5 19.5l.8-3.3 10-10a2 2 0 012.9 2.9l-10 10-3.7.4zM13.5 6.5l2.9 2.9'],
                ['id' => 'about-contact', 'label' => 'Contact', 'type' => 'contact', 'icon' => 'M3.5 6.5h17v11h-17zM3.5 7.5l8.5 6 8.5-6'],
            ] as $item)
                <li>
                    <a
                        href="#{{ $item['id'] }}"
                        data-toc-target="{{ $item['id'] }}"
                        data-active="false"
                        class="about-toc-link"
                    >
                        <svg
                            aria-hidden="true"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            viewBox="0 0 24 24"
                            class="about-toc-link__icon size-4 shrink-0"
                        >
                            <path d="{{ $item['icon'] }}" />
                        </svg>

                        <span class="about-toc-link__label text-xs font-medium">
                            {{ $item['type'] === null ? $item['label'] : $this->sectionLabel($item['type'], $item['label']) }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- ================================================================== --
     | 01 — The short version (hero), modelled on mhabir.dev's opening
     |      panel: status pill, stacked display name, terminal line, bio,
     |      inline anchors, pill CTAs and a full-bleed low-poly portrait.
     |      Renders no section marker — the first numbered marker below
     |      opens the ribcage.
     --}}
    <section id="about-intro" class="relative scroll-mt-28 overflow-hidden border-b border-ink-200 dark:border-ink-800">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        {{-- The portraits are shot on a white studio field (≈45-47% of each
             source is min(R,G,B) > 240). This filter maps whiteness to alpha:
             the carrier runs alpha through `1 - min(R,G,B)`, then the alpha
             table saturates it — any pixel whose lowest channel is below
             ~0.62 stays fully opaque (skin, hair, clothing, shatter), and
             only the near-white field fades out between 0.62 and 0.9. That
             steep ramp is what stops the face going ghostly against the dark
             grid while keeping a clean, AA-grade edge instead of a halo. --}}
        <svg class="pointer-events-none absolute size-0" aria-hidden="true" focusable="false">
            <defs>
                <filter id="hero-portrait-knockout" color-interpolation-filters="sRGB">
                    <feColorMatrix type="matrix" values="1 0 0 0 0  1 0 0 0 0  1 0 0 0 0  0 0 0 1 0" result="rC" />
                    <feColorMatrix type="matrix" values="0 1 0 0 0  0 1 0 0 0  0 1 0 0 0  0 0 0 1 0" result="gC" />
                    <feColorMatrix type="matrix" values="0 0 1 0 0  0 0 1 0 0  0 0 1 0 0  0 0 0 1 0" result="bC" />
                    <feBlend in="rC" in2="gC" mode="darken" result="rg" />
                    <feBlend in="rg" in2="bC" mode="darken" result="mn" />
                    <feColorMatrix in="mn" type="matrix" values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  -0.34 -0.34 -0.34 0 1" result="carrier" />
                    <feComposite in="SourceGraphic" in2="carrier" operator="arithmetic" k1="1" k2="0" k3="0" k4="0" result="cut" />
                    <feComponentTransfer in="cut">
                        <feFuncA type="table" tableValues="0 0.08 0.24 0.41 0.57 0.74 0.93 1 1 1 1 1 1 1 1 1" />
                    </feComponentTransfer>
                </filter>
            </defs>
        </svg>

        <div class="relative w-full px-5 py-16 sm:px-8 lg:px-10 lg:py-24">
            <div class="grid gap-10 md:grid-cols-[0.9fr_1fr] md:items-center">
                {{-- Left: pill → stacked name → terminal line → bio → anchors → CTAs --}}
                <div class="space-y-7">
                    <span class="inline-flex items-center gap-2 rounded-full border border-signal-300/60 bg-signal-50 px-4 py-1.5 font-mono text-2xs font-medium uppercase tracking-widest text-signal-700 dark:border-signal-800/60 dark:bg-signal-950/40 dark:text-signal-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-signal-500 animate-pulse"></span>
                        {{ $this->profile()->role }} · {{ $this->profile()->location }}
                    </span>

                    {{-- The name draws itself in: the reference hand-traces its
                         letterforms; here Dancing Script is stroked like a
                         signature outline, then the fill fades in. The real
                         heading stays behind for the document outline. --}}
                    <h1 id="about-hero-heading" class="sr-only">{{ $this->profile()->name }} — {{ $this->profile()->role }}</h1>

                    <div class="py-1 text-ink-900 dark:text-ink-50" role="img" aria-label="{{ $this->profile()->name }}">
                        <svg class="hero-name-draw" viewBox="0 0 660 156" fill="none" aria-hidden="true" focusable="false">
                            <text x="6" y="116" class="hero-name-draw__text">{{ $this->profile()->name }}</text>
                        </svg>
                    </div>

                    {{-- Scramble-decode ticker, as on the reference: the whole
                         phrase appears as noise and resolves left to right,
                         one character per tick, holds, then the next phrase
                         scrambles in. app.js drives it; no-JS shows the first
                         phrase complete (the sr-only copy lists them for AT). --}}
                    <div class="mt-6 min-h-[2.2rem]">
                        <p class="font-mono text-sm text-ink-500 md:text-base dark:text-ink-400" aria-live="polite">
                            <span class="text-signal-600 select-none dark:text-signal-400" aria-hidden="true">&gt;&nbsp;</span>
                            <span class="sr-only">Shipping production Laravel with measurable impact, building APIs that don’t page you at 3am, solving one bug at a time, teaching Postgres, queues and caches to behave.</span>
                            <span
                                aria-hidden="true"
                                data-cycle="Shipping production Laravel with measurable impact,Building APIs that don&#39;t page you at 3am,Solving one bug at a time,Teaching Postgres, queues and caches to behave"
                                data-cycle-el
                            >Shipping production Laravel with measurable impact</span>
                            <span class="text-signal-600 animate-pulse select-none dark:text-signal-400" aria-hidden="true">_</span>
                        </p>
                    </div>

                    <p class="mt-5 max-w-[58ch] text-base leading-relaxed text-ink-600 md:text-lg dark:text-ink-300">
                        {{ $this->profile()->bio }}
                    </p>

                    <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm font-semibold">
                        @foreach ([['Selected work', '#work-heading'], ['Writing', '#recent-heading'], ['Get in touch', '#contact-heading']] as [$anchorLabel, $anchorHref])
                            <a href="{{ $anchorHref }}" class="text-signal-700 underline-offset-4 transition-colors hover:underline dark:text-signal-400">
                                {{ $anchorLabel }}
                            </a>
                        @endforeach
                    </div>

                    {{-- Pill CTAs, straight off the reference: the primary emits
                         two phase-offset expanding rings (pure CSS), and each
                         pill sits in a magnetic wrapper that leans toward the
                         pointer and springs back on leave (app.js). --}}
                    <div class="mt-9 flex flex-wrap gap-3">
                        <span class="inline-block will-change-transform" data-magnetic>
                            <a
                                href="#work-heading"
                                class="btn-push relative inline-flex cursor-pointer items-center gap-2 rounded-full bg-signal-600 px-7 py-3.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 dark:bg-signal-500"
                            >
                                <span class="pointer-events-none absolute inset-0" aria-hidden="true">
                                    <span class="hero-cta-ring"></span>
                                    <span class="hero-cta-ring hero-cta-ring--alt"></span>
                                </span>
                                View my work
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M12 5v14m7-7-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </a>
                        </span>
                        @if ($email = $this->profile()->link('email'))
                            <span class="inline-block will-change-transform" data-magnetic>
                                <a
                                    href="{{ $email['url'] }}?subject={{ rawurlencode('CV request — '.$this->profile()->name) }}"
                                    class="btn-push inline-flex items-center gap-2 rounded-full border border-ink-300 px-7 py-3.5 text-sm font-semibold text-ink-800 transition-colors hover:border-ink-900 dark:border-ink-700 dark:text-ink-100 dark:hover:border-ink-300"
                                >
                                    Download CV
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4m5-3 4 5 4-5M12 3v12" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </a>
                            </span>
                        @endif
                    </div>

                    {{-- Icon-only social row, as on the reference. The dead
                         Dribbble stub from the old row is gone. --}}
                    <div class="mt-8 flex items-center gap-1">
                        @foreach (['github', 'linkedin', 'x'] as $network)
                            @if ($link = $this->profile()->link($network))
                                <a
                                    href="{{ $link['url'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer me"
                                    aria-label="{{ $link['label'] }}"
                                    class="rounded-md p-2.5 text-ink-500 transition-colors hover:text-signal-700 dark:text-ink-400 dark:hover:text-signal-400"
                                >
                                    <x-app.ui.social-icon :network="$network" class="size-[18px]" />
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>

                {{-- Right: low-poly portrait, full-bleed in its column. A different
                     render per theme, so light and dark each keep their own
                     composition; the negative margins let it run off the top,
                     bottom and right edges of the hero the way the art does. --}}
                <div
                    class="hero-portrait self-stretch md:-my-16 md:-mr-8 lg:-my-24 lg:-mr-10"
                    aria-hidden="true"
                >
                    <div class="hero-portrait__glow"></div>

                    {{-- Both theme renders live in one positional stack; the
                        theme-scoped display rules on the images themselves do
                        the swapping. No color treatment anywhere: light keeps
                        the SVG knockout; dark ships the background already
                        removed in the asset (baked alpha WebP) so no filter
                        can ghost the bright parts of the face. --}}
                    <div class="hero-portrait__stack">
                        <img
                            class="hero-portrait__img hero-portrait__img--light"
                            src="{{ asset('images/hero-portrait-light.jpg') }}"
                            alt=""
                            width="1536"
                            height="1024"
                            loading="eager"
                            fetchpriority="high"
                            decoding="async"
                        />
                        <img
                            class="hero-portrait__img hero-portrait__img--dark"
                            src="{{ asset('images/darkmode.webp') }}"
                            alt=""
                            width="1502"
                            height="1047"
                            loading="eager"
                            decoding="async"
                        />
                    </div>

                    <span class="hero-portrait__orbit hero-portrait__orbit--a"></span>
                    <span class="hero-portrait__orbit hero-portrait__orbit--b"></span>
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 01b — Expertise ticker + stat band, the two panels that open
     |      mhabir.dev's page right after its hero: the capability marquee
     |      scrolling the six expertise domains, then the four-column stat
     |      band fed from config. Un-numbered — they read as part of the
     |      opening panel, so the ribcage's next marker is still 02.
     --}}
    <x-app.ui.expertise-marquee :items="$this->profile()->topExpertise()" />

    <x-app.ui.stat-band :stats="$this->profile()->stats" />

    {{-- ================================================================== --
     | 02 — Thinking Explorer: the interactive rework of the pitch band.
     |      The procedural neural brain is the focal point; the value claims
     |      render as the rank-ordered rail beneath it, and every mode,
     |      category and node is operator-managed through the reasons
     |      section's rows. The wrapper owns the band's closing hairline.
     --}}
    <div id="about-why" class="scroll-mt-28 border-b border-ink-200 dark:border-ink-800">
        <x-app.ui.thinking-explorer
            :number="2"
            :explorer="$this->thinkingExplorer()"
            :reasons="$this->reasons()"
            :ctaHref="($email = $this->profile()->link('email')) ? $email['url'] : null"
        />
    </div>

    {{-- ================================================================== --
     | 03 — AI Workflow: the autonomous-delivery dashboard from
     |      docs/AI-WORKFLOW.md. The section header uses the same idiom as
     |      every other band here — ghost ordinal, heading, squiggle rule,
     |      lead, all reveal-driven — on the shared canvas; the dashboard
     |      itself is the one deliberately dark instrument panel on the
     |      page, rendered as a contained panel below the header. Rows come
     |      from $this->workflow(), which overlays database rows on the
     |      built-in demo; interactivity (automation, add/advance tasks,
     |      activity log) is Alpine and the section stays readable without
     |      JavaScript.
     --}}
    @php $workflow = $this->workflow(); @endphp
    <section id="ai-workflow" class="relative isolate overflow-x-clip scroll-mt-28 py-20 md:py-28" aria-labelledby="ai-workflow-heading">
        {{-- Ambient behind the glass panel: two soft stage-colour pools, low
             enough to be weather rather than decoration, giving the panel's
             backdrop blur something to actually refract in both themes. --}}
        <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-1/3 -z-10 h-[26rem] opacity-35 blur-3xl dark:opacity-70" style="background:radial-gradient(36rem 17rem at 16% 40%, color-mix(in oklab, var(--color-aw-accent, #10b981) 14%, transparent), transparent 70%),radial-gradient(32rem 15rem at 84% 62%, color-mix(in oklab, var(--color-aw-verified, #4d7c0f) 9%, transparent), transparent 70%);"></div>

        <div class="w-full px-5 sm:px-8 lg:px-10">
            <div class="mb-12 md:mb-16" data-reveal>
                <div class="flex flex-wrap items-center gap-4 md:gap-6">
                    <span class="sec-ordinal relative inline-block shrink-0 select-none">
                        <span class="exp-ordinal-stroke block text-5xl font-extrabold leading-none md:text-6xl">03</span>
                        <span aria-hidden="true" class="absolute inset-0 block text-5xl font-extrabold leading-none text-signal-600 md:text-6xl dark:text-signal-400">03</span>
                    </span>

                    <div class="inline-block">
                        <h2 id="ai-workflow-heading" class="sec-heading text-4xl font-bold leading-none tracking-[-0.025em] text-ink-900 md:text-5xl dark:text-ink-50">
                            {{ $workflow['heading'] }}
                        </h2>

                        <svg aria-hidden="true" class="sec-rule mt-4 block h-[10px] w-[190px] overflow-visible text-signal-600 md:w-[230px] dark:text-signal-400" viewBox="0 0 230 10" fill="none">
                            <path d="M3 7C55 2.5 120 9.5 165 5.5S215 4 227 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" pathLength="100" />
                        </svg>
                    </div>
                </div>

                <p class="sec-lead mt-5 max-w-2xl text-base leading-relaxed text-ink-500 md:text-lg dark:text-ink-400">
                    {{ $workflow['lead'] }}
                </p>
            </div>

            <x-app.ui.ai-workflow
                :eyebrow="$workflow['eyebrow']"
                :cta="$workflow['cta']"
                :tasks="$workflow['tasks']"
                :agents="$workflow['agents']"
                :metrics="$workflow['metrics']"
            />
        </div>
    </section>

    {{-- ================================================================== --
     | 04 — How I work : framed portrait on the left, the about-me details
     |      (at-a-glance facts) and the operating principles on the right.
     |      The left panel echoes the hero — same two theme renders, same
     |      knockout mechanism — but framed as a bordered card instead of
     |      bleeding off the page.
     --}}
    <section id="about-principles" class="relative scroll-mt-28 overflow-hidden py-20 sm:py-24" aria-labelledby="principles-heading">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

        <div class="shell relative">
            <x-app.ui.section-marker :number="4" :label="$this->sectionLabel('principles', 'How I work')" />

            <div class="mt-10 grid items-stretch gap-12 lg:grid-cols-[minmax(0,6fr)_minmax(0,7fr)] lg:gap-16">
                {{-- Left: the portrait, framed. Own natural height — an aspect-locked
                     frame (4/5 beside the details, 16/10 when stacked below lg)
                     instead of stretching to the full column: the landscape
                     render can feed a portrait-ratio window, not a tower.
                     Centered in the grid row — the right column runs much taller,
                     and a top-anchored frame leaves a dead gap under the art.
                     Hidden on phones (below md) where the stacked frame pushed
                     the content too far down --}}
                <div data-reveal class="about-how relative hidden self-center md:block">
                    <div class="about-how__glow"></div>

                    <div class="relative min-h-96 overflow-hidden rounded-2xl border border-ink-200 bg-white/60 shadow-soft-2 dark:border-ink-800 dark:bg-ink-950/60 sm:min-h-[26rem] md:aspect-[16/10] md:min-h-0 lg:aspect-[4/5]">
                        <div aria-hidden="true" class="pointer-events-none absolute inset-0 grid-field"></div>

                        {{-- Both theme renders live in one positional stack, same
                            mechanism as the hero: CSS on <html class="dark">
                            decides which one shows. Light reuses the shared
                            `#hero-portrait-knockout` filter declared in the
                            hero section; dark ships the baked-alpha WebP and
                            takes no filter at all. --}}
                        <div class="absolute inset-0">
                            <img
                                class="about-how__img about-how__img--light"
                                src="{{ asset('images/hero-portrait-light.jpg') }}"
                                alt=""
                                width="1536"
                                height="1024"
                                loading="lazy"
                                decoding="async"
                            />
                            <img
                                class="about-how__img about-how__img--dark"
                                src="{{ asset('images/darkmode.webp') }}"
                                alt=""
                                width="1502"
                                height="1047"
                                loading="lazy"
                                decoding="async"
                            />
                        </div>

                        <span class="hero-portrait__orbit hero-portrait__orbit--a"></span>
                        <span class="hero-portrait__orbit hero-portrait__orbit--b"></span>
                    </div>

                    {{-- Floating stat chips straddling the frame's bottom edge. --}}
                    <div class="absolute -bottom-5 left-5 flex flex-wrap gap-3">
                        <span class="inline-flex items-center gap-2 rounded-full border border-ink-200 bg-white px-4 py-2 text-xs font-medium shadow-lift dark:border-ink-700 dark:bg-ink-900">
                            <span class="h-1.5 w-1.5 rounded-full bg-signal-500 animate-pulse"></span>
                            <span class="tabular-nums text-ink-900 dark:text-ink-50">150+</span>
                            <span class="text-ink-500 dark:text-ink-400">projects shipped</span>
                        </span>
                        <span class="inline-flex items-center gap-2 rounded-full border border-ink-200 bg-white px-4 py-2 text-xs font-medium shadow-lift dark:border-ink-700 dark:bg-ink-900">
                            <span class="h-1.5 w-1.5 rounded-full bg-signal-500 animate-pulse"></span>
                            <span class="tabular-nums text-ink-900 dark:text-ink-50">8+</span>
                            <span class="text-ink-500 dark:text-ink-400">years online</span>
                        </span>
                    </div>
                </div>

                {{-- Right: heading, about-me lead, at-a-glance facts, principles. --}}
                <div data-reveal>
                    <h2 id="principles-heading" class="max-w-2xl text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                        {{ $this->sectionHeading('principles', 'The things I keep coming back to') }}
                    </h2>

                    <p class="mt-4 max-w-2xl text-base leading-relaxed text-ink-500 dark:text-ink-400">
                        I am a {{ strtolower($this->profile()->role) }}. {{ $this->sectionLead('principles', 'These are the six defaults learned the expensive way — when a decision is hard, they are what break the tie.') }}
                    </p>

                    <dl class="mt-7 grid gap-x-8 gap-y-4 rounded-xl border border-ink-200 bg-white/50 p-5 sm:grid-cols-2 dark:border-ink-800 dark:bg-ink-950/50">
                        @foreach ($this->focus() as $fact)
                            <div class="flex flex-col gap-0.5">
                                <dt class="font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                                    {{ $fact['label'] }}
                                </dt>
                                <dd class="text-sm font-medium text-ink-800 dark:text-ink-200">
                                    {{ $fact['value'] }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    <ol class="mt-8 grid gap-px overflow-hidden rounded-lg border border-ink-200 bg-ink-200 sm:grid-cols-2 dark:border-ink-800 dark:bg-ink-800">
                        @foreach ($this->principles() as $index => $principle)
                            <li
                                data-reveal
                                style="transition-delay: {{ min($index * 60, 240) }}ms"
                                class="group bg-white p-6 transition-colors hover:bg-ink-50/80 dark:bg-ink-950 dark:hover:bg-ink-900/60"
                            >
                                <span class="font-mono text-2xs tabular-nums text-signal-600 dark:text-signal-400">
                                    {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                                </span>
                                <h3 class="mt-3 text-base font-semibold tracking-tight text-ink-900 transition-colors group-hover:text-signal-700 dark:text-ink-50 dark:group-hover:text-signal-400">
                                    {{ $principle['title'] }}
                                </h3>
                                <p class="mt-2.5 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                    {{ $principle['body'] }}
                                </p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 05 — Capabilities : the reference #skills panel — doubled stroke
     |      ordinal over a wavy rule, then the capability tiles in a
     |      four-column grid above the production stack map: a generated
     |      SVG conductor diagram (root mid-left, two columns of rich
     |      nodes repeating the cards' copy) on md+ and the same
     |      relationships as a rail on mobile, both generated from the
     |      expertise config so map and page can never drift.
     |
     |      This section is the page's one full-bleed panel: no `shell` —
     |      content runs the full viewport width on the hero's edge padding
     |      (px-5/sm:px-8/lg:px-10). The map panel caps and centres itself
     |      so the `w-full` SVG cannot balloon on wide monitors.
     --}}
    <section id="about-capabilities" class="scroll-mt-28 border-b border-ink-200 py-20 dark:border-ink-800 md:py-28" aria-labelledby="expertise-heading">
        <div class="w-full px-5 sm:px-8 lg:px-10">
            <div class="mb-12 md:mb-16" data-reveal>
                <div class="flex flex-wrap items-center gap-4 md:gap-6">
                    <span class="sec-ordinal relative inline-block shrink-0 select-none">
                        <span class="exp-ordinal-stroke block text-5xl font-extrabold leading-none md:text-6xl">05</span>
                        <span aria-hidden="true" class="absolute inset-0 block text-5xl font-extrabold leading-none text-signal-600 md:text-6xl dark:text-signal-400">05</span>
                    </span>

                    <div class="inline-block">
                        <h2 id="expertise-heading" class="sec-heading text-4xl font-bold leading-none tracking-[-0.025em] text-ink-900 md:text-5xl dark:text-ink-50">
                            {{ $this->sectionHeading('expertise', 'Capabilities') }}
                        </h2>

                        <svg aria-hidden="true" class="sec-rule mt-4 block h-[10px] w-[190px] overflow-visible text-signal-600 md:w-[230px] dark:text-signal-400" viewBox="0 0 230 10" fill="none">
                            <path d="M3 7C55 2.5 120 9.5 165 5.5S215 4 227 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" pathLength="100" />
                        </svg>
                    </div>
                </div>

                <p class="sec-lead mt-5 max-w-2xl text-base leading-relaxed text-ink-500 md:text-lg dark:text-ink-400">
                    Where the years went — production depth across the Laravel platform and the tooling that surrounds it.
                </p>
            </div>

            {{-- Capability stage: a clickable card per domain in a four-
                column grid, the generated mind map running full-width
                below it. Clicking a card lights the matching branch;
                the 3D tilt, spotlight and circuit draw are carried by
                about-effects.css + initSkillsMap(). --}}
            <div class="skills-stage mt-12 grid gap-8 lg:grid-cols-[minmax(0,28rem)_minmax(0,1fr)] lg:items-center" data-skills-stage>
                <ul class="cap-scroll grid list-none gap-4 overscroll-contain p-0 md:grid-cols-2 lg:grid-cols-1 lg:max-h-[61rem] lg:overflow-y-auto lg:pe-3" data-reveal>
                    @foreach ($this->profile()->expertise as $index => $item)
                        <li>
                            <button
                                type="button"
                                data-domain="{{ $index }}"
                                aria-pressed="false"
                                class="cap-card group relative block h-full w-full cursor-pointer overflow-hidden rounded-xl border border-ink-200/80 bg-white/70 p-6 text-left transition-[border-color,background-color,box-shadow] duration-300 hover:border-signal-400/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-signal-500 dark:border-ink-800 dark:bg-ink-950/60 dark:hover:border-signal-500/70"
                            >
                                <span aria-hidden="true" class="cap-card__spot"></span>

                                <span class="relative grid h-full grid-cols-[5rem_minmax(0,1fr)] items-start gap-x-4 gap-y-3 lg:grid-cols-[auto_minmax(0,1fr)] lg:grid-rows-[auto_auto_1fr] lg:items-stretch lg:gap-x-4 lg:gap-y-0">
                                    <span aria-hidden="true" class="cap-card__tile lg:row-span-3">
                                        <flux:icon
                                            :name="$item['icon']"
                                            class="cap-card__icon"
                                            variant="solid"
                                        />
                                    </span>

                                    {{-- Mobile: the head holds only the logo — the title drops
                                         to its own full-width row beneath the tile, per the
                                         reference's stacked figure. Desktop keeps title +
                                         number beside the tile via lg:col-start-2. --}}
                                    <span class="col-span-full min-w-0 pt-0.5 lg:col-start-2 lg:self-start">
                                        <span class="flex items-baseline justify-between gap-x-3 min-w-0">
                                            <span class="min-w-0 text-lg font-bold leading-snug tracking-tight text-ink-900 sm:truncate dark:text-ink-50">{{ $item['title'] }}</span>
                                            <span class="font-mono text-2xs font-semibold tracking-widest text-signal-700 dark:text-signal-400">0{{ $index + 1 }}</span>
                                        </span>
                                    </span>

                                    <span class="col-span-full text-sm leading-relaxed text-ink-500 lg:col-start-2 lg:mt-2 dark:text-ink-400">{{ $item['description'] }}</span>

                                        <span class="col-span-full flex flex-wrap items-center justify-between gap-3 lg:col-start-2 lg:self-end lg:pt-4">
                                            <span class="flex flex-wrap gap-1.5">
                                                @foreach ($item['tags'] as $tag)
                                                    <span class="rounded border border-ink-200 bg-ink-100/70 px-2 py-1 font-mono text-[11px] uppercase tracking-wide text-ink-500 dark:border-ink-700/80 dark:bg-ink-900/70 dark:text-ink-400">{{ $tag }}</span>
                                                @endforeach
                                            </span>

                                            <span class="cap-card__trace whitespace-nowrap font-mono text-2xs tracking-widest">trace branch →</span>
                                        </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                {{-- Mind map: the platform root wired to each domain, nodes
                    flowing in two columns to its right, each carrying the
                    full tag copy from the cards. Geometry is precomputed
                    in stackMap(); pulse + wire share wireD. Capped and
                    centred, horizontally scrollable below ~920px like
                    the reference's overflow panel. --}}
                <div class="relative hidden lg:block">
                    <p class="mb-4 flex items-center gap-3 font-mono text-2xs uppercase tracking-widest text-ink-400 dark:text-ink-500">
                        <span class="h-px w-8 bg-ink-300 dark:bg-ink-700"></span>
                        Click a capability to trace its branch
                    </p>

                    <div class="map-scroll overflow-x-auto">
                        <svg viewBox="0 0 1120 {{ $this->stackHeight() }}" style="min-width: 920px" class="skills-map w-full" fill="none" role="img" aria-label="Mind map: the Laravel platform root connected to each capability domain">
                            <g class="skills-root">
                                <rect x="40" y="228" width="232" height="96" rx="16" class="skills-root__frame" stroke-width="2" />
                                <circle cx="252" cy="248" r="4" class="skills-root__dot" />
                                <text x="64" y="272" class="skills-root__title">Laravel platform</text>
                                <text x="64" y="298" class="skills-root__meta">{{ count($this->profile()->expertise) }} domains · production</text>
                            </g>

                            @foreach ($this->stackMap() as $block)
                                <path
                                    class="skills-wire"
                                    data-domain="{{ $loop->index }}"
                                    d="{{ $block['wireD'] }}"
                                    pathLength="100"
                                    style="--wire-delay: {{ $loop->index * 0.12 }}s"
                                />

                                <g class="skills-node" data-domain="{{ $loop->index }}" style="--node-delay: {{ 0.25 + $loop->index * 0.12 }}s">
                                    <rect x="{{ $block['x'] }}" y="{{ $block['y'] }}" width="{{ $block['width'] }}" height="{{ $block['height'] }}" rx="12" class="skills-node__frame" stroke-width="1.5" />
                                    <rect x="{{ $block['x'] }}" y="{{ $block['y'] }}" width="{{ $block['width'] }}" height="44" rx="12" class="skills-node__strip" />
                                    <rect x="{{ $block['x'] }}" y="{{ $block['y'] + 22 }}" width="{{ $block['width'] }}" height="22" class="skills-node__strip" />
                                    <text x="{{ $block['x'] + 18 }}" y="{{ $block['y'] + 29 }}" class="skills-node__title">{{ $block['title'] }}</text>
                                    @foreach ($block['tags'] as $tagIndex => $tag)
                                        <text x="{{ $block['x'] + 18 }}" y="{{ $block['y'] + 62 + $tagIndex * 21 }}" class="skills-node__tag">{{ $tag }}</text>
                                    @endforeach
                                </g>

                                <circle class="skills-node__tap" cx="{{ $block['tapX'] }}" cy="{{ $block['wireY'] }}" r="3.5" style="--node-delay: {{ 0.25 + $loop->index * 0.12 }}s" />

                                <circle class="skills-pulse" r="2.5" style="--node-delay: {{ 0.25 + $loop->index * 0.12 }}s">
                                    <animateMotion dur="5s" begin="-{{ $loop->index * 0.8 }}s" repeatCount="indefinite" path="{{ $block['wireD'] }}" />
                                </circle>
                            @endforeach
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 06 — Experience : the career timeline, modelled on mhabir.dev's
     |      #experience panel — a giant stroke-outline ordinal with its
     |      accent-fill overlay and a wavy rule, "Experience" in display
     |      type with "My professional journey" as the muted lead, then
     |      hairline-topped employer blocks: ghost stroked watermark,
     |      COMPANY eyebrow + display name row with an accent date pill,
     |      a left rail (line, terminal dot, ring taps) carrying each
     |      role's highlights with dash bullets and accent stack chips.
     |      Data comes from config('portfolio.author.experience') so the
     |      history is edited in one place.
     --}}
    <section id="about-experience" class="scroll-mt-28 border-b border-ink-200 py-20 dark:border-ink-800" aria-labelledby="experience-heading">
        <div class="shell">
            {{-- Reference header: outlined+filled giant ordinal, wavy rule
                under the display heading, and a muted lead paragraph. --}}
            <div class="mb-12 md:mb-16" data-reveal>
                <div class="flex flex-wrap items-center gap-4 md:gap-6">
                    <span class="sec-ordinal relative inline-block shrink-0 select-none">
                        <span class="exp-ordinal-stroke block text-5xl font-extrabold leading-none md:text-6xl">06</span>
                        <span aria-hidden="true" class="absolute inset-0 block text-5xl font-extrabold leading-none text-signal-600 md:text-6xl dark:text-signal-400">06</span>
                    </span>

                    <div class="inline-block">
                        <h2 id="experience-heading" class="sec-heading text-4xl font-extrabold leading-none tracking-tight text-ink-900 md:text-5xl dark:text-ink-50">
                            {{ $this->sectionHeading('experience', 'Experience') }}
                        </h2>

                        <svg aria-hidden="true" class="sec-rule mt-4 block h-[10px] w-[190px] overflow-visible text-signal-600 md:w-[230px] dark:text-signal-400" viewBox="0 0 230 10" fill="none">
                            <path
                                d="M2 6.5C24 2.5 40 8.5 62 6c22-2.5 42-2.5 64-0 22 2.5 42 2.5 64-0.5 22-3 26-3 38-0.5"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                pathLength="100"
                                opacity="0.65"
                            />
                        </svg>
                    </div>
                </div>

                <p class="sec-lead mt-4 max-w-2xl text-base leading-relaxed text-ink-500 md:text-lg dark:text-ink-400">
                    My professional journey
                </p>
            </div>

            <div class="mt-12">
                @foreach ($this->profile()->experience as $employer)
                    <article
                        data-reveal
                        style="transition-delay: {{ min($loop->index * 90, 180) }}ms"
                        class="relative overflow-hidden border-t border-ink-200 py-10 dark:border-ink-800 md:py-12"
                    >
                        {{-- Ghost watermark, as on the reference: the company name
                            repeated out to the edges in huge type — outline-only
                            foreground strokes at 5% opacity. --}}
                        <span
                            aria-hidden="true"
                            class="exp-ghost-stroke pointer-events-none absolute -top-1 left-0 select-none whitespace-nowrap font-extrabold uppercase leading-none text-[13vw] md:text-[7vw]"
                        >
                            {{ $employer['company'] }} · {{ $employer['company'] }} · {{ $employer['company'] }}
                        </span>

                        {{-- Company row — label + display name against the date pill. --}}
                        <div class="relative mb-9 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="mb-1.5 font-mono text-sm uppercase tracking-wide text-ink-400 dark:text-ink-500">
                                    Company
                                </p>
                                <h3 class="text-3xl font-extrabold leading-none tracking-tight text-signal-700 dark:text-signal-400 md:text-[2.6rem]">
                                    {{ $employer['company'] }}
                                </h3>
                            </div>

                            <span class="inline-flex items-center rounded-full border border-signal-700/50 bg-signal-500/10 px-4 py-2 font-mono text-sm uppercase tracking-wide text-signal-700 dark:border-signal-400/50 dark:text-signal-400">
                                {{ $employer['period'] }}
                            </span>
                        </div>

                        {{-- Role rail — vertical line with a terminal dot, and a
                            ring tap for each role, exactly on the reference's grid. --}}
                        <div class="relative pl-6 md:pl-8">
                            <span aria-hidden="true" class="absolute bottom-2 left-[5px] top-2 w-px bg-ink-200 dark:bg-ink-800"></span>
                            <span aria-hidden="true" class="absolute left-[2px] top-[4px] size-[7px] rounded-full bg-signal-500"></span>

                            @foreach ($employer['roles'] as $role)
                                <div class="relative">
                                    <span
                                        aria-hidden="true"
                                        class="absolute -left-6 top-[7px] size-[11px] rounded-full border-2 border-signal-500 bg-white md:-left-8 dark:bg-ink-950"
                                    ></span>

                                    <div class="flex flex-wrap items-baseline gap-x-4">
                                        <h4 class="text-xl font-bold tracking-tight text-ink-900 md:text-2xl dark:text-ink-50">
                                            {{ $role['title'] }}
                                        </h4>
                                        <span class="font-mono text-sm tracking-wide text-ink-500 dark:text-ink-400">
                                            {{ $role['period'] }}
                                        </span>
                                    </div>

                                    <ul class="mt-4 max-w-[82ch] space-y-2">
                                        @foreach ($role['highlights'] as $highlight)
                                            <li class="relative pl-5 text-sm leading-relaxed text-ink-700 before:absolute before:left-0 before:top-[0.62em] before:h-px before:w-2.5 before:bg-signal-600 md:text-[15px] dark:text-ink-300 dark:before:bg-signal-400">
                                                {{ $highlight['text'] }}
                                            </li>
                                            <li class="flex flex-wrap gap-2 pt-1">
                                                @foreach ($highlight['stack'] as $tech)
                                                    <span class="inline-block rounded-md border border-signal-700/30 bg-signal-500/8 px-2.5 py-1 font-mono text-xs text-signal-700 transition-colors hover:border-signal-700/60 hover:bg-signal-500/14 md:text-[15px] dark:border-signal-400/30 dark:text-signal-400 dark:hover:border-signal-400/60">{{ $tech }}</span>
                                                @endforeach
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 07 — Selected work : the reference #projects panel — shared header
     |      idiom, then the asymmetric bento (7+5, then 6+6) of bordered
     |      cards: mono P·NN marker, outlined View pill, summary, stack
     |      chips, ghost product watermark. Each outline draws itself in
     |      on reveal (staggered --frame-delay).
     --}}
    <section id="about-work" class="scroll-mt-28 border-b border-ink-200 py-20 dark:border-ink-800 md:py-28" aria-labelledby="work-heading">
        <div class="shell">
            <div class="mb-12 md:mb-16" data-reveal>
                <div class="flex flex-wrap items-center gap-4 md:gap-6">
                    <span class="sec-ordinal relative inline-block shrink-0 select-none">
                        <span class="exp-ordinal-stroke block text-5xl font-extrabold leading-none md:text-6xl">07</span>
                        <span aria-hidden="true" class="absolute inset-0 block text-5xl font-extrabold leading-none text-signal-600 md:text-6xl dark:text-signal-400">07</span>
                    </span>

                    <div class="inline-block">
                        <h2 id="work-heading" class="sec-heading scroll-mt-28 text-4xl font-bold leading-none tracking-[-0.025em] text-ink-900 md:text-5xl dark:text-ink-50">
                            {{ $this->sectionHeading('work', 'Selected work') }}
                        </h2>

                        <svg aria-hidden="true" class="sec-rule mt-4 block h-[10px] w-[190px] overflow-visible text-signal-600 md:w-[230px] dark:text-signal-400" viewBox="0 0 230 10" fill="none">
                            <path d="M3 7C55 2.5 120 9.5 165 5.5S215 4 227 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" pathLength="100" />
                        </svg>
                    </div>
                </div>

                <p class="sec-lead mt-5 max-w-2xl text-base leading-relaxed text-ink-500 md:text-lg dark:text-ink-400">
                    Some things I've built — each with the decisions and the documentation behind it.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-12 lg:gap-5">
                @foreach ($this->projects() as $index => $project)
                    @php $span = ['lg:col-span-7', 'lg:col-span-5', 'lg:col-span-6', 'lg:col-span-6', 'lg:col-span-12'][$index] ?? 'lg:col-span-6'; @endphp
                    <article
                        data-reveal
                        style="--frame-delay: {{ $index * 160 }}ms"
                        class="group relative flex flex-col overflow-hidden rounded-xl border border-ink-200 bg-white p-6 transition-[transform,box-shadow] duration-300 hover:-translate-y-1 hover:shadow-lift lg:p-7 dark:border-ink-800 dark:bg-ink-950 {{ $span }}"
                    >
                        <svg class="draw-frame pointer-events-none absolute inset-0 size-full" aria-hidden="true">
                            <rect x="1" y="1" rx="10" width="calc(100% - 2px)" height="calc(100% - 2px)" pathLength="100" />
                        </svg>
                        <span aria-hidden="true" class="pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-500 group-hover:opacity-100" style="background: radial-gradient(90% 70% at 50% 108%, color-mix(in srgb, var(--color-signal-500) 12%, transparent), transparent 70%);"></span>

                        <div class="relative flex flex-wrap items-start justify-between gap-3">
                            <p class="pt-1 font-mono text-2xs uppercase tracking-widest text-signal-700 dark:text-signal-400">
                                {{ $project->marker() }} — {{ $project->role }}
                            </p>

                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <a
                                    href="{{ $project->href() }}"
                                    class="focus-ring inline-flex shrink-0 items-center gap-1.5 rounded-full bg-ink-900 px-3.5 py-1.5 font-mono text-2xs uppercase tracking-wide text-white transition-colors hover:bg-signal-600 dark:bg-ink-50 dark:text-ink-950 dark:hover:bg-signal-400"
                                >
                                    {{ __('Details') }}
                                    <svg class="size-3 transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14m-7-7 7 7-7 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                </a>

                                @if ($project->url !== null)
                                    <a
                                        href="{{ $project->url }}"
                                        target="_blank"
                                        rel="noopener noreferrer nofollow"
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-ink-300 px-3.5 py-1.5 font-mono text-2xs uppercase tracking-wide text-ink-700 transition-colors hover:border-signal-500 hover:text-signal-700 dark:border-ink-700 dark:text-ink-200 dark:hover:border-signal-400 dark:hover:text-signal-400"
                                    >
                                        View
                                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    </a>
                                @endif
                            </div>
                        </div>

                        <h3 class="relative mt-4 text-xl font-bold tracking-tight text-ink-900 transition-colors group-hover:text-signal-700 md:text-2xl dark:text-ink-50 dark:group-hover:text-signal-400">
                            {{ $project->name }}
                        </h3>

                        <p class="relative mt-3 max-w-[62ch] text-sm leading-relaxed text-ink-500 md:text-[15px] dark:text-ink-400">
                            {{ $project->summary }}
                        </p>

                        <div class="relative mt-5 flex flex-wrap gap-2">
                            @foreach ($project->stack as $tech)
                                <span class="rounded-md border border-ink-200 bg-ink-100/70 px-2.5 py-1 font-mono text-xs text-ink-600 dark:border-ink-700/80 dark:bg-ink-900/70 dark:text-ink-400">{{ $tech }}</span>
                            @endforeach
                        </div>

                        <p class="relative mt-5 text-sm leading-relaxed text-ink-700 md:text-[15px] dark:text-ink-300">
                            {{ $project->outcome }}
                        </p>

                        {{-- Ghost watermark, clipped by the card the way the
                            reference runs its product name off the edge. --}}
                        <span
                            aria-hidden="true"
                            class="exp-ghost-stroke pointer-events-none absolute -bottom-2 right-0 select-none whitespace-nowrap text-6xl font-black uppercase leading-none"
                        >{{ $project->name }}</span>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | 08 — Recent writing : the reference #blog panel — shared header
     |      idiom plus post counter (02/06) and prev/next stepper on the
     |      right, then a scroll-snap carousel of post cards that
     |      app.js paginates and re-counts as it moves. Hidden entirely
     |      when there is nothing published.
     --}}
    @if ($this->recentPosts->isNotEmpty())
        <section id="about-writing" class="scroll-mt-28 py-20 md:py-28" aria-labelledby="recent-heading">
            <div class="shell">
                <div class="mb-12 flex flex-wrap items-start justify-between gap-8 md:mb-16" data-reveal>
                    <div>
                        <div class="flex flex-wrap items-center gap-4 md:gap-6">
                            <span class="sec-ordinal relative inline-block shrink-0 select-none">
                                <span class="exp-ordinal-stroke block text-5xl font-extrabold leading-none md:text-6xl">08</span>
                                <span aria-hidden="true" class="absolute inset-0 block text-5xl font-extrabold leading-none text-signal-600 md:text-6xl dark:text-signal-400">08</span>
                            </span>

                            <div class="inline-block">
                                <h2 id="recent-heading" class="sec-heading text-4xl font-bold leading-none tracking-[-0.025em] text-ink-900 md:text-5xl dark:text-ink-50">
                                    Recent writing
                                </h2>

                                <svg aria-hidden="true" class="sec-rule mt-4 block h-[10px] w-[190px] overflow-visible text-signal-600 md:w-[230px] dark:text-signal-400" viewBox="0 0 230 10" fill="none">
                                    <path d="M3 7C55 2.5 120 9.5 165 5.5S215 4 227 5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" pathLength="100" />
                                </svg>
                            </div>
                        </div>

                        <p class="sec-lead mt-5 max-w-2xl text-base leading-relaxed text-ink-500 md:text-lg dark:text-ink-400">
                            Proof the thinking happens in public
                        </p>
                    </div>

                    <div class="sec-lead flex items-center gap-3 pt-2">
                        <p class="font-mono text-sm tabular-nums text-ink-500 dark:text-ink-400">
                            <span data-carousel-counter>01</span>
                            <span aria-hidden="true" class="text-ink-300 dark:text-ink-700">/</span>
                            <span>{{ sprintf('%02d', $this->recentPosts->count()) }}</span>
                        </p>

                        <div class="flex gap-2">
                            <button
                                type="button"
                                data-carousel-prev
                                aria-label="Previous post"
                                class="grid size-11 place-items-center rounded-full border border-ink-200 bg-white text-ink-600 transition-colors hover:border-signal-500 hover:text-signal-700 disabled:pointer-events-none disabled:opacity-35 md:size-12 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300 dark:hover:border-signal-400 dark:hover:text-signal-400"
                            >
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </button>
                            <button
                                type="button"
                                data-carousel-next
                                aria-label="Next post"
                                class="grid size-11 place-items-center rounded-full border border-ink-200 bg-white text-ink-600 transition-colors hover:border-signal-500 hover:text-signal-700 disabled:pointer-events-none disabled:opacity-35 md:size-12 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-300 dark:hover:border-signal-400 dark:hover:text-signal-400"
                            >
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14m-7-7 7 7-7 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    data-carousel-viewport
                    class="writing-scroll -mx-5 flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth px-5 pb-4 sm:-mx-8 sm:px-8 lg:-mx-10 lg:px-10"
                >
                    @foreach ($this->recentPosts as $index => $post)
                        <article
                            data-reveal
                            style="--frame-delay: {{ min($index * 120, 480) }}ms; transition-delay: {{ min($index * 60, 240) }}ms"
                            class="group relative flex w-[84%] shrink-0 snap-start flex-col overflow-hidden rounded-xl border border-ink-200 bg-white p-6 transition-[transform,box-shadow] duration-300 hover:-translate-y-1 hover:shadow-lift sm:w-[47%] lg:w-[31.6%] dark:border-ink-800 dark:bg-ink-950"
                        >
                            <svg class="draw-frame pointer-events-none absolute inset-0 size-full" aria-hidden="true">
                                <rect x="1" y="1" rx="10" width="calc(100% - 2px)" height="calc(100% - 2px)" pathLength="100" />
                            </svg>

                            <div class="relative flex items-center justify-between gap-3">
                                @if ($post->category)
                                    <span class="rounded-full border border-signal-700/40 bg-signal-500/10 px-3 py-1 font-mono text-2xs uppercase tracking-wide text-signal-700 dark:border-signal-400/40 dark:text-signal-400">
                                        {{ $post->category->name }}
                                    </span>
                                @endif
                                <time datetime="{{ $post->published_at->toDateString() }}" class="ml-auto flex items-center gap-1.5 font-mono text-2xs text-ink-400 dark:text-ink-500">
                                    <flux:icon icon="calendar" class="size-3" />
                                    {{ $post->published_at->isoFormat('ll') }}
                                </time>
                            </div>

                            <h3 class="relative mt-4 text-lg font-bold leading-snug tracking-tight text-ink-900 transition-colors group-hover:text-signal-700 dark:text-ink-50 dark:group-hover:text-signal-400">
                                {{ $post->title }}
                            </h3>

                            <p class="relative mt-2.5 line-clamp-3 text-sm leading-relaxed text-ink-500 dark:text-ink-400">
                                {{ $post->excerpt }}
                            </p>

                            <ul class="relative mt-4 flex flex-wrap gap-1.5">
                                @foreach ($post->tags as $tag)
                                    <li class="rounded-xs border border-ink-200 px-2 py-0.5 font-mono text-2xs text-ink-500 dark:border-ink-700 dark:text-ink-400">{{ $tag->name }}</li>
                                @endforeach
                            </ul>

                            <a
                                href="{{ route('blog.show', $post) }}"
                                wire:navigate
                                class="relative mt-auto inline-flex items-center gap-2 pt-6 font-mono text-2xs font-medium uppercase tracking-widest text-signal-700 transition-colors hover:text-signal-600 dark:text-signal-400 dark:hover:text-signal-300"
                            >
                                Read post
                                <span aria-hidden="true" class="transition-transform group-hover:translate-x-1">→</span>
                            </a>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ================================================================== --
     | 09 — Contact
     --}}
    <section id="about-contact" class="scroll-mt-28 border-t border-ink-200 py-20 dark:border-ink-800" aria-labelledby="contact-heading">
        <div class="shell max-w-3xl">
            <x-app.ui.section-marker :number="9" :label="$this->sectionLabel('contact', 'Get in touch')" />
            <h2 id="contact-heading" class="mt-5 text-3xl font-semibold tracking-tightest text-ink-900 dark:text-ink-50">
                {{ $this->profile()->availability }}
            </h2>

            <p class="mt-4 text-base leading-relaxed text-ink-500 dark:text-ink-400">
                The fastest way to reach me is email. I read everything and reply to most things, though not always quickly.
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                @if ($email = $this->profile()->link('email'))
                    <flux:button variant="primary" :href="$email['url']" icon="envelope">
                        {{ str_replace('mailto:', '', $email['url']) }}
                    </flux:button>
                @endif

                @foreach (['github', 'linkedin', 'x'] as $network)
                    @if ($link = $this->profile()->link($network))
                        <flux:button
                            variant="ghost"
                            :href="$link['url']"
                            target="_blank"
                            rel="noopener noreferrer me"
                        >
                            {{ $link['label'] }}
                        </flux:button>
                    @endif
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================================================================== --
     | Floor band — mhabir.dev's outlined CTA marquee, drifting between the
     | Contact section and the footer. Pure decoration: aria-hidden, no JS.
     --}}
    <x-app.ui.cta-marquee />
</div>
