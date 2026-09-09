<?php

namespace App\Services\Builds;

use App\Enums\BuildStatus;
use App\Exceptions\ProvisioningException;
use App\Models\Builds\ImageBuild;
use App\Models\Builds\LogEntry;
use App\Services\Proxmox\ProxmoxClient;
use Illuminate\Support\Facades\Log;

/**
 * Runs a registered image builder and persists its build output and status.
 */
class ImageBuilder
{
    public function __construct(
        private readonly ProxmoxClient $proxmox,
        private readonly TemplateRebuilder $rebuilder = new TemplateRebuilder,
        private readonly TemplateCatalog $catalog = new TemplateCatalog,
        private readonly ?BuilderRegistry $builders = null,
        private readonly ImageBuildFinalizer $finalizer = new ImageBuildFinalizer,
    ) {}

    /** @throws ProvisioningException When the builder cannot start or complete. */
    public function run(ImageBuild $build): void
    {
        $template = $build->runnerTemplate;
        $mapping = $template?->targetMappings()->whereKey($build->proxmox_target_id)->first();
        $entry = $this->catalog->entryForId($build->template_catalog_id, $build->builder_type);

        if ($template === null || $mapping === null || $entry === null) {
            throw new ProvisioningException('The build has no template attached.');
        }

        $logPath = $this->logPath($build);
        $templateDirectory = $this->catalog->templateDirectory($entry);

        if ($templateDirectory === null) {
            throw new ProvisioningException('No installed template matches the build catalog ID '.$build->template_catalog_id.'.');
        }

        $build->forceFill([
            'status' => BuildStatus::Running,
            'started_at' => now(),
            'log_path' => $logPath,
        ])->save();

        try {
            $result = $this->builderRegistry()
                ->forType($entry->builderType())
                ->build($build, $entry, $templateDirectory);
        } catch (\Throwable $e) {
            $this->recordFailure($build, $logPath, $e);

            throw $e;
        }

        $this->storeLog($build, $logPath);

        // A force kill already finalised the record; the non-zero exit is the kill, not a build failure.
        if ($build->fresh()?->status === BuildStatus::Cancelled) {
            $this->rebuilder->advanceBatch($build->refresh());

            return;
        }

        $this->finalizer->complete($build, $result);
    }

    private function builderRegistry(): BuilderRegistry
    {
        return $this->builders ?: app(BuilderRegistry::class);
    }

    /**
     * Persists a copy of the build log to the database, since the on-disk file is not guaranteed
     * to survive (e.g. container restarts, log pruning).
     */
    private function storeLog(ImageBuild $build, string $logPath): void
    {
        if (! is_readable($logPath)) {
            return;
        }

        $contents = file_get_contents($logPath);

        if ($contents === false) {
            return;
        }

        LogEntry::store($build, LogEntry::CHANNEL_BUILD, $contents);
    }

    private function recordFailure(ImageBuild $build, string $logPath, \Throwable $e): void
    {
        file_put_contents(
            $logPath,
            sprintf("\n==> Build failed before completion\n%s: %s\n", $e::class, $e->getMessage()),
            FILE_APPEND,
        );

        $this->storeLog($build, $logPath);
    }

    /** Return the filesystem path used for the build log. */
    public function logPath(ImageBuild $build): string
    {
        $directory = config('builds.log_directory');

        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        return $directory.'/build-'.$build->id.'.log';
    }

    public static function isAvailable(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        $path = rtrim(config('builds.image_builder_path'), '/');

        return is_dir($path) && is_file($path.'/templates.json');
    }
}
