<?php

namespace davidhellmann\primer\tests\Support\Helper;

use Codeception\Module;
use Codeception\TestInterface;
use Craft;
use craft\enums\CmsEdition;
use craft\test\TestSetup;
use ReflectionProperty;

/**
 * Runs each test against its suite's own Project Config fixture, in the edition the fixture names.
 *
 * Craft's test module switches to Pro after installing, and Craft reads the edition only when the
 * app starts, so a Solo fixture would otherwise run as Pro. Enable this module after `\craft\test\Craft`.
 */
class FixtureEdition extends Module
{
    /**
     * Craft's module resets the project config before each test to a seed it reads once per PHP process. With
     * two suites in one `codecept run`, the second would get the first suite's fixture back: forget the seed.
     */
    public function _beforeSuite(array $settings = []): void
    {
        (new ReflectionProperty(TestSetup::class, '_parsedProjectConfig'))->setValue(null, []);
    }

    public function _before(TestInterface $test): void
    {
        $handle = Craft::$app->getProjectConfig()->get('system.edition');
        Craft::$app->edition = CmsEdition::fromHandle(is_string($handle) ? $handle : 'solo');
    }
}
