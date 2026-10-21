<?php

namespace davidhellmann\primer\tests\integration;

use Craft;
use craft\enums\CmsEdition;
use davidhellmann\primer\Plugin;
use davidhellmann\primer\tests\Support\IntegrationTester;

class InstallCest
{
    public function primerIsInstalledInTheSoloFixture(IntegrationTester $I): void
    {
        $I->assertSame(CmsEdition::Solo, Craft::$app->edition);
        $I->assertCount(1, Craft::$app->getSites()->getAllSites());
        $I->assertTrue(Craft::$app->getPlugins()->isPluginInstalled('primer'));
        $I->assertInstanceOf(Plugin::class, Craft::$app->getPlugins()->getPlugin('primer'));
    }

    public function primerUninstallsAndInstallsAgain(IntegrationTester $I): void
    {
        $plugins = Craft::$app->getPlugins();

        $I->assertTrue($plugins->uninstallPlugin('primer'));
        $I->assertFalse($plugins->isPluginInstalled('primer'));
        $I->assertNull(Craft::$app->getProjectConfig()->get('plugins.primer'));

        $I->assertTrue($plugins->installPlugin('primer'));
        $I->assertTrue($plugins->isPluginInstalled('primer'));

        // Uninstalling drops Primer's table and installing creates it, and MySQL commits at each such statement,
        // so the rollback after this test can't restore the database. Craft writes project config changes at the
        // end of a request, which a test never reaches; write them now, or the tests after this one find Primer's
        // row but not its project config, and Craft doesn't load it.
        Craft::$app->getProjectConfig()->flush();
    }
}
