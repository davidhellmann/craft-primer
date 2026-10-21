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
use davidhellmann\primer\elements\Preset;

/**
 * The actor of the `integration` suite: Craft's module plus Asserts, and shortcuts into the fixture's content model.
 */
class IntegrationTester extends Actor
{
    use _generated\IntegrationTesterActions;

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
     * A saved preset with the given fields chosen and filled.
     *
     * @param array<string, int[]> $values Element IDs by field handle; each handle is chosen.
     */
    public function preset(string $sectionHandle, string $typeHandle, array $values = []): Preset
    {
        $preset = new Preset([
            'sectionId' => $this->section($sectionHandle)->id,
            'typeId' => $this->entryType($typeHandle)->id,
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
}
