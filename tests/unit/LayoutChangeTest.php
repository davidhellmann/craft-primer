<?php

namespace davidhellmann\primer\tests\unit;

use Codeception\Test\Unit;
use davidhellmann\primer\decisions\LayoutChange;

/**
 * Which fields' values presets forget when a field layout is saved: those of every layout element that left it.
 */
class LayoutChangeTest extends Unit
{
    public function testAFieldThatLeftTheLayoutIsForgotten(): void
    {
        $this->assertSame(['writers'], LayoutChange::leftFields(
            ['e1' => 'topics', 'e2' => 'writers'],
            ['e1' => 'topics'],
        ));
    }

    public function testAFieldRemovedAndAddedBackInOneSaveIsForgotten(): void
    {
        // Same field, new layout element.
        $this->assertSame(['writers'], LayoutChange::leftFields(
            ['e1' => 'topics', 'e2' => 'writers'],
            ['e1' => 'topics', 'e3' => 'writers'],
        ));
    }

    public function testRemovingOneOfTwoInstancesForgetsTheField(): void
    {
        $this->assertSame(['writers'], LayoutChange::leftFields(
            ['e1' => 'writers', 'e2' => 'writers'],
            ['e2' => 'writers'],
        ));
    }

    public function testALayoutElementThatNowHoldsAnotherFieldForgetsTheOldOne(): void
    {
        $this->assertSame(['writers'], LayoutChange::leftFields(['e1' => 'writers'], ['e1' => 'editors']));
    }

    public function testAnAddedOrMovedFieldForgetsNothing(): void
    {
        $this->assertSame([], LayoutChange::leftFields(
            ['e1' => 'topics', 'e2' => 'writers'],
            ['e2' => 'writers', 'e1' => 'topics', 'e3' => 'heroImage'],
        ));
    }

    public function testEachFieldIsNamedOnce(): void
    {
        $this->assertSame(['writers'], LayoutChange::leftFields(['e1' => 'writers', 'e2' => 'writers'], []));
    }
}
