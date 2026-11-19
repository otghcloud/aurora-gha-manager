<?php

namespace Tests\Feature;

use App\Contracts\Builds\BuildResult;
use App\Enums\BuildStatus;
use App\Enums\PoolOs;
use App\Enums\RunnerState;
use App\Jobs\BuildImageJob;
use App\Models\Builds\ImageBuild;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Pools\Pool;
use App\Models\Runners\Runner;
use App\Models\Templates\RetiredTemplateVmid;
use App\Models\Templates\RunnerTemplate;
use App\Services\Builds\ImageBuildFinalizer;
use App\Services\Builds\TemplateCatalogEntry;
use App\Services\Builds\TemplateRebuilder;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use OTGH\GHARM\Packer\PackerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class TemplateRebuildTest extends TestCase
{
    use RefreshDatabase;

    private Environment $environment;

    private ProxmoxTarget $target;

    private RunnerTemplate $template;

    private TemplateCatalogEntry $catalogEntry;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsRepository::class)->set('installed_at', now()->toIso8601String());

        $account = GitHubAccount::create([
            'account_type' => 'organization',
            'login' => 'otghcloud',
            'github_token' => 'token',
            'github_webhook_secret' => 'secret',
        ]);

        $this->environment = Environment::create([
            'name' => 'Production',
            'slug' => 'production',
            'github_account_id' => $account->id,
        ]);

        $this->target = ProxmoxTarget::create([
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!token',
            'proxmox_token_secret' => 'secret',
            'health_status' => 'healthy',
            'template_vmid_range_start' => 801,
            'template_vmid_range_end' => 899,
        ]);

        $this->template = RunnerTemplate::create([
            'environment_id' => $this->environment->id,
            'name' => 'ubuntu-slim',
            'os' => PoolOs::Linux,
            'template_catalog_id' => 'ubuntu-24.04-proxmox-x64',
        ]);

        $this->catalogEntry = new TemplateCatalogEntry([
            'id' => $this->template->template_catalog_id,
            'builders' => ['packer' => ['type' => 'packer']],
        ]);

        $this->template->targetMappings()->attach($this->target->id, [
            'template_vmid' => 801,
            'generation' => 1,
            'availability_status' => 'available',
        ]);
    }

    public function test_promoting_a_build_swaps_the_vmid_and_retires_the_old_one(): void
    {
        $this->template->targetMappings()->updateExistingPivot($this->target->id, [
            'imported_storage' => 'shared', 'imported_at' => now(),
        ]);
        $build = $this->makeBuild(802);

        app(TemplateRebuilder::class)->promote($build, 802);

        $mapping = $this->template->fresh()->targetMappings()->firstOrFail()->pivot;

        $this->assertSame(802, $mapping->template_vmid);
        $this->assertSame(2, $mapping->generation);
        $this->assertNull($mapping->imported_at);
        $this->assertNull($mapping->imported_storage);
        $this->assertNotNull($mapping->last_built_at);

        $retired = RetiredTemplateVmid::firstOrFail();
        $this->assertSame(801, $retired->vmid);
        $this->assertNull($retired->deleted_at);
    }

    public function test_a_first_build_retires_nothing(): void
    {
        $this->template->targetMappings()->updateExistingPivot($this->target->id, [
            'template_vmid' => null,
            'generation' => 0,
        ]);

        app(TemplateRebuilder::class)->promote($this->makeBuild(805), 805);

        $this->assertSame(805, $this->template->fresh()->targetMappings()->firstOrFail()->pivot->template_vmid);
        $this->assertSame(0, RetiredTemplateVmid::count());
    }

    #[DataProvider('templateNoteBuilds')]
    public function test_successful_build_notes_include_the_recorded_version_and_utc_provenance(?string $builder, ?string $version): void
    {
        $this->travelTo(now()->utc()->setDate(2026, 11, 19)->setTime(22, 30));
        $build = $this->makeBuild(805);
        $build->update(['builder_type' => $builder, 'version' => $version, 'started_at' => now()->subHour()]);
        $existing = "Packer-generated description.\n\nOperator notes: preserve these.";
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::response(['data' => $request->method() === 'GET'
            ? ['description' => $existing, 'digest' => 'current-config-digest']
            : null]));

        app(ImageBuildFinalizer::class)->complete($build, new BuildResult(true, 0, 806));

        Http::assertSent(function (Request $request) use ($existing, $build, $builder, $version): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $this->assertStringEndsWith('/qemu/806/config', $request->url());
            $this->assertSame(['description', 'digest'], array_keys($request->data()));
            $this->assertSame('current-config-digest', $request['digest']);
            $this->assertStringStartsWith($existing."\n\n", $request['description']);
            $this->assertSame(1, preg_match('/\n(    \{.*\n    \})\n/s', $request['description'], $matches));
            $metadata = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([
                'managed_by' => 'pmx-gha-manager',
                'template' => 'ubuntu-slim',
                'catalog_id' => 'ubuntu-24.04-proxmox-x64',
                'image_version' => $version ?? 'unknown',
                'builder' => $builder ?? 'unknown',
                'build_id' => $build->id,
                'node' => 'pve',
                'template_vmid' => 806,
                'started_at' => '2026-11-19T21:30:00+00:00',
                'built_at' => '2026-11-19T22:30:00+00:00',
            ], $metadata);
            $this->assertStringNotContainsString('secret', $request['description']);

            return true;
        });
        $this->assertSame(BuildStatus::Succeeded, $build->fresh()->status);
        $this->assertSame(806, $this->template->targetMappings()->firstOrFail()->pivot->template_vmid);

        app(ImageBuildFinalizer::class)->complete($build, new BuildResult(true, 0, 806));
        Http::assertSentCount(2);
    }

    public static function templateNoteBuilds(): array
    {
        return [
            'Packer' => ['packer', '2026.11.19.1'],
            'Cloud Image' => ['cloudimage', '2026.11.19.2'],
            'legacy unknown version' => [null, null],
        ];
    }

    public function test_build_notes_replace_only_the_managed_block_and_preserve_surrounding_notes(): void
    {
        $build = $this->makeBuild(805);
        $build->update(['version' => 'new-version']);
        $prefix = "Operator prefix.\n\n";
        $suffix = "\n\nOperator suffix.";
        $existing = $prefix."<!-- aurora-gharm-build:start -->\nOld version\n<!-- aurora-gharm-build:end -->".$suffix;
        $this->template->update(['name' => 'Ubuntu $1 \literal']);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::response(['data' => $request->method() === 'GET'
            ? ['description' => $existing]
            : null]));

        app(ImageBuildFinalizer::class)->complete($build, new BuildResult(true, 0, 805));

        Http::assertSent(function (Request $request) use ($prefix, $suffix): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $notes = $request['description'];
            $this->assertSame(['description'], array_keys($request->data()));
            $this->assertStringStartsWith($prefix, $notes);
            $this->assertStringEndsWith($suffix, $notes);
            $this->assertStringContainsString('new-version', $notes);
            $this->assertStringContainsString('Ubuntu $1 \\\\literal', $notes);
            $this->assertStringNotContainsString('Old version', $notes);
            $this->assertSame(1, substr_count($notes, '<!-- aurora-gharm-build:start -->'));

            return true;
        });
    }

    #[DataProvider('templateNoteFailures')]
    public function test_notes_failures_are_reported_without_discarding_the_image_or_stopping_the_batch(string $operation, int $status): void
    {
        Queue::fake();
        Log::spy();
        $build = $this->makeBuild(805, 'notes-batch', 0);
        $next = $this->makeBuild(806, 'notes-batch', 1);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($operation, $status) {
            if (($operation === 'read') === ($request->method() === 'GET')) {
                if ($status === 0) {
                    throw new ConnectionException('Test connection timeout');
                }

                return Http::response(['message' => 'Test notes update rejected'], $status);
            }

            return Http::response(['data' => ['description' => 'Existing notes', 'digest' => 'old-digest']]);
        });

        app(ImageBuildFinalizer::class)->complete($build, new BuildResult(true, 0, 805));

        $this->assertSame(BuildStatus::Succeeded, $build->fresh()->status);
        $this->assertSame(805, $this->template->targetMappings()->firstOrFail()->pivot->template_vmid);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Template built successfully but Proxmox notes could not be updated'
            && $context['build'] === $build->id && $context['vmid'] === 805 && $context['error'] !== '');
        Queue::assertPushed(BuildImageJob::class, fn (BuildImageJob $job): bool => $job->imageBuildId === $next->id);
    }

    public static function templateNoteFailures(): array
    {
        return [
            'read permission denied' => ['read', 403],
            'write permission denied' => ['write', 403],
            'concurrent configuration edit' => ['write', 409],
            'connection timeout' => ['write', 0],
        ];
    }

    public function test_failed_or_cancelled_builds_do_not_write_success_notes(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $failed = $this->makeBuild(805);
        app(ImageBuildFinalizer::class)->complete($failed, new BuildResult(false, 1));
        $cancelled = $this->makeBuild(806);
        $cancelled->update(['status' => BuildStatus::Cancelled]);
        app(ImageBuildFinalizer::class)->complete($cancelled, new BuildResult(true, 0, 806));

        Http::assertNothingSent();
        $this->assertSame(BuildStatus::Failed, $failed->fresh()->status);
        $this->assertSame(BuildStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(801, $this->template->targetMappings()->firstOrFail()->pivot->template_vmid);
    }

    public function test_a_sequential_batch_only_starts_the_next_node_when_one_succeeds(): void
    {
        Queue::fake();

        $first = $this->makeBuild(802, 'batch-1', 0);
        $second = $this->makeBuild(803, 'batch-1', 1);

        $first->forceFill(['status' => BuildStatus::Succeeded])->save();
        app(TemplateRebuilder::class)->advanceBatch($first);

        Queue::assertPushed(BuildImageJob::class, fn (BuildImageJob $job): bool => $job->imageBuildId === $second->id);
    }

    public function test_a_failed_build_cancels_the_rest_of_its_batch(): void
    {
        Queue::fake();

        $first = $this->makeBuild(802, 'batch-2', 0);
        $second = $this->makeBuild(803, 'batch-2', 1);

        $first->forceFill(['status' => BuildStatus::Failed])->save();
        app(TemplateRebuilder::class)->advanceBatch($first);

        Queue::assertNotPushed(BuildImageJob::class);
        $this->assertSame(BuildStatus::Cancelled, $second->fresh()->status);
    }

    public function test_pruning_leaves_a_retired_template_that_runners_still_use(): void
    {
        $this->retire(801);
        $this->makeRunnerFrom(801);

        $this->artisan('templates:prune')->assertExitCode(0);

        $this->assertNull(RetiredTemplateVmid::firstOrFail()->deleted_at);
    }

    public function test_keeping_generations_leaves_the_most_recent_retired_templates(): void
    {
        app(SettingsRepository::class)->setMany([
            SettingsRepository::TEMPLATE_RETENTION_MODE => SettingsRepository::RETENTION_KEEP_LAST_N,
            SettingsRepository::TEMPLATE_RETENTION_GENERATIONS => 2,
        ]);

        $this->retire(801, 1);
        $this->retire(802, 2);
        $this->retire(803, 3);

        // Proxmox is unreachable in tests, so the only prunable row fails to delete and stays put;
        // what matters is that the two newest generations are never even considered.
        $this->artisan('templates:prune')->assertExitCode(0);

        $this->assertSame(3, RetiredTemplateVmid::whereNull('deleted_at')->count());
    }

    public function test_the_template_vm_is_named_after_the_template(): void
    {
        $this->assertSame('tpl-ubuntu-slim', $this->template->vmName());
        $this->template->update(['name' => 'Ubuntu 24.04']);

        $this->assertSame('tpl-ubuntu-2404', $this->template->fresh()->vmName());
    }

    public function test_packer_is_given_the_allocated_vmid_and_template_name(): void
    {
        $this->target->update([
            'build_iso_storage' => 'local',
            'build_vm_storage' => 'local-lvm',
            'network_bridge' => 'vmbr9',
            'vlan_tag' => 42,
        ]);

        $this->template->targetMappings()->updateExistingPivot($this->target->id, [
            'build_iso_file' => 'local:iso/ubuntu.iso',
        ]);

        $build = $this->makeBuild(806);
        $build->load(['environment.githubAccount', 'runnerTemplate', 'proxmoxTarget']);

        $method = new ReflectionMethod(PackerBuilder::class, 'environmentVariables');
        $variables = $method->invoke(new PackerBuilder, $build, $this->catalogEntry);

        $this->assertSame('806', $variables['PKR_VAR_pmx_template_vmid']);
        $this->assertSame('tpl-ubuntu-slim', $variables['PKR_VAR_pmx_template_name']);
        $this->assertSame('vmbr9', $variables['PKR_VAR_pmx_network_bridge']);
        $this->assertSame('42', $variables['PKR_VAR_pmx_vlan_tag']);
        $this->assertSame('local', $variables['PKR_VAR_pmx_iso_storage']);
        $this->assertSame('local-lvm', $variables['PKR_VAR_pmx_vm_storage']);
        $this->assertSame('local:iso/ubuntu.iso', $variables['PKR_VAR_pmx_iso_file']);
    }

    public function test_a_node_without_a_vlan_does_not_pass_one_to_packer(): void
    {
        $this->target->update([
            'build_iso_storage' => 'local',
            'build_vm_storage' => 'local-lvm',
            'network_bridge' => 'vmbr0',
            'vlan_tag' => null,
        ]);

        $this->template->targetMappings()->updateExistingPivot($this->target->id, [
            'build_iso_file' => 'local:iso/ubuntu.iso',
        ]);

        $build = $this->makeBuild(806);
        $build->load(['environment.githubAccount', 'runnerTemplate', 'proxmoxTarget']);

        $method = new ReflectionMethod(PackerBuilder::class, 'environmentVariables');
        $variables = $method->invoke(new PackerBuilder, $build, $this->catalogEntry);

        // Proxmox rejects `tag=0`, so the variable has to be absent rather than zero.
        $this->assertArrayNotHasKey('PKR_VAR_pmx_vlan_tag', $variables);
        $this->assertSame('vmbr0', $variables['PKR_VAR_pmx_network_bridge']);
    }

    public function test_build_sizing_comes_from_the_node_mapping(): void
    {
        $this->target->update(['build_iso_storage' => 'local', 'build_vm_storage' => 'local-lvm']);

        $this->template->targetMappings()->updateExistingPivot($this->target->id, [
            'build_iso_file' => 'local:iso/ubuntu.iso',
            'build_cores' => 8,
            'build_memory_mb' => 16384,
            'build_disk_gb' => 200,
        ]);

        $build = $this->makeBuild(806);
        $build->load(['environment.githubAccount', 'runnerTemplate', 'proxmoxTarget']);

        $method = new ReflectionMethod(PackerBuilder::class, 'environmentVariables');
        $variables = $method->invoke(new PackerBuilder, $build, $this->catalogEntry);

        $this->assertSame('8', $variables['PKR_VAR_build_cpu_cores']);
        $this->assertSame('16384', $variables['PKR_VAR_build_memory_mb']);
        $this->assertSame('200', $variables['PKR_VAR_build_disk_gb']);
    }

    public function test_blank_build_sizing_is_left_to_the_packer_template(): void
    {
        $this->target->update(['build_iso_storage' => 'local', 'build_vm_storage' => 'local-lvm']);

        $this->template->targetMappings()->updateExistingPivot($this->target->id, [
            'build_iso_file' => 'local:iso/ubuntu.iso',
        ]);

        $build = $this->makeBuild(806);
        $build->load(['environment.githubAccount', 'runnerTemplate', 'proxmoxTarget']);

        $method = new ReflectionMethod(PackerBuilder::class, 'environmentVariables');
        $variables = $method->invoke(new PackerBuilder, $build, $this->catalogEntry);

        $this->assertArrayNotHasKey('PKR_VAR_build_cpu_cores', $variables);
        $this->assertArrayNotHasKey('PKR_VAR_build_memory_mb', $variables);
        $this->assertArrayNotHasKey('PKR_VAR_build_disk_gb', $variables);
    }

    public function test_a_failed_build_leaves_every_node_on_its_current_template(): void
    {
        Queue::fake();

        $other = ProxmoxTarget::create([
            'name' => 'PVE 02',
            'slug' => 'pve-02',
            'proxmox_url' => 'https://pve02.example.com:8006/api2/json',
            'proxmox_node' => 'pve02',
            'proxmox_token_id' => 'root@pam!token',
            'proxmox_token_secret' => 'secret',
            'template_vmid_range_start' => 801,
            'template_vmid_range_end' => 899,
        ]);

        $this->template->targetMappings()->attach($other->id, [
            'template_vmid' => 811,
            'generation' => 3,
            'availability_status' => 'available',
        ]);

        $first = $this->makeBuild(802, 'batch-fail', 0);
        $second = ImageBuild::create([
            'environment_id' => $this->environment->id,
            'runner_template_id' => $this->template->id,
            'proxmox_target_id' => $other->id,
            'template_catalog_id' => $this->template->template_catalog_id,
            'status' => BuildStatus::Queued,
            'template_vmid' => 812,
            'rebuild_batch_id' => 'batch-fail',
            'sequence' => 1,
        ]);

        $first->forceFill(['status' => BuildStatus::Failed])->save();
        app(TemplateRebuilder::class)->advanceBatch($first);

        $mappings = $this->template->fresh()->targetMappings()->get()->keyBy('id');

        $this->assertSame(801, $mappings[$this->target->id]->pivot->template_vmid);
        $this->assertSame(1, $mappings[$this->target->id]->pivot->generation);
        $this->assertSame(811, $mappings[$other->id]->pivot->template_vmid);
        $this->assertSame(3, $mappings[$other->id]->pivot->generation);
        $this->assertSame(BuildStatus::Cancelled, $second->fresh()->status);
        $this->assertSame(0, RetiredTemplateVmid::count());
    }

    private function makeBuild(int $vmid, ?string $batch = null, int $sequence = 0): ImageBuild
    {
        return ImageBuild::create([
            'environment_id' => $this->environment->id,
            'runner_template_id' => $this->template->id,
            'proxmox_target_id' => $this->target->id,
            'template_catalog_id' => $this->template->template_catalog_id,
            'status' => BuildStatus::Queued,
            'template_vmid' => $vmid,
            'rebuild_batch_id' => $batch,
            'sequence' => $sequence,
        ]);
    }

    private function retire(int $vmid, int $generation = 1): RetiredTemplateVmid
    {
        return RetiredTemplateVmid::create([
            'runner_template_id' => $this->template->id,
            'proxmox_target_id' => $this->target->id,
            'vmid' => $vmid,
            'generation' => $generation,
            'retired_at' => now(),
        ]);
    }

    private function makeRunnerFrom(int $templateVmid): Runner
    {
        $pool = Pool::create([
            'environment_id' => $this->environment->id,
            'runner_template_id' => $this->template->id,
            'name' => 'ubuntu-slim',
            'labels' => ['self-hosted'],
            'cores' => 2,
            'memory' => 2048,
            'boot_timeout_seconds' => 300,
        ]);

        return Runner::create([
            'environment_id' => $this->environment->id,
            'proxmox_target_id' => $this->target->id,
            'pool_id' => $pool->id,
            'vmid' => 901,
            'runner_name' => 'gha-ubuntu-slim-901-abc',
            'source_template_vmid' => $templateVmid,
            'state' => RunnerState::Idle,
            'state_changed_at' => now(),
        ]);
    }
}
