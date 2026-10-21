<?php

namespace davidhellmann\primer\controllers;

use Craft;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use craft\web\Controller;
use davidhellmann\primer\elements\Preset;
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

        $this->requirePermission('accessPlugin-primer');

        return true;
    }

    /**
     * Every section of the site, its entry types with a preset, and the ones still without.
     */
    public function actionIndex(): Response
    {
        $site = $this->requestedSite();

        $presets = [];
        foreach (Preset::find()->siteId($site->id)->all() as $preset) {
            $presets["$preset->sectionId:$preset->typeId"] = $preset;
        }

        // The sites with a preset, for entry types whose presets are in other scopes only.
        $elsewhere = [];
        foreach (Preset::find()->site('*')->all() as $preset) {
            $elsewhere["$preset->sectionId:$preset->typeId"][$preset->siteId] = true;
        }

        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!isset($section->getSiteSettings()[$site->id])) {
                continue;
            }

            $withPreset = [];
            $withoutPreset = [];
            foreach ($section->getEntryTypes() as $type) {
                $key = "$section->id:$type->id";
                $preset = $presets[$key] ?? null;
                if ($preset || isset($elsewhere[$key])) {
                    $withPreset[] = [
                        'type' => $type,
                        'preset' => $preset,
                        'chipHtml' => Cp::chipHtml($type, ['hyperlink' => false]),
                        'fieldCount' => $preset ? count($preset->getChosenFieldElements()) : null,
                        'otherSites' => $preset ? null : $this->siteNames(array_keys($elsewhere[$key])),
                        'delete' => $preset ? $this->deleteAttributes($preset, Craft::t('site', $type->name), UrlHelper::cpUrl('primer', $this->siteParams($site))) : null,
                    ];
                } else {
                    $withoutPreset[] = $type;
                }
            }

            $sections[] = [
                'section' => $section,
                'presets' => $withPreset,
                'available' => $withoutPreset,
            ];
        }

        return $this->renderTemplate('primer/index', [
            'site' => $site,
            'sections' => $sections,
        ]);
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

        $presets = [];
        foreach (Preset::find()->sectionId($section->id)->siteId($site->id)->all() as $preset) {
            $presets[$preset->typeId] = $preset;
        }

        // The sites with a preset, for entry types whose presets are in other scopes only.
        $elsewhere = [];
        foreach (Preset::find()->sectionId($section->id)->site('*')->all() as $preset) {
            $elsewhere[$preset->typeId][$preset->siteId] = true;
        }

        $types = [];
        foreach ($section->getEntryTypes() as $type) {
            $preset = $presets[$type->id] ?? null;
            $types[] = [
                'type' => $type,
                'preset' => $preset,
                'otherSites' => !$preset && isset($elsewhere[$type->id]) ? $this->siteNames(array_keys($elsewhere[$type->id])) : null,
                'delete' => $preset
                    ? $this->deleteAttributes($preset, Craft::t('site', $type->name), $this->sectionUrl($section, $site))
                    : null,
            ];
        }

        $sectionName = Craft::t('site', $section->name);

        $response = $this->asCpScreen()
            ->title($sectionName)
            ->site($site)
            ->selectableSites($this->selectableSites($section))
            ->crumbs([
                ['label' => Craft::t('primer', 'Primer'), 'url' => UrlHelper::cpUrl('primer', $this->siteParams($site))],
            ])
            ->editUrl($this->sectionUrl($section, $site))
            ->contentTemplate('primer/_section/content', [
                'section' => $section,
                'types' => $types,
                'site' => $site,
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
                    [$section, $type] = $this->sectionAndType((int)$other->sectionId, (int)$other->typeId);
                    $preset = $this->presetInSite($section, $type, $site);
                }
            } else {
                [$section, $type] = $this->sectionAndType(
                    (int)$this->request->getRequiredQueryParam('sectionId'),
                    (int)$this->request->getRequiredQueryParam('typeId'),
                );
                $preset = $this->presetInSite($section, $type, $site);
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
                ['label' => Craft::t('primer', 'Primer'), 'url' => UrlHelper::cpUrl('primer', $this->siteParams($site))],
                ['label' => $sectionCrumb['label'], 'url' => $sectionCrumb['url']],
            ])
            ->action('primer/presets/save')
            ->redirectUrl(UrlHelper::cpUrl('primer', $this->siteParams($site)))
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

        $siteId = (int)$this->request->getRequiredBodyParam('siteId');
        $presetId = $this->request->getBodyParam('presetId');

        if ($presetId) {
            // Section and entry type of a saved preset don't change.
            $preset = Preset::find()->id((int)$presetId)->siteId($siteId)->one()
                ?? throw new NotFoundHttpException('Preset not found');
        } else {
            $preset = new Preset([
                'siteId' => $siteId,
                'sectionId' => (int)$this->request->getRequiredBodyParam('sectionId') ?: null,
                'typeId' => (int)$this->request->getRequiredBodyParam('typeId') ?: null,
            ]);
        }

        if (!$preset->canSave(static::currentUser())) {
            throw new ForbiddenHttpException('User not authorized to save this preset.');
        }

        $preset->setFieldUids($this->request->getBodyParam('fieldUids') ?: []);
        $preset->setFieldValuesFromRequest('fields');

        if (!Plugin::getInstance()->presets->save($preset)) {
            return $this->asModelFailure($preset, Craft::t('primer', 'Couldn’t save preset.'), 'preset');
        }

        return $this->asModelSuccess($preset, Craft::t('primer', 'Preset saved.'), 'preset');
    }

    /**
     * Deletes a preset: POST `presetId`, `siteId`. A page then goes to the posted redirect or the preset's section
     * page, where Craft shows the message; a script gets the message as JSON.
     */
    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();

        $siteId = (int)$this->request->getRequiredBodyParam('siteId');
        $preset = Preset::find()->id((int)$this->request->getRequiredBodyParam('presetId'))->siteId($siteId)->one()
            ?? throw new NotFoundHttpException('Preset not found');

        if (!$preset->canDelete(static::currentUser())) {
            throw new ForbiddenHttpException('User not authorized to delete this preset.');
        }

        $section = $preset->getSection();
        $site = $preset->getSite();
        $this->deletePreset($preset);

        return $this->asSuccess(
            Craft::t('primer', 'Preset deleted.'),
            redirect: $this->getPostedRedirectUrl()
                ?? ($section ? $this->sectionUrl($section, $site) : UrlHelper::cpUrl('primer', $this->siteParams($site))),
        );
    }

    /**
     * The one place a preset is deleted: with the presets of its entry type in every other scope.
     */
    private function deletePreset(Preset $preset): void
    {
        Plugin::getInstance()->presets->deleteInAllScopes($preset);
    }

    /**
     * Whether the screen is requested by `Craft.CpScreenSlideout` (as JSON) rather than as a page, the way Craft's
     * CP screen formatter tells them apart.
     */
    private function inSlideout(): bool
    {
        return $this->request->getAcceptsJson();
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
     * The site's preset of a section's entry type, or a new one, which starts with the field choice of the presets
     * in the other scopes.
     */
    private function presetInSite(Section $section, EntryType $type, Site $site): Preset
    {
        $existing = Preset::find()->sectionId($section->id)->typeId($type->id)->siteId($site->id)->one();
        if ($existing) {
            return $existing;
        }

        if (!isset($section->getSiteSettings()[$site->id])) {
            throw new NotFoundHttpException('The section isn’t enabled for this site.');
        }

        $preset = new Preset([
            'siteId' => $site->id,
            'sectionId' => $section->id,
            'typeId' => $type->id,
        ]);
        $preset->setFieldUids(Plugin::getInstance()->presets->fieldUids($section->id, $type->id));

        return $preset;
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
