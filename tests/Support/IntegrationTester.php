<?php

namespace davidhellmann\primer\tests\Support;

use Codeception\Actor;

/**
 * The actor of the `integration` suite: Craft's module plus Asserts.
 */
class IntegrationTester extends Actor
{
    use _generated\IntegrationTesterActions;
}
