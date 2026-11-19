<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use App\Support\DatabaseBackup;
use App\Support\FileBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

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

    /** Puts an uploaded file in place, as if it had been saved that many seconds ago. */
    private function upload(string $path, string $contents, int $secondsAgo): void
    {
        $full = FileBackup::root().'/'.$path;
        File::ensureDirectoryExists(dirname($full));
        File::put($full, $contents);
        touch($full, time() - $secondsAgo);
    }

    /** @return list<string> the names inside a zip the app sent */
    private function namesIn($response): array
    {
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();
        sort($names);

        return $names;
    }

    public function test_uploaded_files_are_downloaded_in_zips_that_pick_up_where_the_last_one_ended(): void
    {
        $this->upload('attachments/7/scan.pdf', 'an old paper', 300);
        $this->upload('staff-signatures/doctor.png', 'a signature', 200);
        $this->upload('backups/hms-2026-01-01-000000.sql.gz', 'not an upload', 300);

        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->get('/api/backups/files')->assertForbidden();
        $this->postJson('/api/backups/files/done', ['through' => time() - 10])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'name' => 'Owner']));
        $this->getJson('/api/backups')->assertOk()->assertJsonPath('files.count', 2)->assertJsonPath('files.waiting', 2)->assertJsonPath('files.downloaded_at', null);

        // The first zip holds both uploads, in their folders, and not the backups folder.
        $first = $this->get('/api/backups/files')->assertOk()->assertHeader('X-Backup-Count', '2')->assertHeader('X-Backup-More', '0');
        $this->assertSame(['attachments/7/scan.pdf', 'staff-signatures/doctor.png'], $this->namesIn($first));

        // Until the browser says the file arrived, the same files are offered again.
        $this->getJson('/api/backups')->assertJsonPath('files.waiting', 2);
        $this->postJson('/api/backups/files/done', ['through' => (int) $first->headers->get('X-Backup-Through')])->assertOk()
            ->assertJsonPath('files.waiting', 0)->assertJsonPath('files.downloaded_by', 'Owner');
        $this->getJson('/api/backups/files')->assertStatus(422);

        // A paper scanned afterwards is the only thing in the next zip; "from the start" gives everything again.
        $this->upload('attachments/9/new.jpg', 'a new scan', 30);
        $this->getJson('/api/backups')->assertJsonPath('files.count', 3)->assertJsonPath('files.waiting', 1);
        $this->assertSame(['attachments/9/new.jpg'], $this->namesIn($this->get('/api/backups/files')->assertOk()));
        $this->assertCount(3, $this->namesIn($this->get('/api/backups/files?from=start')->assertOk()->assertHeader('X-Backup-Count', '3')));
    }

    public function test_the_notice_names_everything_still_to_copy_and_goes_when_all_of_it_has_left(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $alert = fn () => $this->getJson('/api/me')->assertOk()->json('backup_alert');
        $name = $this->postJson('/api/backups')->assertCreated()->json('backups.0.name');
        $this->upload('attachments/7/scan.pdf', 'an old paper', 300);
        Cache::forget('backup_alert');

        // Both things are asked for in the one notice, not one after the other.
        $this->assertStringContainsString('No backup has been copied off the server yet', $alert());
        $this->assertStringContainsString('1 uploaded file ', $alert());
        $this->assertStringContainsString('Copy everything now', $alert());

        // The backup alone leaves the files still to do; with those gone too, nothing is said.
        // The browser is told not to keep the file: a download answered from its own store would never be noted here.
        $download = $this->get("/api/backups/{$name}/download")->assertOk();
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertNull($download->headers->get('Last-Modified'));
        $this->assertStringNotContainsString('No backup has been copied', $alert());
        $this->assertStringContainsString('1 uploaded file ', $alert());
        $through = (int) $this->get('/api/backups/files')->assertOk()->headers->get('X-Backup-Through');
        $this->postJson('/api/backups/files/done', ['through' => $through])->assertOk()->assertJsonPath('alert', null);
        $this->assertNull($alert());
    }

    public function test_an_admin_is_told_when_backups_are_not_being_made_or_copied_off_the_server(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $alert = fn () => $this->getJson('/api/me')->assertOk()->json('backup_alert');

        $this->assertStringContainsString('No backup has been made yet', $alert());
        $name = $this->postJson('/api/backups')->assertCreated()->assertJsonPath('copied_at', null)->json('backups.0.name');
        $this->assertStringContainsString('No backup has been copied off the server yet', $alert());

        // Downloading one is the copy leaving the server. With nothing else waiting, there is nothing more to say.
        $this->get("/api/backups/{$name}/download")->assertOk();
        $this->assertNull($alert());
        $this->getJson('/api/backups')->assertJsonPath('copied_by', $admin->name);

        // Uploaded papers that were never downloaded are the next thing to copy.
        $this->upload('attachments/7/scan.pdf', 'an old paper', 300);
        Cache::forget('backup_alert');
        $this->assertStringContainsString('1 uploaded file ', $alert());

        // A week later the copy is old.
        $this->travel(8)->days();
        touch(DatabaseBackup::run()['path'], now()->timestamp); // the file's own date is the real clock's, not the test's
        Cache::forget('backup_alert');
        $this->assertStringContainsString('8 days old', $alert());

        // Other staff are not shown any of this.
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->assertNull($alert());
    }

    public function test_a_daily_backup_the_server_keeps_cutting_short_is_given_up_and_reported(): void
    {
        // Three tries already today, and the half-written file the last one left behind.
        File::ensureDirectoryExists(DatabaseBackup::folder());
        File::put($part = DatabaseBackup::folder().'/hms-2026-01-01-000000.sql.gz.part', 'half');
        touch($part, time() - 600);
        Cache::put('backup_tries_'.now()->toDateString(), 3, now()->addDay());

        DatabaseBackup::runIfDue();

        $this->assertFileDoesNotExist($part);
        $this->assertCount(0, DatabaseBackup::all());
        $this->assertStringContainsString('did not finish', Cache::get('backup_error'));
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertStringContainsString('did not finish', $this->getJson('/api/me')->json('backup_alert'));

        // Made by hand, the complaint goes away.
        $this->postJson('/api/backups')->assertCreated()->assertJsonPath('error', null);
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

    public function test_a_backup_is_proved_restorable_and_a_damaged_one_is_caught(): void
    {
        Patient::factory()->count(3)->create();
        $made = DatabaseBackup::run();

        $this->artisan('hms:backup-check', ['file' => $made['path']])->expectsOutputToContain('including 3 patients')->expectsOutputToContain('They match')->assertExitCode(0);

        // A file cut short, as a failed download would leave it.
        $cut = $made['path'].'.cut.sql.gz';
        file_put_contents($cut, gzencode(substr(gzdecode(file_get_contents($made['path'])), 0, 4000)));
        $this->artisan('hms:backup-check', ['file' => $cut])->expectsOutputToContain('damaged or incomplete')->assertExitCode(1);

        // A file that claims more than it holds.
        $short = $made['path'].'.short.sql.gz';
        file_put_contents($short, gzencode(preg_replace('/INSERT INTO `patients`.*?;\n/s', '', gzdecode(file_get_contents($made['path'])))));
        $this->artisan('hms:backup-check', ['file' => $short])->expectsOutputToContain('do not match')->assertExitCode(1);
    }

    public function test_the_command_writes_a_backup(): void
    {
        $this->artisan('hms:backup')->expectsOutputToContain('Backup written')->assertExitCode(0);
        $this->assertCount(1, DatabaseBackup::all());
    }
}
