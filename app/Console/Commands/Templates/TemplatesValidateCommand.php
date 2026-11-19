<?php

namespace App\Console\Commands\Templates;

use App\Services\Templates\TemplateSourceService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Validates a template source directory or .tar.gz archive without importing it.
 */
class TemplatesValidateCommand extends Command
{
    protected $signature = 'templates:validate {path : Directory containing templates.json or a .tar.gz archive}';

    protected $description = 'Validate a template source catalog and its referenced files.';

    /** Validate a local catalog directory or archive. */
    public function handle(TemplateSourceService $sources): int
    {
        $path = $this->argument('path');

        try {
            $result = is_dir($path)
                ? $sources->validateDirectory($path)
                : $sources->validateArchiveFile($path);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Valid template bundle v{$result['version']} with {$result['templates']} template(s).");

        return self::SUCCESS;
    }
}
