{{--
    The theme controller's boot script.

    Runs inline in the head, immediately after Flux's own appearance script,
    and must complete before first paint — that is the whole reason it is
    inline rather than bundled. Three jobs:

      1. Dark is the product default. Flux resolves a missing preference to
         the OS setting; this script fills the missing preference with
         `dark` first, so a visitor who has never chosen gets dark on every
         surface regardless of their OS. A stored choice always wins.

      2. Applies the class right now. Flux's script has already run by the
         time this one executes, and for an unset preference it applied the
         OS value — which may be light. Re-deriving the class from the
         (now-filled) preference removes that brief light flash before the
         Flux store finishes booting at the end of the body.

      3. Publishes `window.DevFolioTheme`, the small helper the toggles use
         for the three things that are not Flux's job: keeping the
         `theme-color` meta (mobile browser chrome) in sync, running the
         short colour cross-fade while the theme flips, and persisting plus
         applying the accent colour the header's picker chooses.

    The appearance *state* itself stays with Flux: the stored key is Flux's
    own `flux.appearance`, and switching goes through `$flux.dark`, so the
    reactive store, the persistence and the class all move together.
    Nothing here ever force-writes a class the visitor has explicitly
    chosen against — the auth layout regression that AuthLayoutTest guards
    is a server-rendered `class="dark"`, which this script does not and
    cannot produce.

    With JavaScript disabled none of this runs: the page renders light, the
    same graceful fallback every other progressive enhancement in this
    codebase makes. docs/themes.md §3 records the full flow.
--}}
<script>
    (function () {
        var storageKey = 'flux.appearance';

        if (! window.localStorage.getItem(storageKey)) {
            window.localStorage.setItem(storageKey, 'dark');
        }

        document.documentElement.classList.toggle(
            'dark',
            window.localStorage.getItem(storageKey) !== 'light',
        );

        /* Accent: restore the picker's choice the same way — attribute on
           <html>, before paint. The default (green-teal) is simply the
           absence of the attribute, so it is never stored. */
        var accentKey = 'devfolio.accent';
        var accent = window.localStorage.getItem(accentKey);

        if (accent) {
            document.documentElement.setAttribute('data-accent', accent);
        }

        window.DevFolioTheme = {
            darkColor: '#0d0d0c',
            lightColor: '#f8f9fa',

            /* Keep the browser-chrome colour in step with the applied theme.
               A rAF, because the Flux store applies the class on an effect
               flush that lands before the next frame — reading the class
               synchronously from the click handler can see the old value. */
            sync: function () {
                window.requestAnimationFrame(function () {
                    var meta = document.querySelector('meta[name="theme-color"]');

                    if (meta) {
                        meta.setAttribute(
                            'content',
                            document.documentElement.classList.contains('dark')
                                ? window.DevFolioTheme.darkColor
                                : window.DevFolioTheme.lightColor,
                        );
                    }
                });
            },

            /* The short colour cross-fade. Added for ~350ms, then removed,
               so scrolling and layout are never animated — only paint. */
            transition: function () {
                document.documentElement.classList.add('theme-switching');

                window.setTimeout(function () {
                    document.documentElement.classList.remove('theme-switching');
                }, 350);
            },

            /* Accent colour. `set('rose')` applies `data-accent="rose"` and
               persists it; `set('emerald')` — the default — removes both.
               The per-accent variable scopes live in app.css. */
            accent: function (name) {
                if (! name || name === 'emerald') {
                    window.localStorage.removeItem(accentKey);
                    document.documentElement.removeAttribute('data-accent');

                    return;
                }

                window.localStorage.setItem(accentKey, name);
                document.documentElement.setAttribute('data-accent', name);
            },
        };

        window.DevFolioTheme.sync();
    })();
</script>
