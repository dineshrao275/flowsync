<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2.1 — custom-field engine: tenant-defined fields on employees, tasks, projects, assets, expenses
 * and documents. `custom_field_values` keeps the canonical value as JSON plus three typed shadow
 * columns (text / number / date) so a field can be filtered and sorted in SQL without casting JSON.
 * Plain integer entity ids (no FK): the owning table differs per entity and may live in another
 * migration set (TMS / HRMS). Repair-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('custom_field_definitions')) {
            Schema::create('custom_field_definitions', function (Blueprint $table) {
                $table->id();
                $table->string('entity', 32);
                $table->string('key', 64);
                $table->string('label', 120);
                $table->string('type', 24);
                $table->json('options')->nullable();
                $table->boolean('required')->default(false);
                $table->boolean('is_filterable')->default(true);
                $table->boolean('is_sensitive')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('position')->default(0);
                $table->string('help_text', 255)->nullable();
                $table->timestamps();

                $table->unique(['entity', 'key']);
                $table->index(['entity', 'is_active', 'position']);
            });
        }

        if (! Schema::hasTable('custom_field_values')) {
            Schema::create('custom_field_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();
                $table->string('entity', 32);
                $table->unsignedBigInteger('entity_id');
                $table->json('value')->nullable();
                $table->string('value_text', 255)->nullable();
                $table->decimal('value_number', 18, 4)->nullable();
                $table->date('value_date')->nullable();
                $table->timestamps();

                $table->unique(['definition_id', 'entity_id']);
                $table->index(['entity', 'entity_id']);
                $table->index(['definition_id', 'value_text']);
                $table->index(['definition_id', 'value_number']);
                $table->index(['definition_id', 'value_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_definitions');
    }
};
