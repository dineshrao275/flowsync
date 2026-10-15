<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_pages', function (Blueprint $table) {
            $table->string('canonical_url', 500)->nullable()->after('og_image');
            $table->boolean('noindex')->default(false)->after('canonical_url');
            $table->string('og_title', 255)->nullable()->after('noindex');
            $table->string('og_description', 500)->nullable()->after('og_title');
        });
    }

    public function down(): void
    {
        Schema::table('website_pages', function (Blueprint $table) {
            $table->dropColumn(['canonical_url', 'noindex', 'og_title', 'og_description']);
        });
    }
};
