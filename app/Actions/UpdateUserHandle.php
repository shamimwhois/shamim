<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class UpdateUserHandle
{
    public function __construct(private CreateHandleChangeNotification $notify)
    {
        //
    }

    /**
     * Applies a rename only after the confirmation issued for this exact handle comes back. The pending entry
     * holds a SHA-256 hash, so both comparisons are constant-time, and it is dropped as soon as it is read:
     * a confirmation works once. The request already screens the secret and the handle's uniqueness, so reaching
     * this method with a stale confirmation or a just-claimed name is a rare race; either way it reports false and
     * the caller tells the user to start again rather than applying a rename that no longer lines up.
     */
    public function handle(User $user, string $handle, string $confirmation): bool
    {
        $key = CreateHandleChangeConfirmation::key($user);

        $pending = Cache::pull($key);

        $valid = is_array($pending)
            && isset($pending['confirmation'], $pending['handle'])
            && is_string($pending['confirmation'])
            && is_string($pending['handle'])
            && hash_equals($pending['confirmation'], hash('sha256', $confirmation))
            && hash_equals($pending['handle'], $handle);

        if (! $valid) {
            return false;
        }

        try {
            DB::transaction(function () use ($user, $handle): void {
                $user->profile->update(['handle' => $handle]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            report($exception);

            return false;
        }

        $this->notify->handle($user, $handle);

        return true;
    }
}
