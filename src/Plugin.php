<?php

namespace davidhellmann\primer;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\elements\ContentBlock;
use craft\elements\Entry;
use craft\events\CreateFieldLayoutFormEvent;
use craft\events\FieldLayoutEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\web\UrlManager;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\holdable\RelationField;
use davidhellmann\primer\services\Applier;
use davidhellmann\primer\services\LayoutCleanup;
use davidhellmann\primer\services\Presets;
use yii\base\Event;

/**
 * Primer plugin
 *
 * @method static Plugin getInstance()
 * @property-read Applier $applier
 * @property-read LayoutCleanup $layoutCleanup
 * @property-read Presets $presets
 * @author David Hellmann <david@hellmann.io>
 * @copyright David Hellmann
 * @license https://craftcms.github.io/license/ Craft License
 */
class Plugin extends BasePlugin
{
    /**
     * Craft's automatic "Access Primer" permission: the one right to see and set presets.
     */
    public const ACCESS_PERMISSION = 'accessPlugin-primer';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'applier' => Applier::class,
                'layoutCleanup' => LayoutCleanup::class,
                'presets' => Presets::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Preset::class;
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['primer'] = 'primer/presets/index';
            $event->rules['primer/sections/<sectionHandle:{handle}>'] = 'primer/presets/section';
            $event->rules['primer/presets/new'] = 'primer/presets/edit';
            $event->rules['primer/presets/<presetId:\d+>'] = 'primer/presets/edit';
        });

        Event::on(Entry::class, Element::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $this->applier->apply($entry, $event->isNew);
        });

        // Inside a preset, a Content Block shows only its relation fields; the block's own layout stays as it is.
        Event::on(FieldLayout::class, FieldLayout::EVENT_CREATE_FORM, function(CreateFieldLayoutFormEvent $event) {
            if ($event->element instanceof ContentBlock && $event->element->getOwner() instanceof Preset) {
                $event->tabs = array_map(fn(FieldLayoutTab $tab) => $this->relationFieldsOnly($tab), $event->tabs);
            }
        });

        // A preset whose section or entry type is deleted loses its row (FK cascade); GC removes the element.
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            Craft::$app->getGc()->deletePartialElements(Preset::class, Preset::TABLE, 'id');
        });

        // A field that left an entry type's layout, or a Content Block's, takes its preset values along.
        Event::on(Fields::class, Fields::EVENT_BEFORE_SAVE_FIELD_LAYOUT, function(FieldLayoutEvent $event) {
            if (!$event->isNew) {
                $this->layoutCleanup->rememberLayout($event->layout);
            }
        });
        Event::on(Fields::class, Fields::EVENT_AFTER_SAVE_FIELD_LAYOUT, function(FieldLayoutEvent $event) {
            $this->layoutCleanup->forgetFieldsThatLeft($event->layout);
            $this->applier->reset();
        });

        // A preset saved or deleted in this request, or one whose layout changed, is the one the next new entry
        // should get.
        Event::on(Preset::class, Element::EVENT_AFTER_SAVE, fn() => $this->applier->reset());
        Event::on(Preset::class, Element::EVENT_AFTER_DELETE, fn() => $this->applier->reset());
    }

    /**
     * A copy of a form tab with only its relation fields.
     */
    private function relationFieldsOnly(FieldLayoutTab $tab): FieldLayoutTab
    {
        $tab = clone $tab;
        $tab->setElements(array_values(array_filter(
            $tab->getElements(),
            fn($element) => $element instanceof CustomField && RelationField::isRelationField($element->getField()),
        )));

        return $tab;
    }
}
