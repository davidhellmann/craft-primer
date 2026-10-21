<?php

namespace davidhellmann\primer\tests\unit;

use Codeception\Test\Unit;
use davidhellmann\primer\decisions\Status;

/**
 * A preset's status after sites are switched, as Craft's status field switches an entry's, and what that field shows.
 */
class StatusTest extends Unit
{
    public function testASiteSwitchedOnSwitchesThePresetOn(): void
    {
        $this->assertSame(
            ['enabled' => true, 'sites' => [1 => false, 2 => true]],
            Status::switched(true, [1 => false, 2 => true], [1, 2]),
        );
        $this->assertTrue(Status::switched(false, [1 => true], [1])['enabled']);
    }

    public function testSitesSwitchedOffLeaveThePresetAsItWas(): void
    {
        $this->assertTrue(Status::switched(true, [1 => false, 2 => false], [1, 2])['enabled']);
        $this->assertSame(['enabled' => false, 'sites' => [1 => false]], Status::switched(false, [1 => false], [1]));
    }

    public function testNoSitesLeaveThePresetAsItWas(): void
    {
        $this->assertSame(['enabled' => true, 'sites' => []], Status::switched(true, [], [1, 2]));
        $this->assertFalse(Status::switched(false, [], [1])['enabled']);
    }

    public function testSitesOutsideTheScopeAreLeftOut(): void
    {
        $this->assertSame(['enabled' => true, 'sites' => [1 => true]], Status::switched(true, [1 => true, 3 => true], [1, 2]));
        $this->assertSame(['enabled' => false, 'sites' => [1 => false]], Status::switched(false, [3 => true], [1]));
    }

    public function testAPresetOffAsAWholeStaysOffInTheSitesNotSwitched(): void
    {
        // Switched off in a one-site scope, which then gained sites 2 and 3.
        $this->assertSame(
            ['enabled' => true, 'sites' => [2 => true, 1 => false, 3 => false]],
            Status::switched(false, [2 => true], [1, 2, 3]),
        );
    }

    public function testTheFieldShowsWhatIsSetOverWhatIsStored(): void
    {
        $this->assertSame(
            [1 => false, 2 => true],
            Status::shown(true, [1, 2], [1 => false, 2 => null], [1 => true, 2 => true]),
        );
    }

    public function testTheFieldShowsWhatIsStoredWhereNothingIsSet(): void
    {
        $this->assertSame([1 => true, 2 => false], Status::shown(true, [1, 2], [], [1 => true, 2 => false]));
    }

    public function testANewPresetShowsOnWhereNothingIsSet(): void
    {
        $this->assertSame([1 => true, 2 => false], Status::shown(true, [1, 2], [2 => false], []));
    }

    public function testAPresetThatIsntEnabledShowsOffEverywhere(): void
    {
        $this->assertSame([1 => false, 2 => false], Status::shown(false, [1, 2], [1 => true], [2 => true]));
    }

    public function testTheFieldShowsTheSitesInTheirOrder(): void
    {
        $this->assertSame([2, 1], array_keys(Status::shown(true, [2, 1], [], [])));
    }
}
