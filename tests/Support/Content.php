<?php

namespace davidhellmann\primer\tests\Support;

use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Validation\AssetRules;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Element\Enums\PropagationMethod;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Assets;
use CraftCms\Cms\Field\ContentBlock;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Field\Entries;
use CraftCms\Cms\Field\Enums\TranslationMethod;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\Field\Users;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\LayoutElements\Entries\EntryTitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Site\Data\SiteGroup;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Folders;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\SiteGroups;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserPermissions;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\User\Elements\User;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\Plugin;

/**
 * The content models the tests run against, built through Craft's own services, and the elements in them.
 *
 * Solo: one site. News (Article: Summary, Topics, Hero image at most 1, Writers, Related articles at most 2; Note:
 * Summary, Writers), Topics (Topic) and the Images volume.
 *
 * Pro: two site groups and three languages. Corporate EN (en-US, primary) and Corporate DE (de-DE); Shop EN (en-US) and
 * Shop FR (fr-FR). One Pages section per propagation method (pagesAll, pagesSiteGroup, pagesLanguage, pagesNone,
 * pagesCustom), each in every site, sharing the Page entry type, which holds an Entries, Assets and Users field per
 * translation method (entriesNone, entriesSite, …). Library (Custom propagation, Book), whose books the Entries fields
 * relate. Features (All propagation) has Page and Feature; Feature holds entriesSite and the Content Block `teaser`:
 * teaserEntries (none, at most 2), teaserAssets (site), teaserUsers (language) and the Plain Text teaserText (none),
 * which a preset doesn't hold.
 */
trait Content
{
    protected function solo(): void
    {
        Edition::set(Edition::Solo);
        $this->volume();

        $summary = $this->field(new PlainText(['name' => 'Summary', 'handle' => 'summary']));
        $topics = $this->field(new Entries(['name' => 'Topics', 'handle' => 'topics']));
        $heroImage = $this->field(new Assets(['name' => 'Hero image', 'handle' => 'heroImage', 'maxRelations' => 1]));
        $writers = $this->field(new Users(['name' => 'Writers', 'handle' => 'writers']));
        $related = $this->field(new Entries(['name' => 'Related articles', 'handle' => 'relatedArticles', 'maxRelations' => 2]));

        $topic = $this->saveEntryType('topic', 'Topic', []);
        $article = $this->saveEntryType('article', 'Article', [$summary, $topics, $heroImage, $writers, $related], 'newspaper');
        $note = $this->saveEntryType('note', 'Note', [$summary, $writers]);

        $this->saveSection('topics', 'Topics', [$topic]);
        $this->saveSection('news', 'News', [$article, $note]);
    }

    protected function pro(): void
    {
        Edition::set(Edition::Pro);

        $corporate = Sites::getPrimarySite();
        $corporate->name = 'Corporate EN';
        $corporate->handle = 'corporateEn';
        $this->assertTrue(Sites::saveSite($corporate));
        $corporateGroup = SiteGroups::getGroupById((int)$corporate->groupId);
        $corporateGroup->name = 'Corporate';
        $this->assertTrue(SiteGroups::saveGroup($corporateGroup));
        $shopGroup = new SiteGroup(['name' => 'Shop']);
        $this->assertTrue(SiteGroups::saveGroup($shopGroup));

        $this->saveSite('corporateDe', 'Corporate DE', 'de-DE', (int)$corporateGroup->id, 2);
        $this->saveSite('shopEn', 'Shop EN', 'en-US', (int)$shopGroup->id, 3);
        $this->saveSite('shopFr', 'Shop FR', 'fr-FR', (int)$shopGroup->id, 4);

        $this->volume();

        $pageFields = [];
        foreach (['entries' => Entries::class, 'assets' => Assets::class, 'users' => Users::class] as $kind => $class) {
            foreach (['none' => 'None', 'site' => 'Site', 'siteGroup' => 'SiteGroup', 'language' => 'Language'] as $method => $suffix) {
                $pageFields[] = $this->field(new $class([
                    'name' => ucfirst($kind) . " ($method)",
                    'handle' => $kind . $suffix,
                    'translationMethod' => TranslationMethod::from($method),
                ]));
            }
        }

        $teaserEntries = $this->field(new Entries(['name' => 'Teaser entries (none)', 'handle' => 'teaserEntries', 'maxRelations' => 2]));
        $teaserAssets = $this->field(new Assets(['name' => 'Teaser assets (site)', 'handle' => 'teaserAssets', 'translationMethod' => TranslationMethod::Site]));
        $teaserUsers = $this->field(new Users(['name' => 'Teaser users (language)', 'handle' => 'teaserUsers', 'translationMethod' => TranslationMethod::Language]));
        $teaserText = $this->field(new PlainText(['name' => 'Teaser text', 'handle' => 'teaserText']));
        $teaser = new ContentBlock(['name' => 'Teaser', 'handle' => 'teaser']);
        $teaser->setFieldLayout($this->layout(\CraftCms\Cms\Field\Elements\ContentBlock::class, [$teaserEntries, $teaserAssets, $teaserUsers, $teaserText]));
        $teaser = $this->field($teaser);

        $page = $this->saveEntryType('page', 'Page', $pageFields, 'file-lines');
        $book = $this->saveEntryType('book', 'Book', []);
        $feature = $this->saveEntryType('feature', 'Feature', [Fields::getFieldByHandle('entriesSite'), $teaser]);

        foreach ([
            'pagesAll' => PropagationMethod::All,
            'pagesSiteGroup' => PropagationMethod::SiteGroup,
            'pagesLanguage' => PropagationMethod::Language,
            'pagesNone' => PropagationMethod::None,
            'pagesCustom' => PropagationMethod::Custom,
        ] as $handle => $method) {
            $this->saveSection($handle, "Pages ($method->value)", [$page], propagationMethod: $method);
        }

        $this->saveSection('library', 'Library', [$book], propagationMethod: PropagationMethod::Custom);
        $this->saveSection('features', 'Features', [$page, $feature]);
    }

