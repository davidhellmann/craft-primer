<?php

namespace davidhellmann\primer\tests\Support;

use Codeception\Actor;
use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use davidhellmann\primer\elements\Preset;
use Yii;
use yii\log\Logger;

/**
 * The actor of the `integration` suite: Craft's module plus Asserts, and shortcuts into the fixture's content model.
 */
class IntegrationTester extends Actor
{
    use _generated\IntegrationTesterActions;

    public function site(string $handle): Site
    {
        $site = Craft::$app->getSites()->getSiteByHandle($handle);
        $this->assertNotNull($site, "Site $handle");

        return $site;
    }

    public function section(string $handle): Section
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);
        $this->assertNotNull($section, "Section $handle");

        return $section;
    }

    public function entryType(string $handle): EntryType
    {
        $type = Craft::$app->getEntries()->getEntryTypeByHandle($handle);
        $this->assertNotNull($type, "Entry type $handle");

        return $type;
    }

    /**
     * The UID of a field's layout element in an entry type's layout: the key Primer stores in its field choice.
     */
    public function layoutElementUid(string $typeHandle, string $fieldHandle): string
    {
        $element = $this->entryType($typeHandle)->getFieldLayout()->getFieldByHandle($fieldHandle)?->layoutElement;
        $this->assertInstanceOf(CustomField::class, $element, "Field $fieldHandle in $typeHandle");

        return $element->uid;
    }

    /**
     * A saved entry of the Topics section, to relate to.
     */
    public function topic(string $title): Entry
    {
        return $this->entry('topics', 'topic', ['title' => $title]);
    }

    /**
     * A saved entry.
     *
     * @param array<string, mixed> $values Field values by handle, plus `title`.
     */
    public function entry(string $sectionHandle, string $typeHandle, array $values = []): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->section($sectionHandle)->id,
            'typeId' => $this->entryType($typeHandle)->id,
            'title' => $values['title'] ?? 'Untitled',
        ]);
        unset($values['title']);
        $entry->setFieldValues($values);
        $this->saveElement($entry);

        return $entry;
    }

    /**
     * A saved asset in the Images volume: a 1×1 PNG.
     */
    public function image(string $filename): Asset
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle('images');
        $this->assertNotNull($volume, 'Volume images');

        $path = tempnam(sys_get_temp_dir(), 'primer');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        $asset = new Asset([
            'tempFilePath' => $path,
            'filename' => $filename,
            'volumeId' => $volume->id,
            'newFolderId' => Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id,
            'avoidFilenameConflicts' => true,
        ]);
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $this->saveElement($asset);

        return $asset;
    }

    /**
     * The fixture's one user, to relate to: Craft Solo allows a single account.
     */
    public function admin(): User
    {
        $user = User::find()->admin()->one();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * A control panel user who may use Primer and edit the given sites, and nothing else. Needs Craft Pro.
     *
     * @param string[] $siteHandles
     */
    public function editor(array $siteHandles): User
    {
        $user = new User([
            'active' => true,
            'username' => 'editor',
            'email' => 'editor@example.com',
        ]);
        $this->saveElement($user);
        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, [
            'accessCp',
            'accessPlugin-primer',
            ...array_map(fn(string $handle) => 'editSite:' . $this->site($handle)->uid, $siteHandles),
        ]);

        return $user;
    }

    /**
     * A saved preset with the given fields chosen and filled.
     *
     * @param array<string, mixed> $values Element IDs by field handle, or a Content Block's array form
     *     (`['fields' => [innerHandle => IDs]]`); each handle is chosen.
     * @param string|null $siteHandle The site it is created in; the primary site by default.
     */
    public function preset(string $sectionHandle, string $typeHandle, array $values = [], ?string $siteHandle = null): Preset
    {
        $preset = new Preset([
            'sectionId' => $this->section($sectionHandle)->id,
            'typeId' => $this->entryType($typeHandle)->id,
            'siteId' => $siteHandle ? $this->site($siteHandle)->id : null,
        ]);
        $preset->setFieldUids(array_map(fn(string $handle) => $this->layoutElementUid($typeHandle, $handle), array_keys($values)));
        $preset->setFieldValues($values);
        $this->saveElement($preset);

        return $preset;
    }

    /**
     * A CP screen as `Craft.CpScreenSlideout` requests it: a GET to the action, answered with JSON.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed> The screen's parts: `content`, `extraToolbarItems`, `actionMenu`, `editUrl`, …
     */
    public function slideout(string $action, array $params = []): array
    {
        $this->haveHttpHeader('Accept', 'application/json');
        $this->sendAjaxGetRequest("/admin/actions/$action", $params);
        $this->deleteHeader('Accept');
        $this->seeResponseCodeIs(200);

        return Json::decode($this->grabPageSource());
    }

    /**
     * A POST as the CP's scripts send it with `Craft.sendActionRequest`, answered with JSON.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed> The decoded answer; empty when it isn't JSON.
     */
    public function postAction(string $action, array $params = []): array
    {
        $this->haveHttpHeader('Accept', 'application/json');
        $this->sendAjaxPostRequest("/admin/actions/$action", $params);
        $this->deleteHeader('Accept');
        $data = Json::decodeIfJson($this->grabPageSource());

        return is_array($data) ? $data : [];
    }

    /**
     * Runs an action and returns what Primer logged meanwhile.
     *
     * @return array<array{level: string, message: string}>
     */
    public function primerLogOf(callable $action): array
    {
        $logger = new class() extends Logger {
            /** @var array<array{level: string, message: string}> */
            public array $primer = [];

            public function init(): void
            {
                // No flush on shutdown: nothing is dispatched.
            }

            public function log($message, $level, $category = 'application'): void
            {
                if (str_starts_with($category, 'davidhellmann\\primer\\')) {
                    $this->primer[] = ['level' => Logger::getLevelName($level), 'message' => (string)$message];
                }
            }
        };

        $previous = Yii::getLogger();
        Yii::setLogger($logger);
        try {
            $action();
        } finally {
            Yii::setLogger($previous);
        }

        return $logger->primer;
    }
}
