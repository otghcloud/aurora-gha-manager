<?php

namespace App\Services\Builds;

use App\Contracts\Builds\BuildResult;
use App\Enums\BuildStatus;
use App\Models\Builds\ImageBuild;
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

        $this->rebuilder->advanceBatch($build->refresh());
    }
}
