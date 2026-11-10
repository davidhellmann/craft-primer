<?php

namespace davidhellmann\primer\policies;

use CraftCms\Cms\Element\Policies\ElementPolicy;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Contracts\CraftUser;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\Plugin;

/**
 * Who may see and change presets: users with *Access Primer*. Saving and deleting need permission to edit the
 * preset's site, and only that one; Craft checks it for saving (`ElementPolicy::before()`).
 *
 * What spans every scope reaches every site, also sites the user can't edit, as with Craft's entries: the field
 * choice, emptying a field taken out of it, and deleting the entry type's presets in every other scope.
 */
class PresetPolicy extends ElementPolicy
{
    /**
     * A preset whose entry type has left its section stays hidden until it's added back.
     */
    public function view(CraftUser $user, Preset $preset): bool
    {
        return $user->can(Plugin::ACCESS_PERMISSION) && (!$preset->sectionId || $preset->typeIsInSection());
    }

    public function save(CraftUser $user, Preset $preset): bool
    {
        return $user->can(Plugin::ACCESS_PERMISSION);
    }

    public function delete(CraftUser $user, Preset $preset): bool
    {
        if (!$user->can(Plugin::ACCESS_PERMISSION)) {
            return false;
        }

        $site = $preset->siteId ? Sites::getSiteById($preset->siteId, true) : null;

        return !Sites::isMultiSite() || ($site !== null && $user->can("editSite:$site->uid"));
    }

    /**
     * Presets aren't duplicated, copied, or edited in drafts; a new one is an unpublished draft, made by Primer.
     */
    public function duplicate(CraftUser $user, Preset $preset): bool
    {
        return false;
    }

    public function copy(CraftUser $user, Preset $preset): bool
    {
        return false;
    }

    public function createDrafts(CraftUser $user, Preset $preset): bool
    {
        return false;
    }

    public function deleteForSite(CraftUser $user, Preset $preset): bool
    {
        return false;
    }
}
