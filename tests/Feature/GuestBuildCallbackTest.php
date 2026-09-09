<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Models\Builds\ImageBuild;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Templates\RunnerTemplate;
use App\Services\Builds\GuestBuildCallbackService;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestBuildCallbackTest extends TestCase
{
    use RefreshDatabase;

    private ImageBuild $build;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsRepository::class)->setMany([
            'installed_at' => now()->toIso8601String(),
            'app_url' => 'https://manager.example.com/',
        ]);

        $account = GitHubAccount::create([
            'account_type' => 'organization',
            'login' => 'otghcloud',
            'github_token' => 'token',
            'github_webhook_secret' => 'secret',
        ]);
        $environment = Environment::create([
            'name' => 'Production',
            'slug' => 'production',
            'github_account_id' => $account->id,
        ]);
        $target = ProxmoxTarget::create([
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);
        $template = RunnerTemplate::create([
            'environment_id' => $environment->id,
            'name' => 'Ubuntu 26.04',
            'os' => 'linux',
            'template_catalog_id' => 'ubuntu-26.04',
        ]);
        $this->build = ImageBuild::create([
            'environment_id' => $environment->id,
            'runner_template_id' => $template->id,
            'proxmox_target_id' => $target->id,
            'template_catalog_id' => 'ubuntu-26.04',
            'builder_type' => 'cloudimage',
            'status' => BuildStatus::Running,
            'log_path' => storage_path('app/builds/callback-test.log'),
        ]);
    }

    public function test_guest_events_require_a_valid_token_and_are_idempotent(): void
    {
        $credentials = app(GuestBuildCallbackService::class)->issueCredentials($this->build);
        $payload = [
            'sequence' => 1,
            'type' => 'stage_started',
            'stage_id' => 'install-mysql',
            'log' => "[image-builder:stage:install-mysql]\n",
        ];

        $this->postJson(route('builds.guest-events', $this->build), $payload)->assertUnauthorized();
        $this->withToken($credentials['token'])->postJson(route('builds.guest-events', $this->build), $payload)
            ->assertOk()
            ->assertJson(['recorded' => true]);
        $this->withToken($credentials['token'])->postJson(route('builds.guest-events', $this->build), $payload)
            ->assertOk()
            ->assertJson(['recorded' => false]);

        $this->assertDatabaseCount('image_build_guest_events', 1);
        $this->assertSame('install-mysql', $this->build->fresh()->guest_stage_id);
        $this->assertStringContainsString('install-mysql', (string) file_get_contents($this->build->log_path));
    }

    public function test_completed_event_persists_the_guest_result_for_the_builder_to_finalize(): void
    {
        $credentials = app(GuestBuildCallbackService::class)->issueCredentials($this->build);

        $this->withToken($credentials['token'])->postJson(route('builds.guest-events', $this->build), [
            'sequence' => 1,
            'type' => 'completed',
            'outcome' => 'succeeded',
            'exit_code' => 0,
        ])->assertOk();

        $this->assertSame(BuildStatus::Running, $this->build->fresh()->status);
        $this->assertSame('succeeded', $this->build->fresh()->guest_outcome);
        $this->assertSame(0, $this->build->fresh()->guest_exit_code);
    }

    public function test_build_api_url_defaults_to_external_url(): void
    {
        $credentials = app(GuestBuildCallbackService::class)->issueCredentials($this->build);

        $this->assertSame('https://manager.example.com', $credentials['url']);
    }

    public function test_build_api_url_uses_the_environment_default_when_not_saved(): void
    {
        config()->set('app.build_api_url', 'http://build-api.internal:8080/');

        $credentials = app(GuestBuildCallbackService::class)->issueCredentials($this->build);

        $this->assertSame('http://build-api.internal:8080', $credentials['url']);
    }
}
