<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_assets', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('name');
            $table->string('url');
            $table->string('hostname');
            $table->string('scheme', 10)->default('https');
            $table->string('status', 20)->default('active');
            $table->string('scan_policy', 30)->default('passive');
            $table->timestamp('last_scanned_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('hostname');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_assets');
    }
};
