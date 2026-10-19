<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F7.7 — spending limits per category: a ceiling for one line and a ceiling per employee per month. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('expense_categories', 'max_per_line')) {
                $table->decimal('max_per_line', 14, 2)->nullable();
            }
            if (! Schema::hasColumn('expense_categories', 'monthly_limit')) {
                $table->decimal('monthly_limit', 14, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            foreach (['max_per_line', 'monthly_limit'] as $column) {
                if (Schema::hasColumn('expense_categories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
