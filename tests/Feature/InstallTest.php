<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\Support\Facades\Plugins;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\TestCase;
use Illuminate\Support\Facades\Schema;

/**
 * Installing Primer adds its table; uninstalling it takes the table and every preset along.
 */
class InstallTest extends TestCase
{
    public function testInstallingAddsThePresetsTable(): void
    {
        $this->assertTrue(Plugins::isPluginInstalled('primer'));
        $this->assertTrue(Schema::hasTable(Preset::TABLE));
    }
}
