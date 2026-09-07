<?php

namespace App\Console\Commands\Templates;

use App\Services\Templates\TemplateDownloadService;
use Illuminate\Console\Command;

/**
 * Removes cached template bundles that are no longer referenced.
 */
class TemplatesPruneBundlesCommand extends Command
{
    protected $signature = 'templates:prune-bundles';

    protected $description = 'Delete downloaded template bundles beyond the configured retention.';

    /** Remove obsolete cached template bundles. */
    public function handle(TemplateDownloadService $downloader): int
    {
        $downloader->prune();

        $this->components->info('Pruned superseded template bundles.');

        return self::SUCCESS;
    }
}
