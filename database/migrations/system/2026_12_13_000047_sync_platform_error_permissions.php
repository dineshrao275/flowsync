<?php

use App\Services\Security\PlatformAccess;
use Illuminate\Database\Migrations\Migration;

/** Adds the `errors.view` / `errors.manage` catalog rows and persona grants (idempotent, additive). */
return new class extends Migration
{
    public function up(): void
    {
        app(PlatformAccess::class)->syncCatalog();
    }

    public function down(): void {}
};
