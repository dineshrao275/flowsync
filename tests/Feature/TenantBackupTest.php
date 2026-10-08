<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantBackupTest extends TestCase
{
    use IsolatesDatabase;

    private string $backupTestDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupTestDir = sys_get_temp_dir().'/flowsync_test_backups_'.uniqid();
        File::makeDirectory($this->backupTestDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->backupTestDir)) {
            File::deleteDirectory($this->backupTestDir);
        }
        parent::tearDown();
    }

    public function test_backup_command_creates_valid_system_and_tenant_backups_with_manifest(): void
    {
        $targetDir = $this->backupTestDir.'/run_1';

        $this->artisan('tenants:backup', [
            '--all' => true,
            '--path' => $targetDir,
            '--verify' => true,
        ])->assertSuccessful();

        $manifestFile = $targetDir.'/manifest.json';
        $this->assertFileExists($manifestFile);

        $manifest = json_decode(file_get_contents($manifestFile), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('system_database', $manifest);
        $this->assertArrayHasKey('tenants', $manifest);

        $systemDbPath = $manifest['system_database']['path'];
        $this->assertFileExists($systemDbPath);
        $this->assertGreaterThan(0, $manifest['system_database']['size_bytes']);
        $this->assertSame(hash_file('sha256', $systemDbPath), $manifest['system_database']['sha256']);

        $this->assertNotEmpty($manifest['tenants']);
        foreach ($manifest['tenants'] as $tenantInfo) {
            $this->assertFileExists($tenantInfo['path']);
            $this->assertGreaterThan(0, $tenantInfo['size_bytes']);
            $this->assertSame(hash_file('sha256', $tenantInfo['path']), $tenantInfo['sha256']);
        }
    }

    public function test_restore_drill_verifies_backup_integrity_without_affecting_live_data(): void
    {
        $targetDir = $this->backupTestDir.'/run_drill';

        $this->artisan('tenants:backup', [
            '--all' => true,
            '--path' => $targetDir,
            '--verify' => true,
        ])->assertSuccessful();

        $manifestPath = $targetDir.'/manifest.json';

        $this->artisan('tenants:backup', [
            '--restore-drill' => $manifestPath,
        ])->assertSuccessful();
    }

    public function test_backup_command_can_target_single_tenant(): void
    {
        $targetDir = $this->backupTestDir.'/run_single';

        $this->artisan('tenants:backup', [
            '--tenant' => 'acme',
            '--path' => $targetDir,
            '--verify' => true,
        ])->assertSuccessful();

        $manifest = json_decode(file_get_contents($targetDir.'/manifest.json'), true);
        $this->assertCount(1, $manifest['tenants']);
        $this->assertSame('acme', $manifest['tenants'][0]['slug']);
    }
}
