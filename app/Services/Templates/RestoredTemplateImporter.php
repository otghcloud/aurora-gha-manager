<?php

namespace App\Services\Templates;

use App\Enums\BuildStatus;
use App\Enums\RunnerState;
use App\Models\Builds\ImageBuild;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Models\Runners\Runner;
use App\Models\Templates\RetiredTemplateVmid;
use App\Models\Templates\RunnerTemplate;
use App\Models\Templates\RunnerTemplateTarget;
use App\Services\Proxmox\ProxmoxClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Registers an already-restored VM without modifying anything on Proxmox. */
class RestoredTemplateImporter
{
    public function register(
        RunnerTemplate $template,
        ProxmoxTarget $target,
        string $storage,
        int $vmid,
        ?string $version = null,
        ?int $userId = null,
    ): void {
        $this->validateRange($target, $vmid);

        if ($template->credential === null
            || $template->credential->os !== $template->os
            || ! $template->credential->hasAuthenticationMaterial()) {
            throw ValidationException::withMessages([
                'runner_template_id' => 'Configure a usable runner credential for this template before importing.',
            ]);
        }

        $proxmox = new ProxmoxClient($target);
        $vm = $proxmox->clusterVms()[$vmid] ?? null;

        if ($vm === null || ($vm['node'] ?? null) !== $target->proxmox_node || ($vm['type'] ?? null) !== 'qemu') {
            throw ValidationException::withMessages([
                'template_vmid' => 'That QEMU VMID was not found on the selected node.',
            ]);
        }

        $config = $proxmox->config($vmid);

        if ((int) ($config['template'] ?? 0) !== 1 || ($vm['status'] ?? null) !== 'stopped' || filled($config['lock'] ?? null)) {
            throw ValidationException::withMessages([
                'template_vmid' => 'The restored VM must be a stopped, unlocked Proxmox template.',
            ]);
        }

        $availableStorage = collect($proxmox->storages('images'))->contains(fn (array $row): bool => ($row['storage'] ?? null) === $storage
            && (bool) ($row['enabled'] ?? true) && (bool) ($row['active'] ?? true));
        $hasDisk = collect($config)->contains(fn ($disk, $key): bool => preg_match('/^(?:scsi|sata|virtio|ide)\d+$/D', $key) === 1
            && is_string($disk) && str_starts_with($disk, $storage.':') && ! str_contains($disk, 'media=cdrom'));

        if (! $availableStorage || ! $hasDisk) {
            throw ValidationException::withMessages([
                'source_storage' => 'Select an active image storage containing an attached template disk, not backup media or a cloud-init/CD-ROM drive.',
            ]);
        }

        DB::transaction(function () use ($template, $target, $storage, $vmid, $version): void {
            $lockedTarget = ProxmoxTarget::query()->lockForUpdate()->findOrFail($target->id);
            $this->validateRange($lockedTarget, $vmid);

            $mappings = RunnerTemplateTarget::where('proxmox_target_id', $target->id);
            $mapping = (clone $mappings)->where('runner_template_id', $template->id)->first();
            $mappedVmid = (clone $mappings)->where('template_vmid', $vmid)->exists();
            $retired = RetiredTemplateVmid::where('proxmox_target_id', $target->id)
                ->where('vmid', $vmid)->whereNull('deleted_at')->exists();
            $runner = Runner::where('proxmox_target_id', $target->id)
                ->where('vmid', $vmid)->whereNot('state', RunnerState::Destroyed->value)->exists();
            $building = ImageBuild::where('proxmox_target_id', $target->id)
                ->whereIn('status', [BuildStatus::Queued->value, BuildStatus::Running->value])
                ->where(fn ($query) => $query->where('runner_template_id', $template->id)->orWhere('template_vmid', $vmid))
                ->exists();

            if ($mapping?->template_vmid !== null || $mappedVmid || $retired || $runner || $building) {
                throw ValidationException::withMessages([
                    'template_vmid' => 'Import cannot replace an existing physical mapping, reuse a reserved/retired VMID, or run while this template has a queued/running build on this node.',
                ]);
            }

            $template->targetMappings()->syncWithoutDetaching([$target->id => [
                'template_vmid' => $vmid,
                'generation' => ($mapping?->generation ?? 0) + 1,
                'version' => $version,
                'availability_status' => 'available',
                'last_built_at' => null,
                'imported_storage' => $storage,
                'imported_at' => now(),
            ]]);
        });

        Log::info('Restored Proxmox template imported', [
            'template' => $template->id,
            'target' => $target->id,
            'vmid' => $vmid,
            'storage' => $storage,
            'version' => $version,
            'user' => $userId,
        ]);
    }

    private function validateRange(ProxmoxTarget $target, int $vmid): void
    {
        if ($target->template_vmid_range_start === null || $target->template_vmid_range_end === null
            || $vmid < $target->template_vmid_range_start || $vmid > $target->template_vmid_range_end) {
            throw ValidationException::withMessages([
                'template_vmid' => 'Restore the template inside this node\'s configured template VMID range, or update the node range first.',
            ]);
        }
    }
}
