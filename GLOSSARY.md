# Primer

A Craft CMS plugin that gives the relation fields of a new entry a starting selection, once, so authors begin from the right entries, assets or users instead of an empty field.

## Language

**Preset**:
The starting elements for the chosen relation fields of one entry type in one section, applied when an entry of that type is created. It spans the sites an entry of that section would and behaves like one: its values propagate and translate the way the entry's would, and it can be switched on or off per site. The choice of fields is the same everywhere.
_Avoid_: Default, prefill, default relation

**Relation field**:
A field that relates entries, assets or users: an Entries, Assets or Users field. Primer treats them alike. Categories and Tags fields are not relation fields in Primer's sense.
_Avoid_: Element field, reference field

**Shared field**:
A relation field whose relations are not managed per site, so every site a preset spans holds the same value.
_Avoid_: Global field, non-localized field

**Content Block**:
Craft's Content Block field. A preset holds one whose own layout has relation fields (first level only), chosen as a whole; inside the preset it shows and holds only those relation fields.
_Avoid_: Block field, nested field
