# Release Notes for Primer

## 2.0.0-beta.1 - Unreleased

### Changed
- Primer now requires Craft CMS 6 (6.0.0-beta.1 or later) and PHP 8.5. Primer 1.x stays the version for Craft CMS 5.
- Primer runs on Craft 6 directly, without the Yii 2 adapter.
- Primer's section of the control panel is Craft's own element index of presets, with a source for each section, and each preset opens in Craft's element editor, as a page or in a slideout.
- A preset's fields are chosen in its *Fields*, at the top of the editor: *Add item* adds one, × removes it.
- *New preset* starts a preset in the section the index shows, with an entry type that has no preset there yet; its section and entry type can be changed until it's created.
- Presets are switched on and off, per site, with Craft's status field and *Set Status* action, and deleted with Craft's *Delete* action.
- Install Primer with `php artisan craft:plugin/install primer`.

### Removed
- Primer's own index, section page and preset screen, with their lightswitches, delete buttons and slideout crumbs. Craft's element index and editor do all of it.

## 1.0.0 - 2026-10-22

### Added
- Presets for the Entries, Assets and Users fields of an entry type in a section, filled into a new entry's empty fields once, when it is created or switches entry type.
- Content Block fields with relation fields in their layout, held in a preset as a whole.
- Presets that span the sites a new entry of their section would be saved to, follow Craft's propagation and translation methods, and switch on or off per site.
- Control panel section with an index, a page per section and a screen per preset, each also as a slideout.
- *Access Primer* permission; saving a preset in a site also needs permission to edit that site.
- One info line in Craft's log for each entry Primer fills. A preset that can't be applied is logged as a warning and never stops the save.
