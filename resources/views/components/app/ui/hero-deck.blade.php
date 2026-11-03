@props([
    'profile' => null,
])

@php
    /**
     * The landing page's opening frame — section 01.
     *
     * Everything the hero asserts comes from the author profile (or the
     * config defaults behind it), so there is nothing here to keep in sync
     * with a copy deck. The one derived thing is the name split: it breaks on
     * whitespace so the display lines follow whatever name is configured,
     * and a single-word name simply renders one line.
     *
     * All colour is applied by `resources/css/hero-deck.css` through the
     * `--color-hx-*` role set in app.css. This file contains no hex, no
     * `text-white`, and no `dark:` colour decisions — the hero follows the
     * theme toggle and the accent picker like every other surface on the
     * site, which is what lets it carry a sci-fi treatment without becoming
     * the one section that looks wrong in one of the two themes.
     */
    $name = $profile?->name ?? config('portfolio.author.name', 'Md Shamim Hossain');
    $role = $profile?->role ?? 'Full Stack Developer';
    $tagline = $profile?->tagline ?? 'I design and build web applications end to end.';
    $availability = $profile?->availability ?? 'Open to work';
    $location = $profile?->location;
    $years = $profile?->yearsOfExperience;

    $words = preg_split('/\s+/', trim($name)) ?: [];
    $lineOne = (string) ($words[0] ?? $name);
    $lineTwo = implode(' ', array_slice($words, 1));

    // The bottom rail's condensed stack: the union of what the shipped
    // projects actually claim, capped at six so the rail stays one line at
    // desktop widths. Never invented — if there are no projects the rail
    // drops the list rather than guessing.
    $stack = collect($profile?->projects ?? [])
        ->flatMap(fn ($project) => $project['stack'] ?? [])
        ->unique()
        ->take(6)
        ->values();

    // Two depths of starfield. Each entry is a position, a size, a peak
    // opacity and a twinkle period, so the field does not pulse in unison.
    $starfield = static function (int $count, int $maxSize, int $minAlpha, int $maxAlpha): array {
        $stars = [];

        for ($i = 0; $i < $count; $i++) {
            $stars[] = [
                'x' => number_format(rand(0, 10000) / 100, 2),
                'y' => number_format(rand(0, 10000) / 100, 2),
                'size' => rand(1, $maxSize),
                'alpha' => number_format(rand($minAlpha, $maxAlpha) / 100, 2),
                'period' => number_format(rand(2600, 7600) / 1000, 2),
                'delay' => number_format(rand(0, 6000) / 1000, 2),
            ];
        }

        return $stars;
    };
@endphp

<section
    class="hx"
    aria-labelledby="hero-heading"
    data-test="home-hero"
    x-data
    @pointermove="
        if ($event.pointerType !== 'mouse') { return; }
        const box = $el.getBoundingClientRect();
        $el.style.setProperty('--hx-px', (($event.clientX - box.left) / box.width * 2 - 1).toFixed(3));
        $el.style.setProperty('--hx-py', (($event.clientY - box.top) / box.height * 2 - 1).toFixed(3));
    "
