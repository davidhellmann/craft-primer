<?php

namespace davidhellmann\primer\decisions;

/**
 * The rules of a preset's field choice (spec 4.4), in layout element UIDs. The choice is the same in every scope:
 * a save writes it to every preset of the entry type, and the fields it takes out are emptied in all of them.
 */
final class FieldChoice
{
    /**
     * The choice a save stores: the chosen UIDs still in the entry type's layout, in choice order. A field removed
     * from the layout and added back gets a new UID, so a stale one never comes back.
     *
     * @param string[] $choice
     * @param string[] $layoutUids The relation fields of the entry type's layout.
     * @return string[]
     */
    public static function kept(array $choice, array $layoutUids): array
    {
        return array_values(array_intersect($choice, $layoutUids));
    }

    /**
     * The layout's fields that aren't chosen, in layout order: they hold no value.
     *
     * @param string[] $layoutUids
     * @param string[] $choice
     * @return string[]
     */
    public static function unchosen(array $layoutUids, array $choice): array
    {
        return array_values(array_diff($layoutUids, $choice));
    }

    /**
     * The fields a save takes out of the choice, to empty in every site of every scope: chosen before, not after,
     * and still in the layout. A dropped stale UID has no field to empty.
     *
     * @param string[] $before The choice the entry type's presets share before the save.
     * @param string[] $after The choice the save stores.
     * @param string[] $layoutUids
     * @return string[]
     */
    public static function removed(array $before, array $after, array $layoutUids): array
    {
        return array_values(array_diff(self::kept($before, $layoutUids), $after));
    }
}
