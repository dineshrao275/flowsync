<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saved filters for any listing page (page key = the list's slug); private by default, shareable by admins. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('list_saved_filters')) {
            return;
        }

        Schema::create('list_saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('page', 60);
            $table->string('name', 80);
            $table->json('filters');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->index(['page', 'owner_user_id']);
            $table->index(['page', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_saved_filters');
    }
};
