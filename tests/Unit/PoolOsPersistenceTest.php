<?php

namespace Tests\Unit;

use App\Enums\PoolOs;
use App\Models\Credentials\BuildCredential;
use App\Models\Credentials\Credential;
use App\Models\Templates\RunnerTemplate;
use Tests\TestCase;

class PoolOsPersistenceTest extends TestCase
{
    public function test_macos_slug_is_normalised_by_model_boundaries(): void
    {
        $this->assertSame(PoolOs::MacOS, new RunnerTemplate(['os' => 'macos'])->os);
        $this->assertSame(PoolOs::MacOS, new Credential(['os' => 'macos'])->os);
        $this->assertSame(PoolOs::MacOS, new BuildCredential(['os' => 'macos'])->os);
    }
}
