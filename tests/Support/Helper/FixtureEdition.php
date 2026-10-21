<?php

namespace davidhellmann\primer\tests\Support\Helper;

use Codeception\Module;
use Codeception\TestInterface;
use Craft;
use craft\enums\CmsEdition;

/**
 * Runs each test in the edition its Project Config fixture names.
 *
 * Craft's test module switches to Pro after installing, and Craft reads the edition only when the
 * app starts, so a Solo fixture would otherwise run as Pro. Enable this module after `\craft\test\Craft`.
 */
class FixtureEdition extends Module
{
    public function _before(TestInterface $test): void
    {
        $handle = Craft::$app->getProjectConfig()->get('system.edition');
        Craft::$app->edition = CmsEdition::fromHandle(is_string($handle) ? $handle : 'solo');
    }
}
