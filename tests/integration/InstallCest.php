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
    }
}
