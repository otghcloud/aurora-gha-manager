<?php

namespace Tests\Feature;

use App\DataTables\Runners\RecentRunnersDataTable;
use App\Enums\RunnerState;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Runners\Runner;
use App\Support\RunnerTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunnerHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner_history_includes_reaping_runners_waiting_for_cleanup(): void
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
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);

        $reaping = Runner::create([
            'environment_id' => $environment->id,
            'proxmox_target_id' => $target->id,
            'vmid' => 901,
            'runner_name' => 'reaping-runner',
            'state' => RunnerState::Reaping,
            'state_changed_at' => now(),
        ]);
        Runner::create([
            'environment_id' => $environment->id,
            'proxmox_target_id' => $target->id,
            'vmid' => 902,
            'runner_name' => 'idle-runner',
            'state' => RunnerState::Idle,
            'state_changed_at' => now(),
        ]);

        $history = app(RecentRunnersDataTable::class)->query(new Runner)->pluck('id');

        $this->assertTrue($history->contains($reaping->id));
    }

    public function test_runner_timeline_renders_enum_casted_event_states(): void
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
            'name' => 'PVE 01',
            'slug' => 'pve-01',
            'proxmox_url' => 'https://pve.example.com:8006/api2/json',
            'proxmox_node' => 'pve',
            'proxmox_token_id' => 'root@pam!runner',
            'proxmox_token_secret' => 'secret',
        ]);

        $runner = Runner::create([
            'environment_id' => $environment->id,
            'proxmox_target_id' => $target->id,
            'vmid' => 901,
            'runner_name' => 'busy-runner',
            'state' => RunnerState::Busy,
            'state_changed_at' => now(),
        ]);

        $runner->events()->create([
            'from_state' => RunnerState::Idle->value,
            'to_state' => RunnerState::Busy->value,
            'reason' => 'job started',
            'created_at' => now(),
        ]);

        $entry = RunnerTimeline::for($runner)->first();

        $this->assertSame('Busy', $entry['title']);
        $this->assertSame('Idle', $entry['from']);
    }
}
