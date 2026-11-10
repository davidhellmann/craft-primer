<?php

namespace davidhellmann\primer;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Cp\Navigation;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementLifecycleSaved;
use CraftCms\Cms\Element\Events\ElementLifecycleSaving;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Field\Elements\ContentBlock;
use CraftCms\Cms\Field\Events\FieldLayoutSaved;
use CraftCms\Cms\FieldLayout\Events\FieldLayoutComponentShowInFormResolving;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\GarbageCollection\Actions\DeletePartialElements;
use CraftCms\Cms\GarbageCollection\Events\RunningGarbageCollection;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\holdable\RelationField;
use davidhellmann\primer\policies\PresetPolicy;
use davidhellmann\primer\services\Applier;
use davidhellmann\primer\services\LayoutCleanup;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Primer plugin
 *
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

    protected array $elementTypes = [
        Preset::class,
    ];

    /**
     * Primer's nav item lists the index's sources, as Craft's own Entries item does.
     */
    public function getCpNavItem(): NavItem|array|null
    {
        $item = parent::getCpNavItem();

        return $item instanceof NavItem
            ? $item->subnav(app(Navigation::class)->sourceSubnav(Preset::class, 'primer'))
            : $item;
    }

    public function boot(): void
    {
        Gate::policy(Preset::class, PresetPolicy::class);

        // Fills a new entry, or one switched to another entry type, from its preset; logs it once it's saved.
        Event::listen(ElementLifecycleSaving::class, [Applier::class, 'handleSaving']);
        Event::listen(ElementLifecycleSaved::class, [Applier::class, 'handleSaved']);

        // A field that left an entry type's layout, or a Content Block's, takes its preset values along.
        Event::listen(FieldLayoutSaved::class, [LayoutCleanup::class, 'handle']);

        // A preset saved or deleted in this request is the one the next new entry should get.
        Event::listen(function(ElementSaved $event) {
            if ($event->element instanceof Preset) {
                app(Applier::class)->reset();
            }
        });
        Event::listen(function(ElementDeleted $event) {
            if ($event->element instanceof Preset) {
                app(Applier::class)->reset();
            }
        });

        // Inside a preset, a Content Block shows only its relation fields; the block's own layout stays as it is.
        Event::listen(function(FieldLayoutComponentShowInFormResolving $event) {
            $component = $event->fieldLayoutComponent;
            if (
                $event->element instanceof ContentBlock
                && !$component instanceof FieldLayoutTab
                && $event->element->getOwner() instanceof Preset
                && (!$component instanceof CustomField || !RelationField::isRelationField($component->getField()))
            ) {
                $event->showInForm = false;
            }
        });

        // A preset whose section or entry type is deleted loses its row (foreign key); GC removes the element.
        Event::listen(function(RunningGarbageCollection $event) {
            $event->garbageCollection->runActions([
                [DeletePartialElements::class, ['elementType' => Preset::class, 'table' => Preset::TABLE]],
            ]);
        });
    }
}
