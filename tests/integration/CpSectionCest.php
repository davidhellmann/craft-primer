<?php

namespace davidhellmann\primer\tests\integration;

use Craft;
use craft\elements\User;
use craft\enums\CmsEdition;
use davidhellmann\primer\tests\Support\IntegrationTester;

class CpSectionCest
{
    public function theNavShowsPrimerAndOpensAnEmptyIndex(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());

        $I->amOnPage('/admin/dashboard');
        $I->seeResponseCodeIs(200);
        $I->seeLink('Primer', '?p=admin/primer');

        // The connector treats Craft's absolute URLs as external, so follow the link by path.
        $I->amOnPage('/admin/primer');
        $I->seeResponseCodeIs(200);
        $I->see('Primer', 'h1');
    }

    public function aUserWithAccessPrimerSeesTheSection(IntegrationTester $I): void
    {
        $I->amLoggedInAs($this->editor($I, ['accessCp', 'accessPlugin-primer']));

        $I->amOnPage('/admin/dashboard');
        $I->seeLink('Primer', '?p=admin/primer');

        $I->amOnPage('/admin/primer');
        $I->seeResponseCodeIs(200);
    }

    public function aUserWithoutAccessPrimerGetsA403(IntegrationTester $I): void
    {
        $I->amLoggedInAs($this->editor($I, ['accessCp']));

        $I->amOnPage('/admin/dashboard');
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#nav-primer');

        $I->amOnPage('/admin/primer');
        $I->seeResponseCodeIs(403);
    }

    /**
     * A non-admin control panel user. Permissions only exist in Craft Pro; in Solo every user may do everything.
     *
     * @param string[] $permissions
     */
    private function editor(IntegrationTester $I, array $permissions): User
    {
        Craft::$app->setEdition(CmsEdition::Pro);

        $user = new User([
            'active' => true,
            'username' => 'editor',
            'email' => 'editor@example.com',
        ]);
        $I->saveElement($user);
        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);

        return $user;
    }
}
