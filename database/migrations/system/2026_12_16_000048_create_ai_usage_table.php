<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AI usage per tenant per day (central: quotas and cost views read it without opening a tenant DB). Counts only, never text. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage')) {
            return;
        }

        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->date('day');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('document_pages')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->decimal('cost_estimate', 12, 6)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
