<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\RunnerState;
use App\Enums\SpawnReason;
use App\Models\Builds\ImageBuild;
use App\Models\Credentials\Credential;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Pools\Pool;
use App\Models\Runners\Runner;
use App\Models\Templates\RetiredTemplateVmid;
use App\Models\Templates\RunnerTemplate;
use App\Models\User;
use App\Services\Provisioning\TargetSelector;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RestoredTemplateImportTest extends TestCase
{
    use RefreshDatabase;

    private RunnerTemplate $template;

    private ProxmoxTarget $target;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsRepository::class)->set('installed_at', now()->toIso8601String());
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        Queue::fake();

        $account = GitHubAccount::create([
            'account_type' => 'organization', 'login' => 'example',
            'github_token' => 'test-token', 'github_webhook_secret' => 'test-secret',
        ]);
        $environment = Environment::create([
            'name' => 'Production', 'slug' => 'production', 'github_account_id' => $account->id,
        ]);
        $credential = Credential::create([
            'name' => 'Imported Linux', 'os' => 'linux', 'username' => 'runner', 'password' => 'test-only-password',
        ]);
        $this->template = RunnerTemplate::create([
            'environment_id' => $environment->id, 'name' => 'Ubuntu Slim',
            'os' => 'linux', 'template_catalog_id' => 'ubuntu-slim', 'credential_id' => $credential->id,
        ]);
        $this->target = ProxmoxTarget::create([
            'name' => 'PVE 02', 'slug' => 'pve-02',
            'proxmox_url' => 'https://pve.example.test/api2/json', 'proxmox_node' => 'pve02',
            'proxmox_token_id' => 'root@pam!test', 'proxmox_token_secret' => 'test-secret',
            'template_vmid_range_start' => 801, 'template_vmid_range_end' => 899,
            'health_status' => 'healthy', 'enabled' => true,
        ]);
    }

    public function test_import_requires_authentication(): void
    {
        auth()->logout();

        $this->get(route('templates.import'))->assertRedirect(route('login'));
        $this->post(route('templates.import.store'), $this->payload())->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_import_page_loads_storage_only_after_selecting_a_node(): void
    {
        $this->get(route('templates.import'))->assertOk()->assertSee('Restore your backup in Proxmox first');
        Http::assertNothingSent();
        $this->fakeProxmox();

        $this->get(route('templates.import', [
            'proxmox_target_id' => $this->target->id, 'runner_template_id' => $this->template->id,
        ]))->assertOk()->assertSee('Source storage')->assertSee('shared')->assertSee('Restored template VMID');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/storage') && $request['content'] === 'images');
    }

    public function test_import_registers_an_available_template_without_building_or_mutating_proxmox(): void
    {
        $this->fakeProxmox();

        $this->post(route('templates.import.store'), $this->payload(['version' => '1.2.3']))
            ->assertRedirect(route('templates.show', $this->template))
            ->assertSessionHasNoErrors();

        $mapping = $this->template->targetMappings()->firstOrFail()->pivot;
        $this->assertSame(801, $mapping->template_vmid);
        $this->assertSame('available', $mapping->availability_status);
        $this->assertSame(1, $mapping->generation);
        $this->assertSame('1.2.3', $mapping->version);
        $this->assertSame('shared', $mapping->imported_storage);
        $this->assertNotNull($mapping->imported_at);
        $this->assertNull($mapping->last_built_at);
        $this->assertNull($mapping->build_iso_file);
        $this->assertSame([$this->target->id], $this->template->builtTargetIds());
        $this->assertSame($this->target->id, app(TargetSelector::class)->selectFor([], $this->template)?->id);
        $this->assertDatabaseCount('image_builds', 0);
        Queue::assertNothingPushed();
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');

        $this->get(route('templates.show', $this->template))->assertOk()->assertSee('Imported')->assertSee('1.2.3')->assertSee('shared');
        $this->get(route('nodes.show', $this->target))->assertOk()->assertSee('Imported / shared');
    }

    public function test_import_preserves_other_nodes_and_unbuilt_mapping_settings_and_does_not_invent_a_version(): void
    {
        $other = ProxmoxTarget::create([
            'name' => 'Original node', 'slug' => 'original', 'proxmox_url' => 'https://original.example.test/api2/json',
            'proxmox_node' => 'pve01', 'proxmox_token_id' => 'token', 'proxmox_token_secret' => 'secret',
        ]);
        $this->template->targetMappings()->attach($other->id, ['template_vmid' => 810, 'availability_status' => 'available']);
        $this->template->targetMappings()->attach($this->target->id, ['build_iso_file' => 'local:iso/ubuntu.iso', 'build_cores' => 4]);
        $this->fakeProxmox();

        $this->post(route('templates.import.store'), $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        $mapping = $this->template->targetMappings()->whereKey($this->target->id)->firstOrFail()->pivot;
        $this->assertNull($mapping->version);
        $this->assertSame('local:iso/ubuntu.iso', $mapping->build_iso_file);
        $this->assertSame(4, $mapping->build_cores);
        $this->assertSame(810, $this->template->targetMappings()->whereKey($other->id)->firstOrFail()->pivot->template_vmid);
        $this->get(route('templates.show', $this->template))->assertOk()->assertSee('Unknown (imported)');

        $this->post(route('templates.import.store'), $this->payload())->assertSessionHasErrors('template_vmid');
        $this->assertSame(1, $mapping->fresh()->generation);
    }

    #[DataProvider('invalidRemoteTemplates')]
    public function test_import_rejects_an_invalid_remote_template(array $vm, array $config, array $storage, string $field): void
    {
        $this->fakeProxmox($vm, $config, $storage);

        $this->post(route('templates.import.store'), $this->payload())->assertSessionHasErrors($field);

        $this->assertDatabaseCount('runner_template_target', 0);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public static function invalidRemoteTemplates(): array
    {
        return [
            'missing VMID' => [['vmid' => 850], [], [], 'template_vmid'],
            'wrong node' => [['node' => 'pve01'], [], [], 'template_vmid'],
            'container' => [['type' => 'lxc'], [], [], 'template_vmid'],
            'ordinary VM' => [[], ['template' => 0], [], 'template_vmid'],
            'running VM' => [['status' => 'running'], [], [], 'template_vmid'],
            'restore still locked' => [[], ['lock' => 'create'], [], 'template_vmid'],
            'wrong storage' => [[], ['scsi0' => 'other:base-801-disk-0'], [], 'source_storage'],
            'cloud-init disk only' => [[], ['scsi0' => null], [], 'source_storage'],
            'CD-ROM only' => [[], ['scsi0' => 'shared:iso/installer.iso,media=cdrom'], [], 'source_storage'],
            'disabled storage' => [[], [], ['enabled' => 0], 'source_storage'],
            'inactive storage' => [[], [], ['active' => 0], 'source_storage'],
        ];
    }

    public function test_import_rejects_vmids_outside_the_template_range_before_contacting_proxmox(): void
    {
        $this->post(route('templates.import.store'), $this->payload(['template_vmid' => 950]))
            ->assertSessionHasErrors('template_vmid');
        Http::assertNothingSent();
        $this->assertDatabaseCount('runner_template_target', 0);
    }

    public function test_import_validates_input_before_contacting_proxmox(): void
    {
        $this->post(route('templates.import.store'), [
            'runner_template_id' => 99999,
            'proxmox_target_id' => 99999,
            'source_storage' => '',
            'template_vmid' => 'not-a-vmid',
            'version' => str_repeat('v', 256),
        ])->assertSessionHasErrors(['runner_template_id', 'proxmox_target_id', 'source_storage', 'template_vmid', 'version']);

        Http::assertNothingSent();
    }

    public function test_import_requires_usable_credentials_for_the_restored_guest(): void
    {
        $this->template->update(['credential_id' => null]);

        $this->post(route('templates.import.store'), $this->payload())->assertSessionHasErrors('runner_template_id');

        Http::assertNothingSent();
    }

    #[DataProvider('mappingConflicts')]
    public function test_import_does_not_overwrite_existing_mappings_or_build_reservations(string $conflict): void
    {
        if ($conflict === 'mapping') {
            $this->template->targetMappings()->attach($this->target->id, ['template_vmid' => 802, 'generation' => 2]);
        } elseif ($conflict === 'another template') {
            $other = $this->template->replicate();
            $other->name = 'Another template';
            $other->save();
            $other->targetMappings()->attach($this->target->id, ['template_vmid' => 801]);
        } elseif ($conflict === 'retired') {
            RetiredTemplateVmid::create([
                'runner_template_id' => $this->template->id, 'proxmox_target_id' => $this->target->id,
                'vmid' => 801, 'generation' => 1, 'retired_at' => now(),
            ]);
        } elseif ($conflict === 'runner') {
            $pool = Pool::create([
                'environment_id' => $this->template->environment_id, 'runner_template_id' => $this->template->id,
                'name' => 'import-test', 'labels' => ['self-hosted'], 'cores' => 2, 'memory' => 2048,
            ]);
            Runner::create([
                'environment_id' => $this->template->environment_id, 'proxmox_target_id' => $this->target->id,
                'pool_id' => $pool->id, 'vmid' => 801, 'runner_name' => 'gha-existing',
                'spawn_reason' => SpawnReason::Warm, 'state' => RunnerState::Idle, 'state_changed_at' => now(),
            ]);
        } else {
            $buildingTemplate = $this->template;
            if ($conflict === 'another build') {
                $buildingTemplate = $this->template->replicate();
                $buildingTemplate->name = 'Another build';
                $buildingTemplate->save();
            }
            ImageBuild::create([
                'environment_id' => $buildingTemplate->environment_id, 'runner_template_id' => $buildingTemplate->id,
                'proxmox_target_id' => $this->target->id, 'template_catalog_id' => $buildingTemplate->template_catalog_id,
                'template_vmid' => $conflict === 'another build' ? 801 : 802,
                'status' => $conflict === 'queued' ? BuildStatus::Queued : BuildStatus::Running,
            ]);
        }
        $before = $this->template->targetMappings()->get()->toArray();
        $this->fakeProxmox();

        $this->post(route('templates.import.store'), $this->payload())->assertSessionHasErrors('template_vmid');

        $this->assertSame($before, $this->template->targetMappings()->get()->toArray());
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        Queue::assertNothingPushed();
    }

    public static function mappingConflicts(): array
    {
        return array_map(fn (string $conflict): array => [$conflict], ['mapping', 'another template', 'retired', 'runner', 'queued', 'running', 'another build']);
    }

    public function test_proxmox_failures_are_reported_without_registering_the_template(): void
    {
        Http::fake(['*' => Http::response(['message' => 'permission denied'], 403)]);

        $this->get(route('templates.import', ['proxmox_target_id' => $this->target->id]))
            ->assertOk()->assertSee('Could not load template storage');
        $this->from(route('templates.import', ['proxmox_target_id' => $this->target->id]))
            ->post(route('templates.import.store'), $this->payload())
            ->assertRedirect()->assertSessionHas('error')->assertSessionHasInput('template_vmid', 801);

        $this->assertDatabaseCount('runner_template_target', 0);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'runner_template_id' => $this->template->id,
            'proxmox_target_id' => $this->target->id,
            'source_storage' => 'shared',
            'template_vmid' => 801,
        ], $overrides);
    }

    private function fakeProxmox(array $vm = [], array $config = [], array $storage = []): void
    {
        Http::fake([
            'https://pve.example.test/api2/json/cluster/resources*' => Http::response(['data' => [array_replace([
                'vmid' => 801, 'node' => 'pve02', 'type' => 'qemu', 'template' => 1, 'status' => 'stopped',
            ], $vm)]]),
            'https://pve.example.test/api2/json/nodes/pve02/qemu/801/config' => Http::response(['data' => array_replace([
                'template' => 1,
                'scsi0' => 'shared:base-801-disk-0,discard=on,size=10G',
                'ide2' => 'shared:vm-801-cloudinit,media=cdrom',
            ], $config)]),
            'https://pve.example.test/api2/json/nodes/pve02/storage*' => Http::response(['data' => [array_replace([
                'storage' => 'shared', 'type' => 'nfs', 'enabled' => 1, 'active' => 1,
            ], $storage)]]),
        ]);
    }
}