    protected function field(FieldInterface $field): FieldInterface
    {
        $this->assertTrue(Fields::saveField($field), "Field $field->handle");

        return $field;
    }

    /**
     * @param FieldInterface[] $fields
     */
    protected function layout(string $elementType, array $fields, bool $withTitle = false): FieldLayout
    {
        return FieldLayout::make($elementType)->tab('Content', function(FieldLayoutTab $tab) use ($fields, $withTitle) {
            if ($withTitle) {
                $tab->add(new EntryTitleField());
            }
            foreach ($fields as $field) {
                $tab->field($field);
            }
        });
    }

    /**
     * @param FieldInterface[] $fields
     */
    protected function saveEntryType(string $handle, string $name, array $fields, ?string $icon = null): EntryType
    {
        $type = new EntryType(['name' => $name, 'handle' => $handle, 'icon' => $icon]);
        $type->setFieldLayout($this->layout(Entry::class, $fields, withTitle: true));
        $this->assertTrue(EntryTypes::saveEntryType($type), "Entry type $handle");

        return $this->entryType($handle);
    }

    /**
     * @param EntryType[] $types
     * @param string[]|null $siteHandles Every site when null.
     */
    protected function saveSection(
        string $handle,
        string $name,
        array $types,
        PropagationMethod $propagationMethod = PropagationMethod::All,
        ?array $siteHandles = null,
    ): Section {
        $sites = $siteHandles === null
            ? Sites::getAllSites(true)->all()
            : array_map(fn(string $siteHandle) => $this->site($siteHandle), $siteHandles);

        $section = new Section([
            'name' => $name,
            'handle' => $handle,
            'type' => SectionType::Channel,
            'propagationMethod' => $propagationMethod,
            'entryTypes' => $types,
            'siteSettings' => array_map(fn(Site $site) => new SectionSiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => false,
            ]), $sites),
        ]);
        $this->assertTrue(Sections::saveSection($section), "Section $handle");

        return $this->section($handle);
    }

    protected function saveSite(string $handle, string $name, string $language, int $groupId, int $sortOrder): Site
    {
        $site = new Site([
            'name' => $name,
            'handle' => $handle,
            'language' => $language,
            'groupId' => $groupId,
            'baseUrl' => "https://$handle.test/",
            'hasUrls' => true,
            'sortOrder' => $sortOrder,
        ]);
        $this->assertTrue(Sites::saveSite($site), "Site $handle");

        return $site;
    }

    protected function volume(): void
    {
        $root = sys_get_temp_dir() . '/primer-test-assets-' . getmypid();
        config()->set('filesystems.disks.primerImages', ['driver' => 'local', 'root' => $root]);

        // Its assets are in every site, as Craft 5's always were.
        $volume = new Volume([
            'name' => 'Images',
            'handle' => 'images',
            'fsHandle' => 'primerImages',
            'propagationMethod' => PropagationMethod::All,
        ]);
        $this->assertTrue(Volumes::saveVolume($volume), 'Volume images');
    }

    protected function site(string $handle): Site
    {
        $site = Sites::getSiteByHandle($handle, true);
        $this->assertNotNull($site, "Site $handle");

        return $site;
    }

    protected function section(string $handle): Section
    {
        Sections::refreshSections();
        $section = Sections::getSectionByHandle($handle);
        $this->assertNotNull($section, "Section $handle");

        return $section;
    }

    protected function entryType(string $handle): EntryType
    {
        EntryTypes::refreshEntryTypes();
        $type = EntryTypes::getEntryTypeByHandle($handle);
        $this->assertNotNull($type, "Entry type $handle");

        return $type;
    }

    /**
     * The UID of a field's layout element in an entry type's layout: what a preset's field choice holds.
     */
    protected function layoutElementUid(string $typeHandle, string $fieldHandle): string
    {
        $element = $this->entryType($typeHandle)->getFieldLayout()->getFieldByHandle($fieldHandle)?->layoutElement;
        $this->assertInstanceOf(CustomField::class, $element, "Field $fieldHandle in $typeHandle");

        return (string)$element->uid;
    }

    /**
     * @param array<string, mixed> $values Field values, and the title.
     */
    protected function entry(string $sectionHandle, string $typeHandle, array $values = [], ?string $siteHandle = null): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->section($sectionHandle)->id,
            'typeId' => $this->entryType($typeHandle)->id,
            'title' => $values['title'] ?? 'Untitled',
            'siteId' => $siteHandle ? $this->site($siteHandle)->id : null,
        ]);
        unset($values['title']);
        $entry->setFieldValues($values);
        $this->assertTrue(Elements::saveElement($entry), 'Entry: ' . implode(', ', $entry->errors()->all()));

        return $entry;
    }

    protected function topic(string $title): Entry
    {
        return $this->entry('topics', 'topic', ['title' => $title]);
    }

    /**
     * A book in some sites of the Library, or in every site.
     *
     * @param string[]|null $siteHandles
     */
    protected function book(string $title = 'Book', ?array $siteHandles = null): Entry
    {
        $siteIds = $siteHandles
            ? array_map(fn(string $handle) => $this->site($handle)->id, $siteHandles)
            : Sites::getAllSiteIds()->all();

        $entry = new Entry([
            'sectionId' => $this->section('library')->id,
            'typeId' => $this->entryType('book')->id,
            'title' => $title,
            'siteId' => $siteIds[0],
        ]);
        $entry->setEnabledForSite(array_fill_keys($siteIds, true));
        $this->assertTrue(Elements::saveElement($entry), 'Book');

        return $entry;
    }

    protected function image(string $filename): Asset
    {
        $volume = Volumes::getVolumeByHandle('images');
        $this->assertNotNull($volume, 'Volume images');

        $path = tempnam(sys_get_temp_dir(), 'primer');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        $asset = new Asset([
            'tempFilePath' => $path,
            'filename' => $filename,
            'volumeId' => $volume->id,
            'newFolderId' => Folders::getRootFolderByVolumeId($volume->id)->id,
            'avoidFilenameConflicts' => true,
        ]);
        $asset->ruleset->useScenario(AssetRules::SCENARIO_CREATE);
        $this->assertTrue(Elements::saveElement($asset), 'Asset: ' . implode(', ', $asset->errors()->all()));

        return $asset;
    }

    protected function admin(): User
    {
        $user = User::find()->admin()->one();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Another user than the admin, which takes Craft Team or above; the tests go to Pro.
     */
    protected function user(string $name): User
    {
        if (Edition::get() === Edition::Solo) {
            Edition::set(Edition::Pro);
        }

        $user = new User(['username' => $name, 'email' => "$name@example.com", 'active' => true]);
        $this->assertTrue(Elements::saveElement($user), "User $name");

        return $user;
    }

    /**
     * A user who may use the control panel and Primer, and edit these sites.
     *
     * @param string[] $siteHandles
     * @param string[] $permissions More permissions.
     */
    protected function editor(array $siteHandles, array $permissions = []): User
    {
        $user = $this->user('editor');
        UserPermissions::saveUserPermissions($user->id, [
            'accessCp',
            Plugin::ACCESS_PERMISSION,
            ...array_map(fn(string $handle) => 'editSite:' . $this->site($handle)->uid, $siteHandles),
            ...$permissions,
        ]);

        return $user;
    }

    /**
     * Saves a preset of a section's entry type in a site (the primary site when null), its chosen fields being the
     * fields of the values given.
     *
     * @param array<string, mixed> $values Values by field handle.
     */
    protected function preset(string $sectionHandle, string $typeHandle, array $values = [], ?string $siteHandle = null): Preset
    {
        $preset = new Preset([
            'sectionId' => $this->section($sectionHandle)->id,
            'typeId' => $this->entryType($typeHandle)->id,
            'siteId' => $siteHandle ? $this->site($siteHandle)->id : null,
        ]);
        $preset->setFieldUids(array_map(fn(string $handle) => $this->layoutElementUid($typeHandle, $handle), array_keys($values)));
        $preset->setFieldValues($values);
        $this->assertTrue(Elements::saveElement($preset), 'Preset: ' . implode(', ', $preset->errors()->all()));

        return $preset;
    }

    /**
     * A preset as stored, in a site.
     */
    protected function stored(Preset $preset, ?string $siteHandle = null): Preset
    {
        $stored = Preset::find()->id($preset->id)->siteId($siteHandle ? $this->site($siteHandle)->id : $preset->siteId)->one();
        $this->assertInstanceOf(Preset::class, $stored);

        return $stored;
    }

    /**
     * The IDs of the elements a field holds, in their order.
     *
     * @return int[]
     */
    protected function ids(mixed $value): array
    {
        if ($value instanceof \CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface) {
            return array_map('intval', $value->status(null)->ids());
        }

        return $value instanceof \CraftCms\Cms\Element\ElementCollection ? $value->ids()->map(fn($id) => (int)$id)->all() : [];
    }
}
