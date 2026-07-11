<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\OidcSsoController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\SamlSsoController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
                ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
                ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
                ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
                ->name('password.email');

    Route::post('password/email', [PasswordResetLinkController::class, 'store']);

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
                ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
                ->name('password.store');

    // IDENTITY-SSO-MFA: reached mid-login, after password succeeds but before
    // Auth::login() completes — the user is logged out at this point, so
    // 'guest' middleware is correct here, not 'auth'.
    Route::get('mfa/challenge', [MfaController::class, 'challenge'])
                ->name('mfa.challenge');

    Route::post('mfa/challenge', [MfaController::class, 'verify'])
                ->name('mfa.verify');

    // IDENTITY-SSO-MFA: OIDC SSO federation login, off by default via
    // config('oidc.enabled') -- the controller itself 404s when disabled.
    Route::get('sso/oidc/redirect', [OidcSsoController::class, 'redirect'])
                ->name('sso.oidc.redirect');

    Route::get('sso/oidc/callback', [OidcSsoController::class, 'callback'])
                ->name('sso.oidc.callback');

    // IDENTITY-SSO-MFA: SAML 2.0 SSO federation login, off by default via
    // config('saml.enabled') -- the controller itself 404s when disabled.
    // 'acs' is POSTed directly by the IdP (HTTP-POST binding), so it is
    // excluded from CSRF verification in VerifyCsrfToken -- like OIDC's
    // callback, it authenticates via a cryptographically signed assertion,
    // not a session-bound CSRF token.
    Route::get('sso/saml/login', [SamlSsoController::class, 'login'])
                ->name('sso.saml.login');

    Route::post('sso/saml/acs', [SamlSsoController::class, 'acs'])
                ->name('sso.saml.acs');

    Route::get('sso/saml/metadata', [SamlSsoController::class, 'metadata'])
                ->name('sso.saml.metadata');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
                ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
                ->middleware(['signed', 'throttle:6,1'])
                ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
                ->middleware('throttle:6,1')
                ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
                ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
                ->name('logout');

    // IDENTITY-SSO-MFA: manage the account's own TOTP second factor.
    Route::get('mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('mfa/enable', [MfaController::class, 'enable'])->name('mfa.enable');
    Route::post('mfa/disable', [MfaController::class, 'disable'])->name('mfa.disable');
});
