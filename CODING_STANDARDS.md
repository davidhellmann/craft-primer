# Coding standards

What a reviewer judges, because no tool can. What a tool can check belongs in a check or a test.

## Decisions take plain values

A decision that doesn't need Craft to be made (whether to apply a preset, how many relations fit a field, which sites a preset spans, which fields a choice keeps) lives in a small class that takes plain values and returns plain values. Craft objects are read and written around it, not inside it.

*Why:* the `unit` suite tests these decisions without Craft and without a database, so a case is a line, not an install. The `feature` suite then only has to prove the wiring.

## Craft's own screens and controls

The control panel is Craft's element index and element editor, and what Primer adds to them goes through Craft's APIs: sources, table attributes, actions, field layout elements and `Ui` controls. No templates, scripts or styles of Primer's own. Colour and icons come only from Craft's models, such as an entry type's own icon and colour.

*Why:* Primer should look and work like a part of Craft, and keep doing so when Craft's control panel changes.

## One meaning, one wording

A thing has the same name everywhere: the terms in `GLOSSARY.md`, in code, UI strings and docs alike.

*Why:* a reader who sees two words assumes two things.
