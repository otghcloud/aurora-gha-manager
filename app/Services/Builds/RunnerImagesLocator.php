<?php

namespace App\Services\Builds;

use App\Exceptions\ProvisioningException;
use Symfony\Component\Process\Process;

/**
 * Locates the checked-out runner-images scripts needed by image builders.
 */
class RunnerImagesLocator
{
    private const FETCH_TIMEOUT_SECONDS = 1800;

    public function __construct(
        private readonly TemplateCatalog $catalog = new TemplateCatalog,
    ) {}

    /** @throws ProvisioningException When required runner-image scripts cannot be located. */
    public function scriptsRoot(TemplateCatalogEntry $template): ?string
    {
        if (! $template->requiresScriptsRoot()) {
            return null;
        }

        if (is_dir($this->bundledPath($template))) {
            return null;
        }

        return $this->fetchedPath($template);
    }

    /**
     * Like scriptsRoot(), but always resolves to an actual filesystem path when scripts are
     * required - including the bundled tree - for callers that read files from disk (e.g. the
     * cloudimage builder's SSH uploads) rather than just needing to know whether Packer's own
     * bundled-relative paths need overriding.
     */
    /** Return the local runner-images checkout root, when available. */
    public function filesystemRoot(TemplateCatalogEntry $template): ?string
    {
        if (! $template->requiresScriptsRoot()) {
            return null;
        }

        $bundled = $this->bundledPath($template);

        return is_dir($bundled) ? $bundled : $this->fetchedPath($template);
    }

    private function fetchedPath(TemplateCatalogEntry $template): string
    {
        $commit = $this->catalog->runnerImagesCommit();

        if ($commit === null) {
            throw new ProvisioningException('The installed templates bundle has no runner-images tree and records no runner_images_commit to fetch.');
        }

        $checkout = rtrim((string) config('builds.runner_images_path'), '/').'/'.$commit;
        $scripts = $checkout.'/'.$template->runnerImagesDirectory();

        if (! is_dir($scripts)) {
            $this->fetch($checkout, $commit, $template->runnerImagesDirectory());
        }

        if (! is_dir($scripts)) {
            throw new ProvisioningException('The runner-images checkout is missing '.$template->runnerImagesDirectory().'.');
        }

        return $scripts;
    }

    private function bundledPath(TemplateCatalogEntry $template): string
    {
        return $this->catalog->root().'/vendor/runner-images/'.$template->runnerImagesDirectory();
    }

    private function fetch(string $checkout, string $commit, string $directory): void
    {
        if (! is_dir($checkout) && ! mkdir($checkout, 0750, true) && ! is_dir($checkout)) {
            throw new ProvisioningException('The runner-images cache directory could not be created: '.$checkout);
        }

        $repository = (string) config('builds.runner_images_repository');
        $steps = [
            ['git', 'init', '-q'],
            ['git', 'config', 'remote.origin.url', $repository],
            ['git', 'sparse-checkout', 'set', '--no-cone', $directory],
            ['git', 'fetch', '-q', '--depth', '1', 'origin', $commit],
            ['git', 'checkout', '-q', 'FETCH_HEAD'],
        ];

        foreach ($steps as $step) {
            $process = new Process($step, $checkout, null, null, self::FETCH_TIMEOUT_SECONDS);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProvisioningException('Fetching runner-images at '.$commit.' failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
            }
        }
    }
}
