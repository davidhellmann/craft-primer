<?php

namespace davidhellmann\primer;

use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Elements;
use craft\web\UrlManager;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\services\Applier;
use davidhellmann\primer\services\Presets;
use yii\base\Event;

/**
 * Primer plugin
 *
 * @method static Plugin getInstance()
 * @property-read Applier $applier
 * @property-read Presets $presets
 * @author David Hellmann <david@hellmann.io>
 * @copyright David Hellmann
 * @license https://craftcms.github.io/license/ Craft License
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'applier' => Applier::class,
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
            $event->rules['primer/presets/new'] = 'primer/presets/edit';
            $event->rules['primer/presets/<presetId:\d+>'] = 'primer/presets/edit';
        });

        Event::on(Entry::class, Element::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $this->applier->apply($entry, $event->isNew);
        });

        // A preset saved or deleted in this request is the one the next new entry should get.
        Event::on(Preset::class, Element::EVENT_AFTER_SAVE, fn() => $this->applier->reset());
        Event::on(Preset::class, Element::EVENT_AFTER_DELETE, fn() => $this->applier->reset());
    }
}
