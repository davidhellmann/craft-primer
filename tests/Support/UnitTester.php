<?php

namespace davidhellmann\primer\tests\Support;

use Codeception\Actor;

/**
 * The actor of the `unit` suite: Asserts only, no Craft.
 */
class UnitTester extends Actor
{
    use _generated\UnitTesterActions;
}