>
    {{-- ================================================================== --
     | 1 — the void. Ground, corner falloff, and the bloom on the vanishing
     | point. Purely atmospheric: nothing here is read, so it is all hidden
     | from assistive technology.
     --}}
    <div class="hx__void" aria-hidden="true"></div>
    <div class="hx__bloom" aria-hidden="true"></div>
    <div class="hx__horizon" aria-hidden="true"></div>

    {{-- ================================================================== --
     | 2 — the starfield. Two depths at two parallax rates. The outer box is
     | what the pointer moves; the inner box is what the drift animation
     | moves, because one element cannot be driven by both at once.
     --}}
    <div class="hx__stars" aria-hidden="true">
        <div class="hx__stars-drift">
            @foreach ($starfield(54, 2, 30, 80) as $star)
                <span
                    class="hx__star"
                    style="--s-x: {{ $star['x'] }}%; --s-y: {{ $star['y'] }}%; --s-s: {{ $star['size'] }}px; --s-o: {{ $star['alpha'] }}; --s-dur: {{ $star['period'] }}s; --s-delay: {{ $star['delay'] }}s;"
                ></span>
            @endforeach
        </div>
    </div>

    <div class="hx__stars hx__stars--far" aria-hidden="true">
        <div class="hx__stars-drift">
            @foreach ($starfield(38, 1, 22, 55) as $star)
                <span
                    class="hx__star"
                    style="--s-x: {{ $star['x'] }}%; --s-y: {{ $star['y'] }}%; --s-s: {{ $star['size'] }}px; --s-o: {{ $star['alpha'] }}; --s-dur: {{ $star['period'] }}s; --s-delay: {{ $star['delay'] }}s;"
                ></span>
            @endforeach
        </div>
    </div>

    {{-- ================================================================== --
     | 3 — the plane. A perspective floor receding to the horizon line. The
     | geometry lives in hero-deck.css; the markup is one box and one floor.
     --}}
    <div class="hx__plane" aria-hidden="true">
        <div class="hx__floor"></div>
    </div>

    {{-- One pass of light down the frame on load. --}}
    <div class="hx__scan" aria-hidden="true"></div>

    {{-- ================================================================== --
     | 5 — the chrome. Corner brackets, drawn in from each corner.
     --}}
    <div class="hx__frame" aria-hidden="true">
        <span class="hx__bracket hx__bracket--tl" style="--d: 40ms"></span>
        <span class="hx__bracket hx__bracket--tr" style="--d: 120ms"></span>
        <span class="hx__bracket hx__bracket--bl" style="--d: 200ms"></span>
        <span class="hx__bracket hx__bracket--br" style="--d: 280ms"></span>
    </div>

    {{-- ================================================================== --
     | The content. Top rail, the two-column body, bottom rail — the same
     | three-band HUD rhythm the rest of the page's chrome uses.
     --}}
    <div class="hx__inner shell">
        <div class="hx__rail hx__rail--top" style="--d: 180ms">
            <x-app.ui.section-marker :number="1" label="Hero" accent />

            <dl class="hx__readout">
                @if ($location)
                    <div>
                        <dt>Base</dt>
                        <dd>{{ strtoupper($location) }}</dd>
                    </div>
                @endif

                @if ($years)
                    <div>
                        <dt>Exp</dt>
                        <dd>{{ $years }} yrs</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="hx__body">
            <div>
                <p class="hx__status" style="--d: 300ms">
                    <span class="hx__status-dot" aria-hidden="true"></span>
                    {{ $availability }}
                </p>

                <h1 id="hero-heading" class="hx__title">
                    <span class="hx__line" style="--d: 380ms">{{ $lineOne }}</span>
                    @if ($lineTwo !== '')
                        <span class="hx__line" style="--d: 470ms">{{ $lineTwo }}</span>
                    @endif
                </h1>

                <div class="hx__rule" style="--d: 600ms" aria-hidden="true"></div>

                <p class="hx__role" style="--d: 680ms">{{ $role }}</p>

                <p class="hx__tagline" style="--d: 760ms">{{ $tagline }}</p>

                <div class="hx__cta" style="--d: 840ms">
                    {{-- The primary CTA leaves the page now rather than scrolling
                         to `#work`, so it carries the internal-route idiom the
                         rest of the site uses: a named route, Livewire's
                         navigate, and a right arrow — `arrow-down` would keep
                         promising a scroll that no longer happens. --}}
                    <flux:button variant="primary" :href="route('about')" wire:navigate icon="arrow-right">
                        About me
                    </flux:button>

                    <flux:button
                        variant="ghost"
                        :href="route('blog.index')"
                        wire:navigate
                        icon="document-text"
                    >
                        Read the writing
                    </flux:button>
                </div>
            </div>

            {{-- ========================================================== --
             | 4 — the signal core. Rings, a radar sweep, a tick dial and a
             | pulsing pip. Below `md` the stylesheet turns this into a
             | watermark behind the headline instead of a column.
             --}}
            <div class="hx__core" aria-hidden="true">
                <span class="hx__ring hx__ring--outer"></span>
                <span class="hx__ring hx__ring--mid"></span>
                <span class="hx__ring hx__ring--inner"></span>
                <span class="hx__sweep"></span>

                <svg class="hx__ticks" viewBox="0 0 200 200" fill="none">
                    @for ($i = 0; $i < 48; $i++)
                        @php $major = $i % 6 === 0; @endphp
                        <line
                            x1="100"
                            y1="6"
                            x2="100"
                            y2="{{ $major ? 16 : 11 }}"
                            transform="rotate({{ $i * 7.5 }} 100 100)"
                            data-major="{{ $major ? 'true' : 'false' }}"
                        />
                    @endfor
                </svg>

                <span class="hx__orbit"><i></i></span>
                <span class="hx__orbit hx__orbit--slow"><i></i></span>
                <span class="hx__pip"></span>
            </div>
        </div>

        <div class="hx__rail hx__rail--bottom" style="--d: 920ms">
            @if ($stack->isNotEmpty())
                <ul class="hx__stack">
                    @foreach ($stack as $tech)
                        <li>{{ $tech }}</li>
                    @endforeach
                </ul>
            @endif

            <p class="hx__scroll">
                Scroll
                <span class="hx__scroll-track" aria-hidden="true"></span>
            </p>
        </div>
    </div>
</section>
