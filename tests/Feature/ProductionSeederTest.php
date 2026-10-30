<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\SystemUser;
use Database\Seeders\ProductionSeeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use IsolatesDatabase;

    public function test_it_refuses_to_run_without_admin_credentials(): void
    {
        putenv('SUPERADMIN_EMAIL');
        putenv('SUPERADMIN_PASSWORD');
        unset($_ENV['SUPERADMIN_EMAIL'], $_ENV['SUPERADMIN_PASSWORD'], $_SERVER['SUPERADMIN_EMAIL'], $_SERVER['SUPERADMIN_PASSWORD']);

        $this->expectException(RuntimeException::class);

        app(ProductionSeeder::class)->run();
    }

    public function test_it_creates_one_admin_with_the_given_password_and_is_idempotent(): void
    {
        putenv('SUPERADMIN_EMAIL=Owner@Example.COM');
        putenv('SUPERADMIN_PASSWORD=a-long-random-passphrase');
        $_ENV['SUPERADMIN_EMAIL'] = $_SERVER['SUPERADMIN_EMAIL'] = 'Owner@Example.COM';
        $_ENV['SUPERADMIN_PASSWORD'] = $_SERVER['SUPERADMIN_PASSWORD'] = 'a-long-random-passphrase';

        try {
            app(ProductionSeeder::class)->run();
            app(ProductionSeeder::class)->run(); // idempotent

            $admin = SystemUser::query()->where('email', 'owner@example.com')->get();
            $this->assertCount(1, $admin);
            $this->assertTrue((bool) $admin->first()->is_super_admin);
            $this->assertTrue(Hash::check('a-long-random-passphrase', $admin->first()->password));
            $this->assertFalse(Hash::check('password', $admin->first()->password));
            $this->assertGreaterThan(0, SubscriptionPlan::query()->count());
        } finally {
            putenv('SUPERADMIN_EMAIL');
            putenv('SUPERADMIN_PASSWORD');
            unset($_ENV['SUPERADMIN_EMAIL'], $_ENV['SUPERADMIN_PASSWORD'], $_SERVER['SUPERADMIN_EMAIL'], $_SERVER['SUPERADMIN_PASSWORD']);
        }
    }
}
