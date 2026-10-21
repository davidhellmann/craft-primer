<?php

namespace davidhellmann\primer\tests\Support\Helper;

use Codeception\Module;
use Codeception\TestInterface;
use Craft;
use craft\helpers\Cp;
use craft\web\Application;
use ReflectionProperty;
use yii\base\Event;

/**
 * Starts each request of a test without what the previous one left behind, as a new PHP process would.
 *
 * The test client handles every request with the same app, so two things outlive a request:
 * - `Cp::requestedSite()` keeps the first site it resolves in a static property, so `?site=` would stick;
 * - Craft's URL manager merges the previous route's params over the new ones, so a redirect from
 *   `primer/presets/5` to `primer/presets/new` would still get `presetId` 5.
 */
class FreshRequest extends Module
{
    public function _before(TestInterface $test): void
    {
        $this->forget();
        // Craft's module calls Event::offAll() after each test, so the handler is added again for each one.
        Event::on(Application::class, Application::EVENT_BEFORE_REQUEST, fn() => $this->forget());
    }

    private function forget(): void
    {
        (new ReflectionProperty(Cp::class, '_requestedSite'))->setValue(null, null);

        if (Craft::$app instanceof Application) {
            Craft::$app->getUrlManager()->setRouteParams([], false);
        }
    }
}
