<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F8 — competency expectations: the level a designation is expected to hold in a skill. Gaps are read against these. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('skill_expectations')) {
            return;
        }

        Schema::create('skill_expectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('designation_id')->constrained('designations')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->unsignedTinyInteger('expected_level'); // 1-5
            $table->timestamps();

            $table->unique(['designation_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_expectations');
    }
};
