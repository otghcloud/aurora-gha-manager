<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsRepository;
use App\Services\Templates\TemplateDownloadService;
use App\Services\Templates\TemplateSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TemplateSourceImportTest extends TestCase
{
    use RefreshDatabase;

    private string $installRoot;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = storage_path('framework/testing/template-source-'.bin2hex(random_bytes(5)));
        $this->installRoot = $this->scratch.'/installed';
        File::ensureDirectoryExists($this->scratch);
        config(['builds.templates_install_path' => $this->installRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    public function test_source_import_forms_are_available_before_a_template_update_check(): void
    {
        app(SettingsRepository::class)->set('installed_at', now()->toIso8601String());
        $this->actingAs(User::factory()->create());

        $this->get(route('settings.templates.index'))
            ->assertOk()
            ->assertSee('Import a template source')
            ->assertSee('template-source-url')
            ->assertSee('template-source-repository')
            ->assertSee('template-source-archive');
    }

    public function test_admin_imports_uploaded_archive_as_validated_inactive_version(): void
    {
        app(SettingsRepository::class)->set('installed_at', now()->toIso8601String());
        $this->actingAs(User::factory()->create());
        $archive = $this->writeArchive([
            ['path' => 'third-party/templates.json', 'data' => $this->catalog('2026.11.19.1')],
        ]);

        $this->post(route('settings.templates.sources.import'), [
            'source_type' => 'upload',
            'archive' => new UploadedFile($archive, 'templates.tar.gz', 'application/gzip', null, true),
        ])->assertRedirect(route('settings.templates.index'))
            ->assertSessionHas('success');

        $this->assertFileExists($this->installRoot.'/2026.11.19.1/templates.json');
        $this->assertNull(app(SettingsRepository::class)->get(SettingsRepository::TEMPLATE_ACTIVE_VERSION));

        $this->post(route('settings.templates.activate-version', '2026.11.19.1'))
            ->assertRedirect(route('settings.templates.index'))
            ->assertSessionHas('success');
        $this->assertSame('2026.11.19.1', app(SettingsRepository::class)->get(SettingsRepository::TEMPLATE_ACTIVE_VERSION));

        $this->post(route('settings.templates.sources.import'), [
            'source_type' => 'upload',
            'archive' => new UploadedFile($archive, 'templates.tar.gz', 'application/gzip', null, true),
        ])->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame('2026.11.19.1', app(SettingsRepository::class)->get(SettingsRepository::TEMPLATE_ACTIVE_VERSION));
    }

    public function test_catalog_with_a_valid_builder_and_referenced_manifest_imports(): void
    {
        app(SettingsRepository::class)->set('installed_at', now()->toIso8601String());
        $this->actingAs(User::factory()->create());
        $catalog = json_encode([
            'schema_version' => 2,
            'image_builder_version' => '2026.11.19.7',
            'templates' => [[
                'id' => 'acme-ubuntu',
                'name' => 'Acme Ubuntu',
                'metadata' => ['version' => '1.0'],
                'platform' => ['type' => 'linux'],
                'builders' => [
                    'packer' => [
                        'path' => 'templates/ubuntu/packer',
                        'build_manifest' => 'templates/ubuntu/packer/build.json',
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
        $archive = $this->writeArchive([
            ['path' => 'third-party/templates.json', 'data' => $catalog],
            ['path' => 'third-party/templates/ubuntu/packer/build.json', 'data' => '{}'],
        ]);

        $result = app(TemplateSourceService::class)->importUpload(
            new UploadedFile($archive, 'templates.tar.gz', 'application/gzip', null, true),
        );

        $this->assertSame('2026.11.19.7', $result['version']);
        $this->assertSame(1, $result['templates']);
        $this->assertFileExists($this->installRoot.'/2026.11.19.7/templates/ubuntu/packer/build.json');
    }

    public function test_archive_path_traversal_and_symlinks_are_rejected(): void
    {
        $traversal = $this->writeArchive([
            ['path' => 'third-party/templates.json', 'data' => $this->catalog('2026.11.19.2')],
            ['path' => 'third-party/../../escape', 'data' => 'nope'],
        ]);
        $symlink = $this->writeArchive([
            ['path' => 'third-party/templates.json', 'data' => $this->catalog('2026.11.19.3')],
            ['path' => 'third-party/link', 'data' => '', 'type' => '2'],
        ]);

        foreach ([$traversal, $symlink] as $archive) {
            try {
                app(TemplateSourceService::class)->validateArchiveFile($archive);
                $this->fail('A hostile archive was accepted.');
            } catch (RuntimeException) {
                $this->assertDirectoryDoesNotExist($this->scratch.'/escape');
            }
        }
    }

    public function test_schema_errors_prevent_archive_import(): void
    {
        $archive = $this->writeArchive([
            ['path' => 'third-party/templates.json', 'data' => json_encode([
                'schema_version' => 1,
                'image_builder_version' => '2026.11.19.4',
                'templates' => [],
            ], JSON_THROW_ON_ERROR)],
        ]);

        try {
            app(TemplateSourceService::class)->validateArchiveFile($archive);
            $this->fail('An unsupported catalog schema was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('schema_version 2', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->installRoot.'/2026.11.19.4');
    }

    public function test_unsafe_builder_names_are_rejected(): void
    {
        $catalog = json_encode([
            'schema_version' => 2,
            'image_builder_version' => '2026.11.19.8',
            'templates' => [[
                'id' => 'acme-ubuntu',
                'name' => 'Acme Ubuntu',
                'metadata' => ['version' => '1.0'],
                'platform' => ['type' => 'linux'],
                'builders' => [
                    'packer.unexpected' => [
                        'path' => 'templates/ubuntu/packer',
                        'build_manifest' => 'templates/ubuntu/packer/build.json',
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
        $archive = $this->writeArchive([
            ['path' => 'third-party/templates.json', 'data' => $catalog],
            ['path' => 'third-party/templates/ubuntu/packer/build.json', 'data' => '{}'],
        ]);

        try {
            app(TemplateSourceService::class)->validateArchiveFile($archive);
            $this->fail('An unsafe builder name was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid builder', $exception->getMessage());
        }
    }

    public function test_invalid_catalog_cannot_be_activated(): void
    {
        File::ensureDirectoryExists($this->installRoot.'/2026.11.19.6');
        file_put_contents($this->installRoot.'/2026.11.19.6/templates.json', json_encode([
            'schema_version' => 1,
            'image_builder_version' => '2026.11.19.6',
            'templates' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            app(TemplateDownloadService::class)->activate('2026.11.19.6');
            $this->fail('An invalid catalog was activated.');
        } catch (RuntimeException) {
            $this->assertNull(app(SettingsRepository::class)->get(SettingsRepository::TEMPLATE_ACTIVE_VERSION));
        }
    }

    public function test_activation_version_cannot_escape_the_install_directory(): void
    {
        try {
            app(TemplateDownloadService::class)->activate('../../outside');
            $this->fail('A path traversal version was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('version is invalid', $exception->getMessage());
        }

        $this->assertNull(app(SettingsRepository::class)->get(SettingsRepository::TEMPLATE_ACTIVE_VERSION));
    }

    public function test_source_validation_rejects_root_and_dot_segment_install_directories(): void
    {
        foreach (['/', '../outside'] as $installRoot) {
            config(['builds.templates_install_path' => $installRoot]);

            try {
                app(TemplateSourceService::class)->validateArchiveFile($this->scratch.'/missing.tar.gz');
                $this->fail('An unsafe template install directory was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('install directory', $exception->getMessage());
            }
        }
    }

    public function test_github_repository_source_uses_codeload_without_running_git(): void
    {
        $archive = $this->writeArchive([
            ['path' => 'source/templates.json', 'data' => $this->catalog('2026.11.19.5')],
        ]);
        Http::fake(['*' => Http::response(file_get_contents($archive), 200)]);

        $result = app(TemplateSourceService::class)->importRepository('https://github.com/acme/templates.git', 'release/2026.11');

        $this->assertSame('2026.11.19.5', $result['version']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://codeload.github.com/acme/templates/tar.gz/release/2026.11');
    }

    public function test_arbitrary_remote_hosts_are_not_requested(): void
    {
        Http::fake();

        try {
            app(TemplateSourceService::class)->importUrl('https://127.0.0.1/private.tar.gz');
            $this->fail('A non-codeload URL was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('codeload.github.com', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /** @param array<int, array{path: string, data: string, type?: string}> $entries */
    private function writeArchive(array $entries): string
    {
        $tar = '';

        foreach ($entries as $entry) {
            $name = $entry['path'];
            $data = $entry['data'];
            $type = $entry['type'] ?? '0';
            $header = str_repeat("\0", 512);
            $this->putTarField($header, 0, 100, $name);
            $this->putTarField($header, 100, 8, sprintf("%07o\0", 0644));
            $this->putTarField($header, 108, 8, sprintf("%07o\0", 0));
            $this->putTarField($header, 116, 8, sprintf("%07o\0", 0));
            $this->putTarField($header, 124, 12, sprintf("%011o\0", strlen($data)));
            $this->putTarField($header, 136, 12, sprintf("%011o\0", 0));
            $this->putTarField($header, 148, 8, '        ');
            $this->putTarField($header, 156, 1, $type);
            $this->putTarField($header, 257, 6, "ustar\0");
            $this->putTarField($header, 263, 2, '00');
            $checksum = array_sum(array_map('ord', str_split($header)));
            $this->putTarField($header, 148, 8, sprintf("%06o\0 ", $checksum));
            $tar .= $header.$data.str_repeat("\0", (512 - (strlen($data) % 512)) % 512);
        }

        $tar .= str_repeat("\0", 1024);
        $path = $this->scratch.'/source-'.bin2hex(random_bytes(4)).'.tar.gz';
        file_put_contents($path, gzencode($tar));

        return $path;
    }

    private function putTarField(string &$header, int $offset, int $length, string $value): void
    {
        $header = substr_replace($header, str_pad(substr($value, 0, $length), $length, "\0"), $offset, $length);
    }

    private function catalog(string $version): string
    {
        return json_encode([
            'schema_version' => 2,
            'image_builder_version' => $version,
            'templates' => [],
        ], JSON_THROW_ON_ERROR);
    }
}
