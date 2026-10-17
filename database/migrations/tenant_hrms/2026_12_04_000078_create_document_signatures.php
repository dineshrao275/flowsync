<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F6.7 — internal e-signature. A signature request names a stored HR document and the employee who must sign it.
 * Signing records WHO (typed name, user, re-entered password), WHEN, FROM WHERE (IP, user agent) and WHAT (SHA-256 of
 * the file as it was), sealed into a receipt hash. This is an evidence trail, not a certified qualified signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_signatures')) {
            return;
        }

        Schema::create('document_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('employee_documents')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete(); // the signer
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->string('status', 12)->default('pending'); // pending | signed | declined | cancelled
            $table->string('verification_code', 64)->unique(); // public lookup: fsig_<tenant>_<random>
            $table->string('message', 500)->nullable();
            $table->string('typed_name')->nullable();
            $table->string('document_sha256', 64)->nullable();
            $table->string('receipt_hash', 64)->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent', 255)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
    }
};
