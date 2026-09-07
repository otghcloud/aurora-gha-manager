<?php

namespace App\Console\Commands\Templates;

use App\Services\Templates\TemplateDownloadService;
use Illuminate\Console\Command;

/**
 * Downloads and installs a selected template bundle.
 */
class TemplatesSyncBundleCommand extends Command
{
    protected $signature = 'templates:sync-bundle';

    protected $description = 'Use the image\'s bundled templates when they are newer than the pinned download.';

    /** Download and install the selected template bundle. */
    public function handle(TemplateDownloadService $downloader): int
    {
        $adopted = $downloader->adoptBundledIfNewer();

        if ($adopted === null) {
            $this->components->info('Template bundle is already current.');

            return self::SUCCESS;
        }

        $this->components->info("Activated the bundled template catalog ({$adopted}).");

        return self::SUCCESS;
    }
}
