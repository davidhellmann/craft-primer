<?php

namespace davidhellmann\primer\tests\Support\Helper;

use Codeception\Module;
use Codeception\TestInterface;
use craft\helpers\Cp;
use craft\web\Controller;
use ReflectionProperty;
use yii\base\Event;

/**
 * Lets every CP request of a test pick its own `?site=`.
 *
 * `Cp::requestedSite()` keeps the first site it resolves in a static property, which lasts a PHP process: one
 * request in production, but every request of the whole suite here. This forgets it before each controller action.
 */
class RequestedSite extends Module
{
    public function _before(TestInterface $test): void
    {
        $this->forget();
        // Craft's module calls Event::offAll() after each test, so the handler is added again for each one.
        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, fn() => $this->forget());
    }

    private function forget(): void
    {
        (new ReflectionProperty(Cp::class, '_requestedSite'))->setValue(null, null);
    }
}
