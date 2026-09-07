<?php

namespace App\Console\Commands\Targets;

use App\Models\Infrastructure\ProxmoxTarget;
use App\Services\Health\HealthCheckService;
use Illuminate\Console\Command;

/**
 * Reports connectivity and health for configured Proxmox targets.
 */
class TargetsHealthCommand extends Command
{
    protected $signature = 'targets:health';

    protected $description = 'Check the health and capacity of every enabled Proxmox target';

    /** Run health checks for selected Proxmox targets. */
    public function handle(HealthCheckService $health): int
    {
        $failed = false;

        foreach (ProxmoxTarget::query()->where('enabled', true)->orderBy('name')->get() as $target) {
            $healthy = $health->checkTarget($target);
            $failed = $failed || ! $healthy;
            $target->refresh();

            $this->components->{ $healthy ? 'info' : 'error' }(
                "{$target->name}: {$target->health_status}, {$target->current_vm_count} VM(s) visible"
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
