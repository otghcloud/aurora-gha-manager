<?php

namespace Tests\Feature;

use App\Enums\RunnerState;
use App\Exceptions\ProxmoxException;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Runners\Runner;
use App\Models\User;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->assertSee('Create node')
            ->assertSee('Template Storage')
            ->assertSee('Runner VM Storage');
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
        $timeouts = [
            'proxmox_api_timeout' => 180,
            'proxmox_import_timeout' => 7200,
            'proxmox_task_timeout' => 5400,
            'proxmox_shutdown_timeout' => 600,
            'cloudimage_guest_ip_timeout' => 900,
            'cloudimage_ssh_timeout' => 1200,
            'cloudimage_build_timeout' => 21600,
        ];
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
            'runner_vm_storage' => 'runner-ssd',
            'enabled' => true,
            'proxmox_verify_tls' => false,
            ...$timeouts,
        ])->assertRedirect(route('nodes.index'));

        $this->assertDatabaseHas('proxmox_targets', [
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_node' => 'pve',
            'runner_vm_storage' => 'runner-ssd',
            ...$timeouts,
        ]);

        $this->assertSame('secret', ProxmoxTarget::firstOrFail()->proxmox_token_secret);
    }

    public function test_node_timeouts_can_be_edited_and_are_shown_on_the_form(): void
    {
        $target = ProxmoxTarget::create([
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
            'proxmox_api_timeout' => 240,
        ]);
        $this->put(route('nodes.update', $target), [
            'name' => $target->name,
            'proxmox_url' => $target->proxmox_url,
            'proxmox_node' => $target->proxmox_node,
            'proxmox_token_id' => $target->proxmox_token_id,
            'max_total_vms' => 12,
            'template_vmid_range_start' => 801,
            'template_vmid_range_end' => 899,
            'runner_vmid_range_start' => 901,
            'runner_vmid_range_end' => 999,
            'proxmox_import_timeout' => 7200,
        ])->assertRedirect(route('nodes.index'))->assertSessionHasNoErrors();

        $this->assertSame(7200, $target->fresh()->proxmox_import_timeout);
        $this->assertSame(240, $target->fresh()->proxmox_api_timeout);
        $this->get(route('nodes.edit', $target))
            ->assertOk()
            ->assertSee('Cloud image disk import')
            ->assertSee('value="7200"', false);
    }

    public function test_node_timeouts_reject_unbounded_or_invalid_values(): void
    {
        $this->post(route('nodes.store'), [
            'proxmox_api_timeout' => 0,
            'proxmox_import_timeout' => 43201,
            'proxmox_task_timeout' => -1,
            'proxmox_shutdown_timeout' => null,
            'cloudimage_guest_ip_timeout' => 'invalid',
            'cloudimage_ssh_timeout' => 1.5,
            'cloudimage_build_timeout' => 43201,
        ])->assertSessionHasErrors(array_keys(ProxmoxTarget::TIMEOUT_DEFAULTS));
    }

    #[DataProvider('httpTimeoutCases')]
    public function test_http_timeouts_distinguish_disk_import_from_normal_requests(bool $passwordAuth, bool $custom): void
    {
        $target = new ProxmoxTarget([
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_auth_realm' => $passwordAuth ? 'password' : 'api_token',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
            'proxmox_username' => 'root@pam',
            'proxmox_password' => 'password',
            ...($custom ? ['proxmox_api_timeout' => 77, 'proxmox_import_timeout' => 2400, 'proxmox_shutdown_timeout' => 180] : []),
        ]);
        Http::fake(function (Request $request, array $options) use ($custom) {
            $import = $request->method() === 'PUT' && isset($request->data()['scsi0']);
            $this->assertSame($import ? ($custom ? 2400 : 3600) : ($custom ? 77 : 120), $options['timeout']);

            if (str_ends_with($request->url(), '/access/ticket')) {
                return Http::response(['data' => ['ticket' => 'ticket', 'CSRFPreventionToken' => 'csrf']]);
            }

            return Http::response(['data' => $request->method() === 'POST' ? 'UPID:test' : ['status' => 'stopped', 'exitstatus' => 'OK']]);
        });

        $client = new ProxmoxClient($target);
        $client->importCloudImage(102, 'hdd', '/images/ubuntu.img');
        $client->config(102);
        $client->shutdown(102);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/status/shutdown')
            && (int) $request['timeout'] === ($custom ? 180 : 300));
        $client->shutdown(102, 45);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/status/shutdown')
            && (int) $request['timeout'] === 45);
    }

    public static function httpTimeoutCases(): array
    {
        return [
            'token defaults' => [false, false],
            'token custom' => [false, true],
            'password defaults' => [true, false],
            'password custom' => [true, true],
        ];
    }

    public function test_task_polling_respects_the_node_timeout(): void
    {
        $client = new ProxmoxClient(new ProxmoxTarget([
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_task_timeout' => 1,
        ]));
        $polls = 0;
        Http::fake(function (Request $request) use (&$polls) {
            if ($request->method() === 'POST') {
                return Http::response(['data' => 'UPID:test']);
            }

            return Http::response(['data' => ['status' => ++$polls === 1 ? 'running' : 'stopped', 'exitstatus' => 'OK']]);
        });

        $this->expectException(ProxmoxException::class);
        $this->expectExceptionMessage('Timed out waiting for Proxmox task (shutdown 102)');
        $client->shutdown(102);
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
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(222, $options['timeout']);

            return Http::response(['data' => [
                ['storage' => 'local', 'type' => 'dir', 'avail' => 10 * 1024 ** 3, 'enabled' => 1],
            ]]);
        });

        $this->postJson(route('nodes.storage-options'), [
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
            'proxmox_api_timeout' => 222,
        ])->assertOk()->assertJsonPath('iso.0.name', 'local');

        $this->assertDatabaseCount('proxmox_targets', 0);
    }

    public function test_storage_path_reads_the_configured_local_directory_instead_of_assuming_a_default(): void
    {
        $target = ProxmoxTarget::create([
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://pve.example.com:8006/api2/json/storage/local' => Http::response([
                'data' => ['type' => 'dir', 'path' => '/srv/proxmox-images/'],
            ]),
            'https://pve.example.com:8006/api2/json/nodes/pve/storage/local' => Http::response([
                'data' => [['subdir' => 'content'], ['subdir' => 'status']],
            ]),
            'https://pve.example.com:8006/api2/json/nodes/pve/storage?*' => Http::response([
                'data' => [['storage' => 'local', 'type' => 'dir']],
            ]),
        ]);

        $this->assertSame('/srv/proxmox-images', (new ProxmoxClient($target))->storagePath('local'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/nodes/pve/storage/local'));
    }

    public function test_cloud_image_source_resolves_to_the_local_storage_filesystem_path(): void
    {
        $target = new ProxmoxTarget([
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://pve.example.com:8006/api2/json/storage/local' => Http::response([
                'data' => ['type' => 'dir', 'path' => '/var/lib/vz'],
            ]),
            'https://pve.example.com:8006/api2/json/nodes/pve/storage/local' => Http::response([
                'data' => [['subdir' => 'content'], ['subdir' => 'status']],
            ]),
            'https://pve.example.com:8006/api2/json/nodes/pve/storage?*' => Http::response([
                'data' => [['storage' => 'local', 'type' => 'dir', 'enabled' => 1]],
            ]),
            'https://pve.example.com:8006/api2/json/nodes/pve/storage/local/content?*' => Http::response([
                'data' => [['volid' => 'local:iso/ubuntu-cloud.img']],
            ]),
        ]);

        $this->assertSame(
            '/var/lib/vz/template/iso/ubuntu-cloud.img',
            (new ProxmoxClient($target))->downloadCloudImage('local', 'https://images.example.com/ubuntu-cloud.img'),
        );
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_storage_without_a_filesystem_path_is_rejected(): void
    {
        $target = new ProxmoxTarget([
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://pve.example.com:8006/api2/json/storage/local-lvm' => Http::response([
                'data' => ['type' => 'lvmthin'],
            ]),
        ]);

        $this->expectException(ProxmoxException::class);
        $this->expectExceptionMessage('Storage local-lvm does not expose a node-local filesystem path.');

        (new ProxmoxClient($target))->storagePath('local-lvm');
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
            'https://pve.example.com:8006/api2/json/storage/nassmb' => Http::response([
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
            'https://pve.example.com:8006/api2/json/storage/nassmb' => Http::response(['data' => []]),
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
