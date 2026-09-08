<?php

namespace Tests\Feature;

use App\Services\SettingsRepository;
use App\Services\Templates\TemplateDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TemplateBundleSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $bundledRoot;

    private string $installRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bundledRoot = storage_path('framework/testing/bundled');
        $this->installRoot = storage_path('framework/testing/installed');

        config([
            'builds.image_builder_path' => $this->bundledRoot,
            'builds.templates_install_path' => $this->installRoot,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->bundledRoot, $this->installRoot] as $root) {
            if (is_dir($root)) {
                exec('rm -rf '.escapeshellarg($root));
            }
        }

        parent::tearDown();
    }

    public function test_a_newer_bundled_catalog_takes_over_from_an_older_pinned_download(): void
    {
        $this->givenBundledVersion('2026.09.06.1');
        $this->givenDownloadedVersion('2026.09.05.2');
        $this->pin('2026.09.05.2');

        $adopted = app(TemplateDownloadService::class)->adoptBundledIfNewer();

        $this->assertSame('2026.09.06.1', $adopted);
        $this->assertNull(app(SettingsRepository::class)->get(SettingsRepository::TEMPLATE_ACTIVE_VERSION));
    }

    public function test_a_newer_download_is_left_pinned(): void
    {
        $this->givenBundledVersion('2026.09.05.2');
        $this->givenDownloadedVersion('2026.09.06.1');
        $this->pin('2026.09.06.1');

        $this->assertNull(app(TemplateDownloadService::class)->adoptBundledIfNewer());
        $this->assertSame('2026.09.06.1', app(SettingsRepository::class)->get(SettingsRepository::TEMPLATE_ACTIVE_VERSION));
    }

    public function test_a_pinned_version_that_is_no_longer_installed_falls_back_to_the_bundle(): void
    {
        $this->givenBundledVersion('2026.09.05.2');
        $this->pin('2026.09.04.1');

        $this->assertSame(TemplateDownloadService::BUNDLED, app(TemplateDownloadService::class)->adoptBundledIfNewer());
        $this->assertNull(app(SettingsRepository::class)->get(SettingsRepository::TEMPLATE_ACTIVE_VERSION));
    }

    public function test_nothing_changes_when_the_bundle_is_already_in_use(): void
    {
        $this->givenBundledVersion('2026.09.06.1');

        $this->assertNull(app(TemplateDownloadService::class)->adoptBundledIfNewer());
    }

    public function test_downloading_an_existing_version_replaces_its_catalog_contents(): void
    {
        $this->givenDownloadedVersion('2026.09.08.1');
        file_put_contents($this->installRoot.'/2026.09.08.1/stale', 'old');

        $archiveSource = storage_path('framework/testing/archive-source');
        $archiveRoot = $archiveSource.'/aurora-gha-manager-templates-main';
        if (is_dir($archiveSource)) {
            exec('rm -rf '.escapeshellarg($archiveSource));
        }
        mkdir($archiveRoot, 0777, true);
        $this->writeCatalog($archiveRoot, '2026.09.08.1');
        file_put_contents($archiveRoot.'/fresh', 'new');

        $archive = storage_path('framework/testing/templates.tar');
        foreach ([$archive, $archive.'.gz'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $phar = new \PharData($archive);
        $phar->buildFromDirectory($archiveSource);
        $phar->compress(\Phar::GZ);

        Http::fake(['*' => Http::response(file_get_contents($archive.'.gz'), 200)]);

        app(TemplateDownloadService::class)->download();

        $this->assertFileDoesNotExist($this->installRoot.'/2026.09.08.1/stale');
        $this->assertFileExists($this->installRoot.'/2026.09.08.1/fresh');
        $this->assertSame('2026.09.08.1', app(SettingsRepository::class)->get(SettingsRepository::TEMPLATE_ACTIVE_VERSION));

        unlink($archive.'.gz');
        unlink($archive);
        exec('rm -rf '.escapeshellarg($archiveSource));
    }

    private function givenBundledVersion(string $version): void
    {
        $this->writeCatalog($this->bundledRoot, $version);
    }

    private function givenDownloadedVersion(string $version): void
    {
        $this->writeCatalog($this->installRoot.'/'.$version, $version);
    }

    private function pin(string $version): void
    {
        app(SettingsRepository::class)->set(SettingsRepository::TEMPLATE_ACTIVE_VERSION, $version);
    }

    private function writeCatalog(string $root, string $version): void
    {
        if (! is_dir($root)) {
            mkdir($root, 0777, true);
        }

        file_put_contents(
            $root.'/templates.json',
            json_encode(['image_builder_version' => $version, 'templates' => []])
        );
    }
}
