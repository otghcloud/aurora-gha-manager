<?php

use App\Models\ImageBuild;

/**
 * Backward-compatible alias for integrations published before model namespaces were grouped.
 */
class_alias(App\Models\Builds\ImageBuild::class, ImageBuild::class);
