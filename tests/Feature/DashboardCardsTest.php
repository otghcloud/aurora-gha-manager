<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\RunnerState;
use App\Livewire\DashboardCards;
use App\Models\Builds\ImageBuild;
use App\Models\GitHub\GitHubAccount;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Runners\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_cards_count_integer_backed_runner_states(): void
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

        Runner::create([
            'environment_id' => $environment->id,
            'proxmox_target_id' => $target->id,
            'vmid' => 901,
            'runner_name' => 'idle-a',
            'state' => RunnerState::Idle,
            'state_changed_at' => now(),
        ]);
        Runner::create([
            'environment_id' => $environment->id,
            'proxmox_target_id' => $target->id,
            'vmid' => 902,
            'runner_name' => 'idle-b',
            'state' => RunnerState::Idle,
            'state_changed_at' => now(),
        ]);
        Runner::create([
            'environment_id' => $environment->id,
            'proxmox_target_id' => $target->id,
            'vmid' => 903,
            'runner_name' => 'busy',
            'state' => RunnerState::Busy,
            'state_changed_at' => now(),
        ]);
        ImageBuild::create([
            'environment_id' => $environment->id,
            'proxmox_target_id' => $target->id,
            'template_catalog_id' => 'ubuntu-24.04',
            'status' => BuildStatus::Running,
        ]);

        $data = (new DashboardCards)->render()->getData();

        $this->assertSame(0, $data['stateCounts'][RunnerState::Spawning->name] ?? 0);
        $this->assertSame(2, $data['stateCounts'][RunnerState::Idle->name] ?? 0);
        $this->assertSame(1, $data['stateCounts'][RunnerState::Busy->name] ?? 0);
        $this->assertSame(1, $data['activeBuildsCount']);
    }
}
