<?php

namespace App\Services\Builds;

use App\Contracts\Builds\BuildResult;
use App\Enums\BuildStatus;
use App\Exceptions\ProxmoxException;
use App\Models\Builds\ImageBuild;
use App\Services\Proxmox\ProxmoxClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Applies a builder's terminal result to the generic image build lifecycle. */
class ImageBuildFinalizer
{
    public function __construct(private readonly TemplateRebuilder $rebuilder = new TemplateRebuilder) {}

    public function complete(ImageBuild $build, BuildResult $result): void
    {
        $build->refresh();

        if ($build->status->isFinished()) {
            return;
        }

        DB::transaction(function () use ($build, $result): void {
            $build->forceFill([
                'status' => $result->successful ? BuildStatus::Succeeded : BuildStatus::Failed,
                'exit_code' => $result->exitCode,
                'process_pid' => null,
                'finished_at' => now(),
            ])->save();

            if (! $result->successful) {
                Log::error('Image build failed', [
                    'build' => $build->id,
                    'template_catalog_id' => $build->template_catalog_id,
                    'exit_code' => $result->exitCode,
                    'guest_stage_id' => $build->guest_stage_id,
                    'guest_error' => $build->guest_error,
                ]);

                return;
            }

            try {
                $this->rebuilder->promote($build, $result->templateVmid ?? (int) $build->template_vmid);
            } catch (\Throwable $exception) {
                Log::error('Image build succeeded but the template record could not be updated', [
                    'build' => $build->id,
                    'template' => $build->runner_template_id,
                    'template_catalog_id' => $build->template_catalog_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });

        if ($result->successful) {
            try {
                $this->writeTemplateNotes($build, $result->templateVmid ?? (int) $build->template_vmid);
            } catch (ProxmoxException|ConnectionException $exception) {
                Log::warning('Template built successfully but Proxmox notes could not be updated', [
                    'build' => $build->id,
                    'target' => $build->proxmox_target_id,
                    'vmid' => $result->templateVmid ?? $build->template_vmid,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->rebuilder->advanceBatch($build->refresh());
    }

    private function writeTemplateNotes(ImageBuild $build, int $vmid): void
    {
        $target = $build->proxmoxTarget;
        $template = $build->runnerTemplate;

        if ($target === null || $template === null) {
            throw new ProxmoxException('Cannot write template notes without the template and Proxmox target.');
        }

        $proxmox = new ProxmoxClient($target);
        $config = $proxmox->config($vmid);
        $metadata = json_encode([
            'managed_by' => ProxmoxClient::MANAGED_BY,
            'template' => $template->name,
            'catalog_id' => $build->template_catalog_id,
            'image_version' => $build->version ?? 'unknown',
            'builder' => $build->builder_type ?? 'unknown',
            'build_id' => $build->id,
            'node' => $target->proxmox_node,
            'template_vmid' => $vmid,
            'started_at' => $build->started_at?->copy()->utc()->toIso8601String(),
            'built_at' => $build->finished_at->copy()->utc()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $block = "<!-- aurora-gharm-build:start -->\n### Aurora GHARM build\n\n"
            .implode("\n", array_map(fn (string $line): string => '    '.$line, explode("\n", $metadata)))
            ."\n<!-- aurora-gharm-build:end -->";
        $existing = (string) ($config['description'] ?? '');
        $description = preg_replace_callback(
            '/^<!-- aurora-gharm-build:start -->\R.*?^<!-- aurora-gharm-build:end -->/ms',
            fn (): string => $block,
            $existing,
            -1,
            $replaced,
        );

        if ($replaced === 0) {
            $description = ($existing === '' ? '' : $existing."\n\n").$block;
        }

        $proxmox->updateDescription($vmid, $description, $config['digest'] ?? null);
    }
}
