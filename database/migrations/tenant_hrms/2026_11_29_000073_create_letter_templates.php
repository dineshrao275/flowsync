<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F6.2 — letter templates with {{merge}} fields; a generated letter is stored as an ordinary HR document. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letter_templates')) {
            return;
        }

        Schema::create('letter_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('document_type_slug', 64)->default('experience_letter');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_templates');
    }
};
