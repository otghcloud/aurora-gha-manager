<?php

namespace App\Services\Templates;

use App\Enums\RunnerState;
use App\Models\Runners\Runner;
use App\Models\Templates\RetiredTemplateVmid;
use App\Services\Proxmox\ProxmoxClient;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Destroys template VMs superseded by a rebuild, for both the scheduled prune and manual purges.
 */
/**
 * Removes superseded templates once no active runner or build depends on them.
 */
class TemplatePruner
{
    /**
     * Prunes every superseded template that nothing is cloned from, honouring the retention setting.
     */
    /** @return int Number of retired template records removed. */
    public function pruneRetained(SettingsRepository $settings): int
    {
        $keep = $settings->templateRetentionMode() === SettingsRepository::RETENTION_KEEP_LAST_N
            ? $settings->templateRetentionGenerations()
            : 0;

        $pruned = 0;

        $retired = RetiredTemplateVmid::with('proxmoxTarget')
            ->whereNull('deleted_at')
            ->orderByDesc('generation')
            ->get()
            ->groupBy(fn (RetiredTemplateVmid $row): string => $row->runner_template_id.':'.$row->proxmox_target_id);

        foreach ($retired as $generations) {
            foreach ($generations->skip($keep) as $row) {
                if ($this->stillInUse($row)) {
                    continue;
                }

                $pruned += $this->purge($row) ? 1 : 0;
            }
        }

        return $pruned;
    }

    /**
     * Purges every superseded template for one runner template, ignoring the retention setting.
     *
     * @return array{purged: int, skipped: int}
     */
    /** @return array<int, int> Retired template IDs removed for the template. */
    public function purgeForTemplate(int $runnerTemplateId): array
    {
        $purged = 0;
        $skipped = 0;

        $retired = RetiredTemplateVmid::with('proxmoxTarget')
            ->where('runner_template_id', $runnerTemplateId)
            ->whereNull('deleted_at')
            ->get();

        foreach ($retired as $row) {
            if ($this->stillInUse($row)) {
                $skipped++;

                continue;
            }

            $this->purge($row) ? $purged++ : $skipped++;
        }

        return ['purged' => $purged, 'skipped' => $skipped];
    }

    /** Whether a retired VM is still referenced by a build or runner. */
    public function stillInUse(RetiredTemplateVmid $retired): bool
    {
        return Runner::where('proxmox_target_id', $retired->proxmox_target_id)
            ->where('source_template_vmid', $retired->vmid)
            ->whereNot('state', RunnerState::Destroyed->value)
            ->exists();
    }

    /** Purge a retired VM record and remote VM when safe. */
    public function purge(RetiredTemplateVmid $retired): bool
    {
        $proxmox = new ProxmoxClient($retired->proxmoxTarget);

        try {
            $proxmox->destroy($retired->vmid);
        } catch (Throwable $e) {
            // A VM removed outside the manager would otherwise leave a row that can never be
            // purged, so treat a confirmed absence as success rather than a failure.
            if (! $this->existsOnTarget($proxmox, $retired)) {
                Log::info('Superseded template was already gone from the host', [
                    'vmid' => $retired->vmid,
                    'node' => $retired->proxmoxTarget?->name,
                ]);

                $retired->forceFill(['deleted_at' => now()])->save();

                return true;
            }

            Log::warning('Could not destroy a superseded template', [
                'vmid' => $retired->vmid,
                'node' => $retired->proxmoxTarget?->name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $retired->forceFill(['deleted_at' => now()])->save();

        return true;
    }

    /**
     * Whether the VM is still on the cluster.
     *
     * Only a successful listing counts as proof: an unreachable API must not be read as "gone",
     * or a transient outage would quietly drop rows for VMs that still exist.
     */
    private function existsOnTarget(ProxmoxClient $proxmox, RetiredTemplateVmid $retired): bool
    {
        try {
            return array_key_exists($retired->vmid, $proxmox->clusterVms());
        } catch (Throwable $e) {
            Log::warning('Could not confirm whether a superseded template still exists', [
                'vmid' => $retired->vmid,
                'node' => $retired->proxmoxTarget?->name,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }
}
