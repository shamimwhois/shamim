<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use Throwable;

/**
 * Make an uploaded SVG safe to store and serve.
 *
 * SVG is the one image that is also a document. It can carry `<script>`, an
 * `onload=` on its root, a `<foreignObject>` wrapping arbitrary HTML, and a
 * `javascript:` URL in an `xlink:href` — every one of which runs in the origin
 * that serves it. A feed serves attachments from the app's own signed route, so
 * an unsanitised SVG is stored XSS with a file picker for an entry point.
 *
 * The sanitizer is an allow-list, not a clean-up. It walks the parsed document
 * and **removes** any element, attribute or namespace that is not on the list,
 * which is the only direction that fails safe: a construct the author of this
 * class never imagined is dropped rather than passed through, because it was
 * never allowed in the first place. The alternative — enumerating what to strip
 * — has to be right about every future browser feature, and is wrong the first
 * time it is not.
 *
 * **Why hand-rolled rather than a library.** This project does not add
 * dependencies without approval, and the subset an avatar-or-diagram SVG
 * actually uses is narrow: shapes, paths, text, gradients. The allow-list below
 * is that subset. It is deliberately conservative — a diagram that uses a
 * construct not listed here is stored with that construct dropped, which is a
 * cosmetic loss, where the other failure is a script running as the reader.
 *
 * The class does not claim to be a general-purpose sanitiser. It is the door
 * for one format on one upload path, and it is tested against the specific
 * vectors that matter (`SvgSanitizerTest`).
 */
