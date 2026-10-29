<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateHandleChangeConfirmation;
use App\Actions\UpdateUserHandle;
use App\Http\Requests\CreateUserHandleConfirmationRequest;
use App\Http\Requests\UpdateUserHandleRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final readonly class UserHandleController
{
    /**
     * Step one. A username change here is verified by proof of the live session rather than a password, which
     * this app never holds, so the request is validated first and then answered with a one-time confirmation
     * the browser must bring back. The confirmation rides on Inertia flash, which is read exactly once.
     */
    public function store(CreateUserHandleConfirmationRequest $request, #[CurrentUser] User $user, CreateHandleChangeConfirmation $confirm): RedirectResponse
    {
        $handle = $request->handle();

        Inertia::flash('handleChange', [
            'handle' => $handle,
            'confirmation' => $confirm->handle($user, $handle),
        ]);

        return back();
    }

    /**
     * Step two. The request rejects a stale confirmation or a name already claimed back onto the dialog as field
     * errors. Only a rare race survives to the action, which then reports false; that too is answered with a
     * message asking the user to start again rather than renaming to something that no longer lines up.
     */
    public function update(UpdateUserHandleRequest $request, #[CurrentUser] User $user, UpdateUserHandle $action): RedirectResponse
    {
        $handle = $request->handle();

        if (! $action->handle($user, $handle, $request->confirmation())) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'That confirmation is no longer valid. Start the change again.',
            ]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Your username is now @'.$handle.'.',
        ]);

        return back();
    }
}
