<?php

namespace davidhellmann\primer\tests\integration;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\enums\CmsEdition;
use davidhellmann\primer\elements\Preset;
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

    public function uninstallingDeletesEveryPresetAndTheTable(IntegrationTester $I): void
    {
        // Only the admin to relate to: on MySQL, the rows this test writes are committed with the DDL below.
        $ids = [
            $I->preset('news', 'article', ['writers' => [$I->admin()->id]])->id,
            $I->preset('news', 'note')->id,
        ];

        $I->assertTrue(Craft::$app->getPlugins()->uninstallPlugin('primer'));

        $db = Craft::$app->getDb();
        $I->assertNull($db->getTableSchema(Preset::TABLE, true), 'Table');
        $I->assertFalse((new Query())->from(Table::ELEMENTS)->where(['or', ['id' => $ids], ['type' => Preset::class]])->exists(), 'Elements');
        $I->assertFalse((new Query())->from(Table::ELEMENTS_SITES)->where(['elementId' => $ids])->exists(), 'Element sites');
        $I->assertFalse((new Query())->from(Table::RELATIONS)->where(['sourceId' => $ids])->exists(), 'Relations');

        $I->assertTrue(Craft::$app->getPlugins()->installPlugin('primer'));
        // See primerUninstallsAndInstallsAgain.
        Craft::$app->getProjectConfig()->flush();
    }
}
