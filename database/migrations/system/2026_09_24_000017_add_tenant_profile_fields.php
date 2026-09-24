<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('description');
            $table->string('registration_number')->nullable()->after('legal_name');
            $table->string('tax_id')->nullable()->after('registration_number');
            $table->string('country')->nullable()->after('tax_id');
            $table->string('street')->nullable()->after('country');
            $table->string('city')->nullable()->after('street');
            $table->string('state')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('state');
            $table->string('website')->nullable()->after('postal_code');
            $table->string('industry')->nullable()->after('website');
            $table->string('company_size')->nullable()->after('industry');
            $table->string('contact_phone')->nullable()->after('company_size');
            $table->string('billing_address')->nullable()->after('billing_email');
            $table->string('billing_currency')->default('USD')->after('billing_address');
            $table->string('timezone')->default('UTC')->after('billing_currency');
            $table->string('locale')->default('en')->after('timezone');
            $table->string('brand_domain')->nullable()->after('locale');
            $table->string('brand_logo_url')->nullable()->after('brand_domain');
            $table->string('brand_primary_color')->nullable()->after('brand_logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name',
                'registration_number',
                'tax_id',
                'country',
                'street',
                'city',
                'state',
                'postal_code',
                'website',
                'industry',
                'company_size',
                'contact_phone',
                'billing_address',
                'billing_currency',
                'timezone',
                'locale',
                'brand_domain',
                'brand_logo_url',
                'brand_primary_color',
            ]);
        });
    }
};
