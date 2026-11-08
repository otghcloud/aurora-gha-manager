<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Exceptions\ProvisioningException;
use App\Models\Builds\ImageBuild;
use App\Models\Credentials\BuildCredential;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Templates\RunnerTemplate;
use App\Services\Builds\TemplateCatalogEntry;
use App\Services\Ssh\SshConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use OTGH\GHARM\CloudImage\CloudImageBuilder;
use OTGH\GHARM\CloudImage\CloudImageGuestBuildReconciler;
use ReflectionMethod;
use Tests\TestCase;

class CloudImageFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private ImageBuild $build;

    protected function setUp(): void
    {
        parent::setUp();

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
        ]);
    }

    public function test_reconciled_success_does_not_fail_or_access_the_stopped_guest(): void
    {
        $this->build->forceFill(['status' => BuildStatus::Succeeded])->save();

        $this->assertFalse($this->awaitResult());
    }

    public function test_failed_build_is_not_accepted_as_success(): void
    {
        $this->build->forceFill([
            'status' => BuildStatus::Failed,
            'guest_outcome' => 'succeeded',
        ])->save();

        $this->expectException(ProvisioningException::class);
        $this->awaitResult();
    }

    public function test_cancelled_build_is_not_accepted_as_success(): void
    {
        $this->build->forceFill([
            'status' => BuildStatus::Cancelled,
            'guest_outcome' => 'succeeded',
        ])->save();

        $this->expectException(ProvisioningException::class);
        $this->awaitResult();
    }

    public function test_callback_success_claims_finalization_before_returning(): void
    {
        $this->build->forceFill(['guest_outcome' => 'succeeded'])->save();

        $this->assertTrue($this->awaitResult());
        $this->assertNotNull($this->build->fresh()->guest_finalizing_at);
        $this->assertFalse(app(CloudImageGuestBuildReconciler::class)->claimFinalization($this->build));
    }

    public function test_finalization_can_only_be_claimed_once(): void
    {
        $reconciler = app(CloudImageGuestBuildReconciler::class);

        $this->assertTrue($reconciler->claimFinalization($this->build));
        $this->assertFalse($reconciler->claimFinalization($this->build));
    }

    public function test_stale_finalization_can_be_recovered(): void
    {
        $this->build->forceFill(['guest_finalizing_at' => now()->subMinutes(16)])->save();

        $this->assertTrue(app(CloudImageGuestBuildReconciler::class)->claimFinalization($this->build));
    }

    public function test_finished_build_cannot_be_claimed(): void
    {
        $this->build->forceFill(['status' => BuildStatus::Succeeded])->save();

        $this->assertFalse(app(CloudImageGuestBuildReconciler::class)->claimFinalization($this->build));
    }

    public function test_worker_waits_for_the_existing_finalizer_without_using_ssh(): void
    {
        $this->build->forceFill([
            'guest_outcome' => 'succeeded',
            'guest_finalizing_at' => now(),
        ])->save();
        $build = Mockery::mock(ImageBuild::class)->makePartial();
        $build->shouldReceive('getCasts')->andReturn($this->build->getCasts());
        $build->setRawAttributes($this->build->getAttributes(), true);
        $build->shouldReceive('refresh')->once()->andReturn($build);
        $build->shouldReceive('refresh')->once()->andReturnUsing(function () use ($build): ImageBuild {
            $this->build->forceFill(['status' => BuildStatus::Succeeded])->save();
            $build->setRawAttributes($this->build->getAttributes(), true);

            return $build;
        });

        $this->assertFalse($this->awaitResult(build: $build));
    }

    public function test_ssh_success_is_persisted_and_claimed_before_returning(): void
    {
        $ssh = Mockery::mock(SshConnection::class);
        $ssh->shouldReceive('run')->once()->andReturn('{"outcome":"succeeded","exit_code":0}');

        $this->assertTrue($this->awaitResult($ssh));
        $this->assertSame('succeeded', $this->build->fresh()->guest_outcome);
        $this->assertNotNull($this->build->fresh()->guest_finalizing_at);
    }

    public function test_ssh_failure_is_not_swallowed_as_a_connection_error(): void
    {
        $ssh = Mockery::mock(SshConnection::class);
        $ssh->shouldReceive('run')->once()->andReturn('{"outcome":"failed","error":"Stage failed"}');

        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('Stage failed');
        $this->awaitResult($ssh);
    }

    public function test_worker_error_after_concurrent_success_never_deletes_the_template(): void
    {
        $this->build->forceFill(['template_vmid' => 9000])->save();
        $this->build->proxmoxTarget->forceFill(['build_vm_storage' => 'local-lvm'])->save();
        $this->build->setRelation('credentialSnapshot', new BuildCredential([
            'username' => 'runner',
            'private_key' => 'test-private-key',
            'public_key' => 'test-public-key',
        ]));
        Log::spy();
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['data' => 'UPID:create']);
            }

            if ($request->method() === 'GET') {
                return Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]);
            }

            $this->build->fresh()->forceFill(['status' => BuildStatus::Succeeded])->save();

            throw new ProvisioningException('Worker lost connection after reconciliation');
        });

        $result = app(CloudImageBuilder::class)->build(
            $this->build,
            new TemplateCatalogEntry([
                'builders' => ['cloudimage' => ['artifact' => ['file' => '/images/ubuntu.img']]],
            ]),
            '/unused',
        );

        $this->assertTrue($result->successful);
        $this->assertSame(9000, $result->templateVmid);
        $this->assertSame(BuildStatus::Succeeded, $this->build->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        Log::shouldHaveReceived('warning')->once();
    }

    private function awaitResult(?SshConnection $ssh = null, ?ImageBuild $build = null): bool
    {
        $method = new ReflectionMethod(CloudImageBuilder::class, 'awaitGuestResult');

        return $method->invoke(
            app(CloudImageBuilder::class),
            $build ?? $this->build,
            $ssh ?? Mockery::mock(SshConnection::class),
            '/tmp/cloudimage-build',
        );
    }
}