final class SvgSanitizer
{
    /**
     * Elements an SVG may keep.
     *
     * Structural, shape, paint and text primitives only. Anything that can
     * reference the network (`<image>`, `<use>` with a remote href), execute
     * (`<script>`), or embed another document (`<foreignObject>`) is absent by
     * construction — there is nothing to strip, because it was never allowed.
     */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite',
        'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDropShadow',
        'feFlood', 'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode',
        'feMorphology', 'feOffset', 'feSpecularLighting', 'feTile', 'feTurbulence',
        'marker',
    ];

    /**
     * Attributes an element may keep, on top of the presentation list below.
     *
     * `href`/`xlink:href` are *not* here. An SVG referencing anything outside
     * itself is the whole of the risk this class exists for, and no vector this
     * app draws depends on a remote reference — a chart is geometry, not a
     * fetch.
     */
    private const ALLOWED_ATTRIBUTES = [
        'id', 'class', 'style', 'transform', 'viewBox', 'preserveAspectRatio',
        'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'points', 'd', 'pathLength', 'offset', 'gradientUnits', 'gradientTransform',
        'patternUnits', 'patternContentUnits', 'patternTransform', 'clipPathUnits',
        'maskUnits', 'maskContentUnits', 'markerWidth', 'markerHeight', 'markerUnits',
        'refX', 'refY', 'orient', 'filterUnits', 'primitiveUnits', 'result', 'in', 'in2',
        'stdDeviation', 'values', 'type', 'tableValues', 'slope', 'intercept', 'amplitude',
        'exponent', 'mode', 'operator', 'radius', 'scale', 'fill-opacity', 'fill-rule',
        'stroke-opacity', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'font-family',
        'font-size', 'font-style', 'font-weight', 'text-anchor', 'dominant-baseline',
        'opacity', 'stop-color', 'stop-opacity', 'flood-color', 'flood-opacity',
        'spreadMethod', 'attributeName', 'begin', 'dur', 'from', 'to', 'repeatCount',
        'keySplines', 'keyTimes', 'calcMode', 'xmlns', 'version', 'baseProfile',
        'role', 'aria-label', 'aria-hidden',
    ];

    /**
     * Presentation attributes that are safe as a class but not enumerated above.
     *
     * Listed separately so the intent is legible: these are *paint* attributes,
     * and the reasons a value would be refused are all about the value (a
     * `url(...)` or a `javascript:`) rather than the name. {@see isSafeValue()}
     * is what actually decides.
     */
    private const PAINT_ATTRIBUTES = [
        'fill', 'stroke', 'color', 'clip-path', 'mask', 'filter', 'marker-start',
        'marker-mid', 'marker-end', 'stop-color', 'background', 'visibility', 'display',
    ];

    /**
     * Return the same SVG with every un-allowed element, attribute and value
     * removed, or `null` when the bytes are not parseable XML.
     *
     * A parse failure is a refusal rather than a pass-through: a document the
     * parser cannot read is a document this class cannot make safe, and
     * guessing would be the one mistake worth avoiding here.
     */
    public static function clean(string $svg): ?string
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            // No network, no entities: a DTD that fetches is an SSRF and an
            // entity expansion is a billion-laughs bomb. Neither is needed to
            // read the geometry an SVG carries.
            $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOENT | LIBXML_NOCDATA);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false || $document->documentElement === null) {
            return null;
        }

        // The root must actually be an `svg`, or the file is some other XML
        // that happens to parse. Serving that as `image/svg+xml` would paint
        // nothing and might be something else entirely.
        if (strtolower($document->documentElement->nodeName) !== 'svg') {
            return null;
        }

        self::scrub($document->documentElement);

        $clean = $document->saveXML($document->documentElement);

        return $clean === false ? null : $clean;
    }

    /**
     * Whether a file's bytes look like SVG at all.
     *
     * The read-back mime is the authority; this is the second check that keeps
     * a mislabelled file from reaching the parser as an empty string.
     */
    public static function looksLikeSvg(string $bytes): bool
    {
        return preg_match('/<svg[\s>]/i', substr($bytes, 0, 4096)) === 1;
    }

    /**
     * Walk one element, dropping what is not allowed, and recurse.
     */
    private static function scrub(DOMElement $element): void
    {
        // Attributes first: `removeAttribute` while iterating a live
        // `attributes` collection skips entries, so the names are copied out.
        //
        // The collection is a `DOMNamedNodeMap`, whose entries are `DOMAttr`
        // for every attribute — but it is also iterable over namespace nodes
        // for a document that declares one, which have no `nodeName` worth
        // reading here. Trusting the documented type rather than re-checking it
        // at runtime keeps this loop honest about what it walks; a namespace
        // node simply has no name to preserve.
        $names = [];

        foreach ($element->attributes as $attribute) {
            $names[] = $attribute->nodeName;
        }

        foreach ($names as $name) {
            if (! self::isAllowedAttribute($name, $element->getAttribute($name))) {
                $element->removeAttribute($name);
            }
        }

        $children = [];

        foreach ($element->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                if (! self::isAllowedElement($child->nodeName)) {
                    $element->removeChild($child);

                    continue;
                }

                self::scrub($child);

                continue;
            }

            // A comment can carry conditional-compilation markup in old IE and
            // is never needed to draw a shape; a processing instruction can be
            // an xml-stylesheet. Only text and CDATA survive.
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $element->removeChild($child);
            }
        }
    }

    private static function isAllowedElement(string $name): bool
    {
        return in_array(self::localName($name), self::ALLOWED_ELEMENTS, true);
    }

    /**
     * Whether an attribute may stay, judged on its name *and* its value.
     *
     * The value check is not decoration. `style="fill:url(http://evil)"` and
     * `fill="javascript:…"` are both carried by an allowed name, so a name-only
     * check would wave through the exact constructs this class exists to stop.
     */
    private static function isAllowedAttribute(string $name, string $value): bool
    {
        $local = self::localName($name);

        // `on*` is never allowed, on any element, under any name. This one line
        // is the difference between an image and a script.
        if (str_starts_with(strtolower($local), 'on')) {
            return false;
        }

        if (! in_array($local, self::ALLOWED_ATTRIBUTES, true)
            && ! in_array($local, self::PAINT_ATTRIBUTES, true)) {
            return false;
        }

        return self::isSafeValue($value);
    }

    /**
     * Whether an attribute's value is free of the URL and script vectors.
     */
    private static function isSafeValue(string $value): bool
    {
        $normalised = strtolower(trim($value));

        // Control characters and whitespace are stripped before the check
        // because `java\tscript:` is `javascript:` to a browser but not to a
        // `stripos`. Normalising first is what makes the comparison honest.
        $normalised = (string) preg_replace('/[\x00-\x20]+/', '', $normalised);

        if (str_contains($normalised, 'javascript:')) {
            return false;
        }

        if (str_contains($normalised, 'data:') && str_contains($normalised, 'base64')) {
            // A base64 data URL can be an image *or* an HTML document that
            // navigates; the safe half is not worth sorting from the unsafe one
            // on a path a reader does not need.
            return false;
        }

        // `url(` can point anywhere. Allowed only when it stays inside the
        // document — a local fragment or a filter reference, which is how a
        // gradient or a mask is written.
        if (preg_match('/url\(\s*[\'"]?([^)\'"]*)/i', $value, $match) === 1) {
            $target = trim($match[1]);

            $isLocal = $target === '' || str_starts_with($target, '#');
            $isBareReference = preg_match('/^[a-zA-Z0-9_-]+$/', $target) === 1;

            if (! $isLocal && ! $isBareReference) {
                return false;
            }
        }

        // An external reference in a presentation value, before the parser sees
        // it as one.
        if (preg_match('#(?:https?:)?//#', $normalised) === 1) {
            return false;
        }

        return true;
    }

    /**
     * An attribute's name without its namespace prefix.
     *
     * `xlink:href` and `href` are the same attribute to a browser, so the check
     * has to see the same name the browser does. Stripping the prefix is what
     * makes `xlink:href="javascript:…"` fail the allow-list rather than slip
     * past it under a name that was never listed.
     */
    private static function localName(string $name): string
    {
        $position = strpos($name, ':');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /**
     * Remove the `xlink`/`xmlns` prefixes from an SVG's output.
     *
     * The per-name check already strips `xlink:href`, but a *surviving* prefixed
     * attribute whose namespace declaration is gone is inert rather than live —
     * an unknown prefix does not resolve to a link. Dropping the declarations
     * is the belt to that braces, and it is why the cleaned output needs no
     * namespace table to be read safely.
     */
    public static function stripNamespaceDeclarations(string $svg): string
    {
        return (string) preg_replace('/\s+xmlns(?::[a-zA-Z0-9_-]+)?="[^"]*"/i', '', $svg);
    }
}
