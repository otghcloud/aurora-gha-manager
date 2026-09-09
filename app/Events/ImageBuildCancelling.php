<?php

namespace App\Events;

use App\Models\Builds\ImageBuild;

/** Dispatched before an active image build is marked cancelled. */
class ImageBuildCancelling
{
    public function __construct(public readonly ImageBuild $build) {}
}
