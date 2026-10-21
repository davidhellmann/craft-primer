<?php

namespace davidhellmann\primer\tests\unit;

use Codeception\Test\Unit;
use davidhellmann\primer\decisions\FieldChoice;

/**
 * Which fields a preset's choice keeps, which hold no value, and which a save takes out of the choice in every scope.
 */
class FieldChoiceTest extends Unit
{
    /**
     * The entry type's relation fields, by layout element UID, in layout order.
     */
    private const LAYOUT = ['topics', 'hero', 'writers'];

    public function testAChoiceKeepsTheFieldsStillInTheLayout(): void
    {
        $this->assertSame(['writers', 'topics'], FieldChoice::kept(['writers', 'topics'], self::LAYOUT));
    }

    public function testAStaleUidIsDroppedFromTheChoice(): void
    {
        // The field left the entry type's layout; added back, it would have a new UID.
        $this->assertSame(['topics', 'writers'], FieldChoice::kept(['topics', 'gone', 'writers'], self::LAYOUT));
    }

    public function testWithoutAnyFieldInTheLayoutNothingIsKept(): void
    {
        $this->assertSame([], FieldChoice::kept(['topics', 'hero'], []));
    }

    public function testTheFieldsNotChosenHoldNoValue(): void
    {
        $this->assertSame(['hero'], FieldChoice::unchosen(self::LAYOUT, ['writers', 'topics']));
    }

    public function testAStaleUidInTheChoiceDoesntStandForAField(): void
    {
        $this->assertSame(['topics', 'hero', 'writers'], FieldChoice::unchosen(self::LAYOUT, ['gone']));
    }

    public function testAFieldTakenOutOfTheChoiceIsRemoved(): void
    {
        $this->assertSame(['hero'], FieldChoice::removed(['topics', 'hero'], ['topics'], self::LAYOUT));
    }

    public function testAddingAFieldRemovesNothing(): void
    {
        $this->assertSame([], FieldChoice::removed(['topics'], ['topics', 'writers'], self::LAYOUT));
    }

    public function testReorderingTheChoiceRemovesNothing(): void
    {
        $this->assertSame([], FieldChoice::removed(['topics', 'hero'], ['hero', 'topics'], self::LAYOUT));
    }

    public function testADroppedStaleUidIsNoRemovedField(): void
    {
        // It has no field left to empty.
        $this->assertSame([], FieldChoice::removed(['topics', 'gone'], ['topics'], self::LAYOUT));
    }

    public function testEmptyingTheChoiceRemovesEveryChosenField(): void
    {
        $this->assertSame(['topics', 'writers'], FieldChoice::removed(['topics', 'writers'], [], self::LAYOUT));
    }

    public function testANewPresetInAnotherScopeRemovesFromTheSharedChoice(): void
    {
        // The choice is the same in every scope: a new preset that leaves out a field the others have chosen
        // takes it out of theirs.
        $shared = ['topics', 'hero'];
        $posted = FieldChoice::kept(['topics'], self::LAYOUT);

        $this->assertSame(['hero'], FieldChoice::removed($shared, $posted, self::LAYOUT));
    }
}
