<?php

namespace davidhellmann\primer\tests\unit;

use Codeception\Test\Unit;
use davidhellmann\primer\decisions\Status;

/**
 * A preset's `enabled` after its sites' statuses are set, as Craft's status field sets an entry's.
 */
class StatusTest extends Unit
{
    public function testASiteSwitchedOnSwitchesThePresetOn(): void
    {
        $this->assertTrue(Status::enabled(false, [1 => false, 2 => true]));
    }

    public function testSitesSwitchedOffLeaveThePresetAsItWas(): void
    {
        $this->assertTrue(Status::enabled(true, [1 => false, 2 => false]));
        $this->assertFalse(Status::enabled(false, [1 => false]));
    }

    public function testNoSitesLeaveThePresetAsItWas(): void
    {
        $this->assertTrue(Status::enabled(true, []));
        $this->assertFalse(Status::enabled(false, []));
    }
}
