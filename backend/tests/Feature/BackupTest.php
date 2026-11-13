<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use App\Support\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->useStoragePath(sys_get_temp_dir().'/hms-backup-test-'.getmypid());
        File::deleteDirectory(DatabaseBackup::folder());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path());
        parent::tearDown();
    }

    public function test_a_backup_holds_every_table_and_row_and_awkward_text_survives(): void
    {
        Patient::factory()->create(['name' => "Sita O'Brien \"Rao\"; DROP", 'address' => "Line one\nLine two — తెలుగు"]);
        Patient::factory()->count(3)->create();

        $made = DatabaseBackup::run();
        $sql = gzdecode(file_get_contents($made['path']));

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `patients`', $sql);
        $this->assertStringContainsString("Sita O''Brien \"Rao\"; DROP", $sql);
        $this->assertStringContainsString('తెలుగు', $sql);
        $this->assertStringContainsString("End of backup: {$made['tables']} tables", $sql);
        $this->assertGreaterThanOrEqual(4, $made['rows']);
        $this->assertFileDoesNotExist($made['path'].'.part');

        // Loading the file into an empty database gives the same patients back.
        $copy = new \PDO('sqlite::memory:');
        $copy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $copy->exec(preg_replace('/^SET .*;$/m', '', $sql));
        $this->assertSame(4, (int) $copy->query('select count(*) from patients')->fetchColumn());
        $this->assertSame("Line one\nLine two — తెలుగు", $copy->query("select address from patients where name like 'Sita%'")->fetchColumn());
    }

    public function test_only_the_newest_backups_are_kept(): void
    {
        $folder = DatabaseBackup::folder();
        File::ensureDirectoryExists($folder);
        foreach (['2026-01-01-000000', '2026-01-02-000000', '2026-01-03-000000'] as $stamp) {
            file_put_contents("{$folder}/hms-{$stamp}.sql.gz", 'x');
        }

        DatabaseBackup::run(null, null, 2);

        $names = array_column(DatabaseBackup::all(), 'name');
        $this->assertCount(2, $names);
        $this->assertSame('hms-2026-01-03-000000.sql.gz', $names[1]);
    }

    public function test_only_an_admin_sees_makes_and_downloads_backups(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->getJson('/api/backups')->assertForbidden();
        $this->postJson('/api/backups')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/backups')->assertOk()->assertJsonCount(0, 'backups');
        $name = $this->postJson('/api/backups')->assertCreated()->assertJsonCount(1, 'backups')->json('backups.0.name');

        $this->get("/api/backups/{$name}/download")->assertOk()->assertHeader('content-disposition', "attachment; filename={$name}");
        $this->getJson('/api/backups/..%2F..%2F.env/download')->assertNotFound();
        $this->getJson('/api/backups/hms-2020-01-01-000000.sql.gz/download')->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->get("/api/backups/{$name}/download")->assertForbidden();
    }

    public function test_the_daily_backup_is_made_once_a_day(): void
    {
        DatabaseBackup::runIfDue();
        DatabaseBackup::runIfDue();
        $this->assertCount(1, DatabaseBackup::all());

        // Next morning the first page served makes a new one.
        $this->travel(1)->days();
        DatabaseBackup::runIfDue();
        $this->assertCount(2, DatabaseBackup::all());
    }

    public function test_the_command_writes_a_backup(): void
    {
        $this->artisan('hms:backup')->expectsOutputToContain('Backup written')->assertExitCode(0);
        $this->assertCount(1, DatabaseBackup::all());
    }
}
