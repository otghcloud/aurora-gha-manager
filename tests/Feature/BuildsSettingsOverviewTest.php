<?php

namespace Tests\Feature;

use App\DataTables\Builds\BuildsDataTable;
use App\Enums\BuildStatus;
use App\Models\Builds\ImageBuild;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Templates\RunnerTemplate;
use App\Models\User;
use App\Services\SettingsRepository;
use Composer\InstalledVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildsSettingsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Environment $environment;

    private ProxmoxTarget $node;

    private RunnerTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsRepository::class)->set('installed_at', now()->toIso8601String());
        $this->actingAs(User::factory()->create());

        $account = GitHubAccount::create([
            'account_type' => 'organization',
            'login' => 'example',
            'github_token' => 'token',
            'github_webhook_secret' => 'secret',
        ]);

        $this->environment = Environment::create([
            'name' => 'Production',
            'slug' => 'production',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'user@pve!token',
            'proxmox_token_secret' => 'secret',
            'github_account_id' => $account->id,
        ]);

        $this->node = ProxmoxTarget::create([
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!token',
            'proxmox_token_secret' => 'secret',
        ]);

        $this->template = RunnerTemplate::create([
            'environment_id' => $this->environment->id,
            'name' => 'Ubuntu Runner',
            'os' => 'linux',
            'template_catalog_id' => 'ubuntu-24.04-proxmox-x64',
        ]);
    }

    public function test_build_index_has_generic_description_filters_and_status_summaries(): void
    {
        $this->build(BuildStatus::Succeeded);
        $this->build(BuildStatus::Failed);
        $this->build(BuildStatus::Queued);
        $this->build(BuildStatus::Running);

        $this->get(route('builds.index'))
            ->assertOk()
            ->assertSee('Image builds across all configured builders.')
            ->assertDontSee('Packer runs triggered from this interface.')
            ->assertSee('filter-template')
            ->assertSee('filter-node')
            ->assertSee('filter-status')
            ->assertSee('All templates')
            ->assertSee('All nodes')
            ->assertSee('All statuses')
            ->assertSee('Built')
            ->assertSee('Failed')
            ->assertSee('In progress')
            ->assertSee('value="1">Ubuntu Runner', escape: false)
            ->assertSee('value="1">PVE 01', escape: false);
    }

    public function test_build_query_applies_template_node_and_status_filters(): void
    {
        $matching = $this->build(BuildStatus::Running);
        $otherTemplate = RunnerTemplate::create([
            'environment_id' => $this->environment->id,
            'name' => 'Windows Runner',
            'os' => 'windows',
        ]);
        $otherNode = ProxmoxTarget::create([
            'name' => 'PVE 02',
            'slug' => 'pve-02',
            'proxmox_url' => 'https://pve02.example.com:8006/api2/json',
            'proxmox_node' => 'pve02',
            'proxmox_token_id' => 'root@pam!token',
            'proxmox_token_secret' => 'secret',
        ]);
        $this->build(BuildStatus::Running, $otherTemplate, $this->node);
        $this->build(BuildStatus::Running, $this->template, $otherNode);
        $this->build(BuildStatus::Failed);

        $this->app['request']->query->replace([
            'template' => (string) $this->template->id,
            'node' => (string) $this->node->id,
            'status' => (string) BuildStatus::Running->value,
        ]);

        $this->assertSame([$matching->id], app(BuildsDataTable::class)
            ->query(new ImageBuild)
            ->pluck('id')
            ->all());
    }

    public function test_settings_overview_shows_retained_usage_plugin_versions_and_template_sources(): void
    {
        $this->build(BuildStatus::Succeeded);
        config([
            'builds.templates_repository' => 'https://user:password@example.com/runner-images.git?token=hidden',
            'builds.image_builder_path' => '/opt/templates/bundled',
            'builds.templates_install_path' => '/opt/templates/downloaded',
        ]);

        $response = $this->get(route('settings.overview'))
            ->assertOk()
            ->assertSee('Configuration')
            ->assertSee('Lifetime usage')
            ->assertSee('Runner records')
            ->assertSee('Workflow jobs served')
            ->assertSee('Image build records')
            ->assertSee('Usage totals count records currently retained')
            ->assertSee('Installed Plugins')
            ->assertSee('Template Sources')
            ->assertSee('https://example.com/runner-images.git')
            ->assertDontSee('password')
            ->assertDontSee('token=hidden')
            ->assertSee('/opt/templates/bundled')
            ->assertSee('/opt/templates/downloaded')
            ->assertSee('1', false);

        foreach (['otghcloud/aurora-gha-plugin-cloudimage', 'otghcloud/aurora-gha-plugin-packer'] as $plugin) {
            $response->assertSee($plugin);
            $response->assertSee(InstalledVersions::getPrettyVersion($plugin));
        }
    }

    private function build(BuildStatus $status, ?RunnerTemplate $template = null, ?ProxmoxTarget $node = null): ImageBuild
    {
        return ImageBuild::create([
            'environment_id' => $this->environment->id,
            'runner_template_id' => ($template ?? $this->template)->id,
            'proxmox_target_id' => ($node ?? $this->node)->id,
            'template_catalog_id' => 'ubuntu-24.04-proxmox-x64',
            'status' => $status,
        ]);
    }
}
