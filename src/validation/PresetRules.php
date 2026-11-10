<?php

namespace davidhellmann\primer\validation;

use Closure;
use CraftCms\Cms\Element\Validation\ElementRules;
use davidhellmann\primer\elements\Preset;
use Illuminate\Validation\Rule;
use Override;

use function CraftCms\Cms\t;

/**
 * What a preset needs to be saved: a section and one of its entry types, enabled for the preset's site, kept once
 * saved, and no other preset of the entry type in a site this one would span. A new preset, an unpublished draft,
 * can be saved without them while its section and entry type are picked.
 *
 * @extends ElementRules<Preset>
 *
 * @property Preset $subject
 */
class PresetRules extends ElementRules
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function rules(): array
    {
        $saving = $this->inScenarios(self::SCENARIO_DEFAULT, self::SCENARIO_LIVE);

        $rules = parent::rules();

        return [
            ...$rules,
            'siteId' => [
                $rules['siteId'],
                Rule::when($saving, [
                    function(string $attribute, mixed $value, Closure $fail) {
                        if ($this->subject->getSection() && !$this->subject->sectionIsEnabledForSite()) {
                            $fail(t('The section isn’t enabled for this site.', category: 'primer'));
                        }
                    },
                ]),
            ],
            'sectionId' => Rule::when($saving, [
                'required',
                'integer',
                fn(string $attribute, mixed $value, Closure $fail) => $this->validateUnchanged($attribute, $fail),
            ]),
            'typeId' => Rule::when($saving, [
                'required',
                'integer',
                fn(string $attribute, mixed $value, Closure $fail) => $this->validateUnchanged($attribute, $fail),
                function(string $attribute, mixed $value, Closure $fail) {
                    if (!$this->subject->typeIsInSection()) {
                        $fail(t('This entry type isn’t part of the section.', category: 'primer'));
                    } elseif ($this->subject->overlapsAnotherPreset()) {
                        $fail(t('This entry type already has a preset in this section.', category: 'primer'));
                    }
                },
            ]),
        ];
    }

    /**
     * A saved preset keeps its section and entry type: its values, field choice and scope are theirs.
     */
    private function validateUnchanged(string $attribute, Closure $fail): void
    {
        $stored = $this->subject->storedSectionAndType();

        if ($stored !== null && $stored[$attribute] !== $this->subject->$attribute) {
            $fail(t('A saved preset keeps its section and entry type.', category: 'primer'));
        }
    }
}
