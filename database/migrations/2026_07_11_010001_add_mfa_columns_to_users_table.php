<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IDENTITY-SSO-MFA (scoped to TOTP MFA — SSO/SAML/OIDC federation remains a
 * separate, larger effort needing a real external IdP to test against).
 * Per-user opt-in: mfa_enabled defaults false, so the existing password-only
 * login is unaffected until a user explicitly turns MFA on for their account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('password');
            $table->boolean('mfa_enabled')->default(false)->after('mfa_secret');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_enabled', 'mfa_confirmed_at']);
        });
    }
};
