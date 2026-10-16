<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3.1 — saved task filters. A filter belongs to one project and one owner; `is_shared` makes it
 * visible to every member of that project (only the owner or a project manager may edit or delete
 * it). `filters` holds the same key/value set the task list endpoint accepts; `jql` is reserved for
 * the F3.2 query language.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_filters')) {
            return;
        }

        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filters')->nullable();
            $table->text('jql')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->index(['project_id', 'is_shared']);
            $table->index(['project_id', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
