<?php

namespace Tests\Unit;

use App\Enums\PoolOs;
use App\Support\LabelPresets;
use Tests\TestCase;

class LabelPresetsTest extends TestCase
{
    public function test_presets_are_defined_for_every_pool_os(): void
    {
        foreach (PoolOs::cases() as $os) {
            $this->assertIsArray(LabelPresets::forOs($os));
        }
    }

    public function test_all_presets_include_every_pool_os_slug(): void
    {
        $presets = LabelPresets::all();

        foreach (PoolOs::cases() as $os) {
            $this->assertArrayHasKey($os->slug(), $presets);
        }
    }

    public function test_only_ubuntu_2604_claims_the_latest_ubuntu_label(): void
    {
        $owners = array_filter(
            LabelPresets::forOs(PoolOs::Linux),
            fn (array $labels): bool => in_array('ubuntu-latest', $labels, true),
        );

        $this->assertSame(['Ubuntu 26.04'], array_keys($owners));
        $this->assertContains('ubuntu-26.04', $owners['Ubuntu 26.04']);
    }
}
