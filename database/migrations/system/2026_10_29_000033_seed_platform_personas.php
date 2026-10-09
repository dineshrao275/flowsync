<?php

use App\Services\Security\PlatformAccess;
use Illuminate\Database\Migrations\Migration;

/** P8.3 - seeds the platform permission catalog and the four persona roles (idempotent, additive). */
return new class extends Migration
{
    public function up(): void
    {
        app(PlatformAccess::class)->syncCatalog();
    }

    public function down(): void {}
};
