<?php

namespace davidhellmann\primer\tests\integration;

use Craft;
use craft\elements\User;
use craft\enums\CmsEdition;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * Deleting a preset: from the ⋯ menu of its page, or with the × on the index and the section page.
 */
class DeletePresetCest
{
    public function _before(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
    }

    public function deletingFromAPageGoesToTheSectionPageWithTheMessage(IntegrationTester $I): void
    {
        $article = $I->preset('news', 'article');
        $note = $I->preset('news', 'note');

        // What `_nav` posts on a page: a form, not JSON.
        $I->sendFormPost('/admin/actions/primer/presets/delete', [
            'presetId' => $article->id,
            'siteId' => $article->siteId,
        ]);

        $I->seeCurrentUrlMatches('~admin/primer/sections/news$~');
        $I->seeElement('[data-primer-type="article"] a.btn.add');
        // Craft's flash message, rendered as a script that shows the notification.
        $I->seeInSource('Preset deleted.');
        $I->assertNull(Preset::find()->id($article->id)->one());
        $I->assertNotNull(Preset::find()->id($note->id)->one());
    }

    public function aPostedRedirectWins(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $I->sendFormPost('/admin/actions/primer/presets/delete', [
            'presetId' => $preset->id,
            'siteId' => $preset->siteId,
            'redirect' => Craft::$app->getSecurity()->hashData('primer'),
        ]);

        $I->seeCurrentUrlMatches('~admin/primer$~');
    }

    public function deletingFromASlideoutAnswersWithTheMessage(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $response = $I->postAction('primer/presets/delete', ['presetId' => $preset->id, 'siteId' => $preset->siteId]);

        $I->seeResponseCodeIs(200);
        $I->assertSame('Preset deleted.', $response['message']);
        $I->assertNull(Preset::find()->id($preset->id)->one());
    }

    public function anUnknownPresetIsNotFound(IntegrationTester $I): void
    {
        $I->postAction('primer/presets/delete', ['presetId' => 999999, 'siteId' => Craft::$app->getSites()->getPrimarySite()->id]);

        $I->seeResponseCodeIs(404);
    }

    public function deletingNeedsAPost(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $I->amOnPage("/admin/actions/primer/presets/delete?presetId=$preset->id");

        $I->seeResponseCodeIs(405);
        $I->assertNotNull(Preset::find()->id($preset->id)->one());
    }

    public function deletingNeedsAccessToPrimer(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');
        Craft::$app->setEdition(CmsEdition::Pro);
        $editor = new User(['active' => true, 'username' => 'editor', 'email' => 'editor@example.com']);
        $I->saveElement($editor);
        Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, ['accessCp']);
        $I->amLoggedInAs($editor);

        $I->postAction('primer/presets/delete', ['presetId' => $preset->id, 'siteId' => $preset->siteId]);

        $I->seeResponseCodeIs(403);
        $I->assertNotNull(Preset::find()->id($preset->id)->one());
    }
}
