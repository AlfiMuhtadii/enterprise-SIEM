<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I18N-LOCALIZATION: per-user locale preference. Nullable -- null means
 * "use the request-resolution fallback chain" (see SetUserLocale
 * middleware), not "broken"; existing users are unaffected until they
 * explicitly set a preference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
