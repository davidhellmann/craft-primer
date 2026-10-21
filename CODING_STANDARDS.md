# Coding standards

What a reviewer judges, because no tool can. What a tool can check belongs in a check or a test.

## Decisions take plain values

A decision that doesn't need Craft to be made (whether to apply a preset, how many relations fit a field, which sites a preset spans, which fields a choice keeps) lives in a small class that takes plain values and returns plain values. Craft objects are read and written around it, not inside it.

*Why:* the `unit` suite tests these decisions without Craft and without a database, so a case is a line, not an install. The `integration` suite then only has to prove the wiring.

## Craft's own markup and colours

The control panel uses Craft's tables, chips, menus, lightswitches, status fields, slideouts and crumbs. Colour and icons come only from Craft's models, such as an entry type's own icon and colour. No invented badges, accents or colours.

*Why:* Primer should look like a part of Craft, in light and dark mode, and keep doing so when Craft's styles change.

## One meaning, one wording

A thing has the same name everywhere: the terms in `GLOSSARY.md`, in code, UI strings and docs alike.

*Why:* a reader who sees two words assumes two things.
