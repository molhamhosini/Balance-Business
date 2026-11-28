<?php

namespace Tests\Feature\System;

use App\Filament\Admin\Pages\Backups;
use App\Filament\Admin\Pages\DataImport;
use App\Models\AuditLog;
use App\Services\System\BackupService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Feature\Accounting\AccountingTestCase;

class BackupTest extends AccountingTestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/bb-backup-test-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/app');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function svc(): BackupService
    {
        return app(BackupService::class);
    }

    public function test_create_list_and_path_validation(): void
    {
        $name = $this->svc()->create('manual', 'قبل الإقفال');
        $this->assertMatchesRegularExpression('/-\d{4}-\d{2}-\d{2}_\d{6}-manual\.sql\.gz$/', $name);
        $list = $this->svc()->list();
        $this->assertCount(1, $list);
        $this->assertSame('manual', $list[0]['tag']);
        $this->assertSame('قبل الإقفال', $list[0]['note']);
        $this->assertGreaterThan(100, $list[0]['size']);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->path('../../.env');
    }

    public function test_page_is_admin_only_and_creates_a_backup(): void
    {
        Filament::setTenant($this->company);
        $this->actingAs($this->entry);
        $this->assertFalse(Backups::canAccess());

        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('system_admin');
        $admin->companies()->attach($this->company);
        $this->actingAs($admin);
        $this->get(Backups::getUrl(['tenant' => $this->company]))->assertOk()->assertSee(__('Create backup now'));
        Livewire::test(Backups::class)->callAction('create', ['note' => 'test'])->assertHasNoActionErrors();
        $this->assertCount(1, $this->svc()->list());
        $name = $this->svc()->list()[0]['name'];
        Livewire::test(Backups::class)->callAction('download', arguments: ['name' => $name])->assertFileDownloaded($name);
        Livewire::test(Backups::class)->callAction('delete', arguments: ['name' => $name]);
        $this->assertCount(0, $this->svc()->list());
    }

    public function test_import_takes_a_backup_first(): void
    {
        Filament::setTenant($this->company);
        $this->actingAs($this->chief);
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->fromArray([['code', 'name_ar'], ['', 'عميل جديد']]);
        $rel = 'imports/c.xlsx';
        File::ensureDirectoryExists(dirname(\Illuminate\Support\Facades\Storage::disk('local')->path($rel)));
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save(\Illuminate\Support\Facades\Storage::disk('local')->path($rel));
        Livewire::test(DataImport::class)->set('data.type', 'customers')->set('data.file', [$rel])->call('import');
        $tags = array_column($this->svc()->list(), 'tag');
        $this->assertSame(['before-import'], $tags);
        \Illuminate\Support\Facades\Storage::disk('local')->delete($rel);
    }

    public function test_restore_round_trip_keeps_a_safety_copy(): void
    {
        $name = $this->svc()->create('manual');
        DB::table('companies')->where('id', $this->company->id)->update(['name_ar' => 'اسم بعد النسخة']);

        $safety = $this->svc()->restore($name);

        $this->assertSame('شركة الذبائح', DB::table('companies')->where('id', $this->company->id)->value('name_ar'));
        $this->assertStringEndsWith('-before-restore.sql.gz', $safety);
        $this->assertContains($safety, array_column($this->svc()->list(), 'name'));
        $this->assertTrue(AuditLog::where('action', 'restored')->exists());
        // the restore ran DDL (implicit commit): rebuild the test database for the next tests
        RefreshDatabaseState::$migrated = false;
    }
}
