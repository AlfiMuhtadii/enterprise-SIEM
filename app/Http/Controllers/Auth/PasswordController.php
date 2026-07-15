<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        // SEC-SESSION-INVALIDATION: a session hijacked before this password
        // change must not survive it. Regenerates the remember-me token
        // immediately and (paired with the AuthenticateSession middleware
        // in the 'web' group) logs every other active session out on its
        // next request. Checked against the NEW password since $request->user()
        // already reflects it after update() above.
        Auth::logoutOtherDevices($validated['password']);

        return back()->with('status', 'password-updated');
    }
}
