<?php

namespace davidhellmann\primer\tests\unit;

use Codeception\Test\Unit;
use davidhellmann\primer\decisions\Truncation;

/**
 * How many of a preset's elements fit the entry's field: the first ones, in preset order.
 */
class TruncationTest extends Unit
{
    public function testAFieldWithoutAMaximumTakesEveryElement(): void
    {
        $this->assertSame([7, 3, 5], Truncation::toMaximum([7, 3, 5], null));
    }

    public function testAMaximumKeepsTheFirstElementsInPresetOrder(): void
    {
        $this->assertSame([7, 3], Truncation::toMaximum([7, 3, 5], 2));
    }

    public function testAMaximumAboveTheCountKeepsEveryElement(): void
    {
        $this->assertSame([7, 3, 5], Truncation::toMaximum([7, 3, 5], 4));
    }

    public function testAMaximumOfZeroMeansNoLimit(): void
    {
        // Craft stores "no limit" as null, but an emptied number input can arrive as 0.
        $this->assertSame([7, 3, 5], Truncation::toMaximum([7, 3, 5], 0));
    }

    public function testNoElementsStayNone(): void
    {
        $this->assertSame([], Truncation::toMaximum([], 2));
    }
}
