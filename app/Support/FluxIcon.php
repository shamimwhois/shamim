<?php

namespace App\Support;

use Flux\FluxManager;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads back a stored icon name only while Flux still ships that glyph.
 *
 * An icon name is free text: an operator types it into the topic form, and a
 * seeder writes it into a file that runs long after the person who chose the
 * name has gone. Either way a name Flux does not have throws while the page is
 * rendering, which takes down every reader of that taxonomy rather than just
 * showing them a missing picture. The value is stored as written, so the intent
 * survives in the database, and the fallback the views already code
 * (`?? 'tag'`) is what a reader sees.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class FluxIcon implements CastsAttributes
{
    /**
     * Give back the stored name when Flux can render it, otherwise nothing.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return app(FluxManager::class)->componentExists('icon.'.$value) ? $value : null;
    }

    /**
     * Store the name as it was written, trimmed of the spaces a form adds.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $name = trim((string) $value);

        return $name === '' ? null : $name;
    }
}
