<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4.5 — a project dashboard layout: the ordered list of widget types to show. `user_id` null is the
 * project's shared default (set by a project manager); a row with a user id is that person's own
 * arrangement and wins over the default. The widgets' data is computed live, never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_dashboards')) {
            return;
        }

        Schema::create('project_dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->json('widgets');
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_dashboards');
    }
};
