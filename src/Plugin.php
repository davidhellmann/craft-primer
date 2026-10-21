<?php

namespace davidhellmann\primer;

use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Elements;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\services\Applier;
use yii\base\Event;

/**
 * Primer plugin
 *
 * @method static Plugin getInstance()
 * @property-read Applier $applier
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
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Preset::class;
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
