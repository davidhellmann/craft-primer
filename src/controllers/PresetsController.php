<?php

namespace davidhellmann\primer\controllers;

use Craft;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\web\Controller;
use davidhellmann\primer\elements\Preset;
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
        $site = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();

        $presets = [];
        foreach (Preset::find()->siteId($site->id)->all() as $preset) {
            $presets["$preset->sectionId:$preset->typeId"] = $preset;
        }

        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!isset($section->getSiteSettings()[$site->id])) {
                continue;
            }

            $withPreset = [];
            $withoutPreset = [];
            foreach ($section->getEntryTypes() as $type) {
                $preset = $presets["$section->id:$type->id"] ?? null;
                if ($preset) {
                    $withPreset[] = [
                        'type' => $type,
                        'preset' => $preset,
                        'chipHtml' => Cp::chipHtml($type, ['hyperlink' => false]),
                        'fieldCount' => count($preset->getChosenFieldElements()),
                        'delete' => $this->deleteAttributes($preset, Craft::t('site', $type->name), UrlHelper::cpUrl('primer')),
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
        $site = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle)
            ?? throw new NotFoundHttpException('Section not found');

        $presets = [];
        foreach (Preset::find()->sectionId($section->id)->siteId($site->id)->all() as $preset) {
            $presets[$preset->typeId] = $preset;
        }

        $types = [];
        foreach ($section->getEntryTypes() as $type) {
            $preset = $presets[$type->id] ?? null;
            $types[] = [
                'type' => $type,
                'preset' => $preset,
                'delete' => $preset
                    ? $this->deleteAttributes($preset, Craft::t('site', $type->name), $this->sectionUrl($section))
                    : null,
            ];
        }

        $sectionName = Craft::t('site', $section->name);

        $response = $this->asCpScreen()
            ->title($sectionName)
            ->crumbs([
                ['label' => Craft::t('primer', 'Primer'), 'url' => 'primer'],
            ])
            ->editUrl($this->sectionUrl($section))
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
        $site = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();

        if (!$preset) {
            if ($presetId) {
                $preset = Preset::find()->id($presetId)->siteId($site->id)->one()
                    ?? throw new NotFoundHttpException('Preset not found');
            } else {
                [$section, $type] = $this->sectionAndType(
                    (int)$this->request->getRequiredQueryParam('sectionId'),
                    (int)$this->request->getRequiredQueryParam('typeId'),
                );

                // An entry type has one preset per section and site: open it if it exists.
                $existing = Preset::find()->sectionId($section->id)->typeId($type->id)->siteId($site->id)->one();
                if ($existing) {
                    return $this->redirect(UrlHelper::cpUrl("primer/presets/$existing->id"));
                }

                $preset = new Preset([
                    'siteId' => $site->id,
                    'sectionId' => $section->id,
                    'typeId' => $type->id,
                ]);
            }
        }

        $section = $preset->getSection() ?? throw new NotFoundHttpException('Section not found');
        $type = $preset->getType() ?? throw new NotFoundHttpException('Entry type not found');
        $typeName = Craft::t('site', $type->name);
        $sectionCrumb = $this->sectionCrumb($section);

        $response = $this->asCpScreen()
            ->title($preset->id ? $typeName : Craft::t('primer', 'New preset: {type}', ['type' => $typeName]))
            ->crumbs([
                ['label' => Craft::t('primer', 'Primer'), 'url' => 'primer'],
                ['label' => $sectionCrumb['label'], 'url' => $sectionCrumb['url']],
            ])
            ->action('primer/presets/save')
            ->redirectUrl('primer')
            ->submitButtonLabel(Craft::t('app', 'Save'))
            ->addAltAction(Craft::t('app', 'Save and continue editing'), [
                'redirect' => 'primer/presets/{id}',
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
                ->editUrl(UrlHelper::cpUrl("primer/presets/$preset->id"))
                ->actionMenuItems(fn() => [
                    $this->deleteMenuItem($preset, $typeName, $section),
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

        if (!Craft::$app->getElements()->saveElement($preset, updateSearchIndex: false)) {
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
        $this->deletePreset($preset);

        return $this->asSuccess(
            Craft::t('primer', 'Preset deleted.'),
            redirect: $this->getPostedRedirectUrl() ?? ($section ? $this->sectionUrl($section) : UrlHelper::cpUrl('primer')),
        );
    }

    /**
     * The one place a preset is deleted. Ticket 14 widens it to the presets of every scope.
     */
    private function deletePreset(Preset $preset): void
    {
        Craft::$app->getElements()->deleteElement($preset, true);
    }

    /**
     * Whether the screen is requested by `Craft.CpScreenSlideout` (as JSON) rather than as a page, the way Craft's
     * CP screen formatter tells them apart.
     */
    private function inSlideout(): bool
    {
        return $this->request->getAcceptsJson();
    }

    private function sectionUrl(Section $section): string
    {
        return UrlHelper::cpUrl("primer/sections/$section->handle");
    }

    /**
     * The section page as a crumb: a click opens the page, a double-click its slideout.
     *
     * @return array{label: string, url: string, nav: array<string, mixed>}
     */
    private function sectionCrumb(Section $section): array
    {
        return [
            'label' => Craft::t('site', $section->name),
            'url' => $this->sectionUrl($section),
            'nav' => [
                'action' => 'primer/presets/section',
                'params' => ['sectionHandle' => $section->handle],
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
    private function deleteMenuItem(Preset $preset, string $name, Section $section): array
    {
        return [
            'icon' => 'trash',
            'label' => Craft::t('primer', 'Delete preset'),
            'destructive' => true,
            'attributes' => $this->deleteAttributes($preset, $name, $this->sectionUrl($section)),
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
