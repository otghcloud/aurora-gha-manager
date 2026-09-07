<?php

namespace Tests\Feature;

use App\Services\SettingsRepository;
use App\Services\Templates\TemplateDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
