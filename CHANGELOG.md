# Release Notes for Primer

## 1.0.0 - 2026-10-22

### Added
- Presets for the Entries, Assets and Users fields of an entry type in a section, filled into a new entry's empty fields once, when it is created or switches entry type.
- Content Block fields with relation fields in their layout, held in a preset as a whole.
- Presets that span the sites a new entry of their section would be saved to, follow Craft's propagation and translation methods, and switch on or off per site.
- Control panel section with an index, a page per section and a screen per preset, each also as a slideout.
- *Access Primer* permission; saving a preset in a site also needs permission to edit that site.
- One info line in Craft's log for each entry Primer fills. A preset that can't be applied is logged as a warning and never stops the save.
