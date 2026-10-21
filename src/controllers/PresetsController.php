<?php

namespace davidhellmann\primer\controllers;

use Craft;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use craft\web\Controller;
use davidhellmann\primer\decisions\Status;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\models\EntryTypePresets;
use davidhellmann\primer\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The index of sections and their presets, and the preset screen.
 */
class PresetsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::ACCESS_PERMISSION);

        return true;
    }

    /**
     * Every section of the site, its entry types with a preset, and the ones still without.
     */
    public function actionIndex(): Response
    {
        $site = $this->requestedSite();
        $presets = Plugin::getInstance()->presets->ofEntryTypes($site);
        $indexUrl = $this->indexUrl($site);

        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!isset($section->getSiteSettings()[$site->id])) {
                continue;
            }

            $withPreset = [];
            $withoutPreset = [];
            foreach ($section->getEntryTypes() as $type) {
                $line = $this->typeLine($section, $type, $presets, $indexUrl);
                if (!$line['preset'] && !$line['otherSites']) {
                    $withoutPreset[] = $type;
                    continue;
                }

                $withPreset[] = $line + [
                    'chipHtml' => Cp::chipHtml($type, ['hyperlink' => false]),
                    'fieldCount' => $line['preset'] ? count($line['preset']->getChosenFieldElements()) : null,
                ];
            }

            $sections[] = [
                'section' => $section,
                'presets' => $withPreset,
                'available' => $withoutPreset,
            ];
        }

        return $this->renderTemplate('primer/index', [
            'site' => $site,
            'siteParams' => $this->siteParams($site),
            'sections' => $sections,
        ]);
    }

    /**
     * An entry type's line on the index or the section page: its preset in the site, with its status and delete
     * attributes, or, when its presets are all in other scopes, the names of their sites the section is still enabled
     * for ("Only in … so far").
     *
     * @param string $redirect Where a page goes after deleting the preset.
     * @return array{type: EntryType, preset: Preset|null, on: bool, otherSites: string|null, delete: array<string, mixed>|null}
     */
    private function typeLine(Section $section, EntryType $type, EntryTypePresets $presets, string $redirect): array
    {
        $preset = $presets->inSite($section, $type);
        $otherSiteIds = $preset ? [] : $presets->siteIds($section, $type);

        return [
            'type' => $type,
            'preset' => $preset,
            'on' => $preset?->isOn() ?? false,
            'otherSites' => $otherSiteIds ? $this->siteNames($otherSiteIds) : null,
            'delete' => $preset ? $this->deleteAttributes($preset, Craft::t('site', $type->name), $redirect) : null,
        ];
    }

    /**
     * The section page: `primer/sections/<handle>`, its entry types and the fields their presets fill. One CP screen
     * for the page and, requested by `Craft.CpScreenSlideout` with `sectionHandle` as a param, the slideout.
     */
    public function actionSection(string $sectionHandle): Response
    {
        $site = $this->requestedSite();
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle)
            ?? throw new NotFoundHttpException('Section not found');

        $presets = Plugin::getInstance()->presets->ofEntryTypes($site, $section);
        $types = array_map(
            fn(EntryType $type) => $this->typeLine($section, $type, $presets, $this->sectionUrl($section, $site)),
            $section->getEntryTypes(),
        );

        $sectionName = Craft::t('site', $section->name);

        $response = $this->asCpScreen()
            ->title($sectionName)
            ->site($site)
            ->selectableSites($this->selectableSites($section))
            ->crumbs([$this->indexCrumb($site)])
            ->editUrl($this->sectionUrl($section, $site))
            ->contentTemplate('primer/_section/content', [
                'section' => $section,
                'types' => $types,
                'site' => $site,
                'siteParams' => $this->siteParams($site),
            ]);

        if ($this->inSlideout()) {
            $response->toolbarHtml($this->slideoutCrumbs([
                ['label' => $sectionName],
            ]));
        }

        return $response;
    }

    /**
     * The preset screen: `primer/presets/<id>`, or `primer/presets/new?sectionId=&typeId=`.
     *
     * @param Preset|null $preset A preset that failed to save, with its errors.
     */
    public function actionEdit(?int $presetId = null, ?Preset $preset = null): Response
    {
        $site = $this->requestedSite();

        if (!$preset) {
            if ($presetId) {
                $preset = Preset::find()->id($presetId)->siteId($site->id)->one();
                if (!$preset) {
                    // Another scope's preset, after a switch in the site menu: this site's preset of the entry type.
                    $other = Preset::find()->id($presetId)->site('*')->one()
                        ?? throw new NotFoundHttpException('Preset not found');
                    $preset = $this->presetToEdit((int)$other->sectionId, (int)$other->typeId, $site);
                }
            } else {
                $preset = $this->presetToEdit(
                    (int)$this->request->getRequiredQueryParam('sectionId'),
                    (int)$this->request->getRequiredQueryParam('typeId'),
                    $site,
                );
            }

            // A page shows a saved preset under its own URL; a slideout just shows it.
            if ($preset->id && $preset->id !== $presetId && !$this->inSlideout()) {
                return $this->redirect(UrlHelper::cpUrl("primer/presets/$preset->id", $this->siteParams($site)));
            }
        }

        $section = $preset->getSection() ?? throw new NotFoundHttpException('Section not found');
        $type = $preset->getType() ?? throw new NotFoundHttpException('Entry type not found');
        if (!$preset->typeIsInSection()) {
            throw new NotFoundHttpException('The entry type isn’t part of the section.');
        }
        $typeName = Craft::t('site', $type->name);
        $sectionCrumb = $this->sectionCrumb($section, $site);

        $response = $this->asCpScreen()
            ->title($preset->id ? $typeName : Craft::t('primer', 'New preset: {type}', ['type' => $typeName]))
            ->site($site)
            ->selectableSites($this->selectableSites($section))
            ->crumbs([
                $this->indexCrumb($site),
                ['label' => $sectionCrumb['label'], 'url' => $sectionCrumb['url']],
            ])
            ->action('primer/presets/save')
            ->redirectUrl($this->indexUrl($site))
            ->submitButtonLabel(Craft::t('app', 'Save'))
            ->addAltAction(Craft::t('app', 'Save and continue editing'), [
                'redirect' => UrlHelper::cpUrl('primer/presets/{id}', $this->siteParams($site)),
                'shortcut' => true,
                'retainScroll' => true,
            ])
            ->contentTemplate('primer/_preset/content', [
                'preset' => $preset,
                'site' => $site,
            ])
            ->metaSidebarTemplate('primer/_preset/meta', [
                'preset' => $preset,
                'siteStatuses' => $this->siteStatuses($preset),
            ]);

        if ($this->inSlideout()) {
            $response->toolbarHtml($this->slideoutCrumbs([
                $sectionCrumb,
                ['label' => $typeName, 'icon' => $type->icon, 'color' => $type->color],
            ]));
        }

        if ($preset->id) {
            $response
                ->editUrl(UrlHelper::cpUrl("primer/presets/$preset->id", $this->siteParams($site)))
                ->actionMenuItems(fn() => [
                    $this->deleteMenuItem($preset, $typeName, $section, $site),
                ]);
        }

        return $response;
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        if ($this->request->getBodyParam('presetId')) {
            // Section and entry type of a saved preset don't change.
            $preset = $this->postedPreset();
        } else {
            $preset = new Preset([
                'siteId' => (int)$this->request->getRequiredBodyParam('siteId'),
                'sectionId' => (int)$this->request->getRequiredBodyParam('sectionId') ?: null,
                'typeId' => (int)$this->request->getRequiredBodyParam('typeId') ?: null,
            ]);
        }

        if (!$preset->canSave(static::currentUser())) {
            throw new ForbiddenHttpException('User not authorized to save this preset.');
        }

        $preset->setFieldUids($this->request->getBodyParam('fieldUids') ?: []);
        $preset->setFieldValuesFromRequest('fields');
        $this->applyStatusField($preset);

        // The preset keeps the entry type's other presets in line itself (`Preset::afterSave()`).
        if (!Craft::$app->getElements()->saveElement($preset, updateSearchIndex: false)) {
            return $this->asModelFailure($preset, Craft::t('primer', 'Couldn’t save preset.'), 'preset');
        }

        return $this->asModelSuccess($preset, Craft::t('primer', 'Preset saved.'), 'preset');
    }

    /**
     * The sidebar's status field, `enabledForSite[<siteId>]`, as Craft's `ElementsController` applies it: each site
     * the user may edit, and the preset on as soon as one site is (`Preset::switchSites()`).
     */
    private function applyStatusField(Preset $preset): void
    {
        $enabledForSite = $this->request->getBodyParam('enabledForSite');
        if (!is_array($enabledForSite)) {
            return;
        }

        if (array_diff(array_keys($enabledForSite), Craft::$app->getSites()->getEditableSiteIds())) {
            throw new ForbiddenHttpException('User not authorized to edit the preset’s status for all the submitted sites.');
        }

        $preset->switchSites(array_map(fn($on) => (bool)$on, $enabledForSite));
    }

    /**
     * Switches a preset on or off for one site, the lightswitch of the index and section page: POST `presetId`,
     * `siteId`, `enabled`. Its values and the other sites of its scope stay as they are, unless the preset was off as a
     * whole: then they stay off (`Status::switched()`).
     */
    public function actionToggle(): Response
    {
        $this->requirePostRequest();

        $preset = $this->postedPreset();
        if (!$preset->canSave(static::currentUser())) {
            throw new ForbiddenHttpException('User not authorized to save this preset.');
        }

        // As Craft's status field does: switching a site on switches the preset on.
        $on = (bool)$this->request->getRequiredBodyParam('enabled');
        $sites = $preset->switchSites([$preset->siteId => $on]);

        if (!Craft::$app->getElements()->saveElement($preset, false, count($sites) > 1, false)) {
            return $this->asFailure(Craft::t('primer', 'Couldn’t save preset.'));
        }

        $site = Craft::t('site', $preset->getSite()->getName());

        return $this->asSuccess($on
            ? Craft::t('primer', 'Preset switched on for {site}.', ['site' => $site])
            : Craft::t('primer', 'Preset switched off for {site}.', ['site' => $site]));
    }

    /**
     * Deletes a preset: POST `presetId`, `siteId`. A page then goes to the posted redirect or the preset's section
     * page, where Craft shows the message; a script gets the message as JSON.
     */
    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();

        $preset = $this->postedPreset();
        if (!$preset->canDelete(static::currentUser())) {
            throw new ForbiddenHttpException('User not authorized to delete this preset.');
        }

        $section = $preset->getSection();
        $site = $preset->getSite();
        // Presets have no trash to restore them from. The entry type's presets in every other scope go too, also in
        // sites the user can't edit (`Preset::afterDelete()`).
        Craft::$app->getElements()->deleteElement($preset, true);

        return $this->asSuccess(
            Craft::t('primer', 'Preset deleted.'),
            redirect: $this->getPostedRedirectUrl()
                ?? ($section ? $this->sectionUrl($section, $site) : $this->indexUrl($site)),
        );
    }

    /**
     * The preset a POST is about: `presetId` in `siteId`.
     */
    private function postedPreset(): Preset
    {
        return Preset::find()
            ->id((int)$this->request->getRequiredBodyParam('presetId'))
            ->siteId((int)$this->request->getRequiredBodyParam('siteId'))
            ->one() ?? throw new NotFoundHttpException('Preset not found');
    }

    /**
     * Whether the screen is requested by `Craft.CpScreenSlideout` (as JSON) rather than as a page, the way Craft's
     * CP screen formatter tells them apart.
     */
    private function inSlideout(): bool
    {
        return $this->request->getAcceptsJson();
    }

    private function indexUrl(Site $site): string
    {
        return UrlHelper::cpUrl('primer', $this->siteParams($site));
    }

    /**
     * @return array{label: string, url: string}
     */
    private function indexCrumb(Site $site): array
    {
        return ['label' => Craft::t('primer', 'Primer'), 'url' => $this->indexUrl($site)];
    }

    private function sectionUrl(Section $section, Site $site): string
    {
        return UrlHelper::cpUrl("primer/sections/$section->handle", $this->siteParams($site));
    }

    /**
     * The section page as a crumb: a click opens the page, a double-click its slideout.
     *
     * @return array{label: string, url: string, nav: array<string, mixed>}
     */
    private function sectionCrumb(Section $section, Site $site): array
    {
        return [
            'label' => Craft::t('site', $section->name),
            'url' => $this->sectionUrl($section, $site),
            'nav' => [
                'action' => 'primer/presets/section',
                'params' => ['sectionHandle' => $section->handle] + $this->siteParams($site),
                'form' => false,
            ],
        ];
    }

    /**
     * "Delete preset" in the preset page's ⋯ menu; afterwards the page goes to the section page. Craft leaves
     * destructive actions out of a slideout's menu.
     *
     * @return array<string, mixed>
     */
    private function deleteMenuItem(Preset $preset, string $name, Section $section, Site $site): array
    {
        return [
            'icon' => 'trash',
            'label' => Craft::t('primer', 'Delete preset'),
            'destructive' => true,
            'attributes' => $this->deleteAttributes($preset, $name, $this->sectionUrl($section, $site)),
        ];
    }

    /**
     * The data attributes `_nav` deletes a preset with: what to post, the confirmation, and where a page goes after.
     *
     * @return array{data: array<string, mixed>}
     */
    private function deleteAttributes(Preset $preset, string $name, string $redirect): array
    {
        return [
            'data' => [
                'primer-delete' => ['presetId' => $preset->id, 'siteId' => $preset->siteId],
                'primer-confirm' => Craft::t('app', 'Are you sure you want to delete “{name}”?', ['name' => $name]),
                'primer-redirect' => Craft::$app->getSecurity()->hashData($redirect),
            ],
        ];
    }

    /**
     * Craft shows no title or crumbs in a slideout; its header gets crumbs in Craft's style instead.
     *
     * @param array<array<string, mixed>> $crumbs
     */
    private function slideoutCrumbs(array $crumbs): string
    {
        return $this->getView()->renderTemplate('primer/_slideout-crumbs', ['crumbs' => $crumbs]);
    }

    /**
     * The site the screen is about, from `?site=`: one the user may edit. Craft falls back to another editable site.
     */
    private function requestedSite(): Site
    {
        return Cp::requestedSite() ?? throw new ForbiddenHttpException('User not authorized to edit content in any sites.');
    }

    /**
     * The `site` param of a URL that has to stay in the site even when the current request has none, such as a
     * redirect after a POST. Craft adds it to every other CP URL on a multi-site install.
     *
     * @return array<string, string>
     */
    private function siteParams(Site $site): array
    {
        return Craft::$app->getIsMultiSite() ? ['site' => $site->handle] : [];
    }

    /**
     * The sites of Craft's site menu on a section's screens: those of the section the user may edit.
     *
     * @return Site[]
     */
    private function selectableSites(Section $section): array
    {
        $siteSettings = $section->getSiteSettings();

        return array_values(array_filter(
            Craft::$app->getSites()->getEditableSites(),
            fn(Site $site) => isset($siteSettings[$site->id]),
        ));
    }

    /**
     * The site's preset of a section's entry type to edit, saved or new (`Presets::toEdit()`).
     */
    private function presetToEdit(int $sectionId, int $typeId, Site $site): Preset
    {
        [$section, $type] = $this->sectionAndType($sectionId, $typeId);

        return Plugin::getInstance()->presets->toEdit($section, $type, $site)
            ?? throw new NotFoundHttpException('The section isn’t enabled for this site.');
    }

    /**
     * The sites of the preset's scope the user may edit, in Craft's site order, each with whether the preset is on
     * there: what the sidebar's status field shows (`Status::shown()`).
     *
     * @return array<array{site: Site, on: bool}>
     */
    private function siteStatuses(Preset $preset): array
    {
        $stored = [];
        if ($preset->id) {
            foreach (Preset::find()->id($preset->id)->site('*')->all() as $inSite) {
                $stored[$inSite->siteId] = $inSite->isOn();
            }
        }

        $scope = array_column($preset->getSupportedSites(), 'siteId');
        $sites = array_values(array_filter(
            Craft::$app->getSites()->getEditableSites(),
            fn(Site $site) => in_array($site->id, $scope, true),
        ));
        $set = [];
        foreach ($sites as $site) {
            $set[$site->id] = $preset->getEnabledForSite($site->id);
        }

        $shown = Status::shown($preset->enabled, array_column($sites, 'id'), $set, $stored);

        return array_map(fn(Site $site) => ['site' => $site, 'on' => $shown[$site->id]], $sites);
    }

    /**
     * The sites' names in Craft's site order, as one list.
     *
     * @param int[] $siteIds
     */
    private function siteNames(array $siteIds): string
    {
        $names = [];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            if (in_array($site->id, $siteIds, true)) {
                $names[] = Craft::t('site', $site->name);
            }
        }

        return implode(', ', $names);
    }

    /**
     * @return array{Section, EntryType}
     */
    private function sectionAndType(int $sectionId, int $typeId): array
    {
        $section = Craft::$app->getEntries()->getSectionById($sectionId)
            ?? throw new NotFoundHttpException('Section not found');

        foreach ($section->getEntryTypes() as $type) {
            if ($type->id === $typeId) {
                return [$section, $type];
            }
        }

        throw new BadRequestHttpException('The entry type isn’t part of the section.');
    }
}
