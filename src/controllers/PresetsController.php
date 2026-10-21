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

        $type = $preset->getType() ?? throw new NotFoundHttpException('Entry type not found');
        $typeName = Craft::t('site', $type->name);

        $response = $this->asCpScreen()
            ->title($preset->id ? $typeName : Craft::t('primer', 'New preset: {type}', ['type' => $typeName]))
            ->crumbs([
                ['label' => Craft::t('primer', 'Primer'), 'url' => 'primer'],
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

        if ($preset->id) {
            $response->editUrl(UrlHelper::cpUrl("primer/presets/$preset->id"));
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
