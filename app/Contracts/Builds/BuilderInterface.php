<?php

namespace App\Contracts\Builds;

use App\Models\Builds\ImageBuild;
use App\Services\Builds\TemplateCatalogEntry;

/**
 * Contract implemented by image builder plugins.
 */
interface BuilderInterface
{
    public function type(): string;

    public function build(
        ImageBuild $build,
        TemplateCatalogEntry $entry,
        string $templateDirectory,
    ): BuildResult;
}
