<?php

namespace Tests\Feature;

use App\Enums\RunnerState;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Runners\Runner;
use App\Models\User;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProxmoxTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsRepository::class)->set('installed_at', now()->toIso8601String());
        $this->actingAs(User::factory()->create());
    }

    public function test_standalone_target_create_page_renders_without_an_environment(): void
    {
        $this->get(route('nodes.create'))
            ->assertOk()
            ->assertSee('Create node');
    }

    public function test_standalone_target_detail_page_renders(): void
    {
        $target = ProxmoxTarget::create([
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);

        $this->get(route('nodes.show', $target))
            ->assertOk()
            ->assertSee('Template coverage');
    }

    public function test_standalone_target_can_be_created(): void
    {
        $this->post(route('nodes.store'), [
            'name' => 'PVE 01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
            'max_total_vms' => 12,
            'template_vmid_range_start' => 100,
            'template_vmid_range_end' => 8999,
            'runner_vmid_range_start' => 9000,
            'runner_vmid_range_end' => 9999,
            'enabled' => true,
            'proxmox_verify_tls' => false,
        ])->assertRedirect(route('nodes.index'));

        $this->assertDatabaseHas('proxmox_targets', [
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_node' => 'pve',
        ]);

        $this->assertSame('secret', ProxmoxTarget::firstOrFail()->proxmox_token_secret);
    }

    public function test_target_vmid_ranges_cannot_overlap(): void
    {
        $this->post(route('nodes.store'), [
            'name' => 'PVE 01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
            'max_total_vms' => 12,
            'template_vmid_range_start' => 100,
            'template_vmid_range_end' => 199,
            'runner_vmid_range_start' => 150,
            'runner_vmid_range_end' => 249,
        ])->assertSessionHasErrors('runner_vmid_range_start');

        $this->assertDatabaseCount('proxmox_targets', 0);
    }

    public function test_storage_options_use_unsaved_node_connection_details(): void
    {
        Http::fake([
            'https://pve.example.com:8006/api2/json/nodes/pve/storage*' => Http::response(['data' => [
                ['storage' => 'local', 'type' => 'dir', 'avail' => 10 * 1024 ** 3, 'enabled' => 1],
            ]]),
            'https://pve.example.com:8006/api2/json/cluster/resources*' => Http::response(['data' => []]),
        ]);

        $this->postJson(route('nodes.storage-options'), [
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ])->assertOk()->assertJsonPath('iso.0.name', 'local');

        $this->assertDatabaseCount('proxmox_targets', 0);
    }

    public function test_storage_path_reads_the_node_local_mount_path(): void
    {
        $target = ProxmoxTarget::create([
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);

        Http::fake([
            'https://pve.example.com:8006/api2/json/nodes/pve/storage/nassmb' => Http::response([
                'data' => ['path' => '/mnt/pve/nassmb'],
            ]),
        ]);

        $this->assertSame('/mnt/pve/nassmb', (new ProxmoxClient($target))->storagePath('nassmb'));
    }

    public function test_storage_path_uses_the_standard_mount_for_cifs_storage(): void
    {
        $target = ProxmoxTarget::create([
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);

        Http::fake([
            'https://pve.example.com:8006/api2/json/nodes/pve/storage/nassmb' => Http::response([
                'data' => ['type' => 'cifs'],
            ]),
        ]);

        $this->assertSame('/mnt/pve/nassmb', (new ProxmoxClient($target))->storagePath('nassmb'));
    }

    public function test_storage_path_falls_back_to_the_node_storage_list_when_detail_omits_type(): void
    {
        $target = ProxmoxTarget::create([
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);

        Http::fake([
            'https://pve.example.com:8006/api2/json/nodes/pve/storage/nassmb' => Http::response(['data' => []]),
            'https://pve.example.com:8006/api2/json/nodes/pve/storage*' => Http::response([
                'data' => [['storage' => 'nassmb', 'type' => 'cifs']],
            ]),
        ]);

        $this->assertSame('/mnt/pve/nassmb', (new ProxmoxClient($target))->storagePath('nassmb'));
    }

    public function test_node_connection_can_be_tested_from_the_nodes_page(): void
    {
        $target = ProxmoxTarget::create([
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);

        Http::fake([
            'https://pve.example.com:8006/api2/json/cluster/resources*' => Http::response(['data' => []]),
        ]);

        $this->post(route('nodes.test', $target))
            ->assertRedirect()
            ->assertSessionHas('success', 'Proxmox node PVE 01 is reachable (0 VMs visible).');
    }

    public function test_decommissioned_node_with_reaping_runners_can_be_deleted(): void
    {
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
            'name' => 'Decommissioned PVE',
            'slug' => 'decommissioned-pve',
            'proxmox_url' => 'https://retired.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);
        $runner = Runner::create([
            'environment_id' => $environment->id,
            'proxmox_target_id' => $target->id,
            'vmid' => 901,
            'runner_name' => 'stranded-runner',
            'state' => RunnerState::Reaping,
            'state_changed_at' => now(),
        ]);

        $this->delete(route('nodes.destroy', $target))
            ->assertRedirect(route('nodes.index'))
            ->assertSessionHas('success', 'Proxmox target deleted.');

        $this->assertDatabaseMissing('runners', ['id' => $runner->id]);
        $this->assertDatabaseMissing('proxmox_targets', ['id' => $target->id]);
    }
}
